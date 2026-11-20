package agent

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"
)

const maxRequestBytes = 64 * 1024

type challengeRequest struct {
	ChallengeRequest
	Credential        string `json:"credential"`
	SubscriptionToken string `json:"subscription_token"`
}

type sessionRequest struct {
	SessionID string `json:"session_id"`
}

type apiError struct {
	Code    string `json:"code"`
	Message string `json:"message"`
}

type apiResponse struct {
	Data  any       `json:"data,omitempty"`
	Error *apiError `json:"error,omitempty"`
}

func NewHTTPHandler(store *PeerStore, dataPlanes ...*DataPlane) http.Handler {
	var dataPlane *DataPlane
	if len(dataPlanes) > 0 {
		dataPlane = dataPlanes[0]
	}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(writer http.ResponseWriter, request *http.Request) {
		writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]string{"status": "ok"}})
	})
	// The isolated acceptance harness uses aggregate counts to distinguish identity denials from full capacity.
	mux.HandleFunc("GET /v1/test/status", func(writer http.ResponseWriter, request *http.Request) {
		snapshot, err := store.RuntimeSnapshot()
		writeResult(writer, snapshot, err)
	})
	mux.HandleFunc("POST /v1/member-peers/ensure", func(writer http.ResponseWriter, request *http.Request) {
		var payload EnsurePeerRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		receipt, err := store.EnsurePeer(payload)
		writeResult(writer, receipt, err)
	})
	mux.HandleFunc("POST /v1/member-peers/bind-subscription", func(writer http.ResponseWriter, request *http.Request) {
		var payload BindSubscriptionRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		receipt, err := store.BindSubscription(payload)
		writeResult(writer, receipt, err)
	})
	mux.HandleFunc("GET /v1/member-peers/{peerID}/usage", func(writer http.ResponseWriter, request *http.Request) {
		snapshot, err := store.PeerUsage(request.PathValue("peerID"))
		writeResult(writer, snapshot, err)
	})
	mux.HandleFunc("POST /v1/member-peers/challenge", func(writer http.ResponseWriter, request *http.Request) {
		var payload challengeRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		challenge, err := store.IssueChallenge(payload.ChallengeRequest, payload.SubscriptionToken, payload.Credential)
		writeResult(writer, challenge, err)
	})
	mux.HandleFunc("POST /v1/member-peers/authorize", func(writer http.ResponseWriter, request *http.Request) {
		var payload AuthorizeRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		receipt, err := store.Authorize(payload)
		writeResult(writer, receipt, err)
	})
	mux.HandleFunc("POST /v1/member-peers/renew", func(writer http.ResponseWriter, request *http.Request) {
		var payload sessionRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		expiresAt, err := store.RenewSession(payload.SessionID)
		if err != nil {
			writeFailure(writer, err)
			return
		}
		writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]time.Time{"expires_at": expiresAt}})
	})
	mux.HandleFunc("POST /v1/member-peers/disconnect", func(writer http.ResponseWriter, request *http.Request) {
		var payload sessionRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		if payload.SessionID == "" {
			writeFailure(writer, ErrInvalid)
			return
		}
		if err := store.Disconnect(payload.SessionID); err != nil {
			writeFailure(writer, err)
			return
		}
		writeJSON(writer, http.StatusOK, apiResponse{Data: map[string]string{"status": "disconnected"}})
	})
	mux.HandleFunc("POST /v1/member-peers/revoke", func(writer http.ResponseWriter, request *http.Request) {
		var payload RevokePeerRequest
		if !decodeRequest(writer, request, &payload) {
			return
		}
		var receipt RevocationReceipt
		var err error
		if dataPlane != nil {
			receipt, err = dataPlane.RevokePeer(payload)
		} else {
			receipt, err = store.RevokePeer(payload)
		}
		if errors.Is(err, ErrRevocationPending) {
			writeJSON(writer, http.StatusAccepted, apiResponse{Data: map[string]string{
				"status": "revocation_pending", "peer_id": payload.PeerID, "request_id": payload.RequestID,
			}})
			return
		}
		writeResult(writer, receipt, err)
	})
	return http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.TLS == nil || len(request.TLS.PeerCertificates) == 0 {
			writeJSON(writer, http.StatusUnauthorized, apiResponse{
				Error: &apiError{Code: "client_certificate_required", Message: "A trusted client certificate is required."},
			})
			return
		}
		mux.ServeHTTP(writer, request)
	})
}

