# Proposal 090-n — Console manifest parity and connected-app discovery

**Status:** Workstreams A–C **shipped** 2026-11-16 (WordPress manifest, connected-app
discovery slice, console CI). Workstream D remains product-owned.
**Related:** `090-i-remaining-surfaces-strategy.md` (classification + Phase 4),
`090-b-laravel-admin-surfaces-proposal.md` (ADR-6, ADR-8),
`083-nvoos-complete-console-app-proposal.md`,
`docker/laravel/app-skeleton/contracts/console-endpoints.json`.

---

## 1. The gaps, as measured before this change

| # | Gap | Evidence |
|---|---|---|
| 1 | WordPress did not emit the capability manifest | `WP_MCP_AI_REST_Features_Controller::get_features()` returned only `data.features`; the console fell back to a hardcoded `isWordPress` matrix in `examples/nvoos-complete-velocity/src/runtime-config.ts`. |
| 2 | The console blanked `toolkits` on WordPress even though the backend serves it | `runtime-config.ts` carried `toolkits: isAdmin && !isWordPress ? … : ''` with a comment claiming “no /toolkits route yet”; `WP_MCP_AI_Pro_Spa_Config` points at `mcp-ai-pro/v1/toolkit-manifests`, and `spa-v2/src/api/toolkits.ts` already supports both bases. |
| 3 | The console half of the contract was unguarded in CI | `runtime-config.test.ts` exists but no workflow ran it; only the mirror sync smoke-booted the assembled artifact. |
| 4 | The four Class C products had no discovery mechanism on Laravel | `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor` were permanently `''` on that runtime; no `connectedApps` registry existed (grep found none). |

After the change:

| Fact | Before | After |
|---|---|---|
| WordPress manifest | absent | `endpoints` + `endpoint_reasons` + `endpoint_gaps` + `backend` + `contract` |
| WordPress live measurement | n/a | **39 true / 6 false / 0 gaps** (`chatChannels`, `professions`, `orgTeams`, `contentGraph`, `mediaStudio`, `documentEditor` false *by reason*) |
| Console contract CI | none | `console-tests.yml` → `tsc --noEmit` + 166 Vitest tests |
| `toolkits` on WordPress | blank | `mcp-ai-pro/v1/toolkit-manifests` |
| Connected apps on Laravel | never | configured probe → `endpoint_urls` + flipped key, cached |

---

## 2. Industry standards this plan adopts

Research conducted 2026-11-16; the citations are the sources that shaped the
decisions, not a reading list.

### 2.1 Capability discovery as a public, machine-readable manifest

Current practice converges on a single document that tells a client what the
server supports *before* it calls anything — OpenAPI `servers`, MCP
`initialize` capabilities, and the newer `/.well-known/*` capability manifests
(RFC 8615). The guidance that matters operationally:

- **Keep the document cheap.** No database queries, model calls or mutations
  (the `/.well-known` capability-document guidance). Our map is a router walk.
- **Version it and let clients ignore unknown keys.** Additive fields must not
  force a client upgrade. `endpoint_contract_version` is explicit;
  `endpoint_urls` is additive and older consoles ignore it.
- **Publish a reason, not just a boolean.** A `false` that cannot be explained
  is indistinguishable from a regression; capability documents are increasingly
  expected to distinguish *unsupported* from *unconfigured* from *not
  installed*.

Applied: one fixture (`contracts/console-endpoints.json`), one vocabulary, two
runtime implementations, and a manifest v2 that carries availability, supply
class and the derived gap list.

### 2.2 Runtime truth over a maintained matrix

A hand-maintained per-backend matrix rots silently — that is exactly gap #2.
Both runtimes now derive availability from their own router
(`rest_get_server()->get_routes()` on WordPress,
`RouteFacade::getRoutes()` on Laravel). Adding a route flips the key with no
catalogue edit; removing one becomes a gap unless the key is classified as
deliberately unavailable.

### 2.3 Consumer-driven contract testing without a broker

Pact's core discipline — *the consumer's real client generates the expectation,
the provider verifies the same contract, and the contract lives with the code* —
is implemented with a JSON fixture and two suites:

- Laravel `EndpointCapabilitiesTest` — provider covers every key; reason map
  equals the fixture; vocabulary equals `reason_codes`; gaps empty.
