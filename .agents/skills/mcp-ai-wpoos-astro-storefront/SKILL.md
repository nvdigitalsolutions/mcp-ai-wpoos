---
name: mcp-ai-wpoos-astro-storefront
description: Operational guide for the NV-authored headless storefront (Proposal 092) — the funiq-bridge Payload-shaped commerce contract (`nvoos-commerce/v1`), the `examples/nvoos-astro-velocity` Astro app, its hermetic dev/test loop (fixture server, vitest, Playwright, Lighthouse CI), the contract drift guard, the rebuild webhook, and the sync workflow + public mirror deployment. Use when working on the storefront, the bridge contract, image optimization, SEO/crawlability, or the mirror deploy loop.
---

# NV oOS Astro Storefront — ops guide (Proposal 092)

## What this is

The public, NV-authored headless storefront: an Astro 7 app consuming a
Payload-shaped commerce envelope over same-origin `/api/*`, replacing the
licensed Funiq PWA (owner decision Q1: keep A/B until parity review, then
retire). Full context: `docs/project/proposals/092-astro-storefront-layer-proposal.md`.

| Piece | Location | Role |
|---|---|---|
| Bridge addon | `addons/funiq-bridge/` | Serves the Payload envelope from WordPress + WooCommerce data (transitional backend; Laravel takes over at T1 per Proposals 085/090) |
| Storefront source | `examples/nvoos-astro-velocity/` | Astro SSR + prerendered catalog, React islands (cart badge, gallery), View Transitions |
| Sync workflow | `.github/workflows/sync-nvoos-astro-velocity.yml` | Builds + tests, then rebuilds for the mirror |
| Public mirror | `nvdigitalsolutions/nvoos-astro-velocity` (PUBLIC) | Assembled deploy tree (Astro `dist/` + server shim) — zero licensed bytes |

## Contract rules (CI-enforced — never weaken)

- The Astro app binds **only** to the Payload envelope (`nvoos-commerce/v1`
  REST namespace alias). Never call WP-specific endpoints from `src/`.
- The word **"funiq" is banned from `examples/nvoos-astro-velocity/src/`**
  (CI grep gate). The bridge addon and docs may still name it; the app may not.
- Golden fixtures are the contract test: `examples/nvoos-astro-velocity/tests/fixtures/products.json`
  must stay semantically identical to the bridge transformer output pinned by
  `addons/funiq-bridge/tests/phpunit/Transformers/TestTransformerGolden.php`.

## Dev loop (hermetic — no live site needed)

```bash
cd examples/nvoos-astro-velocity
node scripts/fixture-server.mjs            # :8123 — hermetic contract + media
NVOOS_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1 npm run build   # prerenders catalog
NVOOS_TARGET_SITE=http://localhost:8123 PORT=4321 npm run serve                # BFF + app
npm run check && npm run lint && npm run test          # astro check · eslint · vitest (unit + contract)
npx playwright install chromium && npm run test:e2e    # 2 specs — JS-disabled SEO + islands
npm run lhci:ci                                        # CHROME_PATH → Playwright's Chromium
```

- The fixture server returns **401 on `/auth/me` without a cookie** and serves
  a real 1×1 PNG on `/wp-content/uploads/` — keep both behaviours.
- PHP side (bridge): `php -l` + phpcs, then PHPUnit in Docker (`oos-wp`
  container, isolated DB `wordpress_test_astro`) — see the
  `mcp-ai-wpoos-test-suite` skill for the exact recipe. Run against WP 6.9
  **and** WP 7.1.

## Image optimization (Phase 1.7) — the two rules that cost a session

- The runtime endpoint is **`/**_image/**` (trailing slash required)**; the
  no-slash variant 404s.
- `src/lib/images.ts` must emit **absolute same-origin hrefs** — relative
  hrefs 404. Use `renderOrigin()` (`Astro.site` when prerendering, `Astro.url`
  at request time) via `responsiveImage`/`fixedImage`.
- `astro.config.mjs` derives `image.domains` from `ASTRO_SITE` — so a build
  against the fixture server uses a `localhost` origin, and the mirror
  workflow rebuilds with the **prod host** in `ASTRO_SITE`.

## Key gotchas (learned the hard way)

- **CartBadge** must hydrate `client:load transition:persist` — `client:idle`
  is too slow for Playwright e2e.
- Playwright islands with `client:visible` hydrate lazily → use `toPass()` +
  scroll; keep header elements on `client:load`.
- **RebuildWebhook** (`addons/funiq-bridge/includes/RebuildWebhook.php`):
  shutdown closures double-fire on Cloudways and full `shutdown` actions trip
  output buffers — the class keeps a static `$pending` flag with
  `send_pending()`/`reset_pending()` seams. Test through those seams, not the
  action.
- **TestTransformerGolden**: paths use `dirname(__DIR__, 2)`; golden JSON and
  transformer output must both be normalized (`normalize_golden_fields()` —
  floats like `1299.0` vs `1299`, name-sorted terms) before comparison.
- `updated_option`/`edited_term` hooks need all 3 args in PHPUnit —
  WooCommerce handlers are strict.
- `do_action('init')` re-fires WooCommerce "already registered" notices —
  register CPTs directly (test pattern #2) and grant capabilities in the
  bootstrap; PHPUnit never runs activation.
- Drift guard: after touching the bridge transformer, run
  `npm run sync:bridge-fixtures` and confirm `tests/fixtures/products.json`
  shows a format-only reflow, not semantic changes.

## Deploy & revalidation

- `npm run sync:dist` assembles the deploy tree; the workflow has **two
  builds**: localhost-origin (smoke/e2e/LHCI) and prod-host rebuild (mirror).
- Rebuild trigger: bridge fires a debounced `nvoos-storefront-content-changed`
  → GitHub `repository_dispatch` → the sync workflow.
- Mirror repo is public (owner Q6). A/B coexistence rules and rollback:
  `docs/operations/deployment/nvoos-astro-velocity-setup.md`.

## Standards this app is built to (research-backed)

- **Hybrid rendering** — prerender the catalog at build; SSR the dynamic
  pockets (stock, promos). This is the 2026 Astro headless-commerce standard.
- **Astro Image** for automatic WebP/AVIF + lazy loading via the runtime
  endpoint (above).
- **SEO**: canonical single product URL, `Product` JSON-LD in initial HTML
  with SKU-aligned IDs, `/sitemap.xml`, robots, cache headers + revalidation —
  verified by the JS-disabled crawlability spec and Lighthouse gates
  (perf ≥ 0.8, a11y ≥ 0.9, best-practices ≥ 0.9, seo ≥ 0.95).

## References

- Proposal 092 (design, phases, owner decisions) · `docs/operations/deployment/nvoos-astro-velocity-setup.md`
- `examples/nvoos-astro-velocity/README.md` (architecture, env, commands)
- `addons/funiq-bridge/README.md` (REST surface, data map)
- Related: Proposal 085 + appendix (contract invariant, Strangler Fig),
  Proposal 090 (Laravel backend flip — the storefront survives it unchanged).
