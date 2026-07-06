# OWASP ZAP DAST Integration Proposal

**Date:** 2026-07-06
**Author:** NV Digital Solutions (AI Agent)
**Status:** Phase 1–5 Implemented ✅ | Gate hardening in progress
**Audit Roadmap Item:** R-T-06 — DAST pipeline integration ✅
**Related:** `docs/project/audits/2026-04/remediation-roadmap.md` (Wave 1 tooling)

---

## Executive Summary

The NV oOS plugin currently has a strong **SAST** (Static Application Security Testing) layer across four CI workflows — dependency scanning, PHPStan security analysis, WordPress-specific grep checks, and forbidden-pattern regression guards. What's missing is **DAST** (Dynamic Application Security Testing): no workflow spins up a live WordPress instance and attacks it from the outside.

This proposal integrates **OWASP ZAP** (Zed Attack Proxy) — the industry-standard open-source DAST tool maintained by the Open Web Application Security Project — into the CI/CD pipeline. ZAP will catch runtime vulnerabilities that static analysis cannot: authentication bypass, broken access control, injection via JSON payloads, SSRF through tool arguments, information disclosure in error responses, and CORS misconfigurations.

The integration reuses the existing `tests/qa/docker/docker-compose.qa.yml` Docker stack (WordPress 6.9 + MySQL 8.0) already proven in the E2E Playwright workflow, so no new infrastructure is required.

---

## Why DAST Matters for This Plugin

This plugin is a **high-value DAST candidate** because:

| Surface | Risk |
|---|---|
| **~30+ REST endpoints** (`/wp-json/mcp-ai/v1/` + addon namespaces) | Auth bypass, privilege escalation, data exposure |
| **4 authentication methods** (nonce, Bearer, guest, Auth0) | Token leakage, replay attacks, broken session management |
| **SSE streaming** (`/wp-json/mcp-ai/v1/sse`) | Connection exhaustion, injection via event stream |
| **File upload paths** (document prompts, image gen, media processing) | Malicious file upload, path traversal |
| **Tool execution surface** (~830 tools accepting arbitrary JSON) | SSRF, command injection, data exfiltration via tool arguments |
| **Third-party API keys** (OpenAI, Gemini, Ollama) | Credential leakage in error responses or debug output |
| **WordPress.org plugin directory** | wp.org runs Plugin Check (PCP) but no DAST — ZAP provides defense-in-depth before submission |

### What ZAP Can Find That SAST Cannot

- **Authentication bypass**: Does an endpoint with `__return_true` permission_callback actually expose data it shouldn't?
- **Broken access control**: Can a subscriber-level user reach admin-only REST routes?
- **Injection via JSON**: Do tool argument schemas with `additionalProperties: true` accept dangerous payloads?
- **Information disclosure**: Do error responses leak stack traces, API keys, or server paths?
- **CORS misconfiguration**: Are `Access-Control-Allow-Origin: *` headers present on sensitive endpoints?
- **Missing security headers**: `X-Content-Type-Options`, `Content-Security-Policy`, `Strict-Transport-Security`
- **Rate limiting gaps**: Can an attacker brute-force the guest token or nonce endpoints?

---

## Industry Standards & Best Practices

### Scan Types

| Scan | ZAP Action | Attack Payloads? | Safe for Production? | CI Placement |
|---|---|---|---|---|
| **Baseline** | `zaproxy/action-baseline` | No (passive only, ~1 min spider) | Yes | Every PR |
| **Full** | `zaproxy/action-full-scan` | Yes (active spider + attack payloads) | No — staging only | Weekly cron, pre-release gate |
| **API** | `zaproxy/action-api-scan` | Yes (OpenAPI-driven, targeted) | Depends on endpoint | PRs touching REST code |

### Pipeline Placement (Shift-Left Consensus)

```
PR opened
  → SAST (lint, PHPStan, dependency audit, regression guards)
  → Unit tests
  → Build + deploy to ephemeral Docker env
  → DAST (ZAP baseline — passive only)
  → Gate (WARN on new findings, FAIL on regressions)
  → Merge

Nightly/Weekly cron
  → DAST (ZAP full scan on staging)
  → Report → GitHub Issue for Critical/High
```

### Failure Threshold Strategy

Industry best practice is a phased hardening approach:

1. **Advisory phase** (weeks 1–4): `continue-on-error: true`. All findings → WARN. Team triages and builds a `.zap-rules.tsv` baseline.
2. **Hardened phase** (week 5+): High/Critical → FAIL the build. Medium → WARN. Low/Info → IGNORE.
3. **Rules file** (`.zap-rules.tsv`): Per-rule-ID actions (`IGNORE`, `WARN`, `FAIL`) checked into the repo, version-controlled alongside code. This file is the single source of truth for what constitutes a blocking finding.

