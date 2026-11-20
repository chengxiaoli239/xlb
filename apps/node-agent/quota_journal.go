package agent

import (
	"context"
	"errors"
	"fmt"
	"time"
)

type QuotaLeaseSnapshot struct {
	Reservation            QuotaReservation
	Granted                bool
	UploadBytes            int64
	DownloadBytes          int64
	PendingBytes           int64
	Final                  bool
	FinalAcknowledgementID string
	Settled                bool
}

type QuotaJournal interface {
	BeginQuotaLease(QuotaReservationRequest) (QuotaLeaseSnapshot, error)
	ActivateQuotaLease(QuotaReservation) error
	ReserveQuotaPermit(string, string, int64) error
	CommitQuotaPermit(string, string, int64, int64) error
	FinalizeQuotaLease(string, string) (QuotaLeaseSnapshot, error)
	PendingQuotaReservations(int) ([]QuotaReservationRequest, error)
	PendingQuotaSettlements(int) ([]QuotaSettlement, error)
	MarkQuotaSettled(string, string) error
}

func (store *PeerStore) BeginQuotaLease(request QuotaReservationRequest) (QuotaLeaseSnapshot, error) {
	if request.NodeID != store.nodeID || request.EntitlementID == "" || request.PeerID == "" ||
		request.RequestID == "" || request.Generation < 1 || request.GrantBytes < 1 {
		return QuotaLeaseSnapshot{}, ErrInvalid
	}
	var result QuotaLeaseSnapshot
	err := store.update(func(state *agentState) error {
		if previous, exists := state.QuotaLeases[request.RequestID]; exists {
			if previous.Reservation.QuotaReservationRequest != request {
				return ErrRequestConflict
			}
			result = previous
			return nil
		}
		result = QuotaLeaseSnapshot{Reservation: QuotaReservation{QuotaReservationRequest: request}}
		state.QuotaLeases[request.RequestID] = result
		return nil
	})
	return result, err
}

func (store *PeerStore) ActivateQuotaLease(reservation QuotaReservation) error {
	request := reservation.QuotaReservationRequest
	if request.NodeID != store.nodeID || reservation.ID == "" || reservation.Status != "reserved" ||
		!reservation.LeaseExpiresAt.After(time.Now()) {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		snapshot, exists := state.QuotaLeases[request.RequestID]
		if !exists || snapshot.Reservation.QuotaReservationRequest != request {
			return ErrRequestConflict
		}
		if snapshot.Granted {
			if !snapshot.Reservation.LeaseExpiresAt.Equal(reservation.LeaseExpiresAt) {
				return ErrRequestConflict
			}
			return nil
		}
		snapshot.Reservation = reservation
		snapshot.Granted = true
		state.QuotaLeases[request.RequestID] = snapshot
		return nil
	})
}

func (store *PeerStore) ReserveQuotaPermit(requestID string, direction string, bytes int64) error {
	if requestID == "" || (direction != "upload" && direction != "download") || bytes < 1 {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		snapshot, exists := state.QuotaLeases[requestID]
		if !exists {
			return ErrNotFound
		}
		request := snapshot.Reservation.QuotaReservationRequest
		if !snapshot.Granted || snapshot.Final || !snapshot.Reservation.LeaseExpiresAt.After(time.Now()) {
			return ErrQuotaUnavailable
		}
		if bytes > request.GrantBytes-snapshot.UploadBytes-snapshot.DownloadBytes-snapshot.PendingBytes {
			return ErrQuotaExhausted
		}
		snapshot.PendingBytes += bytes
		state.QuotaLeases[requestID] = snapshot
		return nil
	})
}

func (store *PeerStore) CommitQuotaPermit(requestID string, direction string, bytes int64, forwarded int64) error {
	if requestID == "" || (direction != "upload" && direction != "download") ||
		bytes < 1 || forwarded < 0 || forwarded > bytes {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		snapshot, exists := state.QuotaLeases[requestID]
		if !exists || snapshot.PendingBytes < bytes {
			return ErrInvalid
		}
		snapshot.PendingBytes -= bytes
		if direction == "upload" {
			snapshot.UploadBytes += forwarded
		} else {
			snapshot.DownloadBytes += forwarded
		}
		state.QuotaLeases[requestID] = snapshot
		return nil
	})
}

func (store *PeerStore) FinalizeQuotaLease(requestID string, acknowledgementID string) (QuotaLeaseSnapshot, error) {
	if requestID == "" || acknowledgementID == "" {
		return QuotaLeaseSnapshot{}, ErrInvalid
	}
	var result QuotaLeaseSnapshot
	err := store.update(func(state *agentState) error {
		snapshot, exists := state.QuotaLeases[requestID]
		if !exists {
			return ErrNotFound
		}
		if snapshot.PendingBytes != 0 {
			return ErrQuotaPending
		}
		if snapshot.FinalAcknowledgementID != "" && snapshot.FinalAcknowledgementID != acknowledgementID {
			return ErrRequestConflict
		}
		snapshot.Final = true
		snapshot.FinalAcknowledgementID = acknowledgementID
		state.QuotaLeases[requestID] = snapshot
		result = snapshot
		return nil
	})
	return result, err
}

