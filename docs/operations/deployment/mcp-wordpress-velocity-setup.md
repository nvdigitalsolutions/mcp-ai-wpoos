# Standalone mcp-wordpress on Cloudways Velocity — Implementation Plan

Deploy [docdyhr/mcp-wordpress](https://github.com/docdyhr/mcp-wordpress)
(MIT, Aionda GmbH) as a **remote MCP server** on **Cloudways Velocity** and
connect it to NV oOS assistants through the plugin's **Remote Sites → MCP
Server** connection type. This mirrors the media-worker Velocity pattern
(`docs/operations/deployment/media-worker-velocity-setup.md`) — same
monorepo → subtree-mirror → Velocity auto-deploy pipeline, same token-auth
and hardening discipline.

> **Status:** Plan — pending approval before scaffolding.
> **Why standalone at all:** the 30-tool native port (PR #6777) already
> covers *same-site* management. This deployment earns its keep only for
> **managing other/multiple WordPress sites** from NV oOS assistants (up to
> 50 sites upstream), or for exposing the site to external MCP clients.

---

## 1. Research summary — industry standards applied

| Decision | Standard / source | Choice |
|---|---|---|
| Transport | MCP spec 2025-03-26 revision made **Streamable HTTP** the endorsed remote transport; HTTP+SSE is deprecated for new servers; stdio is local-only | **Streamable HTTP** on `/mcp`, stateless |
| Protocol revision | The plugin's MCP client targets **2026-07-28** (`WP_MCP_AI_STDIO_Transport::PROTOCOL_VERSION`); the SDK negotiates per client | SDK negotiates; pinned SDK 1.30.1 currently negotiates **2025-11-25** — plugin client must accept the negotiated version |
| Bridging | mcp-wordpress ships **stdio-only** (`StdioServerTransport`); the plugin's Remote Sites MCP connection speaks **HTTP** (`test_mcp_server_connection()` performs a real JSON-RPC handshake) | **Native Streamable HTTP server** built on mcp-wordpress internals (pinned deep imports + SDK `StreamableHTTPServerTransport`) — single process, no stdio, no gateway binary |
| Auth | MCP auth guidance: API keys for single-tenant service-to-service, OAuth 2.1 for multi-user, mTLS as extra layer | **Static token in a custom header** — matches the plugin's `custom_header` auth type (`mcp_header_name` + encrypted `token`); OAuth deferred (single tenant) |
| Least privilege | OWASP LLM Top 10 — **LLM06 Excessive Agency**: scope tools via the least-capable credential, gate per assistant | Dedicated WP user, **Editor role by default**; admin-scoped App Password only if user/settings tools are needed |
| Egress/SSRF | Server only calls the configured site's REST API; upstream ships an SSRF denylist (v3.3.21 changelog) | Keep enabled; no private-range exceptions |
| Secrets | Never in git; platform env vars; rotate on a schedule | Velocity env vars + two-step rotation (media-worker §3 pattern) |
| Observability | 2026-07-28 revision removed MCP logging — use stderr/health endpoints + platform logs | `/healthz` (auth-free, minimal) + Velocity logs + WP-side audit/usage tracker |

---

## 2. Architecture

```
┌─ Cloudways Velocity (single Node process) ──────────────────────────────┐
│  gateway app:                                                            │
│    token auth (X-MCP-Token, timing-safe) → native MCP server             │
│    (upstream McpServer + 71 tools, policy-filtered at registration,     │
│     SDK StreamableHTTPServerTransport, stateless)                       │
│  /healthz (no auth, minimal)      /mcp (401 without token)              │
└──────────────────────────────▲──────────────────────────────────────────┘
                               │ HTTPS + X-MCP-Token
┌─ WordPress (any Cloudways app / external site) ──┐
│  NV oOS plugin: Remote Sites → MCP Server        │
│    url: https://<app>/mcp, auth_type:            │
│    custom_header, X-MCP-Token: <secret>          │
│  → Test Connection (JSON-RPC initialize)         │
│  → Discover Tools → assistant connection_ref     │
└──────────────────────────────────────────────────┘
        │ Application Password (least privilege)
        ▼
  target WordPress site(s) — wp/v2 REST API
```

**Alternatives considered (not chosen):**

1. **Fork mcp-wordpress to speak Streamable HTTP from its own entry point** —
   equivalent outcome to the deep-import approach but a maintained fork;
   the pinned deep imports achieve it without forking (version lock +
   `check:pins` CI gate make the internal imports safe).
2. **supergateway / mcp-remote as a stdio→HTTP bridge** — extra child
   process and session semantics to operate on Velocity; rejected once the
   SDK's native transport proved sufficient.
3. **OAuth 2.1 (MCP Authorization)** — only if multiple human users /
   third parties need access; out of scope for a single-tenant bridge.

---

## 3. Gateway application (new monorepo addon — SCAFFOLDED)

New subtree-mirrored addon, `addons/mcp-wordpress-gateway/`, mirroring the
media-worker split (monorepo source of truth → standalone mirror →
Velocity auto-deploy). Implemented modules:

| File | Role |
|---|---|
| `src/index.js` | Boot: config (fail-closed) → build MCP server → listen |
| `src/config.js` | Env parsing, token validation (≥32 chars), timing-safe authorize (incl. rotation window), allow/deny parsing |
| `src/tool-policy.js` | Wildcard allow/deny matching (deny wins), pure + unit-tested |
| `src/gateway-server.js` | Builds upstream `McpServer` from pinned deep imports, wraps `ToolRegistry.registerTool` so the policy filters at **registration**, serves stateless `StreamableHTTPServerTransport` requests |
| `src/app.js` | Express surface: `/healthz` (auth-free, minimal), `/mcp` token gate + 1 MB body cap + passthrough |
| `tests/` | `node --test` unit + HTTP-surface suites (22 tests) + `smoke.mjs` full-chain smoke |

Dependencies (all exact-pinned, CI-gated by `check:pins`):
`mcp-wordpress@3.3.36`, `@modelcontextprotocol/sdk@1.30.1`, `express@4.21.2`.

Notes:

- Tool policy applies at registration, so filtered tools are absent from
  `tools/list` **and** rejected on `tools/call` — no HTTP response
  rewriting needed. Policy changes require a gateway restart.
- `TRUST_PROXY=1` (NGINX in front, same as the media worker) if we add
  rate limiting by IP; `express-rate-limit` is the planned follow-up.
- Token check is **timing-safe** and fails closed; `MCP_GATEWAY_TOKEN_PREVIOUS`
  accepted during rotation (two-step, no downtime — media-worker §3 pattern).
- Verified locally: 22 unit/HTTP tests green; smoke test negotiates
  protocol version `2025-11-25` with the pinned SDK, returns 7 tools under
  `allow=wp_list_*`, and rejects a denied `tools/call`.

### `.env.example`

```dotenv
MCP_GATEWAY_TOKEN=<32+ random chars>
# Optional rotation overlap:
MCP_GATEWAY_TOKEN_PREVIOUS=
# Tool policy (comma-separated, trailing * wildcard, deny wins):
MCP_TOOLS_ALLOW=*
MCP_TOOLS_DENY=
# Inherited by mcp-wordpress via env passthrough:
WORDPRESS_SITE_URL=https://target-site.example.com
WORDPRESS_USERNAME=mcp-bridge
WORDPRESS_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
WORDPRESS_AUTH_METHOD=app-password
```

Multi-site later: switch to mcp-wordpress's multi-site config
(`mcp-wordpress.config.json`, up to 50 sites) — one gateway serves many
sites; keep one App Password per site.

---

## 4. WordPress-side credentials (least privilege)

1. Create a dedicated user on **each** target site — `mcp-bridge` — role
   **Editor** (posts/pages/media/comments/taxonomies). Promote to a
   custom-scoped admin **only** if `wp_create_user`/`wp_update_settings`/
   application-password tools are required; consider a second, separately
   gated gateway for that tier instead.
2. Generate **one Application Password per consumer** (`Users → Profile →
   Application Passwords`, e.g. "NV oOS gateway").
3. Rotate on the same cadence as the gateway token (§6); revoke immediately
   on any credential leak.

---

## 5. Provision the Velocity application

Mirror `media-worker-velocity-setup.md` §1:

1. **Repo:** standalone mirror `nvdigitalsolutions/mcp-ai-wpoos-mcp-wordpress`
   (subtree sync from `addons/mcp-wordpress-gateway/`), branch `main`.
2. **Build settings:** Node **22**, npm, root directory = repo root,
   entry = `src/index.js` (or `npm start`).
3. **Environment variables:**

   | Variable | Required | Value |
   |---|---|---|
   | `MCP_GATEWAY_TOKEN` | **yes** | ≥32 random chars (media-worker §3 generator) |
   | `NODE_ENV` | yes | `production` |
   | `TRUST_PROXY` | yes | `1` |
   | `WORDPRESS_SITE_URL` / `WORDPRESS_USERNAME` / `WORDPRESS_APP_PASSWORD` | yes | target site + bridge user |
   | `WORDPRESS_AUTH_METHOD` | yes | `app-password` |

4. Deploy; verify:

   ```bash
   curl https://<app-url>/healthz                       # → {"status":"ok",...}  (no auth)
   curl -H "X-MCP-Token: <token>" https://<app-url>/mcp # → 405/JSON-RPC handshake surface
   curl https://<app-url>/mcp                          # → 401 unauthorized
   ```

---

## 6. Security hardening

- **Auth:** token ≥32 chars, timing-safe, `_PREVIOUS` rotation window;
  plugin stores its copy encrypted (`save_connection()` encrypts tokens).
- **Transport:** HTTPS only (Velocity provides TLS); `mcp_verify_ssl:
  true` in the connection.
- **Network:** Cloudways firewall / Cloudflare rule to allowlist the WP
  server's egress IP on `/mcp`; `healthz` may stay public (minimal data).
- **Rate limiting & body caps** in the proxy (see §3); the plugin-side
  concurrency guard + audit logger provide the WP-side control.
- **Logging:** no secrets/tokens in logs; structured JSON lines; watch for
  `[Auth]` rejections. Velocity manages backups — the gateway is stateless.
- **Tool scoping:** in the plugin, after Discover Tools, expose only the
  tools each assistant needs (per-assistant gating), matching LLM06.
- **Host allowlist:** add the Velocity app host in Security Center before
  Test Connection (403s otherwise — same as Elementor MCP apps).

---

## 7. Connect the plugin

Remote Sites → **Add Connection → MCP Server**:

| Field | Value |
|---|---|
| URL | `https://<velocity-app-url>/mcp` |
| Auth type | `custom_header` |
| Header name | `X-MCP-Token` |
| Token | same `MCP_GATEWAY_TOKEN` (stored encrypted) |
| Verify SSL | on |
| Timeout | 30 s |

Then: **Test Connection** (real JSON-RPC initialize + tools/list) →
**Discover Tools** → attach `connection_ref` on the assistant → `wp_*`
tools appear bridged in chat. Optional Security Center allowlist entry if
not already present.

---

## 8. Monitoring & operations

- Uptime alert on `GET /healthz`.
- Alert on 401/429 spikes and 502 `gateway_unavailable` (the app is a
  single Node process under Velocity's PM2 — a crash restarts it).
- WP-side: assistant audit log + usage tracker record every bridged tool
  execution (canonical envelope observability applies to bridged tools
  too — verify during smoke testing).
- **Smoke test (before rollout):** one read-only call per cluster
  (`wp_list_posts`, `wp_list_users`, `wp_get_site_settings`), then one
  reversible write (`wp_create_post` draft → `wp_delete_post`) from an NV
  oOS test assistant.

---

## 9. Deployment flow & CI

```
monorepo PR (addons/mcp-wordpress-gateway/**)
  → merge to alpha-working/main
  → sync workflow (git subtree split, ~20 min)
  → force-push main on mcp-ai-wpoos-mcp-wordpress
  → Velocity auto-deploy
  → CI in standalone repo (node --test, npm audit, dependency pin check)
```

Same discipline as the media worker: never commit to the standalone repo
directly; dependency bumps via the monorepo lockfile.

---

## 10. Rollout checklist

- [ ] PR approves plan; `addons/mcp-wordpress-gateway/` scaffolded with
      `src/`, `tests/`, `package.json` + lockfile, `.env.example`, `README.md`
- [ ] Standalone mirror repo created; sync workflow added
- [ ] Velocity app provisioned; env set (token ≥32 chars; exact
      `mcp-wordpress` version pinned)
- [ ] `/healthz` public check green; `/mcp` returns 401 without token
- [ ] Bridge user + Application Password created on the target site
- [ ] Plugin: Security Center allowlist + Remote Sites MCP Server
      connection; Test Connection green; tools discovered
- [ ] Assistant smoke test (read-only + reversible write) passes
- [ ] Monitoring/uptime alerts live; token rotated after first 24 h

## 11. Open items for Cloudways support

1. Confirm the plugin's MCP App client accepts **sessionless** Streamable
   HTTP (no `mcp-session-id` after initialize) and the negotiated protocol
   version (`2025-11-25` with the pinned SDK) — the local smoke test
   proves the server side; the first live **Test Connection** proves the
   client side.
2. Confirm outbound HTTPS from the Velocity app to arbitrary target sites
   is not firewalled (media worker does outbound provider calls, so
   presumably fine — verify against the first real target site).
