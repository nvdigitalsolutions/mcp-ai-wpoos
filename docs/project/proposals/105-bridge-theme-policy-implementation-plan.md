# Proposal 105 — Implementation Plan: Bridge Theme Policy (Q6)

**Status:** IMPLEMENTED (2026-11-03) — all phases shipped; see the proposal status line for the summary and the cluster validation counts (bridge 128 tests × WP 6.9/7.1; storefront 290 unit / 57 e2e / LHCI / prod-sim; forge 250 unit / 25 e2e incl. offline build).
**Companion to:** `105-bridge-theme-policy-proposal.md` + `105-bridge-theme-policy-research.md`
**Branches/PRs:** cluster commits on `feat/astro-105-106-theming-content` (bridge → storefront → forge → docs), one PR against `alpha-working`.

Each phase carries its own exit gate. The **contract doc shape is fixed** (§1) — all three surfaces implement against it verbatim.

---

## 1. The contract (fixed — do not deviate)

`GET /globals/theme` (public) · `PATCH /globals/theme` (`manage_astro`) — both namespaces (`nvoos-commerce/v1` + legacy alias), the BannerController pattern.

```json
{
  "policy": "auto",
  "contrast": "auto",
  "defaultTheme": "dark",
  "updatedAt": "2026-11-03T09:00:00Z",
  "$extensions": {}
}
```

