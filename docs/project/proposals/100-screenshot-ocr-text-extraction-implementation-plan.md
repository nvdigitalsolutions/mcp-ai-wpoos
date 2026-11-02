# 100 — Screenshot OCR (Screen-to-Text Snippet): Implementation Plan

**Status:** Implemented (M0–M4 core; see implementation notes at the end)
**Date:** 2026-11-02
**Related:** `100-screenshot-ocr-text-extraction-proposal.md`, Proposal 067/068/071/073/075/076, `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Decisions (recommended; owner confirmation needed)

| # | Decision | Recommendation | Rationale |
|---|---|---|---|
| D1 | Snippet hotkey transport | **`tauri-plugin-global-shortcut`** (already a dependency), not the PTT `win_hook` | A snippet trigger is a **one-shot chord** (Win+Shift+T). The low-level hook exists for PTT *hold* semantics + key-swallowing; a chord needs neither, and touching the swallow discipline for a new key class is the single riskiest thing we could do. The plugin already serves macOS toggle PTT — zero new deps. |
| D2 | Windows default engine | **Windows.Media.Ocr (WinRT)** via the existing `windows` crate; ONNX RapidOCR as fallback | Zero-download default, identical engine to PowerToys Text Extractor, per-word boxes for word-click. WinRT async interop is the only unknown — bounded by the M0 spike, with ONNX as the guaranteed fallback (D2f) |
| D3 | macOS engine + capture | **Extend the existing `nvoos-audio-helper` Swift sidecar** with `screenshot` + `ocr` subcommand modes | Screen Recording TCC is already granted for meetings; `SCScreenshotManager` (macOS 14+) + `VNRecognizeTextRequest` are the sanctioned native stack; zero new Rust crates, zero new entitlements, zero new externalBin entries |
| D4 | Portable/multilingual backend | **Hand-rolled RapidOCR (PP-OCR det + rec + dict) on the pinned `ort` rc.9**, mirroring the vision module's letterbox+NMS discipline | Avoids `rapid-ocr-rs` (pulls its own ort — conflicts with the rc.9 pin). Reuses `ndarray16`, `image`-crate PNG encode, and the model-registry download pattern verbatim |
| D5 | Overlay transport | **Scaled preview data-URI** to the webview for *display only*; the full-resolution frame **stays in Rust memory** for cropping | Keeps the frozen frame off disk entirely (privacy bright line). CSP already allows `img-src data:` |
| D6 | Selection UX | Clone the Text Extractor vocabulary: drag = select, **Shift+drag = move**, release = resize, **Esc = cancel**, right-click = cancel; optional single-word click (M4b) | De-facto industry standard (PowerToys/TextSniper/NormCap); nothing to invent |
| D7 | Feature gating | `snippet` (capture + selection + platform-native engines; no new deps) and `snippet-onnx = ["snippet", "dep:ort", "dep:ort-sys", "dep:ndarray16", "dep:image"]` | House convention: every surface is cfg-gated and reports `available() == false` when absent. The ONNX backend shares the ort pin with `vision` |
| D8 | Cleanup for OCR text | **Separate `cleanup::run_ocr_deterministic`** (own small rule set + golden tests), never the speech rule chain | Speech rules (fillers, numbers-as-words, capitalization) would corrupt OCR output. OCR gets: whitespace collapse, reading-order line joining, artifact stripping |
| D9 | VLM fallback | Opt-in toggle reusing `LocalOpenAiProvider::describe_crop`-style multimodal path | Zero new transport; local-only (LM Studio/Ollama) invariant already enforced |

**Approval gates before M1:** D1 (hotkey transport), D2/D2f (Windows engine + fallback posture), D5 (overlay transport), D7 (feature names), D9 (VLM scope). All other decisions are implementation detail.

## 2. Key architectural findings (shape the whole design)

### 2.1 The snippet overlay is a *different kind of window* than the dictation capsule

`ensure_overlay` builds the capsule with `.focused(false)` — a focusless
state indicator with `core:default` capability only. The snippet overlay is
the inverse: a **pointer-owning, full-screen, transparent, always-on-top**
window that renders the frozen frame and reports a selection. It needs:

- its own `WebviewWindowBuilder` (label `"snippet"`), `.focused(true)`,
  `.visible(false)`, `.decorations(false)`, `.transparent(true)`,
  `.always_on_top(true)`, `.skip_taskbar(true)`, sized to the **virtual
  screen** (not the primary monitor);
- its own capability file `capabilities/snippet.json` mirroring
  `overlay.json` (`core:default` — application commands and events are
  permitted by default; the window can never touch network/fs/clipboard
  itself);
- a `?window=snippet` route in `App.tsx` beside the existing
  `?window=overlay` branch, rendering `views/Snippet.tsx`;
- **pointer-event discipline**: the window must NOT set
  `set_ignore_cursor_events(true)`; the dictation capsule never takes
  focus, the snippet overlay always does while visible.

### 2.2 The frozen frame never leaves Rust memory at full resolution

A 4K virtual screen BGRA buffer is ~33 MB; PNG over an event is MBs and
still not what the crop needs. Design:

1. Hotkey → Rust `capture.rs` freezes the virtual screen into a BGRA
   buffer (GDI `BitBlt`/`GetDIBits` on Windows; `screenshot` sidecar
   command returning base64 PNG on macOS, decoded once).
2. Rust builds a **scaled preview PNG** (longest edge ≤ 2560 px, JPEG-
   quality threshold for size) and emits `snippet://frame` with the
   data-URI + scale factors.
