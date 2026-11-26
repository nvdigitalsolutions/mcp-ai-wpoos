# nvoos-astro-forge — WP → Astro frontend generator (Proposal 096, Phases 0–4)

Turns a WordPress/WooCommerce site into a native **Astro 7** headless
frontend. WordPress stays the CMS; the generated project pulls content
through an Astro **content-layer loader** at build time (freshness via
webhook → rebuild), renders sanitized HTML, and ships SEO-complete static
output. The pilot customer is a WooCommerce storefront (Proposal 098 has
already extracted its design language — the tokens feed the template's
`--forge-*` variables).

> Phases 0–3 (generation core, Woo collections, SEO/media/search, bridge
> plugin, vault + deploy + multi-site) and Phase 4 (the `setup_wizard` and
> the `/mcp` tool surface) are shipped. **Proposal 104 Phase 4** (the
> storefront runtime-hardening pass) landed the preset wizard, the agent
> stubs, typed copy tables, the `kind`-typed SEO builder, the theme-module
> port and the Tailwind token bridge — see “Presets” and “Phase 4 template
> additions” below. Remaining: the registry publication (owner action —
> `server.json` is ready) and the §3.3 long-tail tool slices.

## Layout

| Path | Role |
| --- | --- |
| `lib/types.ts` | `SiteProfile` / `WpCollection` contracts — `exclude`, `kind`, `authMode`, `optional`, `frontPage` (allowlisted URLs only) |
| `lib/wp-client.ts` | WP REST client — pagination via `X-WP-TotalPages`, `_fields` pruning + `_embed`, typed errors, explicit truncation |
| `lib/wp-loader.ts` | The Astro content-layer loader — wc/v3 commerce extraction, wp/v2 featured-media resolution, optional sources |
| `lib/sanitize.ts` | Versioned `sanitize-html` allowlist — the XSS gate before `set:html` |
| `lib/scaffold.ts` | Template copy + FORGE-marker rewrite + manifest + gitignore gate + storefront-page pruning + preset surfaces + agent stubs + render variant (Q4) + OG endpoint (Q5) |
| `lib/presets.ts` | 104 §4.3 preset registry — `blog` / `storefront` / `portfolio` / `landing` / `docs` / `minimal` descriptors (render mode + surfaces incl. `ogImages` + the variants each can carry), `suggestPreset()`, `suggestedVariant()`, `applyPresetToProfile()` |
| `scripts/forge.mjs` | CLI on **commander** + **@clack/prompts**: `analyze`, `wizard [--preset …] [--render …]`, `site`, `vault`, `deploy-config`, `deploy`, scaffold (`--preset`, `--render`, `--variant`, `--og-images`, `--no-agents`) — plain JS, Node ≥ 22.6 |
| `lib/discovery.ts` | `site_analyze` — root/types/counts/front-page/junk/Woo/SEO probes → draft site.json |
| `lib/vault.ts` | Phase 3.1 credential store — AES-256-GCM entries under `~/.nvoos-forge/vault.json`, key from `NVOOS_FORGE_VAULT_KEY`, masked output |
| `lib/sites.ts` | Phase 3.4 multi-site registry — named profile refs + default flag in `~/.nvoos-forge/sites.json` |
| `lib/github.ts` | Phase 3.2 octokit deploy — private-by-default repo create + git-data commit/push, sanitized errors |
| `lib/deploy-config.ts` | Phase 3.3 generated pipeline — deploy.yml + rebuild.yml + the fail-closed `verify-dispatch.mjs` |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 + wc/v3 wire fixture (`:8473`) — stands in for WordPress Playground in CI |
| `scripts/builtin-template.mjs` | Test fixture generator (nested `main/templates/blog/` tree with a REAL loader) |
| `templates/blog/` | The production template — posts/pages/products, static front page, RSS, 404, 301 redirect map, canonical + JSON-LD, sitemap/robots, `--forge-*` tokens, typed copy tables, theme module, `lib/seo.ts`, Tailwind token bridge, the inert `_ssr` product route (Q4) and `_og` card endpoint (Q5) |
| `lib/contrast.ts` | WCAG 2.2 contrast math — the token gate warns on AA failures at scaffold time |
| `sites/` | Committed site PROFILES (`<site>.json`) — the only thing the fleet workflow scaffolds. A file here must carry `{ url, collections: [...] }` or it is skipped as a non-site |
| `compare/` | `forge compare` element specs (`<site>.json`) — passed by explicit `--elements`, never scaffolded |
| `tests/` | Unit tests (`npm test`) + the exit-gate e2e (`npm run test:e2e`) |

## Dev loop

```sh
npm install
npm test                 # unit: client, loader, sanitizer, scaffold, vault, github, deploy-config, sites
npm run typecheck
npm run test:e2e         # fixture WP → scaffold → npm install → astro check + build → HTML asserts
```

## Forge a site

```sh
# 1. Write the operator profile (the allowlist — the forge never takes arbitrary URLs).
cat > site.json <<'EOF'
{
  "url": "https://shop.example.com",
  "name": "Example Store",
  "collections": [
    { "slug": "posts", "restBase": "wp/v2/posts",
      "fields": ["id", "date_gmt", "modified_gmt", "title", "slug", "link", "content", "excerpt"],
      "embed": true },
    { "slug": "pages", "restBase": "wp/v2/pages",
      "fields": ["id", "date_gmt", "modified_gmt", "title", "slug", "link", "content", "excerpt"],
      "embed": true }
  ]
}
EOF

# 1. Discover — one command drafts the profile (site_analyze).
node scripts/forge.mjs analyze https://shop.example.com --write site.json

# 2. Check the source first — a build must never silently truncate.
node scripts/forge.mjs site.json --target ./customer-frontend --check

# 3. Scaffold + build.
node scripts/forge.mjs site.json --target ./customer-frontend
cd customer-frontend && npm install && npm run build
```

