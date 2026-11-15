# Status, session log & follow-ups

Moved out of `SKILL.md` on 2026-11-08 (87 KB against Zed's ~100 KB
skill-load limit). Shipped-status log, the per-session engineering notes
from 2026-10-13 onward, and the standing follow-ups. Read it before
planning work on the app so you do not redo a completed item.

## Status & follow-ups (2026-10-13)

Done: P0 core, P1 (nvoos client/archive), P3 (MCP stdio), engine track live-
verified, embedded fallback implemented, PR #7011 → `alpha-working`;
post-merge hardening: exit-on-close + stuck-PTT release + model download UX
(#7017), VAD windowing/whisper CRT fix/hotkey guard (#7020), and this round:
symmetric hook swallow (stuck-Alt fix), hands-free toggle release fix
(double-tap recording no longer ends on its own key release), engine/model
pairing validation + model dropdown, force re-download, vendored
whisper.cpp CMakeLists bindings fix; hook exit-safety (synthetic key-up via
SendInput after unhook — fixes the keyboard stuck until Ctrl+Alt+Del when
closing with Alt+F4/while the PTT key is held), adaptive VAD gate +
no-speech fallback (audible audio is never dropped), `LastDictationStats`
+ Diagnostics tab (`get_diagnostics` IPC: mic device name + live level
meter, hook health, model install state, credentials, copy-report JSON),
mic input-device picker in Settings (`audio.input_device` +
`list_input_devices` IPC + `state::reapply_audio` — the resident capture
restarts on save; MicCapture's worker thread now stops via a Drop stop
channel), the model re-download hardening (staging-swap install,
read-only attribute clearing, engine released before file replacement,
and the real re-download root cause: the tmp-file `OpenOptions` missing
`.write(!resuming)` — a client-side InvalidInput, not an OS error), and
the **live-capture fix**: the resident stream was being dropped right
after `play()` so the pre-roll ring never filled (zero callbacks on
healthy mics) — the worker now holds the stream, capture health
(callbacks/stall/errors) shows on Diagnostics with a Restart-capture
button, `restart_microphone` IPC, and the `mic_probe` example +
`live_capture_delivers_samples` ignored test verify real hardware;
the **Stage-2 polish/archive contract fix** (verified live against
console.nvoos.cloud v1.2.3): `extract_reply_text` now parses the v1.2.x
versions (`data.content`, `data.choices[0].message.content`) and the
`nvoos_polish_probe` example exercises the real path — the archive route
`POST /chat-transcripts` REQUIRES both `assistant_id` and `session_key`
(both returned by the chat response: `assistant_id` + camelCase
`sessionKey`; the app now threads them via `PolishMeta`), and the **per-
request polish model override**: `nvoos.polish_model` (Settings > NV oOS)
is sent as `options.model` on the polish chat request — verified live:
the site answers with the requested model (`deepseek-v4-pro` honored;
~10.5 s vs ~9 s for `deepseek-flash` at ~4× cost, so flash stays the
sensible default for transcript cleanup). `nvoos.polish_provider` is
sent as `options.provider` the same way — the site's `sanitize_options`
allowlist accepts openai/gemini/anthropic/deepseek/etc., and a provider
without an API key configured on the site errors, so the overrides only
work for providers the site has keys for (the public `/status` endpoint
reports "N providers operational" but never names them). The
`nvoos_polish_probe` example takes `--provider`/`--model`/`--archive`/
`--raw` flags for live A/B runs. Also: the app's Windows credential
(CRED target `nvoos/assistant-credential.com.nvdigitalsolutions.
nvoos-dictate`) can vanish when the user exercises the Clear-credential
UI — probe runs should check `credential: stored` and ask the user to
re-store it (the site only ever shows `cred_XXXXX.SECRET` at creation;
a lost one needs regeneration in the console).
New this session (2026-10-13): **first-class local Stage-2 polish providers**
— `lm_studio` and `ollama` — plus the shared `local_openai`/`openai_shape`
transport refactor, `load_timeout_ms` cold-load enforcement, and polish
`ttft`/`total` latency on the Diagnostics page (see "Stage-2 polish
providers" section above). All gates green on the reference PC:
`cargo fmt --check`, `clippy --all-targets --offline -- -D warnings`
(default + `--features mcp`), `cargo test --offline` (78 lib + 17 golden +
6 history + 5 validation), and `npm run build` (tsc + vite). A fresh debug
exe was built with `npm run tauri -- build --debug --no-bundle --features
whisper,parakeet,silero-vad` → `src-tauri/target/debug/nvoos-dictate.exe`
(the cleanup providers need no feature flag — they're always compiled;
the local-engine features are what require a flagged build).
New this session (2026-10-13, implemented): **video + long-media input**
(Proposal 071). `util/decode.rs` → `DecodedMedia` (extension `Hint`,
audio-track-by-`get_codecs().make()`, duration via `n_frames`×`time_base`,
`MAX_MEDIA_SAMPLES` cap); `media/chunker.rs` (`chunk_transcribe`) windows
whisper-class engines at 30 s + context carry-over and parakeet at the 240 s
VAD split; `SttEngine::transcribe_with_context`; `transcribe_media` +
`summarize_media` IPC (+ `media://progress`); `CleanupProvider::summarize`
(nvoos + local). Gates green: 81 lib + 17 golden + 6 history + 5 validation,
clippy default + `mcp`, frontend build. Deferred: GUI file-picker, DB column,
media fixtures.
New this session (2026-10-13, implemented): **local object detection +
  tracking** (Proposal 075) — the `vision/` module (see "Camera & live vision"
  above): `detect.rs` (letterbox + `ort` YOLO + NMS), `tracker.rs` (SORT +
  click-to-select pin, dependency-free + unit-tested), `models.rs` (vision ONNX
  catalogue), `controller.rs` (`VisionController` worker loop). `Settings.vision`,
  `vision_available/start_vision/stop_vision/get_detections/
  vision_select_track/get_vision_models/download_vision_model` IPC, a `vision`
  block in `get_diagnostics`, `StatusInfo` vision fields, and a Home preview
  `<canvas>` overlay + Diagnostics/Settings rows. The `vision_probe` spike
  proved `yolov11n.onnx` runs on `ort` rc.9 (confirmed `[1, 84, 8400]`, ~49 ms
  CPU). Gates green: 85 lib + 17 golden + 6 history + 5 validation, clippy
  default + `mcp` + `camera` + `vision`, `cargo fmt --check`, `npm run build`.
  Deferred M5: MediaPipe-hand model + ByteTrack low-confidence recovery.

Follow-up (2026-10-13): **VLM label polish (Proposal 076)** for the COCO-mislabel
  problem — see "Vision label polish" above. Default model is `qwen/qwen2.5-vl-7b`
  (note the `qwen/` prefix), verified live on the reference PC: LM Studio returns
  the label as **fenced** JSON (``` ```json {…} ``` ```), which
  `parse_label_response` tolerates. Also fixed the **duplicate-track bug**: a
  pinned track kept drifting via `predict()` while unmatched, so a re-appearing
  object spawned a second track; the spawn step now re-associates any detection
  still overlapping a live/pinned track (IoU > 0.1) instead of spawning, and the
  pinned grace period is `max_age*2`. The Home "System" card, `README.md`
  (camera/vision features + feature flags), and `NOTICE.md` (model-weight
  licenses: YOLO AGPL-3.0, et al.) were surfaced. Gates green this session:
  94 lib + 17 golden + 6 history + 5 validation, clippy default + `mcp` +
  `camera` + `vision`.
Remaining: PTT manual matrix + loopback meeting verification (P2
real-call), embedded live test (needs a Gemma 4 audio endpoint), Embedded
addon permission tweak for `cred_x.SECRET`, P4 (Linux) port, the macOS
port, and release signing/updater feed. See the implementation plan
(`docs/project/proposals/067-…-plan.md`) for the full item list. The macOS
port (067-P5) is merged into `alpha-working` (PR #7030, 2026-10-12):
`hotkey/macos_event_tap.rs` + portable `tap_state.rs` state machine,
`paste/macos_cgevent.rs`, `permissions.rs` TCC layer + Settings
Permissions tab, `meetings/macos_system_audio.rs` + `sidecar_protocol.rs`
+ `mac-helper/` Swift package (externalBin stubs in
`src-tauri/binaries/`), `util/macos_foreground.rs`, Info.plist +
entitlements, macOS CI/publish matrix. **Boot-verified on `macos-latest`
(2026-10-18):** the CI boot-smoke job boots the real `.app` and holds it
30 s, and the headless `--mcp` probe loads the engine — see "Verifying
the macOS port from a non-Mac machine" above (PRs #7122 + #7123). What
remains hardware-only: TCC grants, real PTT input, meeting capture
(`docs/ptt-matrix-macos.md`). See the comprehensive plan
`examples/nvoos-dictate/docs/macos-port-plan.md` (phases M0–M8: TCC
permission model + first-run wizard, permission-free combo PTT default +
opt-in Right-Option CGEventTap hold mode, ScreenCaptureKit Swift sidecar
for meeting system audio, NSWorkspace per-app cleanup, signed/notarized
DMG). An alternative macOS design is scoped in proposal 068
(`docs/project/proposals/068-macos-dictation-port-proposal.md`: listen-only
CGEventTap PTT, Metal/CoreML STT, Sparkle distribution) — reconcile the
two before merging the port.

New this session (2026-10-13): **FastConformer STT engine** (the `nemo_ctc`
slot, `fastconformer` feature, registry id
`sherpa-onnx-nemo-ctc-en-conformer-large`) and the **NV oOS console window**
(`Settings.spa.url` + `open_nvoos_console` + origin-locked navigation guard
+ the Rust-side `check_spa` reachability probe). See the two sections above for
full detail. Gates green on the reference PC: `cargo check`/`clippy
--all-targets -- -D warnings`/`fmt --check` (default + `fastconformer`),
`cargo test --lib` 107 passed (incl. 4 `validate_spa_url` tests), `npm run
build`. The complete debug exe is the full feature union
`fastconformer,parakeet,whisper,silero-vad,camera,vision,agent,mcp` — NOT just
the STT flags; see the "complete build" note in Build & run. PR #7047 tracks
the code change (branch `add/fastconformer-engine-and-assistant-spa-tab`).

New this session (2026-11-02): **screen OCR snippet (Proposal 100)** —
  features `snippet` (capture + selection overlay + platform-native engines,
  implies `dep:image`) and `snippet-onnx` (RapidOCR ONNX, shares the ort pin),
  the `snippet/` module (capture/ocr_winrt/ocr_onnx/models/controller), the
  pointer-owning full-screen `snippet` window + capability, WinRT end-to-end
  verified live (~35 ms with word boxes), the ONNX pipeline verified live
  (~90–120 ms, BGR + blank-offset decode), the pinned pack catalogue, the
  OCR cleanup profile, snippet history (append-only migration), the
  `snippet_ocr_probe` spike example, and the Swift sidecar `screenshot`/`ocr`
  subcommands (CI-verified). See "Snippet OCR" above for the full set of
  gotchas (DataWriter path, DIB ownership, monkt-vs-official exports).
  Gates green: fmt, clippy (default + `snippet` + `snippet-onnx`),
  `cargo test` 117 default / 122 snippet / 126 snippet-onnx, `npm run build`.
  The complete-build union and `verify_complete_build.ps1` now include
  `snippet,snippet-onnx`.

New this session (2026-11-02): **rebrand to NV oOS Cognition v0.3.0 +
  public mirror** — the product name, window titles, macOS `.app` paths,
  CI workflow names and the publish release title move to the Cognition
  brand; technical identifiers stay `nvoos-dictate` (crate, binary,
  updater tags `nvoos-dictate-v*`, `%APPDATA%/nvoos-dictate`, keychain
  targets) for updater/keychain/data-dir continuity (Proposal 103). The
  publish workflow no longer runs `scripts/gen-icons.mjs` — that script
  emits placeholder art and would overwrite the committed brand icons in
  every release bundle; it is bootstrap-only. `examples/nvoos-dictate` is
  mirrored one-way to the public repo
  `nvdigitalsolutions/nvoos-cognition` by
  `.github/workflows/sync-nvoos-cognition.yml` (subtree split + force
  push; backfilled 2026-11-02, run #3 success). The mirror carries the
  proprietary `LICENSE.txt` — source-visible, not open-source.
  **Mirror token recipe (activation or rotation):** a repo-scoped
  fine-grained PAT — resource owner `nvdigitalsolutions`, only the
  `nvoos-cognition` repo, Contents: read+write — stored as
  `NVOOS_COGNITION_REPO_TOKEN`. Push-step diagnostics: `Invalid username
  or token` means the token VALUE is bad (expired/truncated/wrong kind);
  a correctly-shaped token with the wrong repo scope says `Repository not
  found` instead. First-backfill notes: the `fetch-depth: 0` monorepo
  checkout takes ~15–25 min on ubuntu runners, and the mirror API's
  `pushed_at` updates immediately while `size` lags — verify a sync by
  `pushed_at` plus a root `contents/` listing.

New this session (2026-11-03): **document-OCR input pipeline (Proposal
  109) — proposal + implementation plan + M0 spike (PR #7425).** New
  `document` feature (pdfium-render pinned pdfium_7881/image_025/
  thread_safe + tiff), the `document_probe` spike example, the
  `make_doc_fixtures.py` generator with committed PDF fixtures, and the
  verified pdfium-render 0.9.5 API facts — see the new "Document OCR
  input" section above. Also this session: the **release build + bundle
  recipe** (NSIS ~13 MiB, MSI ~163 MiB, the >60-min-first-build trap) and
  the **verify-script release-proof agent marker** (the `nvoos-agent`
  thread name is DCE'd in release builds — the script now also matches
  `could not spawn agent worker`). Complete-union + verify markers gain
  `document` at M1, when lib code lands.

New this session (2026-11-03, later): **Proposal 109 M1–M4 implemented**
  on PR #7425 — the full document pipeline described in the "Document OCR
  input" section: hybrid router + shared `layout.rs`, scan profile, OCR
  dispatch, multi-page TIFF, controller/progress/cancel, local VLM polish,
  txt/md/hOCR/ALTO + searchable-PDF exports (new `snippet-pdf` feature +
  lopdf), IPC + Settings + Diagnostics + history `source` column, the Home
  "Document OCR" panel, encrypted fixture, ADR 0005, NOTICE entry, and a
  `document (pdfium)` verify marker (`pdfium library not found`). Gates:
  fmt, clippy `-D warnings` (default + document + snippet-pdf + full
  union), 144 lib + 17 golden + 6 history + 5 validation + 5 document
  integration tests, `npm run build`. Known gaps: encrypted-fixture
  stream-decryption round-trip unverified; searchable-PDF v1 re-rasterizes
  (vector-preserving relayer + word-positioned text layer deferred); polish
  needs a live local VLM to exercise end-to-end. **hOCR/ALTO are now v2**
  (word boxes — follow-up 2): `DocumentPage.lines: Vec<DocumentLine>` with
  `DocumentWord` boxes in the page's canonical raster space (PDF pages =
  300-DPI render pixels, TIFF/image = source pixels; engine boxes scaled
  back from the scan-profile 2x space). Polished pages carry pre-polish
  boxes when the finalized text aligns perfectly (`words::align`), else
  synthetic line-level boxes (`fallback_lines`); model tiers output no
  coordinates by design (Proposal 111).


## Session log

New this session (2026-11-04): **Proposal 109 M1-M4 follow-up PR + CI fmt
fix.** PR #7425 (proposal/plan/M0) was squash-merged into alpha-working;
M1-M4 + ADR 0005 + docs land via the follow-up PR from
`feat/nvoos-dictate-document-ocr-m0`. The CI `cargo fmt --check` failure
was stale formatting, not toolchain drift: the committed files were
edited after the last local fmt run, so they were never fmt-clean for the
pinned stable. Fix = run `cargo fmt` with the same toolchain CI uses
(dtolnay@stable == rustup stable 1.99.1, rustfmt 1.10.0). **rustup
self-update blocker on this PC:** `could not create link ... cargo-miri.exe
(os error 183)` - delete the stale hardlink (`rm ~/.cargo/bin/cargo-miri.exe`)
and re-run `rustup update stable`; the toolchain sync is blocked until the
self-update completes. The CI windows-bundle union now includes
`document,snippet-pdf` (compile-only on CI - PDFium binds at runtime, and
the document tests need the dll so they stay out of CI). UI fact:
"Transcribe media" = audio/video only; PDF/TIFF go through the Home
"Document OCR" panel; single raster images (PNG/JPEG) are the documented
follow-up. Research notes:
`docs/project/proposals/109-document-input-pipeline-ocr-research.md`
(engine comparisons, licensing matrix, cloud-vs-local economics,
small-VLM polish options, addons/pro survey).

New this session (2026-11-04, later): **raster-image input (Proposal 109
follow-up 1).** PNG/JPEG/WebP now route through the document pipeline:
`document/images.rs` (image::open -> RGB8 frame, 40 MP pixel cap, clean
"unsupported image" error), a controller refactor (`run_raster_pages` is
the shared TIFF/image loop; `run_tiff_pages`/`run_image_pages` are thin
wrappers; the non-document stub set gained a `run_image_pages` stub),
`pick_document` gained an Images filter (png/jpg/jpeg/webp), the Home copy
lists images, and dispatch keys off extension (png|jpg|jpeg|webp).
Fixtures: `scripts/make_image_fixtures.ps1` (System.Drawing PNG+JPEG
600x200 + broken.png copied from malformed.pdf; the repo-level
info/exclude ignores *.png/*.jpg, so committing new versions needs
`git add -f`); `tests/document_images.rs` (3 decode/error tests, no
pdfium needed). No Cargo.toml change: `image` 0.25 default features
include webp, and `document` implies `snippet` implies `dep:image`.
Image results label themselves `document-ocr` in history (same as
scanned pages) - cosmetic only.

New this session (2026-11-04, provisioning): **pdfium.dll bundle-resource
provisioning** (follow-up 3). `scripts/fetch-pdfium.ps1` downloads the
pinned `chromium/7881` tgz and verifies the sha256 of the EXTRACTED dll
(the pin is the 7,211,520-byte binary, not the re-packable tgz — the gate
caught exactly that mistake on first run); `tauri.windows.conf.json` adds
it as a Windows-only bundle resource; `build.rs` stages it beside
dev/test binaries; the publish workflow fetches before bundling.
**Tauri v2 places bundle resources under a `resources/` subdir beside the
installed exe (relative paths preserved)** — `pdf.rs::bind()` now tries
exe-dir, then exe-dir/resources, then the system search, and
`document_probe.rs` uses that production path (it previously called
`Pdfium::default()` = cwd/system only). **Verification recipe:** NSIS
`/S` silent install (Git Bash mangles `/S` and `/D=` — use
`cmd //c start /wait "" "<setup>" /S`; the Tauri NSIS template ignores
`/D` anyway and installs to `%LOCALAPPDATA%\<productName>`), copy
`target/debug/examples/document_probe.exe` into the install dir, and run
it against `tests/fixtures/text-2page.pdf` — success proves the bundled
layout binds. `msiexec /a` admin-extract is unreliable under MSYS
(`start /wait` reports the stub's exit, not the install's) — the NSIS
check is the authoritative one.

New this session (2026-11-04, word boxes + model tier): **hOCR/ALTO v2
  word-level bounding boxes** (follow-up 2) and **Proposal 111
  (DeepSeek-OCR provider tier — implemented later this session, see below)**. Word boxes: additive serde
  (`DocumentPage.lines`, `#[serde(default)]`; TS `lines?`), canonical
  raster space per page (PDF = 300-DPI render pixels, TIFF/image = source
  pixels; engine boxes scaled back from the 2x scan-profile space),
  `words.rs` (from_snippet / scale_words / linear vs column-aware line
  grouping / perfect-token align / fallback_lines), `layout.rs` gained
  `line_groups` (shared with cluster_lines - behavior byte-identical),
  hOCR `ocrx_word` + ALTO per-word `<String>` emitters with a v1 fallback
  when `lines` is empty. Polished pages: boxes are pre-polish by contract.
  DeepSeek-OCR (MIT, 3B MoE, 6.7GB via Ollama/LM Studio/llama.cpp
  PR #17400): proposal 111 decides **model tier via the local-server
  transport, never embedded** - see the proposal for P0/P1/P2 and the
  probe-first verification plan.

New this session (2026-11-04, evening): **Proposal 111 implemented**
  (PR #7439, stacked on #7434 word-boxes — merge #7434 first). Ships:
  `document/model.rs` (`DocumentModel` worker, own current-thread
  runtime like `polish.rs`; `should_escalate` pure+tested;
  `build_document_model` = configured provider first, the other local
  provider as fallback; `page_to_png_data_uri` sends the RAW page —
  never the binarized scan profile — downscaled to a 3000px long edge;
  `maybe_model` is soft-fail — the deterministic result always
  survives). Prompt contract is the official family prompt
  `<image>\n<|grounding|>Convert the document to markdown.` with **no
  system message** (DeepWiki/Cohorte guidance); the weak box-level OCR
  text rides along as prior context. Escalation: budgeted `max_pages`
  per document + `all_scans` opt-in + `min_confidence` gate — honest
  note: WinRT reports constant 1.0 and ONNX reports None, so only
  `all_scans` fires until an engine reports real scores. New
  `PageProvenance::Model` ('model'); `provenance_counts()` is now a
  4-tuple (text, ocr, polished, model). Settings: new Documents tab
  (provider Ollama/LM Studio, model id, all-scans, min-confidence,
  page budget, Test-connection button via
  `test_document_model_connection`); Diagnostics: Document OCR card
  with per-provenance counts. Live probe:
  `cargo run --features document --example document_model_probe --
  <pdf|image>` (the verification gate for the tier).
  **Default-feature CI discipline (the recurring trap):** the
  `document` module is ALWAYS compiled (type-stable for AppState/IPC),
  so every new model-tier reference must be cfg-gated — `pub mod
  model` is `#[cfg(feature = "document")]`, `run_pipeline` (shared
  entry) cfg-splits the runner dispatch (`&model` arg exists only in
  document builds), the not-document runner stubs dropped the model
  param, `test_document_model_connection` has a not-document stub,
  and the probe is registered via `required-features = ["document"]`
  in Cargo.toml. Gates re-verified green: fmt, clippy `-D warnings`
  (default + document + document,snippet-pdf), tests 127 default /
  158 document, `npm run build`. Known gaps: no live deepseek-ocr
  server run yet (ollama pull deepseek-ocr ~6.7GB); DeepSeek-OCR-2
  (Apache-2.0, 3B, OmniDocBench v1.5 91.09%) is a candidate default
  model id. Plan doc:
  `docs/project/proposals/111-deepseek-ocr-provider-implementation-plan.md`.
  **Live verification (2026-11-04, probe run):** `ollama pull
  deepseek-ocr` (6.7 GB F16) then the probe against the fixtures —
  `image-text.png` -> `## IMAGE OCR FIXTURE 123`, `text-2page.pdf`
  (rendered 2319x3000) -> the fox text, clean. Two transport findings,
  both shipped: (1) Ollama's `/v1/chat/completions` OpenAI-compat mount
  non-deterministically DROPS image features for the `deepseekocr`
  architecture (some page sizes derail into `<|im_start|>` loops; the
  native `/api/chat` with `images:[b64]` is reliable at every tested
  size 600x200..3000x3000) — `understand_document` branches: ollama ->
  native `/api/chat` (endpoint = base URL minus the `/v1` suffix),
  lm_studio -> compat mount; the Settings connection test pings ollama
  with a blank 64x64 image via the native route (text-only pings loop
  on this vision model). (2) Even native replies occasionally carry a
  stray template-fragment prefix (`\<|im_start|>user`,
  `</|im_start|>user`, `\>`) — `parse_document_model_reply` strips the
  fragment list deterministically (slash variants + bare `\` first);
  the content after them is valid. **Local-machine ops:** the Ollama
  install on this PC was missing `llama-server.exe` + CPU runners
  (every model call HTTP 500) — silent reinstall `OllamaSetup.exe /S`
  restored it; the probe exe needs `pdfium.dll` copied beside it in
  `target/debug/examples/` (bind() searches next to the exe; build.rs
  stages at `target/debug/` for the main binary only).

New this session (2026-11-12): **v0.4.0 release bump + mirror-sync test
  prep.** Version moved 0.3.0 → 0.4.0 across `package.json`,
  `package-lock.json` (which had drifted at 0.2.0 through the 0.3.0 bump —
  both root and `packages[""]`), `tauri.conf.json`, `Cargo.toml` and the
  crate's `Cargo.lock` entry. Unreleased since `nvoos-dictate-v0.3.0`
  (2026-11-02): document-OCR pipeline M0–M4 + raster-image input +
  hOCR/ALTO word boxes + searchable PDF, the DeepSeek-OCR model tier with
  Ollama native `/api/chat` routing, PDFium in Windows bundles, and the
  memory layer with safety gates (each covered above). Docs: README gained
  Document OCR / memory bullets, the `document`/`snippet-pdf`/`memory`
  build+test commands, a Releases section (tag → publish draft → dispatch
  mirror) and the corrected proprietary license; `CHANGELOG.md` added
  (the publish workflow body already referenced one); skill metadata
  bumped. Release-sync test path: tag `nvoos-dictate-v0.4.0`, publish the
  draft release, then dispatch
  `mirror-nvoos-cognition-release.yml` with the tag.
