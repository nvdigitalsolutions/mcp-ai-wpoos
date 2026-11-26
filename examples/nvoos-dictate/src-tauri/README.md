# `src-tauri/` — Cognition crate

## Purpose

The Rust/Tauri core of NV oOS Cognition: local-first push-to-talk dictation,
on-device OCR, meeting transcription, and the optional local browser agent. It
owns capture, recognition, post-processing, storage, and the desktop shell; it
does not own the WordPress plugin or the marketing site.

## Tier

**Sub-project** — proprietary (`LicenseRef-Proprietary`), versioned separately
from the plugin (currently 0.4.0). It is not part of the WordPress plugin build
and does not ship in the plugin ZIP.

## Runtime

| Item | Value |
|---|---|
| Rust | 1.90 MSRV, edition 2021 |
| Shell | Tauri 2 (`productName` **NV oOS Cognition**, id `com.nvdigitalsolutions.nvoos-dictate`) |
| Platforms | Windows (primary), macOS (arm64 + x86_64) |
| Frontend | `../src/` (React/TS), built by the app's `package.json` |
| Features | see `Cargo.toml`; default is **empty** — engines are opt-in |

Feature matrix (all off by default):

| Feature | Enables |
|---|---|
| `mock` | deterministic engine for tests/CI |
| `parakeet`, `fastconformer` | sherpa-onnx NeMo engines |
| `whisper` | whisper-rs (compiles whisper.cpp; `force-debug`) |
| `moonshine` | ONNX moonshine |
| `silero-vad` | Silero VAD |
| `camera` | nokhwa capture |
| `vision` | YOLO detection (implies `camera`) |
| `gestures` | hand-landmark gestures (implies `camera`) |
| `agent` | CDP browser agent (chromiumoxide) |
| `snippet`, `snippet-onnx`, `snippet-pdf` | screen-region OCR; ONNX and PDF variants |
| `document` | PDF/TIFF input (pdfium-render, tiff) |
| `memory` | local embedder (ort + tokenizers) |
| `mcp` | headless stdio MCP server (rmcp) |

## Public surface

| Item | Contract |
|---|---|
| `lib.rs` (`nvoos_dictate_lib`) | the module tree; `main.rs` is a thin shell |
| `ipc.rs` | Tauri commands; the TypeScript side must match these names |
| `config.rs` | the persisted settings schema (serde) |
| `error.rs` | `AppError` / `AppResult` — the one error seam |
| `storage/` | SQLite `Db`, migrations, and the platform credential store |

The binary also serves a **headless stdio MCP server** under `--mcp` (feature
`mcp`). History, dictionary, and meeting tools work headless; `dictate` asks the
user to run the desktop app.

## Inputs / outputs / neighbors

```
audio ─┐
camera ┼─▶ stt / vision ─▶ cleanup ─▶ paste
screen ┘        │              │
                └──▶ memory ───┘
nvoos provider ──▶ the configured NV oOS site (Stage-2 polish)
```

- **Inputs:** microphone, camera, screen region, PDF/TIFF, the local model cache.
- **Outputs:** clipboard paste, history rows, meeting transcripts, MCP tool results.
- **Neighbors:** `../src` (UI), `../../README.md` (app-level build/run), the
  WordPress site (only through `nvoos`).

## Generated artefacts

| Path | Source | Notes |
|---|---|---|
| `gen/schemas/` | Tauri codegen | never hand-edit |
| `target/` | cargo | build output |
| `binaries/` | packaging | `nvoos-audio-helper-<triple>` sidecars |
| `resources/` | staged at build | see its README; PDFium is gitignored and staged by `build.rs` |

`capabilities/` and `tauri.conf.json` are **hand-authored**, not generated.

## Conventions

- Every module opens with `//!`; every file carries an SPDX line. The module
  directory does not get a README — the `//!` block is the folder doc.
- **Item docs are best-effort.** `missing_docs` measured **739** public items on
  2026-11-26, above the contract's threshold, so the enforced bar is crate +
  module docs. Do not add `#![deny(missing_docs)]` without burning that down
  (Proposal 166, decision recorded in the implementation plan).
- No model weights are bundled. Downloads use streaming SHA-256 + atomic install;
  provenance failures are fail-closed.
- `cargo fmt --check`, `cargo clippy --all-targets -- -D warnings`, and
  `cargo test` are the local gates.

## Tests

```bash
cargo test                      # unit + integration + doctests
cargo test --features mcp
cargo test --features document
cargo fmt --check
cargo clippy --all-targets -- -D warnings
```

See [`tests/README.md`](tests/README.md) and [`examples/README.md`](examples/README.md).

## Also Load

- [`../../README.md`](../../README.md) — app-level features, build, privacy.
- [`.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md`](../../../.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md)
- [Proposal 103 (rebrand)](../../../docs/project/proposals/103-nvoos-cognition-rebrand-proposal.md)
- [Proposal 166 (this convention)](../../../docs/project/proposals/166-cross-language-documentation-contract-proposal.md)
