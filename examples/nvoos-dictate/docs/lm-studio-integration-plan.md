# LM Studio Local Polish Provider — Implementation Plan

**Status:** Implemented (M1–M4)
**Date:** 2026-10-13
**Relates to:** ADR 0001 (Stage-2 paste strategy), ADR 0003 (remote-STT fallback), `cleanup/providers/`

> **Implementation note:** the shared-transport option (Option A from the
> design discussion) was chosen. `cleanup/providers/openai_shape.rs` owns the
> provider-agnostic prompt/body/parse helpers; `openai_compat` and `lm_studio`
> are thin sibling adapters. `id()` stays `lm_studio` for correct
> transcript/history labelling.

## 1. Goal

Let a user run Stage-2 "polish" (the LLM cleanup of dictated text) against a
**local model served by LM Studio**, with zero cloud egress. Today the app
ships two Stage-2 polish providers:

| provider | backend | egress |
|---|---|---|
| `nvoos` | configured WordPress site's assistant | cloud |
| `openai_compat` | any OpenAI-compatible `/v1` (OpenAI, Ollama, …) | cloud or local |

LM Studio is *technically reachable* today by pointing `openai_compat.base_url`
at `http://localhost:1234/v1`, but that is a mislabeled, poorly-discoverable
experience with real correctness gaps (below). This plan promotes LM Studio to
a **first-class local provider** so the "local-first" story the app already
sells for STT (ADR 0003) extends to the polish step, too.

## 2. Research findings (industry standards)

### 2.1 LM Studio server contract (verified against lmstudio.ai docs)

- Default OpenAI-compatible server: **`http://localhost:1234/v1`**.
- Endpoints used here:
  - `POST /v1/chat/completions` — OpenAI chat shape (system/user messages,
    `model`, `temperature`, `max_tokens`, non-streaming by default).
  - `GET /v1/models` — returns `{ data: [{ id, object, … }] }`; the `id` here
    is the **model identifier** to pass back on `chat/completions.model`.
- **Model identifier convention:** the value to send in the `model` field is
  the LM Studio "model identifier" (e.g. `llama-3.2-3b-instruct`,
  `qwen2.5-7b-instruct-1m`), *not* the raw GGUF filename. `GET /v1/models`
  is the authoritative source for the correct identifier.
- **Single-model behavior:** if exactly one model is loaded, LM Studio serves
  it regardless of the `model` field value. For deterministic routing the
  user must load the target model first, or enable Just-In-Time (JIT) loading
  so the server auto-loads on request.
- **Auth:** the default local server needs **no token**; the headless/remote
  server optionally requires a bearer token configured in LM Studio's
  server settings. Any non-empty `Authorization: Bearer …` is accepted as a
  placeholder by the default server.
- **Supported payload parameters:** `model, messages, temperature, top_p,
  top_k, max_tokens, stream, stop, presence_penalty, frequency_penalty,
  repeat_penalty, seed`.
- **Idle TTL / auto-evict:** the server can unload an idle model (TTL); a
  cold JIT load adds first-token latency that can exceed the app's
  `wait_for_llm_ms` budget on the very first dictation.

### 2.2 Best-practice rules derived from the above

1. **Discover models via `GET /v1/models`** rather than asking the user to
   type a model filename — removes the #1 source of "400 unknown model".
2. **Always send `temperature` (low, ~0.0–0.2)** for transcript cleanup; the
   task is deterministic editing, not creative generation.
3. **Non-streaming is fine** for polish (short inputs, single short output),
   matching the existing providers; do not add streaming scope creep.
4. **Surf latency honestly.** A local model can be slower than a cloud one on
   CPU; the provider should expose time-to-first-token / total to the
   Diagnostics page so the user can tune `wait_for_llm_ms` and `num_threads`.
