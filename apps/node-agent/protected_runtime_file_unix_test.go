//go:build !windows

package agent

import (
	"os"
	"path/filepath"
	"testing"
)

func TestValidateProtectedNodeRuntimeFileRequiresProtectedFileAndDirectories(t *testing.T) {
	directory := t.TempDir()
	protectedDirectory := filepath.Join(directory, "runtime")
	if err := os.Mkdir(protectedDirectory, 0o700); err != nil {
		t.Fatal(err)
	}
	path := filepath.Join(protectedDirectory, "node-runtime.json")
	if err := os.WriteFile(path, []byte("{}"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := ValidateProtectedNodeRuntimeFile(path); err != nil {
		t.Fatalf("ValidateProtectedNodeRuntimeFile protected input error = %v", err)
	}

	if err := os.Chmod(protectedDirectory, 0o770); err != nil {
		t.Fatal(err)
	}
	if err := ValidateProtectedNodeRuntimeFile(path); err == nil {
		t.Fatal("trusted runtime under a group-writable directory was accepted")
	}
	if err := os.Chmod(protectedDirectory, 0o700); err != nil {
		t.Fatal(err)
	}

	if err := os.Chmod(path, 0o660); err != nil {
		t.Fatal(err)
	}
	if err := ValidateProtectedNodeRuntimeFile(path); err == nil {
		t.Fatal("group-writable trusted runtime file was accepted")
	}
}
