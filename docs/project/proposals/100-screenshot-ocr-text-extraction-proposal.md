# Proposal 100 — Screenshot OCR: Extract Text from Any Screen Region (Snippet Tool for the NV oOS Dictation Companion)

**Status:** Implemented (M0–M4 core: WinRT + ONNX engines verified, capture/selection/overlay/hotkey/delivery shipped; macOS Vision via the Swift sidecar is written and CI-verified; VLM fallback + word-click mode deferred)
**Date:** 2026-11-02
**Related:** Proposal 067 (dictation companion), 068 (macOS port), 071 (video/file input), 073 (live camera), 075 (local object detection/tracking), 076 (VLM label polish), `.agents/skills/mcp-ai-wpoos-dictation-app`, `examples/nvoos-dictate`

---

## 1. Executive summary

The dictation companion captures **sound** and turns it into text. This
proposal adds the natural companion capability: capture **pixels** and turn
them into text. A global hotkey (industry default **Win+Shift+T** on
Windows) freezes the screen under a full-screen selection overlay — exactly
like the Windows Snipping Tool — the user drags a region, and the text in
that region is recognized **entirely on-device**, cleaned through the app's
existing pipeline, placed on the clipboard, and optionally auto-pasted into
the focused app.

The feature is *not* a screenshot saver. It is the OCR equivalent of
push-to-talk: hotkey → select → text lands where you were typing. It closes
the same "trapped text" gap the dictation flow closes for voice (error
dialogs, video subtitles, PDFs, images, apps that block copy), and it reuses
an unusual amount of the app's existing machinery: the global-hotkey layer,
the overlay window, the paste module, the Stage-1 cleanup rules, the ONNX
Runtime stack pinned for vision (Proposal 075), the model-registry download
pattern, and — on macOS — the existing ScreenCaptureKit sidecar and Screen
Recording permission already granted for meetings.

## 2. Problem statement

Text is routinely trapped in places the cursor cannot select:

- **App surfaces** — error dialogs, toast notifications, game UIs, canvases,
  Electron apps that disable copy.
- **Media** — paused video (subtitle burn-in, tutorial code on screen),
  live streams, webinars.
- **Documents & images** — scanned PDFs, screenshots in chat, receipts,
  slides, ID cards, code in images.

