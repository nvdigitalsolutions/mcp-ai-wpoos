# Proposal 085 — Funiq Bridge Velocity Storefront: Unified Velocity App with a Private Licensed-Content Mirror

**Status:** Proposal (open decisions in §7) · **Date:** 2026-10-21
**Related:** `addons/funiq-bridge` (proprietary), Proposal 083 (Complete Console Velocity app),
Proposal 072 (proprietary licensing research), PR #7166 (Console migration Phase 3b/4),
Proposal 081/082 (Schedule Anything SaaS), `examples/schedule-anything-velocity/`

## 1. Problem Statement

Funiq is a client-facing furniture storefront delivered as two pieces with
very different legal statuses:

1. **The Funiq storefront PWA** — a *purchased* CodeCanyon item
   ([Funiq – Furniture Store App with Payload CMS](https://codecanyon.net/item/funiq-furniture-store-app-with-payload-cms/60883645)),
   licensed under an Envato Market Regular License: NV Digital can *use and
   modify* it in one end product, but **cannot redistribute it** — not even
   modified.
2. **The Funiq Bridge** (`addons/funiq-bridge/`) — a WordPress plugin NV Digital
   *authored*: Payload-compatible REST at `/wp-json/funiq/v1/`, CPTs/taxonomies,
   and a React "Funiq CMS" admin SPA embedded in wp-admin. This is proprietary
   NV code and already lives in the public monorepo by design (Proposal 072's
   model: proprietary subtrees with local licenses inside a GPL repo).

Today the storefront is wired to WordPress via a `.htaccess` rewrite
(`/api/*` → `/wp-json/funiq/v1/*`) or a cross-origin config edit, and the
admin runs inside wp-admin. There is no unified, deployable storefront
surface, and no documented pipeline that respects the Envato license while
using the repo's proven Velocity deployment machinery.

This proposal defines a **single Cloudways Velocity app** serving the
storefront PWA and proxying WordPress (admin included), built on the
repo's two proven Velocity recipes, with the one structural change that the
Envato license forces: **the deploy repo containing the purchased bundle
must be private**, while all NV-authored code stays public.

## 2. Research & Industry Standards

### 2.1 Envato Market licensing — what "purchased" actually means

- Envato Market items are **licensed, not sold**. The Regular License grants
  use in **one end product** (the purchaser's own or one client's); it does
  not transfer copyright and does not grant redistribution rights.
- Redistribution is explicitly prohibited **even when modified**:
  "You're not allowed to share or sell the item you downloaded, even if
  you've made changes to it"
  ([WRTeam — Regular vs Extended License](https://www.wrteam.in/blog/envato-codecanyons-regular-license-vs-extended-license)).
  Publishing the item's source — or its compiled bundle — in a public
  repository is redistribution and breaches these terms. A **private**
  deployment repository used only to operate the purchaser's own end
  product is ordinary licensed use, not redistribution.
- **Regular vs Extended** is decided by whether the end product is
  **distributed free of charge or sold**: "the license you need will depend
  on whether the end product is distributed free of charge or sold or
  otherwise available free of charge — the number of end products won't
  make a difference"
  ([Envato Market — Which Licence Do I Need?](https://help.market.envato.com/hc/en-us/articles/115005593363-Which-Envato-Market-Licence-Do-I-Need)).
  If the Funiq storefront is itself a product sold to customers (or SaaS
  fees are charged for it), the **Extended License** is required. This is
  an owner decision — §7, Q1.

**Consequence:** the boundary is per-artifact, not per-product. NV-authored
files (the bridge plugin, the admin SPA, `serve.mjs`, the sync workflow)
may stay public; any file derived from the purchased item (its source, its
built bundle) may not appear in public git history — ever, because git
history is immutable and survives force-pushes via forks and clones.

### 2.2 Same-origin BFF proxy — the established session model

- The BFF pattern exists for exactly this shape: the proxy "proxies all
  requests to the API" and "the SPA only interacts with the backend, relying
  on traditional cookies for authenticated sessions" (Auth0,
  [BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/));
  serving SPA and API from one origin **eliminates CORS** and keeps tokens
  out of JavaScript (Duende,
  [BFF security framework](https://docs.duendesoftware.com/bff/)).
- The repo's `serve.mjs` (both Velocity apps) *is* this pattern — static
  serving + reverse proxy of `/wp-json`, `/wp-admin`, `/wp-login.php`,
  `/wp-includes`, login re-scoping, `Set-Cookie` Domain stripping. Porting
  it again (per its documented intent) gives Funiq the same posture.
- The Funiq-specific addition is one proxy rule: `/api/*` →
  `/wp-json/funiq/v1/*`, which replaces the `.htaccess` rewrite from the
  bridge README and lets the purchased PWA run **unmodified**.

### 2.3 Storefront rendering & SEO — the honest constraint

- "Client-side-only rendering (where the browser assembles the page from
  JavaScript) is an SEO risk that no competent agency should ship in 2026"
  ([AskPhill — Shopify headless commerce](https://askphill.com/blogs/blog/shopify-headless)).
  Industry guidance for headless storefronts: SSR/SSG first paint, JSON-LD
  (Product/Offer/Breadcrumb/Organization) rendered in the **initial HTML**,
  real `<a href>` links crawlable with JS disabled
  ([Ecommerce SEO Agency — headless SEO 2026](https://ecommerceseoagency.ca/blog/headless-ecommerce-seo-best-practices-2026/)).
- The purchased PWA is client-rendered. Modifying it (SSR wrapper,
  prerendering) is *permitted* under the Envato license for the purchaser's
  own end product — but it is a substantial change to licensed code and
  must happen in the private repo. The pragmatic sequencing: **v1 ships the
  PWA as-is** (CSR, documented SEO limitation), with an SSR/prerender track
  deferred as a private-repo follow-on (§3.5). The bridge REST
  (`/wp-json/funiq/v1/products`) is the canonical data source any future
  SSR shell would consume.

### 2.4 Licensed assets in CI/CD — pipeline hygiene

- Industry practice for proprietary dependencies is the **private artifact
  feed / private source repo + CI-time injection** model: licensed code is
  stored in a private location, pulled into the build at pipeline time with
  scoped credentials, and never committed into public history
  ([r/devops — dependent-repo CI/CD patterns](https://www.reddit.com/r/devops/comments/18eo4g5/how_do_you_handle_cicd_for_multiple_repos_that/)).
  This is the same discipline used for private npm registries and GitHub
  Packages, applied to a purchased frontend.
- GitHub Actions can consume private repos via repo-scoped **deploy tokens**
  held in repository secrets (never exposed in logs). The same mechanism
  the repo already uses for its two Velocity mirrors (deploy-token
  force-push) is used once more for the private PWA clone.
- Velocity deploys from a **connected GitHub repository** (branch, root
  directory, Node version, build command, environment variables) — see
  [Cloudways Velocity — deployments](https://support.cloudways.com/en/articles/16187473-how-to-manage-deployments-for-your-cloudways-velocity-application)
  and
  [launching an app](https://support.cloudways.com/en/articles/15550368-how-to-launch-a-velocity-application-on-cloudways).
  A private repo is fully supported: Velocity connects via the owner's
  GitHub OAuth, so any repo the account can read is deployable.

### 2.5 Reuse of the PR 7166 console patterns

PR #7166 established the repo's modern admin-surface discipline:
runtime-config injection, schema-driven API client classes (fetch +
`X-WP-Nonce` + error surfacing), `useX` hooks, lazy routes with
kill-switches, and fetch-mocked vitest contract tests. The bridge's admin
SPA predates all of it (single `AdminApp`, `wp_localize_script` nonce). The
deferred standalone-admin track (§3.5) adopts these patterns rather than
inventing new ones; the v1 scope keeps the admin inside wp-admin, which
needs none of them.

## 3. Proposed Design

### 3.1 Options considered

| | A — All-public Velocity app (SA pattern verbatim) | B — Private mirror, monorepo sync with licensed-bundle injection (**chosen**) | C — Fully private primary repo (no monorepo involvement) |
|---|---|---|---|
| Deliverable | `examples/funiq-bridge-velocity/` → public mirror | Public `examples/funiq-bridge-velocity/` (NV-authored files only) + private PWA repo + **private** mirror | Private repo owns everything |
| Envato compliance | **Violates** — purchased bundle in public history | Compliant — licensed bytes only in private repos | Compliant |
| Repo precedent | SA + console mirrors | Same mirror workflow + one token-gated clone step | None |
| PR 7166 pattern reuse | Full | Full (admin work stays in the monorepo) | Weaker — pattern porting across repo boundary |
| Effort | Lowest | Medium (one extra workflow step) | Medium-high (new primary repo tooling) |

Option B wins: it keeps every NV-authored file public and every licensed
byte private, while preserving the monorepo as the single source of truth
for the bridge and its future admin surface.

### 3.2 Shape of the deployment

```
Browser ──> Velocity app (private mirror nvoos-funiq-velocity)
              ├── dist/storefront/     # purchased Funiq PWA bundle   ← licensed
              ├── /                    # storefront SPA
              ├── /api/*               # proxied → /wp-json/funiq/v1/*
              ├── /wp-json, /wp-admin, # proxied → NVOOS_TARGET_SITE
              │   /wp-login.php,       #   (Funiq CMS stays inside
              │   /wp-includes         #    wp-admin — same origin)
              └── /assets/*            # hashed storefront chunks
```

- **Storefront:** the purchased PWA bundle served at the app root. The
  `/api` proxy rule makes it work with zero PWA changes and no CORS.
- **Admin:** unchanged — the proxy serves `/wp-admin` on the same origin,
  so the existing Funiq CMS (wp-admin-embedded, `wp_localize_script` nonce)
  keeps working exactly as today. A standalone admin is a deferred
  follow-on (§3.5).
- **Repository layering** (the Envato boundary made structural):

| Repo | Visibility | Contents | History |
|---|---|---|---|
| `nvdigitalsolutions/mcp-ai-wpoos` (`examples/funiq-bridge-velocity/`) | Public | NV-authored only: `server.js`, `scripts/serve.mjs` (+`/api` rule), `package.json`, `scripts/sync-dist.mjs` (admin build bridge), sync workflow, README | Normal |
| `nvdigitalsolutions/nvoos-funiq-pwa` | **Private** | Purchased PWA source + build config (owner-managed) | Normal |
| `nvdigitalsolutions/nvoos-funiq-velocity` | **Private** | Assembled app: NV-authored server files + storefront `dist/` injected at CI time | Flat, force-push (SA mirror pattern) |

### 3.3 Deployment & sync

- Sync workflow `sync-nvoos-funiq-velocity.yml` triggers on pushes touching
  `examples/funiq-bridge-velocity/**` (and optionally
  `addons/funiq-bridge/**`) on `main`/`alpha-working`, plus
  `workflow_dispatch`. It: (1) assembles the NV-authored tree, (2) clones
  the private PWA repo with a repo-scoped token and copies its built
  `dist/` in, (3) runs the same asset-closure + `node server.js` smoke
  gates the SA workflow runs, (4) force-pushes one flat commit to the
  **private** mirror. The public workflow file contains no licensed bytes
  and no secret values — only the push/clone targets.
- Velocity: connect the private mirror, root directory = repo root, entry
  `server.js` (shim → `scripts/serve.mjs`), no build command, env
  `NVOOS_TARGET_SITE=<bridge-enabled WP site>`. Deploy-token secret
  `NVOOS_FUNIQ_VELOCITY_REPO_TOKEN` (31 chars — under the 40-char GitHub
  secret limit that bit the SA track).

### 3.4 Compliance

- **Envato:** licensed bundle confined to the two private repos; the public
  monorepo and workflow reference it only by URL/target. License proof
  (purchase code) is stored privately with the PWA repo, never in the
  monorepo. Regular-vs-Extended tier is an owner decision (§7 Q1).
- **NV-authored parts:** the velocity example ships
  `"license": "UNLICENSED"` (npm-standard proprietary marker, per Proposal
  072 §3.4) and a `LICENSE` consistent with the bridge addon's; the bridge
  plugin already carries `License: Proprietary` + addon-local `LICENSE`.
- **Third-party notices:** the example ships its own
  `THIRD_PARTY_NOTICES.md` (esbuild dev-tooling, React/WP packages provided
  at runtime by WordPress) per the existing bridge convention, and updates
  the root `CREDITS.md` per the SPA skill rule.

### 3.5 Follow-on extractions (deferred, tracked)

- **Laravel standalone port (API out of WordPress, NV oOS merge)** — the
  long-horizon target: the bridge REST moves to a standalone Laravel
  service consuming `nvoos/core` + `nvoos/laravel-adapter`. Full analysis:
  [`085-funiq-laravel-standalone-integration-appendix.md`](085-funiq-laravel-standalone-integration-appendix.md).
- **Standalone Funiq CMS admin** on the app origin (`/admin`): second
  esbuild output variant (bundled React instead of WP externals), a
  `/funiq/v1/auth/nonce` endpoint in the plugin with the WP 6.9
  cookie-rebind pattern (schedule-anything skill §3), media uploads via
  REST instead of the `wp.media` modal, and the PR 7166 client/hook/vitest
  patterns. Deferred: v1 keeps the admin in proxied wp-admin at zero cost.
- **Storefront SSR/prerender track** (private repo) for SEO — see §2.3.
- **Authenticated storefront flows** (accounts, cart, checkout) — out of
  the bridge's current REST surface; scoped separately if the PWA needs
  them (the PWA was built for Payload's `/api/users` JWT model, which the
  bridge does not currently emulate).

## 4. Implementation Plan

Detailed in
[`docs/project/plans/funiq-bridge-velocity-storefront-implementation.md`](../plans/funiq-bridge-velocity-storefront-implementation.md).
Phases: **0** decision gates → **1** private repo scaffolding → **2**
monorepo example tree → **3** bridge plugin hardening (nonce endpoint
seed) → **4** sync workflow with licensed-bundle injection → **5** Velocity
deploy runbook → **6** release bookkeeping.

## 5. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Licensed bundle leaks into public history (misplaced file, wrong workflow target) | Legal (license breach) | Path-filtered workflow; smoke gate asserts the mirror target is private; CI job prints repo visibility in the run summary; PWA injection happens only in the workflow's temp tree |
| Regular License insufficient for the business model (product sold / multi-client) | Legal | Owner decision gate Q1 **before** Phase 1; Extended License purchased if required |
| PWA bundle served as `index.html` fallback for missing assets (MIME/strict-mode breakage) | Medium | Port the SA workflow's asset-closure + `text/css` MIME gates verbatim |
| `/api` proxy rule collides with WP core routes | Low | `/api/*` is not a WP core prefix; rule ordered before generic proxying; smoke-tested |
| Storefront SEO limitations (CSR) surface in client sign-off | Medium | Documented in §2.3; deferred SSR track; `noindex` recommendation for account-style pages |
| WP 6.9 nonce pitfall when the standalone admin lands | High (deferred) | Nonce endpoint uses the proven cookie-rebind pattern; regression test ported from the SA suite |
| Velocity private-repo connection rejected | Low | Private repos deployable via owner GitHub OAuth; verified during Phase 5 runbook |

## 6. References

- Repo: Proposal 083 + plan (`nvoos-complete-console-app-implementation.md`),
  Proposal 072 (proprietary licensing), Proposal 081/082 (SA SaaS),
  PR #7166 (Console Phase 3b/4), `addons/funiq-bridge/` (README, LICENSE,
  `AdminPage.php`), `examples/schedule-anything-velocity/` +
  `.github/workflows/sync-nvoos-schedule-anything-velocity.yml`,
  `mcp-ai-schedule-anything-ops` + `mcp-ai-wpoos-spa-ui` skills.
- Industry: Envato Market [license FAQ](https://help.market.envato.com/hc/en-us/sections/200616950-Envato-Market-License-FAQ),
  [Regular vs Extended](https://help.market.envato.com/hc/en-us/articles/115005593363-Which-Envato-Market-Licence-Do-I-Need),
  [WRTeam license comparison](https://www.wrteam.in/blog/envato-codecanyons-regular-license-vs-extended-license);
  Auth0 [BFF](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/);
  Duende [BFF](https://docs.duendesoftware.com/bff/);
  AskPhill [headless commerce](https://askphill.com/blogs/blog/shopify-headless);
  Ecommerce SEO Agency [headless SEO 2026](https://ecommerceseoagency.ca/blog/headless-ecommerce-seo-best-practices-2026/);
  Cloudways [Velocity deployments](https://support.cloudways.com/en/articles/16187473-how-to-manage-deployments-for-your-cloudways-velocity-application).

## 7. Open Decisions (owner)

1. **License tier:** is the Funiq end product distributed free of charge
   (Regular License OK) or sold / fee-based (Extended License required)?
   Blocks Phase 1.
2. **PWA private repo name/ownership:** `nvdigitalsolutions/nvoos-funiq-pwa`
   (private) — confirm, or an existing private location for the PWA source?
3. **Domain layout:** single app domain (storefront at root, admin at
   `/wp-admin` via proxy) — confirm; or separate admin subdomain later?
4. **SEO expectations for v1:** accept CSR-as-is, or accelerate the SSR/
   prerender track?
