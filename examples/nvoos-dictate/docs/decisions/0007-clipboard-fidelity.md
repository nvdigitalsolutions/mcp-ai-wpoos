# ADR 0007 — Clipboard fidelity and restore safety

**Status:** Accepted
**Date:** 2026-11-12

## Context

Paste is implemented as "copy the text, send Ctrl+V, restore the clipboard"
(ADR 0001, `paste/clipboard_keystroke.rs`). A synthesized unicode paste was
tried and rejected: Chromium and WebView2 only honour the native Ctrl+V
accelerator, so the clipboard route is the only one that works everywhere.

The restore path had three defects:

1. **Text-only snapshot.** `clipboard.get_text().ok()` returns `Err` when the
   clipboard holds an image or a file list. That was treated as "nothing to
   restore", so a dictation silently destroyed the user's copied screenshot or
   files.
2. **No history hygiene.** Every dictation wrote one or two clipboard entries,
   filling Windows clipboard history (`Win+V`) with transient transcripts.
3. **No race guard.** The restore always ran, including when the user had
   copied something else during the paste window — overwriting their new copy.

## Decision

**Windows: capture every format as bytes.**

```
ClipSnapshot = Empty
             | Formats { items: Vec<ClipItem>, unsupported: Vec<String> }   // Windows
             | Text | Image                                                  // other platforms
             | Partial(reason)
```

The clipboard is enumerated (`EnumClipboardFormats`) and each payload is
cloned as bytes. `GlobalSize` returning zero is the discriminator: HGLOBAL
payloads always have a size, while GDI handles (`CF_BITMAP`, `CF_PALETTE`,
metafiles) are not global memory. Those are recorded in `unsupported` and
reported, never misread as data. Restore replays the same bytes, so HTML, RTF,
`CF_DIB`, `CF_HDROP` and private application formats survive intact.

Size cap: 64 MiB total across formats. Above that the snapshot is `Partial`
and nothing is restored — a half-restored rich clipboard is worse than an
honest failure.

Other platforms keep the `arboard` fallback (text, RGBA image) with its own
caps (1 MiB text, 32 MiB image). That is a documented limitation, not parity.

**History exclusion.** On Windows the transcript is written with Microsoft's
documented `ExcludeClipboardContentFromMonitorProcessing` and
`CanIncludeInClipboardHistory = 0` registered formats, so dictation does not
enter clipboard history or cloud clipboard sync. The *restore* is written
normally, because it is the user's own content.

**Sequence guard.** `GetClipboardSequenceNumber()` is read immediately after
the transcript write and again before restoring. If it changed, something else
wrote to the clipboard during the paste; the restore is aborted rather than
stomping the newer content, and the reason is recorded.

**Timing.** The settle delay is configurable (`paste.restore_delay_ms`,
default 250 ms). 180 ms — the previous fixed value — is too short for RDP and
large IDEs, where the target reads the clipboard after the restore has already
run and pastes the wrong thing.

**Escape hatch.** `paste.restore_clipboard = false` leaves the transcript on
the clipboard.

## Consequences

- A copied image, file list, or text block survives a dictation.
- `Win+V` no longer fills with transcripts.
- Pasting still works when the target is slow, and the failure mode of a very
  slow target is now a configurable knob rather than a magic number.
- On Windows, rich and private formats are preserved instead of being
  downgraded to text. Formats whose payload is a GDI handle rather than a
  global memory block cannot be copied as bytes; they are counted
  (`partial_formats`) and surfaced in Diagnostics, so the loss is visible
  rather than silent.
- macOS/Linux still restore text and images only (`arboard`). Closing that gap
  needs `NSPasteboard` item/type enumeration and is tracked separately.
- **Privacy constraint:** a snapshot may contain a password. It lives in memory
  for the duration of one paste, is never logged, never serialised, and never
  enters the diagnostics payload — only its `kind` string does.
- A unicode-typing escape hatch (never touching the clipboard) is **not**
  implemented. It is slower and does not work in every target, and it would
  need interactive validation this change could not perform. Tracked as a
  follow-up.
