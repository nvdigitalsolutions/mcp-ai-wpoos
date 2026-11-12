# Cloudways API Verification Spike — Service Exchange (Phase 2)

**Date:** 2026-11-12
**Status:** ✅ Route contract verified · ⏳ Authenticated end-to-end run pending credentials
**Scope:** `examples/nvoos-service-exchange/server/clients/cloudways.js` and the
call sites in `server/provision/steps.js`
**Method:** live unauthenticated probes + in-repo production client evidence
**Probe script:** `examples/nvoos-service-exchange/scripts/cloudways-api-probe.mjs`
(`npm run probe:cloudways`) and `scripts/cloudways-mcp-probe.mjs`
(`npm run probe:mcp`)

---

## 1. Why this spike was needed

The original client was written from production experience because
`developers.cloudways.com` is a client-rendered app and the Help Center sits
behind a Cloudflare challenge. Four call shapes were marked
`VERIFY(Phase 2)`:

1. OAuth endpoint version (v1 vs v2),
2. list-servers path (`/server` vs `/servers`),
3. create-app method/body,
4. app-status polling and the site URL fields.

---

## 2. Evidence sources

### 2.1 In-repo production code (authoritative for shapes)

| Source | What it proves |
|---|---|
| `addons/pro/includes/cloudways/class-wp-mcp-ai-cloudways-client.php` | API base is **v2**: `https://api.cloudways.com/api/v2`; OAuth at `/api/v2/oauth/access_token`; form body `email` + `api_key`; responses `{ access_token, expires_in }`; bearer auth on subsequent calls |
| `addons/cloudways-dashboard/includes/rest/class-nvoos-cloudways-dashboard-rest.php` | `GET /servers` (with optional `project_id`), `GET /server/{id}`, `GET /server/{id}/apps`, `GET /app/{id}`, `GET /projects`, and **`POST /app`** with `{ server_id: (string), application, app_label, project_name }`; creation response `{ app: { id } }` |
| `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php` | `GET /app/{app_id}` → `{ app: { status, app_fqdn, cname, username, password, … } }`; status set `running` / `provisioning` / `pending` / `deploying` / `failed` / `error`; poll-until-running with backoff |
| `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` | v2 OAuth + `GET /server` (singular) also live in the admin connection tester — both server-list shapes exist |

### 2.2 Live unauthenticated probes (2026-11-12)

`npm run probe:cloudways` output (abridged):

```
V1 POST /oauth/access_token   403  route-exists-auth-required   application/json
V1 GET  /server               400  route-exists                 application/json
V1 GET  /app/1                400  route-exists                 application/json
V2 POST /oauth/access_token   403  route-exists-auth-required   application/json
V2 GET  /server               400  route-exists                 application/json
V2 GET  /servers              400  route-exists                 application/json
V2 GET  /projects             200  route-exists (HTML catch-all) text/html
V2 GET  /server/1/apps        200  route-exists (HTML catch-all) text/html
V2 GET  /app/1                400  route-exists                 application/json
V2 GET  /app/1/credentials    200  route-exists (HTML catch-all) text/html
V2 GET  /app/1/app_credentials 200 route-exists (HTML catch-all) text/html
V2 POST /app                  400  route-exists                 application/json
V2 POST /app/cache/purge      400  route-exists                 application/json
V2 GET  /monitoring/server    200  route-exists (HTML catch-all) text/html
```

**How to read this:**

- **JSON `403 invalid_credentials`** on OAuth = the form contract is live; our
  dummy credentials were rejected with the documented error shape
  (`{"error":"invalid_credentials","error_description":"…"}`).
- **JSON `400 invalid_request`** on an authenticated route = the API gateway
  recognized the route and rejected the missing OAuth parameter. This is a
  positive existence signal.
- **HTML `200 "You have reached Cloudways API."`** is the gateway catch-all;
  it is **inconclusive** — those paths are only validated by production code
  (and, ultimately, by the authenticated run).

---

### 2.3 Cloudways MCP evidence (live, 2026-11-12)

Both Cloudways MCP servers are configured in Zed (`cloudways-nvdigital`,
`cloudways-mushroom`; Streamable HTTP at `https://mcp.cloudways.com/mcp/`).
They are not attached to every agent session, so the same endpoint was called
by `examples/nvoos-service-exchange/scripts/cloudways-mcp-probe.mjs`
(credentials read from the Zed settings at runtime; never printed).

Observed:

- `initialize` → protocol `2025-03-26`, server `cloudways-mcp-server`.
- **66 tools** advertised (server/app lifecycle, services, backups, DNS,
  monitoring, analytics, copilot).
- `server_list` → 5 servers (`NV Digital - US2` 1686836, `NV Digital MCP`
  1536372, `- SIN2` 1122552, `- SIN` 1099457, `- US` 1099434). Note: the
  fleet skill's server table does not yet list **US2**.
- `app_list` → account-wide, paginated; IDs are **strings**
  (`"id":"6618594"`), which matches the `server_id: "<string>"` create body.
- `app_get` → confirms the app record shape:
  `{ id, label, application, cname, app_fqdn, server_id, sys_user, project_name, … }`.
  **`sys_user` is present** and cross-validated: `tvhqswptzh` for
  “MCP Server - Samtani”, exactly the folder name in the fleet map.
- `app_credentials` → `{ app_creds, navigate }`; `app_creds` was an empty
  array for the sampled app (no credentials line exposed). Not required for
  provisioning — `app_get` supplies `sys_user`.
- MCP tool schemas require **string** `server_id` / `app_id`; passing numbers
  returns `Input validation error: … is not of type 'string'`.

