# WordPress.org Submission Manifest

This document describes what is — and what is **not** — part of the
WordPress.org Plugin Directory submission for **NV Digital Open Operator
System (oOS)**.

> **Reviewer note:** if you encountered a finding whose path begins with
> `addons/`, that finding is **out of scope** for this submission. The
> ZIP uploaded to WordPress.org never contains anything under `addons/`,
> `core/`, or `shared/`. See "How separation is enforced" below.

## Plugin slug

| Property            | Value                                |
|---------------------|--------------------------------------|
| Plugin folder name  | `mcp-ai-wpoos`                       |
| Main plugin file    | `mcp-ai-wpoos.php`                   |
| Text domain         | `mcp-ai-wpoos` (matches folder name) |
| Repository slug we are requesting | `mcp-ai-wpoos`         |

The plugin's hooks, options, transients, capabilities, JS handles, REST
namespaces, custom-post-type slugs, and constants are all derived from
the `mcp-ai-wpoos` / `wp_mcp_ai_*` / `WP_MCP_AI_*` namespaces. We are
asking the WordPress.org Plugin Directory team to keep this slug.

## What is included in the submission

The WordPress.org ZIP contains **only** the following top-level entries:

```
mcp-ai-wpoos/
├── mcp-ai-wpoos.php          ← main plugin entry point
├── readme.txt
├── README.md
├── CHANGELOG.md
├── LICENSE
├── assets/                   ← built CSS/JS only (no `assets/examples`)
├── includes/                 ← all base-plugin PHP
├── languages/                ← .pot + .mo files
└── vendor/                   ← Composer no-dev autoload
```

## What is excluded

Excluded **before** the ZIP is built (via `rsync` in
`.github/workflows/release.yml`):

* `addons/` — every addon (Pro, Embedded, Fantasy-Football, Algorave,
  Canvas, Cornerstone3D, Graphify, Docs-Hub, Chat-SPA, Canvas-Toolkit,
  Document-Editor, Media-Studio, Toolkit-Shell, SaaS-Controller,
  Cloud-Worker)
* `core/`, `shared/`, `archive/`, `examples/`, `packages/`, `src/`,
  `patches/`
* `tests/`, `bin/`, `coverage/`, `docs/`
* `node_modules/`, `composer.lock`, `package*.json`, all dev configs
* `mcp-ai-wpoos-base.php` (a restricted-mode entry point used only for
  private builds)
* `mcp-diagnostic-debug.php`, `test-*.php`, `verify-*.sh`, `*-backup.php`
* CDN-loading enqueue files and assets:
  - `includes/class-wp-mcp-ai-langchain-enqueue.php`
  - `includes/class-wp-mcp-ai-transformers-enqueue.php`
  - `includes/class-wp-mcp-ai-webworker-enqueue.php`
  - `assets/js/langchain-*.js`
  - `assets/js/transformers-tasks-client*.js`
  - `assets/js/web-worker-manager*.js`
  - `assets/js/workers/`

Excluded **as a second layer** by `.distignore` (used by the
`10up/action-wordpress-plugin-deploy` SVN deploy step). `.distignore`
mirrors the rsync list 1:1.

## How separation is enforced

The release pipeline (`.github/workflows/release.yml`) produces **two
artifacts** in the build job:

1. `mcp-ai-wpoos-{version}.zip` — base only. **This is the submission.**
2. `mcp-ai-wpoos-{version}-full.zip` — base + addons. Attached to the
   GitHub Release page only.

After both ZIPs are built, the workflow runs three guards:

1. `find build/${PLUGIN_SLUG} -type d -name 'addons'` — must return
   nothing.
2. `unzip -l mcp-ai-wpoos-{version}.zip | grep -E '(^|/)addons/'` —
   must return nothing.
3. The `WordPress.org Plugin Check` job (`wp plugin check`) is run
   against the base-only ZIP and any `ERROR`-severity finding fails the
   build.

A fourth guard runs in the SVN-deploy job: `grep -E '^\s*addons\s*$'
.distignore` must succeed before `10up/action-wordpress-plugin-deploy`
is allowed to push.

## What this means for review findings

If the reviewer sees an issue path that starts with `addons/` (for
example `addons/embedded/...`, `addons/fantasy-football/...`,
`addons/pro/...`), that file is not in the submission. Re-running
Plugin Check against the base-only ZIP will not surface those findings.

The base plugin's own findings (paths under `includes/`, `assets/`,
root `*.php`, `readme.txt`) are addressed in this PR and tracked in
the changelog.

---

## Per-Finding Response Table (reviewer reply)

This table maps each original WP.org reviewer finding to its resolution.
Findings prefixed with `addons/` resolve as "lived in addons/, not in
this submission" — confirmed by the `Assert no addons/ in base-only build`
CI step.

