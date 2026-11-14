# Proposal 090-i — The remaining eleven console surfaces

**Status:** Recommendation recorded 2026-11-14. Decision needed on two items (§9).
**Context:** `090-b` has driven the console contract from 17 unavailable keys to 11. Every *portable* key is now live: **34 of 45** route-backed keys report `true` from `/features`.
**Remaining:** `mcpApps`, `chatChannels`, `remoteSites`, `mediaCc`, `commandCenter`, `shortcuts`, `slashCommands`, `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor`.
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
| `remoteSites` | `mcp-ai-pro/v1/remote-sites` (connections to other WordPress sites) | **A→B** | **Fold into the mcpApps connection registry** as a second connection kind; keep the key as an alias |
| `commandCenter` | `mcp-ai-pro/v1/command-center/overview` (KPI row over agents/workflows/cost) | **A** | Port — it aggregates data this runtime already owns |
| `shortcuts` | `mcp-ai-pro/v1/tool-shortcuts` (assistant-scoped tool presets) | **A** | Port — a small record read |
| `slashCommands` | catalogue + `/slash-commands/execute` + audit table | **A** | Port the catalogue and executor onto `ToolRegistry`, keeping the audit row |
| `chatChannels` | channel command center (messages, contacts, delivery) | **A**, but a **toolkit family** | Model as a manifest-driven communications toolkit, not a bespoke controller |
| `mediaCc` | `mcp-ai-pro/v1/media-cc/health` + sweep trigger | **D** | Integrate an upstream health probe; unavailable when no worker is configured |
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
| `remoteSites` | **Fold into the connection registry**; alias the key once generic connections land | M | `mcpApps` |
| `commandCenter` | Port — aggregate agents, runs, schedules, cost | **S** | — |
| `shortcuts` | Port — assistant-scoped presets record | **S** | — |
| `slashCommands` | Port catalogue + executor over `ToolRegistry`; keep the audit table | M | — |
| `chatChannels` | Manifest-driven communications toolkit (`toolkits` engine) | M | Toolkit manifest(s) |
| `mediaCc` | Upstream health probe; unavailable when `MEDIA_WORKER_URL` is unset | **S** | A configured worker |
| `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor` | **Discover, don't port.** `connectedApps` registry + product-side MCP server/UI | L (product-side) | Product work, not runtime work |

## 5. Sequenced plan

**Phase 1 — Capability manifest v2 (1 PR, S).**
Reasons + `since` for every `false` key, mirrored in `contracts/console-endpoints.json`; console renders the reason. No new routes. *Acceptance:* both CIs assert the reason map; an operator can explain every unavailable surface without reading code.

**Phase 2 — MCP connections (3 PRs, L).**
(a) extract the client + wire corpus; (b) connection store + `GET/PUT /api/v1/mcp-apps/{assistant}` with masked secrets and an SSRF/allowlist guard; (c) tool bridging into the registry as `mcp__<connection>__<tool>`, inheriting each tool's capability gate. *Acceptance:* a connection to a local MCP server lists tools, calls one, and a private-IP target is refused with a typed error; `mcpApps` reports `true`.

**Phase 3 — The cheap data surfaces (2 PRs, S+M).**
`commandCenter`, `shortcuts`, then `slashCommands` (catalogue + executor + audit), `mediaCc` probe. *Acceptance:* each key flips; slash-command execution writes an audit row and honours `required_capability`.

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

## 9. Open decisions

1. **Licence of the extracted MCP stack.** Moving ~6.6k lines from `addons/pro` (Proprietary) into `lib/core` (MIT) is a relicensing act, not a refactor. Options: (a) relicense as MIT — consistent with the `OkfParser` extraction, owner's call; (b) keep the client in Pro and have the Laravel app depend on Pro — breaks the base/MIT boundary; (c) write a smaller client in core and leave Pro untouched — least risk, most duplicated protocol code. **Recommendation: (a)**, because two MCP implementations will drift on exactly the handshake edge cases the Pro stack already paid for.
2. **`chatChannels` scope.** A communications toolkit is only worth porting if a Laravel estate actually sends SMS/email. **Recommendation:** defer until a toolkit manifest exists; flip the reason to `not_implemented` meanwhile.

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
