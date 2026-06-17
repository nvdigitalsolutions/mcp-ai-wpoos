# NV oOS — Quality Assurance Framework

This directory contains the end-to-end QA framework for the NV oOS WordPress plugin.
QA is structured in three layers, from human-readable test cases to fully automated CI
pipelines.

## Layers

```
tests/qa/
├── README.md                      ← this file
├── test-plan.md                   ← IEEE 829 Test Plan (scope, approach, schedule)
├── test-cases/                    ← manual test cases (human-readable, machine-parseable)
│   ├── TC-AUTH-*.md
│   ├── TC-CHAT-*.md
│   ├── TC-TOOLS-*.md
│   ├── TC-REST-*.md
│   ├── TC-ADMIN-*.md
│   └── TC-SECURITY-*.md
├── docker/                        ← Docker Compose stack for isolated QA environment
│   ├── docker-compose.qa.yml
│   └── entrypoint.sh
├── playwright/                    ← Playwright browser automation
│   ├── playwright.config.ts
│   ├── package.json
│   ├── fixtures/                  ← reusable auth / page fixtures
│   ├── tests/                     ← automated test specs (.spec.ts)
│   └── utils/                     ← wp-helpers, visual-diff utilities
└── artifacts/                     ← generated output (gitignored)
    ├── videos/
    ├── traces/
    ├── screenshots/
    └── reports/
```

## Quick Start

### Option A: Automated QA (Docker — Preferred)

```bash
# Start the QA Docker stack (WordPress + Playwright)
docker compose -f tests/qa/docker/docker-compose.qa.yml up --abort-on-container-exit

# View test artifacts
ls tests/qa/artifacts/
npx playwright show-report tests/qa/artifacts/reports/

# Tear down
docker compose -f tests/qa/docker/docker-compose.qa.yml down -v
```

### Option B: Manual QA (Browser)

```bash
# Start only the WordPress service (no tests run)
docker compose up -d

# Open in browser
# → http://localhost:8000
# → http://localhost:8000/wp-admin  (admin / password)

# Follow manual test steps in test-cases/*.md
```

### Option C: Playwright Locally (Without Docker)

```bash
cd tests/qa/playwright
npm install

# Ensure WordPress is running at http://localhost:8000
npx playwright test --ui          # Interactive UI mode
npx playwright test               # Headless run
npx playwright test --trace on    # With trace recording
```

## Test Case Format

Every test case in `test-cases/` follows the IEEE 829-inspired template:

| Field            | Description                                          |
|------------------|------------------------------------------------------|
| Test Case ID     | Unique identifier (`TC-{MODULE}-{NNN}`)              |
| Feature          | What subsystem is under test                         |
| Priority         | P0 (Critical) → P3 (Low)                             |
| Type             | Functional / UI / Security / Regression / Smoke      |
| Preconditions    | Environment state required before test               |
| Steps            | Step-by-step actions with expected results           |
| Postconditions   | Environment state after test                         |
| Automation Ready | Playwright locators / API selectors identified       |

## Automation Readiness

Test cases include an **Automation Readiness** checklist at the bottom:

```markdown
### Automation Readiness
- [ ] Playwright locators identified: `[data-testid="..."]`
- [ ] API assertions: `page.waitForResponse(...)`
- [ ] Visual checkpoint: `page.screenshot()`
```

When all boxes are checked, the test case is ready to be implemented as a `.spec.ts` file.

## CI/CD Integration

The GitHub Actions workflow at `.github/workflows/qa-e2e.yml` runs on every PR that
touches PHP or JS files. It:

1. Spins up the QA Docker stack
2. Executes all Playwright tests with trace + video recording
3. Uploads artifacts (videos, traces, screenshots, reports) as workflow artifacts
4. Posts a summary comment on the PR

## Recording & Visual Review

Three artifact types are captured per test:

| Artifact   | Format   | Purpose                               |
|------------|----------|---------------------------------------|
| Video      | `.webm`  | Full test recording for human review  |
| Trace      | `.zip`   | Step-by-step replay in Trace Viewer   |
| Screenshot | `.png`   | Visual regression diff at checkpoints |
