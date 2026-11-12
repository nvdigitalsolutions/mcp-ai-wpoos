# Gotchas — the full list (each cost a fix)

Split out of `SKILL.md` (2026-11-11) to keep that file under Zed's ~100 KB
skill-load limit. Every entry here is a real defect that shipped once; the
short digest of the highest-traffic ones stays in `SKILL.md`.


- **Astro 7 spread params are flat slash-joined strings, NOT arrays** —
  `getStaticPaths` for `src/pages/posts/[...parts].astro` must return
  `params: { parts: '1/slug' }`. The route generator uses the params value as
  the path segment directly and the validator requires `typeof value === 'string'`
  (verified against astro 7.4.4 dist sources: `routing/generator.js`,
  `routing/internal/validation.js`, `render/params-and-props.js`). An array
  fails the build with `GetStaticPathsInvalidRouteParam`.
- **FORGE marker rewriting must be line-anchored** — naive
  `indexOf('// FORGE:site')` matches prose that mentions the marker inside a
  header comment and mangles `content.config.ts`. Match
  `line.trim() === '// FORGE:site'` only.
- **Template-copy filters must exclude by directory basename** — a
  `/(node_modules|dist|\.astro)$/` path regex also matches every `*.astro`
  page file (the `\.astro$` branch) and silently drops all pages. Filter on
  `path.basename(src)` ∈ {node_modules, dist, .astro}.
- **Emitting a regex literal through a JS template literal needs the
  backslash doubled** — `\\/+$/` in the scaffold source (an identity escape
  otherwise emits `//`, turning the generated line into a comment and
  breaking the parse).
- **Truncation math:** `truncated = page === maxPages && page < totalPages` —
  a catalog that ends exactly at the cap is complete, not truncated.
- **`sanitize-html` `allowedIframeHostnames` strips the disallowed `src` but
  keeps the inert `<iframe>` tag** — assert on `src` absence, not tag absence.
- **Node ≥ 22.6 native type stripping runs the CLI with zero build step** —
  `scripts/forge.mjs` imports the `.ts` libs directly. Requirements: explicit
  `.ts` extension specifiers in relative imports, erasable-only TS (no enums/
  namespaces), `allowImportingTsExtensions: true` + `noEmit` in the tsconfig.
  The generated template inherits `allowImportingTsExtensions` from
  `astro/tsconfigs/strict` — vendored `.ts`-extension imports typecheck as-is.
  The same rule covers `tests/**/*.ts` (the tsconfig `include` lists it):
  vitest resolves an extensionless specifier, `tsc` does not.
- **`astro check` on a generated project needs `@types/sanitize-html` and
  `@types/node` in the template devDeps** — the vendored `sanitize.ts` and
  `wp-client.ts` typecheck under the language server.
