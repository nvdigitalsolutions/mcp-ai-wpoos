# Shell harness pitfalls (Windows/Cygwin PTY, session 2026-11-08)

Verification output in this environment invents failures. Rule these out
before debugging a "bug" that only a command produced:

- **Parallel terminal calls interleave on the Cygwin PTY.** Output arrives
  mid-word - `lib/core/LICENSE` + `pterer`, `package.json` + `es`, a status
  counter glued onto a later line. Run output-producing commands
  *sequentially*, redirect to a file, then read it once. An interleaved
  stream is not evidence, and "fixing" what it appears to show is wasted work.
- **Windows `python3` does not understand `/tmp`** even though bash writes
  there happily (symptom: `FileNotFoundError` on a file you just created).
  `cygpath -w /tmp` gives `C:\Users\<user>\AppData\Local\Temp`; address it in
  Python as `os.environ["TEMP"] + "/file"` (forward slashes are fine).
- **Backslashes do not survive heredocs**, quoted or not. A PHP line meant to
  contain two backslashes reaches the file with one, the string terminates
  early, and you get a *bogus* parse error pointing at the wrong line. Build
  the character explicitly with `chr(92)` (PHP or Python-generated files)
  instead of writing it literally.
- **msys `rsync`/`zip` are unreliable here** (`msys-xxhash-0.dll` missing).
  Use WSL: `wsl -e bash -lc 'cd /mnt/f/GITHUB/mcp-ai-wpoos && <command>'`.
  The WSL run also reproduces `--exclude` / `--exclude-from` semantics, which
  matters for packaging verification.
- **`git archive HEAD` ignores unstaged `.gitattributes`.** Stage the rule
  first, or archive the tree object printed by `git write-tree`.
- **`git add -A` is forbidden** in this repo's commit discipline, and globs
  sweep other agents' worktrees. Stage explicit paths and re-read
  `git diff --cached --name-only` before committing.
