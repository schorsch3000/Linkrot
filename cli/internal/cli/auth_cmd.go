package cli

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"regexp"
	"strings"
	"time"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
	"github.com/schorsch3000/Linkrot/internal/color"
	"github.com/schorsch3000/Linkrot/internal/config"
)

var tokenName = regexp.MustCompile(`^[a-z-]{1,32}$`)

// defaultTokenName names the token after this machine: what the server accepts is a-z and -, at most 32 characters.
func defaultTokenName() string {
	host, _ := os.Hostname()
	name := strings.Trim(regexp.MustCompile(`[^a-z]+`).ReplaceAllString(strings.ToLower(host), "-"), "-")
	if len(name) > 32 {
		name = strings.Trim(name[:32], "-")
	}
	if name == "" {
		return "lr"
	}
	return name
}

func looksLikeEmail(s string) bool {
	at := strings.LastIndex(s, "@")
	return at > 0 && at < len(s)-1 && !strings.ContainsAny(s, " \t\r\n,<>")
}

// readLine asks for one line on the terminal or, if stdin is not one, takes the next line of it.
func (a *App) readLine(prompt string) (string, error) {
	if a.ReadLine != nil {
		return a.ReadLine(prompt)
	}
	_, _ = fmt.Fprint(a.Err, prompt)
	if a.lines == nil {
		a.lines = bufio.NewReader(a.stdin()) // one reader for the whole run: a second one would lose what the first buffered
	}
	line, err := a.lines.ReadString('\n')
	if err != nil && (!errors.Is(err, io.EOF) || line == "") {
		return "", err
	}
	return strings.TrimSpace(line), nil
}

// session proves that the user reads the mailbox of the host: a code is mailed, typed in here, and traded for a
// management session. It is short-lived, kept in memory only, and every call needs a fresh code.
func (a *App) session(ctx context.Context, server, email string) (*api.Client, error) {
	anonymous := api.NewClient(server, "")
	a.say("Asking %s for a code (this takes a few seconds)...\n", server)
	challenge, err := anonymous.AuthStart(ctx, email)
	if err != nil {
		return nil, err
	}
	a.say("If %s is the address of this host, a code is on its way. Mail can take a minute.\n", email)
	code, err := a.readLine("Code: ")
	if err != nil {
		return nil, fmt.Errorf("read the code: %w", err)
	}
	if code == "" {
		return nil, usageErrorf("no code given")
	}
	session, err := anonymous.AuthVerify(ctx, challenge.Challenge, code)
	var apiErr *api.Error
	if errors.As(err, &apiErr) && apiErr.Code == api.CodeInvalidCode {
		return nil, fmt.Errorf("that code is wrong or expired; run the command again for a new one (%w)", err)
	}
	if err != nil {
		return nil, err
	}
	return api.NewClient(server, session.Session), nil
}

// say prints a progress line on stderr unless -q.
func (a *App) say(format string, args ...any) {
	if !a.Quiet {
		_, _ = fmt.Fprintf(a.Err, format, args...)
	}
}

// mailTarget is the server and the address a mailed code goes to: the flags, else what the config file remembers.
func mailTarget(c config.Config, server, email string) (string, string, error) {
	if server == "" {
		server = c.Server
	}
	if server == "" {
		return "", "", usageErrorf("no server: pass --server https://host or run `config --server <url>`")
	}
	server, err := config.NormalizeServer(server)
	if err != nil {
		return "", "", usageErrorf("%v", err)
	}
	if email == "" {
		email = c.Email
	}
	if !looksLikeEmail(email) {
		return "", "", usageErrorf("--email <address> is required: the address that is configured for the host on the server")
	}
	return server, strings.ToLower(email), nil
}

