# Proposal 053 — ChatGPT Plugin Addon: Implementation Plan

**Status:** ⏳ ACTIVE — Phase 0 shipped ✅; Phase 1 in progress (sync workflow ✅, docs/inventory ✅, mirror repo + PAT secret blocked on GitHub admin credentials); Phase 2 (base+pro OAuth resource-server contract) implemented and green on `addon/chatgpt-plugin` branch, pending review; Phase 3 (public submission) not started
**Proposal:** [053-chatgpt-plugin-addon-proposal.md](./053-chatgpt-plugin-addon-proposal.md) · **Research:** [053-chatgpt-plugin-addon-research.md](./053-chatgpt-plugin-addon-research.md)
**Scope:** `addons/chatgpt-plugin/**` (the package + its sync pipeline) and additive base+pro changes for the OAuth resource-server contract (Tier 3)

---

## 0. Execution rules

- One commit per logical unit; imperative subjects ≤50 chars.
- **Addon folder** (`addons/chatgpt-plugin/`): JSON manifests, skills, and
  `bin/*.mjs` stay dependency-free (Node built-ins only). Every change passes
  `node bin/validate.mjs` before merge; the mirror CI runs the same gate.
- **base+pro PHP changes:** PHP 7.4-compatible (base), WPCS, every touched
  file passes `php -l`; new/changed REST behavior gets PHPUnit coverage in
  `tests/rest/` or `tests/security/`.
- **Version lockstep:** any `version` bump touches `plugin.json` AND
  `.codex-plugin/plugin.json` plus `CHANGELOG.md` in the same commit —
  enforced by `bin/validate.mjs`.
- **Mirror discipline:** never commit to `nvdigitalsolutions/nvoos-chatgpt-plugin`
  directly; all changes flow through the monorepo and the sync workflow.
- **Secrets:** no credential ever enters the addon folder or its ZIPs
  (stamped URLs fail `bin/validate.mjs`; `dist/` and `.env*` are gitignored).

---

## 1. Phase 0 — Package scaffold (shipped)

| # | Task | Files | Notes |
|---|---|---|---|
| 0.1 | Portable + legacy manifests, MCP configs (placeholder URL), skills, marketplace, `bin/` scripts, in-addon CI, LICENSE, CHANGELOG, README | `addons/chatgpt-plugin/**` | ✅ Done on this branch. `node bin/validate.mjs` green; ZIP packaging verified (single top-level folder, manifest inside). |

**Definition of done:** `bin/validate.mjs` passes locally; all five JSON files parse; mirror-ready (self-contained, license, CI, changelog).

---

## 2. Phase 1 — Sync pipeline + Tier 1–2 distribution (addon-only)

