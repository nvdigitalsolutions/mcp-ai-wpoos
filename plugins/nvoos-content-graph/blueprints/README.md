# Playground Blueprints — Project Asteria Demo

Dev-only working folder for the WordPress Playground demo of NV oOS Content
Graph. **Nothing here ships in the wp.org ZIP** (excluded via `.distignore`);
the canonical preview blueprint lives in
[`.wordpress-org/blueprints/blueprint.json`](../.wordpress-org/blueprints/blueprint.json),
which mirrors the SVN path `assets/blueprints/blueprint.json`.

| File | Role |
|---|---|
| `seed-content.php` | The Project Asteria content pack: 26 posts + 5 pages, 3 authors + The Editor, 5 categories, 10 tags. `{{slug}}` tokens in bodies become internal links → `LINKS_TO` graph edges. Three posts are true degree-0 orphans for the Content Gap Analysis. Idempotent (guarded by the `nvoos_cg_demo_seeded` option). |
| `demo.json` | **Generated.** Standalone blueprint for shareable links — installs the newest built plugin ZIP from the repo (`build/nvoos-content-graph-v*.zip`, highest version auto-discovered), seeds Asteria, builds the graph, lands on the Graph Explorer. |
| `_probe.json`, `_verify.json` | Throwaway validation blueprints (deleted after testing). |

## Regenerating

```bash
php bin/generate-content-graph-blueprint.php
```

The generator embeds `seed-content.php` into a `runPHP` step (stripping the
opening `<?php` tag) and writes both blueprint files. The standalone demo's
`installPlugin` URL points at the **newest** `build/nvoos-content-graph-v*.zip`
(highest version, served via CORS-enabled `raw.githubusercontent.com`) — no pin
bump needed when a new release ships. The generated JSON is committed — the
generator is optional tooling for content edits.

## Trying it

Standalone demo link (substitute the real branch):

```
https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/nvdigitalsolutions/mcp-ai-wpoos/<branch>/plugins/nvoos-content-graph/blueprints/demo.json
```

Local end-to-end validation (boots real WP in Node, installs the plugin from
the repo's built ZIP, runs every step):

```bash
npx -y @wp-playground/cli@3.1.54 run-blueprint \
    --blueprint=plugins/nvoos-content-graph/blueprints/demo.json \
    --verbosity=debug
```

Validated 2026-09-18 on WP 7.1.1 / PHP 8.3: 49 nodes, 255 edges, 5
communities, 3 orphans, "The Asteria Codex" ranked #1 God Node.

## Design notes

- **Preview-safe vs standalone.** wp.org Preview mode pre-installs the
  plugin, so `blueprint.json` intentionally has no `installPlugin` step (a
  self-install would replace the trunk build being previewed). `demo.json`
  owns installation — from the newest repo-built ZIP rather than
  wordpress.org, so the demo always reflects the current build.
- **Deterministic build.** The blueprint fires the plugin's public
  `nvoos_content_graph/initial_build` hook instead of relying on the
  activation one-shot cron, and disables `auto_rebuild` during the bulk seed
  so ~30 per-post incremental builds don't fire.
- **Pretty permalinks + rewrite flush** are set inside the seed step:
  `url_to_postid()` needs the rewrite rules to turn `{{slug}}` links into
  `LINKS_TO` edges.
- Full plan and validation checklist:
  [`docs/playground-blueprint-plan.md`](../docs/playground-blueprint-plan.md).
