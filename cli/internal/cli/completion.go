package cli

import (
	"context"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/clip"
)

// completionTimeout bounds the live lookups so that <TAB> never hangs the shell.
const completionTimeout = 2 * time.Second

// completeIDs completes file ids from the server, with the file name as description. If maxArgs is not
// zero, ids are only completed for the first maxArgs arguments (down takes an id and then a path).
func (a *App) completeIDs(maxArgs int) func(*cobra.Command, []string, string) ([]string, cobra.ShellCompDirective) {
	return func(cmd *cobra.Command, args []string, toComplete string) ([]string, cobra.ShellCompDirective) {
		if maxArgs > 0 && len(args) >= maxArgs {
			return nil, cobra.ShellCompDirectiveDefault // e.g. a target path: let the shell complete files
		}
		client, err := a.client()
		if err != nil {
			return nil, cobra.ShellCompDirectiveNoFileComp
		}
		ctx, cancel := context.WithTimeout(cmd.Context(), completionTimeout)
		defer cancel()
		files, err := client.List(ctx)
		if err != nil {
			return nil, cobra.ShellCompDirectiveNoFileComp
		}
		var out []string
		for _, f := range files {
			if strings.HasPrefix(f.ID, toComplete) && !contains(args, f.ID) {
				out = append(out, f.ID+"\t"+f.Name)
			}
		}
		return out, cobra.ShellCompDirectiveNoFileComp
	}
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

func completeFixed(values ...string) func(*cobra.Command, []string, string) ([]string, cobra.ShellCompDirective) {
	return func(*cobra.Command, []string, string) ([]string, cobra.ShellCompDirective) {
		return values, cobra.ShellCompDirectiveNoFileComp
	}
}

// registerCompletions wires up the dynamic completion of ids and flag values.
func (a *App) registerCompletions(root *cobra.Command) {
	for _, c := range root.Commands() {
		switch c.Name() {
		case "rm":
			c.ValidArgsFunction = a.completeIDs(0)
		case "info", "set":
			c.ValidArgsFunction = a.completeIDs(1)
		case "down":
			c.ValidArgsFunction = a.completeIDs(1)
		case "up":
			_ = c.RegisterFlagCompletionFunc("copy", completeFixed(clip.Clipboard, clip.Primary, clip.OSC52))
		}
		if f := c.Flags().Lookup("rm-after"); f != nil {
			_ = c.RegisterFlagCompletionFunc("rm-after", completeFixed("1h", "1d", "1w", "1m", "1y"))
		}
	}
}
