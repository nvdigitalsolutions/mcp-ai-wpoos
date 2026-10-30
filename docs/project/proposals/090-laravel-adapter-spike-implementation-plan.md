# Proposal 090 — Laravel Adapter Spike (v1.4.0) Implementation Plan

**Status:** Proposal (awaiting execution)
**Date:** 2026-10-25
**Relates to:** ROADMAP v1.4.0 "Laravel Adapter Spike" · `laravel-scale-deployment-architecture.md` · `085-funiq-laravel-standalone-integration-appendix.md` (Phase B gate 3) · `fastapi-porting-implementation-plan.md` §18 decision #15 · `cross-platform-extraction-architecture.md` · `PROPOSALS_COMPLETION_STATUS.md` (next-priority #2)
**Author:** NV oOS coding agent (Zed)

---

## 1. Objective

Prove the Laravel standalone track **before** any Phase B / GA commitment.
One definition of done: the shipped engine (`nvoos/core`) running on
**Laravel Octane (FrankenPHP)** with the shipped adapters
(`nvoos/laravel-adapter`), executing one real tool end-to-end and
delivering one SSE stream — all without WordPress in the request path.

This is the repo's stated v1.4.0 spike (4–6 weeks). It is the de-risking
step for the adapter's missing production track record, and it clears the
spike gate (gate 3 of §8.1) in
`docs/project/plans/funiq-bridge-velocity-storefront-implementation.md`.

## 2. Background — shipped vs unproven

| Asset | State | What the spike must prove |
|---|---|---|
| `lib/core/` → `nvoos/core` (MIT) | ✅ Shipped — engine, `ChatOrchestrator`, `ProviderRouter`, 109 tools | Runs outside WordPress with zero WP dependencies loaded |
| `lib/laravel-adapter/` → `nvoos/laravel-adapter` | ✅ Shipped — all 8 adapters, Eloquent models, migrations (`nvoos_posts`, `nvoos_terms`, `nvoos_post_term`, `nvoos_settings`, `nvoos_schedules`, `nvoos_files`, `nvoos_file_metadata`), `NvoosToolJob`, tests | Correct at runtime — the contracts hold under Octane's persistent workers |
| `docker/docker-compose.laravel.yml` + `docker/laravel/` | ✅ Dev scaffold exists (PHP 8.2 CLI image, MySQL 8.0, Redis 7; entrypoint boots the Laravel dev server) | Extended to run Octane — the current image has **no Octane/FrankenPHP yet** |
| `laravel-scale-deployment-architecture.md` | 📋 Proposal — Octane + Horizon + Reverb + pgvector | Its core claims (3–10× vs PHP-FPM, persistent warm boot) verified on the real engine |

## 3. Scope

| In scope | Out of scope |
|---|---|
| Dev-environment Octane (FrankenPHP) wiring | Production deployment, Horizon/Reverb production wiring |
| One real tool end-to-end through the engine | Tool porting beyond the one tool |
| One SSE stream with disconnect resilience | pgvector production setup (optional smoke only) |
| One queue job via `QueueClientInterface` (Redis) | Funiq service build, console re-scoping |
| One `ContentStoreInterface` persistence round-trip | Golden-contract suite (separate Funiq gate 4) |
| MCP smoke: `initialize` + `tools/list` (streamable HTTP) | MCP directory submission |
| Adapter unit-test suite green | |

## 4. Environment

Start from the existing scaffold:

```bash
docker compose -f docker/docker-compose.laravel.yml up -d
```

The spike must **extend the dev image** to run Octane (the current
entrypoint boots the Laravel dev server; Octane requires
`laravel/octane` + the FrankenPHP binary). Composer path repos already
mount `lib/{core,laravel-adapter}` into the container.

Octane production hardening from the Laravel-scale doc's research applies
even in dev so the spike measures the right thing: `--max-requests=500`
(leak protection), `octane.warm` pre-fork, OPcache with
`validate_timestamps=0` in any benchmark run.

## 5. Pass criteria

Each criterion is a checkbox; all of P1–P7 must pass for a **Go**.

