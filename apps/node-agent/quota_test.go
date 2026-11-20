package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"
)

func TestQuotaReservationAndSettlementUseCanonicalJSONFields(t *testing.T) {
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 2, RequestID: "request-1", GrantBytes: 4096,
	}
	encodedRequest, err := json.Marshal(request)
	if err != nil {
		t.Fatal(err)
	}
	if string(encodedRequest) != `{"entitlement_id":"entitlement-1","node_id":"node-1","peer_id":"peer-1","generation":2,"request_id":"request-1","grant_bytes":4096}` {
		t.Fatalf("unexpected quota request JSON: %s", encodedRequest)
	}

	settlement := QuotaSettlement{
		NodeID: "node-1", PeerID: "peer-1", Generation: 2, RequestID: "request-1",
		AcknowledgementID: "ack-1", UploadBytes: 100, DownloadBytes: 200, Final: true,
	}
	encodedSettlement, err := json.Marshal(settlement)
	if err != nil {
		t.Fatal(err)
	}
	if string(encodedSettlement) != `{"node_id":"node-1","peer_id":"peer-1","generation":2,"request_id":"request-1","acknowledgement_id":"ack-1","upload_bytes":100,"download_bytes":200,"final":true}` {
		t.Fatalf("unexpected quota settlement JSON: %s", encodedSettlement)
	}
}

func TestDurableQuotaLeaseProviderUsesAPICompatibleRequestIDAndPeerIdentity(t *testing.T) {
	authority := newQuotaAuthorityFake(1024)
	journal := openQuotaTestJournal(t, "node-provider")
	provider, err := NewDurableQuotaLeaseProvider(authority, journal, 256)
	if err != nil {
		t.Fatal(err)
	}
	identity := PeerQuotaIdentity{
		NodeID: "node-provider", EntitlementID: "entitlement-provider", PeerID: "peer-provider", Generation: 3,
	}
	lease, err := provider.OpenLease(context.Background(), identity)
	if err != nil {
		t.Fatal(err)
	}
	request := lease.reserved.QuotaReservationRequest
	if len(request.RequestID) != 36 || request.RequestID[8] != '-' || request.RequestID[13] != '-' ||
		request.RequestID[18] != '-' || request.RequestID[23] != '-' || strings.Contains(request.RequestID, "_") {
		t.Fatalf("request ID %q is not compatible with Laravel's URL-safe UUID contract", request.RequestID)
	}
	if request.NodeID != identity.NodeID || request.EntitlementID != identity.EntitlementID ||
		request.PeerID != identity.PeerID || request.Generation != identity.Generation || request.GrantBytes != 256 {
		t.Fatalf("reservation request does not match peer identity and configured lease: %+v", request)
	}
	snapshot, err := journal.quotaLease(request.RequestID)
	if err != nil || !snapshot.Granted || snapshot.Reservation.QuotaReservationRequest != request {
		t.Fatalf("reservation intent was not durably activated: snapshot=%+v err=%v", snapshot, err)
	}
	if _, err := NewDurableQuotaLeaseProvider(nil, journal, 256); !errors.Is(err, ErrInvalid) {
		t.Fatalf("provider without authority error = %v; want fail-closed invalid", err)
	}
}

type quotaAuthorityFake struct {
	mu                            sync.Mutex
	quota                         int64
	reserved                      int64
	used                          int64
	reservations                  map[string]QuotaReservation
	settlements                   map[string]QuotaSettlement
	failNextFinal                 bool
	failNextReserveAfterExecution bool
	unavailable                   bool
}

func newQuotaAuthorityFake(quota int64) *quotaAuthorityFake {
	return &quotaAuthorityFake{
		quota: quota, reservations: make(map[string]QuotaReservation),
		settlements: make(map[string]QuotaSettlement),
	}
}

func (authority *quotaAuthorityFake) Reserve(_ context.Context, request QuotaReservationRequest) (QuotaReservation, error) {
	authority.mu.Lock()
	defer authority.mu.Unlock()
	if authority.unavailable {
		return QuotaReservation{}, errors.New("authority unavailable")
	}
	if existing, ok := authority.reservations[request.RequestID]; ok {
		if existing.QuotaReservationRequest != request {
			return QuotaReservation{}, ErrRequestConflict
		}
		return existing, nil
	}
	if request.GrantBytes > authority.quota-authority.used-authority.reserved {
		return QuotaReservation{}, ErrQuotaExhausted
	}
	reservation := QuotaReservation{
		QuotaReservationRequest: request, ID: "reservation:" + request.RequestID,
		Status: "reserved", LeaseExpiresAt: time.Now().Add(time.Minute),
	}
	authority.reservations[request.RequestID] = reservation
	authority.reserved += request.GrantBytes
	if authority.failNextReserveAfterExecution {
		authority.failNextReserveAfterExecution = false
		return QuotaReservation{}, errors.New("response lost after reservation")
	}
	return reservation, nil
}

