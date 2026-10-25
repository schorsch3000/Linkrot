package cli

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
)

func (a *App) now() time.Time {
	if a.Now != nil {
		return a.Now()
	}
	return time.Now()
}

func (a *App) printJSON(v any) error {
	enc := json.NewEncoder(a.Out)
	enc.SetIndent("", "  ")
	return enc.Encode(v)
}

// withClient resolves the id argument and runs fn.
func (a *App) withClient(cmd *cobra.Command, fn func(ctx context.Context, c *api.Client) error) error {
	c, err := a.client()
	if err != nil {
		return err
	}
	return fn(cmd.Context(), c)
}

func (a *App) lsCommand() *cobra.Command {
	var asJSON bool
	cmd := &cobra.Command{
		Use:   "ls",
		Short: "List your files and their URLs",
		Args:  usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				files, err := c.List(ctx)
				if err != nil {
					return err
				}
				if asJSON {
					if files == nil {
						files = []api.File{}
					}
					return a.printJSON(files)
				}
				now := a.now()
				pt := a.outPaint()
				var rows [][]cell
				if len(files) > 0 {
					h := []string{"ID", "NAME", "SIZE", "EXPIRES", "USES", "STATUS", "URL"}
					row := make([]cell, len(h))
					for i, t := range h {
						row[i] = cell{t, color.Bold}
					}
					rows = append(rows, row)
				}
				for _, f := range files {
					rows = append(rows, []cell{
						{f.ID, color.Yellow},
						{f.Name, ""},
						{sizeText(f.Size), ""},
						{expiryText(f, now), expiryStyle(f, now)},
						{usesText(f), ""},
						{statusText(f), statusStyle(f)},
						{f.URL, color.Cyan},
					})
				}
				return writeTable(a.Out, pt, rows)
			})
		},
	}
	cmd.Flags().BoolVar(&asJSON, "json", false, "print the files as JSON")
	return cmd
}

func (a *App) infoCommand() *cobra.Command {
	var asJSON bool
	cmd := &cobra.Command{
		Use:   "info <id>",
		Short: "Show one file",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				id, err := resolveID(ctx, c, args[0])
				if err != nil {
					return err
				}
				f, err := c.Get(ctx, id)
				if err != nil {
					return err
				}
				if asJSON {
					return a.printJSON(f)
				}
				a.printInfo(f)
				return nil
			})
		},
	}
	cmd.Flags().BoolVar(&asJSON, "json", false, "print the file as JSON")
	return cmd
}

func (a *App) printInfo(f *api.File) {
	now := a.now()
	rows := [][2]string{
		{"id", f.ID},
		{"name", f.Name},
		{"size", fmt.Sprintf("%s (%d bytes)", sizeText(f.Size), f.Size)},
		{"status", statusText(*f)},
		{"sha256", f.SHA256},
		{"created", time.Unix(f.CreatedAt, 0).UTC().Format("2006-01-02 15:04:05 UTC")},
	}
	if f.ExpiresAt != nil {
		rows = append(rows, [2]string{"expires", fmt.Sprintf("%s (%s)", time.Unix(*f.ExpiresAt, 0).UTC().Format("2006-01-02 15:04:05 UTC"), remaining(*f.ExpiresAt, now))})
	}
	rows = append(rows, [2]string{"uses left", usesLabel(*f)})
	if f.URL != "" {
		rows = append(rows, [2]string{"url", f.URL})
	}
	table := make([][]cell, len(rows))
	for i, r := range rows {
		value := cell{r[1], ""}
		if r[0] == "url" {
			value.style = color.Cyan
		}
		table[i] = []cell{{r[0] + ":", color.Bold}, value}
	}
	_ = writeTable(a.Out, a.outPaint(), table)
}

func usesLabel(f api.File) string {
	if f.UsesLeft == nil {
		return "unlimited"
	}
	return fmt.Sprint(*f.UsesLeft)
}

func (a *App) setCommand() *cobra.Command {
	var (
		rmAfter, name string
		uses          int
		asJSON        bool
	)
	cmd := &cobra.Command{
		Use:   "set <id> [flags]",
		Short: "Change the settings of an uploaded file",
		Long: "Changes settings of an uploaded file and prints its (possibly new) URL.\n" +
			"--rm-after counts from now. --uses sets the number of remaining downloads; 0 means unlimited.",
		Example: fmt.Sprintf("  %[1]s set abcd --rm-after 1w\n  %[1]s set abcd --mv better-name.pdf --uses 1", a.Name),
		Args:    usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			flags := cmd.Flags()
			if !changed(flags, "rm-after", "mv", "uses") {
				return usageErrorf("nothing to change: give --rm-after, --mv and/or --uses")
			}
			var p api.Patch
			if flags.Changed("rm-after") {
				if err := validateDuration(rmAfter); err != nil {
					return err
				}
				p.RmAfter = &rmAfter
			}
			if flags.Changed("mv") {
				if name == "" {
					return usageErrorf("--mv needs a name")
				}
				p.Name = &name
			}
			if flags.Changed("uses") {
				switch {
				case uses < 0:
					return usageErrorf("--uses must not be negative")
				case uses == 0:
					p.ClearUses = true
				default:
					p.Uses = &uses
				}
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				id, err := resolveID(ctx, c, args[0])
				if err != nil {
					return err
				}
				f, err := c.Update(ctx, id, p)
				if err != nil {
					return err
				}
				if asJSON {
					return a.printJSON(f)
				}
				if f.URL == "" {
					return nil // still uploading, there is no URL yet
				}
				_, err = fmt.Fprintln(a.Out, f.URL)
				return err
			})
		},
	}
	f := cmd.Flags()
	f.StringVar(&rmAfter, "rm-after", "", "delete the file after this time, counted from now (1h, 2d, 3w, 4m, 5y)")
	f.StringVar(&name, "mv", "", "rename the file")
	f.IntVar(&uses, "uses", 0, "remaining downloads (0 = unlimited)")
	f.BoolVar(&asJSON, "json", false, "print the file object as JSON")
	return cmd
}

func (a *App) rmCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "rm <id>...",
		Short: "Delete files",
		Args:  usageArgs(cobra.MinimumNArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				var errs []error
				for _, arg := range args {
					id, err := resolveID(ctx, c, arg)
					if err == nil {
						err = c.Delete(ctx, id)
					}
					if err != nil {
						errs = append(errs, fmt.Errorf("%s: %w", arg, err))
					}
				}
				return errors.Join(errs...)
			})
		},
	}
}

func (a *App) cleanupCommand() *cobra.Command {
	return &cobra.Command{
		Use:   "cleanup",
		Short: "Ask the server to run its cleanup now",
		Args:  usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				r, err := c.Cleanup(ctx)
				if err != nil {
					return err
				}
				if !r.Ran {
					_, err = fmt.Fprintln(a.Out, "another cleanup is running, nothing done")
					return err
				}
				_, err = fmt.Fprintf(a.Out, "removed %d database entries and %d files\n", r.RowsDeleted, r.FilesDeleted)
				return err
			})
		},
	}
}
