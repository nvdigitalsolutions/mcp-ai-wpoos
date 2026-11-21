# Proposal 160 — Tool Readiness Triage Plan

**Status:** Planned · **Date:** 2026-11-21 · **Owner:** platform
**Depends on:** 090-M (the readiness manifest), 149 §123–§134 (machine principals)
**Measured against:** `lib/core/resources/tool-readiness.php`, 2026-11-21

## 1. The measured state

| Fact | Value |
|---|---|
| Manifest rows | **223** |
| Tool classes in `lib/core/src/Tool` | 214 |
| **Servable** (`ready` + `pure` + `delegated`) | **96** |
| `ready` / `pure` / `delegated` | 68 / 22 / 6 |
| `unverified` | **108** |
| `quarantined` | 19 |
| Rows with MCP annotations | 115 |

The 108 unverified are constructible but carry **no evidence**. Under the
manifest's own vocabulary they are *not served*: the default for an unknown slug
is `unverified`, so failing to classify cannot make a tool callable. That default
is the reason this backlog is safe to leave — and the reason it must be cleared
deliberately rather than in bulk.

### 1.1 What the 108 are

| Group | Count |
|---|---|
| Image / file / vector-store | **21** |
| Video | 8 |
| Audio | 2 |
| Cache / cron / jobs / workflow | ~30 |
| Charts / search / research / agents / other | ~47 |
| **Document / PDF / OCR** | **0** |

There is no document-family backlog: documents are covered by Paper Store and
semantic search, which are already classified.

### 1.2 Toolkit manifests are not tool bundles

`resources/pro-toolkits/*.json` (21 of them, 1:1 with
`addons/pro/includes/toolkits/manifests`) contain `screens`, `settings`,
`entities`, `dashboard` and `tab_labels` — **no tool slugs**. A toolkit is an
admin screen specification. It cannot be "added to the machine allowlist": the
allowlist (`oos.machine_tools`, `/ops/machine-tools`) takes tool slugs, and the
picker only offers **servable** tools. The route to allowing document/media work
is classifying the individual tools those screens use.

## 2. Why this is a triage, not a bulk edit

A heuristic pass was attempted and **rejected on its own results**: scanning the
108 classes for I/O markers classified `generate_openai_image`, `deep_research`,
`web_search` and `upload` as "no I/O" — 105 of 108 came back clean. The marker
list missed the runtime's HTTP abstraction. Writing classifications from it
would have made network tools servable, which is precisely the failure the
vocabulary exists to prevent.

**Rule: a readiness decision must be defensible from the class's own code or
from a passing test that calls it. Nothing else counts.**

## 3. Standards basis

- **MCP tool annotations are a risk vocabulary, not a permission system.** The
  MCP guidance is explicit: set `readOnlyHint: true` on reads,
  `destructiveHint: false` on additive writes, `openWorldHint: false` on
  closed-domain tools, and add `idempotentHint` only where repeat-safety has been
  verified. Clients use them for retries and parallel calls — so an inaccurate
  hint is worse than a missing one.
- **Our runtime already treats them as a veto** (§126): `destructiveHint: true`
  or `readOnlyHint: false` routes a machine call to approval; `openWorldHint`
  alone does not veto; no annotation makes no claim. Triage must therefore
  annotate *accurately*, because the allowlist and the veto read the same rows.
- **Inventory before enablement** (OWASP NHI Top 10): a machine identity should
  only reach tools whose behaviour is recorded. `unverified` is the inventory
  state; this plan is the enablement pass.

## 4. The decision tree

Use the first rule that applies; record the reason in the row.

| # | Rule | Readiness | Evidence required |
|---|---|---|---|
| 1 | The class performs the work it claims and a test calls it successfully | `ready` | `evidence` = test path, `probe` = `call`, annotations |
| 2 | No I/O by nature and correctness is self-evident (pure computation) | `pure` | none; reason states the computation |
| 3 | The client performs the work; the server half only describes it | `delegated` | reason naming the client half |
| 4 | The class describes work it does not perform (stand-in, prompt builder, retired slug) | `quarantined` | reason + `replaced_by` when one exists |
| 5 | Needs a service this runtime does not bind | `unavailable` | reason naming the unbound service |
| 6 | None of the above is provable today | leave `unverified` | — |

`ready` and `pure` are the only paths that expand the servable set. Both require
the annotations an operator and a machine will read.

## 5. Annotation policy for newly servable rows

