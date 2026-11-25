#![allow(dead_code)] // Process supervision stays disabled until the production adapter contract is implemented.

use std::{fmt, sync::Arc};

use base64::{engine::general_purpose::{STANDARD, URL_SAFE_NO_PAD}, Engine as _};
use rustls::{pki_types::CertificateDer, RootCertStore};
use serde::Serialize;
use sha2::{Digest, Sha256};
use zeroize::Zeroize;

const PROOF_BRIDGE_ENABLED: bool = false;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
/// `Ready` means the local adapter completed its proof/peer readiness contract;
/// it never means VPN routing or traffic has been verified.
enum BridgeState {
    Stopped,
    Starting,
    Ready,
    Failed,
    Stopping,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub(super) enum BridgeFailure {
    FeatureDisabled,
    InvalidMemberProof,
    InvalidMemberGrant,
    DeviceKeyUnavailable,
    DeviceProofSigningFailed,
    LaunchFailed,
    ProcessExited,
    StopFailed,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
struct BridgeSnapshot {
    state: BridgeState,
    failure: Option<BridgeFailure>,
}

/// Signing capability supplied by the platform's non-exportable device key.
/// Implementations must never return private key material.
pub(super) trait DeviceProofSigner: Send + Sync {
    fn public_key_spki_base64url(&self) -> Result<String, BridgeFailure>;
    fn sign_challenge_base64url(&self, challenge: &[u8; 32]) -> Result<String, BridgeFailure>;
}

/// An unforgeable capability created only by the native member/device verifier.
/// Keep fields and constructor private so IDs or booleans cannot claim proof success.
pub(super) struct VerifiedMemberDeviceProof {
    member_id: String,
    installation_id: String,
    device_id: String,
    peer_binding_id: String,
    node_id: String,
    peer_generation: u64,
    device_binding_generation: u64,
    registered_public_key_spki_base64url: String,
    signer: Box<dyn DeviceProofSigner>,
}

impl VerifiedMemberDeviceProof {
    /// Creates the capability only from a complete server-authorized peer response.
    /// Peer credentials and profile content are validated here but never retained.
    pub(super) fn from_authorized_response(
        member_id: &str,
        installation_id: &str,
        device: &super::BoundDevice,
        authorization: &super::DeviceAuthorization,
        registered_public_key_spki_base64url: &str,
        signer: Arc<dyn super::DeviceProofProvider>,
    ) -> Result<Self, BridgeFailure> {
        let peer_id = authorization.peer_id.as_deref().unwrap_or_default();
        let node_id = authorization.node_id.as_deref().unwrap_or_default();
        let peer_credential = authorization.peer_credential.as_deref().unwrap_or_default();
        let profile = authorization.profile.as_deref().unwrap_or_default();
        let peer_generation = authorization.peer_generation.unwrap_or_default();
        let device_binding_generation = authorization.device_binding_generation.unwrap_or_default();
        let credential = super::decode_base64url(peer_credential).ok_or(BridgeFailure::InvalidMemberProof)?;
        let profile_text = profile.to_ascii_lowercase();
        let signer_installation_id = signer.installation_id();

        if authorization.status != "ready"
            || member_id.trim().is_empty()
            || !device.bound
            || device.status != "registered"
            || !valid_uuid(installation_id)
            || signer_installation_id != installation_id
            || !valid_uuid(&device.id)
            || !valid_uuid(peer_id)
            || !valid_uuid(node_id)
            || peer_generation == 0
            || device_binding_generation == 0
            || credential.len() != 32
            || profile.is_empty()
            || profile.len() > 128 * 1024
            || [
                "private_key:",
                "privatekey:",
                "peer_credential:",
                "peercredential:",
                "peer_secret:",
                "peersecret:",
                "subscription_token:",
                "subscriptiontoken:",
            ]
                .iter()
                .any(|field| profile_text.contains(field))
        {
            return Err(BridgeFailure::InvalidMemberProof);
        }

        let signer = DeviceProofSignerAdapter(signer);
        let current_public_key = signer.public_key_spki_base64url()?;
        if registered_public_key_spki_base64url.trim().is_empty()
            || current_public_key != registered_public_key_spki_base64url
        {
            return Err(BridgeFailure::DeviceKeyUnavailable);
        }

        Ok(Self {
            member_id: member_id.to_owned(),
            installation_id: installation_id.to_owned(),
            device_id: device.id.clone(),
            peer_binding_id: peer_id.to_owned(),
            node_id: node_id.to_owned(),
            peer_generation,
            device_binding_generation,
            registered_public_key_spki_base64url: registered_public_key_spki_base64url.to_owned(),
            signer: Box::new(signer),
        })
    }

    pub(super) fn signer(&self) -> &dyn DeviceProofSigner {
        self.signer.as_ref()
    }

    pub(super) fn matches_device_snapshot(&self, devices: &super::DeviceSummary) -> bool {
        devices.entitlement_active
            && devices.devices.iter().any(|device| {
                device.id == self.device_id
                    && device.installation_id == self.installation_id
                    && device.status == "registered"
            })
    }
}

/// Short-lived server-authorized inputs for one tunnel attempt. Runtime secrets
/// stay separate from the reusable device-proof capability and are redacted
/// from debug output and zeroized when this grant is dropped.
pub(super) struct AuthorizedMemberTunnelGrant {
    proof: Arc<VerifiedMemberDeviceProof>,
    bridge_endpoint: url::Url,
    trusted_server_roots: RootCertStore,
    peer_credential: String,
    profile: String,
    profile_renderer_id: String,
    profile_renderer_version: String,
}

impl AuthorizedMemberTunnelGrant {
    pub(super) fn from_authorized_response(
        proof: Arc<VerifiedMemberDeviceProof>,
        authorization: &super::DeviceAuthorization,
    ) -> Result<Self, BridgeFailure> {
        let peer_generation = authorization.peer_generation.unwrap_or_default();
        let device_binding_generation = authorization.device_binding_generation.unwrap_or_default();
        let bridge_endpoint = authorization.bridge_endpoint.as_deref().unwrap_or_default();
        let server_ca_pem = authorization.bridge_server_ca_pem.as_deref().unwrap_or_default();
        let profile_digest = authorization.profile_sha256.as_deref().unwrap_or_default();
        let renderer_id = authorization.profile_renderer_id.as_deref().unwrap_or_default();
        let renderer_version = authorization.profile_renderer_version.as_deref().unwrap_or_default();
        let bridge_protocol = authorization.bridge_protocol.as_deref().unwrap_or_default();
        let peer_credential = authorization.peer_credential.as_deref().unwrap_or_default();
        let profile = authorization.profile.as_deref().unwrap_or_default();

        let endpoint = url::Url::parse(bridge_endpoint).map_err(|_| BridgeFailure::InvalidMemberGrant)?;
        let mut credential = URL_SAFE_NO_PAD
            .decode(peer_credential)
            .map_err(|_| BridgeFailure::InvalidMemberGrant)?;
        if credential.len() != 32 {
            credential.zeroize();
            return Err(BridgeFailure::InvalidMemberGrant);
        }
        credential.zeroize();

        if authorization.status != "ready"
            || authorization.connectable != Some(true)
            || authorization.peer_id.as_deref() != Some(proof.peer_binding_id.as_str())
            || authorization.node_id.as_deref() != Some(proof.node_id.as_str())
            || peer_generation != proof.peer_generation
            || device_binding_generation != proof.device_binding_generation
            || !valid_bridge_endpoint(&endpoint)
            || profile.is_empty()
            || profile.len() > MAX_PROFILE_BYTES
            || contains_secret_field(profile)
            || !valid_sha256(profile, profile_digest)
            || renderer_id != "xlb-mihomo-bridge-v1"
            || !valid_renderer_version(renderer_version)
            || bridge_protocol != "xlb-proof-v1"
        {
            return Err(BridgeFailure::InvalidMemberGrant);
        }

        let trusted_server_roots = parse_ca_bundle(server_ca_pem)?;
        Ok(Self {
            proof,
            bridge_endpoint: endpoint,
            trusted_server_roots,
            peer_credential: peer_credential.to_owned(),
            profile: profile.to_owned(),
            profile_renderer_id: renderer_id.to_owned(),
            profile_renderer_version: renderer_version.to_owned(),
        })
    }

    pub(super) fn proof(&self) -> &VerifiedMemberDeviceProof {
        self.proof.as_ref()
    }

    pub(super) fn bridge_endpoint(&self) -> &url::Url {
        &self.bridge_endpoint
    }

    pub(super) fn trusted_server_roots(&self) -> &RootCertStore {
        &self.trusted_server_roots
    }

    pub(super) fn peer_credential(&self) -> &str {
        &self.peer_credential
    }

    pub(super) fn profile(&self) -> &str {
        &self.profile
    }

    pub(super) fn profile_renderer_id(&self) -> &str {
        &self.profile_renderer_id
    }

    pub(super) fn profile_renderer_version(&self) -> &str {
        &self.profile_renderer_version
    }
}

impl fmt::Debug for AuthorizedMemberTunnelGrant {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("AuthorizedMemberTunnelGrant")
            .field("proof", &"[verified]")
            .field("bridge_endpoint", &self.bridge_endpoint)
            .field("peer_credential", &"[redacted]")
            .field("profile", &"[redacted]")
            .finish()
    }
}

impl Drop for AuthorizedMemberTunnelGrant {
    fn drop(&mut self) {
        self.peer_credential.zeroize();
        self.profile.zeroize();
    }
}

const MAX_PROFILE_BYTES: usize = 128 * 1024;
const MAX_CA_BUNDLE_BYTES: usize = 64 * 1024;

fn valid_bridge_endpoint(endpoint: &url::Url) -> bool {
    endpoint.scheme() == "https"
        && endpoint.host_str().is_some()
        && endpoint.username().is_empty()
        && endpoint.password().is_none()
        && !endpoint.as_str().contains('@')
        && endpoint.query().is_none()
        && endpoint.fragment().is_none()
        && (endpoint.path().is_empty() || endpoint.path() == "/")
        && endpoint.port().map_or(true, |port| port > 0)
}

fn contains_secret_field(profile: &str) -> bool {
    let normalized = profile.to_ascii_lowercase().replace('_', "").replace('-', "");
    ["privatekey:", "peercredential:", "peersecret:", "subscriptiontoken:"]
        .iter()
        .any(|field| normalized.contains(field))
}

fn valid_sha256(value: &str, expected: &str) -> bool {
    if expected.len() != 64 || !expected.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return false;
    }
    let actual = Sha256::digest(value.as_bytes());
    let mut encoded = String::with_capacity(64);
    for byte in actual {
        use std::fmt::Write as _;
        let _ = write!(encoded, "{byte:02x}");
    }
    encoded.eq_ignore_ascii_case(expected)
}