### `embed` per collection — and when to turn it off

`collections[].embed` (boolean, default `true` except product collections) makes
the loader fetch `_embed=1`, which is what gives a post its **author display
name** (`_embedded.author[0].name`) and its **featured image URL**
(`_embedded['wp:featuredmedia']`). Both are read in `lib/wp-loader.ts` and used
by every template that renders a byline or a card image.

Turn it off per collection when a site renders neither — a product catalogue, a
CPT index with no bylines, a pages-only brochure:

```json
{ "slug": "pages", "restBase": "wp/v2/pages", "fields": ["id", "slug", "title", "content"], "embed": false }
```

Two things worth knowing before you do:

- **`_embed` is not a performance lever.** Measured on a live Elementor site:
  4.11 s for 100 posts without it, 3.70 s with it — noise next to the CMS's own
  bootstrap. If a build is slow, measure the CMS first
  (`time wp eval 'echo 1;'` vs `--skip-plugins`) before touching this flag.
- **You cannot replace the author half with a sidecar.** `wp/v2/users?include=…`
  is refused with `rest_user_enum_blocked` on any hardened CMS, while `_embed`
  still resolves authors internally — so dropping it there silently removes
every byline. Keep `embed: true` wherever a template prints one.

### Design rebuild (Proposal 114)

The forge never replicates a source site's markup — it rebuilds the
presentation with the NV oOS design system (098 extraction → semantic
content model → ADS components → token theme → verified parity):

```sh
# 1. Extract the design language (roles + confidence + unassigned pool,
#    typography, spacing, radii, adaptation brief — token data only,
#    never source CSS/assets).
#    Band surfaces come from the builder adapter when the source is Elementor;
#    for every other builder (WPBakery/Brando, Divi, Bricks, Gutenberg…) the
#    vendor-agnostic outline is PROMOTED into the band model and the renderer
#    themes it positionally — without that a non-Elementor rebuild rendered
#    template defaults (virtuallyjamaica.com's red #dd3333 hero came out white).
#    Bot-protected source (Cloudflare 403 on /wp-json)? Add --no-cms: the
#    design/colour/text baselines only need the rendered homepage.
node scripts/forge.mjs analyze https://shop.example.com --design --write site.json
node scripts/forge.mjs analyze https://shop.example.com --no-cms --design --colors --text --write site.json

# 2. Scaffold applies it: the `design` block derives the --forge-* token
#    theme (explicit `tokens` overrides win), writes
#    src/lib/design.generated.ts, and audits every rendered pair against
#    WCAG 2.2 with one-token fix suggestions from the source's own pool.
node scripts/forge.mjs site.json --target ./customer-frontend

# 3. Build: the vendored semantic classifier (src/forge/semantic.ts)
#    rebuilds Elementor sections as ADS design components
#    (src/components/design/ — hero/feature-grid/testimonial/logo-cloud…);
#    unrecognized content stays on the .post-body path. dist/design-audit.json
#    ships the contrast audit. --strict-contrast fails the build on failing
#    pairs (manifest designAudit: strict).

# 4. Verify after a build. EVERY gate runs by default — language parity, token
#    block, band flow, nav anchors, fact consumption, text, SEO, tracking, box
#    geometry, interaction states, colour system and pixel — and prints a
#    scorecard (gate, verdict, pass rate). `--minimal` keeps only the
#    deterministic, browser-free gates; that is what the CI blocking step uses.
#    Individual flags still select one gate:
#    language parity (always on), fact consumption (always on — every fact a
#    band declares must leave its marker in that band's HTML; --strict-facts
#    to fail), box geometry (--geometry — measures each band at 1440/834/390
#    and diffs against the baseline `analyze --geometry` recorded in the
#    profile), interaction states (--interactions — forces hover / focus /
#    focus-visible / active on the BUILT page and requires every state the
#    source declared plus a keyboard focus ring on every control; per-site
#    `verify.gates` may downgrade it to advisory), content parity (--text — the
#    build's visible text against the baseline `analyze --text` recorded;
#    normalised 3-word shingles, coverage of the SOURCE is the gate), the colour
#    system (--colors — computed background/text of body, header and footer,
#    diffed with CIEDE2000), and pixel parity (--pixel — deterministic
#    screenshots diffed with pixelmatch's delta + SSIM). Geometry, interactions,
#    pixel and colour need Playwright; when the browser is not installed those
#    gates record `skip` rather than aborting the run (a skip still counts
#    against 100%). Language/text parity never need a browser.
#
#    Scorecard semantics: `pass`/`warn`/`fail` are verdicts, `skip` means a
#    gate is APPLICABLE but has no baseline (it counts against 100%), and
#    `n/a` means structurally inapplicable (outline-only site, no tracking
#    block) and is excluded from the denominator.
#
#    Colour is measured per scheme (--colors-modes light,dark, the default) so
#    a dark header in light mode and an invented dark mode both surface; it now
#    also measures ONE REGION PER BAND ANCHOR (`band:home`, `band:about`…) using
#    the COMPOSITED background, so a band surface that stops rendering is a
#    deviation instead of a silent transparent skip. Bands are read with
#    `descendantFill` on BOTH sides (the rebuild paints the surface on the
#    `.ds-section-wrap` or a child), and the painted gradient is a third metric:
#    a source gradient missing from the build fails, a differing one warns, a
#    photo url is never compared (the localizer rewrites it). Pixel captures
#    default to light and take --pixel-mode light|dark|both.
node scripts/forge.mjs verify site.json --target ./customer-frontend            # every gate
node scripts/forge.mjs verify site.json --target ./customer-frontend --minimal  # browser-free gates (CI blocking)
node scripts/forge.mjs verify site.json --target ./customer-frontend --geometry
node scripts/forge.mjs verify site.json --target ./customer-frontend --text
node scripts/forge.mjs verify site.json --target ./customer-frontend --colors

# 5. Pixel parity is a REGRESSION gate: record a baseline from a known-good
#    build, then every later verify diffs against it (0.1% pixels / SSIM 0.98
#    to fail). --pixel-live compares against the live SOURCE instead — useful
#    for near-clone work, meaningless for a language-level rebuild.
node scripts/forge.mjs verify site.json --target ./customer-frontend --pixel --pixel-baseline
node scripts/forge.mjs verify site.json --target ./customer-frontend --pixel
node scripts/forge.mjs verify site.json --target ./customer-frontend --pixel --pixel-live

# 6. Capture the geometry, text and colour baselines alongside the design block.
#    `--geometry` also records each band's measured interaction STATES (hover /
#    focus / focus-visible / active) and the rest paint they are relative to.
node scripts/forge.mjs analyze https://customer.example --design --geometry --text --colors \
  --write site.json

# 7. Every verify run also audits EACH BAND (presence · surface · content · facts ·
#    text · geometry) and writes <target>/verify-artifacts/bands.json. A check it
#    cannot evaluate is SKIPPED, never passed; a failing band fails the run
#    (--bands-warn downgrades, --no-bands opts out).
node scripts/forge.mjs verify site.json --target ./customer-frontend --minimal
node scripts/forge.mjs status site.json --target ./customer-frontend   # owner + drift + bands

# 8. When the site is synced and a developer takes over, LOCK it. A locked
#    project is fail-closed: scaffold/deploy refuse to overwrite it, and the
#    project's `npm run check` reports hand-edits as expected drift.
node scripts/forge.mjs lock   site.json --target ./customer-frontend --by "Dev name" --reason "custom dev handoff"
node scripts/forge.mjs unlock site.json --target ./customer-frontend --yes   # returns it to the forge

# 9. Template CSS fixes that reach a BUILT project (a hand-copied global.css, a
#    cherry-picked shim) wipe the `--forge-*` token block — only the scaffold
#    pipeline rewrites it, so the build silently falls back to template defaults.
#    Re-apply just that span instead of re-scaffolding (which reinstalls deps).
node scripts/forge.mjs tokens site.json --target ./customer-frontend --check   # drift report, exit 1
node scripts/forge.mjs tokens site.json --target ./customer-frontend           # rewrite in place

# 10. Compare individual ELEMENTS when a gate is too coarse to name the culprit.
#    The spec names a selector and the properties to read on BOTH pages (a CSS
#    name, or rect.width|height|left|centre); a property that differs by more
#    than --tolerance fails the command unless --soft. Specs live in
#    compare/<site>.json — NOT in sites/: the fleet workflow enumerates
#    sites/*.json as site profiles and scaffolds each one, so a spec left there
#    was built as a site and failed assertProfile (2026-11-21).
node scripts/forge.mjs compare site.json --target ./customer-frontend \
  --elements compare/monicarajiv.json
node scripts/forge.mjs compare site.json --target ./customer-frontend \
  --elements compare/monicarajiv.json --path /accommodations/ --width 390 --soft
```

