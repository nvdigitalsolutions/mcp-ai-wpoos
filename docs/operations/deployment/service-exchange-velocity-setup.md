# Service Exchange — Cloudways Velocity Deployment & Operations

**App:** `examples/nvoos-service-exchange` → mirror
`nvdigitalsolutions/nvoos-service-exchange`
**Workflow:** `.github/workflows/sync-nvoos-service-exchange.yml`
**Proposal:** 143 · **Plan:** `docs/project/proposals/143-expert-service-exchange-implementation-plan.md`

---

## 1. What gets deployed

A single Velocity Node app serving:

- the built Vite wizard (`dist/`), and
- the zero-dependency control plane (`server/`, mounted at `/api/v1/*`).

The runtime has **no npm dependencies** (`node:` builtins only), so Velocity's
install/build steps are no-ops in the mirror tree.

---

## 2. One-time setup

1. **Create the mirror repo** `nvdigitalsolutions/nvoos-service-exchange`.
2. **Add a repo-scoped token** as the monorepo secret
   `NVOOS_SERVICE_EXCHANGE_REPO_TOKEN` (contents: write on the mirror).
3. **Backfill the mirror:** Actions → *Sync nvoos-service-exchange* →
   Run workflow (`workflow_dispatch`).
4. **Create the Velocity app:**
   - Connect `nvdigitalsolutions/nvoos-service-exchange`, branch `main`.
   - Root directory: repo root.
   - Entry file: `server.js`.
   - Build command: none (the bundle ships prebuilt).
   - Runtime: Node 22 LTS.
5. **Set env** (dashboard, one row per variable — never replace the whole
   set):

### Mock (demo) deployment

| Variable | Value |
|---|---|
| `EXCHANGE_MODE` | `mock` |
| `PORT` | injected by Velocity |

### Live deployment

| Variable | Required | Notes |
|---|---|---|
| `EXCHANGE_MODE` | yes | `live` |
| `EXCHANGE_ADMIN_TOKEN` | yes | ≥32 random chars; bearer for every non-health route |
| `EXCHANGE_ENCRYPTION_KEY` | yes | AES-256-GCM key material for stored `op_` tokens. **Changing it invalidates stored tokens** (re-mint credentials) |
| `CLOUDWAYS_EMAIL` | yes | Cloudways account email |
| `CLOUDWAYS_API_KEY` | yes | Cloudways API key |
| `CLOUDWAYS_DEFAULT_SERVER_ID` | yes | Target server for new estates |
| `CLOUDWAYS_BASE_URL` | no | Defaults to **v2**; see `docs/operations/deployment/cloudways-api-verification-spike.md` |
| `CLOUDWAYS_LEGACY_BASE_URL` | no | Defaults to v1 for legacy-only ops |
| `CLOUDWAYS_APP_TIMEOUT_MS` / `CLOUDWAYS_APP_POLL_MS` | no | App provisioning wait (600000 / 15000) |
| `EXCHANGE_WP_MANAGER` | no | `true` when the account has WP Manager |
| `EXCHANGE_SSH_KEY` | no | `true` when the SSH runner is configured (see §6) |
| `EXCHANGE_GITHUB_TOKEN` | console deploy | Fine-grained token: contents write on the console mirror |
| `EXCHANGE_CONSOLE_REPO` | no | Default `nvdigitalsolutions/nvoos-complete-velocity` |
| `GATEWAY_BASE_URL` | no | Default `https://mcp.nvoos.pro` |
| `EXCHANGE_RECONCILE_MS` | no | Advisory reconcile loop; `0` disables |
| `EXCHANGE_JOBS_FILE` | no | Default `data/jobs.jsonl` (advisory, lossy) |

Boot is **fail-closed**: `EXCHANGE_MODE=live` with any required variable
missing refuses to start.

---

## 3. Dry-run verification (before any customer)

0. **API contract spike:** `CLOUDWAYS_EMAIL=… CLOUDWAYS_API_KEY=… CLOUDWAYS_DEFAULT_SERVER_ID=… npm run probe:cloudways`
   (read-only; see `cloudways-api-verification-spike.md`).
1. `GET /api/v1/health` → `{ ok: true, data: { mode: "live", version: "0.1.0" } }`.
2. `GET /api/v1/blueprints` with the admin token → 5 seeds.
3. `POST /api/v1/estates` with `{"blueprint_slug":"clinic-ops"}` →
   `state: "requested"`.
4. `POST /api/v1/estates/clinic-ops/advance` once per step, watching the
   returned `steps[]`. Stop at the first non-`ok` step and apply its
   remediation.
5. `GET /api/v1/estates/clinic-ops` → verify every step `ok` and
   `entitlement: "trialing"`.
6. `GET /api/v1/catalogue` → the estate appears once `listed` + entitled.

---

## 4. What each provisioning step touches

