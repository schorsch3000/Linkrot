package cli

import (
	"fmt"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/config"
)

func (a *App) configCommand() *cobra.Command {
	var server, token string
	cmd := &cobra.Command{
		Use:   "config",
		Short: "Show or set the server URL and token",
		Example: fmt.Sprintf("  %[1]s config --server https://linkrot.example\n  %[1]s config --token <token>\n  %[1]s config",
			a.Name),
		Args: usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			path, err := a.ConfigPath()
			if err != nil {
				return err
			}
			c, err := config.Load(path)
			if err != nil {
				return err
			}
			if changed(cmd.Flags(), "server", "token") {
				if cmd.Flags().Changed("server") {
					if c.Server, err = config.NormalizeServer(server); err != nil {
						return usageErrorf("%v", err)
					}
				}
				if cmd.Flags().Changed("token") {
					if token == "" {
						return usageErrorf("token must not be empty")
					}
					c.Token = token
				}
				return config.Save(path, c)
			}
			server := c.Server
			if server == "" {
				server = "(not set)"
			}
			_, err = fmt.Fprintf(a.Out, "server: %s\ntoken:  %s\nfile:   %s\n", server, config.MaskToken(c.Token), path)
			return err
		},
	}
	cmd.Flags().StringVar(&server, "server", "", "server URL, e.g. https://linkrot.example")
	cmd.Flags().StringVar(&token, "token", "", "API token (see lr-auth on the server)")
	return cmd
}
