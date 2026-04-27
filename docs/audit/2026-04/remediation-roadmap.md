# Phase 6 — Remediation Roadmap

> Each item is sized for a single focused PR following the project's `feat(scope):` / `fix(scope):` commit convention and the GSD × BMAD Phase 5 development flow. Suggested CODEOWNERS reviewer follows the existing [`CODEOWNERS`](../../../CODEOWNERS) mapping.

## Item ID prefixes

- **R-S-XX** — Security fix (closes a finding)
- **R-A-XX** — Architectural change (introduces a shared service)
- **R-T-XX** — Tooling / CI change
- **R-D-XX** — Documentation change
- **R-Q-XX** — Quality / lint cleanup

---

## Wave 1 — Tooling foundation (block nothing; ship first)

These changes make the rest of the audit reproducible and prevent regression while remediation is in flight.

### R-T-01 — Re-enable PHPCS on `addons/pro/`
- **Closes:** F-LINT-02
- **Files:** `phpcs.xml.dist`, `composer.json` (`lint:base` script)
- **Measured baseline (Wave 24):** 5,806 errors, 8,141 warnings across 745 files (3,758 PHP files in tree); 11,016 auto-fixable by `phpcbf`.
- **Plan:** Remove the `<exclude-pattern>*/addons/pro/*</exclude-pattern>` line; run `composer run format` (PHPCBF auto-fix) on the pro tree; commit the auto-fixes; document remaining errors with targeted `phpcs:ignore` annotations only where unavoidable.
- **Acceptance:** `composer run lint` passes against the full tree (or fails only on a documented allow-listed set). CI green.

### R-T-02 — Add pro `composer audit` and `npm audit` to CI
- **Closes:** I-09 gap
- **Files:** `.github/workflows/security.yml`
- **Plan:** Add `composer audit` and `npm audit --omit=dev` steps that `cd addons/pro` before running.
- **Acceptance:** CI fails when a new advisory appears in pro deps.

### R-T-03 — Add CodeQL `security-extended` for PHP + JS
- **Closes:** F-CODEQL-01 (implicit)
- **Files:** new `.github/workflows/codeql.yml`
- **Plan:** Use `github/codeql-action/init@v3` with `languages: javascript, php` and `queries: security-extended`. Schedule weekly + on PR.
- **Acceptance:** Scheduled workflow runs; results visible in Security tab.

### R-T-04 — Add `wp plugin-check` to release build pipeline
- **Closes:** WP.org checklist gap
- **Files:** `bin/run-plugin-check.sh` (new), `.github/workflows/build-assets.yml` or release workflow
- **Plan:** Build the WP.org ZIP via existing `bin/build-plugin-zip.sh`; spin up `wordpress:cli` container; install `plugin-check`; run against the ZIP; fail on any Errors.
- **Acceptance:** Release workflow gates on `wp plugin-check` clean run.

### R-T-05 — Security regression workflow
- **Closes:** F-AUTHZ-01 future regressions
- **Files:** new `.github/workflows/security-regression.yml`
- **Plan:** Per [`test-coverage-gaps.md`](./test-coverage-gaps.md) §4 — block new `__return_true` permission callbacks, new `sslverify => false`, new `eval(`/`shell_exec(` outside the explicit pro shell-tool allowlist, plus run the negative-permission and nonce-failure parametric test files.
- **Acceptance:** Workflow blocks at least one synthetic regression PR introducing each forbidden pattern.

### R-Q-01 — `composer run format` on base tree to clear 168 auto-fixable PHPCS errors
- **Closes:** F-LINT-01 (partial)
- **Plan:** Single mechanical PR running PHPCBF.

### R-Q-02 — `npm audit fix` on root and pro
- **Closes:** F-NPM-01, F-NPM-02
- **Plan:** Run `npm audit fix` (non-breaking) plus `npm audit fix --force` for `uuid` if breaking-change CI passes.

---

## Wave 2 — High-severity remediation (one PR each)