- **A new `lib/*.ts` module that a VENDORED module imports must join
  `FORGE_LIB_FILES`** (`lib/scaffold.ts`). The scaffold does not copy `lib/`
  wholesale — step 2 of `scaffoldProject` vendors a fixed list into
  `src/forge/`. #7574 added `import type { ForgeVerify } from
  './gate-policy.ts'` to `types.ts` and left the module out, so EVERY generated
  project failed `astro check` with `ts(2307): Cannot find module
  './gate-policy.ts'` while `npm run typecheck` and the unit suite stayed green
  (the module does exist in the repo — just not in the customer repo). **No
  fast gate can see this class of break**: `vitest run --exclude forge-e2e` is
  the documented quick gate and it passes. Touching a vendored import means run
  the e2e, or reproduce cheaply — `forge scaffold <site> --target <dir>` →
  `npm install` → `npm run check` (~3 min; that also runs the
  generated-artifact drift check).
- **`vitest run -t` is unreliable in `forge-e2e.test.ts`** — the fixture server
  and the derived profiles are built by earlier tests in the same run, so any
  name filter (test OR describe) skips their creator and every selected test
  fails with `expected undefined to be defined`. Run the whole file.
- **EVERY scaffold replaces the target wholesale, `--overwrite` or not** — the
  project is built in a `<target>.forge-tmp-*` sibling and swapped in
  (`swapStagedProject`), so the new tree is the TEMPLATE only: `node_modules/`,
  `dist/`, `.astro/` and **`verify-artifacts/` all disappear**. Budget
  `npm install` after every re-scaffold, and re-record the pixel seam with
  `verify --pixel-record-source` — otherwise the parity gate reports "no
  baseline or source screenshots" and the scorecard shows `–` for a site that
  was passing. The swap is atomic: it fails before destroying anything when
  something holds the target open (dev server, watcher, editor), and leaves the
  old tree intact. An empty target is safe; a non-empty non-forge dir is
  refused without `overwrite: true`.
- **Woo sets `price` to the sale price while on sale** — sale state is
  `salePrice !== regularPrice`, never `salePrice !== price` (the first cut
  rendered no strike-throughs at all). wc/v3 needs consumer keys (401
  without); wp/v2 product CPTs stay public but have no commerce fields —
  both shapes share one loader via `kind: 'product'`.
- **`_embed` does not guarantee media** — resolve `featured_media` IDs via
  `wp/v2/media/{id}?_fields=source_url` with bounded concurrency (5); an
  image miss is never a build failure.
- **Template pages binding collections must be scaffold-pruned** when the
  profile omits the collection, and every remaining consumer (the sitemap
  endpoint) gates on `nvoos.json` — an undefined `getCollection()` fails
  the build.
- **The WP static front page** maps to profile `frontPage` — rendered at
  `/`, skipped by the slug route + sitemap (one canonical URL).
- **Astro ignores underscore-prefixed files in `src/pages/`** (the partial
  convention) — `_redirects.ts` never becomes a route. Emit
  `/redirects.json` and materialize `dist/_redirects` post-build.
- **Token rewrites must keep their FORGE markers** and cover every token
  the template block defines (layout tokens included) — see the
  `/* FORGE:tokens */` span contract below.
- **`inferSize` is required on Astro Image for remote sources** —
  `widths`/`sizes` alone fail the build with `missing-image-dimension`.
  The domain allowlist mirrors the content layer's env-first rule, and
  SmartImage degrades to a plain `<img>` for non-allowlisted hosts (a CDN
  image must never fail a build).
- **`process.exit()` races undici teardown on Windows** (0xC0000409) —
  graceful `exitCode` + natural drain in the CLI.
- **The scaffold CLI is operator-run and has no rate limiting** — rate
  limiting/retry belongs on the future MCP surface, not the CLI.
- **`forge.mjs` is plain JS, never TS syntax** — `.mjs` gets NO type
  stripping: `import type` and inline `type` specifiers are syntax errors
  there (strip them; the runtime DEPLOY_MODES check carries the constraint).
  Same rule for the 104 CLI additions: **commander** parses args, and the
  **@clack/prompts** named exports (`select`/`confirm`/`text`/`spinner`/
  `isCancel`) import from a plain ESM package — no TS anywhere.
- **Program-level commander options are GLOBAL — they shadow subcommand
  flags of the same name** (commander parses program options from anywhere
  in argv before dispatching). The scaffold is therefore the
  `isDefault: true` subcommand (`forge`): `forge.mjs site.json --target X`
  and `forge.mjs forge site.json --target X` parse identically, and the
  wizard/deploy subcommands keep their own `--target`. Never declare
  `--target`/`--preset` at program level again.
- **The wizard's clack prompts must stay TTY-gated** — `forge wizard`
  without `--preset` prompts via `select`/`confirm` only when
  `process.stdin.isTTY && process.stdout.isTTY`; otherwise
  `suggestPreset(site_analyze(...))` applies deterministically (the e2e
  wizard run depends on this — it spawns with piped stdio). The shared
  `setupWizard()` stays non-interactive for the MCP surface: prompt hooks
  arrive through the optional `ui` parameter (`selectPreset`/
  `confirmScaffold`), never directly in wizard.ts.
- **`scaffold()` applies the preset itself** (collection filter + surface
  pruning + manifest `surfaces` flag) — callers must not pre-apply
  `applyPresetToProfile` or a `preset`-scaffold forgets the filter (the
  minimal e2e caught this). Wizard.ts still pre-applies so the REGISTERED
  profile JSON reflects the preset; the re-application in scaffold is
  idempotent.
- **`getCollection()` on a pruned collection breaks `astro check`** — the
  minimal preset drops the products collection, so the sitemap/redirects
  endpoints type-error on `getCollection('products')`. They keep the
  `manifest.collections` runtime gate and call
  `getCollection('products' as 'posts')` with the routing-fields comment —
  the cast documents that only id/slug/link are consumed. (The pages
  themselves stay scaffold-pruned as before.)
- **`limax` is vendored into generated projects** — `wp-loader.ts` imports
  it (D4 slug rule: transliteration, underscore→dash, 80-char cap,
  `'item'` fallback), so `templates/blog/package.json` must list it in
  `dependencies` or payload-source scaffolds fail to build. Same for
  `schema-dts` in the template devDeps (`astro check` on `src/lib/seo.ts`)
  — and in the FORGE devDeps, because the forge tests import the template
  files directly under `tsc --noEmit`.
- **Copy tables derive the completeness gate from the SHAPE, not literal
  strings** — `en.ts` must NOT be `as const`, or `Copy = typeof en` locks
  literal types and a second locale's different strings fail typecheck
  (only identical text would pass). The gate is: missing/extra keys fail
  `astro check`; string values are free.
- **Astro 7 server mode no longer prerenders `getStaticPaths` pages** —
  the build warns `getStaticPaths() ignored in dynamic page` and turns them
  on-demand. The `--render ssr` scaffold therefore injects
  `export const prerender = true;` into the static chrome (home, pages,
  posts, PLP, search — `addPrerender()` in scaffold.ts, inserted right
  after the opening frontmatter fence, never double-injected) so only the
  PDP is on-demand and Pagefind/sitemap keep working; the OG endpoint
  carries the flag in the template itself.
- **`pagefind --site dist` breaks server builds** — the prerendered HTML
  lives under `dist/client/` in server-output mode; the ssr scaffold
  rewrites the build script to `pagefind --site dist/client`, and
  `postbuild.mjs` materializes `_redirects` next to whichever redirects
  map exists (`dist/` or `dist/client/`).
- **`scaffold()` stages beside the target and renames it in (#7516)** — the
  first scaffold in a freshly-written checkout can hit Windows
  `EPERM … rename '<target>.forge-tmp-…' -> '<target>'` (Defender/lock); a
  retry succeeds and POSIX CI is unaffected. Do not "fix" it by deleting the
  staging — that reintroduces the half-written-project bug.
- **Inert template files live in underscore dirs, renamed at scaffold
  time** — `products/_ssr/` becomes `products/[id]/` (the on-demand route)
  and `_og/` becomes `og/` (OG cards) only when selected; otherwise they
  are pruned. Astro skips underscore dirs under `src/pages/`, so both stay
  route-less in the template. Keep the rename SOURCE a sibling of the
  target: `_ssr/[...slug].astro` (not `_ssr/[id]/[...slug].astro`) — on
  Windows, renaming `_ssr` → `[id]` when `_ssr` contains a `[id]` child
  nests it to `[id]/[id]/...`.
- **OG deps are injected, never templated** — `satori`/
  `@resvg/resvg-js`/`@fontsource/inter` are added to the GENERATED
  package.json only when `ogImages` is on (default scaffolds stay zero-dep
  for OG); versions are pinned in `OG_DEV_DEPENDENCIES` (scaffold.ts). The
  OG endpoint resolves fonts via `createRequire` against
  `@fontsource/inter/files/inter-latin-{400,700}-normal.woff`; card tokens
  are baked from the applied `--forge-*` values (line-anchored
  `FORGE:og-tokens` span). A render failure warns + falls back — never a
  build failure.
- **The manifest writes explicit loader defaults** — `source` and
  `authMode` are always emitted (`'wp'` / `'application-password'` when
  unset) so the JSON's inferred type keeps both keys and the SSR route can
  read `productsRef.source`/`authMode` under `astro check`. Dropping the
  defaults makes those keys vanish from the type.
- **`--render ssr` + blog-only profiles** — the products tree is pruned
  first (collection-driven); the ssr swap is guarded on `hasProducts` so
  the inert `_ssr` dir never survives a blog-only scaffold.
- **Node strip-only mode bans parameter properties** —
  `constructor(private readonly x)` fails with
  `ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX` at CLI runtime even though vitest
  (esbuild) accepts it. Declare the field explicitly and assign in the
  constructor. Also banned: enums/namespaces; inline `type` specifiers in
  named imports need a separate `import type` statement.
- **Secret grep gates must not match empty assignments** — `PATTERN=[^ ]+`
  matches the newline after an empty `=` (and the quote in the scaffold's
  own emission). Use value-shaped classes (`[^[:space:]"']{4,}` in GNU
  grep, `[^'"\n]{4,}` in JS) so `.env.example` placeholders and scaffold.ts
  stay clean while real values still trip.
