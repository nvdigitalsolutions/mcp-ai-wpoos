---
name: mcp-ai-wpoos-gateway-ops
description: "Operational guide for the NV oOS MCP Gateway (addons/mcp-gateway → mirror nvoos-mcp-gateway → Cloudways Velocity at mcp.nvoos.pro, issue #6902). Covers public-key management (rotation, PUBLIC_SITE_SLUGS privacy filter), the upstream-401 token re-mint loop, deploy verification, the OAuth 2.1 / ChatGPT connector track (Auth0, DCR, PKCE harness, Proposal 099), registry publishing via mcp-publisher, directory-submission packs, and the test gotchas. Use when operating the gateway, debugging gateway auth/upstream errors, deploying to Velocity, working the ChatGPT OAuth connector, or continuing the MCP directory submissions."
---

# mcp-ai-wpoos — Gateway ops

## What / where

- Canonical source: `addons/mcp-gateway/` (Node, express, zero-added-deps, env-configured, fail-closed).
- One-way mirror: `nvdigitalsolutions/nvoos-mcp-gateway` (`main`) via `.github/workflows/sync-mcp-gateway.yml` — snapshot commit per sync, no `--force`, version-guarded (older `package.json` versions are skipped). Never commit to the mirror directly.
- Deploy target: Cloudways Velocity at **`https://mcp.nvoos.pro`**. Auto-deploy fires on the mirror push; env vars are dashboard-only (no Cloudways API/MCP surface for Velocity).
- Tracking issue: **#6902**. Deploy guide: `docs/operations/deployment/mcp-gateway-velocity-setup.md`. Submission pack: `docs/operations/deployment/mcp-gateway-directory-submission.md`.

## Live map (verified 2026-11-03)

| Slug | Site | Visibility |
|---|---|---|
| `demo` | `https://nvoos.pro` | Public — demo key, listings |
| `sascha` | `https://nirmanawellness.nvdigital.solutions` | Private client — own key |
| `ideabits` | `https://ideabits.nvdigital.solutions` | Private client — own key |
| `console` | `https://console.nvoos.cloud` | (not bound at time of writing) |

- `PUBLIC_SITE_SLUGS=demo` — public `/health` reports only `demo`; `/health/full` (keyed) reports every site.
- **OAuth 2.1 is configured** (Auth0 tenant `https://nvoos.us.auth0.com`) —
  see the OAuth section below; static keys + OAuth coexist.
- Demo key (published in directory listings, **never in the repo**): bound to `demo` only, allowlisted `get_environment_status` + `deep_research`.
- Client sites carry their **own** public keys (one key may bind both ideabits+sascha when the same client owns them).

## Public keys — GATEWAY_PUBLIC_KEYS

- Syntax: `key=slug-a,slug-b;key2=slug-c` (or a JSON object `{"key":["slug-a"]}`).
  `;` = entry separator, `=` = key→sites, `,` = multiple sites. A trailing `;`
  is harmless. Whitespace is trimmed.
- Keys are 32-byte `base64url` (`node -e "console.log(require('crypto').randomBytes(32).toString('base64url'))"`)
  — the alphabet can never contain `;`, `,`, or `=`, so no quoting is needed.
  Minimum length 16 (`MIN_KEY_LENGTH`).
- **One variable, one line**: the Velocity dashboard must have a single
  `GATEWAY_PUBLIC_KEYS` row holding the whole map — duplicate rows do not
  merge.
- **Fail-closed**: a key bound to an unconfigured slug (no
  `NVOOS_SITE_<SLUG>_URL`/`_TOKEN`) is a boot error and takes the whole
  gateway down — add the site vars in the same env edit as the key.
- Rotation: new key in `GATEWAY_PUBLIC_KEYS`, old one in
  `GATEWAY_PUBLIC_KEYS_PREVIOUS` (accepted with a one-time warning), remove
  once consumers switched.