---

## Phased Implementation Plan

### Phase 1 — Baseline Scan on PRs (✅ Implemented)

**Goal:** Get ZAP running in CI with zero false positives blocking work.

**Deliverables:**
- `.github/workflows/zap-baseline.yml` — triggers on PRs touching PHP or REST code, spins up the QA Docker stack, runs ZAP baseline (passive only), uploads HTML/JSON reports, posts a PR comment with alert summary.
- Advisory mode: `continue-on-error: true`, findings as workflow annotations.

**Trigger:** PRs with changes to `**.php`, `includes/rest/**`, `addons/*/includes/rest/**`.

**CI cost:** ~8 minutes of `ubuntu-latest` (3 min Docker startup + 3–5 min ZAP scan).

### Phase 2 — Rules Baseline (Week 2–3)

**Goal:** Triage Phase 1 findings, create a version-controlled rules file.

**Deliverables:**
- `.zap-rules.tsv` — maps ZAP rule IDs to actions (`IGNORE`, `WARN`, `FAIL`) with documented justifications.
- WordPress-specific ignores: headers managed by core/server (`X-Content-Type-Options`, `Server`), XML-RPC (WordPress core concern), CSP (plugin doesn't control server config).

**Process:** After 1–2 weeks of PR scans, security reviewer audits every alert category, decides which are false positives vs. real findings, and commits the rules file.

### Phase 3 — Full Active Scan (Week 3–4)

**Goal:** Run active attacks (SQLi, XSS, command injection payloads) on a schedule.

**Deliverables:**
- `.github/workflows/zap-full-scan.yml` — weekly cron (Sunday 02:00 UTC) + manual dispatch. Can target a staging URL or spin up Docker locally.
- Active spider (crawls all discovered pages) + active scan (sends real attack payloads).
- GitHub Issue auto-creation for Critical/High findings.

**⚠️ Warning:** Full scans send real attack payloads. Must never target production. Default target is the ephemeral Docker environment; staging URL requires a repository variable.

### Phase 4 — API-Specific Scan (Week 4–6)

**Goal:** Targeted scanning of the REST API surface using an OpenAPI specification.

**Deliverables:**
- `.github/workflows/zap-api-scan.yml` — triggers on PRs touching REST controller files.
- OpenAPI 3.0 spec generation from WordPress registered routes (script introspects `rest_get_server()->get_routes()`).
- Uses `zaproxy/action-api-scan` with the generated OpenAPI document.

**Prerequisite:** The plugin has no existing OpenAPI spec. This phase includes building a generator script or manually curating a spec for critical endpoints.

### Phase 5 — Hardened Gates (Week 6+)

**Goal:** Make ZAP a blocking gate in the release pipeline.

**Changes:**
- All scan workflows: `continue-on-error: false` for High/Critical.
- `release.yml`: `deploy-wporg` job gains `needs: [build, plugin-check, zap-full-scan]`.
- `post-deploy-health.yml`: optionally runs ZAP baseline against live production URL after deployment.
- Security badge/report in repo README.

---

## Authentication Strategy

ZAP must authenticate to test protected endpoints. Strategy per auth method:

| Auth Method | ZAP Approach |
|---|---|
| **WordPress nonce** (`X-WP-Nonce`) | Pre-flight script hits `/wp-admin/admin-ajax.php?action=rest-nonce`, extracts nonce, injects via ZAP replacer rule as `X-WP-Nonce` header |
| **Bearer token** (assistant credentials) | Test credential generated in setup step, passed as `Authorization: Bearer <token>` header via ZAP context |
| **Guest token** (`X-WP-MCP-AI-Guest`) | Generated in setup, injected as custom header via replacer |
| **Auth0 JWT** | Too complex for automated CI — covered via manual pentest |

**Implementation pattern** (in CI setup step, before ZAP scan):

```bash
# Generate a test assistant + credential for authenticated scanning
ASSISTANT_ID=$(wp post create --post_type=mcp_ai_assistant \
  --post_title="ZAP Test Assistant" --post_status=publish --porcelain)
CREDENTIAL=$(wp eval "
  \$cred = WP_MCP_AI_Assistant_Credential_Manager::generate_credential($ASSISTANT_ID);
  echo \$cred['token'];
")
echo "ZAP_AUTH_TOKEN=${CREDENTIAL}" >> $GITHUB_ENV

# ZAP scan with auth header
docker run --network host ghcr.io/zaproxy/zaproxy:stable \
  zap-baseline.py -t http://localhost:8000 \
  -z "-config replacer.full_list\(0\).description=auth \
       -config replacer.full_list\(0\).enabled=true \
       -config replacer.full_list\(0\).matchtype=REQ_HEADER \
       -config replacer.full_list\(0\).matchregex=false \
       -config replacer.full_list\(0\).matchstring=Authorization \
       -config replacer.full_list\(0\).replacement=Bearer\ ${CREDENTIAL}"
```

---

## Integration with Existing Workflows

### Release Pipeline (Pre-Deploy Gate — Phase 5)

In `release.yml`, the `deploy-wporg` job:

```yaml
deploy-wporg:
  needs: [build, plugin-check, zap-full-scan]  # Add zap-full-scan
```

This ensures no release ships without a passing ZAP full scan.

### Post-Deploy Health Check (Phase 5)

Extend `post-deploy-health.yml` to optionally run a ZAP baseline scan against the live production/staging URL. Catches environment-specific misconfigurations (e.g., missing security headers due to reverse proxy).

### Security Regression Guards (Complementary)

The existing `security-regression.yml` statically prevents `__return_true` permission_callbacks. ZAP dynamically verifies that unauthenticated requests to protected endpoints return `401/403` — defense in depth.

---

## Files Created / Modified

| File | Phase | Purpose |
|---|---|---|
| `.github/workflows/zap-baseline.yml` | 1 | ZAP baseline (passive) scan on PRs |
| `.zap-rules.tsv` | 2 | Per-rule alert action configuration |
| `docs/operations/security/ZAP_DAST.md` | 1 | Operational reference documentation |
| `docs/project/proposals/OWASP-ZAP-DAST-INTEGRATION-PROPOSAL.md` | 1 | This proposal |
| `.github/workflows/zap-full-scan.yml` | 3 | ZAP full (active) scan, weekly cron |
| `.github/workflows/zap-api-scan.yml` | 4 | ZAP API scan with OpenAPI spec |
| `bin/generate-openapi-spec.php` | 4 | OpenAPI spec generator from WP REST routes |
| `.github/workflows/release.yml` | 5 | Add `zap-full-scan` to `deploy-wporg` needs |
| `.github/workflows/post-deploy-health.yml` | 5 | Optional ZAP scan on live URL |

---

## Estimated CI Costs & Timing

| Scan Type | Docker Startup | Scan Duration | Total | GitHub Actions Cost |
|---|---|---|---|---|
| Baseline | ~3 min | ~3–5 min | ~8 min | Free tier (public repo) |
| Full | ~3 min | ~15–30 min | ~25 min | Free tier |
| API | ~3 min | ~5–10 min | ~10 min | Free tier |

All scans are well within the GitHub Actions free tier for public repositories (2,000 minutes/month for `ubuntu-latest`).

---

## Risk Assessment

| Risk | Mitigation |
|---|---|
| ZAP baseline flags WordPress core behaviors as plugin issues | `.zap-rules.tsv` with documented IGNORE rules for core-managed headers/behaviors |
| Full scan sends attack payloads that modify test data | Full scan runs on ephemeral Docker environment only, never production |
| CI time increase | Baseline scan only on PRs touching PHP/REST code; full scan on cron only |
| False positives block PRs prematurely | Phase 1–2 are advisory only; gates become blocking only after rules baseline is stable |
| Docker stack startup flakiness | Same stack proven reliable in `qa-e2e.yml` Playwright workflow; health checks with 120s timeout |

---

## Success Metrics

- **Phase 1 (Week 2):** ZAP baseline runs successfully on ≥90% of PRs without flaky failures.
- **Phase 2 (Week 3):** `.zap-rules.tsv` committed with ≤5 IGNORE rules requiring justification.
- **Phase 3 (Week 4):** First full scan completes; ≤10 Critical/High findings (tracked as GitHub Issues).
- **Phase 5 (Week 8):** ZAP blocks at least one real vulnerability from reaching `main`. Zero ZAP-related false-positive build failures.

---

## References

- [OWASP ZAP Official Documentation](https://www.zaproxy.org/docs/)
- [ZAP GitHub Actions — Baseline Scan](https://github.com/zaproxy/action-baseline)
- [ZAP GitHub Actions — Full Scan](https://github.com/zaproxy/action-full-scan)
- [ZAP GitHub Actions — API Scan](https://github.com/zaproxy/action-api-scan)
- [ZAP Docker Images](https://www.zaproxy.org/docs/docker/)
- [OWASP Top 10 (2021)](https://owasp.org/www-project-top-ten/)
- [WordPress REST API Security Best Practices](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/)
