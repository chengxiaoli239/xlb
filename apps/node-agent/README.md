# Node Access Agent (isolated test only)

This Go module includes a loopback-only HTTP/mTLS control API and TLS 1.3/HTTP/2 TCP data plane around the peer store. `cmd/xlb-bridge` exposes an isolated-test SOCKS5 client that forwards TCP 80/443 streams to that data plane. It is restricted to `NODE_AGENT_MODE=isolated-test` and `XLB_BRIDGE_MODE=isolated-test`; production mode is deliberately unavailable. It does not configure Mihomo, WireGuard, TUN routing, or a production member profile. Its `confirmed_test` receipts are not production node acknowledgements. Do not connect it to member traffic or deploy it as a production Agent.

## Implemented in the local core

- Stable provisioning and revocation request IDs with payload conflict detection.
- Separate, idempotent subscription bind/rotation after native peer provisioning, with strictly increasing credential generations and challenge invalidation.
- Peer, device, subscription, and generation identity checks.
- Native app peers can use the registered device proof without a third-party subscription; third-party peers must bind a subscription ID, token, and generation.
- P-256 signed, short-lived, one-use connection challenges.
- Every authorization attempt that reaches a known challenge consumes it, including invalid proof and capacity rejection.
- One active session per peer and an atomic node capacity limit across local processes.
- Revocation receipts include node, peer, request, generation, acknowledgement ID, and final cumulative byte counters.
- State directories/files are owner-only; credential and URL-token material is stored as SHA-256 digests, not plaintext.
- State changes use a process-shared `flock`, fsync, and atomic file replacement.
- Expired sessions cannot be renewed; outstanding challenge count is bounded per peer; traffic counter overflow is rejected.
- HTTP endpoints require a trusted client certificate and TLS 1.3+, strictly decode bounded JSON, and the executable binds only to loopback.
- The optional data-plane probe listener is enabled only by explicitly setting `NODE_AGENT_PROBE_LISTEN` to a distinct loopback address. It reuses the Agent's TLS 1.3 mTLS identity and certificate policy, but the runnable Agent currently registers no `DataPlaneProbeExecutor`; every authenticated challenge therefore returns `503 probe_unavailable`. This listener cannot turn liveness or a fake echo into a healthy-node observation.
- `cmd/node-agent` refuses to start outside explicit isolated-test mode.
- The loopback data plane accepts TLS 1.3+, authorizes the leaf device certificate against the registered peer key and all generations, reserves node session capacity, and closes active flows before returning a final test revocation receipt.
- The isolated SOCKS5 bridge has no non-loopback listener by default and maps only TCP streams to the node's bounded HTTP/2 tunnel endpoint.
- The importable `xlb/node-agent/bridge` package accepts a `tls.Certificate` backed by `crypto.Signer` and verifies the signer matches the peer-proof certificate. Its local SOCKS5→TLS/mTLS→peer-proof→echo integration test now uses a signer wrapper that exposes no private-key export method, proving the bridge path works through the signer contract. This is an in-memory signer test, not an Android/Windows/macOS secure-store adapter; the command-line certificate/key loader remains isolated-test-only.
- `ProfileRenderer` defines the production-shaped `ProfileArtifact` identity/digest contract, including an HTTPS data-plane origin and CA trust bundle supplied to the renderer from trusted node configuration. `MihomoBridgeProfileRenderer` emits a structured Mihomo profile whose only proxy is the loopback SOCKS5 proof bridge; artifact validation reparses the YAML and rejects direct fallbacks, non-loopback targets, UDP, malformed/expired CA bundles, unknown fields, or client secret material. The PHP control plane now validates and encrypts per-node endpoint/CA configuration and returns the verified descriptor with a ready member grant. The renderer remains an offline component and is not wired into the executable, which still refuses production mode and uses `UnavailableProfileRenderer` semantics; no production member profile is issued.
- `apps/php-api/tests/Feature/NodePeerProvisioningTest.php` builds `testdata/php-provisioning` as a temporary loopback TLS 1.3/mTLS Node Agent fixture with an explicitly injected renderer. It verifies the PHP provisioning client, peer identity/generation, typed profile artifact, encrypted storage, exact node endpoint/CA matching, and the ready authorization response contract. All generated certificates, private keys, binary, and peer state are temporary test files; the runnable Agent still does not inject this renderer or serve member profiles. This test proves control-plane interoperability only, not that the profile's local bridge is running or a VPN connection can be established.
- The renderer output is pinned by `testdata/mihomo-local-bridge-profile.yaml`; this module checks exact structured YAML equivalence, while `apps/android/core-native/internal/debugprofile` parses the same fixture with the pinned Mihomo v1.19.34 config parser. This validates config syntax and semantics only, not an Android import, active bridge, node connection, or production profile delivery.
- Laravel has the shared entitlement reservation/settlement ledger and reverse quota routes. The Go core has a provider-neutral `QuotaAuthority`, durable reservation intent and final-settlement outboxes, cross-process byte permits, an HTTPS/TLS 1.3 `HTTPQuotaAuthority` mTLS client, and a `DurableQuotaLeaseProvider` that persists UUID-based reservation requests before authority calls. `NewRecoveredQuotaAuthorityDataPlane` drains bounded reservation and settlement outboxes before returning a data plane and fails closed when reconciliation cannot finish. The Go client is tested against local ephemeral certificates, and both PHP and Go validate one shared settlement-ack fixture. The executable still refuses production mode and is only usable as `isolated-test`; it is not a production node service or deployed mTLS connection.
- `TrustedNodeRuntimeConfig` strictly validates `xlb-node-runtime/v1` provider-neutral node identity, a loopback-only `xlb-proof-v1` bridge, device proof, and environment-specific key source. The isolated executable now requires and consumes this config at startup, and rejects production environment declarations or a node ID mismatch. Unknown fields and any renderer declaration other than `unavailable` are rejected; production runtime support remains unavailable.
- An opt-in Go test now runs the HTTPQuotaAuthority client through a local TLS 1.3 mTLS server into PHP CGI and Laravel's real `public/index.php` with a temporary SQLite database. It verifies certificate-to-node mapping, reservation and final settlement, rejects missing certificates and mismatched node IDs. This test adapter is local-only and does not validate a deployed reverse proxy or production certificates.

