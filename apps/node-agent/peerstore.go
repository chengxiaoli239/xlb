package agent

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"syscall"
	"time"
)

var (
	ErrInvalid           = errors.New("invalid node peer request")
	ErrUnauthorized      = errors.New("node peer authorization denied")
	ErrCapacity          = errors.New("node session capacity reached")
	ErrRequestConflict   = errors.New("request ID conflicts with prior operation")
	ErrNotFound          = errors.New("node peer not found")
	ErrChallengeLimit    = errors.New("too many outstanding node peer challenges")
	ErrSessionActive     = errors.New("node peer still has active data sessions")
	ErrRevocationPending = errors.New("node data sessions have not all closed")
)

const (
	challengeLifetime = 2 * time.Minute
	sessionLifetime   = 2 * time.Minute
	maxPeerChallenges = 4
)

type EnsurePeerRequest struct {
	NodeID                  string    `json:"node_id"`
	PeerID                  string    `json:"peer_id"`
	EntitlementID           string    `json:"entitlement_id"`
	RequestID               string    `json:"request_id"`
	Generation              int       `json:"generation"`
	DeviceID                string    `json:"device_id"`
	DeviceName              string    `json:"device_name"`
	DevicePublicKey         string    `json:"device_public_key"`
	DeviceKeyFingerprint    string    `json:"device_key_fingerprint"`
	DeviceBindingGeneration int       `json:"device_binding_generation"`
	SubscriptionID          string    `json:"subscription_id"`
	SubscriptionToken       string    `json:"subscription_token"`
	SubscriptionGeneration  int       `json:"subscription_generation"`
	PeerGeneration          int       `json:"peer_generation"`
	DeviceGeneration        int       `json:"device_generation"`
	ExpiresAt               time.Time `json:"expires_at"`
	Credential              string    `json:"credential"`
}

type EnsurePeerReceipt struct {
	NodeID             string           `json:"node_id"`
	PeerID             string           `json:"peer_id"`
	RequestID          string           `json:"request_id"`
	Generation         int              `json:"generation"`
	AcknowledgementID  string           `json:"acknowledgement_id"`
	ConfirmationStatus string           `json:"confirmation_status"`
	ProfileArtifact    *ProfileArtifact `json:"profile_artifact,omitempty"`
}

type ChallengeRequest struct {
	NodeID                 string `json:"node_id"`
	PeerID                 string `json:"peer_id"`
	DeviceID               string `json:"device_id"`
	SubscriptionID         string `json:"subscription_id"`
	SubscriptionGeneration int    `json:"subscription_generation"`
	PeerGeneration         int    `json:"peer_generation"`
	DeviceGeneration       int    `json:"device_generation"`
}

type Challenge struct {
	ID        string    `json:"challenge_id"`
	ExpiresAt time.Time `json:"expires_at"`
}

type BindSubscriptionRequest struct {
	NodeID                 string `json:"node_id"`
	PeerID                 string `json:"peer_id"`
	RequestID              string `json:"request_id"`
	SubscriptionID         string `json:"subscription_id"`
	SubscriptionToken      string `json:"subscription_token"`
	SubscriptionGeneration int    `json:"subscription_generation"`
	PeerGeneration         int    `json:"peer_generation"`
	DeviceGeneration       int    `json:"device_generation"`
}

type SubscriptionBindingReceipt struct {
	NodeID             string `json:"node_id"`
	PeerID             string `json:"peer_id"`
	RequestID          string `json:"request_id"`
	SubscriptionID     string `json:"subscription_id"`
	Generation         int    `json:"generation"`
	AcknowledgementID  string `json:"acknowledgement_id"`
	ConfirmationStatus string `json:"confirmation_status"`
}

type AuthorizeRequest struct {
	ChallengeRequest
	ChallengeID       string `json:"challenge_id"`
	SessionID         string `json:"session_id"`
	SubscriptionToken string `json:"subscription_token"`
	Credential        string `json:"credential"`
	Signature         string `json:"signature"`
}

type AuthorizeReceipt struct {
	NodeID    string    `json:"node_id"`
	PeerID    string    `json:"peer_id"`
	SessionID string    `json:"session_id"`
	ExpiresAt time.Time `json:"expires_at"`
}

type RevokePeerRequest struct {
	NodeID     string `json:"node_id"`
	PeerID     string `json:"peer_id"`
	RequestID  string `json:"request_id"`
	Generation int    `json:"generation"`
}

type RevocationReceipt struct {
	NodeID             string `json:"node_id"`
	PeerID             string `json:"peer_id"`
	RequestID          string `json:"request_id"`
	Generation         int    `json:"generation"`
	AcknowledgementID  string `json:"acknowledgement_id"`
	UploadBytes        int64  `json:"upload_bytes"`
	DownloadBytes      int64  `json:"download_bytes"`
	ConfirmationStatus string `json:"confirmation_status"`
}

