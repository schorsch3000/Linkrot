# CLI

Go application. It is called `linkrot`; `lr` is the short form (symlink or alias). The program derives its name from
`os.Args[0]` (basename), and uses that name in help texts and the generated shell completion,
so it works under any name.

Conventions:

- the **result** (URL, ids, JSON) goes to **stdout**; progress, hints and errors go to **stderr**, so output can be piped
- colors (`ls`, `info`, errors, progress bar) are used per stream only if it is a terminal. `NO_COLOR` (any
  non-empty value) or `TERM=dumb` turns them off, `CLICOLOR_FORCE=1` turns them on even when piped. JSON output and
  bare URLs are never colored
- `<id>` is the 16 character file id. Wherever an id is expected a **unique prefix of at least 4 characters** is
  accepted. The CLI resolves it against `GET /files`. Ambiguous or unknown prefixes are an error. Public URLs always
  need the full id
- durations: `<n><unit>` with `h`, `d`, `w`, `m` (month), `y`; smallest is `1h`

## Commands

### `up <file> [flags]`

Uploads a file and prints its URL on stdout.

| flag | meaning |
| --- | --- |
| `--rm-after <time>` | delete after `<time>` counted from completion of the upload; default is the server's default |
| `--mv <newname>` | name on the server; default is the basename of `<file>` |
| `--uses <n>` | allow only `n` downloads; default is the server's default (unlimited unless configured) |
| `--copy[=<selection>]` | copy the URL; without a value via OSC 52 (works over SSH), with `clipboard` or `primary` via `wl-copy`, `xclip`, `xsel` or `pbcopy`. The `=` form is required because a space would be read as the file argument |
| `--json` | print the file object as JSON instead of the bare URL |

Behaviour:

1. hash the file (sha256), then create or resume the upload (see [API.md](API.md#upload-protocol))
2. send chunks of the size the server dictates; each chunk is hashed while it is being sent and the digest is appended to
   the body, so there is no extra delay per chunk
3. a progress bar with throughput and ETA is drawn on stderr (only if stderr is a terminal)
4. retry with backoff on network errors and `locked`/`5xx`; resume from the offset the server reports
5. print the URL on stdout

If the same file (same sha256) already exists and is complete the server refuses. The CLI then prints a message on
stderr, prints the **existing URL on stdout** and exits with code `3`, so `lr up file | wl-copy` still does the right
thing while scripts can tell the situation apart.

### `ls [--json]`

Lists all files with id, name, size, expiry, remaining uses, status (incomplete uploads included) and URL.

### `info <id> [--json]`

Shows one file.

### `set <id> [flags]`

Changes metadata of an uploaded file. Takes the same flags as `up` that make sense here (`--rm-after`, `--mv`,
`--uses`). `--rm-after` is counted **from now**. `--uses` sets the number of **remaining** downloads.

### `rm <id>...`

Deletes one or more files. Silent on success. A failure for one id is reported on stderr and does not stop the others; the exit code is then 1.

### `down <id> [target]`

Downloads a file.

- no `target`: write to the current directory with the original name
- `target` is an existing directory: write into it with the original name
- otherwise `target` is the file name; its parent directory must exist
- **never overwrites** a file and **never creates** a directory
- the data goes to `<name>.part` first (created with `O_EXCL`), is resumed with `Range` if a `.part` exists, is
  verified against the sha256 and is then moved into place with `link()` + `unlink()` so an existing file can never
  be replaced. If the final name exists the command fails and says so
- the server-supplied name is sanitised (`filepath.Base`, `..`, empty names and path separators rejected)

### `cleanup`

Asks the server to run its cleanup now (`POST /cleanup`).

### `config`

```
config --server <url>     set the server URL, e.g. https://linkrot.example
config --token <token>    set the token
config                    show the current configuration (token masked)
```

The config file is `$XDG_CONFIG_HOME/linkrot/config.toml` (default `~/.config/linkrot/config.toml`) with mode `0600`; `$LINKROT_CONFIG` overrides the path. The directory is fixed and does not depend on the name the program is called with, so `linkrot`, `lr` and any other alias share one configuration.

### `completion bash|zsh|fish`

Prints a completion script. Subcommands and flags complete statically. For `rm`, `set`, `info` and `down` the ids are
completed live from the server (name as description) with a 2 second timeout; the lowercase start of every id makes
prefix completion pleasant.

## Exit codes

| code | meaning |
| --- | --- |
| 0 | success |
| 1 | error (network, server error, local I/O, ...) |
| 2 | usage error |
| 3 | `up`: the file already exists on the server (URL printed on stdout) |
