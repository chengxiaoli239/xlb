package agent

import (
	"context"
	"errors"
	"io"
	"sync"
	"time"
)

var (
	ErrQuotaUnavailable = errors.New("traffic quota authority is unavailable")
	ErrQuotaExhausted   = errors.New("traffic quota lease is exhausted")
	ErrQuotaPending     = errors.New("traffic quota settlement is pending")
)

type QuotaReservationRequest struct {
	EntitlementID string `json:"entitlement_id"`
	NodeID        string `json:"node_id"`
	PeerID        string `json:"peer_id"`
	Generation    int    `json:"generation"`
	RequestID     string `json:"request_id"`
	GrantBytes    int64  `json:"grant_bytes"`
}

type QuotaReservation struct {
	QuotaReservationRequest
	ID             string    `json:"id"`
	Status         string    `json:"status"`
	LeaseExpiresAt time.Time `json:"lease_expires_at"`
}

type QuotaSettlement struct {
	NodeID            string `json:"node_id"`
	PeerID            string `json:"peer_id"`
	Generation        int    `json:"generation"`
	RequestID         string `json:"request_id"`
	AcknowledgementID string `json:"acknowledgement_id"`
	UploadBytes       int64  `json:"upload_bytes"`
	DownloadBytes     int64  `json:"download_bytes"`
	Final             bool   `json:"final"`
}

type PeerQuotaIdentity struct {
	NodeID        string
	EntitlementID string
	PeerID        string
	Generation    int
}

// QuotaAuthority is the central, shared entitlement budget. Its implementation
// must authenticate the node and durably bind every receipt to its reservation.
type QuotaAuthority interface {
	Reserve(context.Context, QuotaReservationRequest) (QuotaReservation, error)
	Settle(context.Context, QuotaSettlement) error
}

type QuotaLeaseProvider interface {
	OpenLease(context.Context, PeerQuotaIdentity) (*QuotaLease, error)
}

type QuotaLease struct {
	authority QuotaAuthority
	journal   QuotaJournal
	reserved  QuotaReservation
}

type QuotaPermit struct {
	lease     *QuotaLease
	direction string
	bytes     int64
	once      sync.Once
}

func OpenQuotaLease(ctx context.Context, authority QuotaAuthority, journal QuotaJournal, request QuotaReservationRequest) (*QuotaLease, error) {
	if authority == nil || journal == nil || request.EntitlementID == "" || request.NodeID == "" || request.PeerID == "" ||
		request.Generation < 1 || request.RequestID == "" || request.GrantBytes < 1 {
		return nil, ErrInvalid
	}
	snapshot, err := journal.BeginQuotaLease(request)
	if err != nil {
		return nil, errors.Join(ErrQuotaUnavailable, err)
	}
	if snapshot.Granted {
		if !snapshot.Reservation.LeaseExpiresAt.After(time.Now()) {
			return nil, ErrQuotaUnavailable
		}
		return &QuotaLease{authority: authority, journal: journal, reserved: snapshot.Reservation}, nil
	}
	reserved, err := authority.Reserve(ctx, request)
	if err != nil {
		return nil, errors.Join(ErrQuotaUnavailable, err)
	}
	if reserved.QuotaReservationRequest != request || reserved.ID == "" || reserved.Status != "reserved" ||
		!reserved.LeaseExpiresAt.After(time.Now()) {
		return nil, ErrQuotaUnavailable
	}
	if err := journal.ActivateQuotaLease(reserved); err != nil {
		return nil, errors.Join(ErrQuotaUnavailable, err)
	}
	return &QuotaLease{authority: authority, journal: journal, reserved: reserved}, nil
}

