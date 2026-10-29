# Funiq PWA Parity Review — Checklist & Decision Gate

Operationalizes Proposal 092 **Phase 3 — Retire the PWA** (pending owner
parity review) and the research in
`docs/project/proposals/085-pwa-parity-rollback-research-notes.md`.
Owner decision Q1 (092 §7, 2026-10-29): keep A/B until this review passes.

## 0. How to use this document

- The **parity matrix** (§2) compares the PWA's *actual* public surface
  against the Astro storefront (`astro.nvoos.cloud`, Phases 0–2 complete).
- The **hard gates** (§3–§5) are the industry-standard non-negotiables:
  parity is features **plus** SEO/a11y/cache/ops, with a rehearsed
  rollback and a documented go/no-go.
- The **cutover record** (§6) is filled in by the owner at the review —
  named owner, timestamp, verdict. This is the single artifact that flips
  092 Phase 3 from "pending" to "retire" (or "hold").

**Sign-off:**

| Field | Value |
|---|---|
| Reviewer (owner) | |
| Date | |
| Verdict | ☐ RETIRE PWA · ☐ HOLD (gaps listed in §6) |
| Supersedes | 092 §7 Q1 (keep A/B) |

## 1. Baseline — what the PWA actually does against the bridge

The PWA is a CSR SPA built for Payload's API model. Against the bridge it
supports the **anonymous public surface**; its authenticated flows
(accounts/cart/checkout against Payload's `/api/users` JWT model) are
**out of parity scope by design** — the bridge never emulated them, so
they never functioned in production (085 §3.5, recorded non-goal). Parity
therefore compares the surfaces both frontends can actually serve.

> **Data gap:** the live PWA URL is not recorded in the repo. Owner action:
> note it here (`https://…`) and run §2 against it. If the PWA is not
> publicly reachable, mark the feature rows "not verifiable live" and the
> review cannot pass.

## 2. Parity matrix

Status legend: ✅ done · ⚠️ partial/needs verification · ❌ missing ·
**N/V** not verifiable live (blocks the review).

| # | Feature (PWA public surface) | Astro status | Verification (owner runs) |
|---|---|---|---|
| P1 | Home / catalog browsing (PLP) | ✅ | `curl /` + browse; compare product lists |
| P2 | Product detail page (PDP) | ✅ (prerendered + JSON-LD) | Browse N products incl. promo + bestseller flags |
| P3 | Category / brand / color filtering | ✅ (`where` + `sort` operators) | Filter a PLP by each dimension on both apps |
| P4 | Promotions display (sale badges, promo pages) | ✅ | `/promotions/` + PDP badge parity |
| P5 | Promocode validation (display + input) | ✅ (`PromoCodeField` island) | Apply a live code on both |
| P6 | Banner / carousel globals | ✅ (`globals/*`) | Homepage parity |
| P7 | Cart (client-side) | ✅ (nanostores + localStorage) | Add/remove/persist across reload on both |
| P8 | Account flows (login/register/cart checkout) | ❌ **by design** (092 §7 Q5: after v1) | Confirm PWA's flows are equally non-functional vs the bridge |
| P9 | Media (product images) | ⚠️ Astro image pipeline; live catalog currently image-less | Upload one image via Funiq CMS; verify both render |
| P10 | Funiq CMS admin (wp-admin, same-origin) | ✅ (`ManageLink` for managers) | Login → CMS on both origins |

## 3. Hard gates — non-functional parity

- [ ] **SEO parity** (085 §2.3 criteria): canonical product URLs, real
      links crawlable with JS disabled, structured data (Product JSON-LD)
      in initial HTML, sitemap + robots — Astro passes by construction;
      the PWA fails by construction (CSR). Owner confirms this is an
      *accepted improvement*, not a parity gap to replicate.
- [ ] **Accessibility:** Lighthouse a11y ≥ 0.9 on both home and PDP
      (Astro gate from the Phase 2 audits; PWA measured for the record —
      the bar for retirement is the Astro app's score).
- [ ] **Performance:** Lighthouse perf ≥ 0.8, best-practices ≥ 0.9,
      SEO ≥ 0.95 on the Astro app (established gates); PWA numbers
      recorded as the baseline being retired.
- [ ] **Cache behavior:** entry `no-cache`+ETag revalidation, hashed
      assets immutable, public REST `s-maxage` — verified on Astro
      (smoke gates); PWA cache headers recorded.

## 4. Hard gates — operational readiness

- [ ] **Rollback rehearsed:** with the owner present, flip the
      `nvoos_front` cookie web-rule to the PWA and back on the live
      domain; record the run here (date, steps, result). *(Industry
      requirement: rollback rehearsal precedes any retirement decision.)*
- [ ] **A/B split configured** via Velocity web rules (092 setup doc,
      A/B coexistence section) — not required to be left on, but the
      ability must be proven.
- [ ] **Baseline recorded:** one week of the Astro app's RUM/Lighthouse
      and the bridge REST latency baselines captured (any monitoring the
      team uses; the bridge's `X-Cache-Status` headers give cache hit
      visibility).
- [ ] **Monitoring window:** post-retirement observation window defined
      (suggested: 2 weeks) with rollback trigger criteria — e.g. LCP
      regression > 20%, 5xx rate > 1%, catalog pages returning errors.

## 5. Business gates

- [ ] Storefront serves the seeded catalog correctly for a shopper
      journey (browse → filter → PDP → promo code) — the parity walk.
- [ ] Owner confirms the Envato decision closes (085 §7 Q1): license
      tier question retired with the PWA, recorded in §6.

## 6. Cutover / go-no-go record (fill at the review)

| Field | Value |
|---|---|
| Verdict | |
| Owner | |
| Timestamp | |
| Gaps accepted (list §2 ⚠️/❌ rows with rationale) | |
| Rollback rehearsal reference (§4 row 1) | |
| Post-retirement actions (§7) assigned to | |

## 7. Post-retirement actions (after RETIRE)

- [ ] Remove the A/B web rules; retire the `nvoos-funiq-velocity`
      Velocity app and the private PWA repos (or archive with the
      license proof).
- [ ] Remove the licensed-bundle injection from
      `sync-nvoos-funiq-velocity.yml` or archive the workflow, and remove
      `examples/funiq-bridge-velocity/` (NV-authored rollback shell) —
      keep this checklist + the 085 decision record for the audit trail.
- [ ] Record the retirement decision in
      `funiq-bridge-velocity-storefront-implementation.md`'s status
      section (ADR-style supersede entry).
- [ ] Archive operational knowledge (deploy runbook, cache contracts,
      license-boundary notes) before repo wind-down.

## 8. References

- `085-funiq-bridge-velocity-storefront-proposal.md` §2.3 (SEO), §3.5
  (deferred tracks), §7 (owner decisions)
- `092-astro-storefront-layer-proposal.md` §4 (phase table), §7 (Q1 keep
  A/B), §3.9 (end-state)
- `085-pwa-parity-rollback-research-notes.md` (sources for §3–§5 gates)
- `docs/operations/deployment/nvoos-astro-velocity-setup.md` (A/B routing)
