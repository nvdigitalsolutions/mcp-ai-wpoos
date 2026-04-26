# Phase 2 — Automated Scan Results

> Raw output and reproducible commands for the audit. All scans were run on commit ref of this branch on 2026-04-26.

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

# WPCS errors-only on base tree (matches the current CI gate)
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

### Root (10 moderate, 0 high, 0 critical)

| Package | Range | Advisory | CVE / GHSA |
|---|---|---|---|
| `uuid` | < 14.0.0 | Missing buffer bounds check in v3/v5/v6 when `buf` is provided | GHSA-w5hq-g745-h8pq |
| `langsmith` | ≤ 0.5.23 | depends on vulnerable `uuid` | (transitive) |
| `exceljs` | ≥ 3.5.0 | depends on vulnerable `uuid` | (transitive) |
| `yaml` | 2.0.0–2.8.2 | Stack overflow via deeply nested YAML collections | GHSA-48c2-rrv3-qjmp |

Auto-fix path: `npm audit fix` (non-breaking) for `yaml`; `npm audit fix --force` (breaking, downgrades `exceljs`) for `uuid`.

### Pro (3 moderate, 0 high, 0 critical)

| Package | Range | Advisory | CVE / GHSA |
|---|---|---|---|
| `postcss` | < 8.5.10 | (parser issue) | (advisory in tail of audit) |
| `uuid` | < 14.0.0 | as above | GHSA-w5hq-g745-h8pq |
| `exceljs` | ≥ 3.5.0 | depends on vulnerable `uuid` | (transitive) |

## 4. PHPCS (`composer run lint:base` style)

Scanned **902** PHP files (base + addons except pro), errors-only.

```
A TOTAL OF 330 ERRORS AND 0 WARNINGS WERE FOUND IN 73 FILES
PHPCBF CAN FIX 168 OF THESE SNIFF VIOLATIONS AUTOMATICALLY
```

Top sniffs by count:

| Sniff | Count | Auto-fixable | Security relevance |
|---|---:|---|---|
| `WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound` | 52 | ✅ | none |
| `WordPress.Files.FileName.InvalidClassFileName` | 43 | — | none (style) |
| `PEAR.NamingConventions.ValidClassName.Invalid` | 42 | — | none (style) |
| `Squiz.Commenting.FunctionComment.SpacingAfterParamType` | 24 | ✅ | none |
| `Generic.WhiteSpace.ScopeIndent.IncorrectExact` | 23 | ✅ | none |
| `Squiz.Commenting.FunctionComment.MissingParamTag` | 20 | — | low (docs) |
| `WordPress.PHP.YodaConditions.NotYoda` | 12 | — | none (style) |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 10 | — | none (i18n) |
| `WordPress.DB.PreparedSQL.NotPrepared` | **7** | — | **HIGH (SQLi)** |
| `Squiz.Commenting.FunctionComment.Missing` | 6 | — | none (docs) |
| (others) | < 5 each | various | none |

### 4.1 Prepared-SQL findings (security-relevant)

```
addons/graphify/includes/class-nvoos-graphify-db.php
  152 | ERROR | Use placeholders and $wpdb->prepare(); found self
  152 | ERROR | Use placeholders and $wpdb->prepare(); found edges_table
  153 | ERROR | Use placeholders and $wpdb->prepare(); found self
  153 | ERROR | Use placeholders and $wpdb->prepare(); found nodes_table
  154 | ERROR | Use placeholders and $wpdb->prepare(); found self
  154 | ERROR | Use placeholders and $wpdb->prepare(); found meta_table

addons/graphify/includes/class-nvoos-graphify-report.php
   80 | ERROR | Use placeholders and $wpdb->prepare(); found interpolated variable {$nodes_table}
        | at "SELECT community_id, COUNT(*) AS cnt FROM {$nodes_table} WHERE community_id != ''
        | GROUP BY community_id ORDER BY cnt DESC LIMIT 20"

includes/class-wp-mcp-ai-model-catalog-migration.php
  209 | ERROR | Use placeholders and $wpdb->prepare(); found $query
```

These are tracked as findings **F-SQL-01** (graphify) and **F-SQL-02** (model catalog migration). Inspection shows the interpolated values are server-controlled custom-table names from `$wpdb->prefix.'…'`, not user input — but `$wpdb->prepare()` with `%i` (identifier placeholder, WP 6.2+) is still the correct pattern and the WPCS rule should not be silenced.

## 5. Manual security pattern sweeps

These are quick `grep`-based sweeps, not exhaustive but representative.

### 5.1 Dangerous PHP functions

| Pattern | Hits in product code (excl. tests/vendor) |
|---|---|
| `eval(` | **0** product calls. The only references are inside `includes/services/class-wp-mcp-ai-code-optimizer.php` flagging external code as risky — defensive, not a vulnerability. |
| `shell_exec(` | **3 calls** in `addons/pro/`: `tools/ai-tool-builder/class-wp-mcp-ai-tool-check-tool-compliance.php:252,531`; `tools/document-generation/class-wp-mcp-ai-tool-html-to-pdf.php:169`; `tools/document-generation/class-wp-mcp-ai-tool-merge-pdfs.php:247`. See **F-EXEC-01**. |
| `exec(` | **8 calls** in pro tools (`architect-agent/`, `document-generation/`). See **F-EXEC-01**. |
| `system(`, `passthru(` | 0 |
| `proc_open` | 40 occurrences — preferred over `exec`; reviewed sample shows args are array-form (no shell) — ✅ pass. |

### 5.2 TLS verification disabled

