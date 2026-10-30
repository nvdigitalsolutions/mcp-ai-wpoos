# Research Notes — PWA Parity Review, Rollback Leg & Decision Records

Research backing for reviving the Proposal 085 plan's deferred items:
(1) the decision record that closes the "Phase 2 skipped" gap, (2) the
PWA-parity review that gates Proposal 092 Phase 3 (PWA retirement), and
(3) the governed A/B rollback leg (`examples/funiq-bridge-velocity/` +
`sync-nvoos-funiq-velocity.yml`). Collected 2026-10-29 via web research and
cross-checked against the repo's existing research sections (085 §2 Envato
licensing / BFF / SEO; 092 §2 Astro).

## 1. Architecture Decision Records — how to record the skip/supersede

- **Status lifecycle** (Fowler's ADR bliki, the de-facto standard):
  `proposed` → `accepted` → `superseded` (or `deprecated`), with a **link
  to the superseding record**. Status lives in a status line; the record
  body stays immutable.
- **Immutable records:** never rewrite an accepted ADR's content to reflect
  a later change — add a new record or a supersede marker. This is exactly
  the discipline missing from the 085 plan (the Phases 1–6 plan text must
  stay as written; the resolution goes in a new status section).
- **One decision per record, numbered chronologically** (ADR-0001…), with
  context/decision/consequences split (MADR-style) — the repo's own
  convention ("decision #15" in `fastapi-porting-implementation-plan.md`
  §18 and the 092 §7 owner-decision block) matches this pattern.
- **Record where the work happens:** discuss in the PR, record the outcome
  next to the plan it affects, and supersede — don't silently overwrite.

Sources: martinfowler.com/bliki/ArchitectureDecisionRecord.html;
github.com/architecture-decision-record/architecture-decision-record;
catio.tech, archyl.com ADR guides; Microsoft Well-Architected ADR guidance
(learn.microsoft.com); AWS Prescriptive Guidance ADR process.

## 2. Frontend migration parity & legacy sunset gates

- **Hard gates before retirement** (industry-consistent across migration
  playbooks): full functional parity of the public surface **plus**
  non-functional parity — SEO, accessibility (WCAG), cache behavior, RUM
  baselines, and admin operations. Parity is not just "pages render".
- **Baseline before switching:** instrument the legacy app's metrics (and
  traffic baselines) before any traffic moves; use shadow reads/passive
  comparison where the architecture allows (both storefronts already read
  the same bridge contract — the comparison is direct).
- **Explicit go/no-go cutover:** named owners, timestamps, and documented
  go/no-go gates; verify **business outcomes** after the switch, not only
  system health; retire legacy in stages and archive operational knowledge.
- **Rollback must be rehearsed** in production-like conditions before the
  first public traffic step; because both frontends share one backend,
  rollback never needs a data migration — this is our exact A/B design.
- **Cookie/header-based canary cohorts** are the standard mechanism for the
  traffic split (our `nvoos_front` cookie web-rule plan matches); typed
  contracts + telemetry are what make the cohort comparison meaningful.

Sources: deployed.cloud "Pragmatic Legacy Migration Checklist";
hashbyt.com "Modernize a Legacy Frontend Without Breaking It";
devoxsoftware.com SPA modernization patterns; migration-issue precedents
documenting parity/rollback/RUM gates as merge-blockers.

## 3. CI/CD for a licensed bundle in a public monorepo (rollback leg)

- **Private deploy mirror** is the established pattern for licensed/private
  artifacts flowing from a public repo: the public side ships only
  NV-authored files; the licensed bundle is cloned at CI time from a
  private repo and injected into the mirror tree (085 Phase 4 design —
  validated by this research).
- **GitHub Actions hardening checklist applied to the new workflow:**
  - least-privilege `permissions: contents: read` at the workflow level;
  - **secrets via `env:` only — never inlined into command arguments**
    (argv appears in process tables/logs; the old SA workflow's inline
    `x-access-token:…@github.com` URL form is the anti-pattern being
    retired here in favor of `http.extraheader` + env);
  - pin third-party actions (this workflow uses only first-party
    `actions/checkout@v4`, matching repo convention);
  - prefer OIDC/short-lived tokens where available (noted for the owner;
    repo-scoped PATs remain the pragmatic floor on this host);
  - enable secret scanning + push protection (GitHub-side, owner toggle).
