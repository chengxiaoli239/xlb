package agent

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestEnsureIsIdempotentAndRejectsRequestIDReuse(t *testing.T) {
	store := openTestStore(t, 4)
	request, _ := testEnsureRequest(t, "peer-1", "ensure-1")

	first, err := store.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	second, err := store.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	if first != second {
		t.Fatalf("idempotent retry returned a different receipt: first=%+v second=%+v", first, second)
	}
	reopened, err := OpenPeerStore(store.path, "node-test", 4)
	if err != nil {
		t.Fatal(err)
	}
	afterRestart, err := reopened.EnsurePeer(request)
	if err != nil || first != afterRestart {
		t.Fatalf("retry after store reopen was not stable: first=%+v reopened=%+v err=%v", first, afterRestart, err)
	}
	if _, err := store.EnsurePeer(func() EnsurePeerRequest {
		changed := request
		changed.Credential = "another-secret"
		return changed
	}()); !errors.Is(err, ErrRequestConflict) {
		t.Fatalf("expected request conflict, got %v", err)
	}
}

func TestIsolatedEnsureReceiptDoesNotIncludeAProductionProfileArtifact(t *testing.T) {
	store := openTestStore(t, 4)
	request, _ := testEnsureRequest(t, "peer-no-profile", "ensure-no-profile")

	receipt, err := store.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	if receipt.ConfirmationStatus != "confirmed_test" {
		t.Fatalf("confirmation status = %q; want confirmed_test", receipt.ConfirmationStatus)
	}
	if receipt.ProfileArtifact != nil {
		t.Fatal("isolated test Agent must not provide a production profile artifact")
	}
}

func TestEnsureWithProfileRendererPersistsIdentityBoundArtifactAcrossRetries(t *testing.T) {
	request, _ := testEnsureRequest(t, "peer-rendered", "ensure-rendered")
	renderer, err := NewMihomoBridgeProfileRenderer("127.0.0.1:17890")
	if err != nil {
		t.Fatal(err)
	}
	storePath := filepath.Join(t.TempDir(), "peer-store.json")
	store, err := OpenPeerStoreWithProfileRenderer(storePath, "node-test", 4, renderer)
	if err != nil {
		t.Fatal(err)
	}

	first, err := store.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	if first.ConfirmationStatus != "confirmed" || first.ProfileArtifact == nil {
		t.Fatalf("renderer receipt status/artifact = %q/%v; want confirmed with artifact", first.ConfirmationStatus, first.ProfileArtifact != nil)
	}
	profileRequest := ProfileRenderRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, EntitlementID: request.EntitlementID,
		RequestID: request.RequestID, Generation: request.Generation, DeviceID: request.DeviceID,
		DeviceKeyFingerprint: request.DeviceKeyFingerprint, DeviceBindingGeneration: request.DeviceBindingGeneration,
		BridgeProtocol: "xlb-proof-v1",
	}
	if err := ValidateProfileArtifact(profileRequest, *first.ProfileArtifact); err != nil {
		t.Fatalf("renderer artifact did not match the request: %v", err)
	}
	if strings.Contains(strings.ToLower(first.ProfileArtifact.Content), request.Credential) {
		t.Fatal("rendered profile contains the peer credential")
	}

	second, err := store.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(first, second) {
		t.Fatalf("idempotent retry changed its receipt: first=%+v second=%+v", first, second)
	}

	reopened, err := OpenPeerStore(storePath, "node-test", 4)
	if err != nil {
		t.Fatal(err)
	}
	afterRestart, err := reopened.EnsurePeer(request)
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(first, afterRestart) {
		t.Fatalf("idempotent retry after reopen changed its receipt: first=%+v reopened=%+v", first, afterRestart)
	}
}