- [ ] **P1 — Boot.** Octane (FrankenPHP) serves `/health` with the service
      provider bound (all 8 adapters resolve). No WP symbols loaded; a
      guard asserting `! defined( 'ABSPATH' )` proves it.
- [ ] **P2 — One real tool.** A self-contained, read-only core tool
      (candidate: `get_environment_status`; any key-free core tool
      qualifies) executes end-to-end through tool dispatch and returns the
      canonical envelope (success array or error — no `success => false`
      pattern).
- [ ] **P3 — One SSE stream.** A chat stream is delivered over Octane
      (provider may be local — Ollama/LM Studio — or mocked; no external
      API keys required). A mid-stream client disconnect does not crash
      the worker.
- [ ] **P4 — One queue job.** A job dispatched through
      `QueueClientInterface` lands in the Redis queue, is executed by a
      worker, and a retry is observed on a forced failure.
- [ ] **P5 — Persistence round-trip.** One entity written and re-read
      through the `ContentStoreInterface` adapter over the shipped
      Eloquent models/migrations.
- [ ] **P6 — MCP smoke.** `initialize` handshake + `tools/list` over the
      Streamable HTTP transport (single endpoint, POST + GET), per MCP
      spec 2025-06-18. This verifies the PHP-MCP-tooling risk.
- [ ] **P7 — Regression.** `lib/laravel-adapter` PHPUnit suite green
      (`vendor/bin/phpunit` against its `phpunit.xml`).

## 6. Timeline (4–6 weeks, per ROADMAP v1.4.0)

| Week | Slice | Exit |
|---|---|---|
| 1 | Octane in the dev image, composer path repos, service-provider binding | P1 |
| 2 | Tool dispatch + persistence round-trip | P2, P5 |
| 3 | Queue job + SSE stream | P4, P3 |
| 4 | MCP smoke + hardening (`--max-requests`, OPcache) | P6 |
| 5 | Concurrent-stream soak (≥10 simultaneous SSE streams, 30 min) | no worker crash/leak |
| 6 | Write-up + bookkeeping | Go/No-Go decision |

## 7. Bookkeeping on completion

- **Go** → tick the spike gate in
  `docs/project/plans/funiq-bridge-velocity-storefront-implementation.md`
  §8.1 (gate 3), update `PROPOSALS_COMPLETION_STATUS.md` (next-priority
  #2), bump the status line in `laravel-scale-deployment-architecture.md`,
  and open Funiq T1 planning (the 085 appendix §7 sequence).
- **No-Go** → reopen G9 with the collected evidence; the FastAPI plan
  (§19.2) becomes active again.
- Results are recorded in a follow-up
  `090-laravel-adapter-spike-report.md` (or this doc's status line is
  updated with the outcome table).

## 8. Flip conditions — what reopens G9 (decision #15)

These are the documented triggers. A spike failure alone is not one; a
failure **in one of these modes** is.

| # | Trigger | Consequence |
|---|---|---|
| F1 | Octane/FrankenPHP instability — worker crashes, memory growth, or stream corruption under the §6 week-5 soak | Laravel track loses its performance premise; FastAPI plan revived |
| F2 | PHP MCP tooling cannot meet the Streamable HTTP spec (sessions, `Last-Event-ID` resumability, `Origin` validation) | Evaluate gateway-only MCP (Express) + FastAPI engine |
| F3 | Adapter contract defects that require **engine redesign** — WordPress assumptions leaking through the hexagonal layer | The "reuse" premise fails; Laravel track cost rises toward the FastAPI port cost |
| F4 | Strategic shifts, independent of spike outcome: ML-heavy product pivot, Python-first hiring, or decline of the WP plugin business | Re-evaluate per decision #15's conditions |

## 9. References

- `laravel-scale-deployment-architecture.md` (Octane/Horizon/Reverb/pgvector research)
- `085-funiq-laravel-standalone-integration-appendix.md` §1–§7
- `fastapi-porting-implementation-plan.md` §18 (decision #15)
- `cross-platform-extraction-architecture.md`
- `docker/docker-compose.laravel.yml`, `docker/laravel/`
- MCP specification 2025-06-18, "Transports" (Streamable HTTP)