## Verification

```sh
go test -race ./...
go vet ./...
go build ./cmd/node-agent
```

The process test starts 12 child processes behind a barrier; exactly 3 may reserve a node configured with capacity 3. Local data-plane tests cover HTTP/2 capacity reservation, certificate identity copy rejection, revocation closure, and final traffic accounting. `bridge/live_acceptance_test.go` is opt-in through SSM forwards and exercises 12 processes, independent test identities, HTTPS egress, copied-key denial, and revocation on an isolated node. These test identities are synthetic and do not constitute a valid member session or member purchase-flow acceptance.

The Laravel/Go mTLS integration is run through `php artisan test --compact tests/Feature/NodePeerTrafficQuotaTest.php --filter=test_go_quota_authority_integrates_with_laravel_over_local_mutual_tls`. It uses ephemeral test certificates and a test database; it is not a cloud or production acceptance.

## Required before deployment

1. Connect the isolated Agent state machine to the production control plane with durable bind/rotate outbox and receipt persistence before any credential is issued.
2. Replace test-only receipts, certificate lifecycle, state retention, health/metrics, and deployment controls with a reviewed production protocol; protect proof keys with platform secure storage.
3. Provision one-time test CA/server/client certificates outside the repository for isolated integration. Production mTLS material must be provisioned separately through an approved secret channel.
4. Add bounded audit logs, startup recovery, operation cleanup, and deployment hash verification.
5. Run the opt-in multi-process Agent/data-plane acceptance on an isolated node; local tests alone do not prove cloud-node behavior.
6. Complete M6 member acceptance with an isolated valid member session and two independently keyed proof-capable client identities. Ordinary Clash/Mihomo imports do not satisfy device proof.

## Isolated test server

The server requires `NODE_AGENT_MODE=isolated-test`, `NODE_AGENT_NODE_ID`, `NODE_AGENT_RUNTIME_CONFIG`, `NODE_AGENT_STATE_PATH`, `NODE_AGENT_SERVER_CERT`, `NODE_AGENT_SERVER_KEY`, and `NODE_AGENT_CLIENT_CA`. Startup strictly loads runtime v1 and requires its environment and node ID to match the isolated process; renderer status must remain `unavailable`. The optional mTLS `GET /v1/test/status` endpoint returns only active-session and capacity counts so acceptance tests can distinguish proof rejection from a full node; it never returns peer identifiers. `NODE_AGENT_CAPACITY` must be between 1 and 4096; management and data listeners default to `127.0.0.1:9443` and `127.0.0.1:9444` and must remain loopback-only. The optional `NODE_AGENT_PROBE_LISTEN` must use a distinct loopback address; until a real `DataPlaneProbeExecutor` is wired, authenticated challenges return `503 probe_unavailable`, never probe success. These controls are isolated-test only. No production member traffic is supported.
