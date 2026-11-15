package cli

import (
	"bufio"
	"errors"
	"fmt"
	"io"
	"os"
	"slices"
	"strings"

	"github.com/spf13/cobra"
	"golang.org/x/term"

	"github.com/schorsch3000/Linkrot/internal/config"
)

// promptToken is what --token holds when it has no value: ask on the terminal.
const promptToken = "\x00prompt"

func (a *App) configCommand() *cobra.Command {
	var server, token string
	var allowUnsafe bool
	cmd := &cobra.Command{
		Use:   "config",
		Short: "Show or set the server URL and token; get and set change the settings of the server",
		Example: fmt.Sprintf("  %[1]s config --server https://linkrot.example\n  %[1]s config --token <token>\n  %[1]s config --token\n  %[1]s config --token - < token-file\n  %[1]s config\n  %[1]s config get\n  %[1]s config set taglines off",
			a.Name),
		// --token may stand without a value, so a word after it is either its value or a stray argument; RunE sorts that out.
		Args: func(*cobra.Command, []string) error { return nil },
		RunE: func(cmd *cobra.Command, args []string) error {
			path, err := a.ConfigPath()
			if err != nil {
				return err
			}
			c, err := config.Load(path)
			if err != nil {
				return err
			}
			a.warnLooseConfig(path)
			if cmd.Flags().Changed("token") && token == promptToken && len(args) > 0 && a.followsToken(args[0]) {
				token, args = args[0], args[1:] // "--token <value>": the flag took no value, the word after it is the value
			}
			if len(args) > 0 {
				return usageErrorf("unknown command %q for %q", strings.Join(args, " "), cmd.CommandPath())
			}
			if changed(cmd.Flags(), "server", "token") {
				if cmd.Flags().Changed("server") {
					if c.Server, err = config.NormalizeServer(server); err != nil {
						return usageErrorf("%v", err)
					}
				}
				if cmd.Flags().Changed("token") {
					switch token {
					case "-":
						if token, err = readToken(a.stdin()); err != nil {
							return err
						}
					case promptToken:
						if token, err = a.askToken(); err != nil {
							return err
						}
					}
					if token == "" {
						return usageErrorf("token must not be empty")
					}
					c.Token = token
				}
				save := config.Save
				if allowUnsafe {
					save = config.SaveLoose
				}
				err := save(path, c)
				var unsafe *config.UnsafeFileError
				if errors.As(err, &unsafe) {
					return fmt.Errorf("%w: run `chmod 600 %s` first, or pass --allow-unsafe-file to write it anyway", err, unsafe.Path)
				}
				return err
			}
			server := c.Server
			if server == "" {
				server = "(not set)"
			}
			_, err = fmt.Fprintf(a.Out, "server: %s\ntoken:  %s\nfile:   %s\n", server, config.MaskToken(c.Token), path)
			return err
		},
	}
	cmd.AddCommand(a.settingsCommands()...)
	cmd.Flags().StringVar(&server, "server", "", "server URL, e.g. https://linkrot.example")
	cmd.Flags().StringVar(&token, "token", "", "API token (see lr-auth on the server); without a value it is asked for on the terminal (hidden), `-` reads it from stdin; both keep it out of the shell history and the process list")
	cmd.Flags().Lookup("token").NoOptDefVal = promptToken
	cmd.Flags().BoolVar(&allowUnsafe, "allow-unsafe-file", false, "write the token even into a config file (behind a symlink) that others can read")
	return cmd
}

// readToken takes the first line of r, trimmed.
func readToken(r io.Reader) (string, error) {
	line, err := bufio.NewReader(io.LimitReader(r, 4096)).ReadString('\n')
	if err != nil && err != io.EOF {
		return "", fmt.Errorf("read token from stdin: %w", err)
	}
	return strings.TrimSpace(line), nil
}

// followsToken reports whether word stands right behind "--token" on the command line, which makes it the value.
func (a *App) followsToken(word string) bool {
	i := slices.Index(a.args, "--token")
	return i >= 0 && i+1 < len(a.args) && a.args[i+1] == word
}

// askToken asks for the token on the terminal without echoing it. Without a terminal it fails rather than guess.
func (a *App) askToken() (string, error) {
	read := a.ReadSecret
	if read == nil {
		f, ok := a.stdin().(*os.File)
		if !ok || !term.IsTerminal(int(f.Fd())) {
			return "", usageErrorf("--token without a value asks on the terminal, but stdin is not one; pass the token as --token <token>, or on stdin as --token -")
		}
		read = func(prompt string) (string, error) {
			_, _ = fmt.Fprint(a.Err, prompt)
			b, err := term.ReadPassword(int(f.Fd()))
			_, _ = fmt.Fprintln(a.Err)
			return string(b), err
		}
	}
	token, err := read("Token: ")
	if err != nil {
		return "", fmt.Errorf("read token: %w", err)
	}
	return strings.TrimSpace(token), nil
}
