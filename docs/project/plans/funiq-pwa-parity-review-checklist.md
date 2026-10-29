# Funiq Parity Review — Checklist & Decision Gate

Operationalizes Proposal 092 **Phase 3 — Retire the licensed leg** (pending
owner parity review) and the research in
`docs/project/proposals/085-pwa-parity-rollback-research-notes.md`.
Owner decision Q1 (092 §7, 2026-10-29): keep A/B until this review passes.

> **Baseline corrected 2026-10-29.** The Envato item "Funiq — Furniture
> Store App with Payload CMS" contains **no web PWA**. Its licensed source
> (private repo `nvoos-funiq-pwa`) was forensically mapped: the public
> surface to compare is (a) the **React Native app's API contract** (9 GET
> endpoints against a Payload envelope) and (b) the **Payload admin** — the
> licensed React/Next backend — now deployable as the governed rollback leg
> (`examples/funiq-bridge-velocity/`). §1–§2 below replace the earlier
> "CSR SPA, live URL unknown" baseline entirely.

## 0. How to use this document

- **§2** is the parity matrix: the app's *actual* contract vs. the bridge
  (WP backend) vs. the Astro storefront vs. the hosted Payload leg.
- **§3–§5** are the industry-standard non-negotiables: parity is features
  **plus** SEO/a11y/cache/ops, with a rehearsed rollback and a documented
  go/no-go.
- **§6** is filled in by the owner at the review — named owner, timestamp,
  verdict. This is the single artifact that flips 092 Phase 3 from
  "pending" to "retire" (or "hold").

**Sign-off:**

| Field | Value |
|---|---|
| Reviewer (owner) | |
| Date | |
| Verdict | ☐ RETIRE LICENSED LEG · ☐ HOLD (gaps listed in §6) |
| Supersedes | 092 §7 Q1 (keep A/B) |

## 1. Baseline — what the licensed item actually is (verified 2026-10-29)

Source: `nvoos-funiq-pwa` (private). Evidence paths below; no licensed
bytes are reproduced in this public doc.

| Fact | Evidence (private repo) |
|---|---|
| Mobile app: **React Native 0.82**, Android + iOS only — no web target | `funiq/package.json` (scripts: android/ios/start, no web) |
| CMS: **Payload 3.64** on **Next.js 15.4**, admin at `/admin`, REST at `/api/*`, GraphQL at `/api/graphql`, Postgres adapter (`DATABASE_URI`) | `payload-cms/funiq-cms/src/payload.config.ts`, `package.json` |
| Collections: brands, categories, colors, media, products, promocodes, promotions, statuses, users (auth) + globals banner/carousel | `payload-cms/funiq-cms/src/collections/` |
| **No `orders` collection exists** in the CMS | collection list above |

**The app's complete network surface** (`funiq/src/`):

- `services/` is the only place axios is used — nine endpoints, all GET:
  `/api/brands`, `/api/colors`, `/api/statuses`, `/api/categories`,
  `/api/products`, `/api/promocodes`, `/api/globals/banner`,
  `/api/globals/carousel` (`config/index.tsx`).
- Everything else is **client-side only**: orders, reviews, notifications,
  sign-in/sign-up/forgot-password screens are Redux/mock UI with no network
  calls. `PLACE_ORDER` (`/api/orders`) is a **dead constant** — never
  referenced outside `config/index.tsx`, and the CMS has no orders
  collection to receive it.
- Envelope: standard Payload list shape; the app reads
  `response.data.docs` (and `totalDocs` exists but is unused).
- Fetch failures fall back to bundled sample data (`constants/data.tsx`),
  so an unreachable API renders the demo catalog — this must be understood
  when "verifying" the app visually.

**Consequences for parity scope:** the "authenticated flows out of scope"
note from the original baseline becomes stronger — the app *itself* never
authenticates against any backend. Parity = the nine-endpoint read
contract, served correctly by the bridge (for the Astro storefront), and
natively by the hosted Payload leg (for the app). There is no live web URL
to verify against; the hosted leg is the verifiable stand-in (§2.1).

## 2. Parity matrix

Status legend: ✅ verified (smoke or forensics) · ⚠️ partial/drift noted ·
❌ missing · **N/A** not applicable.

### 2.1 Endpoint matrix