- `PUBLIC_SITE_SLUGS` (added 2026-11-01, PR #7347): comma-separated slugs
  visible on the public `/health`; unset/`*` = all (legacy). Hidden sites
  stay invisible publicly and their degradation does not flip the public
  `status`; `/health/full` always reports everything. Unknown slugs are
  **boot warnings, never fatal** (a typo hides sites — the safe direction).
- `PUBLIC_DEMO_KEY` (added 0.3.2, PR #7446): optional — when set, the
  public landing page (`GET /`) renders the operator-published demo
  credential in a "Try it now" block and substitutes it into the
  copy-paste client configs (HTML-escaped). Unset = `YOUR_KEY`
  placeholders. Public-by-design; the value lives only in the Velocity
  env, never in the repo.

## Upstream 401 playbook (the recurring failure)

Symptom: `tools/list` → `_meta.gateway.errors.<slug>: "upstream error (401)"`
(the gateway degrades gracefully — other sites keep flowing).

1. Identify the host: `GET /health/full` with any key → `sites.<slug>.url`.
2. Probe the site directly:
   `curl -s -X POST https://<host>/wp-json/mcp-ai/v1/mcp -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{...}}'`
   — the plugin's structured `{"code":"wp_mcp_ai_mcp_auth_required",...}`
   401 means the **operator token is stale/rotated** (not a bot-wall; a
   Cloudways Anti-Bot/Imunify wall would return HTML/403).
3. Fix on the target site: Settings → External Operators → re-mint the
   demo/client operator's token (**full `op_xxxx.SECRET`**, both halves, no
   trailing whitespace) and set its Allowed tools (least privilege —
   e.g. `get_environment_status` for public demo keys).
4. Update `NVOOS_SITE_<SLUG>_TOKEN` in the Velocity env (env edits restart
   the app — `/health` `uptime` resets).
5. Verify: `tools/list` shows no `_meta.gateway.errors` + one safe
   `tools/call` (e.g. `<slug>.get_environment_status`).

## Deploy verification ladder

- **Version marker**: the `version` field of `GET /health` (public) and
  `GET /health/full` (keyed) must equal `addons/mcp-gateway/package.json`'s
  `version` — there is **no `/health.version` route** (it 404s; verified live
  2026-11-03). The `VERSION` constant in `src/index.js` is a hardcoded string
  that must track it (PR #7345 added the comment + a health test that
  enforces the contract — a version bump that misses the constant now fails
  the suite). Historical: the 0.2.0 bump forgot it and `/health` lied 0.1.1.
- **0.2.0 fingerprints** (independent of the marker): `traceparent` response
  header + RFC 9558 `RateLimit-*` headers (the policy layer).
- **Restart clock**: `uptime` tells when the app last restarted — compare
  against the mirror-sync time to judge whether a deploy landed.
- **Stale deploy** (mirror advanced, `/health` version behind, uptime older
  than the sync): Velocity auto-deploy missed it → Deployment Manager shows
  a commit not in the mirror history → disconnect/reconnect the GitHub repo
  and redeploy (§7 of the setup guide). Also the CI-skip-marker incident:
  a squash body absorbing `[ci skip]` runs zero workflows — recover with a
  follow-up PR touching `addons/mcp-gateway/**` or
  `gh workflow run sync-mcp-gateway.yml --ref alpha-working`.
- **Env edit pitfall**: paste-append, never replace the whole env set — the
  replace dropped three site-var groups in one session (2026-11-01).

## OAuth 2.1 / ChatGPT connector (Proposal 099, Phases 0–3 shipped 2026-11-01)

Live state: the gateway is **OAuth-configured** — Auth0 tenant
`https://nvoos.us.auth0.com` wired via `GATEWAY_OAUTH_ISSUER` /
`GATEWAY_OAUTH_JWKS_URI` / `GATEWAY_OAUTH_RESOURCE=https://mcp.nvoos.pro`
(+ `GATEWAY_OAUTH_DEFAULT_SITES=demo`, PR #7373).
`GET /.well-known/oauth-protected-resource` (root + `/mcp` variant) serves the
RFC 9728 document; unauth 401s carry
`WWW-Authenticate: Bearer, resource_metadata="…", scope="site:read site:demo …"`.
Static keys keep working (dual mode). **ChatGPT connector LIVE 2026-11-01** —
Phase 4 walkthrough completed (see the connector notes below).

- **Auth0 checklist** (Phase 1, done live): create API with
  **Identifier = `https://mcp.nvoos.pro`** (canonical token `aud`), add
  permissions `site:read` / `site:<slug>` per site (colon names allowed).
  **ChatGPT needs a second API with Identifier = the full server URL
  `https://mcp.nvoos.pro/mcp`** — the connector binds `resource` to the
  path-insertion metadata URL (RFC 9728 §3.3) and Auth0 matches it exactly
  (missing → error page `access_denied : Service not found: …`).
  **DCR toggle: Dashboard → Settings → Advanced → “Dynamic Application
  Registration”** — the docs’ “Settings → Applications” path is stale (cost a
  search round live). Smoke: `POST https://<tenant>/oidc/register` → 400
  “dynamic client registration is disabled” when off, **201 + client_id**
  when on (Auth0 issues a client_secret even for `none` auth — harmless).
  API alternative `flags.enable_dynamic_client_registration` unverified —
  a tenant-flags PATCH 400'd live for another flag; use the dashboard.
- **DCR’d clients get NO connections** → authorize fails with
  `invalid_request : no connections enabled for the client`. Fix (verified
  live 2026-11-01): promote the DB connection to a domain connection via
  the Management API — `PATCH /api/v2/connections/<con_…>` with
  `{"is_domain_connection":true}` — which enables it for every app
  including DCR ones (the dashboard “Enable Application Connections”
  tenant toggle did NOT take in this tenant). Management API access:
  reuse the auto-created **“Auth0 Management API (Test Application)”**
  (pre-authorized; secret in its Settings) or create an M2M app — gotcha:
  the client-grant must target the **Management API** (`audience
  https://<tenant>/api/v2/`), not the gateway API.
- **Auth0 serves RFC 8414** at `/.well-known/oauth-authorization-server`
  (separate from the OIDC `openid-configuration` document) with
  `registration_endpoint` + `code_challenge_methods_supported: ["S256","plain"]`
  — S256 presence is the OpenAI requirement; “plain” is Auth0-default and
  harmless (PKCE never reaches the gateway — it only validates bearer
  tokens).
- **Implicit-grant hardening (done 2026-11-01)**: `disable_implicit_grant`
  is NOT a writable tenant flag — `PATCH /api/v2/tenants/settings` with
  `flags.disable_implicit_grant` → 400 “Additional properties not allowed”
  (verified atomic: no other flag changed). The real lever is per
  application: **Application → Settings → Advanced → Grant Types**. Exposure
  is nil in practice anyway: DCR’d clients register `authorization_code`
  only, and the gateway never uses the implicit flow.
- **PKCE harness**: `node --test --test-concurrency=1 src/oauth/pkce-e2e.test.js`
  (PR #7362) — fake AS + full ChatGPT-connector flow, 7 tests; the OAuth group
  (`pkce-e2e` + `jwt` + `oauth` + `resource-server`) is 27/27. Gotcha: the
  fake AS uses `express.urlencoded` — never re-read the stream in handlers
  (`end` never fires → hang); form fields arrive parsed in `req.body`.
- **Security probes** (all rejected live 2026-11-01): `alg:none` → 401,
  attacker-signed RS256 with an unknown `kid` → 401 `unknown_kid` (the
  gateway trusts only the AS JWKS), HS256 alg-confusion → 401
  `invalid_token`, short/unknown static keys → 401, `/admin/*` +
  `/health/full` unauth → 401, `http` → 301 https, TLS valid (WE1),
  helmet headers + HSTS present, Auth0 JWKS has no private material.
- **Phase 4 walkthrough — DONE live 2026-11-01** (owner-verified connect):
  ChatGPT → MCP servers → add `https://mcp.nvoos.pro/mcp` → DCR (fresh
  client per attempt) → authorize → Auth0 consent → tools appear namespaced.
  Connector quirks (all verified live): it requests ALL AS-metadata scopes
  (Auth0's OIDC set, never `site:*`) and requires an exact match in the
  token response (else `OAUTH_SCOPES_MISMATCH`) → post-login Action
  `api.accessToken.addScope(...)` for the OIDC set, wrapped in try/catch (an
  unguarded throw breaks EVERY tenant login with the generic “service
  outage” page); scope-less tokens fall back to
  `GATEWAY_OAUTH_DEFAULT_SITES` (PR #7373).
- **Free diagnostics (no dashboard needed)**: the authorize endpoint is a
  probe — its error pages expand to “TECHNICAL DETAILS” naming the cause
  (`Service not found: …`, `no connections enabled…`, `Unknown client…`);
  a session-less browser reproduces server-side failures (client/browser
  issues show the “couldn't find your session” template instead).
  DCR without `redirect_uris` → 400 payload error when DCR is on (proves
  the toggle without creating a client).

## ChatGPT plugin directory submission (Proposal 101, live 2026-11-03)

The public-directory plugin for the demo MCP (`addons/chatgpt-gateway-plugin`,
0.1.0) — the “NV oOS Gateway” entry locked to `https://mcp.nvoos.pro/mcp`.
Distinct from the Proposal 099 connector above (that stays for power users;
this is the directory discoverability layer). Plan doc:
`docs/project/proposals/101-nvoos-gateway-chatgpt-plugin-implementation-plan.md`.

- **Shipped**: gateway 0.3.0 deployed (2026-11-03 — `/health` version
  0.3.0); challenge route live (`GET /.well-known/openai-apps-challenge`
  → 404 JSON `challenge_not_configured` until `GATEWAY_OPENAI_CHALLENGE_TOKEN`
  is set at E.4 — set it only while completing a submission, then unset);
  plugin scaffold (dual manifests, 3 `demo_*` skills, canonical-URL
  `bin/validate.mjs`); mirror `nvdigitalsolutions/nvoos-chatgpt-gateway-plugin`
  seeded + sync workflow; **GitHub Pages live** at
  `https://nvdigitalsolutions.github.io/nvoos-chatgpt-gateway-plugin/`
  (landing + privacy + terms from `docs/`; Pages API shows `status: built`).
- **Key-type confusion is the recurring mistake**: the demo site's **operator
  token** (`op_xxxx.SECRET`, what the gateway holds server-side in
  `NVOOS_SITE_DEMO_TOKEN`) is NOT a gateway key — the gateway rejects it with
  `unauthorized` / “Unknown API key”. Gateway client keys are 32-byte
  `base64url`. To smoke upstream without the gateway: POST the op_ token
  straight to `https://nvoos.pro/wp-json/mcp-ai/v1/mcp`.
- **Tier-1 smoke (C.4, done 2026-11-03, recorded in the addon README's
  “Verification” section — never the key itself)**: gateway key → `tools/list`
  (two `demo_*` tools, `_meta.gateway.sites:["demo"]`, no errors) →
  `tools/call demo_get_environment_status` (live WP/PHP/plugin report).
  Upstream annotations verified: `readOnlyHint: true` both; `openWorldHint`
  true only for `deep_research` (matches plan E.5 expectations).
- **Registry listing is stale at 0.2.0** (`io.github.nvdigitalsolutions/nvoos-mcp-gateway`,
  verified 2026-11-03) — re-run `mcp-publisher publish` at 0.3.0 before/at
  submission so directory imports reflect the deployed version.
- **Walkthrough recording (D.2)**: gateway-side demo recorded
  `C:\Users\rasta\Videos\nvoos-gateway-walkthrough.mp4` (H.264, ~47 s: demo
  page live calls → `/health` → OAuth well-known → Pages listing). Recording
  tricks: the CDP screencast emits frames on change only (idle-compressed,
  AV1) — transcode with ffmpeg to H.264 before upload; browser demos of live
  gateway calls need a local Node proxy (the gateway sends no CORS headers).
  The portal's ChatGPT-UI segment (add MCP server → Auth0 consent → tools
  appear) must be owner-recorded in ChatGPT — never share the ChatGPT
  account password in chat; if one leaks, rotate it.
- **Open Phase E blockers**: OpenAI portal org verification; ZIP upload +
  metadata findings loop; MCP connect (OAuth → DCR) with the portal redirect
  URI allowlisted in Auth0; domain verification via the challenge token;
  Scan Tools reconciliation (exact `demo_*` list is authoritative for the
  review block; watch `demo_nvoos_get_profile` — don't reference it, and
  policy-deny it if the scan flags `_meta["openai/profile"]`); dedicated
  Auth0 reviewer test user (no MFA, no site account).

## Claude Code plugin distribution (2026-11-04)

Two marketplace-installable distributions, both pointing at the same gateway:

- **Gateway addon plugin** — `addons/mcp-gateway/.claude-plugin/`
  (`nvoos-mcp-gateway`; manifest `version` tracks the gateway release,
  currently 0.3.2). Marketplace `nvoos-marketplace` ships in the same
  folder, so the mirror installs by name:
  `/plugin marketplace add nvdigitalsolutions/nvoos-mcp-gateway` →
  `/plugin install nvoos-mcp-gateway@nvoos-marketplace`.
- **Slim directory plugin** — `addons/claude-gateway-plugin/` (`nvoos-gateway`,
  0.1.1; plugin-only files + icon + `docs/` privacy/terms Pages scaffold).
  Marketplace `nvoos-claude-marketplace`; mirror
  `nvdigitalsolutions/nvoos-claude-gateway-plugin` (sync via
  `sync-claude-gateway-plugin.yml` — subtree split + force-push, secret
  `CLAUDE_GATEWAY_PLUGIN_REPO_TOKEN`). This is the artifact for the
  **Anthropic plugin directory** submission (needs a paid claude.ai plan +
  the developer portal at claude.ai/directory/manage + GitHub Pages enabled
  on the mirror for the privacy/terms URLs).

Release discipline: bump `.claude-plugin/plugin.json` `version` with every
release or installed plugins never see updates. No `claude` CLI on this
machine — `node bin/validate-claude-plugin.mjs <dir> --strict` (monorepo
root; deliberately not shipped in the plugin distribution, which keeps the
directory scan clean) checks the manifest, marketplace catalog, component
front matter, and README/LICENSE requirements; the sync workflow runs it in
a `validate` job.

OAuth alternative: the gateway's RFC 9728 metadata + Auth0 DCR are what
Claude Code's `/mcp` OAuth flow discovers — documented in the slim
plugin's `connect` command. Verified live for the ChatGPT connector, not
yet recorded end-to-end from Claude Code.

## Registry publishing (mcp-publisher)

- Binary: `C:/Users/rasta/.mcp-publisher/mcp-publisher.exe` (device-flow
  session stored on the machine; **the JWT expires — re-run
  `mcp-publisher login github` before publish**; the flow prints a GitHub
  device code and polls).
- `validate` hits the live registry schema (no auth). **`description` must
  be ≤ 100 chars** (422 otherwise; the bridge entry used 95).
- `publish` from the `addons/mcp-gateway/` dir (resolves `server.json` from
  the CWD). Entry: `io.github.nvdigitalsolutions/nvoos-mcp-gateway`
  (remote-only; no packages/ownership marker needed for remotes).
- Verify: `GET https://registry.modelcontextprotocol.io/v0.1/servers?search=<name>`
  → `count:1`, `_meta.io.modelcontextprotocol.registry/official.status: active`.
  Detail URL: `/v0.1/servers/<encodeURIComponent(name)>/versions/<version>`.
- Propagation: mcp.directory, PulseMCP, and Glama **auto-import from the
  official registry** — no manual submission; check +24h/+7d. Only
  **mcpservers.org** needs a manual form (https://mcpservers.org/submit).
- Demo-key rule: the key value is published **in each directory listing**
  but must never be committed to the repo or issues.

## Assets & tests

- `addons/mcp-gateway/assets/` is served at `/assets` (static middleware).
  Logo for listings: `https://mcp.nvoos.pro/assets/nvoos-logo.png`
  (512×512, converted from the brand favicon via PowerShell System.Drawing;
  ship the source JPG alongside).
- The local `.git/info/exclude` ignores `*.png`/`*.jpg` (per-worktree guard)
  — `git check-ignore -v <path>` before adding, then `git add -f` for
  deliberate assets.
- Tests: `npm test` hangs on Windows after the quota suites (pre-existing;
  run targeted files instead — `node --test src/routes/health.test.js
  src/utils/config.test.js src/middleware/auth.test.js`). `node --test <dir>`
  does not recurse — pass explicit files or the package script.

## Cross-references

- `mcp-ai-wpoos-schedule-anything-ops` — the Velocity deploy/mirror-sync
  mechanics shared with the other Velocity apps.
- `mcp-ai-wpoos-git-ops` — sibling-PR conflict resolution, shared-worktree
  rules, `.git/info/exclude` force-add.
- Docs: `docs/operations/deployment/mcp-gateway-velocity-setup.md`,
  `docs/operations/deployment/mcp-gateway-directory-submission.md`,
  `docs/project/proposals/061-mcp-registry-publishing-implementation-plan.md`.
