# Proposal 090-i — The remaining eleven console surfaces

**Status:** Recommendation recorded 2026-11-14; MCP apps, tool shortcuts, the command centre, slash commands, media processing and remote sites shipped the same day. **39 of 45** route-backed keys now report `true` from `/features`.
**Remaining:** `chatChannels`, `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor` — a toolkit-shaped family and four discovered products.
**Related:** `090-b-laravel-admin-surfaces-proposal.md` (ADR-6, ADR-8), `090-d-selective-tool-port.md`, `090-h-workflow-engine-proposal.md`.

---

## 1. Why a strategy is needed

The last ten merges each had the same shape: a console client existed, its WordPress controller was the reference, and the work was a faithful port. That stops being true here. Of the eleven remaining keys, only **two** are ports in that sense. The rest divide into surfaces that are inherently WordPress-hosted, surfaces that belong to separate products, and one that needs a **connected external service** — the opposite kind of work.

Continuing key-by-key would produce two failure modes the repo has already paid for once:

- **Forking products** — reimplementing `contentGraph`/`docsHub`/`mediaStudio`/`documentEditor` on Laravel creates a second implementation of a shipped plugin, which then drifts (the exact class of bug `090-d` records per tool).
- **Dead keys with no explanation** — eleven endpoints that answer "unavailable" with no machine-readable *reason*. The console blanks the surface, the operator cannot tell "not ported yet" from "WordPress-only by design" from "needs a service you have not configured".

## 2. Classification — the frame that decides each key

| Class | Meaning | Work type |
|---|---|---|
| **A. Runtime-adaptable** | The feature is runtime-neutral once its host dependency is put behind a contract | Port / extract |
| **B. Host-coupled admin UX** | The feature *is* a WordPress-admin concept; the console already has or can have a native equivalent | Either a small native surface or an explicit retirement |
| **C. External product** | A separate plugin with its own REST contract and JS island | Discovery at runtime, never reimplementation |
| **D. Connected service** | Needs an external daemon or third-party server to mean anything | Integration + honest unavailability when unconfigured |

| Key | What the console actually calls | Class | Recommendation |
|---|---|---|---|
| `mcpApps` | `GET/PUT mcp-ai/v1/mcp-apps/{assistant_id}` (assigned MCP servers, secrets masked) | **A** | **Build in core.** The only remaining key that unlocks a family rather than a page |
| `remoteSites` | `mcp-ai-pro/v1/remote-sites` (connections to other WordPress sites) | **A→B** | **Shipped** for the two types the runtime can verify; vendor types stay in wp-admin |
| `commandCenter` | `mcp-ai-pro/v1/command-center/overview` (KPI row over agents/workflows/cost) | **A** | Port — it aggregates data this runtime already owns |
| `shortcuts` | `mcp-ai-pro/v1/tool-shortcuts` (assistant-scoped tool presets) | **A** | Port — a small record read |
| `slashCommands` | catalogue + `/slash-commands/execute` + audit table | **A** | Port a **native subset** — the base executor plus the Pro state-mutating handlers are not portable |
| `chatChannels` | channel command center (messages, contacts, delivery) | **A**, but a **toolkit family** | Model as a manifest-driven communications toolkit, not a bespoke controller |
| `mediaCc` | `mcp-ai-pro/v1/media-cc/health` + sweep trigger | **D** | **Worker-backed**: local library + worker health + real sweep; only `schedule` remains without a source |
| `contentGraph` | `nvoos-content-graph/v1` (separate plugin) | **C** | Discover, don't port |
| `docsHub` | `nvoos-docs/v1` (separate plugin) | **C** | Discover, don't port |
| `mediaStudio` | `nvoos-media-studio/v1` (island addon) | **C** | Discover, don't port |
| `documentEditor` | `nvoos-document-editor/v1` (island addon) | **C** | Discover, don't port |
| `mcpApps` (again) | — | **A** | Also the *integration mechanism* for Class C, once a product exposes an MCP server |

## 3. Standards this adopts, and why each one

### 3.1 A capability manifest, not a boolean map (cross-cutting, cheapest win)

`EndpointCapabilities::map()` is already a capability document — it is computed from the live router, asserted by both CIs, and drives the console's degradation. Industry practice for capability discovery (MCP `initialize` capabilities, OpenAPI `servers`, `.well-known` documents) adds three things this map lacks:

