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
