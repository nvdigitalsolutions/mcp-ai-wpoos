# NV oOS Service Exchange

The platform where an expert deploys themselves as an MCP SaaS: a setup wizard
that provisions a Cloudways WordPress estate (Complete bundle + assistant
bundle + toolkits), a branded velocity console, an MCP gateway slug, a Stripe
price pointer, and a catalogue listing — from one Vite app.

- **Proposal:** [`docs/project/proposals/143-expert-service-exchange-proposal.md`](../../docs/project/proposals/143-expert-service-exchange-proposal.md)
- **Implementation plan:** [`docs/project/proposals/143-expert-service-exchange-implementation-plan.md`](../../docs/project/proposals/143-expert-service-exchange-implementation-plan.md)
- **Blueprint spec:** [`docs/service-blueprint-v1.md`](../../docs/service-blueprint-v1.md)
- **Deployment runbook:** [`docs/operations/deployment/service-exchange-velocity-setup.md`](../../docs/operations/deployment/service-exchange-velocity-setup.md)

## Architecture (one deployable)

```
Browser ──> Service Exchange (Velocity)
              ├── dist/                  # Vite wizard (this app)
              └── /api/v1/*              # zero-dependency control plane
                    ├── Cloudways client (API v1 → v2, version-tolerant)
                    ├── WP provisioner   (WP Manager / SSH / paste-block)
                    ├── GitHub client    (per-estate console branches)
                    ├── Gateway client   (verification ladder)
                    └── Commerce         (price pointer + entitlement)
```

Estate truth is **derived from provider probes** (ADR-04), never stored as
authority. The local JSONL job log is advisory.

## Quickstart

```bash
npm install
npm run build        # tsc --noEmit + vite build (entry budget ≤150 KB gzip)
npm run serve        # http://localhost:4182  (wizard + API, mock mode)
npm run dev          # http://localhost:5199  (Vite HMR, proxies /api)
```

Mock mode needs no credentials and runs the complete happy path:

```bash
npm run smoke        # health → 5 seeds → create → 9 steps → live → catalogue
```

## Scripts

| Script | Purpose |
|---|---|
| `npm run dev` | Vite dev server with `/api` proxy to :4182 |
| `npm run build` | Typecheck + production bundle |
| `npm run serve` / `start` | Zero-dependency HTTP server (dist + API) |
| `npm test` | Vitest wizard/UI suite (jsdom) |
| `npm run test:server` | `node --test` control-plane suite |
| `npm run smoke` | Full-stack mock-mode end-to-end check |
| `npm run probe:cloudways` | Read-only live Cloudways API probe (see the verification spike doc) |
| `npm run probe:mcp` | Cloudways MCP probe — lists tools / calls read-only tools via the Zed-configured server (token read at runtime, never printed) |

## Modes

- **`EXCHANGE_MODE=mock`** (default) — deterministic providers, no network,
  suitable for demos, tests and local development.
- **`EXCHANGE_MODE=live`** — real Cloudways/GitHub/gateway calls. Boot fails
  closed unless all required env is present.

## Environment (live)

| Variable | Required | Purpose |
|---|---|---|
| `EXCHANGE_MODE=live` | yes | Live provider calls |
| `EXCHANGE_ADMIN_TOKEN` | yes | Bearer token for every non-health route |
| `EXCHANGE_ENCRYPTION_KEY` | yes | AES-256-GCM at-rest encryption for `op_` tokens |
| `CLOUDWAYS_EMAIL` / `CLOUDWAYS_API_KEY` | yes | Cloudways OAuth |
| `CLOUDWAYS_DEFAULT_SERVER_ID` | yes | Target server for new estates |
| `CLOUDWAYS_BASE_URL` | no | Defaults to API **v2** (`https://api.cloudways.com/api/v2`) |
| `CLOUDWAYS_LEGACY_BASE_URL` | no | Defaults to v1 (`…/api/v1`) for legacy-only ops |
| `CLOUDWAYS_APP_TIMEOUT_MS` / `CLOUDWAYS_APP_POLL_MS` | no | App provisioning wait (default 600000 / 15000) |
| `EXCHANGE_WP_MANAGER` / `EXCHANGE_SSH_KEY` | no | WP execution tier hints |
| `EXCHANGE_GITHUB_TOKEN` / `EXCHANGE_CONSOLE_REPO` | console deploy | Mirror branch pushes |
| `GATEWAY_BASE_URL` | no | Defaults to `https://mcp.nvoos.pro` |
| `EXCHANGE_RECONCILE_MS` | no | Advisory reconcile loop (default 900000, `0` disables) |

## Security posture

- Secrets never leave the control plane unmasked: `op_` tokens and gateway
  keys are encrypted at rest (AES-256-GCM) and only fingerprints/masks are
  serialized.
- Blueprints are structurally scanned for secret-shaped keys and
  credential-shaped values (portability denylist inherited).
- Every state-changing route is bearer-authenticated and rate-limited;
  teardown requires `{ confirm: true, slug }`.
- Velocity env values are dashboard-only (Cloudways constraint) — the app
  renders a validated env block and verifies results, it never writes env.
- Error envelopes are stable codes; provider bodies never reach clients.

## The estate state machine

`requested → cloudways_app → wp_installed → bundle_imported →
credential_minted → console_deployed → gateway_bound → commerce_linked →
listed → live`

Every transition is idempotent and can degrade to a paste-block
(`api → ssh → manual`) when Cloudways automation is unavailable.

## Deploy

`.github/workflows/sync-nvoos-service-exchange.yml` builds, tests, smokes,
assembles a runtime-only tree (zero dependencies) and force-pushes the
`nvdigitalsolutions/nvoos-service-exchange` mirror. See the deployment
runbook for the one-time Velocity setup.
