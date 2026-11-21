package agent

import (
	"context"
	"crypto/subtle"
	"encoding/hex"
	"errors"
	"strings"
	"time"
)

const (
	nodeProbePath           = "/v1/node/probe"
	nodeProbeChallengeBytes = 32
	nodeProbeTimeout        = 2 * time.Second
)

var errInvalidNodeProbeChallenge = errors.New("node probe challenge must be 32 bytes of hex")

type NodeDataPlaneProbeRequest struct {
	Challenge string `json:"challenge"`
}

type NodeDataPlaneProbeResponse struct {
	NodeID    string `json:"node_id"`
	Challenge string `json:"challenge"`
}

// DataPlaneProbeExecutor must perform an actual data-plane handshake through
// the configured canary target and verify that the target echoes challenge.
// Implementations must honor context cancellation and deadlines. Process
// liveness, local byte copying, and control-plane checks are not sufficient.
// This contract alone does not establish that a real executor or node is healthy.
type DataPlaneProbeExecutor interface {
	Exchange(ctx context.Context, challenge []byte) ([]byte, error)
}

func decodeNodeProbeChallenge(encoded string) ([]byte, error) {
	if len(encoded) != hex.EncodedLen(nodeProbeChallengeBytes) || strings.TrimSpace(encoded) != encoded {
		return nil, errInvalidNodeProbeChallenge
	}
	challenge, err := hex.DecodeString(encoded)
	if err != nil || len(challenge) != nodeProbeChallengeBytes {
		return nil, errInvalidNodeProbeChallenge
	}
	return challenge, nil
}

func equalProbeChallenge(expected, actual []byte) bool {
	return len(expected) == nodeProbeChallengeBytes && len(actual) == nodeProbeChallengeBytes &&
		subtle.ConstantTimeCompare(expected, actual) == 1
}