- **a reason** for every `false`,
- **since/sunset** metadata for retirement,
- **a version** the client can branch on.

**Recommendation:** `EndpointCapabilities` v2 — each key resolves to `{ available, reason?, since? }` where `reason` is one of `wordpress_only`, `external_product`, `external_service`, `not_implemented`, `admin_only`. `/features` keeps the flat boolean map for compatibility and adds `endpoint_reasons`. The console renders "Not available on this runtime: external product" instead of a blank.

Standards: capability discovery as in the MCP lifecycle; **RFC 8594** (`Sunset`) for anything scheduled to disappear; **OpenFeature** (CNCF) if flags ever need context-based targeting — the current config booleans are fine until then.

### 3.2 MCP client: follow the specification, not intuition

`mcpApps` is an **MCP client**. The MCP spec's security guidance (2026-07-28) is explicit about the attacks an MCP client creates, and each maps to a control the Laravel implementation must carry:

| Spec requirement | Where it lands |
|---|---|
| Tokens must be issued **for the MCP server**; passthrough forbidden | One credential per connection, audience-bound; never reuse the caller's session token |
| SSRF: HTTPS only, block private/link-local/metadata ranges, validate redirect hops, pin DNS | `UrlGuard`-equivalent in core, applied to every discovery URL (`resource_metadata`, `authorization_servers`, `token_endpoint`) |
| OAuth 2.1 + **RFC 9700**: PKCE mandatory, exact redirect URIs, no implicit | OAuth client flow, per connection |
| **RFC 8707** resource indicators, **RFC 9728** protected-resource metadata, **RFC 7591** DCR, **RFC 9207** `iss` | Discovery + mix-up defence |
| Scope minimisation / step-up | Request the baseline scope; re-authorise on `WWW-Authenticate` challenge |
| Secrets never logged, masked in responses | The existing WordPress behaviour ("secrets masked") becomes a test, not a convention |
| Consent per client, `__Host-` cookies, CSP `frame-ancestors` | Only if a connection-proxy/consent page is ever added |

**MCP Apps (SEP-1865, `ui://` resources)** is the standard path for a server to ship interactive UI. Two consequences: (a) the client should parse `resources/list` + `ui://` now, even if the console renders results as JSON; (b) it is the **route out of Class C** — a product that speaks MCP Apps needs no per-product port.

### 3.3 Extract, don't rewrite (the `OkfParser` precedent)

The Pro addon already ships this stack: **6,602 lines** across `mcp-apps/` — client (1,508), OAuth client (1,397), registry (1,366), tool bridge (346), REST controller (1,656), init (329). It already handles dual-era handshakes, legacy/stateless fallbacks and internal same-origin dispatch.

Rewriting it would discard that hardening. The repo has a documented pattern for exactly this: `OkfParser` was **extracted** from `WP_MCP_AI_OKF_Parser` into `lib/core` under Proposal 145, with a **shared golden corpus** (`tests/fixtures/okf/parser/`) replayed by both implementations so neither drifts.

**Recommendation:** extract the MCP stack the same way — transport behind `HttpClientInterface`, secrets behind a store contract, WordPress REST dispatch behind a `same-origin` port, and a `tests/fixtures/mcp/` wire corpus pinning handshake frames. **Licence is a decision, not a detail** (§9).

### 3.4 Contracts already answer "did we drift?"

`contracts/console-endpoints.json` plus assertions on both CIs is consumer-driven contract testing (the Pact pattern) without the tooling. Extend it rather than add a framework: add the reason map (§3.1) to the same fixture, and add a wire corpus for MCP (§3.3). For errors, adopt **RFC 9457** (`application/problem+json`) only on new surfaces — retrofitting the existing envelopes would break clients for no gain.

### 3.5 Class C: anti-corruption, not reimplementation

The four products are separate plugins with their own REST bases (`nvoos-content-graph/v1`, `nvoos-docs/v1`, …) and JS islands. Two industry patterns apply and both say the same thing:

- **Anti-Corruption Layer** (DDD): the runtime may *translate* an external product's model, never adopt or fork it.
- **Strangler fig**: new capability grows alongside the old; the old is retired when the new proves itself.

**Recommendation:** a `connectedApps` registry — configured base URLs probed at runtime (health + version), reported through the same capability manifest, with the console mounting islands only against a live upstream. When a product later exposes an MCP server, prefer that path and let `mcpApps` be the single integrator.