| Finding (reviewer reference) | Resolution | Proof |
|---|---|---|
| Yahoo Fantasy Sports API | In `addons/fantasy-football/` — excluded from submission | `Assert no addons/` CI step |
| embedded `set_time_limit` | In `addons/embedded/` — excluded from submission | `Assert no addons/` CI step |
| embedded webchat REST route | In `addons/embedded/` — excluded from submission | `Assert no addons/` CI step |
| Foreign text domains (nvoos-*, nvdigital-*-pro) | All in `addons/` — excluded; phpcs `WordPress.WP.I18n` gate prevents regression | phpcs.xml.dist + `Assert single text domain` CI step |
| `ai.google.dev/privacy` dead URL | Replaced with `policies.google.com/privacy` throughout readme.txt | PR #4892 |
| `google.github.io/A2A` dead URL | Updated to `https://a2aproject.github.io/A2A/` in readme.txt | readme.txt line 1454 |
| Plugin directory slug mismatch | Retaining `mcp-ai-wpoos`; directory team notified to align slug | A4 — off-repo action |
| Submission ZIP contained addons/ | Fixed: `release.yml` now builds separate base-only + full ZIPs | A1 — PR #4892, release.yml |
| Missing Plugin Check gate | Added `plugin-check` CI job in `release.yml` | A2 — PR #4892, release.yml |
| Undocumented external services | Extended `== External Services ==` in readme.txt; all base-plugin domains documented | B5 — PR #4892 |
| A2A spec link (base plugin) | A2A REST controller ships in base (`includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`); link updated | B1 |
| Symfony libraries out of date | Pinned to 6.4 LTS line; each package is at latest 6.4.x patch | B2 — composer.json comment |
| HEREDOC syntax | 0 occurrences in base tree; `WordPress.PHP.PreventUseOfHereDocSyntax` phpcs rule locks it in | B14 — phpcs.xml.dist |
| REST route missing `permission_callback` | Only `addons/embedded` route was flagged — excluded; all 83 base routes have explicit callbacks; PHPUnit walker test guards against regression | B12 — tests/test-rest-permission-callbacks.php |
| Inline `<script>` / `<style>` (143 incidences) | Resolved — dead WP < 5.7 fallback branches removed (plugin requires WP 6.0+); multi-line `echo '<script>'` config blocks converted to `wp_print_inline_script_tag()`; admin telemetry CSS moved to `wp_add_inline_style()` via `admin_enqueue_scripts` | B3 — this PR |
| Input sanitization / nonce verification | Resolved — all AJAX handlers confirmed to have `check_ajax_referer()` (49/49 handlers); missing `wp_unslash()` on `$_POST['approval_id']` / `$_POST['resolution']` / `$_POST['note']` in approvals handler fixed; bare `phpcs:ignore NonceVerification.Recommended` lines in DAG builder updated with explanatory comments | B13 — `includes/admin/class-wp-mcp-ai-admin-approvals.php`, `includes/admin/class-wp-mcp-ai-admin-dag-builder.php`, this PR |
| `wp_set_current_user` / `wp_update_user` hardening | Resolved — every base-plugin `wp_set_current_user()` call now routes through `WP_MCP_AI_User_Context_Helper::safe_set_current_user()`, which validates the user exists (`get_userdata()`) and (on multisite) belongs to the current blog before mutating global state | B10 — `includes/helpers/class-wp-mcp-ai-user-context-helper.php`, `tests/test-user-context-helper.php` |
| `WP_CONTENT_DIR` path usage | Resolved — filesystem cache dir moved from `WP_CONTENT_DIR/cache/wp-mcp-ai` to `wp_upload_dir()['basedir']/wp-mcp-ai-cache`; debug-log path construction retains `WP_CONTENT_DIR` only where WP Core itself defines that path | B8 — `includes/cache/class-wp-mcp-ai-cache-service.php`, this PR |
| Inline `<script>` / `<style>` — remaining incidences (F3) | Resolved — all remaining inline echoes across 53 base-plugin files converted to `wp_add_inline_style()` / `wp_print_inline_script_tag()`; PHP parse errors from conversion (duplicate `<?php`, spurious `?>`) fixed across 7 files | F3 — PRs #5027, #5028, #5029, #5031, #5032 |
| `json_decode()` sanitization (F6) | Resolved — all `json_decode()` calls in base-plugin files wrapped with sanitization/validation guards; verified clean in May 20 re-audit | F6 — PRs #5030, #5031; re-audit #5034 |
| `require_once` path hardening (F5) | Resolved — all `require_once` statements use absolute paths derived from `plugin_dir_path()` or `WP_MCP_AI_PATH` constant; verified clean in May 20 re-audit | F5 — PRs #5030, #5031 |
| HTTP timeout guards (F7b) | Resolved — all `wp_remote_get()` / `wp_remote_post()` calls now carry explicit `timeout` arguments; verified clean in HTTP-timeout audit (May 20) | F7b — PRs #5030, #5031; re-audit #5034 |
| Dangerous functions audit | Verified — zero `eval`/`exec`/`system`/`shell_exec`/`passthru` calls in base-plugin tree; audit added to CI | May 20 re-audit (#5034) |
| Superglobal access audit | Verified — all `$_GET`/`$_POST`/`$_SERVER` access gated through sanitization; audit added to CI | May 20 re-audit (#5034) |
| Build-pipeline ZIP hardening | Resolved — `.codex-wordpress` and `phpcs` directories excluded from distribution ZIPs via `bin/build-plugin-zip.sh` and `bin/strip-dev-files.sh`; `.gitignore` rule added | F7b — PR #5033 |
