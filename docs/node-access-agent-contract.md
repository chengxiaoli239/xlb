# Node Access Agent Contract

Status: the Laravel control plane has a shared entitlement byte ledger with atomic reservation/receipt settlement and reverse quota routes protected by a node-certificate fingerprint mapping. The Go core has a provider-neutral quota authority interface, durable local lease journal/retry outbox, per-write byte permits, an HTTPS/TLS 1.3 mTLS authority client, and an optional quota-enforced isolated data plane. A shared settlement-ack fixture is asserted exactly by Laravel and parsed with Go's strict decoder. This locks the response field contract, but is not a live Laravel-to-Go mTLS session. The routes have not been exercised through a deployed TLS terminator or wired into a production Agent executable. The loopback-only test Agent emits `confirmed_test`, has no production profile renderer, and does not configure Mihomo/TUN. Nothing here is deployable for member traffic.

## Trust Boundary

- The Laravel control plane selects an already provisioned, healthy member node. Cloud-provider APIs provision whole nodes; this contract manages member peers on a node and does not depend on AWS.
- Admin-only node deployment profiles and test nodes are never member routes. A member profile is issued only for a production-scoped route, a non-test node, a fresh healthy result, an active entitlement, and a device that has proved its registered key.
- The control plane stores peer credentials and returned profiles with Laravel authenticated encryption. Plaintext peer credentials are sent only in the mutually authenticated request to the selected Agent. Agent and proxy logs must redact credentials and profile payloads.
- Every device subscription is bound to exactly one `node_access_peer_id`, peer generation, and device binding generation. A missing or stale binding is denied; it is never repaired by falling back to an account-wide credential.
- The member profile is returned only after the peer, subscription, device, their generations, and the peer's specific active entitlement match. Provisioning and subscription-binding processors recheck that same entitlement before and after the remote Agent call; if it changes while a request is in flight, the late receipt is superseded and the peer is queued for revocation.
- `POST /api/v1/me/device-bindings/authorize` verifies the one-use device challenge before asking Laravel to resolve the node peer. Its `node_authorization` response includes the peer credential only for `status=ready`, after a matching confirmed node provisioning receipt, the peer's own active and unexpired entitlement, registered device, healthy non-test node, proof fingerprint, peer fingerprint, and device-binding generation have all been rechecked. Provisioning, revocation, unavailable, expired, or mismatched states contain no peer credential or profile. This authenticated response uses `Cache-Control: no-store, private`.
- Third-party credential issuance and profile fetch also require the peer's own active entitlement and unexpired peer. An account's newer active entitlement cannot reactivate an old peer or expose its previous profile; a new entitlement must first receive its own confirmed peer authorization.

## Transport

- Control-plane to Agent traffic uses HTTPS with TLS server validation against the configured CA bundle and a client certificate plus private key (mTLS). Redirects are disabled and requests have a bounded timeout.
- Agent success responses are JSON envelopes of the form `{"data": {...}}`; Laravel `NodeAccessAgentHttpClient` validates and unwraps the data object before acknowledgement validation. Error responses use the Agent error envelope and non-success HTTP status.
- Agent endpoints must be administrator-controlled node records; never accept an endpoint from a member request. Production must use a private, controlled endpoint and a dedicated client certificate whose issuer and scope are limited to the member-node Agent service.
- A transport timeout is an unknown outcome, not a failed operation. Retry the same operation with the same request ID and generation. Do not release a peer slot, expose a profile, or report revocation until a matching Agent acknowledgement is durably recorded.

## Provisioning

`POST /v1/member-peers/ensure`

Request JSON:

```json
{
  "node_id": "node UUID",
  "peer_id": "peer UUID",
  "request_id": "stable operation UUID",
  "generation": 1,
  "device_id": "registered device UUID",
  "device_name": "registered device label",
  "device_public_key": "base64url P-256 public key",
  "device_key_fingerprint": "sha256 hex",
  "device_binding_generation": 1,
  "peer_generation": 1,
  "device_generation": 1,
  "expires_at": "RFC 3339 timestamp",
  "credential": "secret peer credential"
}
```

The initial Laravel ensure payload maps its registered `device_id`, the provisioning generation to both `generation` and `peer_generation`, and the device binding generation to both `device_binding_generation` and `device_generation`. The Agent rejects requests unless each duplicated generation agrees. It treats `(node_id, peer_id, request_id, generation)` as an idempotency key. Replaying an accepted request returns the original result and must not create a second peer or rotate its credential. A generation lower than the latest accepted generation is rejected. A later generation is a distinct explicit lifecycle operation.