### Environment per site (build time vs runtime)

Two different surfaces, easy to confuse:

| Phase | Who reads it | Variables |
|---|---|---|
| Build (`npm run build`, CI) | the content loader fetches the CMS | `WP_SITE_URL`, `ASTRO_SITE`, `WP_FETCH_PROXY`, `WP_APPLICATION_USER` / `WP_APPLICATION_PASSWORD`, `WP_WC_CONSUMER_KEY` / `WP_WC_CONSUMER_SECRET`, `WP_USER_AGENT` |
| **Analysis (CLI)** | `forge analyze` / `forge gate` fetch the **rendered HTML** | `WP_GATE_MODE`, `WP_GATE_USERNAME` / `WP_GATE_PASSWORD`, `WP_GATE_COOKIE`, `WP_GATE_STORAGE_STATE`, `WP_GATE_QUERY`, `WP_GATE_HEADERS_JSON`, `CF_ACCESS_CLIENT_ID` / `CF_ACCESS_CLIENT_SECRET`, `WP_GATE_HTTP_AUTH` |
| Runtime (managed SSR) | the BFF proxies `/api`, `/media`, `/api/forms/submit` | `NVOOS_TARGET_SITE`, `NVOOS_REVALIDATE_TOKEN`, `MAIL_*`, `BREVO_API_KEY` |

`scaffold` writes `.env.example` with every **non-secret** value already filled
in from the profile: `WP_SITE_URL` from `url`, `NVOOS_TARGET_SITE` from `url`,
`NVOOS_FORGE_FORMS_ORIGIN` from `deploy.publicOrigin`, and
`MAIL_PROVIDER` / `MAIL_FROM` / `MAIL_FROM_NAME` / `MAIL_TO` / `MAIL_SUBJECT`
from `forms.mail`. Only credentials stay blank, and `.env` is git-ignored.

```sh
# virtuallyjamaica.com — build time
WP_SITE_URL=https://virtuallyjamaica.com
ASTRO_SITE=https://virtuallyjamaica.com      # deploy.publicOrigin; CI sets it
WP_FETCH_PROXY=http://user:pass@proxy:3128   # the bot-protection workaround

# virtuallyjamaica.com — runtime (deploy.runtime: managed)
NVOOS_TARGET_SITE=https://virtuallyjamaica.com
NVOOS_REVALIDATE_TOKEN=…                      # secret; unset disables /api/revalidate
MAIL_PROVIDER=brevo
MAIL_FROM=forms@virtuallyjamaica.com
MAIL_TO=info@virtuallyjamaica.com
BREVO_API_KEY=…                               # secret
```

