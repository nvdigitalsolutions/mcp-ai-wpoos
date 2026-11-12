---
name: mcp-ai-wpoos-service-exchange
description: "Operational guide for the NV oOS Service Exchange (Proposal 143): Vite wizard + zero-dependency control plane in examples/nvoos-service-exchange that deploys an expert's MCP SaaS estate on Cloudways. Covers Service Blueprint v1, the estate state machine, api→ssh→manual tiers, the verified Cloudways API v2 contract, probes, deployment, and security invariants. Use when working on the Exchange app, blueprints/seeds, provisioning steps, or Cloudways route verification."
---

# NV oOS Service Exchange — Build, Provision, Operate

## What / where

- **App:** `examples/nvoos-service-exchange/` — Vite/React wizard (`src/`) +
  zero-dependency Node control plane (`server/`, `scripts/serve.mjs`).
- **Mirror:** `nvdigitalsolutions/nvoos-service-exchange` via
  `.github/workflows/sync-nvoos-service-exchange.yml` (flat force-push,
  runtime-only tree, dependencies stripped).
- **Docs:** Proposal `docs/project/proposals/143-expert-service-exchange-proposal.md`;
  plan `143-expert-service-exchange-implementation-plan.md`;
  format `docs/service-blueprint-v1.md`;
  runbook `docs/operations/deployment/service-exchange-velocity-setup.md`;
  API evidence `docs/operations/deployment/cloudways-api-verification-spike.md`.
- **Tests today:** 100 `node --test` server tests, 11 vitest UI tests, and a
  full-stack `npm run smoke` (health → 5 seeds → create → 9 steps → live →
  catalogue).

## Architecture (one deployable)

```text
Browser → Exchange (Velocity)
            ├── dist/            # Vite wizard (8 steps)
            └── /api/v1/*        # zero-dep control plane
                  ├── Cloudways client (API v2 primary, v1 fallback)
                  ├── WP provisioner   (WP Manager → SSH → paste-block)
                  ├── GitHub client    (per-estate console branches)
                  ├── Gateway client   (verification ladder)
                  └── Commerce         (price pointer + entitlement)
```

Design rationale and ADRs: plan §3. Key invariants: one Cloudways app per
estate (no multisite); estate state **derived** from probes (ADR-04); secrets
never leave the process unmasked.

## Estate state machine

`requested → cloudways_app → wp_installed → bundle_imported →
credential_minted → console_deployed → gateway_bound → commerce_linked →
listed → live`

- `server/estates/state.js` — 10 states, 9 transitions, contiguous-evidence
  derivation (regressions move the state backwards).
- `server/estates/reconciler.js` — pure merge; `undefined` probe results never
  overwrite evidence (provider outage ≠ deleted estate).
- `server/provision/runner.js` — one step per `advance`, per-estate lock,
  idempotent; a completed estate is a no-op.
- Failure contract: throw `ApiError` with `{ tier, remediation, pasteBlock? }`.
  Handlers never return `{ ok: false }` (canonical-envelope rule).

## Service Blueprint v1

Unit = assistant-portability bundle **+ manifest**. Bundle semantics belong to
the portability engine (`format: "nvoos-assistant"`, `format_version: 1`,
`assistants[].title`); the Exchange validates the envelope + manifest only.

Rules (validator `server/blueprint/validate.js`, schema
`blueprints/schema.json`, spec `docs/service-blueprint-v1.md`):

- `blueprint_version: 1`; unknown keys rejected; `_meta` is the only free-form
  escape.
- `slug` `^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$`, globally unique.
- `gateway.visibility: "public"` requires a non-empty `tool_allowlist`.
- `console.enable` requires `template: "complete-velocity"`; storefront enable
  requires `astro-storefront` or `forge`.
- Banned keys outside `_meta`: token, secret, password, api_key, oauth_data,
  authorization, credentials, `_wp_mcp_ai_credentials`, etc.
- Credential-shaped **values** (`op_…/cred_…` + dot + secret) are rejected
  anywhere, including prose.
- `mcp_apps` entries are `connection_ref` pointers only (optional `label`,
  `position`).

Seeds: `blueprints/*.json` (5 professions). To add one: export a real bundle
from staging (`wp mcp-ai assistant export <id>`), wrap it in a manifest,
validate via `node --test server/blueprint/seeds.test.js`, then list it in
`docs/service-blueprint-v1.md` §6.