type PeerStore struct {
	path                      string
	nodeID                    string
	capacity                  int
	profileRenderer           ProfileRenderer
	profileConfirmationStatus string
	bridgeEndpoint            string
	bridgeServerCA            string
}

type RuntimeSnapshot struct {
	ActiveSessions int `json:"active_sessions"`
	Capacity       int `json:"capacity"`
}

type PeerUsageSnapshot struct {
	NodeID        string `json:"node_id"`
	PeerID        string `json:"peer_id"`
	Generation    int    `json:"generation"`
	Status        string `json:"status"`
	UploadBytes   int64  `json:"upload_bytes"`
	DownloadBytes int64  `json:"download_bytes"`
}

type peerRecord struct {
	NodeID                  string             `json:"node_id"`
	PeerID                  string             `json:"peer_id"`
	EntitlementID           string             `json:"entitlement_id"`
	Generation              int                `json:"generation"`
	DeviceID                string             `json:"device_id"`
	DevicePublicKey         string             `json:"device_public_key"`
	DeviceKeyFingerprint    string             `json:"device_key_fingerprint"`
	DeviceBindingGeneration int                `json:"device_binding_generation"`
	SubscriptionID          string             `json:"subscription_id"`
	SubscriptionTokenHash   string             `json:"subscription_token_hash"`
	SubscriptionGeneration  int                `json:"subscription_generation"`
	PeerGeneration          int                `json:"peer_generation"`
	DeviceGeneration        int                `json:"device_generation"`
	CredentialHash          string             `json:"credential_hash"`
	ExpiresAt               time.Time          `json:"expires_at"`
	Status                  string             `json:"status"`
	UploadBytes             int64              `json:"upload_bytes"`
	DownloadBytes           int64              `json:"download_bytes"`
	RevocationRequestID     string             `json:"revocation_request_id,omitempty"`
	RevocationGeneration    int                `json:"revocation_generation,omitempty"`
	RevocationReceipt       *RevocationReceipt `json:"revocation_receipt,omitempty"`
}

type challengeRecord struct {
	Fields    ChallengeRequest `json:"fields"`
	ExpiresAt time.Time        `json:"expires_at"`
}

type sessionRecord struct {
	PeerID    string    `json:"peer_id"`
	ExpiresAt time.Time `json:"expires_at"`
}

type operationRecord struct {
	Digest                     string                      `json:"digest"`
	EnsureReceipt              *EnsurePeerReceipt          `json:"ensure_receipt,omitempty"`
	SubscriptionBindingReceipt *SubscriptionBindingReceipt `json:"subscription_binding_receipt,omitempty"`
	RevocationReceipt          *RevocationReceipt          `json:"revocation_receipt,omitempty"`
}

type agentState struct {
	NodeID      string                        `json:"node_id"`
	Capacity    int                           `json:"capacity"`
	Peers       map[string]peerRecord         `json:"peers"`
	Challenges  map[string]challengeRecord    `json:"challenges"`
	Sessions    map[string]sessionRecord      `json:"sessions"`
	Operations  map[string]operationRecord    `json:"operations"`
	QuotaLeases map[string]QuotaLeaseSnapshot `json:"quota_leases,omitempty"`
}

func OpenPeerStore(path, nodeID string, capacity int) (*PeerStore, error) {
	return openPeerStore(path, nodeID, capacity, nil, "", "", "confirmed_test")
}

func OpenPeerStoreWithProfileRenderer(path, nodeID string, capacity int, renderer ProfileRenderer) (*PeerStore, error) {
	if renderer == nil {
		return nil, ErrInvalid
	}
	descriptor, ok := renderer.(MemberBridgeDescriptorProvider)
	if !ok {
		return nil, ErrInvalid
	}
	bridgeEndpoint, bridgeServerCA := descriptor.MemberBridgeDescriptor()
	if err := validateMemberBridgeDescriptor(bridgeEndpoint, bridgeServerCA); err != nil {
		return nil, err
	}

	return openPeerStore(path, nodeID, capacity, renderer, bridgeEndpoint, bridgeServerCA, "confirmed")
}

// OpenPeerStoreWithTestProfileRenderer renders isolated-test profiles while preserving test-only receipts.
func OpenPeerStoreWithTestProfileRenderer(path, nodeID string, capacity int, renderer ProfileRenderer) (*PeerStore, error) {
	if renderer == nil {
		return nil, ErrInvalid
	}
	descriptor, ok := renderer.(MemberBridgeDescriptorProvider)
	if !ok {
		return nil, ErrInvalid
	}
	bridgeEndpoint, bridgeServerCA := descriptor.MemberBridgeDescriptor()
	if err := validateMemberBridgeDescriptor(bridgeEndpoint, bridgeServerCA); err != nil {
		return nil, err
	}

	return openPeerStore(path, nodeID, capacity, renderer, bridgeEndpoint, bridgeServerCA, "confirmed_test")
}

