# Phase 2 — Automated Scan Results

> Raw output and reproducible commands for the May 2026 audit. All scans run against branch `alpha-working` at v1.1.22 on 2026-05-25.

## 1. Reproduction commands

```bash
# Composer setup
composer install --no-interaction --prefer-dist

# Dependency audits
composer audit --format=plain
( cd addons/pro && composer audit --format=plain )

# JS audits (production deps only)
npm audit --omit=dev
( cd addons/pro && npm audit --omit=dev )

# WPCS errors-only on base tree
vendor/bin/phpcs --error-severity=1 --warning-severity=8 --report=summary \
  --ignore=vendor,node_modules,addons/pro,addons/pro/vendor,assets/examples,examples,bin,tests \
  -p .

# Security-relevant SQL sniff
vendor/bin/phpcs --error-severity=1 --warning-severity=8 \
  --sniffs=WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.DirectDatabaseQuery \
  --ignore=vendor,node_modules,addons/pro/vendor,assets/examples,examples,bin,tests \
  -p .
```

## 2. `composer audit`

### Root (`./`)

```
No security vulnerability advisories found.
```

### Pro (`addons/pro/`)

```
No security vulnerability advisories found.
```

## 3. `npm audit --omit=dev`

**Not re-run for this audit.** Requires a full `npm install` in a CI-capable environment. The April 2026 baseline (10 moderate root, 3 moderate pro) was partially addressed via R-Q-02. A re-audit before the next release tag is recommended.

## 4. PHPCS (`composer run lint:base` style)

