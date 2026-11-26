package agent

import (
	"bytes"
	"context"
	"crypto"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"mime"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"
)

const (
	quotaReservationPath = "/v1/node-quota/reservations"
	maxQuotaResponseSize = 64 * 1024
)

type HTTPQuotaAuthority struct {
	baseURL *url.URL
	client  *http.Client
}

type quotaResponseEnvelope struct {
	Data  json.RawMessage `json:"data"`
	Error *struct {
		Code string `json:"code"`
	} `json:"error"`
}

type quotaSettlementAcknowledgement struct {
	QuotaSettlement
	Settled bool `json:"settled"`
}

func NewHTTPQuotaAuthority(endpoint string, roots *x509.CertPool, clientCertificate tls.Certificate, timeout time.Duration) (*HTTPQuotaAuthority, error) {
	baseURL, err := url.Parse(endpoint)
	if err != nil || baseURL.Scheme != "https" || baseURL.Host == "" || baseURL.User != nil ||
		baseURL.RawQuery != "" || baseURL.ForceQuery || baseURL.Fragment != "" || roots == nil ||
		!validQuotaAuthorityClientCertificate(clientCertificate) || timeout <= 0 {
		return nil, ErrInvalid
	}

	transport := &http.Transport{
		Proxy: nil,
		DialContext: (&net.Dialer{
			Timeout: timeout, KeepAlive: 30 * time.Second,
		}).DialContext,
		TLSClientConfig: &tls.Config{
			MinVersion:   tls.VersionTLS13,
			RootCAs:      roots,
			Certificates: []tls.Certificate{clientCertificate},
		},
		TLSHandshakeTimeout:   timeout,
		ResponseHeaderTimeout: timeout,
		IdleConnTimeout:       30 * time.Second,
		MaxIdleConns:          8,
	}
	client := &http.Client{
		Transport: transport,
		Timeout:   timeout,
		CheckRedirect: func(_ *http.Request, _ []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}

	return &HTTPQuotaAuthority{baseURL: baseURL, client: client}, nil
}

func validQuotaAuthorityClientCertificate(certificate tls.Certificate) bool {
	if len(certificate.Certificate) == 0 || certificate.PrivateKey == nil {
		return false
	}
	signer, ok := certificate.PrivateKey.(crypto.Signer)
	if !ok {
		return false
	}
	leaf, err := x509.ParseCertificate(certificate.Certificate[0])
	if err != nil || (certificate.Leaf != nil && !bytes.Equal(certificate.Leaf.Raw, leaf.Raw)) {
		return false
	}
	signerPublicKey, err := x509.MarshalPKIXPublicKey(signer.Public())
	if err != nil || !bytes.Equal(signerPublicKey, leaf.RawSubjectPublicKeyInfo) {
		return false
	}
	now := time.Now()
	if now.Before(leaf.NotBefore) || !now.Before(leaf.NotAfter) ||
		(leaf.KeyUsage != 0 && leaf.KeyUsage&x509.KeyUsageDigitalSignature == 0) {
		return false
	}
	clientAuthenticationAllowed := len(leaf.ExtKeyUsage) == 0
	for _, usage := range leaf.ExtKeyUsage {
		if usage == x509.ExtKeyUsageAny || usage == x509.ExtKeyUsageClientAuth {
			clientAuthenticationAllowed = true
		} else if usage != x509.ExtKeyUsageServerAuth {
			return false
		}
	}
	return clientAuthenticationAllowed
}

func (authority *HTTPQuotaAuthority) Reserve(ctx context.Context, request QuotaReservationRequest) (QuotaReservation, error) {
	if authority == nil || authority.client == nil || !validQuotaReservationRequest(request) {
		return QuotaReservation{}, ErrInvalid
	}

	var reservation QuotaReservation
	if err := authority.call(ctx, http.MethodPost, quotaReservationPath, request, &reservation); err != nil {
		return QuotaReservation{}, err
	}
	if reservation.QuotaReservationRequest != request || reservation.ID == "" || reservation.Status != "reserved" ||
		!reservation.LeaseExpiresAt.After(time.Now()) {
		return QuotaReservation{}, errors.Join(ErrQuotaUnavailable, errors.New("quota reservation response identity is invalid"))
	}

	return reservation, nil
}

func (authority *HTTPQuotaAuthority) Settle(ctx context.Context, receipt QuotaSettlement) error {
	if authority == nil || authority.client == nil || !validQuotaSettlement(receipt) {
		return ErrInvalid
	}

	path := quotaReservationPath + "/" + url.PathEscape(receipt.RequestID) + "/settlements"
	var acknowledgement quotaSettlementAcknowledgement
	if err := authority.call(ctx, http.MethodPost, path, receipt, &acknowledgement); err != nil {
		return err
	}
	if !acknowledgement.Settled || acknowledgement.QuotaSettlement != receipt {
		return errors.Join(ErrQuotaUnavailable, errors.New("quota settlement acknowledgement does not match its receipt"))
	}

	return nil
}

func (authority *HTTPQuotaAuthority) call(ctx context.Context, method string, path string, body any, result any) error {
	encoded, err := json.Marshal(body)
	if err != nil {
		return errors.Join(ErrInvalid, err)
	}
	endpoint := *authority.baseURL
	endpoint.Path = strings.TrimRight(endpoint.Path, "/") + path
	endpoint.RawPath = ""
	request, err := http.NewRequestWithContext(ctx, method, endpoint.String(), bytes.NewReader(encoded))
	if err != nil {
		return errors.Join(ErrInvalid, err)
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")

	response, err := authority.client.Do(request)
	if err != nil {
		return errors.Join(ErrQuotaUnavailable, err)
	}
	defer response.Body.Close()

	limitedBody := io.LimitReader(response.Body, maxQuotaResponseSize+1)
	responseBody, err := io.ReadAll(limitedBody)
	if err != nil || len(responseBody) > maxQuotaResponseSize {
		return errors.Join(ErrQuotaUnavailable, errors.New("quota authority response could not be read"), err)
	}
	if response.StatusCode < http.StatusOK || response.StatusCode >= http.StatusMultipleChoices {
		return quotaHTTPError(response.StatusCode, responseBody)
	}
	contentType, _, contentTypeErr := mime.ParseMediaType(response.Header.Get("Content-Type"))
	if contentTypeErr != nil || !strings.EqualFold(contentType, "application/json") {
		return errors.Join(ErrQuotaUnavailable, errors.New("quota authority returned a non-JSON response"))
	}

	var envelope quotaResponseEnvelope
	if err := decodeQuotaJSON(responseBody, &envelope); err != nil || envelope.Error != nil ||
		len(envelope.Data) == 0 || string(envelope.Data) == "null" {
		return errors.Join(ErrQuotaUnavailable, errors.New("quota authority response envelope is invalid"), err)
	}
	if err := decodeQuotaJSON(envelope.Data, result); err != nil {
		return errors.Join(ErrQuotaUnavailable, errors.New("quota authority response data is invalid"), err)
	}

	return nil
}

func decodeQuotaJSON(encoded []byte, destination any) error {
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		return err
	}
	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		if err == nil {
			return errors.New("quota response contains multiple JSON values")
		}
		return err
	}

	return nil
}

