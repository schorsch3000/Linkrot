package cli

import (
	"context"
	"strings"
	"time"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"

	"github.com/schorsch3000/Linkrot/internal/clip"
)

// completionTimeout bounds the live lookups so that <TAB> never hangs the shell.
const completionTimeout = 2 * time.Second

// completeIDs completes file ids from the server, with the file name as description. If maxArgs is not
// zero, ids are only completed for the first maxArgs arguments (down takes an id and then a path).
func (a *App) completeIDs(maxArgs int) func(*cobra.Command, []string, string) ([]string, cobra.ShellCompDirective) {
	return func(cmd *cobra.Command, args []string, toComplete string) ([]string, cobra.ShellCompDirective) {
		if maxArgs > 0 && len(args) >= maxArgs {
			if cmd.Name() == "down" {
				return nil, cobra.ShellCompDirectiveDefault // the target path: let the shell complete files
			}
			return completeFlags(cmd, toComplete)
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

// completeFlags offers the flags of a command that has no positional arguments to complete, so that
// `lr config <TAB>` suggests --server and --token (cobra itself only lists flags after a `-` was typed).
// Flags that were already given are left out.
func completeFlags(cmd *cobra.Command, toComplete string) ([]string, cobra.ShellCompDirective) {
	var out []string
	cmd.Flags().VisitAll(func(f *pflag.Flag) {
		name := "--" + f.Name
		if f.Name != "help" && !f.Hidden && !f.Changed && strings.HasPrefix(name, toComplete) {
			out = append(out, name+"\t"+f.Usage)
		}
	})
	return out, cobra.ShellCompDirectiveNoFileComp
}

func onlyFlags(cmd *cobra.Command, _ []string, toComplete string) ([]string, cobra.ShellCompDirective) {
	return completeFlags(cmd, toComplete)
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
			c.ValidArgsFunction = func(cmd *cobra.Command, args []string, toComplete string) ([]string, cobra.ShellCompDirective) {
				if len(args) == 0 {
					return nil, cobra.ShellCompDirectiveDefault // the file to upload
				}
				return completeFlags(cmd, toComplete)
			}
			_ = c.RegisterFlagCompletionFunc("copy", completeFixed(clip.Clipboard, clip.Primary, clip.OSC52))
		case "config", "ls", "cleanup":
			c.ValidArgsFunction = onlyFlags
		}
		if f := c.Flags().Lookup("rm-after"); f != nil {
			_ = c.RegisterFlagCompletionFunc("rm-after", completeFixed("1h", "1d", "1w", "1m", "1y"))
		}
	}
}