func openPeerStore(path, nodeID string, capacity int, renderer ProfileRenderer,
	bridgeEndpoint, bridgeServerCA, profileConfirmationStatus string) (*PeerStore, error) {
	if path == "" || nodeID == "" || capacity < 1 {
		return nil, ErrInvalid
	}
	directory := filepath.Dir(path)
	if err := os.MkdirAll(directory, 0o700); err != nil {
		return nil, fmt.Errorf("create node state directory: %w", err)
	}
	if err := os.Chmod(directory, 0o700); err != nil {
		return nil, fmt.Errorf("protect node state directory: %w", err)
	}
	if err := os.Chmod(path+".lock", 0o600); err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, fmt.Errorf("protect node state lock: %w", err)
	}
	if err := os.Chmod(path, 0o600); err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, fmt.Errorf("protect existing node state: %w", err)
	}
	store := &PeerStore{
		path: path, nodeID: nodeID, capacity: capacity, profileRenderer: renderer,
		profileConfirmationStatus: profileConfirmationStatus,
		bridgeEndpoint:            bridgeEndpoint, bridgeServerCA: bridgeServerCA,
	}
	if err := store.update(func(state *agentState) error {
		if state.NodeID != "" && (state.NodeID != nodeID || state.Capacity != capacity) {
			return fmt.Errorf("persisted node identity or capacity differs from configured values: %w", ErrInvalid)
		}
		state.NodeID = nodeID
		state.Capacity = capacity
		return nil
	}); err != nil {
		return nil, err
	}
	return store, nil
}

func (store *PeerStore) RuntimeSnapshot() (RuntimeSnapshot, error) {
	var snapshot RuntimeSnapshot
	err := store.update(func(state *agentState) error {
		now := time.Now()
		for sessionID, session := range state.Sessions {
			if !session.ExpiresAt.After(now) {
				delete(state.Sessions, sessionID)
			}
		}
		snapshot = RuntimeSnapshot{ActiveSessions: len(state.Sessions), Capacity: state.Capacity}
		return nil
	})
	return snapshot, err
}

func (store *PeerStore) PeerUsage(peerID string) (PeerUsageSnapshot, error) {
	if peerID == "" {
		return PeerUsageSnapshot{}, ErrInvalid
	}
	var snapshot PeerUsageSnapshot
	err := store.read(func(state *agentState) error {
		if state.NodeID != store.nodeID || state.Capacity != store.capacity {
			return ErrInvalid
		}
		peer, exists := state.Peers[peerID]
		if !exists {
			return ErrNotFound
		}
		snapshot = PeerUsageSnapshot{
			NodeID: store.nodeID, PeerID: peer.PeerID, Generation: peer.Generation,
			Status: peer.Status, UploadBytes: peer.UploadBytes, DownloadBytes: peer.DownloadBytes,
		}
		return nil
	})
	return snapshot, err
}

func (store *PeerStore) PeerQuotaIdentity(peerID string) (PeerQuotaIdentity, error) {
	if peerID == "" {
		return PeerQuotaIdentity{}, ErrInvalid
	}
	var identity PeerQuotaIdentity
	err := store.read(func(state *agentState) error {
		peer, exists := state.Peers[peerID]
		if !exists {
			return ErrNotFound
		}
		if peer.Status != "active" || peer.EntitlementID == "" || !peer.ExpiresAt.After(time.Now()) {
			return ErrUnauthorized
		}
		identity = PeerQuotaIdentity{
			NodeID: peer.NodeID, EntitlementID: peer.EntitlementID,
			PeerID: peer.PeerID, Generation: peer.Generation,
		}
		return nil
	})
	return identity, err
}

