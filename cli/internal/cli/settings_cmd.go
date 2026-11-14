package cli

import (
	"context"
	"fmt"
	"strings"

	"github.com/spf13/cobra"

	"github.com/schorsch3000/Linkrot/internal/api"
)

// settingKey maps what the user typed to the name of the API: "taglines", "show-taglines" and "show_taglines" are the same.
func settingKey(arg string) (string, error) {
	key := strings.ReplaceAll(strings.ToLower(arg), "-", "_")
	if !strings.HasPrefix(key, "show_") {
		key = "show_" + key
	}
	for _, known := range api.SettingKeys {
		if key == known {
			return key, nil
		}
	}
	return "", usageErrorf("unknown setting %q, known: %s", arg, strings.Join(api.SettingKeys, ", "))
}

func parseSettingValue(key, arg string) (bool, error) {
	switch strings.ToLower(arg) {
	case "on", "true", "yes", "1", "enable", "enabled":
		return true, nil
	case "off", "false", "no", "0", "disable", "disabled":
		return false, nil
	}
	return false, usageErrorf("%s: %q is not on or off", key, arg)
}

func (a *App) printSettings(settings map[string]bool) error {
	for _, key := range api.SettingKeys {
		value := "off"
		if settings[key] {
			value = "on"
		}
		if _, err := fmt.Fprintf(a.Out, "%-18s %s\n", key, value); err != nil {
			return err
		}
	}
	return nil
}

// settingsCommands are the subcommands of `config` that talk to the server: get and set.
func (a *App) settingsCommands() []*cobra.Command {
	var asJSON bool
	get := &cobra.Command{
		Use:   "get",
		Short: "Show the settings of the server",
		Long: "Shows the settings of the server you are connected to. They change how its pages look:\n\n" +
			"  show_taglines      the tagline at the bottom of every page and the X-Rot header of downloads\n" +
			"  show_startpage     the page at / (off: an empty page)\n" +
			"  show_title         the linkrot headline with the logo on every page\n" +
			"  show_linkrot_line  the linkrot command on the download page\n" +
			"  show_curl_line     the curl command on the download page\n" +
			"  show_hash_line     the SHA-256 on the download page (without any of the three lines the whole section is gone)",
		Example: fmt.Sprintf("  %[1]s config get\n  %[1]s config get --json", a.Name),
		Args:    usageArgs(cobra.NoArgs),
		RunE: func(cmd *cobra.Command, _ []string) error {
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				settings, err := c.Settings(ctx)
				if err != nil {
					return err
				}
				if asJSON {
					return a.printJSON(settings)
				}
				return a.printSettings(settings)
			})
		},
	}
	get.Flags().BoolVar(&asJSON, "json", false, "print the settings as JSON")

	set := &cobra.Command{
		Use:   "set <setting> <on|off> [<setting> <on|off>...]",
		Short: "Change settings of the server",
		Example: fmt.Sprintf("  %[1]s config set taglines off\n  %[1]s config set curl-line off hash-line off\n  %[1]s config set startpage on",
			a.Name),
		Args: usageArgs(func(_ *cobra.Command, args []string) error {
			if len(args) == 0 || len(args)%2 != 0 {
				return fmt.Errorf("expected pairs of <setting> <on|off>, got %d arguments", len(args))
			}
			return nil
		}),
		RunE: func(cmd *cobra.Command, args []string) error {
			changes := map[string]bool{}
			for i := 0; i < len(args); i += 2 {
				key, err := settingKey(args[i])
				if err != nil {
					return err
				}
				if changes[key], err = parseSettingValue(key, args[i+1]); err != nil {
					return err
				}
			}
			return a.withClient(cmd, func(ctx context.Context, c *api.Client) error {
				settings, err := c.UpdateSettings(ctx, changes)
				if err != nil {
					return err
				}
				return a.printSettings(settings)
			})
		},
	}
	set.ValidArgsFunction = func(cmd *cobra.Command, args []string, toComplete string) ([]string, cobra.ShellCompDirective) {
		if len(args)%2 == 1 {
			return []string{"on", "off"}, cobra.ShellCompDirectiveNoFileComp
		}
		return append([]string{}, api.SettingKeys...), cobra.ShellCompDirectiveNoFileComp
	}
	return []*cobra.Command{get, set}
}
