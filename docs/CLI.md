# CLI

Go application. It is called `linkrot`; `lr` is the short form (symlink or alias). The program derives its name from
`os.Args[0]` (basename), and uses that name in help texts and the generated shell completion,
so it works under any name.

Conventions:

- the **result** (URL, ids, JSON) goes to **stdout**; progress, hints and errors go to **stderr**, so output can be piped
- every command that talks to the server (`up`, `ls`, `info`, `set`, `rm`, `cleanup`, `down`, `config get`, `config set`,
  `custom get`, `custom put`, `custom reset`, `page ls`, `page get`, `page put`, `page rm`, `page template`) first asks
  `GET /health`.
  Warnings are printed on stderr in yellow and the command goes on; an error is printed in red and stops the command
  with exit code 1, since there is no working server. A server that has no health endpoint (404) is not an error
- colors (`ls`, `info`, errors, progress bar) are used per stream only if it is a terminal. `NO_COLOR` (any
  non-empty value) or `TERM=dumb` turns them off, `CLICOLOR_FORCE=1` turns them on even when piped. JSON output and
  bare URLs are never colored
- `<id>` is the 16 character file id. Wherever an id is expected a **unique prefix of at least 4 characters** is
  accepted. The CLI resolves it against `GET /files`. Ambiguous or unknown prefixes are an error. Public URLs always
  need the full id
- durations: `<n><unit>` with `h`, `d`, `w`, `m` (month), `y`; smallest is `1h`

## Commands

### `up [file] [flags]`

Uploads a file and prints its URL on stdout. Without `<file>` (or with `-`) the data is read from **stdin**, see below.

| flag | meaning |
| --- | --- |
| `--rm-after <time>` | delete after `<time>` counted from completion of the upload; default is the server's default |
| `--mv <newname>` | name on the server; default is the basename of `<file>`, `stdin` when reading stdin |
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

#### Upload from stdin

```
pg_dump db | gzip | lr up --mv db.sql.gz
```

`lr up` without a file, or `lr up -`, uploads whatever arrives on stdin. If stdin is a terminal the command refuses
with a usage error (exit code `2`) instead of waiting for typed input; a redirect or a pipe is read until EOF.

The size and the sha256 of a pipe are not known up front and the data cannot be read twice, so this does not hash
first. It uses the streaming variant of the [upload protocol](API.md#streaming-uploads): the upload is created without
size and hash, the chunks go out while they are read (one chunk, 32 MiB by default, is held in memory so it can be
resent), and the sha256 and size of everything are sent in a final `seal` request. Differences to a file upload:

- no resuming after the program is gone (running the same command again starts over); retries of a chunk that failed on the network still work
- the progress line has no percentage and no ETA, only bytes and throughput
- a duplicate is only detected at the end, after everything was sent; the result is the same as for a file (exit code `3`, existing URL on stdout)
- the default name is `stdin`, use `--mv`

### `ls [--json]`

Lists all files with id, name, size, expiry, remaining uses, status (incomplete uploads included) and URL. Below the table a line shows the quota of the server, e.g.
`Quota: 12.3 GB of 100.0 GB used (12%)` (yellow from 90%, red when full; `no limit` if the host has none; omitted by
servers that do not report it). Decimal units, as the server's `quota` is configured. `--json` prints the plain list of
files without it.

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
config --server <url>     set the server URL, e.g. https://linkrot.example (https only; http is accepted for localhost and loopback addresses, and an https to http redirect is refused)
config --token <token>    set the token
config                    show the current configuration (token masked)
config get [--json]       show the settings of the server (they change its public pages)
config set <setting> <on|off> [<setting> <on|off>...]   change settings of the server
```

The settings are `show_taglines`, `show_startpage`, `show_title`, `show_linkrot_line`, `show_curl_line` and
`show_hash_line`, see [API.md](API.md#get-settings-patch-settings---the-look-of-the-public-pages). All are on by default.
`show_` and `_`/`-` are optional when typing them: `lr config set curl-line off`.

The config file is `$XDG_CONFIG_HOME/linkrot/config.toml` (default `~/.config/linkrot/config.toml`) with mode `0600`; `$LINKROT_CONFIG` overrides the path. The directory is fixed and does not depend on the name the program is called with, so `linkrot`, `lr` and any other alias share one configuration.

### `custom`

Files of your server that you can replace with your own, see [API.md](API.md#getputdelete-customname---files-that-replace-the-built-in-look):

```
custom get <file> [-o path]   print the file (the built-in version as long as you did not replace it), or write it to a path
custom put <file> <path|->    replace the file with the content of a file (- is standard input)
custom reset <file>           go back to the built-in version
```

`<file>` is `taglines` (one footer tagline per line), `headerlines` (one `X-Rot` header line per line, printable ASCII
only) or `css` (your stylesheet, loaded after the built-in one). A good way to start: `lr custom get taglines -o
taglines.txt`, edit, `lr custom put taglines taglines.txt`. For `css`, `get` gives the color scheme of the built-in pages as
the starting point; after `put` the pages link `/assets/user.<hash>.css`. An old stylesheet stays reachable for a day, so
pages that are open right now keep their look.

### `page`

Static pages that are linked in the footer of every page of your server, for an imprint, a privacy notice and the like. A
page is a Markdown file; see [API.md](API.md#get-pages-getputdelete-pagesname-get-page-template---static-pages) for the
head (`title`, `link`, `order`, `published`, `lang`) and what the strict Markdown allows (no raw HTML).

```
page template [-o path]       print a page to start from: every key of the head, some dummy content (a draft)
page put <path|-> [--name n]  upload a Markdown file; replaces the page of the same name
page ls [--json]              list the pages in the order of the footer; drafts are marked
page get <name> [-o path] [--html]   print the file exactly as you uploaded it; --html: the rendered body, also of a draft
page rm <name>...             delete pages (the original is deleted too, download it first)
```

The name of a page, which is its URL (`/page/<name>`), is made from the file name: `Imprint.md` becomes `imprint`. `--name`
chooses it, and is needed for standard input. The server checks and renders the file when you upload it and refuses it
with the offending lines if it contains raw HTML, a link or image that is not allowed or a mistake in the head; nothing
changes then. A start: `lr page template -o imprint.md`, edit, `lr page put imprint.md`; the template is a draft until you
set `published: true`.

### `completion bash|zsh|fish`

Prints a completion script. Subcommands and flags complete statically. For `rm`, `set`, `info` and `down` the ids are
completed live from the server (name as description) with a 2 second timeout; the lowercase start of every id makes
prefix completion pleasant. `config set` completes the setting names and `on`/`off`, `custom` the file names, `page get` and `page rm` the names of your pages.

## Exit codes

| code | meaning |
| --- | --- |
| 0 | success |
| 1 | error (network, server error, local I/O, ...) |
| 2 | usage error |
| 3 | `up`: the file already exists on the server (URL printed on stdout) |