func TestCapacityRejectionConsumesChallengeAndDoesNotAllowStaggeredRetry(t *testing.T) {
	store := openTestStore(t, 1)
	firstRequest, firstKey := testEnsureRequest(t, "peer-capacity-first", "ensure-capacity-first")
	secondRequest, secondKey := testEnsureRequest(t, "peer-capacity-second", "ensure-capacity-second")
	if _, err := store.EnsurePeer(firstRequest); err != nil {
		t.Fatal(err)
	}
	if _, err := store.EnsurePeer(secondRequest); err != nil {
		t.Fatal(err)
	}
	firstChallenge, err := store.IssueChallenge(challengeFor(firstRequest), firstRequest.SubscriptionToken, firstRequest.Credential)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := store.Authorize(signedAuthorization(t, firstKey, firstChallenge, firstRequest)); err != nil {
		t.Fatal(err)
	}
	secondChallenge, err := store.IssueChallenge(challengeFor(secondRequest), secondRequest.SubscriptionToken, secondRequest.Credential)
	if err != nil {
		t.Fatal(err)
	}
	secondAttempt := signedAuthorization(t, secondKey, secondChallenge, secondRequest)
	if _, err := store.Authorize(secondAttempt); !errors.Is(err, ErrCapacity) {
		t.Fatalf("expected node capacity rejection, got %v", err)
	}
	if err := store.Disconnect("session-" + firstChallenge.ID); err != nil {
		t.Fatal(err)
	}
	secondAttempt.SessionID = "session-after-capacity-release"
	if _, err := store.Authorize(secondAttempt); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("capacity-rejected challenge was reusable after a slot opened: %v", err)
	}
}

func TestChallengeIssuanceIsBoundedPerPeer(t *testing.T) {
	store := openTestStore(t, 4)
	request, _ := testEnsureRequest(t, "peer-challenge-limit", "ensure-challenge-limit")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	for index := 0; index < maxPeerChallenges; index++ {
		if _, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential); err != nil {
			t.Fatalf("challenge %d was rejected: %v", index+1, err)
		}
	}
	if _, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential); !errors.Is(err, ErrChallengeLimit) {
		t.Fatalf("expected challenge rate limit, got %v", err)
	}
}

func TestPeerProofAndSubscriptionAreBoundToTheRegisteredDevice(t *testing.T) {
	store := openTestStore(t, 4)
	request, privateKey := testEnsureRequest(t, "peer-owner", "ensure-owner")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}

	ownerChallenge, err := store.IssueChallenge(ChallengeRequest{
		NodeID: "node-test", PeerID: request.PeerID, DeviceID: request.DeviceID,
		SubscriptionID: request.SubscriptionID, SubscriptionGeneration: request.SubscriptionGeneration,
		PeerGeneration: request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
	}, request.SubscriptionToken, request.Credential)
	if err != nil {
		t.Fatal(err)
	}
	otherKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	copiedAttempt := signedAuthorization(t, otherKey, ownerChallenge, request)
	copiedAttempt.SessionID = "session-copied-simultaneous"
	if _, err := store.Authorize(copiedAttempt); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("credential copied to another device was accepted: %v", err)
	}
	replayOwnerAttempt := signedAuthorization(t, privateKey, ownerChallenge, request)
	replayOwnerAttempt.SessionID = "session-after-invalid-proof"
	if _, err := store.Authorize(replayOwnerAttempt); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("challenge was not consumed by invalid proof attempt: %v", err)
	}

	ownerChallenge = ownerChallengeForNewProof(t, store, request)
	ownerAuth := signedAuthorization(t, privateKey, ownerChallenge, request)
	if _, err := store.Authorize(ownerAuth); err != nil {
		t.Fatalf("owner device was rejected: %v", err)
	}

	if _, err := store.IssueChallenge(ChallengeRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, DeviceID: "device-other",
		SubscriptionID: request.SubscriptionID, SubscriptionGeneration: request.SubscriptionGeneration,
		PeerGeneration: request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
	}, request.SubscriptionToken, request.Credential); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("another device received a challenge for the owner's peer: %v", err)
	}
	otherChallenge := ownerChallengeForNewProof(t, store, request)
	otherAuth := signedAuthorization(t, otherKey, otherChallenge, request)
	otherAuth.DeviceID = "device-other"
	otherAuth.SessionID = "session-other"
	if _, err := store.Authorize(otherAuth); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("copied credentials were accepted on a different device: %v", err)
	}

	wrongSubscription := signedAuthorization(t, privateKey, ownerChallengeForNewProof(t, store, request), request)
	wrongSubscription.SubscriptionID = "subscription-other"
	if _, err := store.Authorize(wrongSubscription); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("different subscription identity was accepted: %v", err)
	}
	replayed := ownerAuth
	replayed.SessionID = "session-replay"
	if _, err := store.Authorize(replayed); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("one-use challenge was replayed successfully: %v", err)
	}
	if err := store.Disconnect(ownerAuth.SessionID); err != nil {
		t.Fatal(err)
	}
	staggeredChallenge := ownerChallengeForNewProof(t, store, request)
	staggeredCopy := signedAuthorization(t, otherKey, staggeredChallenge, request)
	staggeredCopy.SessionID = "session-staggered-copy"
	if _, err := store.Authorize(staggeredCopy); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("copied credentials were accepted by another device after owner disconnected: %v", err)
	}
}