| # | Task | Files | Notes |
|---|---|---|---|
| 1.1 | **Create the mirror repo** `nvdigitalsolutions/nvoos-chatgpt-plugin` (public, GPL-3.0, no auto-init commits — the first sync force-pushes `main`) and add the `CHATGPT_PLUGIN_REPO_TOKEN` PAT secret (repo-scoped, `contents:write`) to the monorepo settings | GitHub admin | ⏳ Blocked — the GitHub API credentials in this session rejected the create call; remains a manual admin step. Blocker for 1.2 activation (guard skips until then). |
| 1.2 | **Add the sync workflow** — subtree split of `addons/chatgpt-plugin` → force-push mirror `main`, path-filtered on `main`/`alpha-working`, with a secret-presence guard so a merge **before** the secret exists logs a warning instead of red CI (spec in §5) | NEW: `.github/workflows/sync-chatgpt-plugin.yml` | ✅ Created on this branch; inert until merge. |
| 1.3 | **Verify the first sync + mirror CI** — merge 1.2, confirm the mirror `main` contains the folder contents and `.github/workflows/ci.yml` runs `bin/validate.mjs` + packaging smoke green | Mirror repo | ⏳ Pending merge + 1.1. |
| 1.4 | **E2E smoke (Tier 1)** — against a staging site: stamp → `NVOOS_MCP_TOKEN=cred_xxx.SECRET` → `tools/list` over `/wp-json/mcp-ai/v1/mcp` → one read tool → one draft-creating write tool; record the results in the addon README | `addons/chatgpt-plugin/README.md` (doc) | ⏳ Pending a staging site with a published assistant. |
| 1.5 | **Marketplace installs** — local: `codex plugin marketplace add ./addons/chatgpt-plugin`; Git-backed: `codex plugin marketplace add nvdigitalsolutions/nvoos-chatgpt-plugin` after 1.3 | `addons/chatgpt-plugin/marketplace.json` (verify) | ⏳ Pending 1.3. |
| 1.6 | **Docs corrections (Tier 1–2)** — replace the client-credentials ChatGPT guidance with the three-tier matrix + `bearer_token_env_var` instructions | EDIT: `docs/getting-started/installation-setup/remote-client-setup.md`, `docs/reference/api/mcp-server-authentication.md` | ✅ Done on this branch. |
| 1.7 | **Inventory + release docs** — add `chatgpt-plugin` to the addon inventory with sync details | EDIT: `docs/project/ADDON_INVENTORY.md` | ✅ Done (entry #30). `REPOSITORY_ORGANIZATION.md` does not track mirrors — no change needed. |

**Definition of done:** mirror CI green on the first sync; `codex plugin marketplace add nvdigitalsolutions/nvoos-chatgpt-plugin` installs the plugin; smoke test passes on a staging site; zero remaining references to client-credentials ChatGPT connectors in the two docs.

---

## 3. Phase 2 — base+pro OAuth resource-server contract (Tier 3 enabler)

> ✅ **Implemented on this branch** (code + PHPUnit green; see §8 for the runs).
> Architecture note discovered during implementation: the **Pro addon already
> declares `WP_MCP_AI_OAuth_Server`** (its own OAuth authorization server +
> metadata for toolkit MCP servers). To avoid a fatal redeclaration, the base
> Auth0-backed contract ships as **`WP_MCP_AI_OAuth_Resource_Server`** in
> `includes/mcp/`; the REST 401 path prefers the Auth0 contract when
> configured and falls back to Pro's existing challenge otherwise (Pro sites
> unchanged).

All changes are **base** (no Pro dependency) so any NV oOS site can expose the
contract; the addon's `mcp.json` stays unchanged.