fn valid_renderer_version(version: &str) -> bool {
    let mut components = version.split('.');
    components.clone().count() == 3
        && components.all(|component| !component.is_empty() && component.bytes().all(|byte| byte.is_ascii_digit()))
}

fn parse_ca_bundle(pem: &str) -> Result<RootCertStore, BridgeFailure> {
    const BEGIN: &str = "-----BEGIN CERTIFICATE-----";
    const END: &str = "-----END CERTIFICATE-----";
    if pem.is_empty() || pem.len() > MAX_CA_BUNDLE_BYTES {
        return Err(BridgeFailure::InvalidMemberGrant);
    }

    let mut remaining = pem.trim();
    let mut roots = RootCertStore::empty();
    let mut certificate_count = 0;
    while !remaining.is_empty() {
        let Some(body_and_rest) = remaining.strip_prefix(BEGIN) else {
            return Err(BridgeFailure::InvalidMemberGrant);
        };
        let Some((body, rest)) = body_and_rest.split_once(END) else {
            return Err(BridgeFailure::InvalidMemberGrant);
        };
        if body.contains("-----BEGIN") || body.contains("-----END") {
            return Err(BridgeFailure::InvalidMemberGrant);
        }
        let encoded: String = body.chars().filter(|character| !character.is_whitespace()).collect();
        let der = STANDARD.decode(encoded).map_err(|_| BridgeFailure::InvalidMemberGrant)?;
        roots
            .add(CertificateDer::from(der))
            .map_err(|_| BridgeFailure::InvalidMemberGrant)?;
        certificate_count += 1;
        if certificate_count > 8 {
            return Err(BridgeFailure::InvalidMemberGrant);
        }
        remaining = rest.trim_start();
    }

    if certificate_count == 0 {
        return Err(BridgeFailure::InvalidMemberGrant);
    }
    Ok(roots)
}

