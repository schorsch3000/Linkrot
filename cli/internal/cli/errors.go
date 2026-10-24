package cli

import (
	"errors"
	"fmt"
)

// Exit codes, see docs/CLI.md.
const (
	ExitOK        = 0
	ExitError     = 1
	ExitUsage     = 2
	ExitDuplicate = 3
)

// ExitError carries a specific exit code.
type exitError struct {
	code int
	err  error
}

func (e *exitError) Error() string { return e.err.Error() }
func (e *exitError) Unwrap() error { return e.err }

// withCode wraps err so that the program exits with code.
func withCode(code int, err error) error {
	if err == nil {
		return nil
	}
	return &exitError{code: code, err: err}
}

// usageErrorf creates an error that leads to exit code 2.
func usageErrorf(format string, args ...any) error {
	return withCode(ExitUsage, fmt.Errorf(format, args...))
}

// exitCode maps an error to the process exit code.
func exitCode(err error) int {
	if err == nil {
		return ExitOK
	}
	var e *exitError
	if errors.As(err, &e) {
		return e.code
	}
	return ExitError
}
