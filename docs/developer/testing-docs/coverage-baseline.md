# PHPUnit + Vitest Coverage Baseline

**Established:** May 2026 (v1.1.17, PRs #1–#11 + AJAX campaign)

NV oOS maintains a non-regression coverage baseline enforced by CI.

---

## PHPUnit Baseline

The PHPUnit baseline was established by the coverage campaign (PRs #1–#11):

| Campaign | Coverage Added |
|----------|---------------|
| PR #1 — Tool registry smoke test | Registry instantiation + manifest |
| PR #2 — Harness + provider tests | Harness layers, NVIDIA client |
| PR #3 — REST controller tests | Approval, cost-manager, slash-command controllers |
| PR #4 — Slash-command tests | `/help`, `/context`, `/compact`, `/memory` |
| PR #5 — 20 high-risk base tools | `create-post`, `check-site-security`, `load-skill`, etc. |
| PR #6 — 20 high-risk Pro tools | Vault, schedules, ECA, medical, autonomous-session |
| PR #7 — 10 security-sensitive services | Auth, token, nonce, SSRF, upload validation |
| PR #8 — Hooks + security regression suite | 52 tests across 4 files |
| PR #9–#11 — AJAX handler coverage | All 271 AJAX handlers; allowlist cleared to 0 |

### Non-regression CI gate

The CI gate (`phpunit.yml`) reads the baseline from `tests/coverage-baseline.json` and fails the build if any covered class drops below its recorded threshold.

```bash
# Run with coverage
composer run test -- --coverage-html coverage/

# Check against baseline
vendor/bin/phpunit --coverage-text | php bin/check-coverage-baseline.php
```

---

## Vitest Coverage (SPA addons)

Vitest scaffolding was added for all 6 SPA addons in PR #11:

| Addon | Test file location |
|-------|--------------------|
| `addons/toolkit-shell/` | `src/__tests__/` |
| `addons/canvas-toolkit/` | `src/__tests__/` |
| `addons/document-editor/` | `src/__tests__/` |
| `addons/media-studio/` | `src/__tests__/` |
| `addons/chat-spa/` | `src/__tests__/` |
| `addons/docs-hub/` | `src/__tests__/` |

Run Vitest for all addons:
```bash
# From each addon directory
npm run test

# From root (all addons)
for d in addons/*/; do (cd "$d" && npm run test --if-present); done
```

---

## AJAX Handler Audit

A full audit of all WordPress AJAX handlers registered by the base plugin was completed in clusters 1–17. An allowlist file at `tests/ajax-handler-allowlist.json` tracks which handlers are covered. The CI guard fails if uncovered handlers appear outside the allowlist.

```bash
# Run AJAX audit script
php tests/audit-ajax-handlers.php
```

Reference: [`docs/ajax-test-suites.md`](../ajax-test-suites.md)