func (store *PeerStore) EnsurePeer(request EnsurePeerRequest) (EnsurePeerReceipt, error) {
	digest, publicKeyDER, err := validateEnsureRequest(request, store.nodeID)
	if err != nil {
		return EnsurePeerReceipt{}, err
	}
	if fingerprint(publicKeyDER) != request.DeviceKeyFingerprint {
		return EnsurePeerReceipt{}, ErrInvalid
	}
	var priorReceipt EnsurePeerReceipt
	priorExists := false
	if err := store.read(func(state *agentState) error {
		prior, exists := state.Operations[request.RequestID]
		if !exists {
			return nil
		}
		if prior.Digest != digest || prior.EnsureReceipt == nil {
			return ErrRequestConflict
		}
		priorReceipt = *prior.EnsureReceipt
		priorExists = true
		return nil
	}); err != nil {
		return EnsurePeerReceipt{}, err
	}
	if priorExists {
		return priorReceipt, nil
	}

	confirmationStatus := "confirmed_test"
	var artifact *ProfileArtifact
	if store.profileRenderer != nil {
		profileRequest := ProfileRenderRequest{
			NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
			RequestID: request.RequestID, Generation: request.Generation, DeviceID: request.DeviceID,
			DeviceKeyFingerprint:    request.DeviceKeyFingerprint,
			DeviceBindingGeneration: request.DeviceBindingGeneration, BridgeProtocol: "xlb-proof-v1",
			BridgeEndpoint: store.bridgeEndpoint, BridgeServerCAPEM: store.bridgeServerCA,
		}
		renderContext, cancel := context.WithTimeout(context.Background(), 2*time.Second)
		rendered, renderErr := store.profileRenderer.Render(renderContext, profileRequest)
		cancel()
		if renderErr != nil {
			return EnsurePeerReceipt{}, fmt.Errorf("render member bridge profile: %w", ErrInvalid)
		}
		if err := ValidateProfileArtifact(profileRequest, rendered); err != nil {
			return EnsurePeerReceipt{}, err
		}
		artifact = &rendered
		confirmationStatus = store.profileConfirmationStatus
	}

	var receipt EnsurePeerReceipt
	err = store.update(func(state *agentState) error {
		if prior, exists := state.Operations[request.RequestID]; exists {
			if prior.Digest != digest || prior.EnsureReceipt == nil {
				return ErrRequestConflict
			}
			receipt = *prior.EnsureReceipt
			return nil
		}
		if existing, exists := state.Peers[request.PeerID]; exists {
			if existing.Status != "revoked" || request.Generation <= existing.Generation {
				return ErrRequestConflict
			}
		}

		receipt = EnsurePeerReceipt{
			NodeID: store.nodeID, PeerID: request.PeerID, RequestID: request.RequestID,
			Generation: request.Generation, AcknowledgementID: randomID(), ConfirmationStatus: confirmationStatus,
			ProfileArtifact: artifact,
		}
		state.Peers[request.PeerID] = peerRecord{
			NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
			Generation: request.Generation,
			DeviceID:   request.DeviceID, DevicePublicKey: request.DevicePublicKey,
			DeviceKeyFingerprint:    request.DeviceKeyFingerprint,
			DeviceBindingGeneration: request.DeviceBindingGeneration,
			SubscriptionID:          request.SubscriptionID,
			SubscriptionTokenHash:   hashSecret(request.SubscriptionToken),
			SubscriptionGeneration:  request.SubscriptionGeneration,
			PeerGeneration:          request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
			CredentialHash: hashSecret(request.Credential), ExpiresAt: request.ExpiresAt.UTC(), Status: "active",
		}
		state.Operations[request.RequestID] = operationRecord{Digest: digest, EnsureReceipt: &receipt}
		return nil
	})
	return receipt, err
}

func (store *PeerStore) BindSubscription(request BindSubscriptionRequest) (SubscriptionBindingReceipt, error) {
	digest, err := digestJSON(request)
	if err != nil || request.NodeID != store.nodeID || request.PeerID == "" || request.RequestID == "" ||
		request.SubscriptionID == "" || request.SubscriptionToken == "" || request.SubscriptionGeneration < 1 ||
		request.PeerGeneration < 1 || request.DeviceGeneration < 1 {
		return SubscriptionBindingReceipt{}, ErrInvalid
	}
	var receipt SubscriptionBindingReceipt
	err = store.update(func(state *agentState) error {
		if prior, exists := state.Operations[request.RequestID]; exists {
			if prior.Digest != digest || prior.SubscriptionBindingReceipt == nil {
				return ErrRequestConflict
			}
			receipt = *prior.SubscriptionBindingReceipt
			return nil
		}
		peer, exists := state.Peers[request.PeerID]
		if !exists {
			return ErrNotFound
		}
		if peer.Status != "active" || !peer.ExpiresAt.After(time.Now()) ||
			peer.Generation != request.PeerGeneration || peer.DeviceGeneration != request.DeviceGeneration ||
			(peer.SubscriptionID != "" && peer.SubscriptionID != request.SubscriptionID) ||
			request.SubscriptionGeneration <= peer.SubscriptionGeneration {
			return ErrRequestConflict
		}
		peer.SubscriptionID = request.SubscriptionID
		peer.SubscriptionTokenHash = hashSecret(request.SubscriptionToken)
		peer.SubscriptionGeneration = request.SubscriptionGeneration
		state.Peers[request.PeerID] = peer
		for challengeID, challenge := range state.Challenges {
			if challenge.Fields.PeerID == request.PeerID {
				delete(state.Challenges, challengeID)
			}
		}
		receipt = SubscriptionBindingReceipt{
			NodeID: store.nodeID, PeerID: request.PeerID, RequestID: request.RequestID,
			SubscriptionID: request.SubscriptionID, Generation: request.SubscriptionGeneration,
			AcknowledgementID: randomID(), ConfirmationStatus: "confirmed_test",
		}
		state.Operations[request.RequestID] = operationRecord{Digest: digest, SubscriptionBindingReceipt: &receipt}
		return nil
	})
	return receipt, err
}