| # | Task | Files | Notes |
|---|---|---|---|
| 2.1 | **RFC 9728 protected-resource metadata** — serve `GET /.well-known/oauth-protected-resource` returning `resource` (site origin), `authorization_servers` (configured Auth0 issuer when present; empty list otherwise), `scopes_supported`, `resource_documentation`. Implement as a rewrite rule + `template_redirect` handler + `redirect_canonical` guard, mirroring the Pro `/.well-known/mcp` class; expose a REST fallback route for hosts without rewrites | NEW: `includes/mcp/class-wp-mcp-ai-well-known-oauth-protected-resource.php`; EDIT: `includes/bootstrap/loader.php` | ✅ (rewrite-rule transport only; no REST fallback route — the origin-level path is the RFC-canonical URL ChatGPT fetches). |
| 2.2 | **`WWW-Authenticate` challenges** — when `/wp-json/mcp-ai/v1/mcp` rejects an unauthenticated request (no valid token), add `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource", scope="…"`; on tool-call auth failures emit `_meta["mcp/www_authenticate"]` with `error` + `error_description` so ChatGPT surfaces its linking UI | EDIT: `includes/class-wp-mcp-ai-rest.php`, `includes/class-wp-mcp-ai-rest-mcp-methods.php` | ✅ (Auth0 contract preferred; Pro fallback preserved). |
| 2.3 | **Per-tool `securitySchemes` in `tools/list`** — read-only base tools get `site:read`; write tools add `content:write`. Extend the existing `build_tool_annotations()` path rather than a parallel one | EDIT: `includes/class-wp-mcp-ai-rest-mcp-methods.php` (`build_tool_security_schemes()`) | ✅ (no `noauth` entries — the endpoint authenticates every call; schemes match enforced behaviour). |
| 2.4 | **Profile tool `nvoos_get_profile`** — read-only, empty args, returns opaque stable `id` (HMAC of user/site identity, never email), `name`/`email`/`nickname` display fields; declared `_meta["openai/profile"]: true` + `outputSchema` with `required: [id]`, `additionalProperties: false` | NEW: `includes/tools/class-wp-mcp-ai-tool-nvoos-get-profile.php`; EDIT: `includes/class-wp-mcp-ai-tool-registry.php` | ✅ (identity order: WP user → assistant credential → site-scoped). |
| 2.5 | **Auth0 authorization-code token validation hardening** — accept tokens whose `aud` matches the RFC 9728 resource identifier (the MCP endpoint URL) in addition to the legacy `auth0_audience` setting | EDIT: `includes/rest/class-wp-mcp-ai-rest-authenticator.php`, `includes/mcp/class-wp-mcp-ai-oauth-resource-server.php` | ✅ (backward-compatible; Auth0 tenant CIMD setup remains manual — §4/3.1). |
| 2.6 | **OpenAI domain-verification challenge** — serve `/.well-known/openai-apps-challenge` as plain-text from a settings option (exact token, no JSON), same rewrite pattern as 2.1 | NEW: `includes/mcp/class-wp-mcp-ai-well-known-openai-challenge.php` | ✅ (option `wp_mcp_ai_openai_apps_challenge_token` + filter; 404 while unset). |
| 2.7 | **Tests** — metadata document shape (RFC 9728), challenge text/plain, WWW-Authenticate emission on 401, `_meta["mcp/www_authenticate"]` on tool-call failure, securitySchemes present on read vs write tools, profile tool schema/id stability, audience mismatch rejection | NEW: `tests/rest/test-rest-mcp-oauth-resource-server.php` (11 tests), `tests/security/test-mcp-oauth-token-validation.php` (8 tests) | ✅ Green; regression runs on `test-mcp-endpoint`, `test-mcp-tools-list`, `test-rest-authenticator`, `test-rest-mcp-controller` also green. |
| 2.8 | **Docs** — full three-tier auth matrix + Auth0 CIMD setup + challenge-token field in the settings guide | EDIT: `docs/reference/api/mcp-server-authentication.md`, `docs/getting-started/installation-setup/remote-client-setup.md` | ✅ Tier 1–3 matrix + challenge notes in place; a dedicated Auth0 CIMD walkthrough remains a follow-up doc. |

**Definition of done:** `curl /.well-known/oauth-protected-resource` returns a valid RFC 9728 document on a test site with Auth0 configured; an unauthenticated `tools/call` returns the `WWW-Authenticate` challenge; `tools/list` shows `securitySchemes`; PHPUnit green for 2.7.

---

## 4. Phase 3 — Public ChatGPT plugin submission (manual + package)

| # | Task | Notes |
|---|---|---|
| 3.1 | **Auth0 tenant setup** (per OpenAI's Auth0 guide): create the API (identifier = the demo site's MCP endpoint), enable default audience, enable **Manual CIMD Registration**, import OpenAI's CIMD document (`https://chatgpt.com/oauth/client.json`), configure third-party app grants, create a test user + role with the 2.3 scopes | `authorization_response_iss_parameter_supported` and `code_challenge_methods_supported: S256` come from Auth0; verify with the MCP Inspector OAuth flow. |
| 3.2 | **Submission metadata in `plugin.json`** — add `interface.supportURL`, final privacy/terms URLs, onboarding skill pointer, `extensions.com.openai.review` (5 positive + 3 negative test cases with `tools_triggered`/`expected_behavior`, `demo_recording_url`, `commerce: false`), `publication` (countries, release notes) | Fields per the submission field reference; reviewer credentials go in the dashboard form only — never the ZIP. |
| 3.3 | **Assets** — `assets/icon.png`, `logo.png` (square, ≥48px, ≤5MiB), screenshots; brand colors | Fails metadata checks without them. |
| 3.4 | **PII & annotation audit** — run realistic tool calls in developer mode, list every user-related field returned, strip unneeded PII/telemetry or disclose it in the privacy policy; re-verify `readOnlyHint`/`destructiveHint`/`openWorldHint` match behaviour (scanned from the server) | Top rejection reasons per the review doc. |
| 3.5 | **Submission loop** — org verification (`api.apps.write`/`read`), upload ZIP, connect + domain-verify the demo site's MCP endpoint (challenge from 2.6), resolve automated findings, submit, triage review feedback | One MCP server per plugin; origin changes require a new plugin — pick the demo origin carefully. |

