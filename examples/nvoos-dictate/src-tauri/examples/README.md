# `src-tauri/examples/` — de-risk probes

## Purpose

Manual probes that prove one risky dependency or platform path works on the
target machine **before** it is wired into the app. They are not shipped and are
not part of the product surface.

## Tier

**Sub-project** — proprietary (`LicenseRef-Proprietary`), same crate as the app.

## Public surface

None. Each file is a standalone `fn main()` binary.

## Inputs / outputs / neighbors

- **Inputs:** a local model file, a device (mic/camera), a PDF/image, or a
  configured local server; some probes download pinned model files.
- **Outputs:** stdout diagnostics and exit status. No app state is written.
- **Neighbors:** the library modules they exercise (`stt`, `vision`, `gestures`,
  `document`, `memory`, `agent`, `nvoos`, `storage`, `audio`).

## Probes

`required-features` is enforced by Cargo; run a gated probe with the feature on.

| Probe | Feature | Proves |
|---|---|---|
| `vision_probe.rs` | `vision` | a YOLOv8/v11 ONNX detector runs on this machine |
| `agent_probe.rs` | `agent` | a local model can emit a parseable tool call through the existing transport |
| `snippet_ocr_probe.rs` | `snippet` | WinRT OCR / RapidOCR ONNX legs for screen-region OCR |
| `document_probe.rs` | `document` | PDF text-layer extraction + 300-DPI rendering via pdfium |
| `document_model_probe.rs` | `document` | one page → local DeepSeek-OCR-class server → Markdown |
| `embed_probe.rs` | `memory` | the pinned granite embedder loads and produces sane vectors |
| `gesture_probe.rs` | `gestures` | a 21-keypoint ONNX hand model runs on one image |
| `capture_probe.rs` | — | camera capture residency over a short run |
| `keychain_probe.rs` | — | the credential store persists (set → read → delete) |
| `mic_probe.rs` | — | the default input device delivers buffers for 3 s |
| `nvoos_polish_probe.rs` | — | the real Stage-2 polish path against the configured site + keychain credential |
| `download_model.rs` | — | the registry download path (streaming SHA-256 + atomic install) |
| `transcribe_file.rs` | engine at runtime | STT latency/WER benchmark on a WAV file |

`download_model`, `keychain_probe`, `mic_probe`, `nvoos_polish_probe` and
`transcribe_file` are auto-discovered (no `[[example]]` block) and build with the
default feature set.

## Conventions

- One probe, one question. Print the measurement; do not assert inside the probe.
- Never bundle or commit a model. `download_model` exists so a probe can fetch one.
- A probe that needs a feature must declare it in `Cargo.toml` `[[example]]`,
  or its gated imports will not compile in CI.

## Tests

Probes are **not** part of `cargo test`; they are run manually, for example:

```bash
cargo run --example vision_probe --features vision
cargo run --example document_probe --features document -- fixture.pdf
```

## Also Load

- [`../README.md`](../README.md) — crate runtime, features, gates.
- [`../tests/README.md`](../tests/README.md) — automated suites.
- [`.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md`](../../../../.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md)