3. `views/Snippet.tsx` renders it full-bleed behind a dim mask; mouse
   events are mapped back through the scale factors and reported as a
   single `snippet_region_selected { x, y, w, h }` (virtual-screen
   coordinates) — the webview never sees more than the preview.
4. Rust crops the **full-res** buffer with that rect (small regions get a
   2× nearest upscale before OCR — the industry-standard small-text fix,
   per Text Grab), encodes a PNG in memory, and runs the engine on the
   `nvoos-snippet` worker thread.
5. Buffer is dropped on commit/cancel. **Nothing ever touches disk.**

### 2.3 Windows OCR from Rust: blocking `.get()` on the worker thread is the plan

`OcrEngine::RecognizeAsync(SoftwareBitmap)` returns `IAsyncOperation`; the
`windows` crate exposes it. Rather than building an async WinRT bridge, the
`snippet` controller runs recognition on its own worker thread and **blocks
on the operation** (`spawn_blocking` semantics — the worker already exists
for engines). A `SoftwareBitmap` is created from the BGRA buffer via
`CreateCopyFromBuffer` (`BitmapPixelFormat::Bgra8`). WinRT has no
apartment requirement for `OcrEngine` (agile), but the spike verifies this
on the reference PC. Language: `OcrEngine::TryCreateFromLanguage` /
`AvailableRecognizerLanguages` mapped from `Settings.snippet.language`;
"no language pack installed" surfaces Text Extractor's exact message and
**offers the ONNX download** instead of dead-ending (differentiator).

### 2.4 macOS sidecar: additive subcommands, untouched streaming mode

The helper (`mac-helper/Sources/nvoos-audio-helper/`) currently has
`capture` (long-lived audio stream) and `--check`. Snippet needs
**one-shot** modes — process-per-shot is ~50–100 ms and keeps the streaming
protocol untouched:

```
nvoos-audio-helper screenshot            → {"type":"hello",...} {"type":"image","png":"<b64>","w":…,"h":…} {"type":"stopped"}
nvoos-audio-helper ocr --png <b64> --langs en,de
                                         → {"type":"hello",...} {"type":"ocr_result","text":…,"words":[{"string":…,"x":…}]} {"type":"stopped"}
```

- `screenshot` uses `SCScreenshotManager.captureImage(with:configuration:
  completionHandler:)` (macOS 14+; the app's floor is 13.0, so macOS 13
  falls back to `CGWindowListCreateImage` with a deprecation warning
  suppressed — noted, not blocked).
- `ocr` uses `VNRecognizeTextRequest` with `recognitionLevel = .accurate`,
  `usesLanguageCorrection = true`, `recognitionLanguages` from args.
- **No new entitlements**: Screen Recording TCC (already declared and
  already user-granted for meetings) covers screenshots; Vision OCR is
  permission-free. `entitlements.plist`, Info.plist, externalBin list, and
  the CI notarization flow are unchanged — the helper binary is simply
  extended and re-signed with the app.
- The Rust side gets a `snippet/macos_sidecar.rs` client mirroring
  `meetings/` sidecar invocation but for one-shot request/response lines;
  the NDJSON codec in `meetings/sidecar_protocol.rs` gains additive frame
  variants (`Image`, `OcrResult`, `OcrWord`) — **protocol version stays 1**,
  new frame types only, `parse_line` stays tolerant (unit tests extended,
  existing tests untouched).

