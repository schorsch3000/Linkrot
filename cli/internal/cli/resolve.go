package cli

import (
	"context"
	"fmt"
	"strings"

	"github.com/schorsch3000/Linkrot/internal/api"
)

const (
	idLength  = 16
	minPrefix = 4
)

// resolveID turns the argument into a full file id. A full id is used as is; a shorter one has to be a
// unique prefix (at least 4 characters) of one of the files on the server.
func resolveID(ctx context.Context, c *api.Client, arg string) (string, error) {
	switch {
	case len(arg) == idLength:
		return arg, nil
	case len(arg) < minPrefix:
		return "", usageErrorf("id %q is too short: give at least %d characters", arg, minPrefix)
	case len(arg) > idLength:
		return "", usageErrorf("id %q is too long", arg)
	}
	files, err := c.List(ctx)
	if err != nil {
		return "", err
	}
	var matches []api.File
	for _, f := range files {
		if strings.HasPrefix(f.ID, arg) {
			matches = append(matches, f)
		}
	}
	switch len(matches) {
	case 0:
		return "", fmt.Errorf("no file with an id starting with %q", arg)
	case 1:
		return matches[0].ID, nil
	}
	var b strings.Builder
	for _, m := range matches {
		fmt.Fprintf(&b, "\n  %s  %s", m.ID, m.Name)
	}
	return "", fmt.Errorf("id %q is ambiguous, it matches:%s", arg, b.String())
}
