//go:build windows

package agent

// Production runtime file ownership is not implemented for Windows. The
// production Node Agent therefore fails closed on this platform.
func ValidateProtectedNodeRuntimeFile(string) error {
	return ErrInvalidNodeRuntimeConfig
}