func (a *App) authCommand() *cobra.Command {
	var server, email, name string
	var allowUnsafe bool
	cmd := &cobra.Command{
		Use:   "auth --email <address> [--server <url>] [--name <name>]",
		Short: "Get your own token: a code is mailed to the address of the host",
		Long: "Asks the server to mail a code to the address that is configured for the host, asks you for that code, creates a token " +
			"and saves it (together with the server and the address) in the config file. The token is as good as any other.\n\n" +
			"The server answers the same for every address, so a wrong address looks like a mail that does not arrive.",
		Example: fmt.Sprintf("  %[1]s auth --server https://files.example.com --email me@example.com\n  %[1]s auth --email me@example.com --name work-laptop", a.Name),
		Args:    usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := a.ConfigPath()
			if err != nil {
				return err
			}
			c, err := config.Load(path)
			if err != nil {
				return err
			}
			a.warnLooseConfig(path)
			if server, email, err = mailTarget(c, server, email); err != nil {
				return err
			}
			if name == "" {
				name = defaultTokenName()
			}
			if !tokenName.MatchString(name) {
				return usageErrorf("token name %q: use 1-32 characters of a-z and -", name)
			}
			session, err := a.session(cmd.Context(), server, email)
			if err != nil {
				return err
			}
			token, err := session.CreateToken(cmd.Context(), name)
			var apiErr *api.Error
			if errors.As(err, &apiErr) && apiErr.Code == api.CodeDuplicate {
				return fmt.Errorf("%w: pick another name with --name, or revoke it with `%s token rm %s`", err, a.Name, name)
			}
			if err != nil {
				return err
			}
			if err := saveConfig(path, config.Config{Server: server, Token: token, Email: email}, allowUnsafe); err != nil {
				return fmt.Errorf("the token %q was created but not saved (%w); it is shown only once: %s", name, err, token)
			}
			a.say("Created the token %q for %s and saved it in %s\n", name, server, path)
			return nil
		},
	}
	cmd.Flags().StringVar(&server, "server", "", "server URL (default: the one in the config file)")
	cmd.Flags().StringVar(&email, "email", "", "the address of the host, where the code goes (default: the one in the config file)")
	cmd.Flags().StringVar(&name, "name", "", "name of the token: a-z and - (default: this machine's name)")
	cmd.Flags().BoolVar(&allowUnsafe, "allow-unsafe-file", false, "write the token even into a config file (behind a symlink) that others can read")
	return cmd
}

// Token operations, shared by the one-shot commands and the interactive session.

func (a *App) listTokens(ctx context.Context, c *api.Client, asJSON bool) error {
	tokens, err := c.Tokens(ctx)
	if err != nil {
		return err
	}
	if asJSON {
		if tokens == nil {
			tokens = []api.Token{}
		}
		return a.printJSON(tokens)
	}
	for _, t := range tokens {
		used := "never"
		if t.LastUsedAt != nil {
			used = time.Unix(*t.LastUsedAt, 0).Format("2006-01-02 15:04")
		}
		if _, err := fmt.Fprintf(a.Out, "%s\t%s\n", t.Name, used); err != nil {
			return err
		}
	}
	return nil
}

func (a *App) newToken(ctx context.Context, c *api.Client, name string) error {
	if !tokenName.MatchString(name) {
		return usageErrorf("token name %q: use 1-32 characters of a-z and -", name)
	}
	token, err := c.CreateToken(ctx, name)
	if err != nil {
		return err
	}
	_, err = fmt.Fprintln(a.Out, token)
	return err
}

func (a *App) rmToken(ctx context.Context, c *api.Client, name string) error {
	if err := c.DeleteToken(ctx, name); err != nil {
		return err
	}
	a.say("Revoked the token %q\n", name)
	return nil
}

const tokenShellHelp = "ls              list the tokens\nnew <name>      create a token and print it (shown only once)\nrm <name>       revoke a token\nquit            end the session (Ctrl+D does too)\n"

