package main

import (
	"strings"
	"testing"
)

func TestProductionModeRemainsUnavailable(t *testing.T) {
	t.Setenv("NODE_AGENT_MODE", "production")

	err := run()
	if err == nil || !strings.Contains(err.Error(), "production mode is not implemented") {
		t.Fatalf("run() error = %v; want production mode to fail closed", err)
	}
}