- **License boundary enforced by CI:** the sync fails if the mirror target
  is not private (085 Phase 4's planned guard, implemented in the new
  workflow) — compliance as a gate, not a review hope.
- **Rollback mechanics:** flat one-commit-per-sync mirror history =
  one-click redeploy of the previous commit in Velocity (the SA/console
  precedent); deployment environments with protection rules are the
  additional layer the research recommends when Velocity-side approvals
  become available.

Sources: stingrai.io "GitHub Actions Security Checklist 2026";
github/awesome-copilot CI/CD best-practices instructions;
secure-pipelines.com "GitHub Actions Security: The Definitive Guide";
docs.github.com/actions/security/secure-use (OIDC, environments).

## 4. Repo-internal standards this work follows

- 085 §2 (Envato licensing — structural private-repo boundary; BFF
  same-origin session model; CSR-SEO constraint) and §3.4 compliance
  (UNLICENSED marker, THIRD_PARTY_NOTICES, no licensed bytes in public
  history).
- 092 §2 (Astro positioning/SEO evidence) + §7 Q1 (keep A/B until parity
  review) — the decision this parity checklist operationalizes.
- The SA/console Velocity precedent (`examples/schedule-anything-velocity`,
  `sync-nvoos-schedule-anything-velocity.yml`) — ported with deltas, not
  reinvented.

## 5. Licensed-item forensics & the Payload-admin repackage (2026-10-29)

Owner Phase 1 completed; the purchased item was mapped from source before
any bytes moved. Method: read the licensed source in the private repo,
extract the app's real network surface (axios call sites only), compare
each endpoint/field against the bridge transformers and the Payload
collection schemas, then validate the whole deploy design hermetically
(Docker Postgres 16 + real `next build` + `payload migrate` + boot + curl
of every public route) before writing any workflow code.

Findings (evidence paths are private-repo-relative; nothing quoted here):

- **No web PWA exists.** The app is React Native 0.82 (Android/iOS
  scripts only); the web-adjacent artifact is the Payload 3.64 CMS on
  Next.js 15. Deployable-on-Velocity = the Payload admin.
- **The app's entire network surface is nine GET endpoints**
  (brands/colors/statuses/categories/products/promocodes/banner/carousel);
  everything else (orders, auth, reviews, notifications) is client-side
  mock UI. `POST /api/orders` is a dead constant and the CMS has no orders
  collection. Parity scope therefore collapses to the read contract —
  which the bridge already emulates 1:1 (field-level table in the parity
  checklist §2.2).
- **Payload's own deploy mechanics, verified empirically:**
  `next build` needs no database (the template's frontend page is dynamic
  via `headers()`); the vendor config lacks `output: 'standalone'` (its
  Dockerfile comment says the flag must be added — a behaviour-equivalent
  replacement drops only an identity webpack passthrough); `payload
  migrate:create <name>` is fully non-interactive; production never
  auto-pushes schema; the standalone `server.js` serves `/admin`,
  `/api/*`, GraphQL and `/` against a migrated Postgres, and the exact
  `{docs, totalDocs}` envelope on `/api/products` — asserted by the new
  CI smoke (the same class of "route engages" gate the research prescribed
  for the old shell, upgraded from a 502 proxy probe to a real contract
  assertion).
- **App quirks recorded for the rebuild** (checklist §2.3): the product
  list hook prefixes `MAIN_URL` onto absolute image URLs (breaks against
  Payload AND the bridge; the by-ID hook already guards with an `http`
  check), "by ID" is a client-side find over the full list, fetch failures
  silently fall back to bundled sample data, and the licensed products
  collection ships with public create/update access.
- **Repo-pattern continuity:** the repackaged leg keeps the private-mirror
  + flat-history + CI-boundary-guard pattern from §3 unchanged — only the
  payload of the mirror changed (standalone build instead of `dist/`).

Sources: payloadcms.com/docs (production deployment, database migrations,
standalone/Docker guides); nextjs.org/docs (standalone output);
Postgres official image docs (health checks for CI service containers);
codecanyon item documentation bundled in the private repo.