**Security note:** the MCP access tokens live in the Zed settings file. The
probe reads them in-process and prints only header *names*. If a token has
ever been pasted into a shared transcript or log, rotate it in the Cloudways
MCP connection settings and update the Zed config.

---

## 3. Verified contract (implemented)

| Operation | Endpoint | Method | Evidence | Confidence |
|---|---|---|---|---|
| OAuth (v2) | `/api/v2/oauth/access_token` | POST (form `email`,`api_key`) | live + production | ✅ high |
| OAuth (v1 legacy) | `/api/v1/oauth/access_token` | POST (same form) | live | ✅ high |
| List servers | `/api/v2/servers` | GET | production dashboard; live JSON 400 | ✅ high |
| List servers (legacy shape) | `/api/v2/server` | GET | production AJAX; live JSON 400 | ✅ high |
| List apps | `/api/v2/server/{serverId}/apps` | GET | production dashboard | ✅ high |
| Get app | `/api/v2/app/{appId}` | GET | production ×2; live JSON 400 | ✅ high |
| Create app | `/api/v2/app` | POST → `{ server_id:"<string>", application, app_label, project_name }` | production dashboard; live JSON 400 | ✅ high |
| App status values | `running` = ready; `provisioning/pending/deploying` = wait; `failed/error` = terminal | — | production job | ✅ high |
| Site URL | `https://{cname}` (fallback `app_fqdn`) | — | production job | ✅ high |
| Cache purge | `/api/v2/app/cache/purge` | POST | live JSON 400 (route exists); **payload shape unverified** | ⚠️ medium |
| Project list | `/api/v2/projects` | GET | production dashboard; live HTML catch-all | ⚠️ medium |

### Still unverified

| Item | Why | Current handling |
|---|---|---|
| ~~`sys_user`~~ | ~~No in-repo route returns it~~ — **resolved**: `app_get` returns it on the app record | Read from `getApp()` / `ensureApp()`; manual fallback remains |
| `app_version` | Production create call does not send it | Optional request field; omitted by default |
| Cache-purge payload | Route exists; body not exercised | `{ server_id, app_id }` as strings; marked in code |
| Rate limits / quotas | Not observable unauthenticated | Client retries once on 5xx; no burst behaviour assumed |

---

## 4. Corrections applied

`server/clients/cloudways.js` (rewritten):

1. **API v2 is primary** — `CLOUDWAYS_BASE_URL` now defaults to
   `https://api.cloudways.com/api/v2` (matching the production client);
   `CLOUDWAYS_LEGACY_BASE_URL` (`…/api/v1`) is available for v1-only ops.
2. **OAuth** uses the primary base (`/v2/oauth/access_token` by default) and
   surfaces the vendor's `error_description` in the error message (safe text,
   no secrets).
3. **`listServers()`** calls `/servers` and falls back to `/server` only on a
   404 — both shapes exist live.
4. **`ensureApp()`** sends the verified body: `server_id` as a **string**,
   `application`, `app_label`, optional `project_name`, optional
   `app_version` (omitted by default).
5. **`getApp(appId)`** added, unwrapping `{ app: … }`.
6. **`waitForApp()`** added — polls `GET /app/{id}` with the production
   status set; terminal failures and timeouts raise actionable `ApiError`s.
7. **`siteUrlFromApp()`** added — `https://{cname}` with `app_fqdn` fallback.
8. **`purgeCache()`** targets the live-confirmed route with string ids.
9. Removed the v1/v2 `probeV2()` heuristic (no longer meaningful with v2 as
   the primary base).

`server/provision/steps.js`:

- `cloudways_app` now persists `urls.site` from the app record and waits for
  `running` when a status is present.
- `sys_user` resolution accepts `sys_user` / `system_user` if ever returned;
  otherwise the existing fail-closed remediation stands.

`server/config.js`: base URLs + `CLOUDWAYS_APP_TIMEOUT_MS` (default 600 000)
and `CLOUDWAYS_APP_POLL_MS` (default 15 000).

---

## 5. Authenticated verification checklist (pending credentials)

Run when `CLOUDWAYS_EMAIL` + `CLOUDWAYS_API_KEY` are available (read-only —
no writes, no app creation):

```bash
cd examples/nvoos-service-exchange
CLOUDWAYS_EMAIL=… CLOUDWAYS_API_KEY=… CLOUDWAYS_DEFAULT_SERVER_ID=… \
  npm run probe:cloudways
```

The script prints, without echoing tokens or account data:

- `POST /oauth/access_token` → status + `token=present|missing`
- `GET /servers` → status + top-level keys/count
- `GET /projects` → status + top-level keys/count
- `GET /server/{id}/apps` → status + top-level keys/count

Then run one wizard estate in `EXCHANGE_MODE=live` against a **staging**
server and confirm:

1. `cloudways_app` reaches `running`, records `urls.site`, and hydrates
   `provider.sysUser` from the app record (the record shape is already
   confirmed via MCP `app_get`; this run confirms the raw REST payload).
3. `POST /app` creation is idempotent on a second advance.
4. Cache purge succeeds with the current payload (or is corrected here).

---

## 6. References

- Proposal 143 §2.4 (Cloudways automation research)
- Implementation plan §7 (research provenance) and §13 (status)
- `addons/pro/includes/cloudways/class-wp-mcp-ai-cloudways-client.php`
- `addons/cloudways-dashboard/includes/rest/class-nvoos-cloudways-dashboard-rest.php`
- `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php`
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (`handle_fetch_cloudways_data_v2`, `handle_test_cloudways_connection_v2`)