| Behaviour | Annotation |
|---|---|
| Reads only (fetch, list, inspect, transform without side effects) | `readOnlyHint: true`, `destructiveHint: false`, `openWorldHint` per whether it reaches outside |
| Adds or updates, repeat-safe | `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: true` |
| Adds or updates, not repeat-safe | `readOnlyHint: false`, `destructiveHint: false` |
| Deletes or overwrites | `readOnlyHint: false`, `destructiveHint: true` |
| Closed-domain (local computation) | `openWorldHint: false` |
| Reaches the open internet | `openWorldHint: true` |

The 21 image/file tools fetch an `image_url`, so they are **not** pure and not
closed-domain: `openWorldHint: true`, `readOnlyHint: true` for transformations,
`destructiveHint: true` for `delete_file`.

## 6. Batches

Each batch is one PR of **15–25 rows**, applied with
`php bin/audit-tool-readiness.php --classify=map.json`, followed by the gates in
§7. A batch that cannot prove a row leaves it `unverified` and says why.

### Batch 1 — image, file and vector-store (21 rows)

`resize_image`, `crop_image`, `rotate_image`, `convert_image_format`,
`create_image_variation`, `generate_*_image*` (7), `edit_*_image*` (3),
`generate_image_alt_text_validated`, `generate_image_caption_validated`,
`upload_file`, `delete_file`, `get_openai_file_details`, `list_openai_files`,
`manage_vector_store_files`.

- **Transformations** (`resize`, `crop`, `rotate`, `convert`): one evidence test
  with a faked fetcher, one per tool or one table-driven case each. `readOnlyHint:
  true`, `openWorldHint: true`.
- **Generators/editors**: need a provider fake; without one they stay
  `unverified`. Do not mark `ready` because the class "looks complete".
- **`delete_file`**: `destructiveHint: true`; it is the one row in this batch
  that a machine must never run unattended.
- **OpenAI file/vector-store tools**: `ready` only if the OpenAI Files/Vector
  Stores endpoints are actually called and testable; otherwise `unavailable`
  with the unbound-endpoint reason.

### Batch 2 — video and audio (10 rows)

Provider-dependent. Evidence needs a faked provider; `transcribe_openai_audio`
additionally needs a fixture file. Any row whose provider is not bound becomes
`unavailable`, not `ready`.

### Batch 3 — cache, cron, jobs, workflow (~30 rows)

Mostly local stores. `pure` for the arithmetic/decision helpers
(`calculate_erlang_c`, `erlang_c_*`), `ready` for the ones a test can call
against the database, `unavailable` where the service is not bound
(`purge_varnish_cache` when Varnish is absent).

### Batch 4 — charts, search, research, agents (~47 rows)

The riskiest: network and LLM calls. `generate_chart` / `create_chart` are pure
rendering and should classify early; `web_search`, `deep_research`,
`query_remote_site` and the agent tools need a fake transport and a reason that
names what they reach.

## 7. Gates per batch

| Gate | Command | Pass condition |
|---|---|---|
| Manifest integrity | `php bin/audit-tool-readiness.php` | exit 0, no `unclassified`, vocabulary clean |
| App suite | `php -d memory_limit=768M vendor/bin/phpunit` (container) | green |
| Registry parity | `ToolsReadinessTest` | every servable slug constructs |
| Picker | `/ops/machine-tools` | only the newly servable tools appear; no `unverified` row does |
| Reason quality | review | every decision has a reason a reviewer can check |

A `ready` row without an `evidence` path that exists in the tree is a batch
failure, not a warning.

## 8. Risks and rollback

| Risk | Mitigation |
|---|---|
| A wrong `ready` makes a tool machine-callable | evidence test required; annotations set from the class's behaviour; §126 veto still routes destructive tools to approval |
| Provider spend during tests | fake the transport; no live calls in CI |
| Manifest drift | `--write` only adds rows; `--classify` only merges a decision, never invents one |
| Batch too large to review | 15–25 rows per PR, one domain per PR |
| Rollback | the manifest is one file; reverting the batch commit restores the prior state exactly |

## 9. What this does for the allowlist

Only servable tools appear on `/ops/machine-tools`. Clearing a batch therefore
does three things at once: it makes the tools **listable**, it makes them
**annotated** (so the veto is accurate), and it makes the machine gate's
behaviour **reviewable** — an operator can see, per row, whether a machine would
run it or be routed to a human.

It does **not** grant anything. Allowlisting is a separate operator action; an
allowlisted state-changing tool still queues an approval (§124, §126).

## 10. Definition of done

- 0 `unverified` rows, **or** every remaining row carries a reason it cannot be
  classified today (e.g. "provider not bound, revisit with the media worker").
- Every servable row has annotations.
- The picker's list is exactly the servable set — no more, no less.
- The plan's batches are recorded in 149 as they land, with counts.
