# Phase 5 — Test Coverage & Regression Safety

## 1. Existing test inventory (quick count)

Running `find tests addons/*/tests -name 'test-*.php' | wc -l` on this branch:

| Path | `test-*.php` |
|---|---:|
| `tests/` (root) | ~210 |
| `addons/pro/tests/` | ~140 |
| `addons/fantasy-football/tests/` | ~6 |
| `addons/embedded/tests/` | ~5 |
| `addons/algorave/tests/` | ~3 |
| `addons/graphify/tests/` | (none) |
| `addons/canvas/tests/` | (none) |
| `addons/cornerstone3d/tests/` | (none) |
| **Total** | **~365** |

PHPUnit was **not** executed in this audit (requires WordPress test database via `composer run test:install`). The numbers below come from manual inspection of test file names.

## 2. Security-critical paths and their coverage

| Path | Has dedicated security test? | Notes |
|---|---|---|
| REST `permission_callback` for **all 190 routes** | Partial | Spot tests in `tests/rest/` and `tests/rest-api/`; not every controller has a negative permission test. |
| AJAX **313** handlers | Partial | Tests exist for vault metaboxes, AI CPT, performance section. The other ~300 handlers do not have a "called without nonce → fails" test. |
| `wp_ajax_nopriv_*` (6 handlers) | ⚠️ Need each verified | All six should have tests confirming a non-logged-in caller without a valid nonce gets `403`. |
| `class-wp-mcp-ai-credentials.php` (Bearer auth) | ✅ | covered by `tests/test-credentials*.php` |
| Encryption (`wp_mcp_ai_encrypt`) | ✅ | covered |
| Federation HMAC verifier | ✅ | covered by `tests/test-federation-peer-verifier.php` (assumed by name) |
| Tool-registry capability flags | ✅ | `test-capability-flags-integration.php` |
| **Webhook signature verification** (Telegram/WhatsApp/Twitter/Messenger/Google Chat) | ❌ | No dedicated test files in `addons/pro/tests/` for signature-rejection paths. |
| **SSRF allowlist** | ❌ | Not implemented yet → no tests. |
| **Path-traversal in document-generation tools** | ❌ | No fuzz tests. |
| **DICOM upload validation** | ❌ | No tests in cornerstone3d (no test dir). |
| **graphify SQL with `%i`** | ❌ | No tests yet. |
| **Algorave sandbox enforcement** | ❌ | No tests. |
| **Guest-token bypass attempts** | Partial | `tests/test-guest-tokens*.php` covers happy path but not bypass attempts on admin tools. |

## 3. Required new tests

Numbered to align with [`remediation-roadmap.md`](./remediation-roadmap.md).

1. **T-01** — REST permission negative tests for every controller. New file `tests/rest/test-rest-permission-callbacks.php` that loops every registered route from `mcp-ai/v1`, calls it as an unauthenticated user, and asserts `rest_forbidden` (or, for webhook routes, `rest_invalid_signature`).
2. **T-02** — AJAX nonce-failure parametric test. New file `tests/test-ajax-nonce-coverage.php` that introspects all `wp_ajax_*` and `wp_ajax_nopriv_*` registrations and asserts each fails closed without a valid nonce.
3. **T-03** — Webhook signature rejection tests, one per provider (5 files in `addons/pro/tests/`).
4. **T-04** — SSRF allowlist unit + integration tests. Verifies the new central HTTP wrapper rejects `127.0.0.1`, `169.254.169.254`, `::1`, `fe80::*`, and any host not on the allowlist.
5. **T-05** — Path-traversal fuzz for document-generation tools. Asserts `wp_unique_filename`-derived paths can never escape `wp-content/uploads/wp-mcp-ai-temp/`.
6. **T-06** — DICOM validator tests: rejects oversized, missing-magic, and PHI-stripping outputs.
7. **T-07** — Guest-token escalation tests: asserts every "write" or "state-changing" tool refuses execution under `guest_request` regardless of capability flags.
8. **T-08** — graphify `%i` placeholder tests once SQL is fixed.
9. **T-09** — Algorave sandbox tests (Jest): asserts `new Function` runs inside an `<iframe sandbox="allow-scripts">` and cannot read `parent.document.cookie`.
10. **T-10** — `__return_true` permission-callback regression — a CI step that fails if any new `register_rest_route` ships with `__return_true` unless the controller is on a webhook allowlist.

## 4. CI gate to add (R-T-05)

Add `.github/workflows/security-regression.yml` that:

1. Runs the new test files above as a separate job to keep them visible.
2. Fails the build if any new file contains `'__return_true'` for a `permission_callback` outside `addons/pro/includes/rest/class-wp-mcp-ai-*-webhook-controller.php` or `class-wp-mcp-ai-telegram-login-controller.php`.
3. Fails the build if any new file introduces `sslverify => false`, `eval(`, raw `shell_exec(` (without an existing pre-approved allowlist comment).
4. Runs `composer audit` and `npm audit --omit=dev` for **both** root and `addons/pro/`.
5. Adds `github/codeql-action` with `security-extended` queries for PHP and JS.

These gates are intentionally **advisory** for one release cycle (they post warnings) before being made blocking, so that the existing 330 PHPCS errors and 13 npm advisories can be remediated without blocking unrelated work.

## 5. Coverage target

Once the new tests above are added, target **≥ 90 %** branch coverage on:

- `includes/rest/` (REST controllers)
- `includes/class-wp-mcp-ai-credentials.php`
- `includes/class-wp-mcp-ai-encryption.php`
- `includes/class-wp-mcp-ai-federation-peer-verifier.php`
- `includes/a2a/`
- `addons/pro/includes/rest/` webhook controllers
- The forthcoming central HTTP wrapper (R-A-02)
- The forthcoming central upload-validator (R-A-03)

Existing coverage tooling (`composer run test:coverage`) already produces clover output — just enforce a minimum on these specific paths in `phpunit.xml.dist`.