func TestPeerCredentialAndSubscriptionTokenAreNotPersistedInPlaintext(t *testing.T) {
	store := openTestStore(t, 2)
	request, _ := testEnsureRequest(t, "peer-secret-storage", "ensure-secret-storage")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	contents, err := os.ReadFile(store.path)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(contents), request.Credential) || strings.Contains(string(contents), request.SubscriptionToken) {
		t.Fatal("node state persisted a peer credential or subscription token in plaintext")
	}
	info, err := os.Stat(store.path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("node state file permissions = %o, want 600", info.Mode().Perm())
	}
}

func TestNativeAppPeerDoesNotRequireThirdPartySubscriptionCredential(t *testing.T) {
	store := openTestStore(t, 2)
	request, privateKey := testEnsureRequest(t, "peer-native", "ensure-native")
	request.SubscriptionID = ""
	request.SubscriptionToken = ""
	request.SubscriptionGeneration = 0
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	challenge, err := store.IssueChallenge(challengeFor(request), "", request.Credential)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := store.Authorize(signedAuthorization(t, privateKey, challenge, request)); err != nil {
		t.Fatalf("native device peer authorization failed without a subscription token: %v", err)
	}
}

func TestSubscriptionBindAndRotationAreIdempotentAndInvalidateOldToken(t *testing.T) {
	store := openTestStore(t, 2)
	request, _ := testEnsureRequest(t, "peer-bind-subscription", "ensure-bind-subscription")
	request.SubscriptionID = ""
	request.SubscriptionToken = ""
	request.SubscriptionGeneration = 0
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}

	firstBinding := BindSubscriptionRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, RequestID: "bind-subscription-1",
		SubscriptionID: "subscription-member-device", SubscriptionToken: "url-token-v1",
		SubscriptionGeneration: 1, PeerGeneration: request.Generation, DeviceGeneration: request.DeviceGeneration,
	}
	firstReceipt, err := store.BindSubscription(firstBinding)
	if err != nil {
		t.Fatal(err)
	}
	retryReceipt, err := store.BindSubscription(firstBinding)
	if err != nil || firstReceipt != retryReceipt {
		t.Fatalf("subscription binding retry changed its receipt: first=%+v retry=%+v err=%v", firstReceipt, retryReceipt, err)
	}
	oldChallengeRequest := ChallengeRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, DeviceID: request.DeviceID,
		SubscriptionID: firstBinding.SubscriptionID, SubscriptionGeneration: 1,
		PeerGeneration: request.Generation, DeviceGeneration: request.DeviceGeneration,
	}
	if _, err := store.IssueChallenge(oldChallengeRequest, firstBinding.SubscriptionToken, request.Credential); err != nil {
		t.Fatalf("bound subscription token was rejected: %v", err)
	}

	rotation := firstBinding
	rotation.RequestID = "bind-subscription-2"
	rotation.SubscriptionToken = "url-token-v2"
	rotation.SubscriptionGeneration = 2
	if _, err := store.BindSubscription(rotation); err != nil {
		t.Fatal(err)
	}
	if _, err := store.IssueChallenge(oldChallengeRequest, firstBinding.SubscriptionToken, request.Credential); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("rotated subscription accepted its old token: %v", err)
	}
	newChallengeRequest := oldChallengeRequest
	newChallengeRequest.SubscriptionGeneration = 2
	if _, err := store.IssueChallenge(newChallengeRequest, rotation.SubscriptionToken, request.Credential); err != nil {
		t.Fatalf("rotated subscription token was rejected: %v", err)
	}
}