func (store *PeerStore) IssueChallenge(request ChallengeRequest, subscriptionToken, credential string) (Challenge, error) {
	if !validChallengeRequest(request, store.nodeID) {
		return Challenge{}, ErrInvalid
	}
	if credential == "" || (request.SubscriptionID != "" && subscriptionToken == "") ||
		(request.SubscriptionID == "" && subscriptionToken != "") {
		return Challenge{}, ErrUnauthorized
	}
	var challenge Challenge
	var challengeError error
	err := store.update(func(state *agentState) error {
		for challengeID, challenge := range state.Challenges {
			if !challenge.ExpiresAt.After(time.Now()) {
				delete(state.Challenges, challengeID)
			}
		}
		peer, exists := state.Peers[request.PeerID]
		if !exists {
			challengeError = ErrNotFound
			return nil
		}
		if !(peerMatchesChallenge(peer, request) && peer.Status == "active" && peer.ExpiresAt.After(time.Now()) &&
			peer.CredentialHash == hashSecret(credential) &&
			(request.SubscriptionID == "" || peer.SubscriptionTokenHash == hashSecret(subscriptionToken))) {
			challengeError = ErrUnauthorized
			return nil
		}
		outstanding := 0
		for _, activeChallenge := range state.Challenges {
			if activeChallenge.Fields.PeerID == request.PeerID {
				outstanding++
			}
		}
		if outstanding >= maxPeerChallenges {
			challengeError = ErrChallengeLimit
			return nil
		}
		challenge = Challenge{ID: randomID(), ExpiresAt: time.Now().UTC().Add(challengeLifetime)}
		state.Challenges[challenge.ID] = challengeRecord{Fields: request, ExpiresAt: challenge.ExpiresAt}
		return nil
	})
	if err != nil {
		return Challenge{}, err
	}
	if challengeError != nil {
		return Challenge{}, challengeError
	}
	return challenge, nil
}

func (store *PeerStore) Authorize(request AuthorizeRequest) (AuthorizeReceipt, error) {
	if !(validChallengeRequest(request.ChallengeRequest, store.nodeID) && request.ChallengeID != "" &&
		request.SessionID != "" && request.Credential != "" && request.Signature != "" &&
		((request.SubscriptionID == "" && request.SubscriptionToken == "") ||
			(request.SubscriptionID != "" && request.SubscriptionToken != ""))) {
		return AuthorizeReceipt{}, ErrInvalid
	}
	var receipt AuthorizeReceipt
	var authorizationError error
	err := store.update(func(state *agentState) error {
		challenge, exists := state.Challenges[request.ChallengeID]
		if !exists {
			authorizationError = ErrUnauthorized
			return nil
		}
		delete(state.Challenges, request.ChallengeID)
		peer, exists := state.Peers[request.PeerID]
		if !exists {
			authorizationError = ErrNotFound
			return nil
		}
		if !(challenge.ExpiresAt.After(time.Now()) && challenge.Fields == request.ChallengeRequest &&
			peer.Status == "active" && peer.ExpiresAt.After(time.Now()) &&
			peerMatchesChallenge(peer, request.ChallengeRequest) &&
			peer.CredentialHash == hashSecret(request.Credential) &&
			(request.SubscriptionID == "" || peer.SubscriptionTokenHash == hashSecret(request.SubscriptionToken)) &&
			state.Sessions[request.SessionID].PeerID == "") {
			authorizationError = ErrUnauthorized
			return nil
		}
		if err := verifyConnectionProof(peer.DevicePublicKey, request); err != nil {
			authorizationError = ErrUnauthorized
			return nil
		}

		for sessionID, session := range state.Sessions {
			if !session.ExpiresAt.After(time.Now()) {
				delete(state.Sessions, sessionID)
				continue
			}
			if session.PeerID == request.PeerID {
				authorizationError = ErrCapacity
				return nil
			}
		}
		if len(state.Sessions) >= state.Capacity {
			authorizationError = ErrCapacity
			return nil
		}

		receipt = AuthorizeReceipt{
			NodeID: store.nodeID, PeerID: request.PeerID, SessionID: request.SessionID,
			ExpiresAt: time.Now().UTC().Add(sessionLifetime),
		}
		state.Sessions[request.SessionID] = sessionRecord{PeerID: request.PeerID, ExpiresAt: receipt.ExpiresAt}
		return nil
	})
	if err != nil {
		return AuthorizeReceipt{}, err
	}
	if authorizationError != nil {
		return AuthorizeReceipt{}, authorizationError
	}
	return receipt, nil
}

