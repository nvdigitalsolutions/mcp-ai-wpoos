# IEEE 829 Test Plan — NV oOS WordPress Plugin

## 1. Test Plan Identifier

**ID:** `WP-MCP-AI-QA-TP-001`
**Version:** 1.0
**Date:** 2026-06-05

## 2. References

- [NV oOS Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [Project README](../../README.md)
- [Test Suite README](../README.md)
- [Security Posture](../../docs/operations/security/SECURITY_POSTURE.md)
- [REST API Documentation](../../docs/rest-api.md)
- [Tool Reference](../../docs/tool-reference.md)

## 3. Test Items

The following plugin subsystems are in scope for QA:

| Subsystem              | Description                                    | Test Cases Prefix |
|------------------------|------------------------------------------------|-------------------|
| Authentication         | Nonce, Bearer Token, Auth0, Guest Token        | `TC-AUTH-*`       |
| Chat UI                | Shortcode chat, Elementor widget, SSE stream   | `TC-CHAT-*`       |
| Tool Execution         | ~830 tools: execute(), sanitization, envelope  | `TC-TOOLS-*`      |
| REST API               | MCP endpoints, permission callbacks, schemas   | `TC-REST-*`       |
| Admin Dashboard        | Settings pages, sub-tabs, settings persistence | `TC-ADMIN-*`      |
| Security               | XSS, CSRF, SQLi, auth bypass, secrets handling | `TC-SECURITY-*`   |
| Elementor Integration  | Widget rendering, editor integration           | `TC-ELEMENTOR-*`  |
| Memory System          | CCT storage, retrieval, decay, contradictions  | `TC-MEMORY-*`     |

## 4. Features to Be Tested

### In Scope
- All REST API endpoints respond with correct HTTP status codes
- Chat UI: guest token flow, message send/receive, transcript persistence
- Tool execution: canonical envelope format, sanitisation gate compliance
- Admin settings: save, load, checkbox persistence, tab navigation
- Authentication: all three methods (nonce, bearer, Auth0)
- Security: nonce checks, capability checks, input sanitisation, output escaping
- Docker-based QA environment: reproducible setup/teardown

### Out of Scope (This Plan)
- Performance/load testing (separate plan)
- AI model response accuracy (stochastic — tested via `composer run test`)
- Third-party integration live API calls (mocked in unit tests)
- Accessibility audit (uses separate `spa-a11y.yml` workflow)

## 5. Approach

### Testing Levels

| Level          | Method                    | Environment    | Trigger            |
|----------------|---------------------------|----------------|--------------------|
| Smoke          | Playwright + Docker       | CI (GitHub)    | Every PR           |
| Functional     | Playwright + Docker       | CI (GitHub)    | Every PR           |
| Manual         | Browser + Test Cases      | Docker / Local | Release candidate  |
| Visual         | Playwright screenshots    | CI (GitHub)    | Every PR           |
| Security       | Playwright + Manual audit | CI + Manual    | Every PR + Release |

### Test Design Techniques

- **Equivalence Partitioning**: Group inputs by expected behavior (valid/invalid auth tokens, role levels)
- **Boundary Value Analysis**: Test edge cases (empty inputs, max-length strings, invalid JSON)
- **State Transition**: Chat session lifecycle (new → active → transcript saved → expired)
- **Error Guessing**: Based on known WordPress plugin vulnerability patterns

### Pass/Fail Criteria

- **Pass**: All steps produce expected results; trace/video show correct flow
- **Fail**: Any step produces unexpected result, error, or timeout
- **Blocked**: Preconditions cannot be met (environment issue)

## 6. Suspension & Resumption Criteria

- **Suspend**: If >50% of tests fail due to environment issue (e.g., Docker unavailable)
- **Resume**: After environment fix confirmed by smoke test pass

## 7. Test Deliverables

| Deliverable              | Location                                    |
|--------------------------|---------------------------------------------|
| Test Plan                | `tests/qa/test-plan.md`                     |
| Test Cases               | `tests/qa/test-cases/*.md`                  |
| Automated Test Scripts   | `tests/qa/playwright/tests/*.spec.ts`       |
| Test Reports (CI)        | GitHub Actions Artifacts                    |
| Trace/Videos (CI)        | GitHub Actions Artifacts                    |
| Bug Reports              | GitHub Issues                               |

## 8. Environmental Needs

### Docker QA Stack (Preferred)

```
WordPress 6.9 + PHP 8.2  (wordpress:6.9-php8.2-apache)
MySQL 8.0                 (mysql:8.0)
Playwright v1.60          (mcr.microsoft.com/playwright:v1.60.0-noble)
WP-CLI                    (wordpress:cli-php8.2)
```

### Manual QA Environment

- Modern browser (Chrome/Firefox/Edge latest)
- Docker Desktop or local WP install
- Admin credentials: `admin` / `password`

## 9. Staffing & Training Needs

- QA Engineer (GitHub Custom Agent `nv-oos-qa-engineer`) performs per-story validation
- Developers write test cases alongside feature code
- Playwright knowledge required for test script maintenance

## 10. Schedule

| Milestone                  | Target Date  | Deliverable                           |
|----------------------------|-------------|---------------------------------------|
| Phase 0: Foundation        | 2026-06-05  | Test plan + QA directory structure    |
| Phase 1: Manual Test Cases | 2026-06-10  | 20–30 test case documents            |
| Phase 2: Docker QA Stack   | 2026-06-10  | docker-compose.qa.yml + Dockerfile    |
| Phase 3: Playwright Setup  | 2026-06-12  | playwright.config.ts + fixtures       |
| Phase 4: Automated Tests   | 2026-06-20  | 15+ automated test specs              |
| Phase 5: CI Integration    | 2026-06-22  | qa-e2e.yml GitHub Actions workflow    |
| Phase 6: Visual Regression | 2026-06-26  | Screenshot baselines + diff           |

## 11. Risks & Contingencies

| Risk                               | Probability | Impact | Mitigation                            |
|------------------------------------|------------|--------|---------------------------------------|
| Docker Hub rate limiting in CI     | Medium     | High   | Cache images; use GHCR mirrors        |
| Flaky tests due to async UI        | High       | Medium | Playwright auto-wait; retry on failure |
| Test data drift over releases      | Medium     | Medium | Seed data via WP-CLI in setup step     |
| Playwright image OS mismatch       | Low        | Medium | Pin exact image tag (`noble`)         |
| WordPress 6.9 API changes          | Low        | High   | Version-pin WP image; test pre-upgrade |

## 12. Approvals

| Role           | Name                     | Signature | Date |
|----------------|--------------------------|-----------|------|
| QA Lead        | Quinn (QA Engineer Agent)|           |      |
| Tech Lead      |                          |           |      |
| Release Manager|                          |           |      |