func TestPeerUsageSnapshotReturnsMonotonicCumulativeTraffic(t *testing.T) {
	store := openTestStore(t, 2)
	request, _ := testEnsureRequest(t, "peer-usage", "ensure-usage")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	if err := store.AddTraffic(request.PeerID, 1234, 5678); err != nil {
		t.Fatal(err)
	}

	snapshot, err := store.PeerUsage(request.PeerID)
	if err != nil {
		t.Fatal(err)
	}
	if snapshot.NodeID != request.NodeID || snapshot.PeerID != request.PeerID || snapshot.Generation != request.Generation {
		t.Fatalf("usage identity=%+v; want node/peer/generation from ensure request", snapshot)
	}
	if snapshot.UploadBytes != 1234 || snapshot.DownloadBytes != 5678 || snapshot.Status != "active" {
		t.Fatalf("usage snapshot=%+v; want active peer with cumulative counters", snapshot)
	}
}

func TestRevocationIsIdempotentAndBlocksPeerAfterReceipt(t *testing.T) {
	store := openTestStore(t, 2)
	request, privateKey := testEnsureRequest(t, "peer-revoke", "ensure-revoke")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	challenge, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential)
	if err != nil {
		t.Fatal(err)
	}
	authorization, err := store.Authorize(signedAuthorization(t, privateKey, challenge, request))
	if err != nil {
		t.Fatal(err)
	}
	if err := store.AddTraffic("peer-revoke", 300, 500); err != nil {
		t.Fatal(err)
	}

	revocation := RevokePeerRequest{
		NodeID: "node-test", PeerID: "peer-revoke", RequestID: "revoke-1", Generation: 2,
	}
	if _, err := store.RevokePeer(revocation); !errors.Is(err, ErrSessionActive) {
		t.Fatalf("active session was acknowledged as revoked: %v", err)
	}
	if err := store.Disconnect(authorization.SessionID); err != nil {
		t.Fatal(err)
	}
	receipt, err := store.RevokePeer(revocation)
	if err != nil {
		t.Fatal(err)
	}
	retry, err := store.RevokePeer(revocation)
	if err != nil || receipt != retry {
		t.Fatalf("revocation retry was not stable: receipt=%+v retry=%+v err=%v", receipt, retry, err)
	}
	if receipt.UploadBytes != 300 || receipt.DownloadBytes != 500 {
		t.Fatalf("final traffic was not captured: %+v", receipt)
	}
	if _, err := store.RevokePeer(RevokePeerRequest{
		NodeID: "node-other", PeerID: "peer-revoke", RequestID: "revoke-wrong-node", Generation: 2,
	}); !errors.Is(err, ErrInvalid) {
		t.Fatalf("revocation for another node was accepted: %v", err)
	}
	if _, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential); !errors.Is(err, ErrUnauthorized) {
		t.Fatalf("revoked peer was allowed to obtain a new challenge: %v", err)
	}
}

func TestTrafficCountersAccumulateAndRejectOverflow(t *testing.T) {
	store := openTestStore(t, 1)
	request, _ := testEnsureRequest(t, "peer-traffic", "ensure-traffic")
	if _, err := store.EnsurePeer(request); err != nil {
		t.Fatal(err)
	}
	if err := store.AddTraffic(request.PeerID, 10, 20); err != nil {
		t.Fatal(err)
	}
	if err := store.AddTraffic(request.PeerID, 15, 25); err != nil {
		t.Fatal(err)
	}
	receipt, err := store.RevokePeer(RevokePeerRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, RequestID: "revoke-traffic", Generation: 2,
	})
	if err != nil {
		t.Fatal(err)
	}
	if receipt.UploadBytes != 25 || receipt.DownloadBytes != 45 {
		t.Fatalf("traffic totals were not cumulative: %+v", receipt)
	}
}

