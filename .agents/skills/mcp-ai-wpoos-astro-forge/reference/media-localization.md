<!-- Split out of ../SKILL.md on 2026-11-17. Content unchanged; moved here
     so the main skill stays under Zed's ~100 KB load limit. -->

## Proposal 139 — media localization (2026-11-09)

Full notes: `docs/project/proposals/139-forge-media-localization-proposal.md`.

A forged site must never hotlink the CMS. Astro's `<Image>` localizes what it
can see; everything else did not — body-content `<img>`, chrome logos/favicon,
collage photos, and any host outside `image.domains`. A domain move
(`jayainc.com` → `cms.jayainc.com`) therefore broke images.

Three layers, one contract:

- **Authorize** — `image.domains` = CMS host ∪ `profile.media.hosts` ∪
  `NVOOS_FORGE_MEDIA_HOSTS`; `SmartImage` reads the same rule from
  `src/lib/media.ts` instead of a single-host comparison.
- **Repair** — a `/wp-content/` or `/wp-includes/` path whose host is neither
  the CMS nor an allowlisted media host is re-pointed at the CMS origin
  (path + query preserved). Do NOT put the old domain in `media.hosts`: the
  repair is what makes a stale DB work; allowlisting the dead host makes the
  build fetch from the wrong place.
- **Mirror** — `scripts/localize-media.mjs` runs after `astro build` (before
  pagefind). Strategy follows the tier: `nvoos.json runtime: 'static'` →
  **mirror** every remaining candidate into `dist/media/<hash>-<slug>.<ext>`;
  `runtime: 'managed'` (bridge/BFF present) → **proxy** uploads to the
  runtime's same-origin `/media/<rest>` route (a rewrite, no download).
  **External stylesheets are mirrored with their fonts in BOTH modes**
  (theme icon sheets, plus the Google Fonts sheet when
  `NVOOS_FORGE_LOCALIZE_FONTS` is on — the `/media` proxy only matches uploads,
  so leaving the font chain remote kept a render-blocking cross-origin hop in
  a supposedly self-contained artifact). Icon glyphs therefore stop depending
  on the CMS host — and stop tripping a future enforced CSP. A face lists
  several encodings of the SAME font: one missing derivative must prune only
  that `src`, never the whole `@font-face` (a dead `.ttf` dropped et-line and
  its icons on virtuallyjamaica, 2026-11-15). **Linked documents** (an allowlist:
  pdf/doc/xls/ppt/odt/csv/txt/rtf/epub/ics/zip) are mirrored too, with their
  own cap and `#page=` fragments preserved; executables are never candidates.
  Non-image stored facts (the catalogue CTA, `chrome.iconCss`, the JSON-LD
  logo) are repaired at RENDER time via `repairMediaUrl` instead. Override:
  `NVOOS_FORGE_MEDIA_MODE=local|proxy|off`. Fail-soft (report in
  `.forge-cache/media-localize.json`, build never fails), idempotent, honours
  `WP_FETCH_PROXY`, caps per file (25 MB) and per build (500 MB).

- **Optimize** — Proposal 140 adds WebP re-encode (q82), intrinsic
  `width`/`height` fill, self-hosted webfonts with `font-display: swap`,
  dead `preconnect` stripping, and a `media budget` verify gate. Env vars,
  modes and gotchas:
  [`performance-budget.md`](performance-budget.md).

- **Font survival + deployed identity (2026-11-15)** — a mirrored sheet can
  keep its selectors, classes and markup while losing its `@font-face`, which
  is invisible to every design gate. Three defences: `.forge-cache/media-localize.json`
  records `droppedFontFamilies` with `used`; `verify` has a browser-free
  `font coverage` gate in `--minimal` and a browser `font render` gate (both
  `--strict-fonts` blocking); `Base.astro` marks a genuinely unusable marker
  `forge-icon-missing` and `global.css` draws a neutral bullet. A CMS-less
  site should also freeze its media: `forge media-snapshot <profile> --from
  <built-project>` writes `sites/<name>.media/` (URL-keyed manifest; CSS entries
  store the SOURCE sheet, so a second pass does not chase its own `/media/…`
  rewrites). `media.snapshot: true` makes it authoritative; the scaffold
  refuses a flag with no directory.

- **Stale releases** — a green build proves the artifact, not the live site.
  Set `FORGE_BUILD_ID` at build time (CI: `${GITHUB_SHA}-${GITHUB_RUN_ID}`);
  `Base.astro` stamps `<meta name="forge-build">`, `mirror-push` records it in
  `deploy.json`, and `forge deployed <profile> --target <dir> [--strict]`
  compares the live marker (with retries for Velocity's async deploy). The
  monorepo smoke runs it post-push; the generated pipeline emits
  `scripts/verify-deployed.mjs` as a non-blocking step.

Config: `profile.media { hosts, localize, mode, snapshot, maxFileMb }` → `nvoos.json` +
`.env.example`; env wins. `media.mode` (`local|proxy|off`) is how a managed
site — whose default is the bridge’s same-origin `/media` proxy — opts into a
fully self-contained artifact: `"media": { "localize": true, "mode": "local" }`.

**Never rewrite** `<a href>`, `<meta>` (`og:image` must stay absolute) or
JSON-LD — a relative path there is invalid, and the catalogue PDF must keep
working. Gotchas: emit `url(/media/…)` **unquoted** (a nested quote breaks a
`style="…"` attribute); attribute regexes need `(?:^|\s)` / `(?<![\w-])` so
`src` does not match inside `data-src`; SSR output lives in `dist/client`;
and a flaky `EPERM`/`ENOTEMPTY` scaffold timeout under 61 parallel workers is
the Windows staging race — re-run with `--maxWorkers=4` before debugging code.
- **In-process fixture server + `spawnSync` deadlocks:** an integration test
  that serves HTTP from the test process and runs the CLI synchronously
  hangs until the request timeout — the parent's event loop is blocked,
  so it can never answer the child. Spawn asynchronously; await the exit.
