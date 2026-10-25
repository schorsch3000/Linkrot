package cli

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
	"github.com/schorsch3000/Linkrot/internal/download"
	"github.com/schorsch3000/Linkrot/internal/progress"
)

func (a *App) downCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "down <id> [target]",
		Short: "Download a file",
		Long: "Downloads a file and prints the path it was written to.\n\n" +
			"Without a target the file goes to the current directory under its original name. If the target is an\n" +
			"existing directory the file goes into it, otherwise the target is the file name. Existing files are\n" +
			"never overwritten and directories are never created. An interrupted download is resumed when the\n" +
			"command is run again (the data is kept in <name>.part until it is verified).",
		Example: fmt.Sprintf("  %[1]s down abcd\n  %[1]s down abcd ~/Downloads\n  %[1]s down abcd copy.pdf", a.Name),
		Args:    usageArgs(cobra.RangeArgs(1, 2)),
		RunE: func(cmd *cobra.Command, args []string) error {
			target := ""
			if len(args) == 2 {
				target = args[1]
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				id, err := resolveID(ctx, c, args[0])
				if err != nil {
					return err
				}
				var bar *progress.Bar
				finish := func() {
					if bar != nil {
						bar.Finish()
					}
				}
				path, err := download.Download(ctx, c, id, target, download.Options{
					OnProgress: func(done, total int64) {
						if bar == nil {
							bar = progress.New(a.Err, "downloading", total)
						}
						bar.Set(done)
					},
					OnRetry: func(err error, wait time.Duration) {
						finish()
						_, _ = fmt.Fprintf(a.Err, "%s %v; retrying in %s\n", a.errPaint().Paint(color.Yellow, a.Name+":"), err, wait)
					},
					Sleep: a.Sleep,
				})
				finish()
				if errors.Is(err, context.Canceled) {
					return errors.New("interrupted; run the same command again to resume")
				}
				if err != nil {
					return err
				}
				_, err = fmt.Fprintln(a.Out, path)
				return err
			})
		},
	}
}