func (authority *quotaAuthorityFake) Settle(_ context.Context, receipt QuotaSettlement) error {
	authority.mu.Lock()
	defer authority.mu.Unlock()
	reservation, ok := authority.reservations[receipt.RequestID]
	if !ok || receipt.NodeID != reservation.NodeID || receipt.PeerID != reservation.PeerID ||
		receipt.Generation != reservation.Generation || receipt.AcknowledgementID == "" ||
		receipt.UploadBytes < 0 || receipt.DownloadBytes < 0 ||
		receipt.UploadBytes > reservation.GrantBytes-receipt.DownloadBytes || !receipt.Final {
		return ErrInvalid
	}
	total := receipt.UploadBytes + receipt.DownloadBytes
	if existing, ok := authority.settlements[receipt.RequestID]; ok {
		if existing != receipt {
			return ErrRequestConflict
		}
		return nil
	}
	authority.settlements[receipt.RequestID] = receipt
	authority.reserved -= reservation.GrantBytes
	authority.used += total
	if authority.failNextFinal {
		authority.failNextFinal = false
		return errors.New("response lost after settlement")
	}
	return nil
}

func TestQuotaLeaseReservationsAcrossAgentsNeverOversellSharedEntitlement(t *testing.T) {
	const contenders = 12
	authority := newQuotaAuthorityFake(3)
	start := make(chan struct{})
	results := make(chan error, contenders)
	journals := make([]*PeerStore, contenders)
	for index := range contenders {
		journals[index] = openQuotaTestJournal(t, fmt.Sprintf("node-%d", index%2))
	}
	var wait sync.WaitGroup
	for index := range contenders {
		wait.Add(1)
		go func(index int) {
			defer wait.Done()
			<-start
			request := QuotaReservationRequest{
				EntitlementID: "entitlement-1", NodeID: fmt.Sprintf("node-%d", index%2),
				PeerID: fmt.Sprintf("peer-%d", index), Generation: 1,
				RequestID: fmt.Sprintf("request-%d", index), GrantBytes: 1,
			}
			_, err := OpenQuotaLease(context.Background(), authority, journals[index], request)
			results <- err
		}(index)
	}
	close(start)
	wait.Wait()
	close(results)
	reserved, rejected := 0, 0
	for err := range results {
		if err == nil {
			reserved++
		} else if errors.Is(err, ErrQuotaExhausted) {
			rejected++
		} else {
			t.Fatalf("unexpected reservation error: %v", err)
		}
	}
	if reserved != 3 || rejected != contenders-3 || authority.reserved != 3 {
		t.Fatalf("reserved=%d rejected=%d authority_reserved=%d; want 3/%d/3", reserved, rejected, authority.reserved, contenders-3)
	}
}

func TestQuotaLeasePermitsBoundConcurrentForwardingAndSettlesCumulativeTraffic(t *testing.T) {
	authority := newQuotaAuthorityFake(3)
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 2, RequestID: "request-1", GrantBytes: 3,
	}
	journal := openQuotaTestJournal(t, request.NodeID)
	lease, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}

	start := make(chan struct{})
	results := make(chan error, 12)
	var wait sync.WaitGroup
	for range 12 {
		wait.Add(1)
		go func() {
			defer wait.Done()
			<-start
			permit, permitErr := lease.Permit("upload", 1)
			if permitErr != nil {
				results <- permitErr
				return
			}
			results <- permit.Commit(1)
		}()
	}
	close(start)
	wait.Wait()
	close(results)
	committed, exhausted := 0, 0
	for result := range results {
		if result == nil {
			committed++
		} else if errors.Is(result, ErrQuotaExhausted) {
			exhausted++
		} else {
			t.Fatalf("unexpected permit result: %v", result)
		}
	}
	if committed != 3 || exhausted != 9 {
		t.Fatalf("committed=%d exhausted=%d; want 3/9", committed, exhausted)
	}
	if err := lease.Finalize(context.Background(), "node-ack-1"); err != nil {
		t.Fatal(err)
	}
	receipt := authority.settlements["request-1"]
	if receipt.UploadBytes != 3 || receipt.DownloadBytes != 0 || authority.reserved != 0 || authority.used != 3 {
		t.Fatalf("unexpected final settlement: %+v authority=%+v", receipt, authority)
	}
}