func NewMTLSServer(address, certificatePath, privateKeyPath, clientCAPath string, handler http.Handler) (*http.Server, error) {
	certificate, err := tls.LoadX509KeyPair(certificatePath, privateKeyPath)
	if err != nil {
		return nil, fmt.Errorf("load node agent server certificate: %w", err)
	}
	caPEM, err := os.ReadFile(clientCAPath)
	if err != nil {
		return nil, fmt.Errorf("read node agent client CA: %w", err)
	}
	clientCAs := x509.NewCertPool()
	if !clientCAs.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("node agent client CA contains no certificates")
	}

	return &http.Server{
		Addr:              address,
		Handler:           handler,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       30 * time.Second,
		MaxHeaderBytes:    16 * 1024,
		TLSConfig: &tls.Config{
			MinVersion:   tls.VersionTLS13,
			Certificates: []tls.Certificate{certificate},
			ClientAuth:   tls.RequireAndVerifyClientCert,
			ClientCAs:    clientCAs,
		},
	}, nil
}

func decodeRequest(writer http.ResponseWriter, request *http.Request, target any) bool {
	request.Body = http.MaxBytesReader(writer, request.Body, maxRequestBytes)
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		writeFailure(writer, ErrInvalid)
		return false
	}
	var trailing any
	if err := decoder.Decode(&trailing); !errors.Is(err, io.EOF) {
		writeFailure(writer, ErrInvalid)
		return false
	}
	return true
}

func writeResult(writer http.ResponseWriter, value any, err error) {
	if err != nil {
		writeFailure(writer, err)
		return
	}
	writeJSON(writer, http.StatusOK, apiResponse{Data: value})
}

func writeFailure(writer http.ResponseWriter, err error) {
	status := http.StatusInternalServerError
	code, message := "internal_error", "Node agent operation failed."
	switch {
	case errors.Is(err, ErrInvalid):
		status, code, message = http.StatusBadRequest, "invalid_request", "Request is invalid."
	case errors.Is(err, ErrUnauthorized):
		status, code, message = http.StatusForbidden, "unauthorized", "Node peer authorization was denied."
	case errors.Is(err, ErrNotFound):
		status, code, message = http.StatusNotFound, "not_found", "Node peer or session was not found."
	case errors.Is(err, ErrCapacity):
		status, code, message = http.StatusConflict, "capacity_reached", "Node peer capacity was reached."
	case errors.Is(err, ErrSessionActive):
		status, code, message = http.StatusConflict, "session_active", "Node peer still has an active session."
	case errors.Is(err, ErrTargetDenied):
		status, code, message = http.StatusForbidden, "target_denied", "Target is outside the isolated test data-plane policy."
	case errors.Is(err, ErrRequestConflict):
		status, code, message = http.StatusConflict, "request_conflict", "Request ID conflicts with an earlier operation."
	case errors.Is(err, ErrQuotaExhausted):
		status, code, message = http.StatusTooManyRequests, "quota_exhausted", "The shared entitlement traffic quota is exhausted."
	case errors.Is(err, ErrQuotaUnavailable), errors.Is(err, ErrQuotaPending):
		status, code, message = http.StatusServiceUnavailable, "quota_unavailable", "Traffic quota authorization is unavailable."
	}
	writeJSON(writer, status, apiResponse{Error: &apiError{Code: code, Message: message}})
}

func writeJSON(writer http.ResponseWriter, status int, value apiResponse) {
	writer.Header().Set("Content-Type", "application/json")
	writer.Header().Set("Cache-Control", "no-store")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(value)
}