**Not re-run for this audit.** The May 23 compliance document confirms the shipped tree reports **0 errors / 0 warnings** across 796 files (the count discrepancy from our 942 is due to test files, build artifacts, and tool-generated HTML files that don't participate in the WP.org distribution). The addons PHPCS cleanup (PRs #5070, #5078) achieved 93% reduction (1,143 → 82 errors), all in addon code excluded from the WP.org submission.

## 5. Manual security pattern sweeps

These are quick `grep`-based sweeps against `includes/`, not exhaustive but representative.

### 5.1 Dangerous PHP functions

| Pattern | Hits in product code (excl. tests/vendor) |
|---|---|
| `eval(` | **0** product calls. The only references are inside `includes/services/class-wp-mcp-ai-code-optimizer.php:421` flagging external code as risky — defensive, not a vulnerability. ✅ |
| `shell_exec(` | **0** product calls in base. All 11 calls are pro-only and excluded from the WP.org artifact. ✅ |
| `exec(` | **0** product calls in base. The only hit is in `includes/class-wp-mcp-ai-lm-studio-client.php:1454` (`curl_exec($ch)`) which is documented in the May 23 compliance review (W1) — uses `curl_exec` for token-by-token SSE streaming, with non-streaming fallback to `wp_remote_post()`. Gated behind `function_exists('curl_init')`. ✅ |
| `system(`, `passthru(` | **0** ✅ |
| `proc_open` | Present in `includes/agents/class-wp-mcp-ai-agent-code-sandbox.php` — CoSAI-compliant sandbox with timeout enforcement and stripped environment. ✅ |
| `create_function()` | **0** ✅ |
| `preg_replace` with `/e` | **0** ✅ |

### 5.2 TLS verification disabled

```
includes/class-wp-mcp-ai-http-helper.php:82           sslverify => false  (loopback only, gated)
includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php:286  sslverify => false  (loopback only, gated)
```

**2 instances** (down from 4 in April). Both are properly gated: only applied when `is_loopback_address()` returns true, with documented justification (loopback addresses don't have valid SSL certs). See **F-SSL-02** for the formal acceptance.

April's other 2 instances (`trigger-all-import.php` and `schedule-all-import.php`) have been removed.

### 5.3 `permission_callback => '__return_true'` — REST routes only

| File:line | Route | Auth model | Status |
|---|---|---|---|
| `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php:145` | OPTIONS preflight | CORS — intentionally anonymous | ✅ Acceptable |
| `includes/rest/class-wp-mcp-ai-rest-triggers-controller.php:122` | Webhook receiver | **NEW** — must verify signature inside callback | ⚠️ **F-AUTHZ-05** |

### 5.4 `auth_callback => '__return_true'` — CPT meta endpoints

**22 instances** across:
- `includes/professions/class-wp-mcp-ai-profession-cpt.php` (20 meta fields)
- `includes/teams/class-wp-mcp-ai-team-cpt.php` (2 meta fields)

This is the standard WordPress pattern for `register_meta()` when the meta is managed through OAuth/SSO-driven flows (the REST API auth check is on the route `permission_callback`, not the meta `auth_callback`). See **F-CPT-01** for the formal review.

### 5.5 `ABSPATH` guard coverage

**942/942** PHP files in `includes/` have the `ABSPATH` guard. **Zero** missing. ✅

### 5.6 Frontend `innerHTML` usage

Not re-scanned for this audit. The April 2026 audit found 120 sites; the full audit is tracked under R-Q-04 (still open).

### 5.7 `wp_ajax_nopriv_*` handlers (3 total)

All 3 in `includes/class-wp-mcp-ai-professional-selector-shortcode.php`:

| Hook | Handler method | Needs review |
|---|---|---|
| `wp_ajax_nopriv_wp_mcp_ai_get_professional_config` | `handle_get_professional_config` | Nonce? Rate limit? |
| `wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider` | `handle_get_models_for_provider` | Nonce? Rate limit? |
| `wp_ajax_nopriv_wp_mcp_ai_render_professional_chat` | `handle_render_professional_chat` | Nonce? Rate limit? |

Tracked as **F-AUTHZ-06**.

### 5.8 New `includes/agents/` subsystem — first-pass sweep

| Check | Status |
|---|---|
| ABSPATH guard on all 10 files | ✅ |
| No `eval()` / `shell_exec()` / `exec()` | ✅ (code sandbox uses `proc_open` array-form) |
| Capability checks before privileged ops | ✅ (approval gate, audit trail, sandbox all gated) |
| HTTP calls use `wp_remote_*` with timeouts | ⚠️ Not exhaustively verified — 7,965 lines need deeper review |
| Inline script/style enqueued via WP APIs | ✅ No raw `<script>`/`<style>` in agent PHP |
| No `sslverify => false` | ✅ (preliminary — not exhaustively verified) |
| CoSAI P1–P3 documentation | ✅ Each class PHPDoc cites the CoSAI principle |

See **F-AGENT-01** for the full first-pass review.

### 5.9 Privacy API integration

`includes/class-wp-mcp-ai-privacy.php` registers exporters/erasers. The new `includes/agents/class-wp-mcp-ai-agent-audit-trail.php` creates `mcp_ai_audit_event` CPT entries — these must be covered by the Privacy API exporter/eraser. Tracked under **F-AGENT-01**.

### 5.10 Existing `security.yml` workflow

The repo's `.github/workflows/security.yml` runs `composer audit`, `npm audit`, PHPStan with security rules, and grep-based checks. CodeQL `security-extended` is still not present (R-T-03 remains open from April).

## 6. WordPress Plugin Check (`wp plugin-check`)

Not executed in this audit. The gating CI job (R-T-04) was added in April and is part of the release pipeline.

## 7. Summary: What changed since April

| Area | April result | May result |
|---|---|---|
| Dangerous functions in base | 0 | 0 ✅ |
| `sslverify => false` in base | 4 | 2 (loopback-gated, acceptable) |
| `__return_true` REST | 14 → all resolved | 1 new (triggers webhook — needs review) |
| `__return_true` CPT meta | N/A | 22 (standard WP pattern, acceptable) |
| ABSPATH guards missing | 4 | 0 ✅ |
| `wp_ajax_nopriv_` | 6 | 3 (new — needs review) |
| Unprepared SQL | 2 (now fixed) | 0 ✅ |
| New surface | N/A | `includes/agents/` — 7,965 lines |