struct DeviceProofSignerAdapter(Arc<dyn super::DeviceProofProvider>);

impl DeviceProofSigner for DeviceProofSignerAdapter {
    fn public_key_spki_base64url(&self) -> Result<String, BridgeFailure> {
        self.0
            .public_key_spki_base64url()
            .map_err(|_| BridgeFailure::DeviceKeyUnavailable)
    }

    fn sign_challenge_base64url(&self, challenge: &[u8; 32]) -> Result<String, BridgeFailure> {
        let encoded = base64::engine::general_purpose::URL_SAFE_NO_PAD.encode(challenge);
        self.0
            .sign_challenge_base64url(&encoded)
            .map_err(|_| BridgeFailure::DeviceProofSigningFailed)
    }
}

fn valid_uuid(value: &str) -> bool {
    uuid::Uuid::parse_str(value).is_ok()
}

struct BridgeConfig {
    grant: AuthorizedMemberTunnelGrant,
}

impl BridgeConfig {
    fn from_authorized_grant(grant: AuthorizedMemberTunnelGrant) -> Self {
        Self { grant }
    }

    fn has_required_binding_references(&self) -> bool {
        let proof = self.grant.proof();
        !proof.member_id.trim().is_empty()
            && valid_uuid(&proof.installation_id)
            && valid_uuid(&proof.device_id)
            && valid_uuid(&proof.peer_binding_id)
            && valid_uuid(&proof.node_id)
            && proof.peer_generation > 0
            && proof.device_binding_generation > 0
            && proof
                .signer()
                .public_key_spki_base64url()
                .is_ok_and(|public_key| public_key == proof.registered_public_key_spki_base64url)
            && !self.grant.peer_credential().is_empty()
            && !self.grant.profile().is_empty()
            && !self.grant.trusted_server_roots().is_empty()
    }
}