func TestQuotaLeaseUnknownSettlementOutcomeRetriesSameFinalReceipt(t *testing.T) {
	authority := newQuotaAuthorityFake(10)
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 1, RequestID: "request-timeout", GrantBytes: 10,
	}
	journal := openQuotaTestJournal(t, request.NodeID)
	lease, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}
	permit, err := lease.Permit("download", 4)
	if err != nil {
		t.Fatal(err)
	}
	if err := permit.Commit(4); err != nil {
		t.Fatal(err)
	}
	authority.failNextFinal = true
	if err := lease.Finalize(context.Background(), "node-ack-final"); !errors.Is(err, ErrQuotaPending) {
		t.Fatalf("first Finalize error = %v; want pending", err)
	}
	if _, err := lease.Permit("upload", 1); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("permit after unknown final outcome = %v; want fail closed", err)
	}
	if err := lease.Finalize(context.Background(), "node-ack-final"); err != nil {
		t.Fatal(err)
	}
	if err := lease.Finalize(context.Background(), "different-ack"); !errors.Is(err, ErrRequestConflict) {
		t.Fatalf("different acknowledgement must be refused: %v", err)
	}
	if authority.used != 4 || authority.reserved != 0 || len(authority.settlements) != 1 {
		t.Fatalf("settlement was not idempotent: %+v", authority)
	}
}

func TestQuotaLeaseRejectsInvalidPermitsAndKeepsBytesHeldUntilSettlement(t *testing.T) {
	authority := newQuotaAuthorityFake(4)
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 1, RequestID: "request-unused", GrantBytes: 4,
	}
	journal := openQuotaTestJournal(t, request.NodeID)
	lease, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := lease.Permit("other", 1); !errors.Is(err, ErrInvalid) {
		t.Fatalf("invalid direction error = %v", err)
	}
	permit, err := lease.Permit("download", 4)
	if err != nil {
		t.Fatal(err)
	}
	if err := lease.Finalize(context.Background(), "ack-pending"); !errors.Is(err, ErrQuotaPending) {
		t.Fatalf("Finalize with in-flight permit error = %v", err)
	}
	if err := permit.Commit(2); err != nil {
		t.Fatal(err)
	}
	if err := lease.Finalize(context.Background(), "ack-final"); err != nil {
		t.Fatal(err)
	}
	if authority.used != 2 || authority.reserved != 0 {
		t.Fatalf("unused reservation was not released after final receipt: %+v", authority)
	}
}

func TestQuotaLeaseFailsClosedWhenAuthorityCannotReserve(t *testing.T) {
	authority := newQuotaAuthorityFake(10)
	authority.unavailable = true
	journal := openQuotaTestJournal(t, "node-1")
	_, err := OpenQuotaLease(context.Background(), authority, journal, QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 1, RequestID: "request-unavailable", GrantBytes: 1,
	})
	if !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("OpenQuotaLease error = %v; want unavailable", err)
	}
}

func TestCopyWithQuotaNeverForwardsBeyondTheGrantedByteLease(t *testing.T) {
	authority := newQuotaAuthorityFake(5)
	journal := openQuotaTestJournal(t, "node-1")
	lease, err := OpenQuotaLease(context.Background(), authority, journal, QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-copy",
		Generation: 1, RequestID: "request-copy", GrantBytes: 5,
	})
	if err != nil {
		t.Fatal(err)
	}
	var forwarded bytes.Buffer
	count, err := CopyWithQuota(&forwarded, bytes.NewBufferString("abcdefgh"), lease, "upload")
	if !errors.Is(err, ErrQuotaExhausted) {
		t.Fatalf("CopyWithQuota error = %v; want quota exhaustion", err)
	}
	if count != 5 || forwarded.String() != "abcde" {
		t.Fatalf("forwarded %d bytes %q; want exactly 5 bytes", count, forwarded.String())
	}
	if err := lease.Finalize(context.Background(), "ack-copy"); err != nil {
		t.Fatal(err)
	}
	if authority.used != 5 || authority.reserved != 0 {
		t.Fatalf("unexpected final byte accounting: %+v", authority)
	}
}

