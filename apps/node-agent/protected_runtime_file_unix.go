//go:build !windows

package agent

import (
	"os"
	"path/filepath"
	"syscall"
)

// ValidateProtectedNodeRuntimeFile verifies that a trusted runtime or public
// TLS input is a regular file owned by root or the current service user, and
// that its directory ancestry cannot be modified by an untrusted user.
func ValidateProtectedNodeRuntimeFile(path string) error {
	fileInfo, err := os.Lstat(path)
	if err != nil || !fileInfo.Mode().IsRegular() || fileInfo.Mode()&os.ModeSymlink != 0 ||
		fileInfo.Mode().Perm()&0o022 != 0 || !ownedByRootOrCurrentUser(fileInfo) {
		return ErrInvalidNodeRuntimeConfig
	}

	resolvedDirectory, err := filepath.EvalSymlinks(filepath.Dir(path))
	if err != nil {
		return ErrInvalidNodeRuntimeConfig
	}
	directory := filepath.Clean(resolvedDirectory)
	for {
		directoryInfo, err := os.Lstat(directory)
		if err != nil || !directoryInfo.IsDir() || directoryInfo.Mode()&os.ModeSymlink != 0 ||
			!ownedByRootOrCurrentUser(directoryInfo) {
			return ErrInvalidNodeRuntimeConfig
		}
		writableByOthers := directoryInfo.Mode().Perm()&0o022 != 0
		stat, hasUnixOwner := directoryInfo.Sys().(*syscall.Stat_t)
		protectedStickyDirectory := hasUnixOwner && stat.Uid == 0 && directoryInfo.Mode()&os.ModeSticky != 0
		if writableByOthers && !protectedStickyDirectory {
			return ErrInvalidNodeRuntimeConfig
		}
		parent := filepath.Dir(directory)
		if parent == directory {
			break
		}
		directory = parent
	}

	return nil
}

func ownedByRootOrCurrentUser(info os.FileInfo) bool {
	stat, ok := info.Sys().(*syscall.Stat_t)
	return ok && (stat.Uid == 0 || stat.Uid == uint32(os.Geteuid()))
}