// tokenShell is one session for several operations: a single code, a prompt, until the session runs out or the user quits.
// Errors of an operation are shown and the session goes on; an ended session ends it.
func (a *App) tokenShell(ctx context.Context, c *api.Client) error {
	a.say("The session is open for %d minutes. Type help for the commands.\n", api.SessionMinutes)
	for {
		line, err := a.readLine("token> ")
		if errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil {
			return err
		}
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		var opErr error
		switch {
		case fields[0] == "quit" || fields[0] == "exit" || fields[0] == "q":
			return nil
		case fields[0] == "help" || fields[0] == "?":
			_, _ = fmt.Fprint(a.Err, tokenShellHelp)
		case fields[0] == "ls" && len(fields) == 1:
			opErr = a.listTokens(ctx, c, false)
		case fields[0] == "new" && len(fields) == 2:
			opErr = a.newToken(ctx, c, fields[1])
		case fields[0] == "rm" && len(fields) == 2:
			opErr = a.rmToken(ctx, c, fields[1])
		default:
			_, _ = fmt.Fprintf(a.Err, "unknown command %q, type help\n", line)
		}
		var apiErr *api.Error
		if errors.As(opErr, &apiErr) && apiErr.Status == http.StatusUnauthorized {
			return fmt.Errorf("the session has ended, run the command again for a new code (%w)", opErr)
		}
		if opErr != nil {
			_, _ = fmt.Fprintf(a.Err, "%s %v\n", a.errPaint().Paint(color.Red, a.Name+":"), opErr)
		}
	}
}

func (a *App) tokenCommand() *cobra.Command {
	var server, email string
	var asJSON bool
	cmd := &cobra.Command{
		Use:   "token [ls|new|rm]",
		Short: "List, create and revoke the tokens of the host, with a code that is mailed to the address of the host",
		Long: "Manages the API tokens of the host. A code is mailed to the address of the host and asked for; that proves that you read " +
			"its mailbox.\n\nWithout a subcommand the code opens a session of 10 minutes with a prompt (ls, new <name>, rm <name>, quit): " +
			"one code for as many operations as you need. The subcommands ls, new and rm do one operation and need a fresh code each time. " +
			"The session is kept in memory only, never on disk.",
		Example: fmt.Sprintf("  %[1]s token\n  %[1]s token ls\n  %[1]s token new work-laptop\n  %[1]s token rm work-laptop", a.Name),
		Args:    cobra.ArbitraryArgs,
		RunE: func(cmd *cobra.Command, args []string) error {
			if len(args) > 0 {
				return noArgs(cmd, args)
			}
			return a.managed(cmd, server, email, a.tokenShell)
		},
	}
	ls := &cobra.Command{
		Use:   "ls",
		Short: "List the tokens: name and when each was last used",
		Args:  usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.managed(cmd, server, email, func(ctx context.Context, c *api.Client) error { return a.listTokens(ctx, c, asJSON) })
		},
	}
	ls.Flags().BoolVar(&asJSON, "json", false, "print JSON")
	newCmd := &cobra.Command{
		Use:   "new <name>",
		Short: "Create a token and print it (shown only once)",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			if !tokenName.MatchString(args[0]) { // before a mail is sent
				return usageErrorf("token name %q: use 1-32 characters of a-z and -", args[0])
			}
			return a.managed(cmd, server, email, func(ctx context.Context, c *api.Client) error { return a.newToken(ctx, c, args[0]) })
		},
	}
	rm := &cobra.Command{
		Use:   "rm <name>",
		Short: "Revoke a token",
		Args:  usageArgs(cobra.ExactArgs(1)),
		RunE: func(cmd *cobra.Command, args []string) error {
			return a.managed(cmd, server, email, func(ctx context.Context, c *api.Client) error { return a.rmToken(ctx, c, args[0]) })
		},
	}
	cmd.PersistentFlags().StringVar(&server, "server", "", "server URL (default: the one in the config file)")
	cmd.PersistentFlags().StringVar(&email, "email", "", "the address of the host, where the code goes (default: the one in the config file)")
	cmd.AddCommand(ls, newCmd, rm)
	return cmd
}

// managed gets a management session (one mailed code) and runs fn with it.
func (a *App) managed(cmd *cobra.Command, server, email string, fn func(ctx context.Context, c *api.Client) error) error {
	path, err := a.ConfigPath()
	if err != nil {
		return err
	}
	c, err := config.Load(path)
	if err != nil {
		return err
	}
	a.warnLooseConfig(path)
	s, e, err := mailTarget(c, server, email)
	if err != nil {
		return err
	}
	session, err := a.session(cmd.Context(), s, e)
	if err != nil {
		return err
	}
	return fn(cmd.Context(), session)
}