/// Implementations must synchronously terminate and reap the child in `Drop`
/// as the final backstop if both explicit stop operations fail.
trait ProofBridgeProcess {
    fn is_alive(&mut self) -> bool;
    fn stop(&mut self) -> Result<(), BridgeFailure>;
    fn force_stop(&mut self) -> Result<(), BridgeFailure>;
}

trait ProofBridgeAdapter {
    /// Recheck current entitlement and binding against revocation, use the
    /// supplied signer for the peer proof handshake, and only then return an
    /// alive process. On error, implementations must not leave a child process
    /// running without a handle. This contract is not a server verifier.
    fn launch(
        &mut self,
        config: &BridgeConfig,
    ) -> Result<Box<dyn ProofBridgeProcess>, BridgeFailure>;
}

struct BridgeSupervisor<A: ProofBridgeAdapter> {
    adapter: A,
    process: Option<Box<dyn ProofBridgeProcess>>,
    snapshot: BridgeSnapshot,
}

impl<A: ProofBridgeAdapter> BridgeSupervisor<A> {
    fn new(adapter: A) -> Self {
        Self {
            adapter,
            process: None,
            snapshot: BridgeSnapshot {
                state: BridgeState::Stopped,
                failure: None,
            },
        }
    }

    fn status(&mut self) -> BridgeSnapshot {
        if self.snapshot.state == BridgeState::Ready
            && !self
                .process
                .as_mut()
                .is_some_and(|process| process.is_alive())
        {
            return if self.cleanup_process().is_ok() {
                self.fail(BridgeFailure::ProcessExited)
            } else {
                self.fail(BridgeFailure::StopFailed)
            };
        }
        self.snapshot
    }

    fn start(&mut self, config: &BridgeConfig) -> BridgeSnapshot {
        if !PROOF_BRIDGE_ENABLED {
            return self.fail(BridgeFailure::FeatureDisabled);
        }

        self.start_enabled(config)
    }

    fn start_enabled(&mut self, config: &BridgeConfig) -> BridgeSnapshot {
        if self.snapshot.state == BridgeState::Ready {
            return self.status();
        }

        if self.cleanup_process().is_err() {
            return self.fail(BridgeFailure::StopFailed);
        }
        if !config.has_required_binding_references() {
            return self.fail(BridgeFailure::InvalidMemberProof);
        }

        self.snapshot = BridgeSnapshot {
            state: BridgeState::Starting,
            failure: None,
        };

        match self.adapter.launch(config) {
            Ok(mut process) => {
                if process.is_alive() {
                    self.process = Some(process);
                    self.snapshot = BridgeSnapshot {
                        state: BridgeState::Ready,
                        failure: None,
                    };
                } else {
                    let _ = process.stop();
                    self.fail(BridgeFailure::ProcessExited);
                }
            }
            Err(failure) => {
                self.fail(failure);
            }
        }

        self.snapshot
    }

    #[cfg(test)]
    fn start_enabled_for_test(&mut self, config: &BridgeConfig) -> BridgeSnapshot {
        self.start_enabled(config)
    }

    fn stop(&mut self) -> BridgeSnapshot {
        if self.process.is_none() {
            self.snapshot = BridgeSnapshot {
                state: BridgeState::Stopped,
                failure: None,
            };
            return self.snapshot;
        }

        self.snapshot = BridgeSnapshot {
            state: BridgeState::Stopping,
            failure: None,
        };
        match self.stop_process() {
            Ok(()) => {
                self.snapshot = BridgeSnapshot {
                    state: BridgeState::Stopped,
                    failure: None,
                };
            }
            Err(failure) => return self.fail(failure),
        }
        self.snapshot
    }

    fn stop_process(&mut self) -> Result<(), BridgeFailure> {
        let Some(process) = self.process.as_mut() else {
            return Ok(());
        };
        if process.stop().is_err() && process.force_stop().is_err() {
            return Err(BridgeFailure::StopFailed);
        }
        self.process = None;
        Ok(())
    }

    fn cleanup_process(&mut self) -> Result<(), BridgeFailure> {
        self.stop_process()
    }

    fn fail(&mut self, failure: BridgeFailure) -> BridgeSnapshot {
        self.snapshot = BridgeSnapshot {
            state: BridgeState::Failed,
            failure: Some(failure),
        };
        self.snapshot
    }
}

