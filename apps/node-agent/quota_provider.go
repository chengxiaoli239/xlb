package agent

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
)

type DurableQuotaLeaseProvider struct {
	authority QuotaAuthority
	journal   QuotaJournal
	grant     int64
}

func NewDurableQuotaLeaseProvider(authority QuotaAuthority, journal QuotaJournal, grantBytes int64) (*DurableQuotaLeaseProvider, error) {
	if authority == nil || journal == nil || grantBytes < 1 {
		return nil, ErrInvalid
	}

	return &DurableQuotaLeaseProvider{authority: authority, journal: journal, grant: grantBytes}, nil
}

func (provider *DurableQuotaLeaseProvider) OpenLease(ctx context.Context, identity PeerQuotaIdentity) (*QuotaLease, error) {
	if ctx == nil || provider == nil || provider.authority == nil || provider.journal == nil || provider.grant < 1 ||
		identity.NodeID == "" || identity.EntitlementID == "" || identity.PeerID == "" || identity.Generation < 1 {
		return nil, ErrInvalid
	}
	requestID, err := newQuotaRequestID()
	if err != nil {
		return nil, errors.Join(ErrQuotaUnavailable, err)
	}
	return OpenQuotaLease(ctx, provider.authority, provider.journal, QuotaReservationRequest{
		EntitlementID: identity.EntitlementID,
		NodeID:        identity.NodeID,
		PeerID:        identity.PeerID,
		Generation:    identity.Generation,
		RequestID:     requestID,
		GrantBytes:    provider.grant,
	})
}

func newQuotaRequestID() (string, error) {
	value := make([]byte, 16)
	if _, err := rand.Read(value); err != nil {
		return "", err
	}
	value[6] = (value[6] & 0x0f) | 0x40
	value[8] = (value[8] & 0x3f) | 0x80
	encoded := hex.EncodeToString(value)
	return fmt.Sprintf("%s-%s-%s-%s-%s", encoded[:8], encoded[8:12], encoded[12:16], encoded[16:20], encoded[20:]), nil
}
