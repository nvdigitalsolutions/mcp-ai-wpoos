# Docs Catch-Up Plan — Post-1.1.57 Fixes (PRs #5878–#5884)

> **Prepared:** 2026-08-17 · **Status:** ✅ **Executed 2026-08-17 (P0–P3 complete; P4 items 14–16 left for the next maintainer pass)**
> **Scope:** documentation/context updates only (no code changes)
> **Input:** full review of every commit on `alpha-working` from 2026-08-14 through 2026-08-17

---

## 1. Context: What changed in the last 3 days

Two docs catch-up releases landed, then six fix PRs merged **after** the
v1.1.57 catch-up (`1cf3ed937`, Aug 15) and are therefore absent from every
release-note surface:

| PR | Commit | Change | Doc status today |
|---|---|---|---|
| #5878 | `bc486243e` | Media-worker Dependabot 755/756: puppeteer ^25.7.0, uuid ^11.1.1 override (exceljs), Node engine floor ≥22.12.0, `PUPPETEER_SKIP_DOWNLOAD` in Dockerfile | `CREDITS.md` + `media-worker-docker-setup.md` updated ✅ · changelogs ❌ · security-checklist ❌ |
| #5879 | `6fcb5863d` | Attention routing no longer truncates assistant tool lists: route only above the chat payload cap (default 100, configurable in Tools → Configuration), return **all** tools when the vector service is unavailable, score against the actual user query, tool-definition token budget 48,000 | `includes/data/README.md` updated ✅ · changelogs ❌ · fix-history doc ❌ |
| #5880 | `10b6594e3` | MCP `prompts/list` + `prompts/get` scoped to the authenticated assistant (mirrors `tools/list` scoping) | nothing ❌ |
| #5882 | `a3cd979f5` | Queue worker standalone-CLI fatals: arg parsing before `wp-load`, `self::` in procedural scope, `--batch-size` passthrough | nothing ❌ (`bin/README.md` has no queue-worker section at all) |
| #5883 | `c7c7b1d89` | Queue worker: guard `null` from `AMQPQueue::get()` on empty queues (`instanceof AMQPEnvelope`) | nothing ❌ |
| #5884 | `fe3bc1593` | MCP `tools/call` runs with agentic-loop semantics — non-background tools complete synchronously in the JSON-RPC response (direct MCP clients can't poll background jobs) | `.context/rest-api.md` rule 5 + `implementation-plan-mcp-agent-compat.md` async section now partially stale ❌ |

### Verified current — no action needed

- `README.md` (v1.1.57 highlights), `readme.txt` (Stable tag 1.1.57 + changelog), `docs/QUICK_REFERENCE.md`, `docs/project/FOR_REVIEWERS.md`, `docs/project/ADDON_INVENTORY.md`, `MAINTAINER_MAP.md`
- Version constants (`includes/bootstrap/constants.php`, `mcp-ai-wpoos.php`, `package.json`) — all 1.1.57
- `CHANGELOG.md` — thorough PR-level detail for 1.1.55 → 1.1.57 (but stops at the Aug 15 merge)
- `AGENTS.md` — Hermes Console row, addon-level context-tree registration, fleet-operator `.context/` (nested `.context/.context/` duplicate from `87ba13a13` already cleaned up ✅)
- `.context/README.md` (indexes `media-worker.md` + addon trees), `.context/media-worker.md` (Phase 3 W1–W7), `addons/media-worker/README.md` (Phases 2–3, endpoints, security model)
- `bin/README.md` — Hermes MCP server (async submit/poll, skill sync), SSH bridge ✅
- `docs/operations/fleet/hermes-operator-setup.md` (§7 async chat ✅)
- `.zed/settings.json` (Hermes Console profile), proposals index (028 registered), `docs/DOCUMENTATION_INDEX.md` (Aug 13–15 sections)

---

## 2. Work items (priority order)

### P0 — Release-note surfaces (user-facing, must-have)

1. **`CHANGELOG.md`** — extend `[1.1.57]` with a `### Fixed — post-release (PRs #5878–#5884)` subsection, one bullet-group per PR following the existing PR-level style. The 1.1.57 build artifacts were regenerated after these merges, so they belong under 1.1.57 (no version bump needed).
2. **`readme.txt` changelog** — append the same summary to the `= 1.1.57` entry (the wp.org changelog format is one compact paragraph).
3. **`docs/DOCUMENTATION_INDEX.md`** — add an `### New and updated documents (August 16–17, 2026)` block under the `August 2026 — v1.1.55–v1.1.57` header, linking the new fix docs (below) and the changelog update. Also backfill the Aug 15 block: it omits the README/CHANGELOG/QUICK_REFERENCE updates that `1cf3ed937` made.

### P1 — Agent context files (correctness for future agents, must-have)

