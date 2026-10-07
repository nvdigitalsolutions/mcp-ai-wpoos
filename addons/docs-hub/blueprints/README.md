# Docs Hub Playground Blueprints

WordPress Playground demo blueprints for NV oOS Docs Hub — the same pattern
as `plugins/nvoos-content-graph/blueprints/` (Project Asteria).

## Files

| File | Purpose |
|---|---|
| `seed-content.php` | Demo seed (6 Markdown files + the `[nvoos_docs]` page). Embedded into the blueprints by the generator. |
| `demo.json` | **Standalone demo** — self-contained link: installs the newest `build/nvoos-docs-hub-v*.zip` (raw.githubusercontent.com, `alpha-working` branch), seeds the docs, rebuilds, lands on `/docs/`. |
| `../.wordpress-org/blueprints/blueprint.json` | **wp.org Live Preview** — the plugin is pre-installed by the preview loader, so it does NOT self-install. Mirrors SVN `assets/blueprints/blueprint.json`. |

## Regenerating

```bash
php bin/generate-docs-hub-blueprint.php            # re-embed the seed
php bin/generate-docs-hub-blueprint.php --plugin-url=<url>   # pin a ZIP
```

The generated JSONs are committed — the generator is optional tooling for
seed-content edits, not a build dependency. It globs `build/` for the
highest docs-hub version; the build-assets workflow regenerates the JSONs
right after rebuilding the ZIPs.

## What the demo does

1. `login` → admin session for the admin-side views.
2. `setSiteOptions` → demo blog name / tagline.
3. `runPHP` (seed) → pretty permalinks, a ten-page plugin wiki written
   into `wp-content/uploads/nvoos-docs-hub/content/` (the local-first
   source folder; `README.md` owns the `readme` slug so the default home
   page opens the wiki), and a published `/docs/` page containing ONLY
   the `[nvoos_docs]` shortcode. The seed also applies the theme's
   `page-no-title.html` template when it exists and writes full-page demo
   CSS via WordPress core Additional CSS (scoped to `body.page-id-{ID}`,
   using the theme's own alignfull mechanism) so the docs browser fills
   the whole page like a standalone SPA. Idempotent via the
   `nvoos_dh_demo_seeded` option.
4. `runPHP` (build) → deterministic synchronous rebuild
   (`NV_oOS_Docs_Hub_Rebuild_Job::run()`), summary stored in the
   `nvoos_dh_demo_build` option.

The demo is honest by design: no remote calls, no API keys, no settings
changes beyond the blog name — exactly the plugin's zero-setup workflow.
The GitHub-import page explains the opt-in remote path without enabling it.

## Packaging

`blueprints/` is dev-only: excluded from the release ZIP via `.distignore`,
the `docs-hub` step of `bin/build-addon-zips.sh`, and the
`build-spa-addons.yml` assemble step (keep the three in sync).

## Validation

Same harness as the content-graph demos:

```bash
MSYS_NO_PATHCONV=1 npx -y @wp-playground/cli@3.1.54 run-blueprint \
  --blueprint=addons/docs-hub/blueprints/demo.json
```

See `.agents/skills/mcp-ai-wpoos-playground-demos/SKILL.md` for the crash
modes, probe pattern, and browser caveats.