// Permit holds capacity before any bytes are forwarded. Commit records the
// number actually forwarded; callers must not write more than the permit.
func (lease *QuotaLease) Permit(direction string, bytes int64) (*QuotaPermit, error) {
	if direction != "upload" && direction != "download" || bytes < 1 {
		return nil, ErrInvalid
	}
	if err := lease.journal.ReserveQuotaPermit(lease.reserved.RequestID, direction, bytes); err != nil {
		if errors.Is(err, ErrQuotaExhausted) {
			return nil, err
		}
		return nil, errors.Join(ErrQuotaUnavailable, err)
	}
	return &QuotaPermit{lease: lease, direction: direction, bytes: bytes}, nil
}

func (permit *QuotaPermit) Commit(forwarded int64) error {
	if permit == nil || permit.lease == nil || forwarded < 0 || forwarded > permit.bytes {
		return ErrInvalid
	}
	committed := false
	permit.once.Do(func() {
		if err := permit.lease.journal.CommitQuotaPermit(
			permit.lease.reserved.RequestID, permit.direction, permit.bytes, forwarded,
		); err != nil {
			return
		}
		committed = true
	})
	if !committed {
		return ErrQuotaPending
	}
	return nil
}

// Finalize retries exactly the same cumulative receipt after an unknown outcome.
// A failed call keeps the lease closed to new traffic and holds central capacity.
func (lease *QuotaLease) Finalize(ctx context.Context, acknowledgementID string) error {
	if acknowledgementID == "" {
		return ErrInvalid
	}
	snapshot, err := lease.journal.FinalizeQuotaLease(lease.reserved.RequestID, acknowledgementID)
	if err != nil {
		if errors.Is(err, ErrRequestConflict) {
			return err
		}
		return errors.Join(ErrQuotaPending, err)
	}
	if snapshot.Settled {
		return nil
	}
	receipt := QuotaSettlement{
		NodeID: snapshot.Reservation.NodeID, PeerID: snapshot.Reservation.PeerID,
		Generation: snapshot.Reservation.Generation, RequestID: snapshot.Reservation.RequestID,
		AcknowledgementID: snapshot.FinalAcknowledgementID, UploadBytes: snapshot.UploadBytes,
		DownloadBytes: snapshot.DownloadBytes, Final: true,
	}
	if err := lease.authority.Settle(ctx, receipt); err != nil {
		return errors.Join(ErrQuotaPending, err)
	}
	if err := lease.journal.MarkQuotaSettled(receipt.RequestID, acknowledgementID); err != nil {
		return errors.Join(ErrQuotaPending, err)
	}
	return nil
}

func CopyWithQuota(destination io.Writer, source io.Reader, lease *QuotaLease, direction string) (int64, error) {
	if destination == nil || source == nil || lease == nil ||
		(direction != "upload" && direction != "download") {
		return 0, ErrInvalid
	}
	buffer := make([]byte, 32*1024)
	var forwarded int64
	emptyReads := 0
	for {
		read, readErr := source.Read(buffer)
		offset := 0
		for offset < read {
			chunk := read - offset
			var permit *QuotaPermit
			var err error
			for {
				permit, err = lease.Permit(direction, int64(chunk))
				if !errors.Is(err, ErrQuotaExhausted) || chunk == 1 {
					break
				}
				chunk = (chunk + 1) / 2
			}
			if err != nil {
				return forwarded, err
			}
			written, writeErr := destination.Write(buffer[offset : offset+chunk])
			if written < 0 || written > chunk {
				return forwarded, ErrInvalid
			}
			if commitErr := permit.Commit(int64(written)); commitErr != nil {
				return forwarded + int64(written), errors.Join(ErrQuotaPending, commitErr)
			}
			forwarded += int64(written)
			offset += written
			if writeErr != nil {
				return forwarded, writeErr
			}
			if written != chunk {
				return forwarded, io.ErrShortWrite
			}
		}
		if readErr == io.EOF {
			return forwarded, nil
		}
		if readErr != nil {
			return forwarded, readErr
		}
		if read == 0 {
			emptyReads++
			if emptyReads >= 100 {
				return forwarded, io.ErrNoProgress
			}
		} else {
			emptyReads = 0
		}
	}
}
