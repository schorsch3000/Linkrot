package cli

import (
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
)

// maxPageFile is far above what the server accepts (64 KB); it only keeps a wrong file from being read into memory.
const maxPageFile = 1 << 20

var nonSlug = regexp.MustCompile(`[^a-z0-9]+`)

// pageNamePattern is what the server accepts as the name of a page.
var pageNamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?$`)

// slugOf is the name of a page in its URL, made from a file name: imprint.md and Imprint_DE.md become imprint and imprint-de.
func slugOf(path string) string {
	base := strings.TrimSuffix(filepath.Base(path), filepath.Ext(path))
	slug := strings.Trim(nonSlug.ReplaceAllString(strings.ToLower(base), "-"), "-")
	if len(slug) > 40 {
		slug = strings.Trim(slug[:40], "-")
	}
	return slug
}

func (a *App) pageCommand() *cobra.Command {
	cmd := &cobra.Command{
		Use:   "page",
		Short: "Manage the static pages that are linked in the footer of every page",
		Long: "Pages are Markdown files with an optional head between two lines of ---:\n\n" +
			"  title      headline of the page and title of the browser tab\n" +
			"  link       text of the link in the footer (default: the title)\n" +
			"  order      links are sorted by this number, lowest first (default 100), then by text\n" +
			"  published  true (default) or false; a page that is not published is a draft: not reachable, not linked\n" +
			"  lang       language of the page, en by default\n\n" +
			"Raw HTML is refused, images come from https addresses or data: URLs. A page is reachable at /page/<name>, the name\n" +
			"comes from the file name. Start with `page template`.",
		Example: fmt.Sprintf("  %[1]s page template -o imprint.md\n  %[1]s page put imprint.md\n  %[1]s page ls\n  %[1]s page get imprint -o imprint.md\n  %[1]s page rm imprint", a.Name),
		Args:    usageArgs(cobra.NoArgs),
		RunE:    func(cmd *cobra.Command, args []string) error { return noArgs(cmd, args) },
	}
	cmd.AddCommand(a.pageLs(), a.pageGet(), a.pagePut(), a.pageRm(), a.pageTemplate(), a.editCommand("edit <name>", "Edit a page in an editor and upload it whenever it is saved",
		func(name string) (editSpec, error) {
			if !pageNamePattern.MatchString(name) {
				return editSpec{}, usageErrorf("%q is not a page name: 1 to 40 characters of a-z, 0-9 and -, not at the start or end", name)
			}
			return pageSpec(name), nil
		}, a.completePages))
	return cmd
}

func (a *App) pageLs() *cobra.Command {
	var asJSON bool
	cmd := &cobra.Command{
		Use:   "ls",
		Short: "List the pages in the order of the footer",
		Args:  usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				pages, err := c.Pages(ctx)
				if err != nil {
					return err
				}
				if asJSON {
					if pages == nil {
						pages = []api.Page{}
					}
					return a.printJSON(pages)
				}
				var rows [][]cell
				if len(pages) > 0 {
					h := []string{"NAME", "TITLE", "LINK", "ORDER", "PUBLISHED", "LANG", "URL"}
					row := make([]cell, len(h))
					for i, t := range h {
						row[i] = cell{t, color.Bold}
					}
					rows = append(rows, row)
				}
				for _, p := range pages {
					published, style := "yes", color.Style("")
					if !p.Published {
						published, style = "no (draft)", color.Yellow
					}
					rows = append(rows, []cell{
						{p.Slug, color.Yellow},
						{p.Title, ""},
						{p.Link, ""},
						{strconv.Itoa(p.Order), ""},
						{published, style},
						{p.Lang, ""},
						{p.URL, color.Cyan},
					})
				}
				return writeTable(a.Out, a.outPaint(), rows)
			})
		},
	}
	cmd.Flags().BoolVar(&asJSON, "json", false, "print the pages as JSON")
	return cmd
}

func (a *App) pageGet() *cobra.Command {
	var out string
	var html bool
	cmd := &cobra.Command{
		Use:   "get <name>",
		Short: "Print the Markdown of a page exactly as you uploaded it",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				content, err := c.GetPage(ctx, args[0], html)
				if err != nil {
					return err
				}
				return a.writeOrPrint(out, content, args[0])
			})
		},
	}
	cmd.Flags().StringVarP(&out, "output", "o", "", "write to this file instead of the terminal")
	cmd.Flags().BoolVar(&html, "html", false, "print the rendered HTML of the body instead (also works for drafts)")
	return cmd
}

// writeOrPrint writes content to a file if one is given, otherwise to standard output.
func (a *App) writeOrPrint(out string, content []byte, what string) error {
	if out == "" {
		_, err := a.Out.Write(content)
		return err
	}
	if err := os.WriteFile(out, content, 0o600); err != nil {
		return err
	}
	_, err := fmt.Fprintf(a.Out, "%s: wrote %s\n", what, out)
	return err
}

func (a *App) pagePut() *cobra.Command {
	var slug string
	cmd := &cobra.Command{
		Use:   "put <path|->",
		Short: "Upload a Markdown file as a page (replaces the page of the same name)",
		Long: "The name of the page is made from the file name (imprint.md becomes imprint, so the page is /page/imprint). Use --name\n" +
			"to choose it, which is needed when reading standard input (-).\n" +
			"The server checks and renders the file and refuses it with the offending lines if it contains raw HTML, a link or\n" +
			"image that is not allowed, or a mistake in the head. Nothing changes then.",
		Args: usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			var r io.Reader
			if args[0] == "-" {
				if slug == "" {
					return usageErrorf("reading standard input needs --name")
				}
				r = a.stdin()
			} else {
				f, err := os.Open(args[0])
				if err != nil {
					return err
				}
				defer func() { _ = f.Close() }()
				r = f
				if slug == "" {
					slug = slugOf(args[0])
				}
			}
			if slug == "" {
				return usageErrorf("cannot make a page name from %q, use --name", args[0])
			}
			content, err := io.ReadAll(io.LimitReader(r, maxPageFile+1))
			if err != nil {
				return err
			}
			if len(content) > maxPageFile {
				return usageErrorf("%s is larger than %d bytes", args[0], maxPageFile)
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				p, err := c.PutPage(ctx, slug, content)
				if err != nil {
					return err
				}
				state := "published"
				if !p.Published {
					state = "not published (draft)"
				}
				_, err = fmt.Fprintf(a.Out, "%s: %s, link %q, order %d, %s\n", p.Slug, state, p.Link, p.Order, p.URL)
				return err
			})
		},
	}
	cmd.Flags().StringVar(&slug, "name", "", "name of the page in its URL; default: made from the file name")
	return cmd
}

func (a *App) pageRm() *cobra.Command {
	return &cobra.Command{
		Use:   "rm <name>...",
		Short: "Delete pages (the original file is gone too, download it first)",
		Args:  usageArgs(cobra.MinimumNArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				for _, name := range args {
					if err := c.DeletePage(ctx, name); err != nil {
						return fmt.Errorf("%s: %w", name, err)
					}
				}
				return nil
			})
		},
	}
}

func (a *App) pageTemplate() *cobra.Command {
	var out string
	cmd := &cobra.Command{
		Use:   "template",
		Short: "Print a page to start from: every key of the head and some dummy content",
		Long:  "The template is a draft (published: false), so uploading it unchanged does not put the dummy text into the footer.",
		Args:  usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				content, err := c.PageTemplate(ctx)
				if err != nil {
					return err
				}
				return a.writeOrPrint(out, content, "template")
			})
		},
	}
	cmd.Flags().StringVarP(&out, "output", "o", "", "write to this file instead of the terminal")
	return cmd
}