The Agent stores the P-256 device proof key, its fingerprint, and `device_binding_generation` alongside the peer credential. Native app peers may have no third-party subscription and use an empty subscription ID with generation `0`; third-party peers must have a bound subscription ID and positive subscription credential generation. Every peer authorization matches the provisioned node ID, peer ID, device ID, peer generation, and device binding generation; third-party authorization additionally matches subscription ID and credential generation. The credential, generations, and peer expiry are checked before accepting a session; a stale, expired, or mismatched identity is denied.

Isolated-test response JSON:

```json
{
  "data": {
    "node_id": "node UUID",
    "peer_id": "peer UUID",
    "request_id": "same operation UUID",
    "generation": 1,
    "acknowledgement_id": "durable Agent receipt ID",
    "confirmation_status": "confirmed_test"
  }
}
```

The isolated-test server returns this control receipt without a profile artifact. It cannot activate a production peer. The single production `profile_artifact` schema is:

```json
{
  "node_id": "node UUID",
  "peer_id": "peer UUID",
  "entitlement_id": "entitlement UUID",
  "request_id": "stable provisioning UUID",
  "generation": 1,
  "device_id": "registered device UUID",
  "device_key_fingerprint": "sha256 hex",
  "device_binding_generation": 1,
  "format": "mihomo-yaml",
  "renderer_id": "xlb-mihomo-bridge-v1",
  "renderer_version": "1.0.0",
  "sha256": "sha256 hex of content",
  "bridge_protocol": "xlb-proof-v1",
  "bridge_endpoint": "https://node.example.invalid:9444",
  "bridge_server_ca_pem": "PEM CA certificate bundle for the node data plane",
  "connectable": true,
  "content": "renderer output without device secrets"
}
```

The native format is `xlb-client-manifest-v1` from `xlb-native-bridge-v1`; both formats use `xlb-proof-v1`. The artifact must bind exactly to the receipt's node, peer, entitlement, request and generation plus the device ID, key fingerprint and binding generation. The Go renderer descriptor is constructed from trusted node runtime inputs, never member request fields; its validation requires a path-free HTTPS origin and a current CA certificate bundle with CA constraints and certificate-signing usage. PHP additionally verifies the endpoint host against administrator-controlled node identity and the returned CA against the dedicated `NODE_ACCESS_MEMBER_BRIDGE_CA_BUNDLE` data-plane trust bundle before persisting or returning either field. That CA setting is separate from the control-plane Agent mTLS CA bundle. The CA bundle contains public trust anchors, not private keys. PHP and Go validate the profile identity field set and digest. Client profile content must not embed private keys, peer credentials, peer secrets, or subscription tokens. Laravel encrypts profile content and any persisted trust material at rest, and clears bridge trust data when final revocation is confirmed. A test receipt must not set a production renderer identity or `connectable=true`.

A production provisioning response nests this object at `data.profile_artifact`; its outer `data` object carries `node_id`, `peer_id`, `request_id`, `generation`, `acknowledgement_id`, and `confirmation_status: confirmed`. The artifact and outer receipt identity must agree. The provisioning processor persists the matching acknowledgement and encrypted artifact before it marks the peer ready or releases the profile to a device-proven session. The isolated test response intentionally omits this object and uses `confirmed_test`.

The required production renderer derives the upstream endpoint, CA and route policy from the Agent's trusted node runtime configuration and the device-bound peer record, never from client-supplied profile fields. Mihomo output must point only to the local proof bridge (loopback SOCKS endpoint); it must not contain a direct node proxy. The native manifest must describe the same bridge protocol, node/peer identity and generation. The bridge obtains its proof key from the platform secure store and proves device identity to the node. The Go Mihomo renderer now requires injected HTTPS endpoint and server CA fields and binds them into its artifact. PHP validates the response endpoint host against the configured node Agent host and CA against a dedicated member data-plane trust bundle, then encrypts and returns the descriptor only for a ready peer. The renderer remains an offline component, PHP has no Admin workflow to provision actual endpoint/trust values, and no production renderer is enabled. Isolated Agent receipts remain `confirmed_test`. Do not make a sample YAML or test Agent output activate a member tunnel. Missing, malformed, or mismatched artifacts keep access unavailable and retryable.

## Trusted Node Runtime Input v1

`apps/node-agent/runtime_config.go` defines the provider-neutral `xlb-node-runtime/v1` input contract. It carries only node ID, provider and region; proof bridge protocol, loopback listener, proof requirement and key-source class; and an explicit profile-renderer status. It does not carry endpoints from a member profile, subscription credentials, peer secrets, or private keys.