- **SDK v2 registerTool + zod 4.6 (cost a full session 2026-10-31):** the
  SDK's deprecated raw-shape overload (`ZodRawShape = Record<string,
  z.ZodType>`) expects a zod classic surface that zod 4.6 no longer
  provides — raw shapes fail with `$ZodType`-missing-`_def` errors. The
  non-deprecated overload accepts a zod INSTANCE typed as the SDK's
  exported structural `StandardSchemaWithJSON`; type the registry field as
  that, never as `z.ZodTypeAny`/raw shapes. The `ToolCallback` generic is a
  conditional type plain function expressions cannot satisfy even when the
  return type is provably assignable (probe it!) — register through a
  cast to an exact `(name, config, cb) => unknown` signature. And the SDK
  imports types from `zod/v4` — keep the mcp files importing `z` from
  `'zod/v4'` so type identities line up.
- **`deploy-config` echoed paths were sliced on `dir.length`** — a
  `./`-prefixed `--target` (`forge deploy-config … --target ./out`) printed
  `ithub/workflows/deploy.yml` while the files landed correctly (only the
  console report was wrong). Now `path.relative` + a dot-slash regression
  test (2026-11-06) — keep path reporting relative-based.

- **A hero band's content is VERTICALLY centred and its copy is WHITE.**
  The generic `.ds-section-wrap` Flatsome shim later makes every wrap a
  COLUMN, so `.ds-section-wrap--hero`'s `align-items:center` centres only
  horizontally — without `justify-content:center` the section sits at the TOP
  of the 724px band (measured 2026-11-12: section y=139 against band centre
  501; source content centre 552 vs band centre 562). And `.ds-text`'s muted
  default (`--forge-muted` = #505050) painted the hero paragraph grey on the
  photo; the source measures `rgb(255,255,255)`. The hero rule must let
  `.ds-text`/`.ds-hero p` inherit the hero ink. Both are pinned in
  `tests/motion.test.ts` (the template-CSS contract).
- **`Animation.rangeStart` is a DICTIONARY, not a string.**
  `document.getAnimations()` returns `{ rangeName, offset: CSSUnitValue }` for a
  scroll-driven animation; `String()` yields `[object Object]` and the emitter
  once wrote `animation-range:[object Object] [object Object]` (invalid CSS the
  browser drops silently). Serialize `offset.value` + `offset.unit` (`cover
  10%`), normalize `normal`/`auto` to absent, and keep an emitter-side guard.
  The hermetic browser test (`tests/animations-browser.test.ts`) round-trips an
  emitted `scroll(root)` + `cover 10% cover 90%` rule back into Chrome — a
  string-only assertion on the emitted CSS would have missed this.
- **A browser test that skips is not a test that passed.** The new browser
  suite uses `describe.skipIf(!hasChromium)` and vitest reports the skip; CI
  installs Chromium BEFORE `npm test`, so a green run proves the probe ran.
- **The band wrapper must not duplicate an id its own markup owns.** A builder
  may put the in-page anchor on an element INSIDE the band — Brando puts
  `id="wifi"` on the red half-column — and the harvested markup keeps it. The
  wrapper emitted the same id, so `#wifi` resolved to the wrapper: links landed
  on the band instead of the panel, and `verify --colors` compared the band's
  `#ededed` surface against the source's `#dd3333` panel. `wrapperAnchor()`
  (`templates/blog/src/lib/anchor.ts`) yields when a rendered `id` attribute
  already matches — attribute-aware, so `id="wifi"` written in prose does not
  count, and `id="wifi-panel"` does not either. Only the kinds that actually
  emit `section.html` (`text`, `raw`) are checked: a component band keeps the
  html but never renders it, so suppressing there would drop the anchor.