`WP_APPLICATION_*` does **not** fix a Cloudflare 403 — the request is rejected
before WordPress sees it. Use `WP_FETCH_PROXY`, or allowlist the builder IP.
Secrets never go in the profile or the manifest; they live in the host
environment (Velocity's env panel, or the CI secret store).

### A gated source — maintenance / coming-soon / staging auth (152)

WordPress has **two credential planes**, and an Application Password only
covers one of them. `WP_APPLICATION_*` authenticates `wp/v2`; it cannot log
into `wp-login.php` (WordPress says so explicitly), so it cannot satisfy a
plugin gate that checks `is_user_logged_in()`. A pre-launch site gates the
**rendered HTML** (`503 + Retry-After`, or a `200` holding page) while
`/wp-json/` stays open — which is why content forges and design does not.

```sh
# diagnose first — exit 0 open, 3 gated, 4 edge-auth, 5 rate-limited
node scripts/forge.mjs gate sites/<name>.json

# a user the gate's role allowlist accepts (the common case)
WP_GATE_USERNAME=forge-reader WP_GATE_PASSWORD='…' \
  node scripts/forge.mjs analyze https://cms.example.com --design --write sites/<name>.json
```

Mechanisms, in precedence order: `WP_GATE_COOKIE` (a captured session — the
only option on a 2FA site) → `WP_GATE_STORAGE_STATE` (Playwright) →
`WP_GATE_USERNAME` / `WP_GATE_PASSWORD` (one login per process) →
`WP_GATE_QUERY` / `WP_GATE_HEADERS_JSON` (a plugin bypass token). Edge gates
are additive: `CF_ACCESS_CLIENT_ID` / `CF_ACCESS_CLIENT_SECRET` (Cloudflare
Access service token) or `WP_GATE_HTTP_AUTH` (staging Basic auth).

`analyze` **refuses** to forge a gated source (`--allow-blocked` overrides,
and the design block will be empty). `forge gate --login --vault` stores the
credential in the encrypted vault instead of the environment. Credentials are
never sent to any host but the CMS, never written to the profile or the
manifest, and every gated request is `Cache-Control: no-store` (an edge cache
must not serve the authenticated page to the public).

The probe is an HTTP fetch, so a **loopback dev site** now needs the documented
exemption for every `analyze` run, not only `--design`/`--geometry`:
`NVOOS_FORGE_ALLOW_LOOPBACK=1 node scripts/forge.mjs analyze http://localhost:8080 …`.

**With the forge bridge installed (≥ 0.3.2) the probe stops guessing.** Every
probe also asks `GET /wp-json/nvoos-forge/v1/gate`, which reports the site's
gate state (`maintenance`, `mode`, `plugin`) without any credential — that is
the only signal that separates "gate off" from "the edge served a cached
anonymous page". The bridge reports what an **anonymous** visitor sees, so a
successful `WP_GATE_*` login always wins over a `maintenance: true` verdict.
No bridge → `404` on that route → the probe falls back to its HTML table.

### Publication gate parity — the built site honours Elementor (165)

`gate` (152) is how the forge gets *in* to a gated source. `gatePublish` is how
the **built** site behaves while the CMS is gated:

```jsonc
// site.json
{
  "deploy": { "runtime": "managed", "manage": true },
  "gatePublish": {
    "honor": "auto",              // auto | coming_soon-only | off
    "trust": "bridge",             // bridge (default) | probe (HTML inference)
    "retryAfterSeconds": 600,      // 503 hint, capped at 86400
    "static": { "policy": "replace" }, // replace the artifact | keep it
    "bypass": { "ttlSeconds": 43200, "cookieName": "nvoos_gate" },
    "session": { "bypass": true, "cookieName": "nvoos_forge_session" }
  }
}
```

| Tier | Behaviour |
|---|---|
| managed | the emitted `server.js` front controller answers `503 + Retry-After` (maintenance) or `200 + noindex` (coming soon) **before** the Astro router; `/api/*`, `/manage`, `/media/*`, `robots.txt` and `_astro/*` stay reachable |
| static | the post-build pass replaces the artifact with the gate page (or keeps it when `policy: "keep"`), empties the sitemap, sets `noindex` + `no-store` |

**A signed-in manager sees the real site — the Elementor behaviour.** With
`deploy.manage: true` the scaffold emits `/manage` (a vanilla, server-rendered
form; no React island) and `POST /api/manage/login`, which verifies a WordPress
**Application Password** server-to-server via `wp/v2/users/me?context=edit` and
mints a host-only HMAC session. The gate verifies that session **locally**, so
preview keeps working while the CMS is in maintenance (or unreachable).

```text
NVOOS_TARGET_SITE=https://cms.example.com   # the CMS origin
NVOOS_MANAGE_SECRET=<random>                # signs the /manage session (falls back to the gate secret)
NVOOS_FORGE_GATE_SECRET=<random>            # operator bypass links: /?nvoos-gate=<token>
```

Flow: open `/manage` → sign in → the staff panel links to the real site,
WP admin and (on an ECA profile) the Activities screen → the same browser sees
the real site; every other visitor still gets the gate. The sign-in requires
`edit_posts` (or `manage_options`); a subscriber is refused. No secret means
fail-closed sign-in, and the operator `?nvoos-gate=` link remains the
zero-dependency fallback.

**The editor can be embedded instead of linked (`deploy.manageBundle: true`).**
With the bridge's standalone bundle present, the scaffold **vendors**
`astro-admin.{js,css}` into `public/manage-assets/` and `/manage` becomes the
same Astro CMS the velocity app serves — posts, pages, products **and**
activities in one editor, on the site's own origin.

| `deploy.manageBundle` | `/manage` renders |
|---|---|
| absent / `false` | §7a — the staff panel, linking to wp-admin (+ the ECA screen) |
| `true` | §7b — the embedded editor; the panel stays as the fallback when the bridge is absent, the account lacks `manage_astro`, or the CMS is unreachable |

How it stays safe:

- **The Application Password never reaches the browser.** Sign-in seals it into
  a second host-only, `HttpOnly` cookie (`nvoos_forge_cred`, AES-256-GCM keyed
  by HKDF from the manage secret, with the session uid as associated data).
  Only `/api/*` — the BFF — decrypts it, per request.
- **A credential-scoped response is never shared.** The proxy forces
  `Cache-Control: private, no-store` whenever it attaches the credential, so an
  edge cache cannot hand a manager's draft listing to the next visitor.
- **SRI pins the bundle.** The page emits `integrity="sha384-…"` on both files
  (the tokens are also recorded in `nvoos.json` → `admin.bundle` and
  `src/lib/manage.generated.ts`), so a stale or substituted bundle is **refused
  by the browser** instead of silently running mismatched code.
- **`/manage` ships no inline script.** The session config travels as a
  `type="application/json"` data block and
  `public/manage-assets/manage-boot.js` does the injection, so the page keeps
  working under `script-src 'self'` and never depends on `'unsafe-inline'` —
  the CSP standard the velocity app enforces. The bundle is also preloaded
  (`fetchpriority="high"`) on the signed-in render only.
- **Fail closed at scaffold time.** A profile that opts in without a usable
  bridge checkout aborts with a named error (`npm run build:standalone` in
  `addons/nvoos-astro-bridge`, or point `NVOOS_FORGE_BRIDGE_DIR` at the addon
  root) rather than emit a `/manage` page whose bundle 404s.
- **The bundle lands in the CUSTOMER project only** — never in
  `templates/blog/`, which is mirrored to the public forge repo.

Rebuild the bundle after any bridge SPA change, then re-scaffold so the SRI
hash and the vendored bytes move together; a hand-edited copy fails the
`npm run check` drift gate because both generated modules are manifested.

**Activities (ECAs) are editable from the CMS admin, not only wp-admin.** Bridge
`1.2.0` serves `content/ecas` + `content/eca-categories`, and the Astro CMS
bundle carries both collections (posts, pages, products **and** activities in
one editor). The nav entry is gated on the server-authored
`user.features` list — present only while the Pro toolkit's
`enable_eca_management` setting is on — so a site without the feature never
offers a route that would answer `eca_disabled`. Deleting an activity trashes
it (activities with enrolments are never hard-deleted without an explicit
`?force=1`). See the 165 runbook's "Open items".

Gate pages ship at `/maintenance/` and `/coming-soon/` (no JS, no third-party
assets, one `h1`, `noindex`). Design, acceptance criteria and the ECA first-run
runbook live in `docs/project/proposals/165-forge-maintenance-mode-parity-*.md`.

### Forms — JetFormBuilder + Elementor submissions (114 §3.9)

Forms integrate the way WooCommerce did: **content at build time, behavior
proxied through WordPress.** The sanitizer keeps a functional form allowlist
(no `action`/`method`/`on*`/`style`), the classifier rebuilds every source
`<form>` as the `Form` design component, and the static site posts to the
bridge plugin's `forms/submit` route — the bridge dispatches to the NATIVE
JetFormBuilder / Elementor Pro handler, so validation, storage, mail, and
post-submit actions run exactly as on WordPress.

```jsonc
// site.json — the forms block (all optional; env vars override at deploy).
{
  "forms": {
    "endpoint": "https://shop.example.com/wp-json/nvoos-forge/v1", // default: <profile.url>/wp-json/nvoos-forge/v1
    "origin": "https://customer.example.com",                      // the bridge's CORS allowlist entry
    "searchForms": ["find"]                                        // extra single-input names mapped to /search/
  }
}
```

Rendered behavior:

- **Search forms** (single search-ish input, or per-site `searchForms` names) map onto the site's own `/search/` surface — a GET to `/search/?q=…`.
- **Submit forms** rebuild with the bridge endpoint as their `action` and carry the WP-side form identifier (`form`), the source `post_id` when the markup carries one, a honeypot, a client timestamp, and progressive-enhancement fetch. No resolvable WP form → the section degrades to a plain title/links band — never a dead endpoint.
- **The bridge** (`plugins/nvoos-forge-bridge` ≥ 0.2.0) runs the origin allowlist, per-IP rate limit, honeypot/timestamp spam ladder, optional Turnstile (action + hostname pinned), server-side sanitization, and typed 404/501 contracts. The no-JS fallback is an ordinary form POST + redirect back with `?form=ok|error`.
- **Config** ships in `.env.example` (`NVOOS_FORGE_FORMS_ENDPOINT`, `NVOOS_FORGE_FORMS_ORIGIN`) — never hardcoded; the allowlist is configured in the bridge plugin's settings page.

### Storefront profiles (Phase 1a)

```json
{
	"url": "https://shop.example.com",
	"name": "Example Store",
	"frontPage": "home",
	"collections": [
		{ "slug": "pages", "restBase": "wp/v2/pages", "fields": ["id", "title", "slug", "content"],
		  "embed": true, "exclude": ["checkout", "lost-password"] },
		{ "slug": "products", "restBase": "wc/v3/products", "kind": "product",
		  "authMode": "wc-consumer", "fields": ["id", "name", "slug", "permalink", "description",
		  "price", "regular_price", "sale_price", "stock_status", "sku", "images", "categories"] }
	]
}
```

- `kind: "product"` enables commerce extraction — wc/v3 flat documents
  (price/stock/sku/images/categories) via `WP_WC_CONSUMER_KEY`/`_SECRET` in
  `.env`, or wp/v2 CPTs (no commerce fields; featured media resolved by ID).
- `source: "payload"` reads the `nvoos-commerce/v1` envelope served by the
  nvoos-astro-bridge (body pagination `{ docs, totalDocs, totalPages }`,
  flat documents with `price`/`oldPrice` sale semantics).
- `exclude` filters junk/system slugs; `frontPage` renders the WordPress
  static front page at `/` (single canonical URL); `optional: true` lets a
  source be absent (empty collection, warning, not a failed build).
- `tokens` applies the 098 design language over the `--forge-*` defaults at
  scaffold time (`{ "accent": "#720eec", ... }`); the scaffold warns when
  rendered pairs fail WCAG AA (SC 1.4.3) — warnings, never blocks.

### SEO surfaces (generated per site)

- `/feed.xml` (RSS 2.0), `/404.html`, `/sitemap.xml`, `/robots.txt`.
- `/search/` — client-side full-text search (Pagefind, indexed post-build).
- `/redirects.json` — the structured 301 map from the WP URL structure to the
  generated one; the post-build step materializes the host-agnostic
  `dist/_redirects` (Netlify/Cloudflare/static-server compatible).
- SEO meta rides the REST fields: Rank Math (`rank_math_title` /
  `rank_math_description`) → Yoast (`yoast_head_json`) → the WP
  title/excerpt fallback, wired into `<title>`, the meta description and
  the JSON-LD (Proposal 106 §1.2; absent plugins never break a build).

### Hybrid local content (Proposal 106 P2)

Every scaffold ships `src/content/posts/` + `src/content/pages/` — local
`.mdx` files (frontmatter: `title`, `description`, `date`, `draft`) merge
under the WP posts/pages collections: **WP wins on collision, local fills
the gaps, drafts never ship**. The example post makes a forged site
build-and-render even with **no WordPress connection** — a network-level
failure degrades to local-only with a loud loader warning (HTTP-level
errors still fail the build).

### Materialized content (a site that outlived its CMS)

`content.source: "materialized"` declares the CMS **gone**. The snapshot
beside the profile (`sites/<name>.content/`, written by
`forge materialize`) is then the only source:

- the scaffold copies it into `src/content/`, points **every** collection at
  its `src/content/<slug>/` glob, emits `localOnly: true`, and marks every
  collection `optional`;
- the generated loader **never touches the network** — a dead origin that
  answers 404, 200 HTML, or nothing at all cannot fail the build;
- `forge <profile> --check` reports the materialized state instead of
  probing the dead CMS;
- the managed env example leaves `NVOOS_TARGET_SITE` blank (the BFF answers
  503) rather than pointing it at the public origin, which would make `/api`
  proxy to itself.

`content.snapshot: true` is the opposite promise: the CMS stays authoritative
and the snapshot is only the outage fallback (WP still runs first).

### Media pipeline

Same-host WordPress images flow through **Astro Image** (`SmartImage.astro` —
responsive WebP/AVIF via `widths`/`sizes`/`inferSize`, lazy): the config
allowlists the WP host from `WP_SITE_URL ?? nvoos.json`. Images on any other
host (CDNs, external) pass through as plain `<img>` — never a build failure.

## License

MIT via `LICENSE` + `NOTICE` (owner decision 096 Q7 — mirrors the 092 Q8
storefront split). The scaffold ships the NOTICE into every generated
project; generated sites are the customer's own. Published to npm as
`@nvdigitalsolutions/nvoos-astro-forge` (tag `nvoos-astro-forge-vX.Y.Z`
through `.github/workflows/publish-astro-forge.yml`).

## Safety invariants (Proposal 096 §2)

1. **No plaintext credentials** — auth comes from `.env`
   (`WP_APPLICATION_USER` / `WP_APPLICATION_PASSWORD`), never from the
   profile or the generated repo.
2. **SSRF-safe by construction** — the forge consumes operator-configured
   site profiles; the open-world fetch surface lives behind the
   `/mcp` `design_analyze` guard (Proposal 098), not here.
3. **Mandatory sanitization** — WordPress HTML passes the versioned
   allowlist before `set:html`. Scripts, event handlers, `javascript:`
   URLs, and non-allowlisted embed hosts never reach a page.
4. **Never silent truncation** — `fetchAll` returns `truncated: true`, the
   loader warns, entries carry the flag, pages show a notice.
5. **Built assets never ship** — the scaffold gate guarantees `dist/` and
   `.astro/` are git-ignored in the customer repo.
6. **Deploys default private, auth never persists** — repos are created
   private unless `--public` is explicit; the GitHub token comes from
   `GITHUB_TOKEN` or the vault for one invocation, is masked in every
   output, and is never written to disk.
7. **Fail-closed rebuilds** — the generated pipeline refuses stale or
   mis-signed dispatch events before any build runs (Phase 3.3
   `verify-dispatch.mjs`).

## Phase 3 — vault, multi-site, deploy

```sh
# Register named sites (profiles stay operator-owned files).
export NVOOS_FORGE_HOME=~/.nvoos-forge
node scripts/forge.mjs site add store site.json --default
node scripts/forge.mjs site list
node scripts/forge.mjs site use store

# Store the deploy token (value on stdin — never argv, never the shell
# history). Requires NVOOS_FORGE_VAULT_KEY (any passphrase).
export NVOOS_FORGE_VAULT_KEY='a long passphrase'
printf 'ghp_…' | node scripts/forge.mjs vault set github

# Deploy: scaffold if needed, emit the pipeline, create a PRIVATE repo,
# push the source. The workflow builds + ships to the target; configure
# FORGE_WEBHOOK_SECRET (plus CLOUDFLARE_* / VELOCITY_* per mode) as repo
# secrets afterwards.
node scripts/forge.mjs deploy store --target ./customer-frontend \
  --to github-pages                 # or cloudflare-pages (--cloudflare-project X) / velocity
```

Deploy modes are adapter-generic: `github-pages` (Pages artifact deploy),
`cloudflare-pages` (wrangler), or `velocity` (connected-repo mirror of
`dist/`). Every mode gets the same `rebuild.yml` — a `repository_dispatch`
wrapper whose `verify-dispatch.mjs` re-verifies the bridge plugin's HMAC
proof (freshness window + constant-time compare) before building.

The vault is AES-256-GCM per entry (wrong key or tampering fails closed);
`NVOOS_FORGE_VAULT_PLAINTEXT=1` is the documented 0600-file fallback for
fixtures only.

## Phase 4 — the wizard and the MCP surface

```sh
# One command: URL → analyzed → preset → registered → scaffolded → built →
# git → deploy pipeline. (Local dev sites need NVOOS_FORGE_ALLOW_LOOPBACK=1.)
node scripts/forge.mjs wizard https://shop.example.com \
  --target ./customer-frontend --build

# …with an explicit preset (otherwise suggested from the analysis),
# a render variant, and per-post OG cards.
node scripts/forge.mjs wizard https://shop.example.com \
  --target ./customer-frontend --preset minimal --no-agents
node scripts/forge.mjs wizard https://shop.example.com \
  --target ./customer-frontend --preset storefront --render ssr --og-images

# The MCP server — dual-era Streamable HTTP (team mode) …
npm run mcp                        # http://127.0.0.1:8472/mcp (FORGE_MCP_PORT to change)

# … or the stdio entry for coding agents (npx nvoos-forge-mcp-stdio).
npx nvoos-forge-mcp-stdio
```

- **Tool catalog (v1, 29 tools + 3 meta-tools):** `forge_help` /
  `forge_plan` / `forge_status` (the `NVOOS_FORGE_MODE=router` surface),
  `site_add|test|list|get|update|remove|set_default`, `site_analyze`,
  `scaffold_project|status`, `tokens_apply`, `scaffold_package`,
  `content_audit`, `content_preview`, `redirects_generate`, `media_audit`,
  `collections_generate`, `rebuild_config`, `rebuild_trigger`,
  `preview_token`, `webhook_verify`, `github_create_repo|commit|status`,
  `deploy_config`, `setup_wizard`.
- **Single-source registry** (`mcp/registry.ts`): every tool declares a
  zod schema, explicit MCP annotations (read-only/destructive/open-world),
  and a write flag — CI fails when any is missing.
- **Write tools fail closed:** a bearer `NVOOS_FORGE_MCP_TOKEN` is
  required (timing-safe compare); unset = every write is refused with
  401 `-32001`. Reads pass unauthenticated. Rate limiting, Host/Origin
  validation, and per-IP audit logging wrap every request.
- **SSRF-guarded fetches:** `site_analyze` accepts registry hosts, or any
  host under the `NVOOS_FORGE_OPEN_ANALYZE=1` opt-in — private/reserved/
  literal-IP ranges are always refused (`lib/ssrf.ts`). Scaffold targets
  are confined to `NVOOS_FORGE_WORKDIR`.
- **Registry entry** (061 pattern): `server.json` — the MCP registry
  metadata (`io.github.nvdigitalsolutions/nvoos-astro-forge`); it is NOT
  part of the npm package.

### Presets (Proposal 104 §4.3)

```sh
node scripts/forge.mjs site.json --target ./customer-frontend --preset blog       # default
node scripts/forge.mjs site.json --target ./customer-frontend --preset storefront
node scripts/forge.mjs site.json --target ./customer-frontend --preset minimal
node scripts/forge.mjs site.json --target ./customer-frontend --theme-policy dark # 105 §4
```

- `--preset` picks blog/storefront/minimal (collection profile + surface
  toggles); the wizard suggests one from the site_analyze report (Woo/products
  detected → storefront; on a TTY it asks via @clack/prompts, in CI/e2e the
  suggestion applies deterministically).
- `--theme-policy auto|light|dark|brand:<slug>` (default `auto`) bakes the
  site-level theme decision into `src/lib/theme/policy.generated.ts` — the
  layout boot applies the policy before any stored preference (forced modes
  disable the theme toggle), and the pure ladder lives in
  `src/lib/theme/policy.ts` (Proposal 105 §4).

| Preset | Template | Collections | Surfaces |
| --- | --- | --- | --- |
| `blog` | `blog` | as discovered (the Phase 0 default) | search, feed, sitemap |
| `storefront` | `blog` | blog + products (`wc/v3`, consumer-key auth) + front page | search, feed, sitemap |
| `portfolio` | `blog` | posts/pages + an **optional** `projects` CPT (added only when no portfolio-ish CPT exists) | search, sitemap — no feed (browsed by facet, not subscribed) |
| `landing` | `blog` | posts/pages, commerce pruned; sets `shape: one-pager` | sitemap only — a one-pager has nothing to search and nothing to subscribe to |
| `docs` | `blog` | posts/pages, commerce pruned (the page hierarchy is the site) | search, sitemap — no feed |
| `minimal` | `blog` | posts/pages only | feed, sitemap — **no search** (page + Pagefind step + nav link pruned) |

Preset invariants, enforced by `presets.test.ts`: **commerce is the only thing a
content preset prunes** (an arbitrary CPT is content and survives every preset),
and a preset **never invents a taxonomy** — it only ensures the one collection it
can honestly default. `suggestPreset()` picks storefront → portfolio → blog from
the discovery report; `landing` and `docs` are only ever chosen explicitly,
because shape and reading order are operator intent, not something a probe can
see.

### Template variants (`--variant <name>`)

A variant is an **additive overlay** on the base template, not a second
template: `templates/blog` stays the single source of truth, so a base fix
reaches every variant for free. The six rules a new variant must clear — and
the candidates already screened out — live in
[`templates/variants/VARIANTS.md`](templates/variants/VARIANTS.md).

```sh
node scripts/forge.mjs site.json --target ./frontend --preset storefront --variant product-3d
node scripts/forge.mjs site.json --target ./frontend --preset landing --variant video-hero
```

| Variant | Fits | Adds | Needs |
| --- | --- | --- | --- |
| `product-3d` | `storefront` | `src/lib/product-model.ts`, `src/components/ModelViewer.astro` | a self-hosted `public/vendor/model-viewer.min.js`; a product `model3d` field |
| `video-hero` | `storefront`, `portfolio`, `landing` | `src/lib/hero-video.ts`, `src/components/VideoHero.astro` | a poster + MP4 (WebM optional) under `public/media/` |

`scaffold()` refuses an incompatible preset/variant pair before any write, and
`suggestedVariant()` reports the first variant a preset can carry — the scaffold
prints it as a hint, never applying it automatically, because `site_analyze`
cannot see whether product records carry a `model3d` field.

**`product-3d`:** the operator authors `model3d = { src, poster?, alt?, ar? }`;
`poster` falls back to the first catalogue image and `alt` to the product name.
`src`/`poster` must be root-relative or `https` (a protocol-relative `//` is
rejected, mirroring the sanitizer). See
`templates/variants/product-3d/VARIANT.md`.

**`video-hero`:** the poster is the LCP element (`eager` +
`fetchpriority="high"`), the video is `preload="none"` so zero media bytes move
until playback starts, and `shouldAutoplay()` vetoes reduced-motion, Save-Data
and 2G. The poster is faded with `opacity` — never `display: none` — so its
`alt` stays in the accessibility tree. See `templates/variants/video-hero/VARIANT.md`.

### Render variant (Proposal 104 Q4 — `--render static|ssr`)

```sh
node scripts/forge.mjs site.json --target ./customer-frontend --render ssr
# wizard: the storefront preset prompts static-vs-ssr on a TTY; non-TTY stays static
```

- `static` (default) — today's template, untouched.
- `ssr` — the static product pages are swapped for the on-demand route
  (`/products/{id}/{slug}/`), `astro.config.mjs` gets `output: 'server'` +
  `@astrojs/node` standalone. The route fetches ONE product from the site's
  REST at request time (same client + auth env as the content layer),
  sanitizes the description, emits `Product` JSON-LD in the initial HTML,
  404s unknown ids, 301s slug drift to the canonical URL, and sends
  `Cache-Control: public, s-maxage=300, stale-while-revalidate=600`.
  Everything else (home, posts, pages, PLP, search, SEO endpoints) stays
  build-time static — mirroring the velocity PDP-on-demand model — so
  Pagefind/sitemap/robots keep working.

### Per-post OG cards (Proposal 104 §4.8 / Q5 — `--og-images`)

Opt-in surface, OFF by default (default scaffolds stay zero-dep for OG).
When on, the scaffold ships `src/pages/og/[slug].png.ts` — build-time cards
for every post (the Cactus pattern) branded from the `--forge-*` tokens,
rendered with `satori` + `@resvg/resvg-js` + `@fontsource/inter` (deps
injected into the generated package.json only when the flag is on). Post
pages point `og:image` at the card; render failures warn and fall back to
a plain card — never a build failure.

### Surface toggles

`feed` / `sitemap` are declared on every preset (all shipped presets keep
them on). When toggled off at scaffold time the corresponding endpoints are
pruned and the chrome adjusts: the nav drops the search link, the head
drops the RSS link, and `robots.txt` drops its sitemap pointer.

## Research basis
### Phase 4 template additions (Proposal 104)

Every scaffold ships, unless `--no-agents`:

- **`AGENTS.md` + `.context/README.md`** (4.4) — project layout, commands, the
  storefront-runtime-contract pointer, “content lives in `src/content/`,
  config in `nvoos.json`”, plus the customer-context hook directory. Both
  are collected by `lib/project-files.ts` — CLI deploy and the
  `github_commit` tool push them.
- **Typed copy tables** (`src/copy/`) (4.5) — `en.ts` is the reference table
  (nav labels, footer, aria labels, empty states); a second locale is added
  as another `Copy`-typed table and a missing key fails `astro check`. The
  zero-dependency floor; the Paraglide upgrade path is documented in the
  template README.
- **`src/lib/seo.ts`** (4.6) — `kind`-typed `jsonLdFor('WebPage'|'BlogPosting'|'Product')`
  + `breadcrumbList()`, typed with `schema-dts`, with the `<` → `\u003c`
  escape; the posts/products pages consume it and the e2e gate pins the output.
- **`src/lib/theme/`** (4.2) — the velocity runtime theme module port
  (`theme.ts` + `registry.ts`, pure — no Astro imports), the documented port
  target for runtime theming (104 Q4: define-only, the boot script + toggle
  land later).
- **`src/styles/tokens-tailwind.css`** (4.7) — an `@theme inline` bridge over
  the `--forge-*` tokens; “import only if/when you add Tailwind v4; unused by
  default”. Ships byte-identical (no FORGE markers).

Generated projects must satisfy the **storefront runtime contract**
(Proposal 104) — see “Storefront runtime contract” in the template README:
no-FOUC theming + `astro:after-swap` re-apply + `color-scheme` sync, PDP
canonical + 301 slug convergence, `Product` JSON-LD in initial HTML, cache
headers + tags on commerce routes.

## Research basis

`docs/project/proposals/096-astro-forge-wp-generator-mcp-research.md` —
loader semantics, Woo auth (`Application Passwords`), sanitize-html
allowlisting, SEO (canonical/sitemap/JSON-LD), and hybrid-rendering
standards, verified 2026-10-31.