func TestQuotaLeaseDuplicateRequestCannotSpendItsSharedGrantTwice(t *testing.T) {
	authority := newQuotaAuthorityFake(3)
	journal := openQuotaTestJournal(t, "node-1")
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-1",
		Generation: 1, RequestID: "request-shared-lease", GrantBytes: 3,
	}
	first, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}
	second, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}
	firstPermit, err := first.Permit("upload", 2)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := second.Permit("download", 2); !errors.Is(err, ErrQuotaExhausted) {
		t.Fatalf("duplicate lease permit error = %v; want shared capacity exhaustion", err)
	}
	if err := firstPermit.Commit(2); err != nil {
		t.Fatal(err)
	}
	secondPermit, err := second.Permit("download", 1)
	if err != nil {
		t.Fatal(err)
	}
	if err := secondPermit.Commit(1); err != nil {
		t.Fatal(err)
	}
	if err := first.Finalize(context.Background(), "node-ack-shared"); err != nil {
		t.Fatal(err)
	}
	receipt := authority.settlements[request.RequestID]
	if receipt.UploadBytes != 2 || receipt.DownloadBytes != 1 || authority.used != 3 {
		t.Fatalf("duplicate lease overran shared grant: %+v authority=%+v", receipt, authority)
	}
}

func TestQuotaLeasePermitIsAtomicAcrossProcesses(t *testing.T) {
	if os.Getenv("XLB_QUOTA_CHILD") == "1" {
		runQuotaPermitChild(t)
		return
	}
	const workers = 12
	const grantBytes = 3
	authority := newQuotaAuthorityFake(grantBytes)
	statePath := filepath.Join(t.TempDir(), "agent-state.json")
	journal, err := OpenPeerStore(statePath, "node-1", 10)
	if err != nil {
		t.Fatal(err)
	}
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-cross-process",
		Generation: 1, RequestID: "request-cross-process", GrantBytes: grantBytes,
	}
	if _, err := OpenQuotaLease(context.Background(), authority, journal, request); err != nil {
		t.Fatal(err)
	}
	barrierPath := filepath.Join(t.TempDir(), "start")
	readyDirectory := filepath.Join(t.TempDir(), "ready")
	if err := os.Mkdir(readyDirectory, 0o700); err != nil {
		t.Fatal(err)
	}
	var wait sync.WaitGroup
	results := make(chan string, workers)
	for index := range workers {
		wait.Add(1)
		go func(index int) {
			defer wait.Done()
			command := exec.Command(os.Args[0], "-test.run=^TestQuotaLeasePermitIsAtomicAcrossProcesses$")
			command.Env = append(os.Environ(),
				"XLB_QUOTA_CHILD=1",
				"XLB_QUOTA_STATE="+statePath,
				"XLB_QUOTA_BARRIER="+barrierPath,
				"XLB_QUOTA_READY="+filepath.Join(readyDirectory, strconv.Itoa(index)),
				"XLB_QUOTA_REQUEST="+request.RequestID,
			)
			output, err := command.CombinedOutput()
			if err != nil {
				results <- "error:" + err.Error() + ":" + string(output)
				return
			}
			results <- strings.TrimSpace(string(output))
		}(index)
	}
	deadline := time.Now().Add(10 * time.Second)
	for {
		entries, err := os.ReadDir(readyDirectory)
		if err != nil {
			t.Fatal(err)
		}
		if len(entries) == workers {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("only %d of %d quota workers reached the barrier", len(entries), workers)
		}
		time.Sleep(5 * time.Millisecond)
	}
	if err := os.WriteFile(barrierPath, []byte("start"), 0o600); err != nil {
		t.Fatal(err)
	}
	wait.Wait()
	close(results)
	committed, exhausted := 0, 0
	for result := range results {
		switch {
		case strings.Contains(result, "QUOTA_COMMITTED"):
			committed++
		case strings.Contains(result, "QUOTA_FULL"):
			exhausted++
		default:
			t.Fatalf("unexpected quota worker result: %s", result)
		}
	}
	if committed != grantBytes || exhausted != workers-grantBytes {
		t.Fatalf("committed=%d exhausted=%d; want %d/%d", committed, exhausted, grantBytes, workers-grantBytes)
	}
	snapshot, err := journal.quotaLease(request.RequestID)
	if err != nil {
		t.Fatal(err)
	}
	if snapshot.UploadBytes != grantBytes || snapshot.PendingBytes != 0 {
		t.Fatalf("cross-process quota journal exceeded its grant: %+v", snapshot)
	}
}