func (store *PeerStore) RenewSession(sessionID string) (time.Time, error) {
	var expiresAt time.Time
	err := store.update(func(state *agentState) error {
		session, exists := state.Sessions[sessionID]
		if !exists {
			return ErrNotFound
		}
		peer := state.Peers[session.PeerID]
		if !session.ExpiresAt.After(time.Now()) || peer.Status != "active" || !peer.ExpiresAt.After(time.Now()) {
			delete(state.Sessions, sessionID)
			return ErrUnauthorized
		}
		expiresAt = time.Now().UTC().Add(sessionLifetime)
		if peer.ExpiresAt.Before(expiresAt) {
			expiresAt = peer.ExpiresAt
		}
		session.ExpiresAt = expiresAt
		state.Sessions[sessionID] = session
		return nil
	})
	return expiresAt, err
}

func (store *PeerStore) Disconnect(sessionID string) error {
	return store.update(func(state *agentState) error {
		delete(state.Sessions, sessionID)
		return nil
	})
}

func (store *PeerStore) AddTraffic(peerID string, uploadBytes, downloadBytes int64) error {
	if peerID == "" || uploadBytes < 0 || downloadBytes < 0 {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		peer, exists := state.Peers[peerID]
		if !exists {
			return ErrNotFound
		}
		if peer.Status != "active" && peer.Status != "revocation_pending" {
			return ErrUnauthorized
		}
		if uploadBytes > math.MaxInt64-peer.UploadBytes || downloadBytes > math.MaxInt64-peer.DownloadBytes {
			return ErrInvalid
		}
		peer.UploadBytes += uploadBytes
		peer.DownloadBytes += downloadBytes
		state.Peers[peerID] = peer
		return nil
	})
}

func (store *PeerStore) RevokePeer(request RevokePeerRequest) (RevocationReceipt, error) {
	if err := store.BeginRevocation(request); err != nil {
		return RevocationReceipt{}, err
	}
	return store.CompleteRevocation(request)
}

func (store *PeerStore) BeginRevocation(request RevokePeerRequest) error {
	digest, err := digestJSON(request)
	if err != nil || request.NodeID != store.nodeID || request.PeerID == "" || request.RequestID == "" || request.Generation < 1 {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		if prior, exists := state.Operations[request.RequestID]; exists {
			if prior.Digest != digest || (prior.RevocationReceipt == nil && prior.EnsureReceipt != nil) ||
				(prior.RevocationReceipt == nil && prior.SubscriptionBindingReceipt != nil) {
				return ErrRequestConflict
			}
			if prior.RevocationReceipt != nil {
				return nil
			}
		}
		peer, exists := state.Peers[request.PeerID]
		if !exists {
			return ErrNotFound
		}
		if peer.Status == "revocation_pending" && peer.RevocationRequestID == request.RequestID &&
			peer.RevocationGeneration == request.Generation {
			return nil
		}
		if peer.Status != "active" || request.Generation != peer.Generation+1 {
			return ErrRequestConflict
		}
		peer.Status = "revocation_pending"
		peer.RevocationRequestID = request.RequestID
		peer.RevocationGeneration = request.Generation
		state.Peers[request.PeerID] = peer
		for challengeID, challenge := range state.Challenges {
			if challenge.Fields.PeerID == request.PeerID {
				delete(state.Challenges, challengeID)
			}
		}
		state.Operations[request.RequestID] = operationRecord{Digest: digest}
		return nil
	})
}