impl<A: ProofBridgeAdapter> Drop for BridgeSupervisor<A> {
    fn drop(&mut self) {
        let _ = self.cleanup_process();
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{cell::Cell, rc::Rc};

    const TEST_CA_PEM: &str = "-----BEGIN CERTIFICATE-----\n\
MIIDDDCCAfSgAwIBAgIUO3xVpkTXq2ci+clkcnp4HbQ0gGIwDQYJKoZIhvcNAQEL\n\
BQAwHjEcMBoGA1UEAwwTWExCIFdpbmRvd3MgVGVzdCBDQTAeFw0yNjExMjQyMzQ2\n\
MDNaFw0zNjExMjEyMzQ2MDNaMB4xHDAaBgNVBAMME1hMQiBXaW5kb3dzIFRlc3Qg\n\
Q0EwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEKAoIBAQC42K2RIoBKTCjPhUFq\n\
yxZUB9BialUZFyvFN04IMXqc7WY1J7DzjM6vZ5YSXbisQshUtLZQjXpJAIosS2V/\n\
YIPKNX+Yrbf7jhBvXrP6YQFbV6TI4ZiyrNNVpNjAOx15NltSgcixUXK9WN2Znap+\n\
3HqM8y4lwVFqbq84zzvQflALaIlxLiAlXO05JsJnyR5T7Q2ecABTAwW5SU4wTcew\n\
Ze5Q1jc7W0CbFrqvnTx7me6Ia/UFvyjDYI1+d/cVBfukQbeATSRpkwGGEFSS5kmb\n\
0GeNtoXkPqN2kPoRkU+bNgJu9LL2tS2pqiZGYmXqp1iKKOoUYz6boGpOWkrKOta/\n\
pT6zAgMBAAGjQjBAMB0GA1UdDgQWBBSlHzhovNNGImTd4q0pTigME0GyPTAPBgNV\n\
HRMBAf8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjANBgkqhkiG9w0BAQsFAAOCAQEA\n\
U4ieOd9whvuxsPMBoBMV91/K1npkr46Zl64BKYCxu+eiLlrdn1VYHf6vDuz5SVaa\n\
oUFpC5fnAiB9pIEZIZVExLl7MKkzUfpszzvc+k7abwYNGF0lIDXK/DwSa7SVkbzS\n\
FerjfAwLioQxLX/rBTtIkvKoJ4Y9Lce0xZRd/wXjiqLmA6byvHC3MkeuGPkI1qvs\n\
B//SjqGdsnb7ZBM6COIOL0NyLrWoKZrjD9x+5IRpo6E9U1PHqIInTUyh1Zl/m/O9\n\
d7hVvJRLG4xfs/M1H6xZ0UqTvRYE0eIYF39mzGWL2xfgdFpMVYhQ6g3pkfgV3H/F\n\
JeIGIezus8B92pF7Fd1XSQ==\n\
-----END CERTIFICATE-----\n";

    #[derive(Clone, Default)]
    struct Counters {
        launches: Rc<Cell<usize>>,
        stops: Rc<Cell<usize>>,
        alive: Rc<Cell<bool>>,
        launch_fails: Rc<Cell<bool>>,
        stop_fails: Rc<Cell<bool>>,
        force_stop_fails: Rc<Cell<bool>>,
        drop_kills: Rc<Cell<usize>>,
    }

    struct FakeAdapter(Counters);

    struct FakeDeviceProof {
        installation_id: String,
    }

    fn valid_authorization() -> super::super::DeviceAuthorization {
        let profile = "mode: global\nproxy-groups:\n  - name: GLOBAL\n";
        super::super::DeviceAuthorization {
            status: "ready".into(),
            peer_id: Some("fd3ae0f1-1234-4234-9234-000000000011".into()),
            node_id: Some("fd3ae0f1-1234-4234-9234-000000000012".into()),
            peer_generation: Some(1),
            device_binding_generation: Some(1),
            bridge_endpoint: Some("https://node.example.test:9443".into()),
            bridge_server_ca_pem: Some(TEST_CA_PEM.into()),
            profile_sha256: Some(format!("{:x}", Sha256::digest(profile.as_bytes()))),
            profile_renderer_id: Some("xlb-mihomo-bridge-v1".into()),
            profile_renderer_version: Some("1.0.0".into()),
            bridge_protocol: Some("xlb-proof-v1".into()),
            connectable: Some(true),
            peer_credential: Some(base64::engine::general_purpose::URL_SAFE_NO_PAD.encode([7u8; 32])),
            profile: Some(profile.into()),
        }
    }

    impl super::super::DeviceProofProvider for FakeDeviceProof {
        fn installation_id(&self) -> &str {
            &self.installation_id
        }

        fn public_key_spki_base64url(&self) -> Result<String, String> {
            Ok("test-public-key".into())
        }

        fn sign_challenge_base64url(&self, challenge: &str) -> Result<String, String> {
            let bytes = super::super::decode_base64url(challenge)
                .ok_or_else(|| "invalid challenge".to_owned())?;
            Ok(format!("test-signature-{}", bytes[0]))
        }
    }

    impl ProofBridgeAdapter for FakeAdapter {
        fn launch(
            &mut self,
            _config: &BridgeConfig,
        ) -> Result<Box<dyn ProofBridgeProcess>, BridgeFailure> {
            self.0.launches.set(self.0.launches.get() + 1);
            if self.0.launch_fails.get() {
                return Err(BridgeFailure::LaunchFailed);
            }
            self.0.alive.set(true);
            Ok(Box::new(FakeProcess(self.0.clone())))
        }
    }

    struct FakeProcess(Counters);

    impl ProofBridgeProcess for FakeProcess {
        fn is_alive(&mut self) -> bool {
            self.0.alive.get()
        }

        fn stop(&mut self) -> Result<(), BridgeFailure> {
            self.0.stops.set(self.0.stops.get() + 1);
            if self.0.stop_fails.get() {
                Err(BridgeFailure::StopFailed)
            } else {
                self.0.alive.set(false);
                Ok(())
            }
        }

        fn force_stop(&mut self) -> Result<(), BridgeFailure> {
            self.0.stops.set(self.0.stops.get() + 1);
            if self.0.force_stop_fails.get() {
                Err(BridgeFailure::StopFailed)
            } else {
                self.0.alive.set(false);
                Ok(())
            }
        }
    }

    impl Drop for FakeProcess {
        fn drop(&mut self) {
            if self.0.alive.get() {
                self.0.drop_kills.set(self.0.drop_kills.get() + 1);
                self.0.alive.set(false);
            }
        }
    }

    fn valid_proof() -> Arc<VerifiedMemberDeviceProof> {
        let device = super::super::BoundDevice {
            id: "fd3ae0f1-1234-4234-9234-000000000010".into(),
            status: "registered".into(),
            bound: true,
        };
        let authorization = valid_authorization();
        let proof = VerifiedMemberDeviceProof::from_authorized_response(
            "member-1",
            "fd3ae0f1-1234-4234-9234-000000000013",
            &device,
            &authorization,
            "test-public-key",
            Arc::new(FakeDeviceProof {
                installation_id: "fd3ae0f1-1234-4234-9234-000000000013".into(),
            }),
        )
        .unwrap();
        Arc::new(proof)
    }

    fn valid_config() -> BridgeConfig {
        let authorization = valid_authorization();
        let grant = AuthorizedMemberTunnelGrant::from_authorized_response(valid_proof(), &authorization)
            .unwrap();
        BridgeConfig::from_authorized_grant(grant)
    }

    fn supervisor(counters: Counters) -> BridgeSupervisor<FakeAdapter> {
        BridgeSupervisor::new(FakeAdapter(counters))
    }

    #[test]
    fn feature_is_off_and_initial_state_is_stopped() {
        assert!(!PROOF_BRIDGE_ENABLED);
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        assert_eq!(supervisor.status().state, BridgeState::Stopped);
        assert_eq!(
            supervisor.start(&valid_config()),
            BridgeSnapshot {
                state: BridgeState::Failed,
                failure: Some(BridgeFailure::FeatureDisabled),
            }
        );
        assert_eq!(counters.launches.get(), 0);
    }

    #[test]
    fn authorized_grant_rejects_missing_peer_binding() {
        let mut authorization = valid_authorization();
        authorization.peer_id = None;
        assert!(matches!(
            AuthorizedMemberTunnelGrant::from_authorized_response(valid_proof(), &authorization),
            Err(BridgeFailure::InvalidMemberGrant)
        ));
    }

    #[test]
    fn verified_proof_exposes_signing_capability_without_key_bytes() {
        let config = valid_config();
        let challenge = [7; 32];

        assert_eq!(config.grant.proof().signer().public_key_spki_base64url().unwrap(), "test-public-key");
        assert_eq!(config.grant.proof().signer().sign_challenge_base64url(&challenge).unwrap(), "test-signature-7");
    }

    #[test]
    fn authorized_grant_validates_server_runtime_material_and_redacts_secrets() {
        let config = valid_config();
        let grant = &config.grant;

        assert_eq!(grant.bridge_endpoint().as_str(), "https://node.example.test:9443/");
        assert!(!grant.trusted_server_roots().is_empty());
        assert_eq!(grant.peer_credential(), URL_SAFE_NO_PAD.encode([7u8; 32]));
        assert_eq!(grant.profile_renderer_id(), "xlb-mihomo-bridge-v1");
        assert_eq!(grant.profile_renderer_version(), "1.0.0");

        let debug = format!("{grant:?}");
        assert!(debug.contains("[redacted]"));
        assert!(!debug.contains(grant.peer_credential()));
        assert!(!debug.contains(grant.profile()));
    }

    #[test]
    fn authorized_grant_rejects_mismatched_or_incomplete_runtime_material() {
        let invalid_authorizations = [
            {
                let mut authorization = valid_authorization();
                authorization.peer_generation = Some(2);
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.bridge_endpoint = Some("https://node.example.test/bridge".into());
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.bridge_server_ca_pem = Some("-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----".into());
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.profile_sha256 = Some("0".repeat(64));
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.profile_renderer_id = Some("unknown-renderer".into());
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.connectable = Some(false);
                authorization
            },
            {
                let mut authorization = valid_authorization();
                authorization.peer_credential = Some(URL_SAFE_NO_PAD.encode([7u8; 31]));
                authorization
            },
        ];

        for authorization in invalid_authorizations {
            assert!(matches!(
                AuthorizedMemberTunnelGrant::from_authorized_response(valid_proof(), &authorization),
                Err(BridgeFailure::InvalidMemberGrant)
            ));
        }
    }

    #[test]
    fn ready_authorization_requires_complete_bound_peer_material() {
        let device = super::super::BoundDevice {
            id: "fd3ae0f1-1234-4234-9234-000000000010".into(),
            status: "registered".into(),
            bound: true,
        };
        let signer = || Arc::new(FakeDeviceProof {
            installation_id: "fd3ae0f1-1234-4234-9234-000000000013".into(),
        }) as Arc<dyn super::super::DeviceProofProvider>;
        let verify = |authorization: &super::super::DeviceAuthorization,
                      device: &super::super::BoundDevice,
                      registered_public_key: &str| {
            VerifiedMemberDeviceProof::from_authorized_response(
                "member-1",
                "fd3ae0f1-1234-4234-9234-000000000013",
                device,
                authorization,
                registered_public_key,
                signer(),
            )
        };

        assert!(verify(&valid_authorization(), &device, "test-public-key").is_ok());

        assert!(matches!(
            verify(&valid_authorization(), &device, "different-public-key"),
            Err(BridgeFailure::DeviceKeyUnavailable)
        ));

        assert!(matches!(
            VerifiedMemberDeviceProof::from_authorized_response(
                "member-1",
                "fd3ae0f1-1234-4234-9234-000000000013",
                &device,
                &valid_authorization(),
                "test-public-key",
                Arc::new(FakeDeviceProof {
                    installation_id: "fd3ae0f1-1234-4234-9234-000000000014".into(),
                }),
            ),
            Err(BridgeFailure::InvalidMemberProof)
        ));

        let mut missing_peer = valid_authorization();
        missing_peer.peer_id = None;
        assert!(matches!(verify(&missing_peer, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let mut wrong_generation = valid_authorization();
        wrong_generation.device_binding_generation = Some(0);
        assert!(matches!(verify(&wrong_generation, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let mut short_credential = valid_authorization();
        short_credential.peer_credential = Some(base64::engine::general_purpose::URL_SAFE_NO_PAD.encode([7u8; 31]));
        assert!(matches!(verify(&short_credential, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let mut unsafe_profile = valid_authorization();
        unsafe_profile.profile = Some("private_key: do-not-retain\n".into());
        assert!(matches!(verify(&unsafe_profile, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let mut camel_case_secret = valid_authorization();
        camel_case_secret.profile = Some("peerCredential: do-not-retain\n".into());
        assert!(matches!(verify(&camel_case_secret, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let mut provisioning = valid_authorization();
        provisioning.status = "provisioning".into();
        assert!(matches!(verify(&provisioning, &device, "test-public-key"), Err(BridgeFailure::InvalidMemberProof)));

        let unbound_device = super::super::BoundDevice { bound: false, ..device };
        assert!(matches!(
            verify(&valid_authorization(), &unbound_device, "test-public-key"),
            Err(BridgeFailure::InvalidMemberProof)
        ));

        let revoked_device = super::super::BoundDevice {
            status: "revoked".into(),
            bound: true,
            ..unbound_device
        };
        assert!(matches!(
            verify(&valid_authorization(), &revoked_device, "test-public-key"),
            Err(BridgeFailure::InvalidMemberProof)
        ));
    }

    #[test]
    fn device_snapshot_must_keep_entitlement_active_to_retain_proof() {
        let config = valid_config();
        let proof = config.grant.proof();
        let devices = super::super::DeviceSummary {
            entitlement_active: true,
            max_devices: 1,
            registered_count: 1,
            available_slots: 0,
            devices: vec![super::super::Device {
                id: "fd3ae0f1-1234-4234-9234-000000000010".into(),
                installation_id: "fd3ae0f1-1234-4234-9234-000000000013".into(),
                name: "Windows".into(),
                platform: "windows".into(),
                status: "registered".into(),
                created_at: "2026-11-22T00:00:00Z".into(),
            }],
        };

        assert!(proof.matches_device_snapshot(&devices));

        let inactive_entitlement = super::super::DeviceSummary {
            entitlement_active: false,
            ..devices
        };
        assert!(!proof.matches_device_snapshot(&inactive_entitlement));
    }

    #[test]
    fn repeated_start_is_idempotent_while_process_is_ready() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());

        assert_eq!(supervisor.start_enabled_for_test(&valid_config()).state, BridgeState::Ready);
        assert_eq!(supervisor.start_enabled_for_test(&valid_config()).state, BridgeState::Ready);
        assert_eq!(counters.launches.get(), 1);
    }

    #[test]
    fn launch_failure_never_reports_ready() {
        let counters = Counters::default();
        counters.launch_fails.set(true);
        let mut supervisor = supervisor(counters.clone());

        let result = supervisor.start_enabled_for_test(&valid_config());
        assert_eq!(result.state, BridgeState::Failed);
        assert_eq!(result.failure, Some(BridgeFailure::LaunchFailed));
        assert_eq!(counters.launches.get(), 1);
    }

    #[test]
    fn process_exit_changes_ready_to_failed() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        assert_eq!(supervisor.start_enabled_for_test(&valid_config()).state, BridgeState::Ready);
        counters.alive.set(false);

        let result = supervisor.status();
        assert_eq!(result.state, BridgeState::Failed);
        assert_eq!(result.failure, Some(BridgeFailure::ProcessExited));
    }

    #[test]
    fn repeated_stop_is_idempotent_and_cleans_process() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start_enabled_for_test(&valid_config());

        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(counters.stops.get(), 1);
        assert!(!counters.alive.get());
    }

    #[test]
    fn force_stop_fallback_cleans_process_after_graceful_stop_fails() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start_enabled_for_test(&valid_config());
        counters.stop_fails.set(true);

        let result = supervisor.stop();

        assert_eq!(result.state, BridgeState::Stopped);
        assert_eq!(result.failure, None);
        assert_eq!(counters.stops.get(), 2);
        assert!(!counters.alive.get());
        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(counters.stops.get(), 2);
    }

    #[test]
    fn stop_failure_is_reported_as_failed() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start_enabled_for_test(&valid_config());
        counters.stop_fails.set(true);
        counters.force_stop_fails.set(true);

        let result = supervisor.stop();
        assert_eq!(result.state, BridgeState::Failed);
        assert_eq!(result.failure, Some(BridgeFailure::StopFailed));
    }

    #[test]
    fn dropping_supervisor_stops_child() {
        let counters = Counters::default();
        {
            let mut supervisor = supervisor(counters.clone());
            supervisor.start_enabled_for_test(&valid_config());
        }

        assert_eq!(counters.stops.get(), 1);
        assert!(!counters.alive.get());
    }

    #[test]
    fn failed_stop_keeps_handle_and_blocks_second_launch_until_retry_succeeds() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start_enabled_for_test(&valid_config());
        counters.stop_fails.set(true);
        counters.force_stop_fails.set(true);

        assert_eq!(supervisor.stop().failure, Some(BridgeFailure::StopFailed));
        assert_eq!(
            supervisor.start_enabled_for_test(&valid_config()).failure,
            Some(BridgeFailure::StopFailed)
        );
        assert_eq!(counters.launches.get(), 1);
        assert_eq!(counters.stops.get(), 4);

        counters.stop_fails.set(false);
        counters.force_stop_fails.set(false);
        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(counters.stops.get(), 5);
        assert!(!counters.alive.get());
    }

    #[test]
    fn drop_retries_child_cleanup_after_stop_failure() {
        let counters = Counters::default();
        {
            let mut supervisor = supervisor(counters.clone());
            supervisor.start_enabled_for_test(&valid_config());
            counters.stop_fails.set(true);
            counters.force_stop_fails.set(true);
            assert_eq!(supervisor.stop().failure, Some(BridgeFailure::StopFailed));
            counters.stop_fails.set(false);
            counters.force_stop_fails.set(false);
        }

        assert_eq!(counters.stops.get(), 3);
        assert!(!counters.alive.get());
    }

    #[test]
    fn process_drop_reaps_child_when_explicit_cleanup_repeatedly_fails() {
        let counters = Counters::default();
        {
            let mut supervisor = supervisor(counters.clone());
            supervisor.start_enabled_for_test(&valid_config());
            counters.stop_fails.set(true);
            counters.force_stop_fails.set(true);
            assert_eq!(supervisor.stop().failure, Some(BridgeFailure::StopFailed));
        }

        assert_eq!(counters.drop_kills.get(), 1);
        assert!(!counters.alive.get());
    }

    #[test]
    fn public_snapshot_contains_only_state_and_fixed_failure_code() {
        let snapshot = BridgeSnapshot {
            state: BridgeState::Failed,
            failure: Some(BridgeFailure::InvalidMemberProof),
        };

        assert_eq!(
            serde_json::to_value(snapshot).unwrap(),
            serde_json::json!({"state": "failed", "failure": "invalid_member_proof"})
        );
    }
}