| Step | Provider action | Degradation |
|---|---|---|
| `cloudways_app` | `ensureApp` (lookup by label first) + poll `GET /app/{id}` until `running`; records `urls.site` from `cname` and `provider.sysUser` from the app record | Manual app creation in the Cloudways console; set `provider.sysUser` if the record lacks it |
| `wp_installed` | WP-CLI install cycle (deactivate → delete → install → activate) | SSH tier; then paste-block |
| `bundle_imported` | `wp mcp-ai assistant import --file=…` | Upload bundle JSON, set `provider.bundlePath` |
| `credential_minted` | `wp mcp-ai credential issue <assistantId>` | Set `provider.assistantId`, re-run |
| `console_deployed` | GitHub mirror branch `estate/<slug>` + Velocity app | Guided dashboard step |
| `gateway_bound` | Env block generated + verification ladder | Apply env in Velocity dashboard, re-advance |
| `commerce_linked` | Price pointer (`price_…`) + trial | Link via `POST /estates/{slug}/commerce` |
| `listed` | Catalogue entry | — |
| `live` | Full gateway ladder re-verified | — |

Every failure returns `{ remediation, tier, pasteBlock? }`; paste-blocks end
each line with `;` (the owner terminal merges lines without them).

---

## 5. Gateway binding (dashboard-only)

Cloudways Velocity env vars have no API surface, so the Exchange renders the
complete, validated env set (`GET /api/v1/estates/{slug}/envblock?reveal=1`,
admin only):

```
GATEWAY_PUBLIC_KEYS=<base64url-key>=<slug>
NVOOS_SITE_<SLUG>_URL=https://<estate-host>
NVOOS_SITE_<SLUG>_TOKEN=op_xxxx.SECRET
```

Rules (enforced by the generator):

- One row per variable; duplicate rows do not merge.
- A key bound to a slug without matching `NVOOS_SITE_*` rows is a gateway
  **boot error** — add all three in the same edit.
- Public estates: append the slug to `PUBLIC_SITE_SLUGS`.
- After saving, the app restarts (`/health` `uptime` resets) — re-run the
  verification ladder:

```
GET /health              → version matches the deployed gateway
GET /health/full         → sites.<slug> == ok
POST /mcp tools/list     → <slug>.* present, no _meta.gateway.errors
POST /mcp tools/call     → one allowlisted tool succeeds
```

**Recurring failure:** `upstream error (401)` means the estate's `op_` token
was rotated or mistyped. Re-mint in the estate's Fleet Operator, update the
Velocity env row, retry. A Cloudways Anti-Bot/Imunify wall returns HTML/403
instead — that is a different fix (allowlist the gateway egress).

---

## 6. Execution tiers

1. **WP Manager** (preferred) — REST transport, no SSH keys.
2. **SSH** — master user; run WP-CLI as the app user
   (`sudo -u {sys_user} -- wp …`) to avoid upload ownership errors.
3. **Manual paste-block** — always available, first-class product path.

Known quirks:

- Zip uploads must sit **above** `public_html`
  (`~/applications/{sys_user}/`), not inside it.
- `file_put_contents … nvoos-docs-hub: Permission denied` during install is
  an ownership issue: `sudo chown -R {sys_user}:{sys_user}
  ~/applications/{sys_user}/public_html/wp-content/uploads/nvoos-docs-hub`.
- Public-key SSH failures on some apps are environmental; fall back to
  paste-blocks rather than blocking the sale.

---

## 7. Rollback

- **App rollback:** redeploy the previous mirror commit from Velocity's
  deployment history (or re-run the sync workflow on the previous monorepo
  commit).
- **Estate carve-out:** estates are additive and state is derived, so a
  rollback never orphans a partially provisioned estate — advancing again
  converges.
- **Storage rollback:** `EXCHANGE_ENCRYPTION_KEY` changes invalidate stored
  credentials. Recover by re-minting `op_` tokens (estate-local action) and
  re-running the gateway step.

---

## 8. Teardown

`POST /api/v1/estates/{slug}/teardown` requires
`{ "confirm": true, "slug": "<slug>" }`. It removes the Exchange record and
records a teardown event; **provider resources (Cloudways app, Velocity app,
gateway env rows) are intentionally not auto-deleted in v1** — operator
confirmation is required because deleting the wrong Cloudways app is
unrecoverable. Follow the checklist:

1. Remove the gateway env rows (app restart).
2. Delete the Velocity console app.
3. Delete the Cloudways app (after backup).
4. Revoke the `op_` credential on the estate (if the estate still exists).

---

## 9. Observability & limits

- Request logs: method, path, status, duration — never headers or bodies.
- `data/jobs.jsonl`: advisory event stream (create/step/step_failed/teardown).
  Lossy by design; never used as the source of truth.
- Rate limiting: fixed-window per IP + route group (`EXCHANGE_RATE_MAX`,
  `EXCHANGE_RATE_WINDOW_MS`).
- v1 auth is a single admin token; multi-owner scoping is a later phase.

---

## 10. References

- Gateway ops: `.agents/skills/mcp-ai-wpoos-gateway-ops/SKILL.md`
- Cloudways fleet/install cycles: `.agents/skills/nv-digital-cloudways-wpcli/SKILL.md`
- Console template: `examples/nvoos-complete-velocity/`
- Portability rules: `.agents/skills/mcp-ai-wpoos-assistant-portability/SKILL.md`