| Endpoint (app contract) | Payload native | Bridge (WP) | Astro storefront | Notes |
|---|---|---|---|---|
| `GET /api/products` | ✅ (200 `{docs,totalDocs}` — smoke) | ✅ `ProductsController` | ✅ via `nvoos-commerce/v1` | Single list endpoint; the app has no by-ID fetch (§2.3 Q2) |
| `GET /api/categories` | ✅ (smoke) | ✅ `CategoriesController` | ✅ | Payload afterRead computes `productsCount` + stringifies `image` — bridge mirrors both |
| `GET /api/brands` | ✅ (smoke) | ✅ | ✅ | `{id, name}` both sides |
| `GET /api/colors` | ✅ (smoke) | ✅ | ✅ | `{id, name, hexCode}` both sides |
| `GET /api/statuses` | ✅ (smoke) | ✅ | ✅ | `{id, name}` both sides |
| `GET /api/promocodes` | ✅ (smoke) | ✅ | ✅ | Field-name drift: bridge emits `isActive`, Payload emits `active` (§2.2) |
| `GET /api/globals/banner` | ✅ (smoke) | ✅ `BannerController` | ✅ | `{image, promotion}` |
| `GET /api/globals/carousel` | ✅ (smoke) | ✅ `CarouselController` | ✅ | Array items `{image, promotion}` |
| `GET /api/promotions` | ✅ (Payload collection; app never calls it directly) | ✅ `PromotionsController` | ✅ `/promotions/` | Surfaced via product/banner/carousel refs |
| `POST /api/orders` | ❌ **no orders collection** | ❌ no orders controller | N/A | Dead constant in the app — no consumer exists on any side |

### 2.2 Field-level contract (transformers vs. Payload afterRead)

| Field | Payload | Bridge | Verdict |
|---|---|---|---|
| product `image` | afterRead: upload object → **URL string** | absolute URL string | ✅ (shape equal; absolute URLs on both) |
| product `images` | array items → **URL-string array** | URL-string array | ✅ |
| product `category` | full doc `{id, name, image, productsCount}` | `{id, name}` | ⚠️ app only reads `category.name` from products (grep: one site) — cosmetic gap; align later |
| product `rating` | number 0–5, default 0 | float or null | ✅ (app type is `number \| null`) |
| product `promotion` | full doc | full doc | ✅ |
| product `statuses`, `colors`, `brand` | `{id, name[, hexCode]}` arrays | identical | ✅ |
| product extras `imageId`/`imagesIds` | absent | present (admin SPA raw IDs) | ⚠️ deliberate admin extension; app ignores |
| promocode `expiresAt` | afterRead → `"Jun 30, 2026"` (en-US) | `gmdate('M j, Y')` — same format | ✅ |
| promocode `active` | `active` (checkbox) | `isActive` + extras `name`/`title`/`logo` | ⚠️ app reads neither — align to `active` for byte-level parity when the bridge admin SPA consumers allow |
| category `image` / `productsCount` | afterRead: URL string + counted | identical | ✅ |
| Payload `createdAt`/`updatedAt` | on products/promocodes (timestamps) | bridge emits `createdAt`/`updatedAt` on products | ✅ |

### 2.3 App-side quirks (must be known before the app is re-pointed anywhere)

| # | Quirk | Impact / owner action |
|---|---|---|
| Q1 | `useGetProducts` prefixes `MAIN_URL` onto `product.image` **unconditionally**; `useGetProductByID` guards with an `http` check | Against any backend emitting absolute URLs (Payload **and** bridge), list images break; the by-ID path is correct. Fix the list hook when the app is rebuilt in the private repo (one line, reuse the by-ID guard). |
| Q2 | "Product by ID" fetches the full product list and finds client-side | No `/api/products/{id}` call exists; bridge single-product route is extra surface — keep, harmless |
| Q3 | `PLACE_ORDER` defined, never called; auth/reviews/notifications screens are mock UI | No backend work needed for those flows on the Payload leg |
| Q4 | Fetch failure silently falls back to bundled sample data | Visual "verification" of the app can pass against a dead backend — API-level checks (smoke) are the real gate |
| Q5 | Payload `products` collection allows **public create/update** (`access: {create, update} → true`) | The licensed default is open-write. Before the hosted leg carries real data: restrict write access to authenticated users (one-line access change in the private repo) — recorded as a hardening item, not a parity gap |

### 2.4 Feature rows (replaces the original P1–P10)

