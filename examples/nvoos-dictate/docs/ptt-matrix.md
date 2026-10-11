# Push-to-talk acceptance matrix

Run this matrix on every release. The Windows PTT path uses a
`WH_KEYBOARD_LL` hook (dedicated thread + message loop) with a
`GetAsyncKeyState` polling fallback that takes over when the hook heartbeat
goes stale — Windows can silently stop delivering low-level hook events while
a **Chromium window has focus**, which is exactly why the focused-app cases
below matter.

| # | Focused app | PTT press/release | Expectation | Passed (date / build) |
|---|---|---|---|---|
| 1 | Chrome (Chromium) | RAlt hold → speak → release | text pastes; no menu-bar activation | |
| 2 | Microsoft Edge (Chromium) | same | text pastes | |
| 3 | VSCode (Chromium) | same | text pastes into the editor; identifiers preserved (code profile) | |
| 4 | Notepad | same | text pastes | |
| 5 | Word / Outlook compose | same | text pastes (docs profile: terminal period) | |
| 6 | Windows Terminal / cmd | same | text pastes; `Ctrl+Shift+V` fallback documented if `Ctrl+V` is intercepted | |
| 7 | Slack / Discord / Telegram | same | text pastes (chat profile: no trailing period) | |
| 8 | Our own window (main) | same | no self-paste; overlay updates only | |
| 9 | Any app | hold Shift + release | raw passthrough (no cleanup, spacing only) | |
| 10 | Any app | double-tap | hands-free toggle; next tap finalizes | |
| 11 | Any app | hold + Esc | cancelled; nothing pasted | |
| 12 | AltGr layouts (e.g. German) | type AltGr chars outside dictation | AltGr fully usable (PTT key only swallowed while recording) | |

## Known limitations

- While dictating, the PTT key itself is swallowed so the focused app never
  sees the held modifier. Outside dictation it passes through untouched.
- If the hook is starved (some fullscreen exclusive apps), the fallback
  poller takes over within ~1.5 s — a short extra latency is expected in
  that window.
- macOS/Linux use toggle semantics (`Ctrl+Shift+Space` = start, again =
  finalize) via tauri-plugin-global-shortcut; Wayland global-shortcut
  restrictions apply (see README).
