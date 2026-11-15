#!/usr/bin/env python3
"""Records a terminal session with the real `lr` against the local server, as an asciicast (docs/img/demo.cast).

A bash runs in a pseudo-terminal; the script types the commands at the speed of a person and waits for each to finish, so
what is recorded is the real output of the real programs, colors and progress bar included.

The one cosmetic step: the server runs on http://localhost:<port>, and the recording shows that origin as
https://files.example.com, which is what a deployed server would print. Nothing else is changed.

Environment: PORT (of the server), BIN (folder with `lr`), WORK (scratch folder), OUT (folder of the images).
"""
import fcntl
import json
import os
import pty
import random
import select
import struct
import subprocess
import sys
import termios
import time

PORT = os.environ["PORT"]
BIN = os.environ["BIN"]
WORK = os.environ["WORK"]
OUT = os.environ["OUT"]
COLS, ROWS = 122, 22
PROMPT_END = b"$\x1b[0m "  # what the colored prompt ends with
ORIGIN = f"http://localhost:{PORT}"
SHOWN = "https://files.example.com"

# What gets typed, with a pause after each line (seconds).
SCRIPT = [
    ("# share a file", 0.9),
    ("lr up report.pdf --rm-after 2d", 1.6),
    ("# pipes work too", 0.9),
    ("tar czf - project | lr up --mv project.tgz --uses 3", 1.6),
    ("lr ls", 2.2),
    ("# a link that rots: one download, then it is gone", 1.0),
    ("url=$(lr up -q secret.txt --uses 1)", 0.8),
    ('curl -s "${url/file/dl}"', 1.0),
    ("curl -s -o /dev/null -w '%{http_code}\\n' \"$url\"", 2.5),
]


def prepare():
    os.makedirs(f"{WORK}/project", exist_ok=True)
    with open(f"{WORK}/report.pdf", "wb") as f:
        f.write(os.urandom(2_400_000))
    for name, size in (("notes.md", 4_000), ("data.bin", 3_000_000), ("main.go", 12_000)):
        with open(f"{WORK}/project/{name}", "wb") as f:
            f.write(os.urandom(size))
    with open(f"{WORK}/secret.txt", "w") as f:
        f.write("the launch code is 12345\n")
    with open(f"{WORK}/inputrc", "w") as f:
        f.write("set enable-bracketed-paste off\nset bell-style none\n")


def main():
    prepare()
    random.seed(7)
    pid, fd = pty.fork()
    if pid == 0:
        os.chdir(WORK)
        env = {
            "PATH": f"{BIN}:" + os.environ["PATH"],
            "HOME": WORK,
            "TERM": "xterm-256color",
            "PS1": "\\[\\e[1;32m\\]$\\[\\e[0m\\] ",
            "INPUTRC": f"{WORK}/inputrc",
            "LINKROT_CONFIG": os.environ["LINKROT_CONFIG"],
            "LC_ALL": "C.UTF-8",
        }
        os.execvpe("bash", ["bash", "--noprofile", "--norc", "-i"], env)
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", ROWS, COLS, 0, 0))

    events = []
    start = time.monotonic()

    def drain():
        """Takes what the terminal has printed so far, without waiting."""
        while select.select([fd], [], [], 0)[0]:
            try:
                data = os.read(fd, 65536)
            except OSError:
                return
            if not data:
                return
            events.append((time.monotonic() - start, data.decode("utf-8", "replace")))

    def read_until_quiet(prompt_needed, quiet=0.25, limit=30.0):
        """Collects output until the shell shows its prompt and has been silent for a moment."""
        deadline = time.monotonic() + limit
        last = time.monotonic()
        tail = b""
        while time.monotonic() < deadline:
            ready, _, _ = select.select([fd], [], [], 0.05)
            if ready:
                try:
                    data = os.read(fd, 65536)
                except OSError:
                    return
                if not data:
                    return
                events.append((time.monotonic() - start, data.decode("utf-8", "replace")))
                tail = (tail + data)[-40:]
                last = time.monotonic()
            elif time.monotonic() - last >= quiet and (not prompt_needed or tail.endswith(PROMPT_END)):
                return

    read_until_quiet(True)  # the first prompt
    for line, pause in SCRIPT:
        time.sleep(0.5)
        for ch in line:
            os.write(fd, ch.encode())
            time.sleep(random.uniform(0.025, 0.06) if ch != " " else random.uniform(0.04, 0.09))
            drain()
        time.sleep(0.25)
        os.write(fd, b"\r")
        read_until_quiet(True)
        time.sleep(pause)
        drain()
    # no typed `exit` at the end of the recording: the terminal is simply closed
    os.close(fd)
    try:
        os.waitpid(pid, 0)
    except ChildProcessError:
        pass

    # the cosmetic step: the origin of the local server shown as the host of a deployed one
    events = replace_across_events(events, ORIGIN, SHOWN)
    header = {"version": 2, "width": COLS, "height": ROWS, "timestamp": int(time.time()), "env": {"TERM": "xterm-256color", "SHELL": "/bin/bash"}}
    with open(f"{OUT}/demo.cast", "w", encoding="utf-8") as f:
        f.write(json.dumps(header) + "\n")
        for t, data in events:
            f.write(json.dumps([round(t, 3), "o", data], ensure_ascii=False) + "\n")
    print(f"  demo.cast: {len(events)} events, {events[-1][0]:.1f}s")


def replace_across_events(events, old, new):
    """str.replace over the whole output, although `old` may be split between two events."""
    out = []
    pending = ""  # text that may be the start of `old`, with the time of its first character
    pending_time = 0.0
    for t, data in events:
        if not pending:
            pending_time = t
        text = (pending + data).replace(old, new)
        keep = 0
        for n in range(min(len(old) - 1, len(text)), 0, -1):
            if old.startswith(text[-n:]):
                keep = n
                break
        emit, pending = (text[: len(text) - keep], text[len(text) - keep:]) if keep else (text, "")
        if emit:
            out.append((pending_time if len(out) == 0 or pending_time <= t else t, emit))
        pending_time = t
    if pending:
        out.append((events[-1][0], pending))
    return out


if __name__ == "__main__":
    sys.exit(main())