### 2.5 The ONNX backend is a two-stage pack, not a single model file

`vision/models.rs` catalogues single `.onnx` files. RapidOCR needs **per
language**: one shared `det.onnx` (DBNet) + `rec.onnx` + `dict.txt` per
language pack (PP-OCRv4/v5). `snippet/models.rs` therefore defines a
`SnippetOcrPack` (id, description, size_mb, license, files[] with url +
sha256 + file_name), and `is_installed` checks **all files of the pack**.
Download reuses the vision downloader discipline (streaming, SHA-256,
atomic rename, `force` restart, measured-hash logging when unpinned) —
per-file, with pack-level progress. Packs pinned in M3 with measured
SHA-256 (same "pin it after review" policy as `yolov11n`). Licenses:
PP-OCR model weights are Apache-2.0 → add a NOTICE.md line (alongside the
YOLO AGPL note).

## 3. Work items

### M0 — De-risk spikes (go/no-go gate for D2/D3)

- [ ] `src-tauri/examples/snippet_ocr_probe.rs` (new example, no feature
      flags required beyond `snippet` scaffolding — a standalone spike):
      (a) **WinRT leg:** load a fixture PNG, create `SoftwareBitmap`
      (Bgra8), run `OcrEngine::RecognizeAsync` with blocking `.get()` on a
      spawned thread, print words + boxes. (b) **ONNX leg:** run a pinned
      RapidOCR det+rec pair on `ort` rc.9 (reuse the vision probe's
      letterbox + `ort::inputs!` shapes), print text. (c) **macOS leg**
      (CI only): build the extended helper and `--check` the new
      subcommands.
- [ ] Outcome recorded in the plan: (a) passes → WinRT default (D2 holds);
      (a) fails → Windows default becomes ONNX (D2f), WinRT kept as a
      follow-up.
- [ ] `windows` crate feature list for WinRT namespaces
      (`Windows.Media.Ocr`, `Windows.Graphics.Imaging`,
      `Windows.Globalization`) confirmed and written into the Cargo.toml
      comment.

### M1 — `snippet` module skeleton: capture + selection + wiring

**Cargo:**
- [ ] `Cargo.toml`: `snippet = []`,
      `snippet-onnx = ["snippet", "dep:ort", "dep:ort-sys", "dep:ndarray16", "dep:image"]`.
      Windows target: add `Win32_Graphics_Gdi` (+ DPI/shutdown features if
      needed) to the existing `windows` dep; WinRT features per M0.
- [ ] Example registration `[[example]] name = "snippet_ocr_probe"` if the
      spike graduates.

**New module `src-tauri/src/snippet/`** (mirrors `vision/` layout:
`mod.rs` always compiled with `RegionRect`, `SnippetResult`,
`available()`):
- [ ] `capture.rs` — `CaptureBackend` trait + `freeze() -> FrozenFrame
      { bgra, width, height, scale }`:
      - Windows: `GetDC(NULL)` → memory DC → `BitBlt(SRCCOPY | CAPTUREBLT)`
        over `SM_XVIRTUALSCREEN/SM_YVIRTUALSCREEN/SM_CXVIRTUALSCREEN/
        SM_CYVIRTUALSCREEN` (multi-monitor negative-coordinate safe).
        Verify/force PerMonitorV2 DPI awareness at startup (`SetProcessDpi
        AwarenessContext`) — Tauri's default awareness must be checked in
        this milestone, not assumed.
      - macOS: sidecar `screenshot` command → decode PNG → BGRA. Windows
        and macOS implementations are `#[cfg]`-split like `paste/`.
- [ ] `select.rs` — `SnippetSelectController`: `arm()` shows the snippet
      window full-virtual-screen, emits `snippet://frame` (preview data-URI
      + scale factors + virtual-screen origin); consumes
      `snippet_region_selected` / `snippet_cancel`; owns the window hide +
      focus-restore (reuse `util` foreground helpers the paste path uses).
      Holds the `FrozenFrame` for the window's lifetime; drops it on
      cancel/commit (privacy contract).
- [ ] `ocr.rs` — `OcrEngine` trait
      (`recognize(&RegionImage, &OcrOptions) -> AppResult<SnippetText>`)
      + dispatch enum `auto | winrt | sidecar | onnx | vlm_fallback` +
      `region_to_png()` helper (in-memory crop, 2× upscale for small
      rects) + `words()` for word-click mode.