The strict decoder rejects unknown fields, oversized or trailing JSON, unsupported schema versions, non-loopback listeners, disabled device proof, and key sources that do not match the declared environment. `isolated-test` accepts only `test-file-only`; `production` requires the declaration `platform-secure-store`. The v1 renderer status is fixed to `unavailable`; claiming `ready` is rejected until a renderer implementation and a new reviewed contract version exist. The executable does not load this configuration and still refuses production mode, so this is validated input groundwork rather than a deployed runtime.

Go tests accept both provider-neutral test and production-shaped metadata while rejecting public listeners, unknown secret fields, insecure key-source combinations, and premature renderer readiness. No profile content is generated by these tests.

## Local Laravel/Go mTLS Integration

`TestHTTPQuotaAuthorityAgainstLaravelCGI` is opt-in through the PHP feature test. A local Go TLS 1.3 server requires a verified client certificate, then passes only the verified leaf certificate into PHP CGI's `SSL_CLIENT_VERIFY` and `SSL_CLIENT_CERT` server variables. PHP CGI executes the real Laravel `public/index.php` against the feature test's temporary SQLite database. The Go `HTTPQuotaAuthority` performs a reservation and final settlement through the real quota routes; the test also verifies that missing client certificates fail during TLS and that a valid certificate cannot claim a different node ID.

This integration validates the application routes, Go wire decoding, certificate fingerprint mapping and local database effects. The CGI TLS adapter is test-only; it does not validate Nginx/FastCGI deployment settings, production CA provisioning, production database concurrency, cloud node receipts, or member purchase flow.

## Third-Party Subscription Binding

The current member lifecycle can provision a native app peer before a third-party subscription exists. After an active peer is established, bind the per-device subscription in a separate idempotent operation:

`POST /v1/member-peers/bind-subscription`

```json
{
  "node_id": "node UUID",
  "peer_id": "peer UUID",
  "request_id": "stable binding or rotation UUID",
  "subscription_id": "member subscription UUID",
  "subscription_token": "secret URL token",
  "subscription_generation": 1,
  "peer_generation": 1,
  "device_generation": 1
}
```

The Agent stores only the subscription token hash. A retry with the same request ID and payload returns the original receipt; a reused ID with another payload is rejected. The subscription ID remains fixed to that peer and its generation must strictly increase on rotation. A successful rotation invalidates outstanding challenges for that peer so an old URL token cannot complete authorization. The receipt binds node, peer, request, subscription, and generation. Laravel must persist this acknowledgement before it exposes the rotated URL; a failed or unknown outcome keeps the new URL unavailable and retryable.

The local Go peer-store core implements this state transition and tests idempotent binding, generation-increasing rotation, stale URL token rejection, and challenge invalidation. Laravel now has a local device-scoped credential controller, encrypted token storage, binding outbox with stable request IDs, mTLS Agent driver, acknowledgement validation, and a guarded profile response. These remain local/test-only and production signing is disabled. The Agent has no production binding deployment, Mihomo profile renderer, or packaged proof bridge.

## Revocation

`POST /v1/member-peers/revoke`

Request JSON:

```json
{
  "node_id": "node UUID",
  "peer_id": "peer UUID",
  "request_id": "stable operation UUID",
  "generation": 2
}
```

The Agent idempotently disables and removes the peer, then returns a durable receipt with the same identity fields, `acknowledgement_id`, final cumulative `upload_bytes`, final cumulative `download_bytes`, and `confirmation_status: confirmed` (the isolated test Agent returns `confirmed_test`). Final counters must never be lower than counters previously reported for that peer. The control plane persists `max(previous, final)` and does not mark a peer `revoked`, clear its encrypted material, release its node capacity, or release a removed device slot before validating this acknowledgement.

Rotating or revoking a third-party subscription invalidates its URL immediately and queues peer revocation. The API reports `revocation_pending`; it does not imply the existing tunnel has already stopped. Only the matching final node receipt moves the peer to `revoked`.

## Connection Proof

For proof-capable clients, a node challenge is one-use and short-lived. The client signs the canonical `NodePeerConnectionProof::message` fields with its registered P-256 private key: node ID, peer ID, device ID, subscription ID, subscription credential generation, peer generation, device binding generation, and challenge ID. The node verifies the signature and all provisioned identities before opening a session. A challenge cannot be replayed, and at most one active session is allowed per peer; the node-wide session cap is reserved atomically and cannot be oversold. Revocation pending denies new sessions immediately; a matching receipt removes all active sessions for that peer.