func (store *PeerStore) PendingQuotaSettlements(limit int) ([]QuotaSettlement, error) {
	if limit < 1 {
		return nil, ErrInvalid
	}
	var pending []QuotaSettlement
	err := store.read(func(state *agentState) error {
		for _, snapshot := range state.QuotaLeases {
			if !snapshot.Final || snapshot.Settled || snapshot.PendingBytes != 0 {
				continue
			}
			request := snapshot.Reservation.QuotaReservationRequest
			pending = append(pending, QuotaSettlement{
				NodeID: request.NodeID, PeerID: request.PeerID, Generation: request.Generation,
				RequestID: request.RequestID, AcknowledgementID: snapshot.FinalAcknowledgementID,
				UploadBytes: snapshot.UploadBytes, DownloadBytes: snapshot.DownloadBytes, Final: true,
			})
			if len(pending) >= limit {
				break
			}
		}
		return nil
	})
	return pending, err
}

func (store *PeerStore) PendingQuotaReservations(limit int) ([]QuotaReservationRequest, error) {
	if limit < 1 {
		return nil, ErrInvalid
	}
	var pending []QuotaReservationRequest
	err := store.read(func(state *agentState) error {
		for _, snapshot := range state.QuotaLeases {
			if !snapshot.Granted && !snapshot.Final {
				pending = append(pending, snapshot.Reservation.QuotaReservationRequest)
			}
			if len(pending) >= limit {
				break
			}
		}
		return nil
	})
	return pending, err
}

func (store *PeerStore) MarkQuotaSettled(requestID string, acknowledgementID string) error {
	if requestID == "" || acknowledgementID == "" {
		return ErrInvalid
	}
	return store.update(func(state *agentState) error {
		snapshot, exists := state.QuotaLeases[requestID]
		if !exists || !snapshot.Final || snapshot.PendingBytes != 0 ||
			snapshot.FinalAcknowledgementID != acknowledgementID {
			return ErrInvalid
		}
		if snapshot.Settled {
			return nil
		}
		snapshot.Settled = true
		state.QuotaLeases[requestID] = snapshot
		return nil
	})
}

func (store *PeerStore) quotaLease(requestID string) (QuotaLeaseSnapshot, error) {
	var snapshot QuotaLeaseSnapshot
	err := store.read(func(state *agentState) error {
		var exists bool
		snapshot, exists = state.QuotaLeases[requestID]
		if !exists {
			return ErrNotFound
		}
		return nil
	})
	return snapshot, err
}

func ResumeQuotaSettlementOutbox(
	ctx context.Context,
	journal QuotaJournal,
	authority QuotaAuthority,
	limit int,
) (int, error) {
	if journal == nil || authority == nil || limit < 1 {
		return 0, ErrInvalid
	}
	pending, err := journal.PendingQuotaSettlements(limit)
	if err != nil {
		return 0, fmt.Errorf("load pending quota settlements: %w", err)
	}
	settled := 0
	for _, receipt := range pending {
		if err := authority.Settle(ctx, receipt); err != nil {
			return settled, errors.Join(ErrQuotaPending, err)
		}
		if err := journal.MarkQuotaSettled(receipt.RequestID, receipt.AcknowledgementID); err != nil {
			return settled, errors.Join(ErrQuotaPending, err)
		}
		settled++
	}
	return settled, nil
}

func ResumeQuotaReservationOutbox(
	ctx context.Context,
	journal QuotaJournal,
	authority QuotaAuthority,
	limit int,
) (int, error) {
	if journal == nil || authority == nil || limit < 1 {
		return 0, ErrInvalid
	}
	pending, err := journal.PendingQuotaReservations(limit)
	if err != nil {
		return 0, fmt.Errorf("load pending quota reservations: %w", err)
	}
	reserved := 0
	for _, request := range pending {
		reservation, err := authority.Reserve(ctx, request)
		if err != nil {
			return reserved, errors.Join(ErrQuotaPending, err)
		}
		if reservation.QuotaReservationRequest != request || reservation.ID == "" || reservation.Status != "reserved" ||
			!reservation.LeaseExpiresAt.After(time.Now()) {
			return reserved, ErrQuotaUnavailable
		}
		if err := journal.ActivateQuotaLease(reservation); err != nil {
			return reserved, errors.Join(ErrQuotaPending, err)
		}
		reserved++
	}
	return reserved, nil
}

func ResumeQuotaOutboxes(ctx context.Context, journal QuotaJournal, authority QuotaAuthority, limit int) error {
	if ctx == nil || journal == nil || authority == nil || limit < 1 || limit > 1000 {
		return ErrInvalid
	}
	if _, err := ResumeQuotaSettlementOutbox(ctx, journal, authority, limit); err != nil {
		return err
	}
	if _, err := ResumeQuotaReservationOutbox(ctx, journal, authority, limit); err != nil {
		return err
	}
	pendingSettlements, err := journal.PendingQuotaSettlements(limit)
	if err != nil {
		return errors.Join(ErrQuotaPending, err)
	}
	pendingReservations, err := journal.PendingQuotaReservations(limit)
	if err != nil {
		return errors.Join(ErrQuotaPending, err)
	}
	if len(pendingSettlements) > 0 || len(pendingReservations) > 0 {
		return ErrQuotaPending
	}
	return nil
}
