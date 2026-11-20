package agent

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/json"
	"math/big"
	"time"

	"crypto/tls"
	"crypto/x509/pkix"
	"encoding/asn1"
)

var deviceIdentityExtensionOID = asn1.ObjectIdentifier{1, 3, 6, 1, 4, 1, 57264, 1, 1}

type DeviceCertificateIdentity struct {
	NodeID                 string `json:"node_id"`
	PeerID                 string `json:"peer_id"`
	DeviceID               string `json:"device_id"`
	SubscriptionID         string `json:"subscription_id"`
	SubscriptionGeneration int    `json:"subscription_generation"`
	PeerGeneration         int    `json:"peer_generation"`
	DeviceGeneration       int    `json:"device_generation"`
}

type DataSession struct {
	PeerID        string
	SessionID     string
	ExpiresAt     time.Time
	PeerExpiresAt time.Time
}

func NewDeviceCertificate(identity DeviceCertificateIdentity, signer crypto.Signer) (tls.Certificate, error) {
	if signer == nil || identity.NodeID == "" || identity.PeerID == "" || identity.DeviceID == "" ||
		identity.PeerGeneration < 1 || identity.DeviceGeneration < 1 ||
		(identity.SubscriptionID == "" && identity.SubscriptionGeneration != 0) ||
		(identity.SubscriptionID != "" && identity.SubscriptionGeneration < 1) {
		return tls.Certificate{}, ErrInvalid
	}
	if publicKey, ok := signer.Public().(*ecdsa.PublicKey); !ok || publicKey.Curve != elliptic.P256() {
		return tls.Certificate{}, ErrInvalid
	}

	claims, err := json.Marshal(identity)
	if err != nil {
		return tls.Certificate{}, err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 120))
	if err != nil {
		return tls.Certificate{}, err
	}
	now := time.Now().UTC()
	template := &x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: identity.PeerID},
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(12 * time.Hour),
		KeyUsage:              x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageClientAuth},
		BasicConstraintsValid: true,
		ExtraExtensions:       []pkix.Extension{{Id: deviceIdentityExtensionOID, Critical: true, Value: claims}},
	}
	certificateDER, err := x509.CreateCertificate(rand.Reader, template, template, signer.Public(), signer)
	if err != nil {
		return tls.Certificate{}, err
	}
	certificate, err := x509.ParseCertificate(certificateDER)
	if err != nil {
		return tls.Certificate{}, err
	}
	return tls.Certificate{Certificate: [][]byte{certificateDER}, PrivateKey: signer, Leaf: certificate}, nil
}

func DeviceIdentityFromCertificate(certificate *x509.Certificate) (DeviceCertificateIdentity, error) {
	if certificate == nil || !time.Now().Before(certificate.NotAfter) || time.Now().Before(certificate.NotBefore) {
		return DeviceCertificateIdentity{}, ErrUnauthorized
	}
	if certificate.IsCA || certificate.KeyUsage&x509.KeyUsageDigitalSignature == 0 {
		return DeviceCertificateIdentity{}, ErrUnauthorized
	}
	clientCertificate := false
	for _, usage := range certificate.ExtKeyUsage {
		if usage == x509.ExtKeyUsageClientAuth {
			clientCertificate = true
			break
		}
	}
	if !clientCertificate {
		return DeviceCertificateIdentity{}, ErrUnauthorized
	}
	for _, extension := range certificate.Extensions {
		if !extension.Id.Equal(deviceIdentityExtensionOID) {
			continue
		}
		if !extension.Critical {
			return DeviceCertificateIdentity{}, ErrUnauthorized
		}
		var identity DeviceCertificateIdentity
		if err := json.Unmarshal(extension.Value, &identity); err != nil {
			return DeviceCertificateIdentity{}, ErrUnauthorized
		}
		if identity.NodeID == "" || identity.PeerID == "" || identity.DeviceID == "" ||
			identity.PeerGeneration < 1 || identity.DeviceGeneration < 1 ||
			(identity.SubscriptionID == "" && identity.SubscriptionGeneration != 0) ||
			(identity.SubscriptionID != "" && identity.SubscriptionGeneration < 1) {
			return DeviceCertificateIdentity{}, ErrUnauthorized
		}
		return identity, nil
	}
	return DeviceCertificateIdentity{}, ErrUnauthorized
}

func (store *PeerStore) OpenDeviceSession(identity DeviceCertificateIdentity, publicKeyDER []byte) (DataSession, error) {
	parsed, err := x509.ParsePKIXPublicKey(publicKeyDER)
	publicKey, ok := parsed.(*ecdsa.PublicKey)
	if err != nil || !ok || publicKey.Curve != elliptic.P256() {
		return DataSession{}, ErrUnauthorized
	}
	keyFingerprint := fingerprint(publicKeyDER)
	var session DataSession
	var authorizationError error
	err = store.update(func(state *agentState) error {
		now := time.Now().UTC()
		for sessionID, activeSession := range state.Sessions {
			if !activeSession.ExpiresAt.After(now) {
				delete(state.Sessions, sessionID)
			}
		}
		peer, exists := state.Peers[identity.PeerID]
		if !exists {
			authorizationError = ErrNotFound
			return nil
		}
		if identity.NodeID != store.nodeID || !sameDeviceIdentity(peer, identity) ||
			peer.DeviceKeyFingerprint != keyFingerprint || peer.Status != "active" || !peer.ExpiresAt.After(now) {
			authorizationError = ErrUnauthorized
			return nil
		}
		for _, activeSession := range state.Sessions {
			if activeSession.PeerID == peer.PeerID {
				authorizationError = ErrCapacity
				return nil
			}
		}
		if len(state.Sessions) >= state.Capacity {
			authorizationError = ErrCapacity
			return nil
		}
		expiresAt := now.Add(sessionLifetime)
		if peer.ExpiresAt.Before(expiresAt) {
			expiresAt = peer.ExpiresAt
		}
		session = DataSession{
			PeerID: peer.PeerID, SessionID: randomID(), ExpiresAt: expiresAt, PeerExpiresAt: peer.ExpiresAt,
		}
		state.Sessions[session.SessionID] = sessionRecord{PeerID: peer.PeerID, ExpiresAt: expiresAt}
		return nil
	})
	if err != nil {
		return DataSession{}, err
	}
	if authorizationError != nil {
		return DataSession{}, authorizationError
	}
	return session, nil
}

func (store *PeerStore) DisconnectPeerSessions(peerID string) error {
	if peerID == "" {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		if _, exists := state.Peers[peerID]; !exists {
			return ErrNotFound
		}
		for sessionID, session := range state.Sessions {
			if session.PeerID == peerID {
				delete(state.Sessions, sessionID)
			}
		}
		return nil
	})
}

func sameDeviceIdentity(peer peerRecord, identity DeviceCertificateIdentity) bool {
	return identity.NodeID == peer.NodeID && identity.PeerID == peer.PeerID && identity.DeviceID == peer.DeviceID &&
		identity.SubscriptionID == peer.SubscriptionID && identity.SubscriptionGeneration == peer.SubscriptionGeneration &&
		identity.PeerGeneration == peer.PeerGeneration && identity.PeerGeneration == peer.Generation &&
		identity.DeviceGeneration == peer.DeviceGeneration && identity.DeviceGeneration == peer.DeviceBindingGeneration
}