## Provisioning steps and tiers

| Step | Live action | Degradation |
|---|---|---|
| `cloudways_app` | `ensureApp` (label lookup first) + poll `GET /app/{id}` until `running`; record `urls.site` from `cname` and `provider.sysUser` | Manual app creation; set `provider.sysUser` |
| `wp_installed` | deactivate → delete → install → activate + `wp cache flush` | SSH tier, then paste-block |
| `bundle_imported` | `wp mcp-ai assistant import --file=…` | Upload bundle; set `provider.bundlePath` |
| `credential_minted` | `wp mcp-ai credential issue <assistantId>`; AES-256-GCM store | Set `provider.assistantId` |
| `console_deployed` | GitHub branch `estate/<slug>` + Velocity app | Guided dashboard step |
| `gateway_bound` | Env block + `/health` → `/health/full` → `tools/list` → `tools/call` | Apply env in Velocity dashboard |
| `commerce_linked` | `price_…` pointer + trial entitlement | `POST /estates/{slug}/commerce` |
| `listed` / `live` | Catalogue + full ladder re-verification | — |

Tier resolution: `wp_manager → ssh → manual`. Paste-blocks terminate every
line with `;` (the owner's terminal merges lines without them). Command
builders are injection-guarded (`server/clients/wp.js`) — never interpolate
unsanitised values.

## Cloudways API v2 — verified contract (2026-11-12)

Base `https://api.cloudways.com/api/v2` (v1 at `…/api/v1` via
`CLOUDWAYS_LEGACY_BASE_URL`). OAuth `POST /oauth/access_token`, form
`email`+`api_key` → `{ access_token, expires_in }`, then Bearer.

- `GET /servers` (production dashboard) — client falls back to `/server` on 404.
- `GET /server/{id}/apps`, `GET /app/{id}`, `GET /projects`.
- `POST /app` body `{ server_id: "<string>", application, app_label,
  project_name?, app_version? }` → `{ app: { id, … } }`. **IDs are strings**;
  numbers fail validation.
- `GET /app/{id}` returns `status` (`running | provisioning | pending |
  deploying | failed | error`), `cname`, `app_fqdn`, **`sys_user`** (folder
  name). Cross-checked: Samtani `tvhqswptzh`, NV Forge `bzprdqtjkk`.
- `POST /app/cache/purge` — route exists; payload shape unverified.
- Errors are `{ error, error_description }`; the client surfaces the
  description (never tokens) and marks `providerCode`.

Client behaviours: OAuth token cache; retry once on 5xx; `ensureApp` hydrates
from `getApp` when the create response is minimal; `waitForApp` polls with a
default 10-minute deadline; `siteUrlFromApp` prefers `cname`.

## Probes (read-only)

- `npm run probe:cloudways` — live REST route-existence probe (+ optional
  authenticated read-only section when `CLOUDWAYS_EMAIL` / `CLOUDWAYS_API_KEY`
  are set; prints status codes, counts, key names only).
- `npm run probe:mcp` — connects to the Zed-configured Cloudways MCP server
  (`cloudways-nvdigital` / `cloudways-mushroom`, Streamable HTTP,
  `https://mcp.cloudways.com/mcp/`, protocol `2025-03-26`, 66 tools). Reads the
  token from `~/AppData/Roaming/Zed/settings.json` at runtime and never prints
  it. Explicit read-only allowlist; prints `sys_user` (an identifier) but never
  passwords/IPs/emails. Useful calls: `server_list`, `server_get`, `app_list`
  (`limit ≤ 100`, `server_id` filter), `app_get`, `app_credentials` (often
  returns an empty `app_creds` — use `app_get.sys_user` instead).

## Gateway and commerce

- Env block generator (`server/gateway/envblock.js`): one row per variable,
  `GATEWAY_PUBLIC_KEYS=<key>=<slug>`, `NVOOS_SITE_<SLUG>_URL/_TOKEN`; a key
  bound to an unconfigured slug is a gateway boot error. Public estates append
  the slug to `PUBLIC_SITE_SLUGS`.
- Velocity env is dashboard-only — never attempt an API write; render and
  verify instead.
- Commerce: expert keeps their own Stripe; the Exchange stores `price_…` and
  gates catalogue visibility on `active|trialing` entitlement. The platform
  never touches customer money (ADR-07).

## Env and deployment

`EXCHANGE_MODE=mock|live`. Live boot fails closed unless
`EXCHANGE_ADMIN_TOKEN`, `EXCHANGE_ENCRYPTION_KEY`, `CLOUDWAYS_EMAIL`,
`CLOUDWAYS_API_KEY`, `CLOUDWAYS_DEFAULT_SERVER_ID` are set. Optional:
`CLOUDWAYS_BASE_URL`, `CLOUDWAYS_LEGACY_BASE_URL`,
`CLOUDWAYS_APP_TIMEOUT_MS/_POLL_MS`, `EXCHANGE_GITHUB_TOKEN`,
`EXCHANGE_CONSOLE_REPO`, `GATEWAY_BASE_URL`, `EXCHANGE_RECONCILE_MS`,
`EXCHANGE_RATE_MAX/_WINDOW_MS`.

Deploy: monorepo workflow runs tests + smoke, builds `dist/`, assembles a
runtime-only tree (server + scripts + blueprints + dist, deps stripped) and
force-pushes the mirror. Rollback = redeploy the previous mirror commit;
estates are additive/derived and converge on the next advance.

## Testing conventions

- Server: `node --test "server/**/*.test.js"` — **quote the glob** (Node's
  runner treats bare directories as modules: `node --test server/estates`
  fails with MODULE_NOT_FOUND on this Node version).
- UI: `npm test` (vitest + jsdom + testing-library); `npm run build` includes
  `tsc --noEmit` and the ≤150 KB gzip entry budget (currently ~65 KB).
- Full stack: `npm run smoke` (mock mode, real HTTP server + built `dist/`).
- Provider tests use an injected `fetchImpl`; zero real network. Errors must
  never contain secrets — assert structurally, not by review.
- Controller tests call the service/handler directly for error fidelity.
- **Skill frontmatter:** `npm run validate:skills` checks both skill roots for
  invalid YAML (an unquoted `: ` in `description:` silently drops the skill),
  name/directory mismatches, missing descriptions, and the description budget.

## Security invariants

1. Credentials never travel in blueprints; `op_` tokens are minted at the
   destination and stored AES-256-GCM encrypted; responses expose only
   fingerprints/masks.
2. Every route except `/api/v1/health` requires the admin bearer; rate-limited;
   teardown requires `{ confirm: true, slug: "<slug>" }`.
3. Env blocks are masked unless explicitly revealed by the authenticated admin.
4. Paste-block values are validated before rendering (shell-adjacent surface).
5. Provider teardown (Cloudways app, Velocity app, gateway env rows) is
   **manual** in v1 — never auto-delete infrastructure.
6. Error envelopes never include provider bodies or stack traces.

## Gotchas

- Cloudways IDs are **strings** in both the API create body and MCP tool
  schemas.
- `sys_user` comes from `GET /app/{id}`, not `app_credentials`.
- JSON route errors (`400 invalid_request`, `403 invalid_credentials`) mean the
  route exists; HTML `200 "You have reached Cloudways API."` is the gateway
  catch-all and proves nothing.
- A gateway `upstream error (401)` means a stale `op_` token; an
  Anti-Bot/Imunify wall returns HTML/403 instead.
- Complete install: zip must sit above `public_html`; docs-hub permission
  errors are ownership (`chown`), not conflicts; run as `sudo -u {sys_user}`.
- Velocity env edits must be paste-appends, never whole-set replacements.
- Mock-mode estates have no minted credentials, so `GET …/envblock` returns
  409 by design.

## Cross-references

- `nv-digital-cloudways-wpcli` — fleet map, install cycles, API/MCP quick
  facts (US2 server, sys_user evidence).
- `mcp-ai-wpoos-gateway-ops` — gateway key/env/verification playbook.
- `mcp-ai-wpoos-assistant-portability` — bundle format and denylist rules.
- `mcp-ai-wpoos-spa-ui` — SPA stack/build/test conventions.
- `nvoos-forge-deploy` — mirror-branch deploy mechanics.
- `mcp-ai-wpoos-checkout-integration` — vendor checkout contract.