func quotaHTTPError(statusCode int, body []byte) error {
	var envelope quotaResponseEnvelope
	_ = json.Unmarshal(body, &envelope)
	code := ""
	if envelope.Error != nil {
		code = envelope.Error.Code
	}
	switch code {
	case "quota_exhausted":
		return ErrQuotaExhausted
	case "request_conflict":
		return ErrRequestConflict
	default:
		return errors.Join(ErrQuotaUnavailable, fmt.Errorf("quota authority returned HTTP %d", statusCode))
	}
}

func validQuotaReservationRequest(request QuotaReservationRequest) bool {
	return request.EntitlementID != "" && request.NodeID != "" && request.PeerID != "" &&
		request.Generation > 0 && validQuotaRequestID(request.RequestID) && request.GrantBytes > 0
}

func validQuotaSettlement(receipt QuotaSettlement) bool {
	return receipt.NodeID != "" && receipt.PeerID != "" && receipt.Generation > 0 &&
		validQuotaRequestID(receipt.RequestID) && receipt.AcknowledgementID != "" &&
		receipt.UploadBytes >= 0 && receipt.DownloadBytes >= 0 &&
		receipt.UploadBytes <= int64(^uint64(0)>>1)-receipt.DownloadBytes && receipt.Final
}

func validQuotaRequestID(requestID string) bool {
	if requestID == "" || len(requestID) > 36 {
		return false
	}
	for _, character := range requestID {
		if (character < 'a' || character > 'z') && (character < 'A' || character > 'Z') &&
			(character < '0' || character > '9') && character != '-' {
			return false
		}
	}

	return true
}
