# Proposal 101 — NV oOS Gateway ChatGPT Plugin: Implementation Plan

**Status:** 🚧 IN PROGRESS — Phase A shipped ✅ (gateway 0.3.0: challenge route + version alignment, 39/39 tests green, 2026-11-02); Phases B–D scaffolded ✅ (package + metadata + sync workflow, `bin/validate.mjs` green); Phase E (portal loop) pending. Namespacing note: PR #7375 changed the tool-name separator to `_` — all references below use `demo_*`.
**Related:** [053-chatgpt-plugin-addon-implementation-plan.md](./053-chatgpt-plugin-addon-implementation-plan.md) · [099-chatgpt-oauth-mcp-connector-proposal.md](./099-chatgpt-oauth-mcp-connector-proposal.md) · gateway ops skill (`.agents/skills/mcp-ai-wpoos-gateway-ops/`) · tracking issue #6902
**Scope:** new addon `addons/chatgpt-gateway-plugin/**` (the ChatGPT plugin package for the public directory), one small addition to `addons/mcp-gateway/` (domain-verification challenge route), mirror + sync plumbing, and the OpenAI submission loop.

---

## 1. Summary

Publish a second ChatGPT plugin, **NV oOS Gateway** (package name
`nvoos-gateway`), whose MCP server is the fleet gateway at
**`https://mcp.nvoos.pro/mcp`** — the stable, OAuth-proven public surface.
This plugin is the *public directory* entry; the existing
`nvoos-site-bridge` package remains the *per-site* product distributed via
marketplace/workspace (see the two-plugin decision in §2). The gateway
plugin lets any ChatGPT or Codex user explore the public NV oOS demo site
(`demo` → nvoos.pro) through namespaced `demo_*` tools, linked with OAuth
consent.

## 2. The two-plugin decision (locked 2026-11-02)

| | `nvoos-site-bridge` (existing) | `nvoos-gateway` (this plan) |
|---|---|---|
| Origin | `https://YOUR-SITE.DOMAIN/wp-json/mcp-ai/v1/mcp` (stamped per install) | `https://mcp.nvoos.pro/mcp` (fixed, origin-locked) |
| Identity model | Per-user site account via OAuth; native profile tool | OAuth consent → gateway-scoped `demo` access (operator-scoped) |
| Distribution | Marketplace / git-backed / ChatGPT workspace | **Public Plugin Directory submission** |
| Tool surface | Full site registry per linked account | Namespaced `demo_*` tools as exposed by the gateway |

OpenAI's rules force this split (one MCP server per plugin; origin changes =
new plugin). The gateway is the right origin for the *directory* because it
is purpose-built, stable, and its OAuth path is already proven live with
ChatGPT's connector (owner-verified connect 2026-11-01).

## 3. Evidence base (verified 2026-11-02)

### 3.1 Live OpenAI docs (fetched, captured in research doc §7)

- Submission flow: ZIP → metadata checks → connect **one** MCP server +
  domain verification → Scan Tools → review details → submit → approve →
  publish. Metadata/skills changes need a new ZIP; hosted tool changes only
  a rescan.
- Origin lock: scheme/host/port can never change between versions — a new
  origin means a new plugin. The gateway origin is final on first upload.
- ZIP hygiene: no `test_credentials`/`reviewer_instructions` in the package,
  no lifecycle hooks, no nonempty server `headers`, no API-key auth (the
  portal supports OAuth `provided`/`dcr`/`cimd` or no auth only).
- Field limits: `displayName` ≤30, `shortDescription` ≤30, `longDescription`
  ≤4000, `developerName` ≤80, ≤20 capabilities ≤120 chars, ≤3 starter
  prompts ≤128 chars, all four listing URLs required, icons square ≥48px
  ≤5 MiB, exactly 5 positive (with `tools_triggered` + `expected_behavior`)
  + 3 negative review cases, `demo_recording_url` required.
- Domain verification: exact token as plain text at
  `https://<host>/.well-known/openai-apps-challenge`.
- Common rejection reasons: unreachable server with reviewer credentials
  (no MFA, works from outside private networks), test-case mismatches,
  undisclosed user-related data (strip session/trace/request IDs,
  timestamps, internal IDs, auth secrets), annotation/hint mismatches.
- Publisher needs verified identity + `api.apps.write`/`api.apps.read`;
  EU-data-residency projects cannot submit MCP plugins.

### 3.2 Auth0 tenant `nvoos.us.auth0.com` (live RFC 8414 probe)

- `authorization_response_iss_parameter_supported: true` → RFC 9207 met →
  ChatGPT uses the **stable redirect**
  `https://chatgpt.com/connector_platform_oauth_redirect` and stable CIMD
  URL. The exact redirect to allowlist comes from the portal's connect
  panel.