func (store *PeerStore) CompleteRevocation(request RevokePeerRequest) (RevocationReceipt, error) {
	digest, err := digestJSON(request)
	if err != nil || request.NodeID != store.nodeID || request.PeerID == "" || request.RequestID == "" || request.Generation < 1 {
		return RevocationReceipt{}, ErrInvalid
	}
	var receipt RevocationReceipt
	err = store.update(func(state *agentState) error {
		if prior, exists := state.Operations[request.RequestID]; exists {
			if prior.Digest != digest || (prior.EnsureReceipt != nil) || (prior.SubscriptionBindingReceipt != nil) {
				return ErrRequestConflict
			}
			if prior.RevocationReceipt != nil {
				receipt = *prior.RevocationReceipt
				return nil
			}
		} else {
			return ErrRequestConflict
		}
		peer, exists := state.Peers[request.PeerID]
		if !exists {
			return ErrNotFound
		}
		if peer.Status != "revocation_pending" || peer.RevocationRequestID != request.RequestID ||
			peer.RevocationGeneration != request.Generation || request.Generation != peer.Generation+1 {
			return ErrRequestConflict
		}
		for sessionID, session := range state.Sessions {
			if session.PeerID != request.PeerID {
				continue
			}
			if session.ExpiresAt.After(time.Now()) {
				return ErrSessionActive
			}
			delete(state.Sessions, sessionID)
		}
		peer.Status = "revoked"
		peer.Generation = request.Generation
		receipt = RevocationReceipt{
			NodeID: store.nodeID, PeerID: peer.PeerID, RequestID: request.RequestID,
			Generation: request.Generation, AcknowledgementID: randomID(),
			UploadBytes: peer.UploadBytes, DownloadBytes: peer.DownloadBytes, ConfirmationStatus: "confirmed_test",
		}
		peer.RevocationReceipt = &receipt
		state.Peers[request.PeerID] = peer
		state.Operations[request.RequestID] = operationRecord{Digest: digest, RevocationReceipt: &receipt}
		return nil
	})
	return receipt, err
}

func (store *PeerStore) update(operation func(*agentState) error) error {
	lock, err := os.OpenFile(store.path+".lock", os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return fmt.Errorf("open node state lock: %w", err)
	}
	defer lock.Close()
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_EX); err != nil {
		return fmt.Errorf("lock node state: %w", err)
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)

	state, err := loadState(store.path)
	if err != nil {
		return err
	}
	if state.Peers == nil {
		state = agentState{
			NodeID: store.nodeID, Capacity: store.capacity,
			Peers: map[string]peerRecord{}, Challenges: map[string]challengeRecord{},
			Sessions: map[string]sessionRecord{}, Operations: map[string]operationRecord{},
			QuotaLeases: map[string]QuotaLeaseSnapshot{},
		}
	}
	if state.QuotaLeases == nil {
		state.QuotaLeases = map[string]QuotaLeaseSnapshot{}
	}
	if state.NodeID != store.nodeID || state.Capacity != store.capacity {
		return ErrInvalid
	}
	if err := operation(&state); err != nil {
		return err
	}
	return saveState(store.path, state)
}

func (store *PeerStore) read(operation func(*agentState) error) error {
	lock, err := os.OpenFile(store.path+".lock", os.O_CREATE|os.O_RDWR, 0o600)
	if err != nil {
		return fmt.Errorf("open node state lock: %w", err)
	}
	defer lock.Close()
	if err := syscall.Flock(int(lock.Fd()), syscall.LOCK_SH); err != nil {
		return fmt.Errorf("lock node state for reading: %w", err)
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)

	state, err := loadState(store.path)
	if err != nil {
		return err
	}
	return operation(&state)
}

func loadState(path string) (agentState, error) {
	data, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return agentState{}, nil
	}
	if err != nil {
		return agentState{}, fmt.Errorf("read node state: %w", err)
	}
	var state agentState
	if err := json.Unmarshal(data, &state); err != nil {
		return agentState{}, fmt.Errorf("decode node state: %w", err)
	}
	return state, nil
}

func saveState(path string, state agentState) error {
	data, err := json.Marshal(state)
	if err != nil {
		return fmt.Errorf("encode node state: %w", err)
	}
	file, err := os.CreateTemp(filepath.Dir(path), ".node-state-*")
	if err != nil {
		return fmt.Errorf("create node state temp file: %w", err)
	}
	temporaryPath := file.Name()
	defer os.Remove(temporaryPath)
	if err := file.Chmod(0o600); err != nil {
		file.Close()
		return fmt.Errorf("protect node state: %w", err)
	}
	if _, err := file.Write(data); err != nil {
		file.Close()
		return fmt.Errorf("write node state: %w", err)
	}
	if err := file.Sync(); err != nil {
		file.Close()
		return fmt.Errorf("sync node state: %w", err)
	}
	if err := file.Close(); err != nil {
		return fmt.Errorf("close node state: %w", err)
	}
	if err := os.Rename(temporaryPath, path); err != nil {
		return fmt.Errorf("replace node state: %w", err)
	}
	directory, err := os.Open(filepath.Dir(path))
	if err != nil {
		return fmt.Errorf("open node state directory: %w", err)
	}
	defer directory.Close()
	if err := directory.Sync(); err != nil {
		return fmt.Errorf("sync node state directory: %w", err)
	}
	return nil
}

