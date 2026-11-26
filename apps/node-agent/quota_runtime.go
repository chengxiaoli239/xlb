package agent

import (
	"context"
	"crypto/x509"
	"time"
)

// NewRuntimeDataPlane constructs quota enforcement only from a fully validated
// trusted runtime. Production-shaped configuration must complete durable
// reservation and settlement recovery before the data plane is returned.
func NewRuntimeDataPlane(
	ctx context.Context,
	store *PeerStore,
	dialer TargetDialer,
	identity NodeTLSIdentity,
	config TrustedNodeRuntimeConfig,
) (*DataPlane, error) {
	if ctx == nil || store == nil || dialer == nil || store.nodeID != config.Node.ID || config.Validate() != nil {
		return nil, ErrInvalidNodeRuntimeConfig
	}
	if config.Environment == RuntimeEnvironmentTest {
		return NewDataPlane(store, dialer)
	}
	if config.Environment != RuntimeEnvironmentProd {
		return nil, ErrInvalidNodeRuntimeConfig
	}
	if validateNodeTLSIdentity(identity) != nil {
		return nil, ErrInvalidNodeRuntimeConfig
	}

	_, authorities, err := parseNodeTLSCertificatePEM([]byte(config.QuotaAuthority.ServerCAPEM))
	if err != nil || len(authorities) == 0 {
		return nil, ErrInvalidNodeRuntimeConfig
	}
	serverRoots := x509.NewCertPool()
	for _, authority := range authorities {
		serverRoots.AddCert(authority)
	}
	quotaAuthority, err := NewHTTPQuotaAuthority(
		config.QuotaAuthority.Endpoint,
		serverRoots,
		identity.Certificate,
		time.Duration(config.QuotaAuthority.TimeoutSeconds)*time.Second,
	)
	if err != nil {
		return nil, ErrInvalidNodeRuntimeConfig
	}
	return NewRecoveredQuotaAuthorityDataPlane(
		ctx,
		store,
		dialer,
		quotaAuthority,
		config.QuotaAuthority.GrantBytes,
		config.QuotaAuthority.OutboxLimit,
	)
}