**Checked, not assumed:** no Class C product exposes an MCP server today (`plugins/nvoos-content-graph` registers only `nvoos-content-graph/v1` REST). The existing MCP surfaces are the gateway (`addons/mcp-gateway` → `mcp.nvoos.pro`, which fronts NV oOS tools) and the Astro storefront's `/mcp` mount. Phase 4 is therefore REST-probe first, MCP later.

## 4. Per-surface decisions

| Key | Verdict | Effort | Depends on |
|---|---|---|---|
| `mcpApps` | **Extract to core**, add connection store + REST + tool bridging | **L** (3 PRs) | Licence decision (§9), `HttpClientInterface` |
| `remoteSites` | **Shipped** — catalogue + CRUD + real verification for `wordpress` and `mcp_server`; vendor provider types remain wp-admin | **M** | — |
| `commandCenter` | Port — aggregate agents, runs, schedules, cost | **S** | Health + activity stores (shipped #7748) |
| `shortcuts` | Port — assistant-scoped presets record | **S** | — |
| `slashCommands` | **Shipped** — native subset (7 commands) + audited executor; the nine Pro handlers stay out of scope | **S** | — |
| `chatChannels` | Manifest-driven communications toolkit (`toolkits` engine) | M | Toolkit manifest(s) |
| `mediaCc` | **Complete** — all seven sections live: local counts/storage/alt/next-gen, worker health, media job queue, `compression` from derivative savings and the schedule summary. Sweep runs from the console or `NVOOS_MEDIA_SWEEP_SCHEDULE` | **M** | `addons/media-worker` at `NVOOS_MEDIA_WORKER_URL` |
| `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor` | **Discover, don't port.** `connectedApps` registry + product-side MCP server/UI | L (product-side) | Product work, not runtime work |

## 5. Sequenced plan

**Phase 1 — Capability manifest v2 (1 PR, S).**
Reasons + `since` for every `false` key, mirrored in `contracts/console-endpoints.json`; console renders the reason. No new routes. *Acceptance:* both CIs assert the reason map; an operator can explain every unavailable surface without reading code.

**Phase 2 — MCP connections (3 PRs, L).**
(a) extract the client + wire corpus; (b) connection store + `GET/PUT /api/v1/mcp-apps/{assistant}` with masked secrets and an SSRF/allowlist guard; (c) tool bridging into the registry as `mcp__<connection>__<tool>`, inheriting each tool's capability gate. *Acceptance:* a connection to a local MCP server lists tools, calls one, and a private-IP target is refused with a typed error; `mcpApps` reports `true`.

**Phase 3 — The cheap data surfaces (2 PRs, S+M).**
`commandCenter` ✅ (with `shortcuts` in #7744) and `slashCommands` ✅, then `mediaCc` probe. *Acceptance:* each key flips; slash-command execution writes an audit row and honours `required_capability`.

**Phase 4 — Connected apps (product-owned).**
`connectedApps` registry + REST probe; start with Content Graph (its `nvoos-content-graph/v1` contract is already the console's target). `remoteSites` folds into Phase 2 as a second connection kind. *Acceptance:* a configured upstream lights its island; an unconfigured one reports `external_product` with no 404 noise. A product that later exposes an MCP server moves from probe to `mcpApps` with no console change.

**Explicitly not scheduled:** `chatChannels` until a communications toolkit manifest exists; class C reimplementations, ever.

## 6. ADRs to record

- **ADR-9 — Capability manifest v2.** `false` carries a reason; the fixture is the contract.
- **ADR-10 — MCP client lives in core.** One client for both runtimes; WordPress keeps its admin UI, not a second protocol implementation.
- **ADR-11 — Connected products are discovered, not ported.** Class C surfaces never gain a Laravel controller.

## 7. Risks

| Risk | Mitigation |
|---|---|
| The MCP client becomes an SSRF pivot | HTTPS-only, private-range block, per-hop redirect validation, DNS pinning; test the refusals, not just the happy path |
| Extracted code drifts from the Pro original | Golden corpus replayed by both suites (the `OkfParser` pattern) |
| Licence blur between Pro (Proprietary) and core (MIT) | Explicit decision in §9; `bin/check-license-boundary.sh` already fails on drift |
| A connected product's contract changes silently | Probe reports a version; the console fails closed per island |
| Reason map becomes a graveyard of stale labels | Reasons are asserted by CI and reviewed when a key flips |

## 8. What this proposal will not do

- Proxy to WordPress (ADR-8) — unchanged.
- Fork `contentGraph`/`docsHub`/`mediaStudio`/`documentEditor`.
- Add an OAuth flow without PKCE, or accept tokens not issued for the connection.
- Invent a second feature-flag mechanism; §3.1 extends the existing map.

## 9. Decisions

1. **Licence of the extracted MCP stack — decided: (a), relicensed as MIT.** The owner confirmed the copyright position; the history for `addons/pro/includes/mcp-apps/` carries a single author identity, so there is no third-party grant to obtain. The cut was drawn during execution and is narrower than the original ~6.6k-line framing: only the protocol *decisions* move to `lib/core`; the WordPress transport, credentials, `WP_Error` mapping and the registry stay proprietary. Three of the six files are the adapter, and the two protocol files are written against `WP_Error` and `wp_remote_*` throughout, so a block move was never available.
   - Shipped in #7738: `Nvoos\Core\Domain\Mcp\McpHandshakePolicy`, pinned by `tests/fixtures/mcp/handshake-cases.json` and replayed from both the core and the WordPress suites.
   - The licence gate gained the rule that made the earlier gap visible: it banned GPL inside a proprietary tree but not MIT, so a mechanical copy would have passed CI. It now checks the direction as well as the crossing.
   - Remaining slices all shipped: request envelopes (#7741), OAuth discovery (#7740) and the Laravel client (#7742). The Laravel surfaces that consume them landed in #7743 (MCP apps) and #7744 (tool shortcuts).
2. **`chatChannels` scope — open.** A communications toolkit is only worth porting if a Laravel estate actually sends SMS/email. **Recommendation:** defer until a toolkit manifest exists; classify it `toolkit-shaped` in the capability manifest meanwhile (that is the shipped reason code — `not_implemented` was superseded when the v2 taxonomy landed in #7737).
3. **`commandCenter` — shipped once the two missing stores existed.** The Pro overview aggregates agent sessions, presence, approvals, token spend, uptime and an activity feed from `WP_MCP_AI_Pro_Agent_Command_Center`'s static collectors. Four of the six KPIs had honest sources here — `assistants` for agents, `nvoos_approvals` for pending approvals, `Nvoos\Laravel\Adapter\TokenUsage` for tokens today, `nvoos_workflow_runs` for active work — and two did not:
   - **`uptime`** had no metrics store. A KPI row cannot ship a fabricated percentage, and a `0` beside four real numbers reads as an outage.
   - **`recent_events`** is an *agent activity* feed in Pro. Substituting workflow runs would have populated the feed with the wrong semantic while looking correct.

   The two stores were built in #7748 (`nvoos_health_samples`, `nvoos_activity_events`, `nvoos:sample-health`), which is exactly what this section asked for, and `GET /api/v1/pro/command-center/overview` followed. Two details make the surface honest rather than merely present: **`uptime` is `null`** until a sample exists (the console renders an em dash, not `0%`), and **`agents_online` is `publish` assistants** — this runtime has no presence channel, so "online" means "enabled", the same reading `orchestration/stats` uses. `agent_statuses.last_active` is `updated_at`, not a heartbeat, and `active_tasks` is queue depth, because workflow runs here are synchronous.

   **Correction (2026-11-14):** this change initially claimed the token table had no app migration and shipped one. That was wrong. Package auto-discovery registers `Nvoos\Laravel\ServiceProvider\NvoosServiceProvider`, which calls `loadMigrationsFrom()`, so the adapter's migrations — `nvoos_token_usage` included — run on a fresh install. The duplicate app migration has been removed; `RuntimeSchemaTest` now pins the tables instead.

4. **`remoteSites` — deferred: feature-sized, not a slice.** The Pro implementation is 6,452 lines across a 5,276-line manager and a 1,176-line controller. It is not a thin surface over stored data:
   - **Credential storage with its own scheme.** The manager encrypts per-field with AES-256-CBC behind a `V2` prefix, detects legacy plaintext and re-encrypts on save, and re-encrypts on export/import.
   - **Fifteen connection types** across `website`, `ai`, `email`, `google` and `chat` categories, each with its own field schema.
   - **Per-provider verification** (`POST /{id}/test`), which is integrations rather than parity.
   - **Base-plugin bridging**: the list merges a synthetic `base` Gmail row read from the base settings.

   The one real design question is cheap to settle now: ADR-8 already says the two runtimes are separate backends, so **connections never migrate between them**. Each runtime therefore owns its own scheme — WordPress keeps the existing `V2` format, Laravel uses `Crypt` (AES-256-GCM under the app key). Nobody needs to reimplement the WordPress cipher.

   **Decomposition, in dependency order:** (a) a `CredentialStore` over `Crypt` plus a `nvoos_remote_sites` table with redacted reads — this also unblocks storing MCP app tokens, which #7743 deliberately refused to accept on a plaintext route; (b) the type catalogue as data and the wizard wiring; (c) per-provider verification. The key can flip only after (c): a Connections page whose Test button fails for every row is worse than a hidden page.

   **Steps (a)–(c) shipped for two types (2026-11-14).** `CredentialStore` landed in #7747 (sealed field map, undecryptable values degrade to absent, uniform mask). The record followed: `nvoos_remote_sites` is a **generic** row — `name`, `url`, `connection_type`, `auth_type`, `config`, sealed `credentials`, `enabled`, test status — rather than WordPress's ~50-field flat array, which is where most of its 6,452 lines come from. `App\Support\RemoteSites\RemoteSiteStore` enforces three rules rather than documenting them: no read returns a credential (`has_credentials` + a uniform mask only), `config` rejects known secret field names so a secret cannot be echoed back on a list, and a blank credential on update **keeps** the stored value (filtered before the merge — merging first would delete it). `RemoteSiteStoreTest` (15 cases).

   The surface then shipped for the two types this runtime can honestly verify: **`wordpress`** (application password, checked against `wp-json/wp/v2/users/me`) and **`mcp_server`** (the shared `McpAppClient` handshake and `tools/list`). `Pro\RemoteSitesController` serves list, catalogue, create, update, delete and test; the wizard's **flat** payload is split into `config`/sealed `credentials` on the way in and flattened back on the way out, because `openEdit()` reads `connection[field.key]`. A 401 is reported as *reached, rejected* — not as an outage — and an unimplemented type says so instead of pretending. `RemoteSitesApiTest` (14 cases).

   **Deliberately absent:** the thirteen vendor types (Gmail, Telegram, WhatsApp, QuickBooks MCP, Upwork, FlowHub…). Listing one before its integration exists would put a Test button on screen that can only fail, which is the outcome this section warned about. Their records still open in the console list; editing them points at wp-admin. Adding a type is one entry in `RemoteSiteTypes` plus a branch in `RemoteSiteVerifier`.

5. **`slashCommands` — shipped as a native subset, not a port.** The earlier `toolkit-shaped` classification was right about the Pro half and too broad about the whole: WordPress registers 24 base commands and nine Pro ones, and most of the base set is WordPress-development operations (`/ship`, `/clean-content`, `/sync-docs`, `/markup-stats`). Reimplementing them would produce commands that parse and then do nothing useful. What shipped is seven commands that run against stores this runtime owns — `/help`, `/status`, `/model`, `/jobs`, `/tools`, `/cost`, `/workflow` — listed in `App\Support\SlashCommands\SlashCommandCatalogue`. The drawer only ever sees commands that can run, and per-command capability is enforced at execution, not just filtered in the catalogue.
   - **The grammar is shared.** `Nvoos\Core\Domain\SlashCommand\SlashCommandParser` owns the tokenising rules with 22 unit tests. It is deliberately *not* wired into the WordPress plugin: the wp.org base build strips `lib/`, so `WP_MCP_AI_Slash_Command_Parser` keeps its standalone implementation. The quirk where `--key=` (empty value) falls through to the positional list is pinned rather than fixed.
   - **The audit row matches WordPress.** `nvoos_slash_command_audit` mirrors `wp_mcp_ai_slash_command_audit`, and a failed command is still a row. A parse failure is not: no command name was established, so there is nothing to attribute.
   - **Errors use WordPress's flat `{code, message, data}` shape**, not this app's `{success, error}` envelope. `ChatPage` reads a top-level `message`; without it the composer would show "HTTP 400" instead of the reason.
   - **`/jobs` is real, and the earlier note was wrong.** `Nvoos\Laravel\Adapter\JobStatusStore` reads `nvoos_jobs`, which the adapter package creates through its own migration (`loadMigrationsFrom()`, registered by package auto-discovery). No app migration is needed. An absent status in the grouped counts is a true zero, so `/jobs` now reports the five known statuses explicitly instead of an empty-state message.

6. **`mediaCc` — deferred after measurement; the "small probe" estimate was wrong.** Reading `WP_MCP_AI_Media_Command_Center_Page::get_health_snapshot()` shows seven gatherers, all of them **local WordPress** state:
   - attachment posts and `_wp_attachment_image_alt` meta (`with_alt`, `alt_pct`),
   - a `filesize()` walk of the uploads directory (`storage`),
   - mime-type counts (`nextgen_pct`),
   - the `wp_mcp_ai_media_compression_stats` option (`compression`),
   - the `wp_mcp_ai_media_processing_queue` option (`queue`),
   - Action Scheduler over three media hooks (`schedule`).

   It is not a worker proxy, so there is no upstream health probe to integrate. On this runtime `nvoos_files` carries path, disk, filename, mime type and size — that is `attachments.total`, `image_count`, `storage` and `nextgen_pct`. The other three have no source at all: no alt-text column, no original-size column for compression savings, no media processing queue, and no scheduler to summarise. The console page renders all seven sections, so shipping the computable half would paint `0%` alt coverage and `0 B` savings — a false diagnosis of a pipeline that does not exist.

   **Decision (revised):** reuse the media worker. The WordPress plugin already talks to `addons/media-worker` (`GET /api/health`, `POST /api/image/optimize`, `X-Site-Token`), so the Laravel runtime points at the same sidecar rather than growing a PHP image pipeline that would duplicate Sharp. `GET /api/v1/pro/media-cc/health` now returns:
   - **real** — `attachments.total`, `image_count`, `storage`, `nextgen_pct` from `nvoos_files`, and `queue` from `nvoos_jobs` filtered to `media.*` handlers;
   - **null** — `with_alt`, `descriptive_filename_count`, `alt_pct`, `compression`, so the console renders an em dash instead of a false `0`;
   - **worker** — `{configured, available, version, uptime}` from the sidecar. A worker that is down is reported, not thrown: the local numbers still render beside it.

   Availability is **config-gated**: the route is always registered, but `EndpointCapabilities` reports `mediaCc` false until `oos.media_worker.url` is set, so an install with no worker hides the page instead of offering a dead one. The reason stays `connected-service` — the vocabulary's own "configure this first" class — so an unconfigured worker is not counted as a gap.

   The console gained two things that apply beyond this page: the nav hides any item whose endpoint is blank (`NavGate.endpoint`), and `formatBytes`/`formatPercent`/`formatCount` render `—` for unknown values rather than `0`.

   **Complete.** Alt text and compression are live (`alt_text` on `nvoos_files`, `compression` summing derivative savings written by `App\Jobs\OptimizeMediaFile`), and the schedule summary is real: `NVOOS_MEDIA_SWEEP_SCHEDULE` registers `nvoos:sweep-media`, and `active`/`ran_today`/`overdue`/`next_run` read the `media.sweep` rows that command writes. The console's sweep action and the scheduled command share `App\Support\Media\MediaSweeper`, so they cannot drift. See [`090-j-media-processing-plan.md`](../plans/090-j-media-processing-plan.md).

## Sources

- MCP specification 2026-07-28, *Security Best Practices* — `modelcontextprotocol.io/docs/2026-07-28/tutorials/security/security_best_practices` (confused deputy, token passthrough, SSRF, scope minimisation).
- MCP Authorization specification — OAuth 2.1, RFC 8707, RFC 9728, RFC 7591, RFC 9207.
- **RFC 9700** *OAuth 2.0 Security Best Current Practice* (2025) — PKCE, exact redirect URIs.
- **MCP Apps (SEP-1865)** — `blog.modelcontextprotocol.io/posts/2025-11-21-mcp-apps/`; spec `github.com/modelcontextprotocol/ext-apps`.
- **OWASP** *MCP Security Cheat Sheet*, *SSRF Prevention Cheat Sheet*, *ASVS 5.0*.
- **RFC 8594** (`Sunset`), **RFC 9457** (Problem Details).
- **OpenFeature** (CNCF) — vendor-neutral flag evaluation.
- Consumer-driven contract testing (Pact) — already mirrored by `contracts/console-endpoints.json`.
- Anti-Corruption Layer / Strangler Fig — Evans; Fowler.