- [ ] `controller.rs` — `SnippetController`: single-flight state machine
      (Idle → Selecting → Recognizing → Done) on the `nvoos-snippet`
      worker thread; stats struct (`SnippetStats`: `captures`,
      `recognitions`, `last_ocr_ms`, `errors`, `last_error`); `available()`.
- [ ] `state.rs` — `AppState.snippet: Mutex<Option<Arc<SnippetController>>>`
      beside `vision`/`agent`; `snippet_available()`, `reapply_snippet()`.
- [ ] `lib.rs` — `pub mod snippet;` (cfg-gated where needed, always
      compiled like `vision`); `ensure_snippet_window(&handle)` mirroring
      `ensure_overlay`; register the window in `setup`.
- [ ] `tauri.conf.json` — no window entry (runtime-created like the
      overlay); **CSP unchanged** (verified: `img-src 'self' data:` already
      allows the preview data-URI).
- [ ] `capabilities/snippet.json` — mirror `overlay.json` (identifier
      `snippet`, windows `["snippet"]`, `core:default` only).
- [ ] **Hotkey (D1):** register the snippet chord via
      `tauri-plugin-global-shortcut` in `SnippetController::start` (the
      same `register_plugin_shortcut` pattern dictation uses on macOS) on
      **all** platforms. Collision guard: refuse (with a Settings error)
      when the chord equals the PTT combo; default `Win+Shift+T` on
      Windows, `Command+Shift+T` on macOS (no OS collision — Apple uses
      ⇧⌘4/5 for capture). No changes to `hotkey/win_hook.rs` or the
      swallow machinery (deliberate isolation, see skill).
- [ ] **Frontend:**
      - `src/lib/ipc.ts` — `SnippetEvent`, `snippet_region_selected`,
        `snippet_cancel`, `onSnippetEvent` (mirror `onDictateEvent`).
      - `src/views/Snippet.tsx` — full-bleed preview render, dim mask,
        drag-select + Shift-move + Esc/right-click cancel (D6), scale
        mapping; reports rect once on mouseup.
      - `src/App.tsx` — `windowKind() === 'snippet'` branch (before the
        overlay branch), no status polling.
- [ ] `ipc.rs` — `snippet_available`, `snippet_cancel` (stub until M2),
      `snippet_region_selected(rect)` (validates rect bounds against the
      frozen frame, clamps, rejects degenerate < 4×4 px); registered in
      `lib.rs` `generate_handler!` beside the vision commands.
- [ ] Unit tests: rect scale-mapping (preview ↔ virtual-screen),
      rect clamping/validation, FrozenFrame drop-on-cancel.

### M2 — Windows recognition end-to-end (reference PC)

- [ ] `ocr.rs` WinRT arm: `SoftwareBitmap` from BGRA, `OcrEngine` create
      from `Settings.snippet.language` (fallback: first available
      recognizer language), blocking recognition on the worker, map lines
      + words into `SnippetText { text, words: Vec<SnippetWord> }`.
- [ ] Language-pack surface: `list_ocr_languages` (AvailableRecognizer
      Languages) for the Settings dropdown; "No OCR languages installed"
      error string verbatim-compatible with the Text Extractor message,
      with a **"Download ONNX engine instead"** action (M4 completes it).