5. **Treat "server not running" as a distinct, actionable error** ("Is LM
   Studio running with the server started?") rather than a generic HTTP error.
6. **Never store a token in config** — reuse the existing keychain secret
   store (empty default = no auth, matching LM Studio's local default).

## 3. Design

### 3.1 Introduce a dedicated `lm_studio` provider

Add a third provider dispatch arm in `cleanup/providers/mod.rs::create`,
alongside the existing `nvoos` and `openai_compat` arms:

```
match settings.cleanup.provider.as_str() {
    "disabled" | "" => Ok(None),
    "lm_studio"  => Some(LmStudioProvider::new(&settings.lm_studio, secrets)?),
    "openai_compat" => …,
    "nvoos" => …,
}
```

The provider is thin: it has no provider-specific auth model beyond an
optional bearer token, and it shares the OpenAI-compatible request/response
shape. To avoid duplicating the `openai_compat` request+parse logic, extract
the shared HTTP body/parse helpers into a small module
(`cleanup/providers/openai_shape.rs`) consumed by both, OR have
`LmStudioProvider` internally reuse `OpenAiCompatProvider`'s transport with a
different discovery path. **Decision to make at implementation:** prefer a
shared `openai_shape` transport module + two thin config adapters (cleaner
than inheritance, keeps `id()` distinct per provider).

### 3.2 Config: new `LmStudioConfig` settings block

Mirror `OpenAiCompatConfig` but add the fields LM Studio uniquely needs:

```rust
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(default)]
pub struct LmStudioConfig {
    /// http://localhost:1234/v1 by default (local). Remote/headless
    /// servers are allowed only over https:// (same invariant as nvoos).
    pub base_url: String,
    /// LM Studio model identifier (from GET /v1/models), or "" to send the
    /// server's single loaded model (omit the field).
    pub model: String,
    /// Idle-TTL awareness: wait up to this many ms for a cold JIT model
    /// load before giving up (0 = rely on wait_for_llm_ms only).
    pub load_timeout_ms: u64,
}

impl Default for LmStudioConfig {
    fn default() -> Self {
        Self {
            base_url: "http://localhost:1234/v1".into(),
            model: String::new(),
            load_timeout_ms: 0,
        }
    }
}
```

Add `pub lm_studio: LmStudioConfig` to `Settings` (with `#[serde(default)]`).

### 3.3 Keychain: optional bearer token

- New secret key constant `KEY_LM_STUDIO_TOKEN = "lm-studio/bearer-token"`.
- Create a normalizer so `base_url` ending in `/v1` vs `/` is tolerated
  (trim trailing `/`, then if it does not already end in `/v1`, leave as-is
  — but document `/v1` is required). The `model` id is validated by the
  discovery call, not by token pre-checks.

### 3.4 Discovery + test-connection IPC (the UX win)

Add one IPC command mirroring the existing `test_nvoos_connection`:

```
pub async fn test_lm_studio_connection(state) -> AppResult<LmStudioConnectionTest>
```

`LmStudioConnectionTest` returns:
- `ok` / `message` (same spirit as `ConnectionTest`)
- `models: Vec<String>` — the resolved model identifiers from `GET /v1/models`
  (populated on success, so the Settings UI can populate a dropdown).
- `recommended_model: Option<String>` — the single loaded model, if exactly
  one is loaded (per §2.1 single-model behavior).

The Settings UI (`src/views/Settings.tsx`) gets a new **"LM Studio"** tab with:
1. Base URL field (default `http://localhost:1234/v1`).
2. **"Test connection"** button → calls `test_lm_studio_connection`, shows the
   list of discovered models, and pre-selects the single loaded model.
3. **Model dropdown** populated by the discovery result (fallback to a free
   text field for headless servers / manual entry).
4. Optional bearer-token panel (reusing the existing `ApiKeyPanel` / secret
   IPC pattern) — empty = none.
5. "Not running" hint surfaced when the connection test fails (actionable
   text, not a raw error).

Model discovery is also surfaced into `StatusInfo`/`DiagnosticsInfo`
(`credentials.lm_studio_configured` + a cached model list), consistent with
how the nvoos/openai keys already report.

### 3.5 Provider polish behaviour and error mapping

`LmStudioProvider::polish`:
- Reuse the `system_prompt_for(category)` already in `openai_compat.rs`
  (move to the shared module) — the dictation-editor instructions are
  provider-agnostic.
- `temperature: 0.1` (lower than OpenAI's `0.2`; local models drift more).
- Omit the `model` field entirely when `config.model` is empty (LM Studio
  routes to the single loaded model).
- Map errors:
  - connection refused / DNS → `"LM Studio is not reachable — is it running
    with the server started?"`
  - HTTP 404 on `/v1/models` → "wrong base URL (expects the /v1 path)"
  - HTTP 400 with "model" in the body → "model identifier unknown — use one
    from Test connection, or load it in LM Studio first"
  - `500`/timeout → surface LLM load/eviction hint (idle TTL).

All failures remain non-blocking to the paste (existing `wait_for_llm_ms`
contract in ADR 0001 already guarantees Stage-1 fallback).

### 3.6 Latency / diagnostics additions

Extend `LastDictationStats` (or the `PolishMeta` returned by the provider) with
`polish_ttft_ms` and `polish_total_ms` so the Diagnostics page can report
local-model polish latency; from there the user can tune `wait_for_llm_ms` and
`load_timeout_ms`. Keep this additive (serde defaults) to avoid breaking the
existing diagnostics shape.

## 4. Files to touch

| File | Change |
|---|---|
| `src-tauri/src/config.rs` | add `LmStudioConfig`, wire into `Settings` |
| `src-tauri/src/cleanup/providers/mod.rs` | add `lm_studio` arm + re-export |
| `src-tauri/src/cleanup/providers/lm_studio.rs` | new provider + discovery client |
| `src-tauri/src/cleanup/providers/openai_shape.rs` | (new) shared request/parse/`system_prompt_for` |
| `src-tauri/src/cleanup/providers/openai_compat.rs` | refactor to use shared module |
| `src-tauri/src/state.rs` | `KEY_LM_STUDIO_TOKEN`, `lm_studio_configured()`, `has_lm_studio_token()` |
| `src-tauri/src/ipc.rs` | `test_lm_studio_connection`, `set_lm_studio_token`, `clear_lm_studio_token`; extend `StatusInfo`/`CredentialsDiagnostics` |
| `src/lib/ipc.ts` | mirror new Settings shape, `StatusInfo`, `DiagnosticsInfo`, and the three IPC wrappers |
| `src/views/Settings.tsx` | new "LM Studio" tab + provider dropdown option + model discovery UI |
| `src/views/Diagnostics.tsx` | show `lm_studio_configured` + polish latency (optional) |
| `src-tauri/tests/*` | provider + discovery contract tests (see §5) |

## 5. Testing

- **Unit (no network):** request-body shape (temperature, model omission when
  empty), response parsing (string + segment-array content, mirroring the
  existing defensive parse), error mapping (refused vs 404 vs 400-with-model).
- **Discovery parse:** feed a canned `GET /v1/models` JSON payload and assert
  the returned ids + single-loaded recommendation.
- **Contract (no network, cassette fixtures):** `test_lm_studio_connection`
  against a `mockito`-style stub or a reqwest `MockClient` (the project
  currently has no network in CI — follow the `nvoos` cassette convention).
- **Gate matrix:** confirm the new provider compiles on the default feature
  set (no new optional deps expected — it reuses existing `reqwest`/`serde`),
  and add the provider's unit tests to the existing `cargo test` default run.
- Keep the golden cleanup tests untouched (Stage-2 is separate from Stage-1).

## 6. Out of scope (explicit)

- **Streaming** responses for polish (non-streaming matches existing providers
  and the short-output use case).
- **LM Studio's native `/api/v0` REST** (richer stats/load control) — a
  possible future enhancement, but the OpenAI-compatible surface is
  sufficient and maximizes reuse via `openai_shape`.
- **Remote LM Studio** automation (auto-start, model auto-load); the app
  discovers and reports, it does not manage the server lifecycle.
- **ollama** as a separate named provider — Ollama already works via
  `openai_compat`; this plan is LM-Studio-specific and can later be
  generalized into a "Local OpenAI-compatible" umbrella if desired.

## 7. Sequenced milestones

1. **M1 — config + provider + shared transport.** ✅ `LmStudioConfig`,
   `openai_shape.rs` extraction, `LmStudioProvider`, dispatch arm. Unit tests.
2. **M2 — discovery + IPC.** ✅ `test_lm_studio_connection`, secret key,
   Status/Diagnostics plumbing, `set/clear_lm_studio_token`.
3. **M3 — UI.** ✅ Settings "LM Studio" tab, model dropdown + auto-select,
   Test-connection, token panel; `ipc.ts` updates.
4. **M4 — diagnostics.** ✅ LM Studio credential row on the Diagnostics page
   (polish-latency `ttft`/`total` surfacing deferred — see §8).

### Verification (all green on the reference toolchain)

```bash
cd src-tauri
cargo fmt --check
cargo clippy --all-targets --offline -- -D warnings
cargo clippy --all-targets --offline --features mcp -- -D warnings
cargo test --offline            # 79 lib tests pass (incl. 4 lm_studio + 6 openai_shape)
```

Frontend: `npm run build` (tsc --noEmit + vite) passes clean.

### Files changed

- `src-tauri/src/config.rs` — `LmStudioConfig` + `OllamaConfig` wired into `Settings`
- `src-tauri/src/cleanup/providers/local_openai.rs` — new shared local-server transport (auth/timing/timeout/discovery, `LocalKind`-parameterized)
- `src-tauri/src/cleanup/providers/openai_shape.rs` — new shared OpenAI request/parse module
- `src-tauri/src/cleanup/providers/openai_compat.rs` — refactored onto shared module
- `src-tauri/src/cleanup/providers/lm_studio.rs` — thin `LocalKind` wrapper + discovery type
- `src-tauri/src/cleanup/providers/ollama.rs` — new thin `LocalKind` wrapper + discovery type
- `src-tauri/src/cleanup/providers/mod.rs` — `lm_studio` + `ollama` dispatch + exports
- `src-tauri/src/state.rs` — `KEY_LM_STUDIO_TOKEN`, `KEY_OLLAMA_TOKEN`, `has_*`, `*_provider()`
- `src-tauri/src/ipc.rs` — `test_lm_studio_connection`, `test_ollama_connection`, `set/clear_*_token`, Status/Diagnostics fields
- `src-tauri/src/lib.rs` — registered the six new provider commands
- `src/lib/ipc.ts` — Settings/Status/Diagnostics types + `LmStudioConnectionTest`/`OllamaConnectionTest` + wrappers
- `src/views/Settings.tsx` — LM Studio + Ollama tabs + provider options
- `src/views/Diagnostics.tsx` — LM Studio + Ollama credential rows + polish latency

## 8. Deferred / follow-ups

- ~~**Polish latency surfacing**~~ ✅ **Done** — `PolishMeta` now carries
  `ttft_ms` / `total_ms` (reported by the local `lm_studio` provider);
  `LastDictationStats` surfaces them as `polish_ttft_ms` / `polish_total_ms`,
  shown on the Diagnostics page under "Polish latency (TTFT / total)".
- ~~**`load_timeout_ms` enforcement**~~ ✅ **Done** — the local provider’s
  shared `local_openai` implementation stores `load_timeout_ms` and, when
  non-zero, wraps the request send in a `tokio::time::timeout` distinct from
  `wait_for_llm_ms`, with a dedicated "cold-load budget" error. A 60 s soft
  default bounds a hung local server even when the budget is 0.
- ~~**Ollama umbrella provider**~~ ✅ **Done** — a first-class `ollama`
  provider was added as a sibling of `lm_studio`. The shared local-server
  behaviour (optional bearer auth, model discovery, timing, cold-load timeout,
  error mapping) was extracted into `cleanup/providers/local_openai.rs`, with
  `LmStudioProvider` and `OllamaProvider` as thin `LocalKind`-parameterized
  wrappers. Ollama ships its own config block, keychain key, IPC commands,
  Settings tab, and Diagnostics row (default `http://localhost:11434/v1`).
- **Original open questions (§8 of the draft):**
  - `openai_shape.rs` extraction **approved** (Option A).
  - `temperature = 0.1` for local models **approved** (0.2 retained for
    the `openai_compat`/cloud path).
  - Ollama umbrella provider — now **implemented** (see above).

## 8. Open questions for review

- Approve the `openai_shape.rs` extraction vs. LM-Studio reusing
  `OpenAiCompatProvider` internally (provider `id()` must stay `lm_studio`).
- Confirm `temperature = 0.1` (vs 0.0) for local-model stability.
- Whether to also surface LM Studio alongside ollama under a future
  "local OpenAI-compatible" umbrella provider (deferred — see §6).