func TestNodeCapacityIsAtomicAcrossProcesses(t *testing.T) {
	if os.Getenv("XLB_AGENT_CHILD") == "1" {
		runAuthorizationChild(t)
		return
	}

	const attempts = 12
	const capacity = 3
	statePath := filepath.Join(t.TempDir(), "agent-state.json")
	setup, err := OpenPeerStore(statePath, "node-test", capacity)
	if err != nil {
		t.Fatal(err)
	}
	keys := make([]*ecdsa.PrivateKey, attempts)
	requests := make([]EnsurePeerRequest, attempts)
	for i := 0; i < attempts; i++ {
		requests[i], keys[i] = testEnsureRequest(t, "peer-"+strconv.Itoa(i), "ensure-"+strconv.Itoa(i))
		if _, err := setup.EnsurePeer(requests[i]); err != nil {
			t.Fatal(err)
		}
	}

	barrier := filepath.Join(t.TempDir(), "go")
	readyDirectory := filepath.Join(t.TempDir(), "ready")
	if err := os.Mkdir(readyDirectory, 0o700); err != nil {
		t.Fatal(err)
	}
	var wait sync.WaitGroup
	var resultMu sync.Mutex
	accepted := 0
	for i := 0; i < attempts; i++ {
		wait.Add(1)
		go func(index int) {
			defer wait.Done()
			privateDER, marshalErr := x509.MarshalPKCS8PrivateKey(keys[index])
			if marshalErr != nil {
				t.Error(marshalErr)
				return
			}
			requestJSON, marshalErr := json.Marshal(requests[index])
			if marshalErr != nil {
				t.Error(marshalErr)
				return
			}
			command := exec.Command(os.Args[0], "-test.run=^TestNodeCapacityIsAtomicAcrossProcesses$")
			command.Env = append(os.Environ(),
				"XLB_AGENT_CHILD=1",
				"XLB_AGENT_STATE="+statePath,
				"XLB_AGENT_BARRIER="+barrier,
				"XLB_AGENT_READY="+filepath.Join(readyDirectory, strconv.Itoa(index)),
				"XLB_AGENT_REQUEST="+base64.RawURLEncoding.EncodeToString(requestJSON),
				"XLB_AGENT_PRIVATE_KEY="+base64.RawURLEncoding.EncodeToString(privateDER),
			)
			output, runErr := command.CombinedOutput()
			if runErr != nil {
				t.Errorf("authorization process failed: %v: %s", runErr, output)
				return
			}
			if strings.Contains(string(output), "REJECTED_CAPACITY") {
				return
			}
			if !strings.Contains(string(output), "AUTHORIZED") {
				t.Errorf("unexpected child result: %s", output)
				return
			}
			resultMu.Lock()
			accepted++
			resultMu.Unlock()
		}(i)
	}
	deadline := time.Now().Add(10 * time.Second)
	for {
		entries, err := os.ReadDir(readyDirectory)
		if err != nil {
			t.Fatal(err)
		}
		if len(entries) == attempts {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("only %d of %d authorization workers reached the barrier", len(entries), attempts)
		}
		time.Sleep(5 * time.Millisecond)
	}
	if err := os.WriteFile(barrier, []byte("start"), 0o600); err != nil {
		t.Fatal(err)
	}
	wait.Wait()
	if accepted != capacity {
		t.Fatalf("authorized %d sessions with node capacity %d", accepted, capacity)
	}
}