The local PHP `SandboxNodePeerRuntime` is a single-process deterministic fake that exercises these checks, credential copying, staggered reuse, peer/node session limits, and receipt handling. Its sequential competing-open test proves the fake rejects authorization beyond its configured capacity; it does not prove atomic capacity reservation across real Agent workers or simultaneous network requests. That requires an Agent-side atomic reservation and isolated-node concurrency testing. The fake is not deployed on a node and does not prove Clash Verge compatibility. Ordinary Clash/Mihomo imports do not sign this device challenge, so a copied subscription/profile cannot be proven to belong to the importing device by the stock clients. Such clients must not be advertised as strictly device-limited until a proof-capable local bridge/client and real Agent have been implemented and tested.

`apps/node-agent` includes a loopback-only mTLS control server requiring TLS 1.3 and a trusted operator client certificate, plus a separate TLS 1.3 data listener that verifies a device certificate's registered P-256 key and node/peer/device/subscription generations in the handler. It exposes ensure, bind-subscription, challenge, authorize, renew, disconnect, usage, and revoke operations; its explicit isolated-test executable refuses non-loopback addresses. The peer store uses file locks and atomic replacement and covers stable operation IDs, one-use challenges, per-peer sessions, multi-process capacity reservation, and cumulative traffic counters. The test data plane closes active HTTP/2 streams before final revocation receipt and tracks streams through connection close. It does not configure a production VPN protocol, Mihomo/TUN, or member profile and emits only `confirmed_test`. If the Agent process crashes before a stream's completed counters are persisted, this test implementation has no replicated usage journal. Its HTTP and race-enabled tests prove local test-mode behavior only. Deployment and real-node acceptance remain open.

The selected third-party direction is a proof-capable local bridge: a Clash/Mihomo profile may point only at a loopback SOCKS listener, while the bridge uses a device key from the platform secure store to authenticate to the node. The imported YAML must not contain the device private key, peer credential, or subscription token. The current isolated `xlb-bridge` accepts test certificate/key files and is not a packaged production client; copying those files can copy the test identity. Windows DPAPI/TPM, Apple Keychain/Secure Enclave, and Android Keystore integration, profile-to-bridge lifecycle management, and two-device real acceptance are still open. Stock Clash/Mihomo without this companion cannot prove device identity and remains outside the strict-device support claim.

The native device-binding response now returns the peer and node IDs, peer/device-binding generations, peer credential, and profile together only after the current device proof and node confirmation pass the checks above. This is response plumbing, not proof that a production profile can currently be issued: the isolated Go Agent returns `confirmed_test` without a profile, and the Laravel provision processor fails closed until a bounded profile is present. Real node profile rendering and the packaged bridge/TUN integration are not implemented.

## Usage Accounting

`GET /v1/member-peers/{peerID}/usage` returns a cumulative snapshot bound to node ID, peer ID, and peer generation. Laravel validates identity and monotonic counters, applies only the unaccounted delta, and queues idempotent revocation when the entitlement limit is reached. The shared quota core is `TrafficQuotaReservationService`; every node reservation reduces the same entitlement budget:

- `reserve(entitlement_id, node_id, peer_id, generation, request_id, grant_bytes, lease_seconds)` atomically increments the single entitlement budget only if `used_bytes + reserved_bytes + grant_bytes <= quota_bytes`. Request IDs are idempotent; reusing one with changed identity or grant is rejected. The lease is 5 to 120 seconds.
- `settle` accepts cumulative upload/download totals bound to the reservation's node, peer, request ID, and peer generation. Counters must be monotonic and their sum cannot exceed the grant. Intermediate receipts consume only newly observed bytes. A matching `final=true` receipt releases the unused remainder; timeout, invalid receipt, or expired lease without a final receipt retains the reservation.
- Snapshot and receipt accounting share per-peer monotonic accounted counters. The entitlement is charged by the maximum of the latest node snapshot and summed settled reservation totals, preventing a later snapshot from billing already-settled bytes twice. Legacy peer watermarks are initialized from their existing cumulative counters.

Canonical provider-neutral Go payloads use `entitlement_id`, `node_id`, `peer_id`, `generation`, `request_id`, and `grant_bytes` for reservation; the returned lease echoes those fields and adds `id`, `status=reserved`, and `lease_expires_at`. Final settlement uses `node_id`, `peer_id`, `generation`, `request_id`, `acknowledgement_id`, cumulative `upload_bytes` / `download_bytes`, and `final=true`. Its acknowledgement echoes the same fields and adds `settled=true`. The control plane derives test/production confirmation status from the authenticated node identity, not from a caller-controlled JSON field.