- WordPress `WP_MCP_AI_REST_Console_Endpoints_Test` — same assertions against
  the WordPress twin, plus the override merge.
- Console `runtime-config.test.ts` — the built endpoint map equals
  `route_backed + asset`, and every key is classified on both runtimes.

We deliberately did **not** add a Pact broker: the provider and consumer ship
from one repository, so the fixture in git plus two CI jobs is the same signal
with less machinery (090-i §3.4).

### 2.4 Additive evolution, Sunset for retirement

New fields are additive; nothing is renamed. Anything scheduled to disappear
should gain `since`/`sunset` metadata (RFC 8594 semantics) rather than vanishing
— not yet needed, recorded here so the next change follows the rule.

### 2.5 Anti-corruption layer and strangler fig for Class C

The four products keep their own REST contracts; the runtime may *translate*
and *discover* them, never fork them (ADR-11). MCP Apps (SEP-1865) remains the
long-term route out of per-product integrations: a product that speaks MCP Apps
needs no console change.

### 2.6 SSRF controls on the discovery path

MCP's security guidance for discovery URLs maps directly onto the probe:
HTTPS for non-loopback hosts, no embedded credentials, refuse private/reserved
IP literals, and do not follow redirects (the classic pivot). DNS pinning is the
one recommended control not implemented — the URLs are operator configuration,
and Guzzle performs resolution itself; the limitation is documented in the
class.

---

## 3. Workstream A — WordPress emits the manifest (shipped)

### Design

`includes/class-wp-mcp-ai-endpoint-capabilities.php` — `WP_MCP_AI_Endpoint_Capabilities`:

| Concern | Decision |
|---|---|
| Route source | `rest_get_server()->get_routes()` (runtime truth) |
| Matching | Literal head of the registered regex pattern, then boundary-aware prefix (`chat` never matches `chat-transcripts`) |
| Key set | 45 route-backed keys, mirroring the Laravel `ROUTES` and the console contract |
| Reason map | The contract's canonical map + three WordPress overrides (`mediaCc` → `route`; `professions`/`orgTeams` → `runtime-adaptable`); `chatChannels` stays `toolkit-shaped` because the admin page points at a route no controller registers |
| Base installs | Pro-supplied keys downgrade to `not-installed` when `WP_MCP_AI_PRO_FILE` is undefined — an inactive addon is not a gap |
| Gaps | Derived: `false` + reason `route` only |

`WP_MCP_AI_REST_Features_Controller::get_features()` now returns:

```json
{
  "success": true,
  "data": {
    "features": { "...": true },
    "endpoints": { "chat": true, "...": false },
    "endpoint_contract_version": 2,
    "endpoint_reasons": { "chat": "route", "contentGraph": "external-product" },
    "endpoint_gaps": [],
    "backend": "wordpress",
    "contract": "mcp-ai/v1"
  }
}
```

The 60-second shared-cache header is unchanged: availability is site-level.

### Contract changes

`contracts/console-endpoints.json` gains:

- `wordpress_reasons` — only keys whose supply class differs on WordPress;
- `reason_codes` — the shared six-code vocabulary (`route`,
  `runtime-adaptable`, `not-installed`, `toolkit-shaped`, `connected-service`,
  `external-product`).

The Laravel `EndpointCapabilities::REASON_CODES` gained `not-installed` (never
emitted there, but part of the shared vocabulary) and its suite now pins the
fixture's `reason_codes` and validates that WordPress overrides only name known
keys.

### Console changes

- `toolkits` resolves per backend: WordPress → `${pro}/toolkit-manifests`,
  Laravel → `${pro}/toolkits` (admin-gated on both).
- The static `isWordPress` matrix stays as a fallback for older backends;
  server-reported `endpoints` override it default-on (fail-open).

### Verification

| Check | Result |
|---|---|
| `WP_MCP_AI_REST_Console_Endpoints_Test` + features controller | 21 tests / 106 assertions, 1 skipped (Pro-active suite skips the Base downgrade) |
| Live manifest in `oos-wp` | `version=2 true=39 false=6 gaps=[]` |
| `phpcs` (5 files) | 0 errors / 0 warnings |

---