**Definition of done:** plugin approved and published (or an explicit decision to stay workspace-only); `bin/validate.mjs` extended to assert the required listing URLs are non-placeholder before a `release` tag.

---

## 5. Sync workflow spec (`sync-chatgpt-plugin.yml`)

```yaml
name: Sync chatgpt-plugin

on:
  push:
    branches: [ main, alpha-working ]
    paths:
      - 'addons/chatgpt-plugin/**'

jobs:
  check-secret:
    runs-on: ubuntu-latest
    outputs:
      has_token: ${{ steps.check.outputs.has_token }}
    steps:
      - id: check
        env:
          TOKEN: ${{ secrets.CHATGPT_PLUGIN_REPO_TOKEN }}
        run: |
          if [ -n "$TOKEN" ]; then
            echo "has_token=true" >> "$GITHUB_OUTPUT"
          else
            echo "has_token=false" >> "$GITHUB_OUTPUT"
            echo "::warning::CHATGPT_PLUGIN_REPO_TOKEN not set; skipping sync"
          fi

  sync:
    needs: check-secret
    if: needs.check-secret.outputs.has_token == 'true'
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4
        with:
          fetch-depth: 0

      - name: Split subtree
        run: git subtree split --prefix=addons/chatgpt-plugin --branch=chatgpt-plugin-split

      - name: Push to nvoos-chatgpt-plugin
        run: |
          git config --local --unset-all http.https://github.com/.extraheader
          git push https://x-access-token:${{ secrets.CHATGPT_PLUGIN_REPO_TOKEN }}@github.com/nvdigitalsolutions/nvoos-chatgpt-plugin.git chatgpt-plugin-split:main --force
```

Notes: the `check-secret` guard exists only because GitHub forbids `secrets.*`
in `if:` expressions; remove it once the secret is permanently in place.
The mirror repo must exist before the first activating push (created in 1.1).

---

## 6. Effort estimation

| Phase | Effort | Blocker |
|---|---|---|
| 1 — Sync + Tier 1–2 | 2–4 days | GitHub admin (1.1) |
| 2 — base+pro resource server | 5–8 days | Auth0 tenant for integration tests |
| 3 — Public submission | 2–4 days + review loop | Verified publisher identity; demo site; Auth0 (3.1) |

---

## 7. Rollback

- **Addon package:** every change is versioned content; revert commits on the
  branch or bump a new patch version. The sync is force-push — reverting in
  the monorepo and pushing again restores the mirror.
- **base+pro (Phase 2):** all endpoints are opt-in (only advertised when an
  authorization server is configured); disabling the settings restores the
  current auth behaviour. Each task lands as an independent commit.
- **Submission:** unpublishing is a dashboard action; the site-side contract
  can stay live for other OAuth clients regardless.

---

## 8. Validation gates per unit

1. `node addons/chatgpt-plugin/bin/validate.mjs` for every addon change.
2. `php -l` on every touched PHP file; `vendor/bin/phpunit` on the targeted
   test files (2.7) when the WP test harness is available; otherwise report
   the command + reason.
3. `composer run lint` on changed PHP where feasible.
4. Manual probes: `curl -i` the two `/.well-known` endpoints and the 401
   challenge; `wp mcp-ai remote` for the Tier 1 token path.

## 9. Dependencies & decisions

- [ ] Mirror repo + PAT secret (1.1) — GitHub admin
- [ ] Auth0 tenant for the demo site (3.1) — publisher
- [ ] Verified developer identity + support/privacy/terms URLs (3.2)
- [ ] Decide: public directory listing this cycle vs workspace-only (proposal decision 3)