- `policy`: `auto | light | dark | brand:<slug>` (slug regex `^[a-z0-9-]+$`).
- `contrast`: `auto | standard | high`.
- `defaultTheme`: `light | dark`.
- `updatedAt`: server-stamped ISO-8601 UTC (`gmdate('c')`) — the SSR cache-key component.
- `$extensions`: always `{}` in v1 (reserved for Proposal 097).
- PATCH accepts a partial `{ policy?, contrast?, defaultTheme? }`; rejects unknown fields + bad values with typed 400s (`astro_theme_policy_invalid_*`); returns the full normalized doc.
- Cache: `Cache-Control: public, s-maxage=60, stale-while-revalidate=15` (route-specific override of the B1 hook's 300) + ETag from the serialized body.
- Successful PATCH fires the debounced rebuild webhook (through `RebuildWebhook`'s static `$pending` seam — test via the seam, never the action).

## 2. Phase 1 — Bridge (PHP + admin)

Files:
- `addons/nvoos-astro-bridge/includes/Schema.php` — add `OPTION_THEME_POLICY = 'funiq_bridge_theme_policy'` + `theme_policy_defaults()` (the doc above with `policy: auto`, `contrast: auto`, `defaultTheme: dark`).
- `addons/nvoos-astro-bridge/includes/Plugin.php` — seed in `seed_default_options()`; register `new ThemePolicyController()` in `register_rest_routes()`.
- `addons/nvoos-astro-bridge/includes/REST/ThemePolicyController.php` (new) — modeled on `BannerController`: `rest_base = 'globals/theme'`, public READABLE, EDITABLE gated by `Schema::CAP_MANAGE`; `get_item()` reads the option (runtime filter `funiq_bridge_theme_policy` applied to the stored value — the product-source pattern), `update_item()` validates (enum whitelists + slug regex + unknown-field rejection), `update_option`, re-stamps `updatedAt`, returns the doc. Cache-header override: set headers after `rest_post_dispatch` via the route or in the callback (B1 hook runs on dispatch — the controller sets its own headers in the response, which win).
- `addons/nvoos-astro-bridge/includes/RebuildWebhook.php` — add a public `schedule_for_theme_policy()` seam reusing the existing debounce/`$pending` machinery (no new action wiring beyond what exists for content changes).
- Admin SPA (`addons/nvoos-astro-bridge/src/`): a "Theme policy" settings section — three radio presets (Auto / Force light / Force dark) + a brand-slug text input with the note that names come from the storefront's ADS registry + contrast select (auto/standard/high) + default-theme select. Wire it to the existing settings API client used by the banner/carousel surfaces (reuse the pattern; standalone + wp-admin bundles both build).
- Tests (`addons/nvoos-astro-bridge/tests/phpunit/REST/TestThemePolicyController.php` + option seeding test): default doc shape; GET public returns doc; PATCH happy path per field; each invalid enum/slug/unknown field → typed 400; PATCH without `manage_astro` → 403; cache headers present with `s-maxage=60`; webhook `$pending` set after PATCH; ETag changes on update.
- Gate: `php -l` + phpcs (bridge ruleset) clean; PHPUnit in Docker (`oos-wp`, DB `wordpress_test_astro`) green on the bridge suite.

## 3. Phase 2 — Storefront (velocity)

Files:
- `src/lib/theme/policy.ts` (new) — **forge-portable, no Astro imports** (mirrors `theme.ts` style): types `ThemePolicy`, `PolicyDoc`; `parsePolicyDoc(json: unknown): PolicyDoc` (lenient but typed; invalid → the default doc); `resolvePolicyTheme(policy, stored, mediaMatches, registry) → { theme, contrast, forced }` implementing the ladder:
  1. `brand:<slug>` with slug ∈ registry → forced brand; unknown slug → `auto` fallback (fail-open) + `forced: false`.
  2. `light`/`dark` → forced, ignore stored.
  3. `auto` → stored (if ∈ `['light', ...registry]`) → media (`light` if matches light, else `dark`) → `defaultTheme`.
  4. contrast: `standard|high` forced; `auto` → stored contrast → `standard`.
- `scripts/build-policy.mjs` + `scripts/check-policy.mjs` (new) — fetch `GET {NVOOS_API_BASE}/globals/theme` at build, emit `src/lib/theme/policy.generated.ts` (repo style: tabs + single quotes, the `tokens-build.mjs` emission discipline); `check-policy` fails when the source (env-provided or default) changed without regeneration. **The default (no backend / fixture demo) is the `auto` doc — never fail a build when the fetch 404s.**
- `src/lib/commerce/client.ts` — `getThemePolicy(requestUrl?: URL): Promise<PolicyDoc>` hitting `globals/theme` through `resolveBase()` (the 104 base-resolution precedence); 60 s in-process TTL via a module-level cache keyed by the resolved base; on fetch failure return the default doc.
- `src/layouts/BaseLayout.astro` — inline the policy JSON (from `policy.generated.ts` for prerendered pages; `await getThemePolicy(Astro.url)` for SSR pages) into the boot script via a `data-theme-policy` JSON script tag; boot ladder: forced → apply policy theme, skip storage; auto → current 104 behaviour; **keep `color-scheme` in sync with the resolved theme**; keep the `astro:after-swap` re-apply.
- `src/components/ThemeToggle.astro` + `ContrastToggle.astro` — read the policy from the inline JSON (`[data-theme-policy]`); forced → `disabled` + `aria-disabled="true"` + menu not mounted + label shows forced name + hidden note "Theme is set by the store"; the existing `document`-delegated handlers gain a forced-mode guard (no new bindings — 104 Defect A lesson).
- SSR cache keys: `src/pages/products/[id]/[...slug].astro` + `products/index.astro` + the new content routes append `policy.updatedAt` to the `Astro.cache.set` entry (tag list stays the same; revision goes in the entry key args).
- Fixture server `scripts/fixture-server.mjs` — `globals/theme` route: GET returns the fixture doc (`{policy:'auto', contrast:'auto', defaultTheme:'dark', updatedAt: fixed, $extensions:{}}`); PATCH (manager seam) updates an in-memory copy; a second fixture doc mode via `?policy=dark` etc. is NOT needed — e2e mutates via PATCH.
- Tests: `tests/unit/policy.test.ts` (≥ 12 ladder cases incl. unknown-slug fail-open, forced-ignores-storage, defaultTheme fallback, contrast ladder), `tests/unit/policy-drift.test.ts` (drift gate), client test extension (getThemePolicy + TTL), e2e `tests/e2e/theme-policy.spec.ts` (forced light/dark/brand render pre-paint with zero FOUC on static + SSR pages; toggles disabled with aria labels; auto honours stored pref; forced contrast; unknown brand renders default auto; axe clean).
- Gate: `npm run check && npm run lint && npm run test` (230 + new), `npm run test:e2e` (25 + new), LHCI, `npm run build:prod-sim`. Windows: `NODE_OPTIONS=--max-old-space-size=8192` for `check`/`build`.

## 4. Phase 3 — Forge

Files:
- `examples/nvoos-astro-forge/scripts/forge.mjs` — new preset `--theme-policy <auto|light|dark|brand:<slug>>` (commander option; invalid value → clack error; default `auto`).
- `examples/nvoos-astro-forge/lib/presets.ts` — add the option to the preset schema/validation + wizard prompt.
- `examples/nvoos-astro-forge/lib/scaffold.ts` / `project-files.ts` — write the policy stub into the generated project (`src/lib/theme/policy.ts` verbatim copy of the velocity module + `policy.generated.ts` with the chosen policy baked) and the Base layout boot block equivalent (template's `Base.astro` — same inline-JSON + ladder pattern, adapted to the template's existing boot script).
- `examples/nvoos-astro-forge/tests/` — `presets.test.ts` extension (option parse + validation), `theme.test.ts` extension (template policy module copy + forced boot behaviour), e2e/CLI smoke with `--theme-policy dark`.
- Gate: `npm run typecheck && npm run test` (215 + new), e2e (20 + new), CLI smoke.

## 5. Phase 4 — MCP preview semantics + docs

- `mcp/tools/design.ts` — `theme_apply` becomes preview-only in production: honoured when `NVOOS_THEME_PREVIEW=1` or no policy doc exists; when a policy exists and the flag is absent, `theme_apply` succeeds but reports `preview: false, servedPolicy: <doc>` in the result (never errors — keep MCP contract stable). Update the tool description + `tests/mcp/handshake.test.ts` if it asserts the description.
- Docs: mark `105-*` proposal IMPLEMENTED; update `docs/operations/deployment/nvoos-astro-velocity-setup.md` (policy setting + propagation matrix) and `.agents/skills/mcp-ai-wpoos-astro-storefront/SKILL.md` (policy contract + precedence + MCP preview semantics; stay under Zed's 100 KB skill limit).
- Gate: skill size check + description-budget check; velocity + forge suites still green.

## 6. Shared pitfalls (from 104 + skills — do not repeat)

- `trailingSlash: 'always'` turns POST into GET on slashless URLs — the canonical policy URL is `/globals/theme` **without** trailing slash (REST namespace routes are not pages; verify against the fixture/bridge route registration and pin in e2e).
- CSP: the boot script stays `is:inline` — if the policy JSON is inlined as a separate script tag, keep it `is:inline` JSON (no event handlers) or the CSP hash gate (104 P5) breaks.
- Astro bundles component scripts once per full load — all policy-state reads must live in the existing delegated handlers + `astro:after-swap` sync, never in new per-component bindings.
- `policy.generated.ts` sits under `src/` → linted. Emit repo style (tabs, single quotes) or the lint gate fails.
- Never stage/commit files outside the cluster's scope — git work is done by the coordinator, not the implementers.