- `code_challenge_methods_supported: ["S256","plain"]` ✅
- `registration_endpoint: https://nvoos.us.auth0.com/oidc/register` ✅ —
  **DCR is the registration path** (the tenant does **not** advertise
  `client_id_metadata_document_supported`, so ChatGPT will not use CIMD
  here; DCR is exactly what the live connector walkthrough used).
- Advertised scopes are the OIDC set (`openid profile email …`) — the known
  connector quirk (ChatGPT requests all AS scopes; the post-login
  `addScope` Action already handles the exact-match requirement).

### 3.3 Gateway OAuth resolution model (code-verified, `src/middleware/auth.js`)

- JWT bearer tokens are validated (signature/issuer/audience/expiry) and
  site bindings derive from `site:<slug>` scopes (`sitesFromScopes`).
- **Tokens without `site:<slug>` scopes fall back to
  `GATEWAY_OAUTH_DEFAULT_SITES`** (live value: `demo`) — so even if the
  portal requests only Auth0's OIDC scopes, the linked plugin lands on the
  `demo` site (PR #7373 behavior).
- OAuth identity is `oauth:<sub>`; the policy engine (`GATEWAY_POLICY`)
  matches rules on `tool`/`site`/`key`/`time`/`geo` — the demo *key's*
  allowlist (`get_environment_status`, `deep_research`) does **not**
  automatically apply to OAuth subjects. The scan surface for OAuth links
  must be measured in the portal (§5 step 3) and curated with a policy rule
  if it is too wide.
- `tools/list` fans out per site with `<slug>_` prefixes, degrades
  gracefully (`_meta.gateway.errors`), negotiates protocol 2024-11-05 →
  2026-07-28.

### 3.4 Gap found in the gateway for this submission

- **No `/.well-known/openai-apps-challenge` route** exists on the gateway
  (grep-verified). The portal requires the exact challenge token served as
  plain text at that path on the MCP origin. New gateway work, §5 phase A.

## 4. Industry standards & best practices distilled (web, 2026-11-02)

1. **Directory context:** the Plugin Directory (chatgpt.com/plugins,
   relaunched 2026-07-09) is the universal directory shared by ChatGPT and
   Codex; listing text, icons, and review cases are the storefront — copy
   must be unambiguous and self-contained (a real-world rejection report
   showed ambiguous single-turn tool behavior failing review).
2. **Test cases are run by humans and bots on several surfaces** — each
   positive case must pass identically on web and mobile phrasing variants;
   tools must not depend on interactive clarification mid-turn.
3. **Annotations are a contract, not a hint** — `readOnlyHint`/`openWorldHint`/
   `destructiveHint` values are scanned and compared against tool behavior;
   a mismatch is a rejection reason. `get_environment_status` (read-only,
   bounded) and `deep_research` (read-only, **open-world** — it hits the
   public web) are the two tools to annotate carefully.
4. **OAuth over API keys for the directory** — the portal has no API-key
   connection flow; use the DCR path against the existing Auth0 tenant,
   with the RFC 9207 stable redirect allowlisted in Auth0.
5. **Scope the review surface** — reviewers scan exactly what the endpoint
   exposes; keep the OAuth-linked tool surface small and well-described
   (policy rule for `oauth:*` identities if needed).
6. **Portfolio consistency** — two plugins from one publisher should share
   branding (reuse the 512×512 logo, the same publisher identity, and
   cross-link privacy/terms), while keeping distinct `displayName`s and
   value props so users can tell "gateway demo access" from "per-site
   bridge".

## 5. Phases

### Phase A — Gateway: domain-verification challenge route (gateway repo)

| # | Task | Files | Notes |
|---|---|---|---|
| A.1 | Add `GET /.well-known/openai-apps-challenge` serving the exact token from env `GATEWAY_OPENAI_CHALLENGE_TOKEN` as `text/plain` (no JSON, no trailing newline); 404 while unset; inert without OAuth config | `addons/mcp-gateway/src/routes/oauth.js` (+ tests) | Mirrors the site-side contract (`wp_mcp_ai_openai_apps_challenge_token` option) |
| A.2 | Tests: 404 unset, exact-token body/content-type when set, no caching headers | `src/oauth/oauth.test.js` or new `challenge.test.js` | `node --test` targeted files (npm test hangs on Windows) |
| A.3 | Bump version + `VERSION` constant lockstep, deploy to Velocity, verify `/health.version` + live route 404 | mirror → auto-deploy ladder (gateway ops skill) | Challenge token is set at submission time only |

### Phase B — Plugin package scaffold (new addon)

| # | Task | Files | Notes |
|---|---|---|---|
| B.1 | Scaffold `addons/chatgpt-gateway-plugin/` from the `chatgpt-plugin` pattern: dual manifests (`plugin.json` + `.codex-plugin/plugin.json`), `mcp.json`/`.mcp.json` pointing at `https://mcp.nvoos.pro/mcp` (**real URL** — this package is gateway-specific, no stamping), skills, `bin/validate.mjs` + `package-plugin.mjs`, CI, LICENSE, CHANGELOG | `addons/chatgpt-gateway-plugin/**` | Keep the real URL in `mcp.json` (mirror ships a usable package); validate.mjs's stamped-URL guard must allow this package's canonical URL |
| B.2 | Skills (3, namespaced): `gateway-site-operations`, `content-studio`, `commerce-desk` — instructions reference `demo_*` tool names (`demo_get_environment_status`, `demo_deep_research`, …) and the OAuth consent step | `skills/*/SKILL.md` | Skill frontmatter `name`+`description` required |
| B.3 | Assets: reuse the 512×512 brand PNG (`logo.png` + `icon.png`) | `assets/` | Same `.git/info/exclude` force-add caveat |
| B.4 | `bin/validate.mjs` green; packaging smoke | — | Gate for every later commit |

### Phase C — Sync pipeline + distribution (mirror)

| # | Task | Notes |
|---|---|---|
| C.1 | Create mirror repo `nvdigitalsolutions/nvoos-chatgpt-gateway-plugin` (public, GPL-3.0) + `CHATGPT_GATEWAY_PLUGIN_REPO_TOKEN` PAT (repo-scoped `contents:write`) | **GitHub admin, manual** (same blocker as 053 §1.1) |
| C.2 | Sync workflow `sync-chatgpt-gateway-plugin.yml` (subtree split → force-push mirror `main`, path-filtered, secret-presence guard) | Copy the 053 §5 spec with renamed secret |
| C.3 | GitHub Pages on the mirror (`main` + `/docs`): landing, `privacy.html`, `terms.html` (gateway-specific copy: gateway-scoped access, no per-user site account claim, demo data) | Manual Pages enable + sync |
| C.4 | E2E smoke (Tier 1): local key → `tools/list` → `demo.get_environment_status` → record results in the addon README | Demo key from the operator, never in the repo |

### Phase D — Submission metadata (package)

| # | Task | Notes |
|---|---|---|
| D.1 | `plugin.json` `extensions.com.openai` per the live field reference: `displayName` "NV oOS Gateway", `shortDescription` ≤30, `longDescription` (gateway demo access, OAuth consent, `demo.*` tools, limitations), `category` (dashboard list — verify), `capabilities`, four listing URLs (mirror Pages for privacy/terms, mirror repo issues for support), ≤3 starter prompts, `onboardingSkill`, `review` (5+3 cases, §6), `commerce: false`, `publication.release_notes` | Version 0.1.0; keep `.codex-plugin` lockstep |
| D.2 | `demo_recording_url` — recorded walkthrough (publisher; not repo-content) | Required for review, entered via package or dashboard |
| D.3 | `publication.countries` — explicit allowlist decision (or omit = default) | Publisher decision |

### Phase E — Portal submission loop (manual, owner-led)

| # | Step | Notes |
|---|---|---|
| E.1 | Org verification (individual/business) + `api.apps.write`/`api.apps.read`; global-residency project | OpenAI dashboard |
| E.2 | Upload ZIP → fix metadata findings | "Copy issues" → fix in package → re-upload |
| E.3 | Connect MCP: URL `https://mcp.nvoos.pro/mcp`, **OAuth → DCR** (registration endpoint from Auth0 discovery), scopes: request `site:demo` if the panel allows; otherwise rely on `GATEWAY_OAUTH_DEFAULT_SITES=demo`; allowlist the portal's redirect URI in Auth0 | The exact redirect comes from the panel; Auth0 app allowlist |
| E.4 | Domain verification: set `GATEWAY_OPENAI_CHALLENGE_TOKEN` in the Velocity env, verify the route, complete the challenge | Phase A route |
| E.5 | **Scan Tools** → record the exact `demo.*` tool list → reconcile test-case `tools_triggered` → verify annotations (`readOnlyHint` true for both demo tools; `openWorldHint` **true** for `deep_research`, false for `get_environment_status`) → add a policy rule narrowing `oauth:*` to the intended tools if the scan surface is too wide → rescan | The measured list is authoritative for the review block |
| E.6 | Review details: reviewer credentials = a dedicated **Auth0 test user** (no MFA, sample data) — no site account needed; enter in the dashboard only | Publisher creates the test user |
| E.7 | Submit → triage feedback → publish | One review at a time; appeals by replying to the email |

## 6. Test-case drafts (grounded in the verified demo surface; reconciled at E.5)

Positive (expected namespaced tools; `tools_triggered` strings final after Scan Tools):

1. **Environment status** — "What is the status of the NV oOS demo site?" → `demo_get_environment_status` → structured report, no secrets.
2. **Research brief** — "Research the current state of WordPress AI agents and summarize for a site operator." → `demo_deep_research` (note: open-world, long-running; use the documented background-mode guidance) → cited summary.
3. **Profile of the gateway** — "What can I do through this gateway?" → `demo_get_environment_status` (+ skills' guidance) → capabilities explained from the linked surface only.
4. **Degradation honesty** — "What tools are available?" → tools listing → namespaced `demo_*` tools only, no internal gateway internals.
5. **Consent-aware refusal** — "Connect me to a site I don't have access to." → no tool; explanation that this plugin only exposes the public demo site.

Negative:

1. "Delete all posts on the demo site." → no destructive tool; refusal.
2. "Show me another user's private data." → refusal; no PII tool.
3. "Transfer money / make a purchase." → refusal; `commerce: false` is declared.

(Exact tool names in positive cases must match the E.5 scan; the drafts above
are placeholders for that reconciliation, not final copy.)

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Scan surface too wide for OAuth links (no key allowlist for `oauth:*`) | E.5 measurement; `GATEWAY_POLICY` rule scoping `oauth:*` subjects to the intended tools; re-scan before submit |
| `demo_nvoos_get_profile` passes through as a designated profile tool reporting the **operator's** identity | Do not reference it in skills/test cases; if the scan marks it `_meta["openai/profile"]`, strip/policy-deny it at the gateway or re-check pass-through semantics (targeted fix in `routes/mcp.js` if needed) |
| Scope mismatch (`OAUTH_SCOPES_MISMATCH` quirk) on the portal flow | Post-login `addScope` Action already live; E.3 requests `site:demo` explicitly when possible; fallback path is `DEFAULT_SITES` |
| Origin lock regret | Accepted: the gateway is the permanent public origin by design; upstream sites can change freely |
| Stale gateway deploy (challenge route missing) | Deploy-verification ladder (version marker + `/health` + restart clock) before E.4 |
| Overlap with the live custom connector | Fine — directory listing is discoverability; the connector stays for power users |

## 8. Validation gates

1. `node addons/chatgpt-gateway-plugin/bin/validate.mjs` on every package change.
2. Gateway: `node --test src/oauth/oauth.test.js src/oauth/challenge.test.js src/routes/health.test.js` (targeted; `npm test` hangs on Windows).
3. Live: `/health.version` = bumped version; `/.well-known/openai-apps-challenge` 404-until-set then exact-token; OAuth metadata unchanged; one `tools/call demo_get_environment_status` through a test key.
4. Portal: metadata checks clean; Scan Tools list recorded in the PR/docs; review cases re-run on web + mobile phrasing.

## 9. Dependencies & decisions

- [x] Go-ahead for the new addon + name `nvoos-gateway` (approved: "NV oOS Gateway")
- [ ] Mirror repo + PAT secret (GitHub admin) — C.1
- [ ] GitHub Pages enable on the mirror — C.3
- [x] Gateway challenge route + version alignment (Phase A) — code shipped; Velocity deploy + env token pending
- [ ] Auth0: allowlist portal redirect URI; dedicated test user — E.3/E.6
- [ ] `publication.countries` allowlist decision — D.3
- [ ] `demo_recording_url` recording — D.2
- [ ] Decide policy scoping for `oauth:*` after E.5 measurement

## 10. Effort estimate

| Phase | Effort | Blocker |
|---|---|---|
| A — gateway challenge route + deploy | ✅ shipped 2026-11-02 (0.3.0, 39/39 tests) | — |
| B — package scaffold | ✅ scaffolded 2026-11-02 (validate.mjs green) | — |
| C — mirror/sync/Pages | workflow shipped; mirror repo + PAT + Pages enable pending | GitHub admin |
| D — metadata | drafted in the scaffold (0.1.0) | URLs live + recording |
| E — portal loop | pending | verified identity, reviewer account |

## 11. Rollback

- Package: revert commits in the monorepo; the mirror is force-pushed on the
  next sync. Unpublishing is a dashboard action; the gateway's OAuth surface
  stays live for the existing connector regardless.
- Challenge route: unset `GATEWAY_OPENAI_CHALLENGE_TOKEN` → 404 (inert).
- Policy rule: removing the rule restores the previous surface; no code
  deploy needed (env-configured).
