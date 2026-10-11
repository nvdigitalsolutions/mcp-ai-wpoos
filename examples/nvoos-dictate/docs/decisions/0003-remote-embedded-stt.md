# ADR 0003 — Real dictation: local engines first, Embedded remote STT as fallback

**Status:** Accepted
**Date:** 2026-10-11

## Context

The app ships the deterministic `MockEngine` by default. Two routes lead to
real dictation:

- **Local engines** (parakeet via sherpa-onnx, whisper-rs, moonshine via
  ort): audio never leaves the machine — the core of the app's local-first
  privacy pitch and consistent with the < 1.5 s key-up → paste latency
  budget. Cost: first-compile API verification of the feature-gated stubs,
  plus multi-hundred-MB model downloads per engine.
- **Embedded addon remote STT**: `POST
  /wp-json/mcp-ai/v1/embedded/transcribe` accepts base64 WAV (≤ 10 MB, rate
  limit 30/min, 60 s timeout) and returns `{text, language}` by proxying to
  a Gemma 4 audio endpoint (Ollama/vLLM/NIM) on the user's own WordPress
  host. Zero local models, but audio transits the network and latency
  depends on the server.

## Decision

1. **Local engines are the primary real-dictation path.** Parakeet
   (sherpa-onnx) first, whisper/moonshine as fallbacks; first-compile API
   verification of the stubs and the SHA-256-verified model registry are on
   the critical path.
2. The `embedded` engine variant is the **fallback** for machines without
   local engines or models. It is off by default, clearly labeled
   "Embedded (plugin)" in the UI, and only ever sends audio toward the
   user-configured site.
3. **Embedded-path auth:** WP Application Passwords (Basic auth) first — it
   satisfies the route's `is_user_logged_in()` check with zero addon changes.
   A small addon PR may extend the route's `permission_callback` to accept
   assistant credentials (`cred_x.SECRET`) so the app reuses the credential
   it already stores. The guest filter is explicitly NOT used (it opens the
   route to the internet).

## Consequences

- Privacy and latency budgets are met on the primary path; the remote path
  is an opt-in trade-off (latency bounded by the WP host + Gemma endpoint,
  30/min rate limit).
- The parakeet/whisper/moonshine features move from "deferred" to the next
  implementation step, including their first-compile API drift fixes.
- New contract tests for the fallback: WAV header shape, request body,
  error mapping (401 definitive, 413, 429); cassette fixtures, no live
  network in CI.
