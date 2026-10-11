# ADR 0001 — Stage-2 paste strategy: never block the paste

**Status:** Accepted
**Date:** 2026-10-11

## Context

Dictation has a hard latency budget (~1–2 s key-up → pasted, per the Wispr
Flow / Superwhisper benchmark literature). Stage-2 LLM polish (NV oOS
assistant or OpenAI-compatible) can take seconds or fail entirely. A provider
failure, timeout, or bad credential must never leave the user without text.

## Decision

1. Stage-1 deterministic output is **always** paste-ready and pastes
   immediately unless the user sets `wait_for_llm_ms > 0`.
2. With `wait_for_llm_ms > 0`, the controller waits at most that window for
   the Stage-2 result and falls back to Stage-1 text on timeout/error.
3. With `wait_for_llm_ms = 0` (default), Stage-1 pastes immediately and the
   Stage-2 result is logged but not re-pasted (no double-paste, no
   select-all-replace fragility).
4. `replace_on_completion` is reserved for a future single-swap refinement;
   it does not change v1 behavior.

## Consequences

- Deterministic cleanup quality is the primary UX lever; the LLM pass is
  purely optional polish.
- Every transcript records its provider so output provenance is visible in
  History.