The canonical reverse API paths are `POST /v1/node-quota/reservations` and `POST /v1/node-quota/reservations/{request_id}/settlements`, intended for TLS 1.3 mTLS. The Go `HTTPQuotaAuthority` client validates the server CA, presents a node client certificate, bounds timeout/body size, disables redirects, strictly decodes responses, validates identity/lease fields, and retries the same body and request ID. Laravel provides both routes and maps the SHA-256 fingerprint of the TLS-verified leaf certificate to exactly one available, healthy, non-test node. The request's `node_id` must match the certificate-mapped node. The controller derives confirmation status from that authenticated node and ignores caller-supplied confirmation fields. The fingerprint column is unique; TLS termination must overwrite the `SSL_CLIENT_VERIFY` and `SSL_CLIENT_CERT` server variables and must not forward client-controlled equivalents as trusted server variables. Laravel feature tests inject those server variables; they do not validate a live TLS terminator or production certificate deployment. The authority enforces idempotency and serializes quota updates at the shared entitlement budget across nodes. A timeout has unknown outcome: replay the same request ID and body. The Agent persists reservation intent before calling the authority, forwards no bytes until a lease is confirmed, reserves a local byte permit before every write, and retries the exact final cumulative settlement from its durable outbox. Unknown or invalid reservation/settlement outcomes fail closed; a lease is never released solely because its timer expired.

The local PHP database core has a 12-process SQLite test where reservation requests for peers on three nodes compete for one shared three-byte entitlement budget; exactly three one-byte reservations succeed. A separate assertion proves two distinct node/peer pairs consume the same entitlement budget. PHP compares both reservation and settlement endpoint bodies with the shared fixtures under `apps/node-agent/testdata/`; Go's strict decoder validates those same fixtures. Go's `DurableQuotaLeaseProvider` makes a Laravel-compatible UUID request ID, durably records the intent, and calls the injected authority. `NewRecoveredQuotaAuthorityDataPlane` replays bounded settlement and reservation outboxes before constructing the quota-enforced data plane; unresolved recovery returns an error and no plane. The executable still rejects production mode and only permits the isolated-test server. Go tests share one isolated fake authority across multiple Agent journals/nodes and separately race local file-backed byte permits across processes. Local mTLS tests validate the client against an ephemeral test authority, including a lost reservation response retried with the same request ID. These prove local contracts and test doubles only. They are not a production database concurrency result, live Laravel-to-Go TLS integration, or deployed data-plane result. Until the Go client is connected to the Laravel API and injected into a real production data plane, the real snapshot reconciliation window remains open and production online quota enforcement is not release-ready.

## Control-Plane States and Retries

- Provisioning: `node_access_peers.status=provisioning`; request resides in `node_access_provisioning_outbox`.
- Ready: only a matching production acknowledgement changes the peer to `active`; credentials and profile remain encrypted at rest.
- Revocation requested: the peer immediately becomes `revocation_pending`, blocking new configuration delivery and connection authorization. The outbox keeps the same request ID and generation through retry and lease recovery.
- Revoked: only a matching node acknowledgement with final counters moves the peer to `revoked`. Unknown outcome, HTTP error, or invalid acknowledgement remains retryable and must not be shown as confirmed disconnection.
- Device removal keeps the registered device counted against quota while any peer lacks a revocation acknowledgement. After all associated peers are confirmed revoked, the device becomes removed and frees its slot.
- Refund, expiry, traffic exhaustion, account disablement, and member-requested device removal all use the same peer revocation path.

## Sandbox Adapter

The local/test sandbox driver accepts only test nodes and only in local/testing environments. Its profile is explicitly `sandbox-only` and `connectable: false`; its reserved `192.0.2.0/24` endpoint is isolated from admin demo nodes and member routes. Its acknowledgement status is `confirmed_test`. It exercises deterministic identity and state transitions; it is not a node, member route, connection test, or release proof. Production dependency injection must select the HTTPS Agent driver or fail closed.

## Acceptance Tests

The PHP suite must cover duplicate operations, server timeout after node execution and replay with the same idempotency key, wrong node/peer/request/generation receipts, retryable revocation, monotonic final traffic, encrypted-at-rest credentials and profiles, proof-gated profile delivery, device-slot retention until all receipts, peer/device/subscription generation binding, copied-credential simultaneous and staggered denial, and sandbox isolation. Isolated member session, AWS authorization and IAM boundary, deployed Agent mTLS credentials, a real isolated node peer, Android tunnel handshake, iOS acceptance scope, and network egress validation remain external acceptance gates.
