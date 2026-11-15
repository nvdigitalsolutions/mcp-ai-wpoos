# Building the QBBridge installer

> **Fork (quickbooks-desktop-mcp):** build with the **32-bit** script
> `installer/build_win32.ps1` (see the main README, "Bouwen"). The steps
> below are upstream's and assume a matching Python; for QuickBooks 2016 the
> exe must be 32-bit.

Two stages: PyInstaller freezes the Python code into standalone `.exe`s
(no Python install required on the end user's machine), then Inno Setup
wraps those into one `QBBridgeSetup.exe` that also auto-registers QBBridge
with Claude Desktop.

## What's been verified vs. what hasn't

**Verified, against a live QuickBooks Desktop file:** the frozen
`qbbridge-mcp.exe`'s QBXMLRP2 COM connection actually works (`pywin32`'s
COM dispatch is known to be fragile under PyInstaller freezing -- this was
tested directly, not assumed). `configure_claude_desktop.exe`'s config-merge
logic was verified against both a clean config and a deliberately malformed
one (confirms the backup-and-warn path, not just the happy path).

**Not yet verified:** actually running `QBBridgeSetup.exe`'s install wizard
end-to-end on a clean machine (this would modify the real Claude Desktop
config on whatever machine runs it, so do this deliberately, not as a
casual check). Also not yet done: code signing (see below) -- without it,
Windows SmartScreen will warn "Unknown publisher" on first run.

## 1. Freeze the Python code

```bash
pip install pyinstaller
cd installer
pyinstaller --onefile --name qbbridge-mcp --hidden-import win32timezone --hidden-import win32com --collect-submodules win32com entrypoint.py
pyinstaller --onefile --name configure_claude_desktop configure_claude_desktop.py
python build_skill_zip.py
```

The `win32com`/`win32timezone` hidden-import flags are required --
`pywin32`'s COM support does not freeze cleanly without them (it fails
silently rather than erroring at build time, so don't skip this even
though PyInstaller doesn't complain if you do).

`build_skill_zip.py` packages `skills/qbbridge-bookkeeping/` into a
zip with the skill's own folder at the top level (not `SKILL.md` at the
zip root) -- that's the shape Claude's Skills upload expects.

Output: `dist/qbbridge-mcp.exe`, `dist/configure_claude_desktop.exe`,
`qbbridge-bookkeeping.zip`.

## 2. Build the installer

Requires [Inno Setup 6](https://jrsoftware.org/isinfo.php) (free).

```bash
"C:\path\to\ISCC.exe" qbbridge_setup.iss
```

Output: `Output/QBBridgeSetup.exe`.

## 3. Code signing (not yet done -- needed before wide distribution)

Both `dist/*.exe` and `Output/QBBridgeSetup.exe` should be signed with a
code-signing certificate before this goes out to anyone beyond internal
testing. An unsigned installer for anything that touches financial
software is a real trust problem, not just a cosmetic SmartScreen popup --
budget for a certificate (a handful of CAs offer these; EV certificates
avoid the SmartScreen reputation-building period that standard ones need).

## What the installer actually does

1. Copies `qbbridge-mcp.exe` and `configure_claude_desktop.exe` to
   `Program Files\QBBridge`.
2. Runs `configure_claude_desktop.exe` once, automatically, which merges a
   `"qbbridge"` entry into `%APPDATA%\Claude\claude_desktop_config.json`
   (creating the file if it doesn't exist, backing up and warning instead
   of overwriting if it's malformed, and leaving every other entry in that
   file untouched).
3. Tells the user to restart Claude Desktop and what to expect from
   QuickBooks' one-time permission dialog.

## Why the skill install step isn't automated

Confirmed, not assumed: there is no documented (or discoverable) way for a
third-party installer to register a skill into Claude Desktop
automatically.

- Claude's own Skills documentation only describes a manual flow:
  Settings > Customize > Skills > "+" > Create skill > upload a zip.
  No file-system location Claude Desktop watches for skills is mentioned
  anywhere, unlike Claude Code's `~/.claude/skills/`.
- The `claude://` URI scheme is real (checked the registry on a machine
  with Claude Desktop installed -- it's registered and launches
  `Claude.exe "%1"`), but inspecting the app's own bundled code
  (`app.asar`) turned up only three real routes: `claude://claude`,
  `claude://cowork/shared-artifact`, and `claude://resume`. None reach
  Settings or Skills, so there's no deep link to jump straight there
  either.
- This is very likely an intentional trust boundary, not a gap: letting a
  third-party installer silently push instructions that shape Claude's
  behavior, with no visible user action, would be a reasonable thing to
  disallow.

Given that, the installer does the next best thing: it builds the correct,
ready-to-upload zip automatically (`build_skill_zip.py`), ships it inside
the install folder, and opens `NEXT_STEPS.txt` automatically at the end of
setup with the exact remaining steps and file path to upload. The one
manual action left is Claude's own upload click -- everything else around
it is automated.

Skills are confirmed (verbatim, from Claude's own Skills help article) to
be available on all plans including Free, contingent only on code
execution being enabled (on by default) -- this is not a paid-plan-gated
step.
