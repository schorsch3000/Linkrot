package cli

import (
	"context"
	"errors"
	"fmt"
	"net/http"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
)

// preflight asks the server for its health before a command does anything. Warnings are printed on stderr in
// yellow and the command goes on; an error is printed in red and stops it, there is no working server to talk to.
// A server without a health endpoint (404) is not an error.
func (a *App) preflight(ctx context.Context, c *api.Client) error {
	h, err := c.Health(ctx)
	if err != nil {
		var ae *api.Error
		if errors.As(err, &ae) && ae.Status == http.StatusNotFound {
			return nil
		}
		return err
	}
	p := a.errPaint()
	failed := false
	for _, check := range h.Checks {
		line := fmt.Sprintf("%s: server %s: %s", a.Name, check.Name, check.Message)
		switch check.Level {
		case api.LevelWarn:
			_, _ = fmt.Fprintln(a.Err, p.Paint(color.Yellow, a.Name+": warning: server "+check.Name+": "+check.Message))
		case api.LevelError:
			failed = true
			_, _ = fmt.Fprintln(a.Err, p.Paint(color.Red, line))
		}
	}
	if failed {
		return errors.New("the server reports a problem, not continuing")
	}
	return nil
}
