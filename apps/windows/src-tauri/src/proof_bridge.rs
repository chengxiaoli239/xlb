#![allow(dead_code)] // Offline lifecycle contract is intentionally not wired to Tauri yet.

use serde::Serialize;

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
enum BridgeFailure {
    InvalidMemberProof,
    LaunchFailed,
    ProcessExited,
    StopFailed,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
struct BridgeSnapshot {
    state: BridgeState,
    failure: Option<BridgeFailure>,
}

/// An unforgeable capability created only by the native member/device verifier.
/// Keep fields and constructor private so IDs or booleans cannot claim proof success.
struct VerifiedMemberDeviceProof {
    member_id: String,
    installation_id: String,
    non_exportable_key_handle: String,
    peer_binding_id: String,
}

impl VerifiedMemberDeviceProof {
    fn new(
        member_id: String,
        installation_id: String,
        non_exportable_key_handle: String,
        peer_binding_id: String,
    ) -> Self {
        Self {
            member_id,
            installation_id,
            non_exportable_key_handle,
            peer_binding_id,
        }
    }
}

struct BridgeConfig {
    proof: VerifiedMemberDeviceProof,
}

impl BridgeConfig {
    fn from_verified_proof(proof: VerifiedMemberDeviceProof) -> Self {
        Self { proof }
    }

    fn has_required_binding_references(&self) -> bool {
        !self.proof.member_id.trim().is_empty()
            && !self.proof.installation_id.trim().is_empty()
            && !self.proof.non_exportable_key_handle.trim().is_empty()
            && !self.proof.peer_binding_id.trim().is_empty()
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
    /// Recheck current entitlement and binding against revocation, complete the
    /// peer proof handshake, and only then return an alive process. On error,
    /// implementations must not leave a child process running without a handle.
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

    fn valid_config() -> BridgeConfig {
        BridgeConfig::from_verified_proof(VerifiedMemberDeviceProof::new(
            "member-1".into(),
            "installation-1".into(),
            "windows-key-handle".into(),
            "peer-binding-1".into(),
        ))
    }

    fn supervisor(counters: Counters) -> BridgeSupervisor<FakeAdapter> {
        BridgeSupervisor::new(FakeAdapter(counters))
    }

    #[test]
    fn feature_is_off_and_initial_state_is_stopped() {
        assert!(!PROOF_BRIDGE_ENABLED);
        let mut supervisor = supervisor(Counters::default());
        assert_eq!(supervisor.status().state, BridgeState::Stopped);
    }

    #[test]
    fn missing_device_key_handle_fails_closed_without_launch() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        let mut config = valid_config();
        config.proof.non_exportable_key_handle.clear();

        assert_eq!(
            supervisor.start(&config),
            BridgeSnapshot {
                state: BridgeState::Failed,
                failure: Some(BridgeFailure::InvalidMemberProof),
            }
        );
        assert_eq!(counters.launches.get(), 0);
    }

    #[test]
    fn repeated_start_is_idempotent_while_process_is_ready() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());

        assert_eq!(supervisor.start(&valid_config()).state, BridgeState::Ready);
        assert_eq!(supervisor.start(&valid_config()).state, BridgeState::Ready);
        assert_eq!(counters.launches.get(), 1);
    }

    #[test]
    fn launch_failure_never_reports_ready() {
        let counters = Counters::default();
        counters.launch_fails.set(true);
        let mut supervisor = supervisor(counters.clone());

        let result = supervisor.start(&valid_config());
        assert_eq!(result.state, BridgeState::Failed);
        assert_eq!(result.failure, Some(BridgeFailure::LaunchFailed));
        assert_eq!(counters.launches.get(), 1);
    }

    #[test]
    fn process_exit_changes_ready_to_failed() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        assert_eq!(supervisor.start(&valid_config()).state, BridgeState::Ready);
        counters.alive.set(false);

        let result = supervisor.status();
        assert_eq!(result.state, BridgeState::Failed);
        assert_eq!(result.failure, Some(BridgeFailure::ProcessExited));
    }

    #[test]
    fn repeated_stop_is_idempotent_and_cleans_process() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start(&valid_config());

        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(supervisor.stop().state, BridgeState::Stopped);
        assert_eq!(counters.stops.get(), 1);
        assert!(!counters.alive.get());
    }

    #[test]
    fn stop_failure_is_reported_as_failed() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start(&valid_config());
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
            supervisor.start(&valid_config());
        }

        assert_eq!(counters.stops.get(), 1);
        assert!(!counters.alive.get());
    }

    #[test]
    fn failed_stop_keeps_handle_and_blocks_second_launch_until_retry_succeeds() {
        let counters = Counters::default();
        let mut supervisor = supervisor(counters.clone());
        supervisor.start(&valid_config());
        counters.stop_fails.set(true);
        counters.force_stop_fails.set(true);

        assert_eq!(supervisor.stop().failure, Some(BridgeFailure::StopFailed));
        assert_eq!(
            supervisor.start(&valid_config()).failure,
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
            supervisor.start(&valid_config());
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
            supervisor.start(&valid_config());
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
