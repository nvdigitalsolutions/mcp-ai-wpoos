# 071a — Video/Media UI: File Picker, Transcript Display, and Summary — Implementation Plan

**Status:** Implemented
**Date:** 2026-10-13
**Related:** Proposal 071 (`071-video-input-extended-cognition-proposal.md`), `071-video-input-extended-cognition-implementation-plan.md` (backend — shipped), `examples/nvoos-dictate`

## Context

Proposal 071's backend (M1–M5) is shipped: `transcribe_media` /
`summarize_media` commands, `media://progress` events, `DecodedMedia` demux,
chunking with context carry-over, and `CleanupProvider::summarize`. The `ipc.ts`
bindings exist. What's missing is the **UI**: no file picker, no
transcript/summary display, no history persistence. This plan closes that gap.

## Research findings

| Finding | Source | Consequence |
|---|---|---|
| `tauri-plugin-dialog` v2 file picker is available from **both** JS and **Rust**; Rust needs `DialogExt` + `app.dialog().file().pick_file()` | [Tauri dialog docs](https://v2.tauri.app/plugin/dialog/), [SO](https://stackoverflow.com/questions/79153464/how-to-use-dialog-in-tauri-v2) | Use the **Rust-side** picker behind a typed IPC command, preserving the app's "no shell / no fs access in the webview" invariant |
| The plugin's permission to gate is `dialog:allow-open` | [Stuffbucket skills](https://stuffbucket.github.io/skills/catalog/tauri-plugins-dialog/), Tauri docs | Add `dialog:default` (or `allow-open`) to the `main` capability only |
| The app's existing capabilities are deliberately minimal (`core:default`, `opener:default` — no fs/shell) | `capabilities/main.json` | Do **not** grant the webview fs access; keep file selection in the Rust core |
| The webview currently has no file-picker at all; `transcribeFile` was exposed in `ipc.ts` but never wired to a button | `src/views/*` | Add the picker as the new entry point |

## Design

1. **Rust-side picker command** `pick_media_file` → opens a native open-file
   dialog (with audio/video filters) and returns the chosen path (or `None`).
   Keeps privileged fs access out of the webview.
2. **`transcribe_media` already runs fully in Rust** (decode + chunk +
   transcribe + `media://progress`). No change needed beyond the picker.
3. **UI (`Home.tsx`)**: a "Transcribe media…" button → `pick_media_file` →
   `transcribeMedia` → progress bar (`onMediaProgress`) → show transcript +
   a "Summarize" button → `summarizeMedia` → show summary.
4. **History (`History.tsx`)**: persist media results so they appear in local
   history. This needs the deferred DB work: a `media` column +
   `insert_transcript` already exists; media transcription should also write a
   history row.
5. **DB**: extend `insert_transcript` with an optional `media` JSON (container +
   duration + source) column (one additive migration), surfaced on `TranscriptRow`.

## Result (implemented)

- `src-tauri/Cargo.toml` + `lib.rs`: added `tauri-plugin-dialog`, registered
  `.plugin(tauri_plugin_dialog::init())` and `ipc::pick_media_file`.
- `capabilities/main.json`: `dialog:default`.
- `src-tauri/src/ipc.rs`: `pick_media_file` command (filters mp4/mov/mkv/webm/
  m4a/mp3/wav/flac/ogg), returns `Option<String>`.
- `src-tauri/src/storage/`: `media` column + `insert_transcript` gains a
  `media` arg; `TranscriptRow.media`; `list_transcripts` returns it.
- `src-tauri/src/ipc.rs` `transcribe_media`: persists a history row with media
  metadata.
- `src/lib/ipc.ts`: `MediaTranscription` + `api.pickMediaFile`, `TranscriptRow.media`.
- `src/views/Home.tsx`: media transcript/summary panel + progress.
- `src/views/History.tsx`: render `media` badge.

## Verification

`cargo fmt --check`, `cargo clippy --all-targets -- -D warnings` (default +
`mcp`), `cargo test`, `npm run build`. Rebuild the debug exe.