4. **`.context/rest-api.md`** — two updates in the "MCP Endpoint Transport & Error Semantics" section:
   - Replace/augment rule 5: direct `tools/call` requests now set `agentic_loop` so non-background tools return synchronously; async polling (`mcp_wait_for_async_tool()`) only applies to background-only tools (Priority 1) and other async paths. This rule currently describes the pre-#5884 behavior.
   - Add a rule: `prompts/list` and `prompts/get` are scoped to the authenticated assistant (token-bound → own assistant only; out-of-scope slugs return not-found), mirroring `tools/list`.
5. **`.context/security-checklist.md`** — add a `v1.1.57 updates:` note to the header changelog block: puppeteer ^25.7.0 / uuid ^11.1.1 (GHSA-jmr9-qjv8-65gv, GHSA-w5hq-g745-h8pq) and MCP prompts assistant-scoping (cross-assistant info-leak prevention).
6. **`.context/media-worker.md`** — Canonical Facts: add "Node engine floor ≥ 22.12.0 (puppeteer 25 requirement; Docker/CI images are node:22)".

### P2 — Operational docs

7. **`bin/README.md`** — new "Queue Worker" section for `bin/queue-worker.php` (this gap predates the 3-day window but the two fixes make it urgent): purpose (RabbitMQ consumer), CLI flags (`--queue`, `--memory-limit`, `--timeout`, `--batch-size`), the wp-load ordering constraint (no `absint()`/`apply_filters()` before `wp-load`), empty-queue null-envelope guard, and a pointer to `docs/project/proposals/009`/`012` for architecture.
8. **`docs/developer/implementation-plan-mcp-agent-compat.md`** — add a short note to the bounded-async-polling section: as of #5884, direct MCP `tools/call` completes synchronously (agentic-loop semantics); the 45s poll budget applies to background-only tools.

### P3 — Fix-history docs (repo convention: `docs/history/2026/fixes/`)

9. **`attention-routing-truncation-fix.md`** (PR #5879) — symptom (tools hidden above 40/30-tool threshold), root cause, new behavior (payload-cap threshold, fail-open on vector-service outage, user-query scoring, 48k token budget, Tools Configuration controls).
10. **`mcp-prompts-assistant-scoping-fix.md`** (PR #5880) — prompts/list flooding + prompts/get cross-assistant leakage → `resolve_assistant_id()` + `apply_token_assistant_scope()`.
11. **`queue-worker-cli-and-empty-queue-fixes.md`** (PRs #5882 + #5883, one doc) — standalone CLI fatals + null envelope guard.
12. **`mcp-tools-call-sync-dispatch-fix.md`** (PR #5884) — async-flagged tools exhausting the 45s poll budget on hosts with unreachable WP-Cron loopback → synchronous dispatch for non-background tools.
13. *(Skip #5878 — Dependabot bumps are covered by `CREDITS.md` + changelog, per existing convention.)*

### P4 — Optional drift checks

14. **`readme.txt` header** — description says "13 AI providers" / "250+ built-in tools" while `CHANGELOG.md` says 15 providers / ~265 base tools. Verify intent (wp.org marketing copy is sometimes conservative) and reconcile if stale.
15. **`MAINTAINER_MAP.md`** — bump "Last reviewed" to August 17 once this work lands.
16. **`addons/fleet-operator/.context/README.md`** — confirm no stale references to the removed nested `.context/.context/` path.

---

## 3. Execution notes

- **Docs-only PR**, matching the repo's established catch-up pattern
  (`chore/v1.1.57-docs-catch-up` → suggest `docs/post-1157-fixes-catch-up`).
  No PHP/JS changes, so the PHPUnit/JS suites are unaffected.
- **Validation:**
  - `composer run docs:check-folder-readmes` — if any folder README is touched (`includes/data/README.md` was already updated inside PR #5879, no action needed).
  - `composer run lint:errors-only` — not required for markdown-only changes.
- **Do not bump the version** — all six fixes are part of the already-tagged 1.1.57 build (zips regenerated post-merge); a bump would re-trigger the release pipeline unnecessarily.
- ~11 files touched, all under `docs/`, `.context/`, `bin/README.md`, `CHANGELOG.md`, `readme.txt`.

## 4. Suggested commit breakdown

1. `docs: changelog and index catch-up for post-1.1.57 MCP fixes` — CHANGELOG.md, readme.txt, DOCUMENTATION_INDEX.md
2. `docs: update agent context for MCP scoping, sync dispatch, queue worker` — `.context/rest-api.md`, `.context/security-checklist.md`, `.context/media-worker.md`, `bin/README.md`
3. `docs: add fix history for PRs #5879–#5884` — `docs/history/2026/fixes/*` (4 new docs)
4. *(optional)* `docs: reconcile readme.txt provider/tool counts`
