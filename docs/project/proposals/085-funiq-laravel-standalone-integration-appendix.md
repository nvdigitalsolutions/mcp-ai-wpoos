# Appendix to Proposal 085 — Funiq Standalone: Laravel Port of the API & the NV oOS Merge

**Status:** Analysis appendix (not a build plan) · **Date:** 2026-10-21
**Related:** Proposal 085, `lib/core/` (nvoos/core), `lib/laravel-adapter/`
(nvoos/laravel-adapter), `docs/project/proposals/laravel-scale-deployment-architecture.md`,
`docs/project/proposals/fastapi-porting-implementation-plan.md`,
`docs/project/proposals/cross-platform-extraction-architecture.md`

## 1. What already exists (verified repo state)

The "take the API out of WordPress" idea is not new to this repo — it is
the **cross-platform extraction thread**, and its foundations are shipped:

| Asset | State | Notes |
|---|---|---|
| `lib/core/` → package **`nvoos/core`** (MIT, public mirror `nvoos-core`) | ✅ Shipped | Framework-agnostic engine: domain contracts, `ChatOrchestrator`, `ProviderRouter`, 109 tools; zero WP dependencies |
| `lib/laravel-adapter/` → package **`nvoos/laravel-adapter`** (MIT, public mirror `nvoos-laravel-adapter`) | ✅ All 8 adapter implementations complete | `src/Adapter/` (`ErrorFactory`, etc.) + `config/` + `database/` + tests; consumes `nvoos/core` contracts (`ContentStoreInterface` → "wraps Eloquent models", `QueueClientInterface` → "wraps Laravel Queues", `FileStoreInterface` → Flysystem, `HttpClientInterface` → Http facade) |
| `lib/craft-adapter/` + `lib/wordpress-adapter/` | ✅ Complete | Sibling adapters (same contracts) |
| `laravel-scale-deployment-architecture.md` | 📋 Proposal (2026-07-01) | Central **Laravel Octane (FrankenPHP)** orchestrator + Redis/**Horizon** + **Reverb** WebSockets + PostgreSQL/**pgvector** (HNSW) + federated mesh routing; Strangler Fig migration (§5); 16-week plan. Status: proposal — **no production Octane deployment exists yet** |
| `fastapi-porting-implementation-plan.md` | 📋 Alternative | Python/FastAPI re-implementation of the same hexagonal pattern |
| Repo priority list | — | "Laravel adapter spike for v1.4.0" is a stated next priority (`PROPOSALS_COMPLETION_STATUS.md`) |
| Cloudways platform | ✅ Supported | `phplaravel` app type (v10.11.0) exists in the Cloudways server-create tools — Laravel deploys to a **PHP server**; Velocity is Node-only |

**Reading this correctly:** the adapter layer is ready, the deployment
architecture is designed, but the Laravel track has not been exercised in
production. The Funiq standalone system would be **the first production
consumer of `nvoos/laravel-adapter`** — a flag, not a blocker.

## 2. What "taking the API out of WP" concretely means

The invariant that makes this a clean port: **the Funiq PWA only knows the
Payload-shaped JSON contract** (`{ docs, totalDocs, limit, totalPages,
page, hasNextPage, hasPrevPage }` under `/api/*`). Whoever serves that
contract — WP REST today, Laravel tomorrow — is invisible to the
storefront. Everything else is a mechanical mapping:

| funiq-bridge component (WP) | Standalone Laravel equivalent | Effort |
|---|---|---|
| `includes/REST/BaseController.php` (Payload pagination envelope) | Plain service/trait — the envelope is pure PHP, portable **as-is** | Trivial |
| 9 collection controllers (`Products`, `Categories`, `Brands`, `Colors`, `Statuses`, `Promotions`, `Promocodes`, `Banner`, `Carousel`) | Laravel routes + controllers + **API Resources**; same paths (`/api/products`, `/api/globals/banner`, …) | Medium |
| CPTs `funiq_product` / `funiq_promotion` / `funiq_promocode` | Eloquent models + migrations (`products`, `promotions`, `promocodes`) | Medium |
| Taxonomies (`funiq_category`, `funiq_brand`, `funiq_color`, `funiq_status`) | Tables + models: `belongsTo` (category/brand), `belongsToMany` (colors/statuses) | Medium |
| Untyped post meta (`_funiq_price`, `_funiq_gallery`, dimensions, flags) | Typed columns: `decimal`, `jsonb`/`json`, `boolean` — the ETL is also the **typing pass** | Medium |
| `includes/Transformers/` (post → Payload shape) | API Resources (1:1 port) | Low |
| `includes/Schema.php` | App config + model constants | Low |
| Media: attachment IDs + `wp.media` picker in the admin | Flysystem (S3/local) + `media` table; admin picker becomes a REST upload UI | **High** (biggest UI delta) |
| Auth: `manage_funiq` cap + `wp_rest` nonce + `wp_localize_script` | Laravel **Gates/Policies** + session cookies (Fortify) or **Sanctum** (SPA cookie mode); the public GETs stay public | Medium |
| `uninstall.php` | `down()` migrations / tenant teardown | Low |
| Funiq CMS admin (wp-admin-embedded SPA) | The **deferred standalone admin** from Proposal 085 §3.5 — now served by Laravel instead of Velocity; consumes `/funiq/v1/auth/nonce` equivalent (Laravel session endpoint) | High (already deferred) |

## 3. Where NV oOS merges in

The merge is composer-level and contract-level:

1. **Packages:** `composer require nvoos/core nvoos/laravel-adapter` — both
   MIT, both public mirrors, no licensing friction with the Envato-private
   PWA (the packages are NV-authored open code; the PWA stays in the
   private repos regardless of backend).
2. **Engine wiring:** the Laravel app binds the 8 adapters (already
   implemented) in its service provider. `ChatOrchestrator` + providers +
   109 core tools run server-side with **zero WordPress**.
3. **The real merge seam — a Funiq `ContentStoreInterface`:** the domain
   contracts are how oOS reads and writes domain data. Implementing
   `ContentStoreInterface` (and `SchemaStoreInterface`) **over the Funiq
   Eloquent models** turns the commerce catalog into a first-class oOS
   data source: AI tools can list/search/create products, read promotions,
   and compose merchandising actions — "merging nvoos with funiq" at the
   code level.
4. **Unlocked product capabilities** (from the Laravel-scale architecture):
   - **pgvector** → semantic product search + recommendations (the
     storefront's `/api/products?where=…` grows a vector dimension).
   - **Horizon/Redis queues** → background jobs (image processing, report
     generation, sync tasks) with guaranteed execution — replaces WP-Cron.
   - **Reverb WebSockets** → the SSE→WebSocket upgrade path for assistant
     chat inside the future standalone admin.
   - **Octane (FrankenPHP)** → 3–10× throughput vs PHP-FPM for the API
     surface; persistent boot holds the oOS engine warm.
   - **AI merchandising/admin assistant** → the "Funiq CMS + AI" product
     story: research-a-product, generate copy, plan promotions — the same
     tool families that exist today in WP, executing in the standalone app.

## 4. Target topologies (three options, in escalating order)

| | T1 — Velocity BFF stays, backend flips | T2 — Converged Laravel app | T3 — Federated mesh (repo's end-state) |
|---|---|---|---|
| Shape | `serve.mjs` proxies `/api/*` and auth routes to a **Laravel origin** instead of WP; PWA still on Velocity | Laravel serves the PWA from `public/` (or a static asset build); **no Velocity** | Laravel orchestrator + WP/Graphify content peers + funiq commerce node on the mesh |
| WP's role | Stays for wp-admin/CMS during transition | Retired for this product (or kept as content peer) | Federated peer (federation driver) |
| Effort | Lowest (env/config change + auth-route re-scoping) | Medium (nginx caching/HSTS replicated; private-repo deploy to a PHP server) | High (the 16-week Laravel-scale plan) |
| Matches which proposal | Prop 085 §3 + this appendix | `laravel-scale-deployment-architecture.md` §3–§5 | `laravel-scale-deployment-architecture.md` §4.6–§4.8 |
| Envato boundary | Unchanged: PWA bundle private | Unchanged: PWA in private repo, deployed to the PHP app | Unchanged |

**Recommendation: T1 first, T2 as the standalone end-state, T3 as the
org-level horizon.** T1 is a one-week flip that derisks the Laravel
service behind a proven front door; T2 consolidates; T3 only pays off when
multiple federated nodes exist.

## 5. Migration — the repo's own doctrine (Strangler Fig)

`cross-platform-extraction-architecture.md` §2.5 and the Laravel-scale doc
§5 both mandate the Strangler Fig: **build alongside, route gradually,
retire the legacy**. Mapped to Funiq:

1. **Contract freeze:** capture the Payload envelope as golden fixtures
   (reuse the bridge's REST tests as the contract suite for the new
   service).
2. **Build the Laravel funiq service** in parallel (private repos for the
   PWA, NV-authored service code public per the owner's preference).
3. **Route-flip by verb:** public `GET` collection routes flip first
   (highest traffic, lowest risk — no auth); writes (`POST`/`PUT`/`DELETE`
   under `manage_funiq`) flip once the admin points at the new service.
4. **Data ETL:** one-time migration WP → PostgreSQL/MySQL with typing
   (post meta is untyped — cast `decimal`/`jsonb`/`boolean` at extraction);
   optional dual-write window for promotions (time-sensitive data).
5. **Admin moves off wp-admin** (standalone admin, deferred in Prop 085)
   — this retires the `wp.media` dependency and the wp-admin embed.
6. **Retire the bridge plugin** from WP; keep or drop WP per the T1/T2
   choice.

## 6. Risks specific to this track

| Risk | Impact | Mitigation |
|---|---|---|
| `nvoos/laravel-adapter` has no production track record (Octane/Horizon/Reverb wiring still a proposal) | High | Run the stated **v1.4.0 Laravel adapter spike** before committing Funiq; T1 keeps WP reachable as instant rollback |
| Auth-model mismatch (wp_rest nonce/cookie vs Laravel session) through the proxy | Medium | T1 keeps the same-origin cookie model; `serve.mjs` login re-scoping is WP-specific and needs a Laravel login-route equivalent or a session endpoint |
| `wp.media` replacement in the admin | Medium | Deferred with the standalone admin; REST upload endpoint + simple picker v1 |
| ETL fidelity (untyped meta, gallery arrays, term relations) | Medium | Golden-contract diffing: compare old vs new API responses record-by-record during migration |
| Streaming swap (SSE → Reverb) if AI chat ships in the admin | Medium | Ship chat after Reverb is wired; plain REST chat acceptable for v1 |
| Scope creep — this is a full product port, not a bridge tweak | High | Sequence strictly: Prop 085 v1 (WP backend) → T1 flip → nvoos merge → admin migration; each stage is independently shippable |
| FastAPI alternative remains open | Low → **Resolved 2026-10-25** | Owner confirmed **Laravel** (decision #15, `fastapi-porting-implementation-plan.md` §18). The Python track stays as the documented fallback; flip conditions in `090-laravel-adapter-spike-implementation-plan.md` §8 |

## 7. Roadmap — how this slots after Proposal 085

```
Prop 085 v1 (now)          Velocity storefront + WP bridge backend
        │
Phase B (T1)               Laravel funiq service behind serve.mjs — GETs flip first
        │                  (spike first: nvoos/laravel-adapter on Octane in dev)
Phase C                    NV oOS merge: composer packages, Funiq ContentStore
        │                  adapter, pgvector search, Horizon jobs, AI merchandising
Phase D (T2)               Standalone admin + retire wp-admin embed; WP optional
        │
Phase E (T3, org horizon)  Federated mesh per laravel-scale-deployment-architecture
```

Each arrow is gated by the previous stage's verification — the Strangler
Fig keeps every intermediate state production-safe and reversible.

## 8. References

- `lib/README.md`, `lib/core/`, `lib/laravel-adapter/` (verified: 8 adapter
  implementations, config/database/tests present; MIT; public mirrors)
- `docs/project/proposals/laravel-scale-deployment-architecture.md`
- `docs/project/proposals/cross-platform-extraction-architecture.md`
  (Strangler Fig §2.5, adapters §2.6)
- `docs/project/proposals/fastapi-porting-implementation-plan.md`
- `docs/project/proposals/PROPOSALS_COMPLETION_STATUS.md` (Laravel spike
  priority)
- `addons/funiq-bridge/` (README data-model map, REST surface,
  `includes/REST/`, `includes/Admin/AdminPage.php`)
- Proposal 085 + its implementation plan
