# `src-tauri/tests/` — integration suites and fixtures

## Purpose

Integration tests that exercise the crate across module boundaries: cleanup
golden files, document input, local history, the MCP tool surface, memory, and
NV oOS payload validation.

## Tier

**Sub-project** — proprietary (`LicenseRef-Proprietary`).

## Public surface

None. Each `*.rs` file is a Cargo integration-test target.

## Inputs / outputs / neighbors

- **Inputs:** the fixtures in this directory, a temp SQLite store, and — for the
  feature-gated suites — the corresponding engine feature.
- **Outputs:** test results only; no fixture is modified.
- **Neighbors:** `src/cleanup`, `src/document`, `src/storage`, `src/memory`,
  `src/mcp`, `src/nvoos`.

## Suites

| Suite | Gated by | Pins |
|---|---|---|
| `cleanup_golden.rs` | — | the text-cleanup pipeline against golden output |
| `document_text.rs` | `#![cfg(feature = "document")]` | PDF text-layer extraction |
| `document_images.rs` | `#![cfg(feature = "document")]` | image/PDF rendering and page provenance |
| `history.rs` | — | the SQLite history round-trip and retention |
| `mcp_tools.rs` | `#![cfg(feature = "mcp")]` | the headless MCP tool dispatch |
| `memory.rs` | — (runtime skip when the embedder is absent) | the memory layer's retrieval seam |
| `nvoos_validation.rs` | — | the payload validation used before a network call |

## Fixtures

| File | Used by |
|---|---|
| `meeting1.raw.txt`, `meeting1.expected.txt` | `cleanup_golden.rs` |
| `text-2page.pdf` | `document_text.rs` |
| `image-text.pdf`, `image-text.jpg`, `image-text.png`, `broken.png`, `malformed.pdf`, `encrypted.pdf` | `document_images.rs` (hostile-input cases) |

Fixtures are committed and read-only. Add a new fixture only with the test that
pins it; never regenerate an expected file to make a failing test pass.

## Conventions

- A feature-gated suite starts with a crate-level `#![cfg(feature = "…")]`, so
  `cargo test` on the default feature set compiles it to an empty target rather
  than failing.
- Tests that need a machine capability (mic, camera, model file) skip with a
  clear message instead of failing the suite.
- Doctests are separate: `cargo test` runs them too.

## Tests

```bash
cargo test                                   # default + doctests
cargo test --features mcp                    # mcp_tools
cargo test --features document               # document_text + document_images
cargo test --test history                    # one suite
```

## Also Load

- [`../README.md`](../README.md) — crate runtime and feature matrix.
- [`../examples/README.md`](../examples/README.md) — manual probes.
- [`.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md`](../../../../.agents/skills/mcp-ai-wpoos-dictation-app/SKILL.md)
