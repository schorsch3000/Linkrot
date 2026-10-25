package cli

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/clip"
	"github.com/schorsch3000/Linkrot/internal/color"
	"github.com/schorsch3000/Linkrot/internal/progress"
	"github.com/schorsch3000/Linkrot/internal/upload"
)

var durationRe = regexp.MustCompile(`^[1-9][0-9]{0,5}[hdwmy]$`)

// validateDuration checks the format of --rm-after (1h, 2d, 3w, 4m, 5y); the server checks the cap.
func validateDuration(s string) error {
	if !durationRe.MatchString(s) {
		return usageErrorf("invalid --rm-after %q: use <n>h, <n>d, <n>w, <n>m (month) or <n>y, e.g. 2d", s)
	}
	return nil
}

func (a *App) upCommand() *cobra.Command {
	var (
		rmAfter, name, copySel string
		uses                   int
		asJSON                 bool
	)
	cmd := &cobra.Command{
		Use:   "up <file> [flags]",
		Short: "Upload a file and print its URL",
		Long: "Uploads a file in resumable chunks and prints the share URL on stdout.\n" +
			"Progress goes to stderr. If the same file already exists on the server, its URL is printed and\n" +
			"the exit code is 3.",
		Example: fmt.Sprintf("  %[1]s up report.pdf --rm-after 2d\n  %[1]s up big.iso --uses 1 --copy", a.Name),
		Args:    usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			flags := cmd.Flags()
			opts := upload.Options{Name: name}
			if flags.Changed("rm-after") {
				if err := validateDuration(rmAfter); err != nil {
					return err
				}
				opts.RmAfter = rmAfter
			}
			if flags.Changed("uses") {
				if uses < 1 {
					return usageErrorf("--uses must be at least 1")
				}
				opts.Uses = &uses
			}
			if flags.Changed("mv") && name == "" {
				return usageErrorf("--mv needs a name")
			}
			if flags.Changed("copy") && !clip.Valid(copySel) {
				return usageErrorf("invalid --copy value %q: use --copy, --copy=clipboard or --copy=primary", copySel)
			}
			client, err := a.client()
			if err != nil {
				return err
			}
			return a.runUp(cmd.Context(), client, args[0], opts, flags.Changed("copy"), copySel, asJSON)
		},
	}
	f := cmd.Flags()
	f.StringVar(&rmAfter, "rm-after", "", "delete the file after this time, counted from the end of the upload (1h, 2d, 3w, 4m, 5y)")
	f.StringVar(&name, "mv", "", "name on the server (default: base name of the file)")
	f.IntVar(&uses, "uses", 0, "allow only this many downloads")
	f.StringVar(&copySel, "copy", "", "copy the URL: OSC 52 by default, or =clipboard / =primary")
	f.Lookup("copy").NoOptDefVal = clip.OSC52
	f.BoolVar(&asJSON, "json", false, "print the file object as JSON")
	return cmd
}

func (a *App) runUp(ctx context.Context, client *api.Client, path string, opts upload.Options, doCopy bool, copySel string, asJSON bool) error {
	var bar *progress.Bar
	finish := func() {
		if bar != nil {
			bar.Finish()
		}
	}
	opts.OnHash = func(done, total int64) {
		if bar == nil {
			bar = progress.New(a.Err, "hashing  ", total)
		}
		bar.Set(done)
	}
	opts.OnProgress = func(done, total int64) {
		if bar == nil || !a.uploading {
			finish()
			bar, a.uploading = progress.New(a.Err, "uploading", total), true
		}
		bar.Set(done)
	}
	opts.OnRetry = func(err error, wait time.Duration) {
		finish()
		_, _ = fmt.Fprintf(a.Err, "%s %v; retrying in %s\n", a.errPaint().Paint(color.Yellow, a.Name+":"), err, wait)
	}
	opts.Sleep = a.Sleep

	file, err := upload.Upload(ctx, client, path, opts)
	finish()

	var dup *upload.DuplicateError
	switch {
	case errors.As(err, &dup):
		if perr := a.printResult(dup.URL, dup.ID, true, nil, asJSON); perr != nil {
			return perr
		}
		a.copyURL(doCopy, copySel, dup.URL)
		return withCode(ExitDuplicate, fmt.Errorf("this file already exists on the server (%s), its URL was printed", dup.ID))
	case errors.Is(err, context.Canceled):
		return fmt.Errorf("interrupted; run the same command again to resume")
	case err != nil:
		return err
	}
	if err := a.printResult(file.URL, file.ID, false, file, asJSON); err != nil {
		return err
	}
	a.copyURL(doCopy, copySel, file.URL)
	return nil
}

func (a *App) printResult(url, id string, duplicate bool, file *api.File, asJSON bool) error {
	if !asJSON {
		_, err := fmt.Fprintln(a.Out, url)
		return err
	}
	var v any = file
	if duplicate {
		v = map[string]any{"id": id, "url": url, "duplicate": true}
	}
	enc := json.NewEncoder(a.Out)
	enc.SetIndent("", "  ")
	return enc.Encode(v)
}

func (a *App) copyURL(doCopy bool, selection, url string) {
	if !doCopy {
		return
	}
	if err := a.Copy(selection, url); err != nil {
		_, _ = fmt.Fprintf(a.Err, "%s %v\n", a.errPaint().Paint(color.Yellow, a.Name+": warning:"), fmt.Sprintf("could not copy the URL: %v", err))
	}
}
