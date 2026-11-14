package cli

import (
	"context"
	"fmt"
	"io"
	"os"
	"strings"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// maxCustomFile is far above what the server accepts (64 KB); it only keeps a wrong file from being read into memory.
const maxCustomFile = 1 << 20

func customName(arg string) (string, error) {
	for _, known := range api.CustomNames {
		if arg == known {
			return known, nil
		}
	}
	return "", usageErrorf("unknown file %q, known: %s", arg, strings.Join(api.CustomNames, ", "))
}

func (a *App) customCommand() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "custom",
		Short: "Download, replace and reset the files that customize your server",
		Long: "Files of your server that you can replace with your own:\n\n" +
			"  taglines     one footer tagline per line, shown on the pages instead of the built-in ones\n" +
			"  headerlines  one line per X-Rot header of a download, printable ASCII only\n\n" +
			"get prints the file (the built-in lines as long as you did not replace it), put replaces it and reset goes back\n" +
			"to the built-in lines.",
		Example: fmt.Sprintf("  %[1]s custom get taglines -o taglines.txt\n  %[1]s custom put taglines taglines.txt\n  %[1]s custom reset taglines", a.Name),
		Args:    usageArgs(cobra.NoArgs),
		RunE:    func(cmd *cobra.Command, args []string) error { return noArgs(cmd, args) },
	}
	cmd.AddCommand(a.customGet(), a.customPut(), a.customReset())
	return cmd
}

func (a *App) customGet() *cobra.Command {
	var out string
	cmd := &cobra.Command{
		Use:   "get <file>",
		Short: "Print a file, the built-in lines if you did not replace it",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			name, err := customName(args[0])
			if err != nil {
				return err
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				content, customized, err := c.GetCustom(ctx, name)
				if err != nil {
					return err
				}
				if out == "" {
					_, err = a.Out.Write(content)
					return err
				}
				if err := os.WriteFile(out, content, 0o600); err != nil {
					return err
				}
				what := "the built-in lines"
				if customized {
					what = "your file"
				}
				_, err = fmt.Fprintf(a.Out, "%s: wrote %s to %s\n", name, what, out)
				return err
			})
		},
	}
	cmd.Flags().StringVarP(&out, "output", "o", "", "write to this file instead of the terminal")
	return cmd
}

func (a *App) customPut() *cobra.Command {
	return &cobra.Command{
		Use:   "put <file> <path|->",
		Short: "Replace a file with the content of a file (- reads standard input)",
		Args:  usageArgs(cobra.ExactArgs(2)),
		RunE: func(cmd *cobra.Command, args []string) error {
			name, err := customName(args[0])
			if err != nil {
				return err
			}
			var r io.Reader
			if args[1] == "-" {
				r = a.stdin()
			} else {
				f, err := os.Open(args[1])
				if err != nil {
					return err
				}
				defer func() { _ = f.Close() }()
				r = f
			}
			content, err := io.ReadAll(io.LimitReader(r, maxCustomFile+1))
			if err != nil {
				return err
			}
			if len(content) > maxCustomFile {
				return usageErrorf("%s is larger than %d bytes", args[1], maxCustomFile)
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				lines, err := c.PutCustom(ctx, name, content)
				if err != nil {
					return err
				}
				_, err = fmt.Fprintf(a.Out, "%s: %d lines in use\n", name, lines)
				return err
			})
		},
	}
}

func (a *App) customReset() *cobra.Command {
	return &cobra.Command{
		Use:   "reset <file>",
		Short: "Go back to the built-in lines",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			name, err := customName(args[0])
			if err != nil {
				return err
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				if err := c.ResetCustom(ctx, name); err != nil {
					return err
				}
				_, err := fmt.Fprintf(a.Out, "%s: back to the built-in lines\n", name)
				return err
			})
		},
	}
}
