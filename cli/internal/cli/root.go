// Package cli implements the command line interface.
package cli

import (
	"fmt"
	"io"
	"strings"

	"github.com/spf13/cobra"
	"github.com/spf13/pflag"

	"github.com/schorsch3000/Linkrot/internal/config"
)

// App holds what commands need from the environment, so tests can replace it.
type App struct {
	Name       string // program name, derived from argv[0]
	Out        io.Writer
	Err        io.Writer
	ConfigPath func() (string, error)
}

// Execute runs the program and returns the exit code. name is the basename of argv[0].
func Execute(name string, args []string, stdout, stderr io.Writer) int {
	app := &App{Name: name, Out: stdout, Err: stderr, ConfigPath: config.Path}
	root := app.NewRoot()
	root.SetArgs(args)
	if err := root.Execute(); err != nil {
		_, _ = fmt.Fprintf(stderr, "%s: %v\n", name, err)
		return exitCode(err)
	}
	return ExitOK
}

// NewRoot builds the command tree. The command name is whatever the program was called as.
func (a *App) NewRoot() *cobra.Command {
	root := &cobra.Command{
		Use:           a.Name,
		Short:         "Share files with links that rot",
		Long:          fmt.Sprintf("%s uploads files to a Linkrot server and prints a link that stops working after a while.", a.Name),
		SilenceUsage:  true,
		SilenceErrors: true,
		Args:          cobra.ArbitraryArgs,
		RunE:          func(cmd *cobra.Command, args []string) error { return noArgs(cmd, args) },
	}
	root.SetOut(a.Out)
	root.SetErr(a.Err)
	root.SetFlagErrorFunc(func(_ *cobra.Command, err error) error { return withCode(ExitUsage, err) })
	root.CompletionOptions.DisableDefaultCmd = false

	root.AddCommand(a.configCommand())
	return root
}

// usageArgs turns argument validation errors of cobra into usage errors (exit code 2).
func usageArgs(validate cobra.PositionalArgs) cobra.PositionalArgs {
	return func(cmd *cobra.Command, args []string) error {
		if err := validate(cmd, args); err != nil {
			return withCode(ExitUsage, err)
		}
		return nil
	}
}

func noArgs(cmd *cobra.Command, args []string) error {
	if len(args) == 0 {
		return cmd.Help()
	}
	return usageErrorf("unknown command %q for %q", strings.Join(args, " "), cmd.CommandPath())
}

func changed(fs *pflag.FlagSet, names ...string) bool {
	for _, n := range names {
		if fs.Changed(n) {
			return true
		}
	}
	return false
}