Today the user's options are: retype it (slow, error-prone), or reach for a
separate tool (PowerToys Text Extractor, TextSniper, ShareX OCR, or the
Windows 11 Snipping Tool's Text Actions) that exists **outside** the app's
workflow — no cleanup pipeline, no paste integration, no history, no
multi-engine choice, no VLM fallback for hard cases.

For an app whose entire identity is "hotkey → text appears where I'm
typing", OCR-from-screen is the same gesture in the visual modality. The
gap is user-visible and the architecture is already 80% in place.

## 3. Industry research summary

### 3.1 UX conventions — what "snippet OCR" means in 2026

The interaction model is fully standardized across platforms; all major
tools converge on the same flow, and we should copy it rather than invent:

| Tool | Platform | Hotkey | Flow | Output |
|---|---|---|---|---|
| **PowerToys Text Extractor** ([Microsoft Learn](https://learn.microsoft.com/en-us/windows/powertoys/text-extractor)) | Windows | **Win+Shift+T** (default) | Full-screen overlay → click-drag region → **Shift = move region**, resize on release, **Esc = cancel** | Clipboard (no file) |
| **Text Grab** ([TheJoeFin/Text-Grab](https://github.com/TheJoeFin/Text-Grab)) — the basis of Text Extractor | Windows | configurable | Four modes: Full-Screen region, movable Grab Frame, Edit window, Quick Lookup | Clipboard, optional cleanup actions |
| **Windows 11 Snipping Tool "Text Actions"** | Windows (Win11 only) | Win+Shift+S | Screenshot → Text Actions button → OCR | Copy / redact |
| **TextSniper** (the Mac reference Text Grab points to) | macOS | e.g. ⌘⇧2 | Hotkey → drag region | Clipboard, no file created |
| **NormCap** ([dynobo/normcap](https://github.com/dynobo/normcap)) — cross-platform open source | Win/mac/Linux | configurable | Overlay with visible border → drag region | Clipboard + notification; Tesseract by default |
| **CleanShot / Shottr / ShareX** | Win/mac | configurable | Screenshot-first, OCR as a post-action | Clipboard / share |

**Synthesis (binding design principles):**

1. **One gesture: hotkey → drag → clipboard.** No file is created, nothing
   is saved by default. The clipboard is the universal hand-off.
2. **Overlay, not window.** The selection surface is a full-screen
   transparent overlay over a *frozen* screenshot. Text Extractor's
   Shift-to-move / drag-to-resize / Esc-to-cancel vocabulary is the de-facto
   standard and should be reproduced verbatim.
3. **Optional auto-paste** is the power-user differentiator (Text Grab's
   "try to insert text into the active app" post-grab action) — it is what
   turns OCR into a dictation-equivalent gesture, and the dictate app
   already owns this via `paste/`.
4. **Post-grab cleanup exists but is minimal by default.** Text Grab offers
   post-grab actions (trim lines, dedupe, case fixes); the app's Stage-1
   deterministic cleanup is a superset waiting for an OCR profile (§5.6).
5. **A visible result signal.** NormCap shows a notification; the dictate
   app already has the overlay capsule for `done`/`error` states — reuse it.

### 3.2 OCR engine landscape

#### 3.2.1 Windows OCR (WinRT `Windows.Media.Ocr`) — the Windows default

- The engine **built into Windows 10/11**, the exact engine behind
  PowerToys Text Extractor and Text Grab's default mode
  ([Text Grab README](https://github.com/TheJoeFin/Text-Grab/blob/main/README.md)).
- **Offline, free, no model download**, excellent on UI/screen text (its
  training domain), per-word bounding boxes.
- Languages come from Windows **OCR language packs** (`Language.OCR*`
  Windows Capabilities — `Get-WindowsCapability -Online | Where-Object
  {$_.Name -Like 'Language.OCR*'}`), ~26 languages, installable per-user;
  default follows system keyboard language. Text Extractor surfaces
  "No OCR languages are installed" when none exist — we mirror that.
- **Callable from unpackaged desktop apps** (Text Grab, PowerToys prove
  this; the older "MSIX-only" claim in blog posts is outdated).
- Weaknesses: language list is fixed by Microsoft, no handwriting strength,
  and calling async WinRT from Rust is real but bounded work (§7 risks).

#### 3.2.2 PaddleOCR / RapidOCR ONNX — the portable cross-platform engine

- [RapidOCR](https://github.com/RapidAI/RapidOCR) converts PaddleOCR's
  models to ONNX for exactly this class of terminal-device OCR; the Rust
  reference implementation is [rapid-ocr-rs](https://github.com/jinghu-moon/rapid-ocr-rs)
  (detection + recognition + CTC decode over ONNX Runtime).
- **PP-OCRv5** ([arXiv:2603.24373](https://arxiv.org/html/2603.24373v1)):
  ~5M-parameter specialized model, **+13 points end-to-end over PP-OCRv4**
  (53.0% → 80.1% weighted average on the complex internal set), big gains
  on handwriting and multilingual text, **106 languages** — with English,
  Thai and Greek getting dedicated improved models (82–89% recognition).
  A PP-OCRv6 paper (34.5M params) exists; v5 remains the stable, widely
  distributed weight set (monkt/paddleocr-onnx on HF hosts det v5 + rec +
  dict per language).
- Pipeline: DBNet text-detection ONNX → per-box crop → SVTR recognition
  ONNX (+ optional angle-classifier), CTC decode with a dictionary file.
- **Strategic fit:** the app already pins `ort = 2.0.0-rc.9` + the
  `ndarray16` alias for vision (Proposal 075), and hand-rolled the YOLO
  letterbox + NMS rather than pulling a crate. Hand-rolling the two-stage
  Paddle pipeline in a new `snippet/ocr_onnx.rs` is the **same house
  discipline** and avoids dependency drift (`rapid-ocr-rs` pulls its own
  ort version — a conflict with the rc.9 pin).
- Model weights (~5–20 MB per language) download at runtime through the
  existing model-registry pattern (streaming SHA-256, atomic rename,
  `%APPDATA%/nvoos-dictate/models/`), exactly like `yolov11n`.

#### 3.2.3 Apple Vision (`VNRecognizeTextRequest`) — the macOS default

- The engine behind **Live Text** ([Apple docs](https://developer.apple.com/documentation/vision/vnrecognizetextrequest)):
  on-device, no model download, `recognitionLevel = .accurate | .fast`,
  `recognitionLanguages`, `usesLanguageCorrection`, word/line bounding
  boxes. Widely praised as best-in-class for screen text; the basis of
  community tools like [macOCR](https://github.com/ragaeeb/macOCR) and
  `ocrmac`.
- The dictate app's macOS port **already ships a Swift sidecar**
  (`mac-helper/`, ScreenCaptureKit system-audio capture, NDJSON over
  stdout) and the **Screen Recording TCC permission is already in the
  permission model** for meetings (ADR 0004). Extending that sidecar with
  a screenshot+OCR command is the zero-new-Rust-dependency macOS path.

#### 3.2.4 Tesseract — evaluated, rejected as primary

- Industry legacy default (NormCap default; Text Grab alternative).
  100+ languages, but [published comparisons](https://intuitionlabs.ai/articles/non-llm-ocr-technologies)
  consistently find it is **not state-of-the-art on screen/UI text**, curved
  text, or handwriting, and its MSVC C++ build is a known cost — the repo
  already pays CMake tax for whisper.cpp and should not add a second.
- Position: optional future backend via `tesseract-rs` if a niche language
  is requested; **not** in v1.

#### 3.2.5 VLM OCR (local multimodal LLM) — the opt-in hard-case fallback

- Qwen2.5-VL-class models OCR noisy screenshots (stylized fonts, busy
  backgrounds, some handwriting) better than any classical engine, at the
  cost of seconds and VRAM. The app **already has the transport**: the
  multimodal `image_url` path built for vision polish (Proposal 076,
  `LocalOpenAiProvider::describe_crop` → LM Studio / Ollama, no cloud
  egress).
- Position: opt-in fallback ("OCR via VLM" toggle) that runs when the
  classical engine's confidence is low or the user explicitly asks. Never
  the default, mirroring the vision-polish opt-in pattern.

### 3.3 Screen-capture standards

**Windows:**

| Route | Notes | Verdict |
|---|---|---|
| **GDI `BitBlt`/`GetDIBits`** of the virtual screen | The classic one-shot path; used by `cleanshot-w` ([PR #36](https://github.com/Squidys-Tools/cleanshot-w/pull/36)) and countless utilities; needs PerMonitorV2 DPI awareness + `SM_XVIRTUALSCREEN` multi-monitor metrics | **Primary** — right cost/benefit for a frozen one-shot |
| **Windows Graphics Capture API** | Modern, per-window capable, but requires Direct3D interop for frame readback — heavyweight for one-shot | Revisit if per-window capture (no overlay) is wanted later |
| **DXGI Desktop Duplication** | High-rate, excludes protected content | Not needed for a static shot |

The `windows` crate (0.58) is already a Windows target dependency — only
GDI features need adding (`Win32_Graphics_Gdi`). Capture happens in the Rust
core on the hotkey thread: freeze the full virtual screen to an in-memory
BGRA buffer (never to disk), then show the overlay *over the frozen image*
so the selection is pixel-perfect even if the screen animates underneath.

**macOS:** `CGWindowListCreateImage` is deprecated in macOS 14;
**ScreenCaptureKit's `SCScreenshotManager.captureImage(with:configuration:
completionHandler:)`** is the sanctioned replacement ([Apple docs](https://developer.apple.com/documentation/screencapturekit),
[usage](https://stackoverflow.com/questions/78847211/how-to-capture-screenshot-at-screen-resolution-with-screencapturekit)).
It lives in the sidecar, which already holds the Screen Recording grant.

### 3.4 Privacy & security standards

- **Local-only is the table stakes:** Text Grab's README leads with "All
  OCR runs entirely on your device — no internet connection, no cloud
  service, and no per-use cost." PowerToys Text Extractor is identical.
  The dictate app's zero-egress default (no screenshot leaves the machine
  in any mode) is the same bright line.
- **Screenshots are more sensitive than audio:** transient by default —
  the frozen frame lives in memory for the selection's lifetime and is
  dropped on Esc/selection-commit; no disk persistence (a future
  `privacy.retain_snippet` flag would mirror `retain_meeting_audio`, off
  by default).
- **Permission surfaces:** on Windows, screen capture needs no OS
  permission (the user's action is the consent). On macOS, Screen
  Recording TCC is already granted for meetings — the OCR flow inherits it
  and the existing Settings → Permissions tab already displays it. Pasting
  into the foreground app reuses the existing Accessibility permission
  handling.
- **Output hygiene:** OCR text is untrusted input; it goes through the
  same sanitize-on-entry / escape-on-exit discipline as dictation text
  before it reaches the clipboard or a paste.

### 3.5 Why build this instead of pointing users at the Snipping Tool?

Honest competitive framing, written into the proposal deliberately:

1. Snipping Tool Text Actions and PowerToys are **Windows-11-only** (and
   PowerToys is an extra install); the dictate app serves Windows 10 and
   **macOS** where the equivalent is a paid app (TextSniper).
2. **Workflow integration**: OCR output flows into the app's Stage-1
   cleanup → optional Stage-2 polish → paste → history/archive pipeline.
   A standalone tool stops at the clipboard.
3. **Engine choice & fallback**: WinRT OCR (or Vision on macOS) for speed,
   RapidOCR for language coverage, local VLM for hard cases — one
   consistent UI, one hotkey, per-language model downloads in-app.
4. It makes the dictate app the **single "turn the world into text"**
   utility: voice, meetings, files, video, webcam — and now screen.

## 4. Goals

1. **Hotkey snippet OCR** — Win+Shift+T (Windows) / ⌘⇧2-style (macOS,
   both configurable): freeze → drag-select → recognize → clipboard →
   optional auto-paste, with the Text-Extractor selection vocabulary
   (Shift to move, Esc to cancel, click for a single word when word boxes
   are available).
2. **Best native engine per platform, zero-download default** — Windows
   OCR via WinRT on Windows, Apple Vision via the existing Swift sidecar
   on macOS. First-run works with **no model download**.
3. **Portable ONNX backend** — RapidOCR (PP-OCRv4/v5 det + rec) through
   the existing `ort` rc.9 stack, with the model-registry download UI and
   language picker, as the multi-language and Windows-OCR-fallback path.
4. **Opt-in VLM fallback** for noisy/handwritten regions via the existing
   multimodal local-LLM transport.
5. **First-class observability** — a `snippet` block in Diagnostics
   (engine, model install state, last-region size, OCR latency, failures),
   matching the vision/mic precedent.

## 5. Non-goals (this proposal)

- **No screenshot file management** — no save-to-disk, no annotation, no
  editor. If the user wants a screenshot saver, they already have the OS
  tools. We are an OCR pipe.
- No per-window capture (WGC) in v1; overlay-over-frozen-frame only.
- No Tesseract backend in v1 (see §3.2.4).
- No cloud OCR providers ever in the default path (local-first bright
  line; the only egress-capable mode is the existing user-configured local
  LM Studio/Ollama VLM).
- No handwriting-first experience (VLM fallback covers it opportunistically).
- No table/structure extraction (spreadsheet mode is Text Grab territory
  and out of scope).
- No screen *video* OCR (tracking text across frames) — that is a
  different proposal (candidate 100b).

## 6. Design

### 6.1 New `snippet/` module (mirrors `vision/` for feature stability)

```
src-tauri/src/snippet/
├── mod.rs            # always compiled: RegionRect, SnippetResult, available()
├── capture.rs        # Windows: GDI virtual-screen freeze (windows crate);
│                     # macOS: sidecar screenshot command; shared trait CaptureBackend
├── select.rs         # selection-window lifecycle: arm → show overlay → region
│                     # or cancel event; word-box single-click when engine supports it
├── ocr.rs            # OcrEngine trait + backend dispatch (winrt | macos_sidecar | onnx | vlm_fallback)
├── ocr_onnx.rs       # #[cfg(feature = "snippet-onnx")]: DBNet det + SVTR/CTC rec
│                     # via ort rc.9 + ndarray16 (letterbox + decode, vision-style)
├── models.rs         # ONNX catalogue (RapidOCR det/rec/dict per language, SHA-256,
│                     # streaming + atomic install) — mirrors vision/models.rs
└── controller.rs     # SnippetController: owns the capture + engine on the
                      # nvoos-snippet worker thread; snapshot + events into AppState
```

Windows WinRT OCR needs no new crate — `windows` 0.58 is already a target
dependency; add the `Win32_Graphics_Gdi` (+ `Win32_Graphics_Dwm` if needed)
and `Win32_Media_Ocr`-equivalent features (WinRT APIs are exposed under the
crate's namespace features; the exact feature list is validated in the M1
spike). macOS needs **no new Rust crates**: the sidecar extension covers
capture + Vision OCR over the existing NDJSON stdout protocol.

**Cargo features:** `snippet = []` (capture + selection + platform engines,
no new mandatory deps — ships in the default build) and
`snippet-onnx = ["snippet", "dep:ort", "dep:ort-sys", "dep:ndarray16"]`
(the portable RapidOCR backend; shares the ort pin with `vision`). The
"complete build" union in the skill gains `snippet,snippet-onnx`.

### 6.2 Hotkey & selection lifecycle (the Snipping-Tool clone)

1. **Arm** — global hotkey (default Win+Shift+T on Windows; macOS default
   follows the app's permission-free toggle combo convention with a
   snippet-specific chord). Reuses `hotkey/` dispatch; a pressed-snippet
   state joins the existing PTT swallow discipline so no modifier can be
   left stuck on cancel/exit (same `release_pending_swallow` safety net).
2. **Freeze** — `capture.rs` grabs the virtual screen to an in-memory
   BGRA buffer (DPI-aware, multi-monitor). **Never touches disk.**
3. **Overlay** — a second full-screen transparent Tauri window
   (unlike the focusless dictation capsule, this one owns the pointer)
   renders the frozen frame + dark mask + crosshair; drag selects,
   **Shift+move / release-to-resize**, **Esc cancels**, click = single
   word (using word boxes when the engine supplies them — Windows OCR and
   Vision both do; RapidOCR supplies polygons → nearest box).
4. **Recognize** — crop rect is copied out of the frozen buffer
   (2× upscale of tiny regions is a standard accuracy trick — Text Grab
   documents the same behavior), PNG-encoded in memory, handed to the
   engine on the `nvoos-snippet` worker (never the UI thread).
5. **Deliver** — Stage-1-lite cleanup → clipboard → optional auto-paste
   into the previously-focused app (the app already restores focus after
   paste) → overlay capsule flashes `copied`/`pasted`/`no text found` →
   dismiss. Recognition latency budget: the ~1.5 s key-up-to-paste budget
   the dictation path targets; WinRT/Vision easily fit, ONNX CPU is
   ~50–200 ms per region on the reference PC, VLM fallback is exempt and
   shows a `recognizing…` state.

### 6.3 OCR engine dispatch

`OcrEngine::recognize(&RegionImage, opts) -> SnippetText` behind a small
trait; selection order in Settings:

1. **Auto (default)** → `winrt` on Windows, `macos_sidecar` on macOS.
2. **ONNX (RapidOCR)** → chosen when the user wants a language outside the
   OS packs, or as the Windows fallback when no OCR pack is installed
   (mirrors Text Extractor's "No OCR languages are installed" message,
   but instead of dead-ending we offer the ONNX path).
3. **VLM fallback** → opt-in toggle: on low classical confidence or manual
   trigger, the crop goes through the existing `describe_crop` multimodal
   transport with an OCR system prompt (badged `VLM` in the result).

### 6.4 macOS sidecar extension

`mac-helper` gains two commands speaking the existing NDJSON line protocol:

- `screenshot` → `SCScreenshotManager.captureImage` (SCContentFilter on
  the main display set, `SCCaptureResolutionBest`, cursor optional) →
  base64 PNG line.
- `ocr <b64png> [langs…]` → `VNRecognizeTextRequest`
  (`recognitionLevel = .accurate`, `usesLanguageCorrection = true`,
  recognitionLanguages from Settings) → `{text, words[{string, box}]}`.

The Screen Recording TCC grant already exists for meetings (ADR 0004);
`permissions.rs` already surfaces it, so the Permissions tab needs no new
entry — only wording. No notarization changes (same helper, same signing).

### 6.5 Settings, IPC, diagnostics

- `Settings.snippet` — `enabled`, `hotkey`, `engine` (`auto|winrt|onnx|vlm`),
  `language` (for ONNX/Vision), `vlm_fallback` (bool), `auto_paste` (bool),
  `cleanup_profile` (`off|line_join|full_stage1`), `retain_history` (off by
  default; transcript DB row mirrors dictation).
- IPC commands: `snippet_available`, `snippet_arm` (hotkey-driven),
  `snippet_cancel`, `snippet_region_selected(rect)` (from the overlay
  webview), `snippet_recognize_file(path)` (bonus: OCR a dropped image —
  the file path is already a supported input for transcribe),
  `get_snippet_models`, `download_snippet_model`; events `snippet://state`.
- `StatusInfo` gains `snippet_available/engine/hotkey`; `get_diagnostics`
  gains a `snippet` block (engine, model install state, last region
  dimensions, `last_ocr_ms`, `ocr_calls`, `errors`).

### 6.6 Cleanup profile (Stage-1 reuse)

OCR output has different artifacts than speech: stray spaces from word
boxes, `|` from table borders, line-break explosion, `O`/`0` and `l`/`I`
confusions that per-app vocab rules can't fix. v1 ships an **OCR profile**
selecting a *subset* of the existing deterministic rules (whitespace
collapse, smart line-joining per reading order, artifact stripping) —
Stage-2 LLM polish remains available as an explicit opt-in for OCR text
exactly as it is for dictation, reusing the provider stack unchanged.

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Async WinRT interop** from Rust (`OcrEngine::RecognizeAsync`) is the main unknown — thread apartment + `IAsyncOperation` handling in the `windows` crate | **M1 de-risk spike first** (`examples/snippet_ocr_probe.rs`): recognize a fixture PNG on the reference PC before any UI work. Fallback decision: if WinRT proves hostile, flip the Windows default to the ONNX backend (already in scope) — feature ships either way |
| **DPI/multi-monitor capture** artifacts (blurry or offset regions) | PerMonitorV2 DPI awareness (already required for the overlay), virtual-screen metrics, 2× upscale of small crops — all captured in unit tests with synthetic rects |
| **`ort` rc.9 vs RapidOCR model opset drift** | The vision spike already proved rc.9 runs opset-12 ONNX; validate det/rec models in the same spike; pin SHA-256 per model in `models.rs` |
| **Hotkey collision** — Win+Shift+S is the Snipping Tool, Win+Shift+T is PowerToys' | Default to the industry-standard **Win+Shift+T** and document the collision; configurable; snippet hotkey refuses to register when it collides with the PTT hotkey (existing guard pattern) |
| **Protected content** (DRM video) captures as black | Documented behavior — same limitation as every GDI-based tool; not an error |
| **Overlay focus/exit safety** — a full-screen pointer-owning window must never strand the user or leave a modifier swallowed | Esc/right-click cancel paths, exit discipline reuses the hook's synthetic-key-up release; the overlay window closes on selection commit *before* paste re-focuses the previous app |
| **Privacy** — screenshots are high-sensitivity | In-memory only by default, zero egress, no disk; `retain_history` off; the VLM fallback only talks to the user-configured **local** LM Studio/Ollama endpoint (local-http invariant already enforced in `LocalOpenAiProvider`) |
| **Scope creep** (screenshot editor, video OCR, tables) | §5 hard non-goals; video/text-tracking is explicitly 100b |

## 8. Milestones

1. **M0 — spikes (de-risk only).** `snippet_ocr_probe.rs`: (a) WinRT
   `OcrEngine` on a fixture PNG from Rust; (b) RapidOCR det/rec ONNX on
   `ort` rc.9; (c) sidecar `screenshot`+`ocr` commands on macOS CI.
   Go/no-go on the Windows default engine comes out of (a) vs (b).
2. **M1 — capture + selection shell.** `capture.rs` (GDI freeze + sidecar
   command), `select.rs` overlay window (frozen frame, drag/Shift/Esc,
   word-click), IPC surface, hotkey wiring with swallow discipline.
   Feature `snippet` compiles everywhere; no engine yet.
3. **M2 — Windows OCR engine** (WinRT backend behind `OcrEngine`; language
   pack detection + "no packs installed" handling) and **macOS Vision
   engine** via the sidecar. End-to-end: hotkey → select → clipboard.
4. **M3 — ONNX RapidOCR backend** (`snippet-onnx` feature, `models.rs`
   catalogue, det+rec+CTC decode, language picker, registry download UI +
   Diagnostics row).
5. **M4 — delivery polish.** Stage-1 OCR cleanup profile, auto-paste
   toggle, VLM fallback toggle (reusing `describe_crop`), history row,
   result capsule states (`copied`/`pasted`/`no text found`).
6. **M5 — verification + docs.** Gate matrix extension (`cargo fmt`,
   clippy default + `snippet` + `snippet-onnx` + macOS target, `cargo
   test`, `npm run build`), fixture-based OCR tests (synthetic text
   images), complete-build union update, skill + README + NOTICE (RapidOCR
   model licenses: Apache-2.0 model weights) updates.

## 9. Effort estimation

- **M0 spikes:** 1–2 sessions (WinRT interop is the only genuine unknown).
- **M1–M2 (platform-native path):** ~3 sessions — hotkey/overlay/capture
  plumbing is familiar territory; the sidecar extension is small.
- **M3 (ONNX backend):** ~2–3 sessions (two-stage pipeline + registry;
  vision established every pattern needed).
- **M4–M5:** ~1–2 sessions.
- **Total:** roughly 7–10 focused sessions; no new external services, no
  new paid dependencies, ~10–25 MB of model weights only when the user
  opts into the ONNX backend.

## 10. Success metrics

- Cold-path latency: hotkey → clipboard ≤ 1.5 s on Windows/macOS with the
  native engine (matches the dictation paste budget).
- Zero network egress in default + ONNX modes (verified in the test
  suite's no-HTTP assertions).
- Accuracy: ≥ 98% character accuracy on clean UI text fixtures (both
  native engines and ONNX); VLM fallback beats classical engines on the
  noisy/handwritten fixture set.
- Usability: first-run works with no model download and no settings
  change; Esc cancels 100% of the time with no stuck modifiers.

## 11. Decision required

1. **Approve the engine strategy** — platform-native first (WinRT /
   Apple Vision), RapidOCR-ONNX as the portable/multilingual backend,
   Tesseract deferred indefinitely?
2. **Approve default hotkeys** — Win+Shift+T on Windows (PowerToys
   convention, collision acknowledged), macOS chord per the port's
   permission-free toggle convention?
3. **Confirm scope line** — OCR pipe only (no screenshot file management,
   no video OCR in this proposal; video-text tracking is reserved as
   proposal 100b)?

---

*Scoped deliberately: a hotkey-driven, local-first, clipboard-bound OCR
pipe that clones the industry-standard Snipping-Tool/Text-Extractor
interaction, reusing the dictate app's hotkey, overlay, paste, cleanup,
ONNX, model-registry, and macOS-sidecar machinery. Screenshot management
and video OCR are explicitly out.*
