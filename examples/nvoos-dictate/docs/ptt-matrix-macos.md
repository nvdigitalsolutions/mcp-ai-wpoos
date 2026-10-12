# macOS PTT / paste / meetings manual matrix (P5 port)

Run before tagging a macOS release, on Apple Silicon and Intel. The PTT
mode under test is the Settings value (toggle combo = permission-free;
Right-Option hold = CGEventTap, needs Accessibility + Input Monitoring).

## PTT — toggle mode (Command+Option+Space)

| App | Arm | Stop | Text pasted | Notes |
|---|---|---|---|---|
| Safari | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Google Chrome | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Firefox | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| VSCode | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | code profile cleanup |
| Zed | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Terminal.app | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| iTerm2 | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Notes | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Slack | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |
| Zoom (call active) | ✓ / ✗ | ✓ / ✗ | ✓ / ✗ | |

## PTT — hold mode (Right Option, event tap)

| Scenario | Expected | Result |
|---|---|---|
| Hold → speak → release | transcribed + pasted | ✓ / ✗ |
| Hold → release with Shift | raw passthrough | ✓ / ✗ |
| Double-tap | hands-free toggle; own release does NOT end it; next press finalizes | ✓ / ✗ |
| Esc while recording | cancel, overlay idle | ✓ / ✗ |
| Hold Option + Arrow key (Safari) | **combo passes through**: cursor moves, dictation continues, text pastes at release | ✓ / ✗ |
| Hold Option + char (VSCode) | Option-char reaches the editor (no swallowed modifier) | ✓ / ✗ |
| Option held > 5 s | no stuck-modifier behavior after release | ✓ / ✗ |
| Quit app while holding PTT | keyboard usable immediately (no stuck Option) | ✓ / ✗ |
| Permissions revoked mid-session | tap goes silent → Diagnostics shows stale heartbeat; combo fallback works | ✓ / ✗ |

## Paste

| Scenario | Expected | Result |
|---|---|---|
| Clipboard had text before dictation | restored after paste (~180 ms) | ✓ / ✗ |
| Accessibility denied | readable error; History copy still works | ✓ / ✗ |
| Terminal / iTerm2 | Cmd+V paste works | ✓ / ✗ |

## Meetings (ScreenCaptureKit sidecar)

| Scenario | Expected | Result |
|---|---|---|
| macOS 14.2+: record during a call | picker appears; system audio + mic captured | ✓ / ✗ |
| Screen Recording denied | meeting runs mic-only (system_audio = false in summary) | ✓ / ✗ |
| > 5 min meeting | chunked + stitched correctly (parakeet TDT limit) | ✓ / ✗ |
| Stop recording | sidecar process exits (no orphan `nvoos-audio-helper`) | ✓ / ✗ |

## Overlay

| Scenario | Expected | Result |
|---|---|---|
| Stage Manager active | overlay visible above other apps, never steals focus | ✓ / ✗ |
| Multiple Spaces | overlay follows the active Space | ✓ / ✗ |
| External display | overlay renders on the active display | ✓ / ✗ |