func runAuthorizationChild(t *testing.T) {
	t.Helper()
	store, err := OpenPeerStore(os.Getenv("XLB_AGENT_STATE"), "node-test", 3)
	if err != nil {
		t.Fatal(err)
	}
	requestBytes, err := base64.RawURLEncoding.DecodeString(os.Getenv("XLB_AGENT_REQUEST"))
	if err != nil {
		t.Fatal(err)
	}
	var request EnsurePeerRequest
	if err := json.Unmarshal(requestBytes, &request); err != nil {
		t.Fatal(err)
	}
	privateDER, err := base64.RawURLEncoding.DecodeString(os.Getenv("XLB_AGENT_PRIVATE_KEY"))
	if err != nil {
		t.Fatal(err)
	}
	parsed, err := x509.ParsePKCS8PrivateKey(privateDER)
	if err != nil {
		t.Fatal(err)
	}
	privateKey := parsed.(*ecdsa.PrivateKey)
	readyFile, err := os.OpenFile(os.Getenv("XLB_AGENT_READY"), os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	if err := readyFile.Close(); err != nil {
		t.Fatal(err)
	}
	for {
		if _, err := os.Stat(os.Getenv("XLB_AGENT_BARRIER")); err == nil {
			break
		}
		time.Sleep(time.Millisecond)
	}
	challenge, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := store.Authorize(signedAuthorization(t, privateKey, challenge, request)); err != nil {
		if errors.Is(err, ErrCapacity) {
			fmt.Print("REJECTED_CAPACITY")
			return
		}
		t.Fatal(err)
	}
	fmt.Print("AUTHORIZED")
}

func testEnsureRequest(t *testing.T, peerID, requestID string) (EnsurePeerRequest, *ecdsa.PrivateKey) {
	t.Helper()
	privateKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	publicDER, err := x509.MarshalPKIXPublicKey(&privateKey.PublicKey)
	if err != nil {
		t.Fatal(err)
	}
	return EnsurePeerRequest{
		NodeID: "node-test", PeerID: peerID, EntitlementID: "entitlement-test", RequestID: requestID, Generation: 1,
		DeviceID: "device-" + peerID, DeviceName: "Test device",
		DevicePublicKey:      base64.RawURLEncoding.EncodeToString(publicDER),
		DeviceKeyFingerprint: fingerprint(publicDER), DeviceBindingGeneration: 2,
		SubscriptionID: "subscription-" + peerID, SubscriptionToken: "url-token-" + peerID,
		SubscriptionGeneration: 4, PeerGeneration: 1, DeviceGeneration: 2,
		ExpiresAt: time.Now().Add(time.Hour).UTC(), Credential: "peer-secret-" + peerID,
	}, privateKey
}

func openTestStore(t *testing.T, capacity int) *PeerStore {
	t.Helper()
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), "node-test", capacity)
	if err != nil {
		t.Fatal(err)
	}
	return store
}

func challengeFor(request EnsurePeerRequest) ChallengeRequest {
	return ChallengeRequest{
		NodeID: request.NodeID, PeerID: request.PeerID, DeviceID: request.DeviceID,
		SubscriptionID: request.SubscriptionID, SubscriptionGeneration: request.SubscriptionGeneration,
		PeerGeneration: request.PeerGeneration, DeviceGeneration: request.DeviceGeneration,
	}
}

func signedAuthorization(t *testing.T, key *ecdsa.PrivateKey, challenge Challenge, request EnsurePeerRequest) AuthorizeRequest {
	t.Helper()
	message, err := ConnectionProofMessage(challengeFor(request), challenge.ID)
	if err != nil {
		t.Fatal(err)
	}
	digest := sha256.Sum256([]byte(message))
	signature, err := ecdsa.SignASN1(rand.Reader, key, digest[:])
	if err != nil {
		t.Fatal(err)
	}
	return AuthorizeRequest{
		ChallengeRequest: challengeFor(request), ChallengeID: challenge.ID,
		SessionID: "session-" + challenge.ID, SubscriptionToken: request.SubscriptionToken,
		Credential: request.Credential, Signature: base64.RawURLEncoding.EncodeToString(signature),
	}
}

func ownerChallengeForNewProof(t *testing.T, store *PeerStore, request EnsurePeerRequest) Challenge {
	t.Helper()
	challenge, err := store.IssueChallenge(challengeFor(request), request.SubscriptionToken, request.Credential)
	if err != nil {
		t.Fatal(err)
	}
	return challenge
}