- [ ] `snippet_arm` flow completion: hotkey → freeze → overlay →
      selection → recognize → clipboard write (`arboard` via the existing
      paste backend's clipboard set) → `snippet://state` `done` → optional
      auto-paste (gate behind `Settings.snippet.auto_paste`, off by
      default in this milestone) → focus restore.
- [ ] `config.rs` — `SnippetConfig` + `Settings.snippet` (full field list
      in §4) with serde defaults (backward-compatible load, like
      `CameraConfig`).
- [ ] Manual verification on the reference PC: error dialog text, browser
      text, paused-video subtitle; latency < 1.5 s hotkey→clipboard.

### M3 — macOS sidecar recognition (CI-verified)

- [ ] `mac-helper`: `screenshot` and `ocr` subcommands (§2.4), Vision
      request setup, NDJSON output via the existing `Output` helper;
      `--check` extended to smoke the OCR path on a tiny embedded fixture
      string (no image needed — call Vision with a generated bitmap).
- [ ] `meetings/sidecar_protocol.rs`: additive `Image`/`OcrResult`/`OcrWord`
      frames + codec tests (existing tests untouched; version stays 1).
- [ ] `snippet/macos_sidecar.rs`: one-shot spawn + line-parse + graceful
      error mapping (Screen Recording denied → the existing permissions
      guidance, deep link to System Settings via `permissions.rs`).
- [ ] macOS 13 fallback path (`CGWindowListCreateImage`) behind a
      `SCScreenshotManager available` check.
- [ ] CI: the macOS boot-smoke workflow already builds the helper; add a
      `--check` invocation to the matrix script so the OCR path is
      exercised on the runner (screenshot capture itself is hardware/TCC
      limited on CI — treat like the existing `screencapture` caveat).

### M4 — ONNX RapidOCR backend + language packs

- [ ] `snippet/models.rs` — pack catalogue (en, and the highest-value
      second languages: de/fr/es initially; catalogue is data, extendable),
      per-file streaming download + SHA-256 + atomic rename, pack-level
      `status_list`/`is_installed`, `download(pack, force, progress)`.
      Pin hashes after first verified download (vision policy).
- [ ] `snippet/ocr_onnx.rs` (`#[cfg(feature = "snippet-onnx")]` + stub):
      DBNet det decode (boxes via threshold map — mirror the letterbox
      discipline from `vision/detect.rs`), crop + resize to rec input,
      SVTR/CTC greedy decode with `dict.txt`, word boxes from det polys.
      **crate-qualified paths** for cfg-gated siblings (skill pitfall).
- [ ] IPC: `get_snippet_models`, `download_snippet_model` (progress via
      the `media://progress`-style event pattern), registered in
      `generate_handler!`.
- [ ] Settings UI: engine dropdown (`Auto | Windows OCR (this PC) | Apple
      Vision (this Mac) | RapidOCR (ONNX) | VLM fallback` — platform-
      filtered), language dropdown (OS packs ∪ ONNX packs), model
      download rows with Re-download (vision Settings pattern).
- [ ] Diagnostics: `SnippetDiagnostics` block (engine, pack install state,
      `last_ocr_ms`, `ocr_calls`, errors) + `Diagnostics.tsx` row.
- [ ] Windows D2f activation: when WinRT fails at runtime (no pack / API
      error) and a RapidOCR pack is installed, the controller falls back
      automatically with a badge in the result capsule.

### M5 — Delivery polish: cleanup profile, VLM fallback, history

- [ ] `cleanup/` — `run_ocr_deterministic(text)` in `rules/` with its own
      rule subset (whitespace collapse, reading-order line join,
      artifact/table-border strip, trailing-noise trim) + golden tests in
      the existing cleanup golden-test style (`tests/cleanup_golden.rs`
      gets a sibling OCR fixture file, never edits the speech cases).
- [ ] `SnippetConfig.cleanup_profile` (`off | ocr | full_stage1`) —
      default `ocr`.
- [ ] VLM fallback (D9): `Settings.snippet.vlm_fallback` toggle; on low
      classical confidence (WinRT/Vision confidence or ONNX mean score
      below threshold) or manual "re-recognize with VLM" from the result
      capsule, send the in-memory crop through the existing multimodal
      provider path (`openai_shape` vision body + `LocalOpenAiProvider`),
      OCR-tuned system prompt, result badged `VLM`. Strictly local
      providers (lm_studio/ollama) for v1.
- [ ] `Settings.snippet.auto_paste` default on (the dictation-equivalent
      gesture), result capsule states `copied | pasted | no text found |
      cancelled`, history:
- [ ] `storage/migrations.rs` — **append** migration (never insert
      mid-list):
      `CREATE TABLE snippets (id, text_raw, text_clean, engine, language,
      region_w, region_h, ocr_ms, created_at)` + index.
- [ ] `Settings.snippet.retain_history` (off by default); `list_history`
      either gains a `kind` filter or a dedicated `list_snippets` IPC —
      pick the dedicated command (keeps the transcripts contract stable).
- [ ] `StatusInfo` + Home card: `snippet_available`, `snippet_engine`,
      `snippet_hotkey`.

### M6 — Verification, docs, complete-build union

- [ ] Gates (reference PC + CI — see §5).
- [ ] Complete-build union: skill "complete build" feature list gains
      `snippet,snippet-onnx`; `build-nvoos-dictate.yml` windows-bundle job
      feature args updated; skill §Build & run updated with the new
      features + the PE string-probe additions (e.g. `snippet`).
- [ ] `README.md` platform notes + feature-flag table; `NOTICE.md`
      RapidOCR model-weight licenses (Apache-2.0) beside the YOLO AGPL
      note; `examples/nvoos-dictate/docs/` ADR (record D1/D2/D5/D9).
- [ ] Proposal status: mark `100-…-proposal.md` and this plan **Implemented
      (M0–M6)** with the deferred list (word-click mode, Tesseract,
      video-OCR 100b).

## 4. Config surface (exact)

```rust
pub struct SnippetConfig {
    pub enabled: bool,             // default true
    pub hotkey: String,            // default "Win+Shift+T" (win) / "Command+Shift+T" (mac)
    pub engine: String,            // "auto" | "winrt" | "sidecar" | "onnx" | "vlm_fallback" — default "auto"
    pub language: String,          // default "" = OS default / first available
    pub vlm_fallback: bool,        // default false
    pub auto_paste: bool,          // default true
    pub cleanup_profile: String,   // "off" | "ocr" | "full_stage1" — default "ocr"
    pub retain_history: bool,      // default false
    pub vlm_min_confidence: f32,   // default 0.6 (ONNX path; native engines use their own)
}
impl Default for SnippetConfig { … }   // serde-tolerant like CameraConfig
```

`Settings` gains `pub snippet: SnippetConfig`. Settings.tsx gains a
"Screen OCR" section (hotkey, engine/language dropdowns, toggles); the
hotkey string is validated before registration (collision check vs PTT
combo, empty = disabled).

## 5. Verification (gate matrix)

```bash
cd examples/nvoos-dictate/src-tauri
cargo fmt --check
cargo clippy --all-targets -- -D warnings                  # default
cargo clippy --all-targets --features snippet -- -D warnings
cargo clippy --all-targets --features snippet-onnx -- -D warnings
cargo clippy --all-targets --features snippet,snippet-onnx -- -D warnings
cargo test                                                 # default (cleanup/sidecar/capture/selection unit tests)
cargo test --features snippet-onnx                         # ONNX decode + pack-registry tests
cargo test --features vision                               # unchanged (regression: no vision drift)
cargo test --features mcp                                  # unchanged
cd ../.. && npm run build                                  # tsc + vite (frontend)
```

Plus the native/live checks (local, not CI): `cargo test -- --ignored
snippet_capture` (real screen freeze), the `snippet_ocr_probe` example
against a fixture screenshot, and the reference-PC manual matrix in M2.
macOS: extended `--check` smoke in the boot-smoke job; TCC-gated
screenshot capture remains human-matrix (same caveat as meetings audio).

**Golden-test discipline:** the cleanup OCR profile ships with golden
fixtures on day one; the speech golden file (`cleanup_golden.rs`) is never
edited by this work — regressions there mean the plan touched the wrong
seam.

## 6. Do-not-regress list (from the skill — treat as binding)

1. **Hotkey swallow machinery** (`hotkey/win_hook.rs`, `tap_state.rs`) is
   not modified. The snippet chord rides the global-shortcut plugin;
   a git diff touching `src/hotkey/` invalidates the plan's isolation
   claim.
2. **Model download hardening** — staging/atomic/read-only-clear patterns
   from `stt/model_registry.rs` and `vision/models.rs` are copied, not
   reinvented; new pack files use `.write(!resuming)` OpenOptions (the
   documented truncation bug).
3. **`ort` rc.9 pin** — `ort-sys` stays `=2.0.0-rc.9`; the ONNX backend
   uses `ndarray16` + positional `ort::inputs!`, never named form.
4. **Sidecar protocol v1** — additive frames only; existing
   `SidecarFrame` variants and `parse_line` tolerance preserved.
5. **Overlay capsule** — `ensure_overlay` and `overlay.json` unchanged;
   the snippet window is separate.
6. **Zero-egress default** — no cloud OCR path exists; the VLM fallback
   enforces the local-http invariant of `LocalOpenAiProvider`.

## 7. Risks & mitigation (implementation-level)

| Risk | Mitigation |
|---|---|
| WinRT async/feature-name surprise (M0) | D2f pre-agreed: ONNX becomes the Windows default; feature ships either way |
| Multi-monitor mixed-DPI capture artifacts | Virtual-screen metrics + PerMonitorV2 check in M1; unit tests with negative-origin rects; manual matrix includes a mixed-DPI setup |
| Overlay focus theft from full-screen apps (games) | Always-on-top + explicit focus; Esc/right-click cancel; single-flight state machine prevents re-arm loops |
| Huge virtual screens → preview event size | Scaled preview cap (longest edge ≤ 2560); full frame stays in Rust |
| `ort` rc.9 vs RapidOCR opset drift | M0(b) spike verdict gates M4; if opset fails, pin older PP-OCRv4 exports (both in catalogue) |
| Snippet hotkey collision (PowerToys uses Win+Shift+T too) | Documented; configurable; the chord registration fails loudly if taken — never silently rebinds |
| macOS 13 (pre-SCScreenshotManager) | `CGWindowListCreateImage` fallback branch (M3) |
| Scope creep (editor, video OCR, tables) | Proposal §5 non-goals re-asserted in every milestone review |

## 8. Sequencing & effort

| Milestone | Content | Effort |
|---|---|---|
| M0 | Spikes: WinRT leg, ONNX leg, sidecar leg | 1–2 sessions |
| M1 | Module skeleton, capture, overlay, hotkey, frontend shell | 2 sessions |
| M2 | Windows WinRT end-to-end + config + Settings | 1–2 sessions |
| M3 | macOS sidecar + CI smoke | 1–2 sessions |
| M4 | ONNX packs + registry + fallback | 2–3 sessions |
| M5 | Cleanup profile, VLM fallback, history, auto-paste | 1–2 sessions |
| M6 | Gates, complete-build union, docs/ADR/NOTICE | 1 session |

Dependencies: M2/M3 parallel after M1; M4 after M0(b); M5 after M2+M4.

## 9. Open questions

1. **M4 language pack list** — which packs ship in the first catalogue cut
   (en + which 2–3)? Recommend en, de, fr, es.
2. **Word-click mode (M4b)** — full-frame OCR-first so the overlay can
   offer single-word click (Text Grab behavior). Defer or include? It
   doubles the OCR call in the common path; recommend **defer** to 100b
   unless owner wants it at launch.
3. **Auto-paste default** — recommend ON (dictation parity), but it makes
   OCR fail louder in protected-context apps. Confirm.
4. **Snippet history surface** — dedicated History tab row vs merged with
   transcripts. Recommend merged list with a badge, dedicated IPC.

## 10. Implementation notes (2026-11-02, shipped)

**M0 spikes — PASSED.** (a) WinRT leg verified live on the reference PC:
`Windows.Media.Ocr` through the `windows` crate recognized a fixture in
35 ms with per-word boxes — D2 holds, WinRT is the Windows default.
(b) ONNX leg verified live: the hand-rolled RapidOCR pipeline runs on the
pinned `ort` rc.9 (~90–120 ms/shot). Key findings baked into the code:
BGR channel order (`DecodeImage: img_mode: BGR`), class 0 = CTC blank with
`class i → dict[i-1]` decode (the official export carries one extra
GTC/NRTR-head token), exact-aspect rec width (no ×8 quantization — it
clips trailing glyphs), unclip-style box expansion, and the **monkt**
fixed-height rec export over the official dynamic-height export (the
latter produces all-blank output on ort rc.9).

**M1–M2 shipped:** `snippet/` module (capture/selection/controller), the
full-screen `snippet` window + capability, WinRT end-to-end (hotkey →
freeze → overlay → recognize → clipboard → optional auto-paste),
`SnippetConfig`, the OCR cleanup profile, snippet history (append-only
migration), Settings/Diagnostics/Home surfaces.

**M3 shipped (CI-verified):** the `mac-helper` Swift sidecar gained
one-shot `screenshot` + `ocr` subcommands (ScreenCaptureKit 14+,
`CGWindowListCreateImage` on 13, Vision `.accurate`); the NDJSON protocol
stays v1 with additive `image`/`ocr_result` frames; `--check` now smokes
Vision on a generated bitmap.

**M4 shipped:** the ONNX pack catalogue with **pinned SHA-256** (det from
the official RapidOCR ModelScope export; rec + dict from the monkt mirror
— dict byte-identical to the official `ppocrv5_en_dict.txt`), verified via
the production downloader (`snippet_ocr_probe --engine download`).

**Deferred:** VLM fallback toggle (M5), word-click mode (M4b), macOS
multi-display stitching, Tesseract. The complete-build union and the
PE-string verifier now include `snippet,snippet-onnx`.