func TestQuotaSettlementOutboxResumesAfterAgentRestartAndUnknownResponse(t *testing.T) {
	authority := newQuotaAuthorityFake(10)
	journalPath := filepath.Join(t.TempDir(), "agent-state.json")
	journal, err := OpenPeerStore(journalPath, "node-1", 5)
	if err != nil {
		t.Fatal(err)
	}
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-restart",
		Generation: 2, RequestID: "request-restart", GrantBytes: 10,
	}
	lease, err := OpenQuotaLease(context.Background(), authority, journal, request)
	if err != nil {
		t.Fatal(err)
	}
	permit, err := lease.Permit("download", 4)
	if err != nil {
		t.Fatal(err)
	}
	if err := permit.Commit(4); err != nil {
		t.Fatal(err)
	}
	authority.failNextFinal = true
	if err := lease.Finalize(context.Background(), "node-ack-restart"); !errors.Is(err, ErrQuotaPending) {
		t.Fatalf("Finalize error = %v; want pending unknown outcome", err)
	}

	restartedJournal, err := OpenPeerStore(journalPath, "node-1", 5)
	if err != nil {
		t.Fatal(err)
	}
	resumed, err := ResumeQuotaSettlementOutbox(context.Background(), restartedJournal, authority, 10)
	if err != nil {
		t.Fatal(err)
	}
	if resumed != 1 || authority.used != 4 || authority.reserved != 0 {
		t.Fatalf("resumed=%d used=%d reserved=%d", resumed, authority.used, authority.reserved)
	}
	pending, err := restartedJournal.PendingQuotaSettlements(10)
	if err != nil {
		t.Fatal(err)
	}
	if len(pending) != 0 {
		t.Fatalf("settled outbox still pending: %+v", pending)
	}
}

func TestQuotaReservationOutboxRetriesSameRequestAfterUnknownReserveOutcome(t *testing.T) {
	authority := newQuotaAuthorityFake(5)
	journalPath := filepath.Join(t.TempDir(), "agent-state.json")
	journal, err := OpenPeerStore(journalPath, "node-1", 5)
	if err != nil {
		t.Fatal(err)
	}
	request := QuotaReservationRequest{
		EntitlementID: "entitlement-1", NodeID: "node-1", PeerID: "peer-reserve-timeout",
		Generation: 1, RequestID: "request-reserve-timeout", GrantBytes: 5,
	}
	authority.failNextReserveAfterExecution = true
	if _, err := OpenQuotaLease(context.Background(), authority, journal, request); !errors.Is(err, ErrQuotaUnavailable) {
		t.Fatalf("OpenQuotaLease error = %v; want unknown reservation outcome", err)
	}
	restartedJournal, err := OpenPeerStore(journalPath, "node-1", 5)
	if err != nil {
		t.Fatal(err)
	}
	resumed, err := ResumeQuotaReservationOutbox(context.Background(), restartedJournal, authority, 10)
	if err != nil || resumed != 1 {
		t.Fatalf("reservation outbox resumed %d requests, err=%v", resumed, err)
	}
	lease, err := OpenQuotaLease(context.Background(), authority, restartedJournal, request)
	if err != nil {
		t.Fatal(err)
	}
	permit, err := lease.Permit("upload", 5)
	if err != nil {
		t.Fatal(err)
	}
	if err := permit.Commit(5); err != nil {
		t.Fatal(err)
	}
	if err := lease.Finalize(context.Background(), "ack-reserved-once"); err != nil {
		t.Fatal(err)
	}
	if authority.reserved != 0 || authority.used != 5 || len(authority.reservations) != 1 {
		t.Fatalf("reservation retry was not idempotent: %+v", authority)
	}
}

func openQuotaTestJournal(t *testing.T, nodeID string) *PeerStore {
	t.Helper()
	store, err := OpenPeerStore(filepath.Join(t.TempDir(), "agent-state.json"), nodeID, 10)
	if err != nil {
		t.Fatal(err)
	}
	return store
}

func runQuotaPermitChild(t *testing.T) {
	t.Helper()
	store, err := OpenPeerStore(os.Getenv("XLB_QUOTA_STATE"), "node-1", 10)
	if err != nil {
		t.Fatal(err)
	}
	ready, err := os.OpenFile(os.Getenv("XLB_QUOTA_READY"), os.O_CREATE|os.O_WRONLY, 0o600)
	if err != nil {
		t.Fatal(err)
	}
	if err := ready.Close(); err != nil {
		t.Fatal(err)
	}
	barrier := os.Getenv("XLB_QUOTA_BARRIER")
	deadline := time.Now().Add(10 * time.Second)
	for {
		if _, err := os.Stat(barrier); err == nil {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("quota process start barrier timed out")
		}
		time.Sleep(5 * time.Millisecond)
	}
	requestID := os.Getenv("XLB_QUOTA_REQUEST")
	if err := store.ReserveQuotaPermit(requestID, "upload", 1); err != nil {
		if errors.Is(err, ErrQuotaExhausted) {
			fmt.Print("QUOTA_FULL")
			return
		}
		t.Fatal(err)
	}
	if err := store.CommitQuotaPermit(requestID, "upload", 1, 1); err != nil {
		t.Fatal(err)
	}
	fmt.Print("QUOTA_COMMITTED")
}