## 4. Workstream B — connected-app discovery (shipped, Phase 4 slice)

### Design

`docker/laravel/app-skeleton/app/Support/ConnectedApps/ConnectedAppRegistry.php`

- **Config** (`lib/laravel-adapter/config/nvoos.php` → `oos.connected_apps`):
  `contentGraph`, `docsHub`, `mediaStudio`, `documentEditor`, each
  `{ url, probe }`. Env: `NVOOS_CONTENT_GRAPH_URL`, `NVOOS_DOCS_HUB_URL`,
  `NVOOS_MEDIA_STUDIO_URL`, `NVOOS_DOCUMENT_EDITOR_URL`.
- **Probe**: `GET {url}{probe}` with `withoutRedirecting()`, `acceptJson()`,
  1 s connect / 2 s total. Defaults: `/graph` for Content Graph, `/health` for
  the other three (each product registers it).
- **Reached semantics**: 2xx/3xx or 401/403. 404/5xx/transport error = absent.
- **Cache**: `oos.connected_apps_ttl` (300 s), keyed by the configured URLs, so
  a failure is cached too and cannot slow every boot.
- **SSRF refusals** (before a socket opens): non-http(s) scheme, no host,
  embedded credentials, plain HTTP for a non-loopback host, private/reserved
  IP literals.
- **Manifest composition** (`FeaturesController`): a reachable app flips its
  `endpoints` key and appears in `endpoint_urls`; the reason stays
  `external-product` (supply class and availability are separate facts).
- **Console**: `fetchRuntimeAvailability` parses `data.endpoint_urls`;
  `buildConsoleRuntime` mounts a connected app at the resolved URL for admin
  sessions only (these are admin surfaces on both runtimes) and ignores unknown
  keys.

### Verification

`ConnectedAppsTest` — 8 tests: no-config, reachable, 404, 401, transport error,
private/reserved refusals (including “no request sent”), plain-HTTP refusal,
cache reuse. 22 tests / 125 assertions together with
`EndpointCapabilitiesTest`.

---

## 5. Workstream C — console CI (shipped)

`.github/workflows/console-tests.yml`:

- Node 22, `npm ci`, `npm run typecheck` (app + e2e project), `npm test`.
- Path filters cover every file the suite reads: the console, `spa-v2`
  sources, the island `dist/` bundles imported with `?url`, the shared
  contract fixture, the Laravel `EndpointCapabilities`, the WordPress SPA
  config and the WordPress features controller.
- Concurrency cancels superseded runs; `contents: read`.

Deliberately separate from `javascript-tests.yml` (root Jest) and
`sync-nvoos-complete-velocity.yml` (mirror assembly + static smoke).

---

## 6. Remaining work

| Item | Owner | Notes |
|---|---|---|
| `chatChannels` | product | The WP admin page calls `mcp-ai-pro/v1/chat-channels`; no controller registers it. Either model the communications toolkit as a manifest family (recommended in 090-i) or point the admin page at a real surface. Until then the key is honestly `toolkit-shaped`. |
| Console renders the reason | console | The nav hides unavailable items. A “Not available on this runtime: external product” empty state is the next UX step; `endpoint_reasons` is already on the wire. |
| DNS pinning for discovery | Laravel | Documented limitation; needs a Guzzle handler or a resolved-IP check. |
| Console e2e in CI | console | `npm run test:e2e` needs a live Laravel stack (MySQL + Redis + Octane + built SPA); remains a local/pre-release gate. |
| Docs catch-up | release | Fold the manifest into `.context/rest-api.md`, the plugin release notes and the docs index in the next window. |

## 7. Risks and rollback

- **Additive only.** An older console that ignores `endpoints`/`endpoint_urls`
  behaves exactly as before; an older backend that omits them leaves the console
  fail-open.
- **Cache staleness.** Features: 60 s shared; probes: 300 s. A newly activated
  plugin or a newly configured product appears within one window.
- **A disabled Pro module** (not merely inactive) still reports a gap; that is
  intentional — the console advertises the surface and the operator should see
  that it is missing.
- **Rollback** is per workstream: revert the loader require + controller fields
  (WordPress), the config block + controller composition (Laravel), or delete the
  workflow. No data migration is involved.
