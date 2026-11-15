# 128 — NiceDB Assessment & Safety-Gates Adoption (Research + Notes)

- **Date:** 2026-11-07
- **Status:** Assessment recorded; the one transferable pattern (typed-confirmation + audit log for destructive operations) **shipped** into the memory layer (Proposal 127) the same day.
- **Subject:** <https://github.com/maxib0n/NiceDB> — "a desktop database client built around one rule: production is read-only until you decide otherwise." Rust + Tauri 2 + Svelte 5, GPL-3.0-or-later (+ §7 carve-out), v0.1 (first public release, 6 commits, 9 stars, unsigned binaries).

## 1. Assessment — not for incorporation

NiceDB fails the filters that decide what the NV oOS Cognition app adopts.
(Verified facts against the README / repo on 2026-11-07.)

| Filter | NiceDB | Verdict |
|---|---|---|
| **License** | GPL-3.0-or-later | **Hard blocker.** The app is proprietary (`NOTICE.md` maintains the boundary: no GPL code linked or embedded). Linking `nd-core`/`nd-drv-*` would breach it. |
| **Mission** | DBA tool: ~20 engines (Postgres → DynamoDB, DuckDB), query plans, sessions/locks, schema diff | Off-axis. No user-story overlap with capture + AI assistant; the only shared surface is SQLite, for which a full DB client is a sledgehammer. |
| **Maturity** | v0.1, 6 commits, 9 stars, no code signing | Too young to depend on. Contrast the SurfSense bar (16.8k stars, ~12k commits, ADR discipline) that justified Proposal 127. |
| **Weight** | Compiles SQLite, DuckDB and unixODBC from source; per-engine driver crates; Svelte UI (we are React); pnpm; pinned toolchain | Violates house rules (minimal deps, pure Rust, heavy runtimes outside the binary). |

If a DB-browsing surface is ever wanted, two better homes exist — a
read-only SQL browser panel built on the DB layer the app already owns,
or the WordPress plugin side (GPL-compatible there).

## 2. What was worth stealing — the safety model

NiceDB's write-safety model is the genuinely excellent part:

1. **Read-only by default** — production connections open read-only; a
   "stamp" marks them everywhere.
2. **Timed unlock** — writing opens for ten minutes with the countdown in
   view, then re-locks itself.
3. **Typed confirmation** — before a change is applied, the UI shows what
   it touches and asks you to type the connection's name; `UPDATE`/`DELETE`
   without `WHERE`, `DROP`, `TRUNCATE` ask before they run.
4. **The log** — every statement that changed something is kept (who,
   when, where, row counts), append-only.
5. **One gate point** — "every write passes through one place in the core,
   so no screen can skip a step."

This is the database-client analogue of the egress-consent discipline
adopted from SurfSense (hosts off by default, one consent question per
user action, never a prompt on a background read). The one piece the
Cognition app did not yet have was the **typed confirmation + append-only
audit log** for its own destructive operations.

## 3. What shipped (Proposal 127 hardening)

Applied to the memory layer's two destructive actions, `purge_memory` and
`reindex_memory` — which were one click away in Settings:

- **Backend is the authority.** `memory/controller.rs` is the single gate
  point (`require_confirmation`): `purge` requires the caller to type
  `PURGE`, `reindex` requires `REINDEX` (exact, case-sensitive, after
  trim). The IPC commands take a `confirmation` argument and reject
  mismatches with a clear error — no surface can skip the step, and a
  refused call touches neither corpus nor log.
- **Append-only audit log.** New `memory_ops` table (`action`, `detail`,
  `created_at`) — the controller writes one row per completed destructive
  op with the row counts; nothing in the app deletes from it, and
  `purge_memory` wipes the corpus but never its own audit trail.
- **Visibility.** `MemoryStatus.recent_ops` (last five, newest first)
  renders on the Diagnostics → Memory card as "Destructive operation
  log"; the Settings → Memory tab shows inline "Type REINDEX / Type PURGE"
  inputs with the buttons disabled until the exact word is typed, and the
  refused/failed path surfaces the backend's message.
- **Tests.** `memory/controller.rs` unit tests (exact + case-sensitive
  gate, refused op touches nothing, gated op logs) and
  `tests/memory.rs::memory_ops_log_is_newest_first_and_survives_purge`.

Deliberately **not** adopted now (recorded for later): the timed-unlock
and per-connection stamp concepts — they need a multi-connection or
multi-tenant surface to hang on, which the app does not have yet. If a
DB-browsing panel ever lands, the full four-gate model is the template.

## 4. Sources

- NiceDB README / LICENSE / SECURITY.md: <https://github.com/maxib0n/NiceDB>
- Proposal 127 (memory layer): [`127-cognition-memory-layer-proposal.md`](127-cognition-memory-layer-proposal.md)
- SurfSense egress model (the prior art both projects converge on): `docs/architecture/egress.md` in <https://github.com/MODSetter/SurfSense>
