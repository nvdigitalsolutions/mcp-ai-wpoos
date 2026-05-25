# Phase 5 — Test Coverage & Regression Safety

## 1. Existing test inventory

| Path | `test-*.php` (April 2026) | `test-*.php` (May 2026) | Delta |
|---|---:|---:|---:|
| `tests/` (root) | ~210 | ~1,077 | **+867 (+413%)** |
| `addons/pro/tests/` | ~140 | ~140 | 0 |
| `addons/fantasy-football/tests/` | ~6 | ~6 | 0 |
| `addons/embedded/tests/` | ~5 | ~5 | 0 |
| `addons/algorave/tests/` | ~3 | ~3 | 0 |
| `addons/graphify/tests/` | none | ~5 (added) | +5 |
| `addons/canvas/tests/` | none | none | 0 |
| `addons/cornerstone3d/tests/` | none | none | 0 |
| **Total** | **~365** | **~1,077** | **+712 (+195%)** |

> The massive increase is attributable to PHPUnit 11 compatibility work (PRs #5093–#5109) which added test fixtures, bootstrap stability improvements, and `DOING_AJAX` definitions across many test files. The growth is primarily in existing test files being expanded, not entirely new test suites.

## 2. Security-critical paths and their coverage (May 2026 update)

| Path | April 2026 status | May 2026 status |
|---|---|---|
| REST `permission_callback` negative tests | Partial — T-01 not implemented | **Still partial** — T-01 remains open |
| AJAX nonce-failure parametric test | Partial — T-02 not implemented | **Still partial** — T-02 remains open |
| Webhook signature rejection tests | ❌ T-03 not implemented | **Still ❌** — T-03 remains open; now also needed for triggers controller (F-AUTHZ-05) |
| SSRF allowlist tests | ❌ T-04 not implemented | **Still ❌** — T-04 remains open |
| Path-traversal fuzz tests | ❌ T-05 not implemented | **Still ❌** — T-05 remains open |
| DICOM validator tests | ❌ T-06 not implemented | **Still ❌** — T-06 remains open |
| Guest-token escalation tests | Partial — T-07 not implemented | **Still partial** — T-07 remains open |
| graphify `%i` placeholder tests | ❌ T-08 not implemented | **Still ❌** — T-08 remains open |
| Algorave sandbox tests | ❌ T-09 not implemented | **Still ❌** — T-09 remains open |
| `__return_true` regression CI | ❌ T-10 (R-T-05) not implemented | **Still ❌** — R-T-05 remains open |
| Agent sandbox escape tests | N/A | **NEW** — T-11 needed for F-AGENT-01 |
| Agent audit trail Privacy API tests | N/A | **NEW** — T-12 needed for F-AGENT-01 |
| Professional selector nonce tests | N/A | **NEW** — T-13 needed for F-AUTHZ-06 |

## 3. Required new tests (May 2026)

Continuing from the April 2026 numbering, adding new items:

### Carry-forward from April (still open)

1. **T-01** — REST permission negative tests for every controller.
2. **T-02** — AJAX nonce-failure parametric test.
3. **T-03** — Webhook signature rejection tests (now includes triggers controller F-AUTHZ-05).
4. **T-04** — SSRF allowlist unit + integration tests.
5. **T-05** — Path-traversal fuzz for document-generation tools.
6. **T-06** — DICOM validator tests.
7. **T-07** — Guest-token escalation tests.
8. **T-08** — graphify `%i` placeholder tests.
9. **T-09** — Algorave sandbox tests.
10. **T-10** — `__return_true` permission-callback regression CI.

### New for May 2026

11. **T-11 — Agent sandbox escape tests.**
    - File: `tests/test-agent-code-sandbox.php`
    - Assert `proc_open` sandbox enforces timeout, output cap, env stripping, and directory isolation.
    - Assert sandboxed code cannot write outside the temp directory.
    - Assert sandboxed code cannot make network requests (stripped environment).
    - Assert sandboxed code cannot access WordPress functions (isolated process).

12. **T-12 — Agent audit trail Privacy API tests.**
    - File: `tests/test-agent-audit-trail-privacy.php`
    - Assert `mcp_ai_audit_event` CPT entries are exported via `wp_mcp_ai_privacy_exporter`.
    - Assert `mcp_ai_audit_event` CPT entries are erased via `wp_mcp_ai_privacy_eraser`.
    - Assert audit trail respects user data export/erase requests.

13. **T-13 — Professional selector nonce verification tests.**
    - File: `tests/test-professional-selector-nonces.php`
    - Assert all 3 `wp_ajax_nopriv_` handlers return `403` without a valid nonce.
    - Assert rate limiting is applied (10 req/min/IP as established in R-S-07).
    - Assert nonce is available to frontend via `wp_localize_script()`.
    - Assert `handle_render_professional_chat` cannot be triggered without valid nonce.

## 4. CI gates (R-T-05 still open from April)

The `security-regression.yml` workflow proposed in April remains unimplemented. It should:

1. Block new `__return_true` permission callbacks outside the webhook allowlist.
2. Block new `sslverify => false` outside loopback-gated contexts.
3. Block new `eval(`/`shell_exec(` outside the explicit pro shell-tool allowlist.
4. Run the new test files T-01 through T-13 as a separate job.
5. Add `github/codeql-action` with `security-extended` queries (R-T-03).

## 5. Coverage target

Target remains **≥ 90%** branch coverage on:

- `includes/rest/` (REST controllers) — now also `class-wp-mcp-ai-rest-triggers-controller.php`
- `includes/agents/` — all 10 classes
- `includes/class-wp-mcp-ai-credentials.php`
- `includes/class-wp-mcp-ai-encryption.php`
- `includes/class-wp-mcp-ai-federation-peer-verifier.php`
- `includes/a2a/`
- `addons/pro/includes/rest/` webhook controllers
- The central HTTP wrapper (R-A-02)