func validateEnsureRequest(request EnsurePeerRequest, nodeID string) (string, []byte, error) {
	if !(request.NodeID == nodeID && request.PeerID != "" && request.RequestID != "" && request.Generation >= 1 &&
		request.EntitlementID != "" && request.DeviceID != "" && request.DeviceName != "" && request.DeviceBindingGeneration >= 1 &&
		((request.SubscriptionID == "" && request.SubscriptionGeneration == 0 && request.SubscriptionToken == "") ||
			(request.SubscriptionID != "" && request.SubscriptionGeneration >= 1 && request.SubscriptionToken != "")) &&
		request.PeerGeneration == request.Generation &&
		request.DeviceGeneration == request.DeviceBindingGeneration && request.Credential != "" &&
		request.ExpiresAt.After(time.Now())) {
		return "", nil, ErrInvalid
	}
	publicKeyDER, err := base64.RawURLEncoding.DecodeString(request.DevicePublicKey)
	if err != nil {
		return "", nil, ErrInvalid
	}
	parsed, err := x509.ParsePKIXPublicKey(publicKeyDER)
	publicKey, ok := parsed.(*ecdsa.PublicKey)
	if err != nil || !ok || publicKey.Curve != elliptic.P256() {
		return "", nil, ErrInvalid
	}
	digest, err := digestJSON(request)
	return digest, publicKeyDER, err
}

func validChallengeRequest(request ChallengeRequest, nodeID string) bool {
	return request.NodeID == nodeID && request.PeerID != "" && request.DeviceID != "" &&
		((request.SubscriptionID == "" && request.SubscriptionGeneration == 0) ||
			(request.SubscriptionID != "" && request.SubscriptionGeneration > 0)) &&
		request.PeerGeneration > 0 && request.DeviceGeneration > 0
}

func peerMatchesChallenge(peer peerRecord, request ChallengeRequest) bool {
	return peer.NodeID == request.NodeID && peer.PeerID == request.PeerID && peer.DeviceID == request.DeviceID &&
		peer.SubscriptionID == request.SubscriptionID && peer.SubscriptionGeneration == request.SubscriptionGeneration &&
		peer.PeerGeneration == request.PeerGeneration && peer.DeviceGeneration == request.DeviceGeneration &&
		peer.DeviceBindingGeneration == request.DeviceGeneration
}

func verifyConnectionProof(publicKeyEncoded string, request AuthorizeRequest) error {
	publicKeyDER, err := base64.RawURLEncoding.DecodeString(publicKeyEncoded)
	if err != nil {
		return ErrUnauthorized
	}
	parsed, err := x509.ParsePKIXPublicKey(publicKeyDER)
	publicKey, ok := parsed.(*ecdsa.PublicKey)
	if err != nil || !ok || publicKey.Curve != elliptic.P256() {
		return ErrUnauthorized
	}
	signature, err := base64.RawURLEncoding.DecodeString(request.Signature)
	if err != nil {
		return ErrUnauthorized
	}
	message, err := ConnectionProofMessage(request.ChallengeRequest, request.ChallengeID)
	if err != nil {
		return ErrUnauthorized
	}
	digest := sha256.Sum256([]byte(message))
	if !ecdsa.VerifyASN1(publicKey, digest[:], signature) {
		return ErrUnauthorized
	}
	return nil
}

func ConnectionProofMessage(request ChallengeRequest, challengeID string) (string, error) {
	message := struct {
		Purpose                string `json:"purpose"`
		NodeID                 string `json:"node_id"`
		PeerID                 string `json:"peer_id"`
		DeviceID               string `json:"device_id"`
		SubscriptionID         string `json:"subscription_id"`
		SubscriptionGeneration int    `json:"subscription_generation"`
		PeerGeneration         int    `json:"peer_generation"`
		DeviceGeneration       int    `json:"device_generation"`
		ChallengeID            string `json:"challenge_id"`
	}{"xlb-node-peer-connect-v1", request.NodeID, request.PeerID, request.DeviceID, request.SubscriptionID,
		request.SubscriptionGeneration, request.PeerGeneration, request.DeviceGeneration, challengeID}
	encoded, err := json.Marshal(message)
	if err != nil {
		return "", err
	}
	return string(encoded), nil
}

func digestJSON(value any) (string, error) {
	data, err := json.Marshal(value)
	if err != nil {
		return "", err
	}
	digest := sha256.Sum256(data)
	return base64.RawURLEncoding.EncodeToString(digest[:]), nil
}

func hashSecret(value string) string {
	digest := sha256.Sum256([]byte(value))
	return base64.RawURLEncoding.EncodeToString(digest[:])
}

func fingerprint(value []byte) string {
	digest := sha256.Sum256(value)
	return fmt.Sprintf("%x", digest[:])
}

func randomID() string {
	value := make([]byte, 24)
	if _, err := rand.Read(value); err != nil {
		panic(err)
	}
	return base64.RawURLEncoding.EncodeToString(value)
}
