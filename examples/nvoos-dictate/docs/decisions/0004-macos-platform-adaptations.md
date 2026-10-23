# ADR 0004 — macOS platform adaptations (P5 port)

**Status:** Accepted (implemented behind `cfg(target_os = "macos")`)
**Date:** 2026-10-12
**Plan:** `docs/macos-port-plan.md` (phases M0–M8)

## Context

The macOS port needs the same product on a fundamentally different
permission and API landscape: TCC-gated capabilities, no in-process loopback
capture, Carbon hotkeys that cannot express modifier-only PTT, and
notarization for distribution. These decisions shaped the port.

## Decisions

1. **Minimum OS = macOS 13.0 (Ventura).** ScreenCaptureKit's audio capture
   is the floor for meeting system audio; a 12.x branch would only add a
   "meetings unsupported" matrix row. Enforced via
   `bundle.macOS.minimumSystemVersion`.

2. **Permission-free PTT default.** The default PTT mode on macOS is
   `toggle` via `tauri-plugin-global-shortcut` (`Command+Option+Space`) —
   zero TCC grants. Right-Option hold-PTT (`ptt_mode = "hold"`) is opt-in
   through a CGEventTap and requires Accessibility + Input Monitoring, the
   latter being the one macOS permission with no one-click prompt. Rationale:
   dictation must work on first launch with zero grants.

3. **CGEventTap with standalone-only swallow.** The tap swallows the PTT
   key-down optimistically; if any other key arrives while held, a synthetic
   PTT key-down is re-injected and everything passes through (Option-combos
   are common on macOS — Option+arrow etc. must keep working). All decision
   logic lives in the platform-free `hotkey/tap_state.rs` state machine;
   the tap shell is a thin translation layer. Swallowing uses core-graphics
   0.23's null-event workaround (returning `None` re-posts the original).

4. **System audio via a bundled Swift sidecar.** ScreenCaptureKit is
   Swift-only; a virtual audio driver cannot be bundled. `mac-helper/`
   builds `nvoos-audio-helper`, embedded via `bundle.externalBin` and
   speaking NDJSON on stdout (mono 16 kHz Int16 PCM chunks) —
   `meetings/sidecar_protocol.rs` decodes. Meetings fall back to mic-only
   when the helper or the Screen Recording grant is missing.

5. **Paste via CoreGraphics (`CGEventPost` Cmd+V), not enigo.** enigo's
   macOS path has maintenance gaps; the `PasteBackend` trait isolates the
   choice. Paste requires Accessibility; without it, `paste()` errors
   readably and History remains the copy source.

6. **Foreground-app detection via objc2/NSWorkspace** (`frontmostApplication`
   → bundle identifier), mapped to `AppCategory` by a platform-free
   `from_bundle_id` table so the mapping is unit-tested on every OS.

7. **Hardened runtime, unsandboxed, notarized DMGs.** App Sandbox is
   incompatible with global hotkeys, event taps, the sidecar child process,
   and keychain ACLs. Distribution therefore requires notarization (Apple
   Developer Program) rather than the Mac App Store. Per-arch DMGs first;
   universal binary was the fast-follow and has shipped (single
   arm64+x86_64 DMG via `universal-apple-darwin` — see macos-port-plan.md
   M7.5).

## Consequences

- Four TCC grants surfaced in Settings → Permissions with detection,
  request, and System Settings deep links; every denial degrades gracefully.
- macOS code is `cfg(target_os = "macos")`-gated with target-scoped
  dependencies, so Windows/Linux builds are untouched (verified by the
  existing CI matrix plus the new `macos-latest` job).
- The Swift helper compiles and smoke-tests (`--check`) in CI on every
  push; its `.build/` tree is gitignored.