| # | Surface | Astro storefront | Payload leg (hosted admin) | Owner verification |
|---|---|---|---|---|
| P1 | Catalog browsing (PLP) | ✅ | ✅ (app consumes `/api/products`) | Astro `/` vs. app Home against the same bridge data |
| P2 | Product detail (PDP) | ✅ prerendered + JSON-LD | ✅ (app Product screen) | Promo + bestseller flags on both |
| P3 | Category/brand/color filtering | ✅ server-side `where`/`sort` | ✅ (app Filter — client-side over the list) | Filter each dimension |
| P4 | Promotions display | ✅ | ✅ (refs on products/banner/carousel) | Badge parity |
| P5 | Promocode display/input | ✅ `PromoCodeField` island | ✅ (app MyPromocodes reads `discount`/`expiresAt`) | One live code on both |
| P6 | Banner/carousel globals | ✅ | ✅ | Homepage parity |
| P7 | Cart | ✅ nanostores + localStorage | ✅ (app Redux-persist) | Persistence across reload/app restart |
| P8 | Account/checkout flows | ❌ by design (092 Q5: after v1) | ❌ (mock UI in the app) | Confirm the app's flows are non-functional against Payload too |
| P9 | Media | ⚠️ Astro image pipeline; seed images pending | ✅ uploads (local disk; S3 = open decision, README ops notes) | Upload one image in the Payload admin; verify app + Astro render |
| P10 | CMS admin | ✅ WP Funiq CMS via `/wp-admin` proxy + ManageLink | ✅ Payload admin at `/admin` (its own origin/DB) | Login to both consoles |

## 3. Hard gates — non-functional parity

- [ ] **SEO parity** (085 §2.3 criteria): canonical product URLs, real
      links crawlable with JS disabled, Product JSON-LD in initial HTML,
      sitemap + robots — Astro passes by construction. The licensed app is
      a **native app** (no SEO surface); the Payload admin is an internal
      console (`X-Robots-Tag: noindex` recommended on the leg). Owner
      confirms these are *accepted improvements*, not parity gaps.
- [ ] **Accessibility:** Lighthouse a11y ≥ 0.9 on Astro home + PDP (Phase 2
      gate). Payload admin a11y is stock Payload UI (recorded, not gated);
      the native app is out of web-a11y scope.
- [ ] **Performance:** Lighthouse perf ≥ 0.8, best-practices ≥ 0.9,
      SEO ≥ 0.95 on Astro (established gates). Payload leg: `/admin` +
      `/api/products` response times recorded from the smoke/baseline.
- [ ] **Cache behavior:** Astro gates unchanged. Payload leg: Payload's
      own headers recorded for `/api/*` (no-cache default for REST);
      admin assets are hashed `/_next/static/*`.

## 4. Hard gates — operational readiness

- [ ] **Rollback rehearsed:** with the owner present, redeploy the
      previous flat mirror commit on the Payload leg and confirm the app
      + admin still serve (the leg has no data migration risk — DB is
      external). Record the run here (date, steps, result). *(Industry
      requirement: rollback rehearsal precedes any retirement decision.)*
- [ ] **Leg reachability proven:** `/admin` login + one product read from
      `/api/products` on the hosted leg (the same checks as the CI smoke,
      run against the live origin).
- [ ] **Baseline recorded:** one week of Astro RUM/Lighthouse + bridge
      REST latency + Payload-leg `/api/products` latency captured.
- [ ] **Monitoring window:** post-retirement observation window defined
      (suggested: 2 weeks) with rollback triggers — e.g. LCP regression
      > 20%, 5xx rate > 1%, catalog endpoints erroring.

## 5. Business gates

- [ ] Storefront serves the seeded catalog for a shopper journey
      (browse → filter → PDP → promo code) — the parity walk.
- [ ] Owner confirms the Envato decision closes (085 §7 Q1): license-tier
      question + `LICENSE.txt` tier field (private repo) resolved before
      retirement, recorded in §6.

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

- [ ] Retire the `nvoos-funiq-velocity` Velocity app; archive the private
      repos `nvoos-funiq-pwa` / `nvoos-funiq-velocity` **with the license
      proof** (Envato purchase code must remain retrievable for audit).
- [ ] Archive `sync-nvoos-funiq-velocity.yml` and
      `examples/funiq-bridge-velocity/` — keep this checklist + the 085
      decision records for the audit trail.
- [ ] Record the retirement decision in
      `funiq-bridge-velocity-storefront-implementation.md`'s status
      section (ADR-style supersede entry).
- [ ] Archive operational knowledge (deploy runbook, smoke harness,
      license-boundary notes) before repo wind-down.

## 8. References

- `085-funiq-bridge-velocity-storefront-proposal.md` §2.3 (SEO), §3.5
  (deferred tracks), §7 (owner decisions)
- `092-astro-storefront-layer-proposal.md` §4 (phase table), §7 (Q1 keep
  A/B), §3.9 (end-state)
- `085-pwa-parity-rollback-research-notes.md` (sources for §3–§5 gates)
- `docs/operations/deployment/nvoos-astro-velocity-setup.md` (A/B routing)
- `docs/operations/deployment/nvoos-funiq-velocity-setup.md` (Payload leg
  deploy runbook)
- `examples/funiq-bridge-velocity/README.md` (mirror layout + local smoke)
- Private forensics: `nvoos-funiq-pwa` — `funiq/src/{config,services,screens,types}`,
  `payload-cms/funiq-cms/src/{payload.config.ts,collections/}`