```
includes/tools/class-wp-mcp-ai-tool-trigger-all-import.php:161        sslverify => false
includes/tools/class-wp-mcp-ai-tool-schedule-all-import.php:239       sslverify => false
addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-validate-image-for-product.php:457   sslverify => false
addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-validate-image-for-vehicle.php:419   sslverify => false
```

Tracked as **F-TLS-01** (4 instances). All four are user-controllable URL fetches → MITM risk if the user has been redirected to attacker-controlled DNS.

### 5.3 `permission_callback => '__return_true'`

14 total. Of those:

| File:line | Auth model | Acceptable? |
|---|---|---|
| `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php:140` | MCP discovery / SSE | **REVIEW** — should require credential header |
| `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php:104, 116` | A2A protocol | **REVIEW** — must verify HMAC inside callback |
| `addons/pro/includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php:54` | Google Chat webhook | **OK if signature verified inside** |
| `addons/pro/includes/rest/class-wp-mcp-ai-telegram-login-controller.php:87` | Telegram login | **OK if hash check inside** |
| `addons/pro/includes/rest/class-wp-mcp-ai-twitter-webhook-controller.php:107, 136` | Twitter CRC + webhook | **OK if signature verified** |
| `addons/pro/includes/rest/class-wp-mcp-ai-whatsapp-webhook-controller.php:131, 156` | Meta verify + delivery | **OK if `hub.verify_token` + signature verified** |
| `addons/pro/includes/rest/class-wp-mcp-ai-messenger-webhook-controller.php:123` (and one more) | Messenger | **OK if signature verified** |
| (others — 2 remaining) | various | **REVIEW** |

Tracked as **F-AUTHZ-01** with per-route follow-ups.

### 5.4 `ABSPATH` guard coverage

- 1,975 of 2,050 non-vendor PHP files have `defined( 'ABSPATH' )` at the top.
- 75 lack the guard. **71 of those are test files** (acceptable). The remaining 4 are:
  1. `addons/pro/includes/vault/class-wp-mcp-ai-vault-conflict-resolver.php`
  2. `addons/pro/includes/vault/class-wp-mcp-ai-vault-background-sync.php`
  3. `addons/pro/build/workflow-builder/workflow-builder.asset.php` (build artifact — `return array(...);` only; safe but should still have the guard or be excluded from runtime load)
  4. `addons/embedded/uninstall.php`

Tracked as **F-CMP-02**.

### 5.5 Frontend `innerHTML` usage

`grep -rn '\.innerHTML' assets/js/ addons/*/assets/` — **120 hits** (excluding `.min.js`). Sampling:

- `assets/js/chat.js:2047,2057,2063,2082` — assigning **constant** SVG icons (`SPEECH_SPINNER_ICON` etc.). ✅ Safe.
- `assets/js/chat.js:2421,2428,2435,2629,2762,2768` — same pattern. ✅ Safe.
- Chat-rendered model output is fed through `chat-markdown-service.js` which uses **DOMPurify** (`grep -l DOMPurify assets/js/`). ✅ Pass.

The full audit of all 120 `innerHTML` sites (especially in `addons/pro/build/`) is a roadmap item — **R-Q-04**.

### 5.6 Eval / `Function()` constructors in JS

```
addons/algorave/assets/js/algorave-pattern-engine.js:917
    const fn = new Function( 'Tone', code );
```

This is the live-coding addon's deliberate JS execution surface — by design. Tracked as **F-AI-01** because it must be:

1. Gated behind a capability check (only `manage_options` users should reach it).
2. Sandboxed (current code runs in main thread with full DOM access).
3. Not reachable from chat-driven AI output unless the user explicitly invokes the algorave shortcode.

See [`addon-deep-dives.md`](./addon-deep-dives.md) §1 for full discussion.

### 5.7 Privacy API integration

Only `includes/class-wp-mcp-ai-privacy.php` registers exporters/erasers. Review needed for:

- Pro CCT data (channel messages, vault items, autonomous sessions) — does the existing exporter cover them?
- Healthcare data (`class-wp-mcp-ai-health-wellness-*`) — HIPAA-grade exporter/eraser?

See **F-PRIV-01**.

### 5.8 Existing `security.yml` workflow

The repo already has `.github/workflows/security.yml` running:
- `composer audit` (root only — pro is missed)
- `npm audit` (root only — pro is missed)
- PHPStan with security rules
- Grep checks for hardcoded secrets, SQL injection, XSS, nonces, file includes

Roadmap **R-T-02** extends this to also run against `addons/pro/`.

## 6. License scan summary

All 80 packages across both `composer.lock` files use GPL-compatible licences (MIT, BSD-3-Clause, LGPL-2.1-or-later, LGPL-3.0). No GPL-incompatible licence found. The Pro addon's source code is itself **proprietary** per `addons/pro/composer.json`'s `"license": "proprietary"` and `mcp-ai-wpoos-pro.php` "All rights reserved" notice — that is **outside** the WP.org-distributed base plugin and therefore does **not** affect WP.org submission readiness, but it does mean the Pro addon must never be uploaded to WP.org (the `.distignore` file already excludes `addons/` — verified).

## 7. CodeQL coverage gap

`.github/workflows/security.yml` runs grep-based security checks but there is no `github/codeql-action` step. The plan requested CodeQL `security-extended` query suite for PHP + JS — this is **not yet present**. Tracked as **R-T-03**.

## 8. WordPress Plugin Check (`wp plugin-check`)

Not executed in this audit — requires building a release ZIP and a working `wp` CLI with the plugin-check plugin installed. Tracked as **R-T-04** (add to `bin/` scripts and CI).
