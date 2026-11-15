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
- **`-q`, `--quiet`** (every command, before or after the command name) keeps only errors and the result a command exists
  to produce: no progress bar, no retry notices, no server warnings, no confirmations, no hints.
  `up -q` prints just the URL, and nothing at all with `--copy` once the URL is on the clipboard (if the copy fails the URL is
  printed after all, next to the warning); `down -q` just the path.
  `ls`, `info`, `config`, `config get`, `custom get`, `page ls`, `page get` and `page template` still print what they were asked for
  (`ls` without the quota line). `set`, `rm`, `cleanup`, `config set`, `custom put|reset`, `page put|rm` and the `-o` of the `get`s
  print nothing. `--json` output is printed, because it was asked for. Errors always are, including the message of a
  duplicate upload (exit code 3, URL on stdout) and the notes of `edit` about an upload that failed; the prompts and
  status lines of `edit` are not. Health problems that stop a command are errors; health warnings are not shown
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
| `--uses <n>` | allow only `n` downloads; default is the server's default (unlimited unless configured). A convenience limit, **not a security feature**: anyone with the link can copy the file |
| `--copy[=<selection>]` | copy the URL; without a value via OSC 52 (works over SSH), with `clipboard` or `primary` via `wl-copy`, `xclip`, `xsel` or `pbcopy`. The `=` form is required because a space would be read as the file argument |
| `--json` | print the file object as JSON instead of the bare URL |

Behaviour:

1. hash the file (sha256), then create or resume the upload (see [API.md](API.md#upload-protocol))
2. send chunks of the size the server dictates; each chunk is hashed while it is being sent and the digest is appended to
   the body, so there is no extra delay per chunk
3. a progress bar with throughput and ETA is drawn on stderr (only if stderr is a terminal)
4. retry with backoff on network errors and `locked`/`5xx`; resume from the offset the server reports. A `Retry-After` of the
   server (seconds or a date) is honoured, for `down` too, but never longer than 5 minutes. A connection
   on which no byte moves for a minute (a server that went silent) counts as a network error, for `down` too
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
- the data goes to `<name>.<start of the sha256>.part` first (created with `O_EXCL`), is resumed with `Range` if that file exists, is
  verified against the sha256 and is then moved into place in a way that fails if the name exists, so an existing file can
  never be replaced (where hard links are missing it copies instead, see [Limits and caveats](#limits-and-caveats)). If the
  final name exists the command fails and says so
- a `<name>.part` that another program wrote is never resumed, appended to or deleted, and the partial data of another
  version of the file is not continued
- the server-supplied name is sanitised (`filepath.Base`, `..`, empty names and path separators rejected)

### `cleanup`

Asks the server to run its cleanup now (`POST /cleanup`).

### `config`

```
config --server <url>     set the server URL, e.g. https://linkrot.example (https only; http is accepted for localhost and loopback addresses, and an https to http redirect is refused)
config --token            ask for the token on the terminal, the input is hidden (fails if stdin is not a terminal)
config --token -          read the token from the first line of stdin, e.g. `lr config --token - < token-file`
config --token <token>    set the token (it ends up in the shell history and the process list; prefer the two forms above)
config --allow-unsafe-file ...  write the token even into a config file that others can read (see below)
config                    show the current configuration (token masked)
config get [--json]       show the settings of the server (they change its public pages)
config set <setting> <on|off> [<setting> <on|off>...]   change settings of the server
```

The config file (`$XDG_CONFIG_HOME/linkrot/config.toml`, `~/.config/linkrot/config.toml`, or `$LINKROT_CONFIG`) is
written with mode 0600 through a temporary file that is fsynced and renamed, so a crash leaves the old or the new file.
If it is a **symlink** (stow, chezmoi, home-manager), the link is followed and kept, and the mode of the file behind it
is kept as well. Every command warns once (not with `-q`) if the config file can be read by group or others, and
`config` refuses to write a token into such a file behind a symlink: `chmod 600` it, or pass `--allow-unsafe-file` to
write it anyway. A plain file readable by others is simply replaced by a private one. If the symlink points into a
read-only place (the Nix store), the error says so and nothing changes.

The settings are `show_taglines`, `show_startpage`, `show_title`, `show_linkrot_line`, `show_curl_line` and
`show_hash_line`, see [API.md](API.md#get-settings-patch-settings---the-look-of-the-public-pages). All are on by default.
`show_` and `_`/`-` are optional when typing them: `lr config set curl-line off`.

The directory of the config file does not depend on the name the program is called with, so `linkrot`, `lr` and any other alias share one configuration.

### `custom`

Files of your server that you can replace with your own, see [API.md](API.md#getputdelete-customname---files-that-replace-the-built-in-look):

```
custom get <file> [-o path]   print the file (the built-in version as long as you did not replace it), or write it to a path
custom put <file> <path|->    replace the file with the content of a file (- is standard input)
custom reset <file>           go back to the built-in version
custom edit [--once] <file> [editor [editor arguments...]]   edit it in an editor, see below
```

`<file>` is `taglines` (one footer tagline per line), `headerlines` (one `X-Rot` header line per line, printable ASCII
only) or `css` (your stylesheet, loaded after the built-in one). A good way to start: `lr custom get taglines -o
taglines.txt`, edit, `lr custom put taglines taglines.txt`. For `css`, `get` gives the color scheme of the built-in pages as
the starting point; after `put` the pages link `/assets/user.<hash>.css`. An old stylesheet stays reachable for a day, so
pages that are open right now keep their look.

### `page`

Static pages that are linked in the footer of every page of your server, for an imprint, a privacy notice and the like. A
page is a Markdown file; see [API.md](API.md#get-pages-getputdelete-pagesname-get-page-template---static-pages) for the
head (`title`, `link`, `order`, `published`, `lang`, `replaces_startpage`) and what the strict Markdown allows (no raw HTML).

```
page template [-o path]       print a page to start from: every key of the head, some dummy content (a draft)
page put <path|-> [--name n]  upload a Markdown file; replaces the page of the same name
page ls [--json]              list the pages in the order of the footer; drafts are marked
page get <name> [-o path] [--html]   print the file exactly as you uploaded it; --html: the rendered body, also of a draft
page rm <name>...             delete pages (the original is deleted too, download it first)
```

A page with `replaces_startpage: true` is shown at `/` instead of the start page of linkrot (several: one after the other,
split by a line) and is not linked in the footer; `page ls` marks it.

The name of a page, which is its URL (`/page/<name>`), is made from the file name: `Imprint.md` becomes `imprint`. `--name`
chooses it, and is needed for standard input. The server checks and renders the file when you upload it and refuses it
with the offending lines if it contains raw HTML, a link or image that is not allowed or a mistake in the head; nothing
changes then. A start: `lr page template -o imprint.md`, edit, `lr page put imprint.md`; the template is a draft until you
set `published: true`.

### `page edit` and `custom edit`

```
page edit   [--once] <name> [editor [editor arguments...]]
custom edit [--once] <file> [editor [editor arguments...]]
```

Downloads the page or file to a temporary file (named `imprint.md`, `user.css`, `taglines.txt`, `headerlines.txt`, so that
the editor knows the syntax), opens it in an editor and **uploads it whenever you save**, until you press Ctrl+C.

- **The editor** is the program named after the name, started with all the words given and the file as the last
  argument: `lr custom edit css /my/fancy/editor --fancy -f` runs `/my/fancy/editor --fancy -f <tempfile>`. Without one:
  `$VISUAL`, then `$EDITOR` (both may carry arguments, `code --wait`), then the first of `vim`, `vi`, `nano`, `pico` that is
  installed (`notepad` on Windows). `--once` and the other flags of `lr` have to come **before** the name, everything after it
  belongs to the editor.
- **Watching.** `lr` stays open and polls the file. A save that then stays unchanged for a moment is uploaded, but only if
  the content changed. A refused upload (raw HTML in a page, a mistake in the head) is explained with the lines and tried
  again at the next save. A change is noticed by size and modification time, and for 5 seconds after a save by the
  content as well (file systems with a coarse clock give two quick saves the same time); a tool that sets an old
  modification time on purpose is not noticed while watching, but the last upload at the end reads the content anyway.
  Because the file is watched and not the editor, graphical editors that return at once
  (`code`, `subl`, `gedit`) work as well. The result of every upload is printed, with the URL of a page or of the new
  stylesheet.
- **Full-screen editors** (vim, nano, ...) own the terminal, and anything printed would garble their screen. So while the
  editor is open nothing is printed; when you quit it, the messages appear, and `lr` stays open: **Enter** opens the editor
  again, **Ctrl+C** stops. Quit, read what the server said, press Enter, fix it.
- **Ctrl+C** stops, after one last upload of a save that had not been uploaded yet. If that fails the temporary file is
  kept and its path printed, and the exit code is 1; otherwise the temporary file is removed. A server that does not
  answer cannot hold you: an upload gives up after 2 minutes while watching and after 1 minute at the end, and a
  second Ctrl+C during that last upload gives up at once (the file is kept).
- **Starting point.** A page that does not exist yet starts from the template (a draft until it says `published: true`).
  A custom file that was never replaced starts from the built-in version. Nothing is uploaded before the first change.
- **`--once`** does not watch: it uploads when the editor exits (the editor has to wait, `code --wait`, a terminal editor
  does) and ends. If the upload is refused, Enter opens the editor again and Ctrl+C gives up and keeps the file.
- Two things to know: someone else's change to the same page in the meantime is overwritten (the last upload wins), and a
  graphical editor started from the same terminal also gets the Ctrl+C that stops `lr` (most launchers detach and do not).
  Without a terminal an editor has to be named.

### `completion bash|zsh|fish`

Prints a completion script for the shell you name. Subcommands and flags complete statically. For `rm`, `set`, `info` and
`down` the ids are completed live from the server (name as description) with a 2 second timeout; the lowercase start of
every id makes prefix completion pleasant. `config set` completes the setting names and `on`/`off`, `custom` the file
names, `page get` and `page rm` the names of your pages.

The script is made for the name you call the program by: run it as `lr completion ...` and it completes `lr`, run it as
`linkrot completion ...` and it completes `linkrot`. If you use both names, load it once under each.

**bash** (needs the `bash-completion` package, version 2, which most distributions load for interactive shells):

```
source <(lr completion bash)                                           # this shell; put it in ~/.bashrc to keep it
lr completion bash > ~/.local/share/bash-completion/completions/lr     # or once: loaded on demand, in every new shell
```

**zsh** (completion has to be on: `autoload -U compinit && compinit` in `~/.zshrc`, before the line below):

```
source <(lr completion zsh)                                            # this shell; put it in ~/.zshrc after compinit
lr completion zsh > "${fpath[1]}/_lr"                                  # or once: a file named _lr in a directory of $fpath
```

**fish**:

```
lr completion fish | source                                            # this shell
lr completion fish > ~/.config/fish/completions/lr.fish                # or once: loaded on demand, in every new shell
```

After writing a file, open a new shell (zsh: delete `~/.zcompdump` first if the new completion does not show up). The
live parts (ids, page names) need a configured server and say nothing, rather than hang, when it cannot be reached.

## Exit codes

| code | meaning |
| --- | --- |
| 0 | success |
| 1 | error (network, server error, local I/O, ...) |
| 2 | usage error |
| 3 | `up`: the file already exists on the server (URL printed on stdout) |

## Limits and caveats

What the client does not promise, and where it behaves differently from what you might expect. The limits of the server
are in [SERVER.md](SERVER.md#limits-and-caveats).

- **A file upload resumes, an upload from stdin does not.** Run `lr up big.iso` again after an interruption and it
  continues where the server is. A pipe cannot: nobody knows whether the same bytes would ever come again, so an interrupted
  `... | lr up` starts over (the server drops the unfinished upload by itself, after `upload_timeout`). The hash of a pipe
  is only known at the end, so its integrity is checked at the end, not chunk by chunk beforehand.
- **`lr down` never overwrites, and on some file systems it cannot do that atomically.** Normally the finished download
  is put in place with `link()`, which fails if the name exists. Where hard links do not exist (FAT/exFAT, some SMB, NFS or
  FUSE mounts, Termux) it creates the file with `O_EXCL` and copies the verified data into it. Nothing is replaced, but a
  crash or a power loss during that copy leaves a truncated file under the final name; a copy that fails or does not match
  is removed again, and the `.part` file is kept.
- **Partial downloads are `<name>.<start of the sha256>.part`.** A `<name>.part` that another program wrote is never
  touched, and partial data of another version of the same file is never continued. Partial files that an older version
  of `lr` left behind are not picked up either: delete them by hand.
- **A server that goes quiet counts as a network error.** A connection on which not a single byte moves for a minute is
  given up, then retried or resumed like any other failure. A `Retry-After` of the server is honoured, but never longer
  than 5 minutes.
- **`lr page edit` and `lr custom edit` watch the file, they do not know your editor.** A change is noticed by size and
  modification time, and for 5 seconds after a save by the content as well (file systems with a coarse clock give two
  quick saves the same time). A tool that sets an old modification time on purpose is not noticed while watching; the last
  upload when you press Ctrl+C reads the content anyway.
- **A server that does not answer cannot hold the editor.** A watched upload gives up after 2 minutes, the last one
  after 1 minute, and a second Ctrl+C during the last one gives up at once; the file is kept and its path printed.
- **The config file holds your token.** `lr` warns once per run if group or others can read it, and refuses to write a
  token into such a file when it is a symlink (dotfile managers) unless you pass `--allow-unsafe-file`. On Windows there are
  no such file modes, nothing is checked. `lr config --token` without a value asks on the terminal, the input hidden; it
  needs a terminal and otherwise fails (use `--token -` with the token on stdin).
- **`--copy` with OSC 52 cannot confirm that anything was copied.** The default selection writes an escape sequence to the
  terminal, and a terminal that ignores it (tmux without `allow-passthrough`, terminals with OSC 52 switched off) still counts as
  success, so `up -q --copy` prints nothing and the URL is nowhere. If your terminal might do that, use `--copy=clipboard`
  (a clipboard tool that fails is noticed) or leave `-q` off.
- **Tested on Linux.** Release archives are built for Linux, macOS, Windows and FreeBSD, but the tests and the
  end-to-end run in CI are on Linux only. Behaviour that depends on the platform (hard links, file modes, the token
  prompt, the clipboard) is the part to watch on the others.