### R-S-01 — Move webhook signature verification into `permission_callback`
- **Closes:** F-AUTHZ-01
- **Files:** `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php`, `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php`, all 5 pro webhook controllers
- **Plan:**
  1. For each `register_rest_route` currently using `'__return_true'`, write a permission callback that calls the existing signature verifier and returns `WP_Error( 'rest_invalid_signature', …, 401 )` on failure.
  2. Add a per-controller test that POSTs an invalid signature and asserts 401 *without* the route body executing.
  3. Add the regression CI guard from R-T-05.
- **Acceptance:** All 11 affected routes return 401 on invalid signature; new tests green.
- **Reviewer:** Backend / REST team.

### R-S-02 — Migrate pro shell tools to `proc_open` + opt-in constant + capability gate
- **Closes:** F-EXEC-01
- **Files:** 11 tool classes (see finding for list)
- **Plan:**
  1. Introduce `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant (default `false`) checked at tool-registration time.
  2. Refactor every `exec()`/`shell_exec()` call to `proc_open` with array argv (no shell) — Symfony Process is already a dependency (`symfony/process`); use it.
  3. Add a top-of-`execute()` `current_user_can( 'manage_options' )` gate.
  4. Audit-log every invocation through `class-wp-mcp-ai-logger.php`.
- **Acceptance:** No `shell_exec`/`exec` outside the architect-agent test stubs; tools refuse to load when constant is `false`; new tests verify refusal path.
- **Reviewer:** Pro / architect-agent team.

### R-S-03 — Convert graphify SQL to `$wpdb->prepare()` with `%i`
- **Closes:** F-SQL-01
- **Files:** `addons/graphify/includes/class-nvoos-graphify-db.php`, `class-nvoos-graphify-report.php`
- **Plan:** Convert each query; bump `Requires at least:` to 6.2 if not already; add tests asserting bogus table names return zero rows; remove no-longer-needed `phpcs:ignore`.
- **Acceptance:** `vendor/bin/phpcs --sniffs=WordPress.DB.PreparedSQL` passes on graphify.

### R-S-04 — HIPAA posture for healthcare + DICOM addons
- **Closes:** F-PRIV-03, F-UPLOAD-01
- **Files:** `addons/pro/includes/.../health-wellness*.php`, `addons/cornerstone3d/`, new `docs/HIPAA_POSTURE.md`
- **Plan:**
  1. Strip DICOM PHI tags before any `wp_remote_post` to AI providers.
  2. Add `wp_mcp_ai_phi_acknowledged` option; refuse to load addons on multisite without it.
  3. Implement Privacy-API exporter/eraser (slot into R-A-04 registry).
  4. Add `docs/HIPAA_POSTURE.md` documenting flow, retention, BAA requirement.
  5. Audit-log every read of a health-wellness CPT post.
- **Acceptance:** Tests assert DICOM upload with PHI tags is sanitised before upstream call; addons fail-closed without PHI ack.

### R-S-05 — Algorave live-coding sandbox
- **Closes:** F-AI-01
- **Files:** `addons/algorave/assets/js/algorave-pattern-engine.js`, new `addons/algorave/assets/js/algorave-sandbox.html`
- **Plan:** Move `new Function( 'Tone', code )` into a sandboxed iframe (`sandbox="allow-scripts"` only); pass code via `postMessage`; add CSP `script-src 'self'`; capability-gate the shortcode at `edit_posts`.
- **Acceptance:** Jest test confirms code in sandbox cannot read `parent.document.cookie`.

---

## Wave 3 — Cross-cutting architecture

### R-A-02 — Central HTTP wrapper with SSRF allowlist
- **Closes:** F-SSRF-01, F-TLS-01 (in part)
- **Files:** new `includes/services/class-wp-mcp-ai-http-client.php`, all 507 `wp_remote_*` callers (migrate gradually)
- **Plan:** Resolve hostname; reject private/link-local/loopback/multicast IPv4 + IPv6; default 10 s timeout; `sslverify => true`; per-host rate-limit bucket; filter `wp_mcp_ai_http_allowed_host` for explicit overrides. Keep `wp_remote_*` as the underlying transport.
- **Acceptance:** `tests/test-http-client-ssrf.php` covers blocked-IP cases.

### R-A-03 — Central upload-validator
- **Closes:** F-UPLOAD-01, F-UPLOAD-02
- **Files:** new `includes/services/class-wp-mcp-ai-upload-validator.php`
- **Plan:** Wraps `wp_check_filetype_and_ext`, MIME match, size cap, ClamAV hook, randomised filename via `wp_unique_filename`.

### R-A-04 — Privacy registry / Privacy-API auto-wiring
- **Closes:** F-PRIV-01
- **Files:** `includes/class-wp-mcp-ai-privacy.php` (extend), CCT/CPT registration sites
- **Plan:** Each CPT/CCT registers with the registry, declaring exportable + erasable fields; one place wires them into `wp_privacy_personal_data_*` filters.

### R-A-05 — Path validator
- **Closes:** F-FS-02
- **Files:** new helper `wp_mcp_ai_validate_path( $path, $allowed_root )`; migrate document-generation tools.

### R-A-06 — Tool-result truncator + delimiter neutraliser
- **Closes:** F-AI-02
- **Files:** new helper invoked from `class-wp-mcp-ai-rest.php` agentic loop.

---

## Wave 4 — Medium / Low items

### R-S-06 — Remove `sslverify => false` (4 sites)
- **Closes:** F-TLS-01

### R-S-07 — Audit the 6 `wp_ajax_nopriv_*` handlers
- **Closes:** F-AUTHZ-02
- **Plan:** Per-handler verification with rate-limit + reduced data exposure where possible.

### R-S-08 — Multisite super-admin gates
- **Closes:** F-AUTHZ-03
- **Plan:** Helper `wp_mcp_ai_user_can_manage_fleet()`; migrate federation/asset-inventory/dependency-scan code.

### R-S-09 — Embedded guest-token origin binding
- **Closes:** F-AUTHZ-04

### R-S-10 — Canvas SVG sanitisation
- **Closes:** F-XSS-02

### R-S-11 — Graphify SVG label escaping
- **Closes:** F-SVG-XSS-01

### R-S-12 — DICOM upload validator (folded into R-S-04 / R-A-03)

### R-S-13 — Document-generation temp-file centralisation
- **Closes:** F-FS-01

### R-S-14 — MCP server allowlist
- **Closes:** F-AI-03

---

## Wave 5 — Hygiene / documentation

### R-D-01 — `readme.txt` external-services disclosure
- **Closes:** F-PRIV-02
- **Files:** `readme.txt`, `docs/EXTERNAL_SERVICES.md` (already exists — reference from readme).

### R-D-02 — `docs/HIPAA_POSTURE.md` (covered by R-S-04)

### R-D-03 — Update CHANGELOG.md, `Tested up to`, `Stable tag` per release (process)
- **Closes:** F-CMP-03

### R-D-04 — Replace stale "519 tools" string with live count
- **Closes:** F-DOC-01

### R-Q-03 — Add `ABSPATH` guard to 4 missing files
- **Closes:** F-CMP-02

### R-Q-04 — Audit all 120 `innerHTML` JS sites for non-icon usage
- **Closes:** F-XSS-01 (frontend portion)

### R-Q-05 — Standardise nonce action names to `wp_mcp_ai_*`
- **Closes:** F-CMP-04

### R-Q-06 — Ship sources for all `.min.js` files (or source maps)
- **Closes:** F-CMP-05 (WP.org guideline 11)

### R-A-01 — Per-shortcode XSS verification pass (rolling)
- **Closes:** F-XSS-01 (PHP portion)

---

## Suggested execution order

1. **Wave 1 (5 tooling PRs)** — establish visibility and regression gates first.
2. **Wave 2 (5 High PRs)** — sequential because each touches a different subsystem.
3. **Wave 3 (5 architecture PRs)** — once visibility is in place, ship the shared services.
4. **Wave 4 + 5 (concurrent, smaller PRs)** — many are mechanical and parallelisable.

Total: **~30 focused PRs** to clear the audit backlog. None individually large; each can be handled by a single GSD × BMAD Developer sprint cycle.
