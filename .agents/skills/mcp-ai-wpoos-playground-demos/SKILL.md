---
type: Skill
name: mcp-ai-wpoos-playground-demos
description: "Operational guide for the NV oOS WordPress Playground demo blueprints — the Content Graph \"Project Asteria\" demo and the Complete bundle × local Ollama demo. Covers blueprint authoring patterns (embedded runPHP seed snippets, generator scripts, preview-safe vs standalone split), CORS hosting gotchas, the CI Plugin Check gate (built-ZIP scan, rsync/distignore sync, ABSPATH guards), Playground worker crash modes (PHP-side localhost fetches, inline-script UTF-8, SPA mount bursts), local Ollama integration (OLLAMA_ORIGINS, browser policy matrix, plugin settings + assistant meta keys), and the CLI validation harness. Use when extending or debugging the demo blueprints, adding a new Playground demo, or triaging a crashing Playground instance."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.81"
  plugin-version-tested: "1.1.81"
  last-updated: "2026-09-18"
---

# NV oOS Playground Demos — Blueprint Authoring & Local-Ollama Playbook

Playbook distilled from building and debugging the two Playground demo
blueprints in PR #6662 (Content Graph) and PR #6663 (Complete × Ollama),
including two full Playground-instance crashes and their fixes.

## When to use this skill

- Extending or debugging `plugins/nvoos-content-graph/blueprints/` or the
  repo-root `blueprints/` (Ollama demo).
- Adding a new Playground demo for any NV oOS plugin/addon.
- A user reports a crashing/hanging Playground instance (see §5 first).
- Re-generating blueprint JSONs after editing seed content.

## 1. Inventory — the two existing demos

| Demo | PR | Source | Generated | Landing |
|---|---|---|---|---|
| Content Graph "Project Asteria" (26 posts + 5 pages, graph pre-built) | #6662 | `plugins/nvoos-content-graph/blueprints/seed-content.php` | `…/blueprints/demo.json` (standalone) + `…/.wordpress-org/blueprints/blueprint.json` (wp.org Preview; mirrors SVN `assets/blueprints/blueprint.json`) | `/wp-admin/admin.php?page=nvoos-content-graph` |
| Complete bundle × local Ollama ("Oma" assistant + Pro SPA v2 chat) | #6663 | `blueprints/ollama-demo.php` (repo root) | `blueprints/ollama-demo.json` | `/ollama-test-lab/` |

Generators (commit the generated JSON; re-run after editing the seed):

```bash
php bin/generate-content-graph-blueprint.php   # emits both content-graph files
php bin/generate-ollama-blueprint.php          # emits blueprints/ollama-demo.json
```

The Ollama demo pins the bundle ZIP at
`alpha-working/build/nvdigital-open-operator-system-oos-complete-1.1.81.zip`
— bump the pin in the generator when a new release ships.

Design plan + full validation checklist:
`plugins/nvoos-content-graph/docs/playground-blueprint-plan.md`.

## 2. Blueprint authoring patterns

- **Seed-snippet embedding.** Keep demo content in a plain PHP file
  (functions only, no namespace, no closing `?>`). The generator strips the
  opening `<?php` tag and embeds the file into a `runPHP` step:
  `<?php require_once '/wordpress/wp-load.php'; <snippet> nvoos_*_seed();`.
  This avoids hand-maintaining JSON-escaped PHP.
- **Idempotency.** Guard every seed with an option
  (`get_option( 'nvoos_*_seeded' )` → return), and check
  `get_page_by_path( $slug, OBJECT, $type )` before each insert.
- **Deterministic builds.** Fire the plugin's public build hook directly
  (`do_action( 'nvoos_content_graph/initial_build' )`) instead of relying on
  the activation one-shot cron (non-deterministic in the Playground runtime).
  Disable per-post auto-rebuild during bulk seeding (e.g.
  `auto_rebuild => 0` in the grouped settings option), then re-enable after.
- **Pretty permalinks + rewrite flush** happen inside the seed step when
  internal links must resolve: the graph builder turns `<a href="/slug/">`
  links into `LINKS_TO` edges via `url_to_postid()`, which needs the
  `/%postname%/` rewrite rules flushed.
- **Preview-safe vs standalone split.** wp.org Preview mode pre-installs the
  plugin, so the committed `blueprint.json` must NOT self-install (a
  self-install replaces the trunk build being previewed). The standalone
  `demo.json` owns the `installPlugin` step. Two files until
  `ifAlreadyInstalled` behavior is verified.
- **`installPlugin` zips** must have exactly one plugin folder at the zip
  root; `activate` goes inside `options`; `landingPage` is a relative path;
  `preferredVersions` holds only `php`/`wp`; use `phpExtensionBundles:
  ["kitchen-sink"]` and `features.networking: true`; declare `$schema`.
- **PCP-proof every PHP file that lives inside a plugin folder** — dev
  snippets get scanned, so each starts with the `ABSPATH` guard (the
  generator strips only `<?php` + whitespace; the guard survives embedding
  and passes inside WordPress).

## 3. Hosting & CORS

- **raw.githubusercontent.com serves with `Access-Control-Allow-Origin: *`**
  — committed `build/*.zip` files work as `installPlugin` URLs.
- **GitHub release-asset URLs do NOT work in the browser**: the
  `release-assets.githubusercontent.com` CDN sends no CORS header, so the
  browser fetch is blocked. Verify any candidate URL with:
  `curl -s -D - -o /dev/null -H "Origin: https://playground.wordpress.net" <url> | grep -i access-control`
- Blueprint size matters: the 38 MB Complete bundle boots fine but slowly
  (see §5); the 9 MB base zip is the "lite" option.

## 4. CI / Plugin Check gate

- **PCP checks the BUILT ZIP, not the source checkout.** The content-graph
  workflow (`build-nvoos-content-graph.yml`) assembles the ZIP with its own
  rsync exclusion list annotated "keep in sync with .distignore" — adding a
  dev folder to `.distignore` alone is NOT enough; add `--exclude='…/'` to
  the workflow's rsync too. The same tri-sync rule applies to
  `bin/build-plugin-zip.sh` for the main plugin (two rsync blocks: base +
  combined) and the root `.distignore` for SVN deploys.
- Symptom when out of sync: PCP reports `missing_direct_file_access_protection`
  for a dev PHP file that leaked into the ZIP. Triage: download the report
  artifact (`gh run download <id> -n pcp-report-content-graph`) and grep for
  `FILE:` headers — the file list names exactly what leaked.
- Defense in depth: `ABSPATH` guards on all dev snippets (§2).

## 5. Playground worker crash modes

All three were observed for real; all three kill the ENTIRE instance.

**Symptom signature (any crash):**
`Request timed out` (messaging.ts) → `PHP.run() failed with exit code 255`
→ `PHP Fatal error: Cannot declare class Playground_SQLite_Integration_Loader,
because the name is already in use in /internal/shared/preload/0-sqlite.php`
→ `Aborted()` / `unreachable RuntimeError`. Everything after (500s, more
timeouts) is fallout, not cause.

| # | Cause | Prevention |
|---|---|---|
| 1 | **Synchronous PHP-side fetch to localhost during page render** (e.g. `wp_remote_get('http://localhost:11434')` inside a shortcode). When browser Private Network Access hangs the request, PHP blocks, the worker times out, a retry reuses the instance, and the SQLite preload re-declares → fatal. | Never fetch localhost from PHP render paths. Move connectivity checks client-side: render a container + inline `<script>` that fetches with an `AbortController` timeout. |
| 2 | **Inline `<script>` with raw multi-byte UTF-8** (emoji, em dashes) in shortcode output. If the streamed inline script is decoded with a non-UTF-8 fallback, the bytes become invalid tokens → `Uncaught SyntaxError: Invalid or unexpected token`. | Keep inline JS **pure ASCII**: `\uXXXX` escapes for every non-ASCII char. Verify: extract the RENDERED script and assert `node --check` passes and `grep -cP '[^\x00-\x7F]'` returns 0. |
| 3 | **Parallel REST bursts on a cold worker.** The Pro SPA fires ~8 concurrent REST calls on mount; the Complete bundle boots ~4s per request, the queue exhausts Playground's messaging budget, and the same duplicate-class fatal follows. | Reduce the mount burst (e.g. `[nvoos_pro_spa … show_sidebar="0"]` skips transcripts/threads/sessions), prefer serialized requests, and recommend Firefox or the local server fallback for heavy demos. |

Note the CLI cannot reproduce crashes #1/#3 — it has no browser policies
and serializes requests. Browser caveats must be eyeballed manually.

## 6. Local Ollama integration

- **The trick:** Playground runs WordPress in the browser, so the plugin's
  `http://localhost:11434` endpoint IS the user's machine. The plugin's SSRF
  guard (`wp_mcp_ai_validate_ai_provider_url()`) explicitly allowlists
  `localhost`, `127.0.0.1`, `host.docker.internal` for AI providers.
- **Pre-wire via the grouped `wp_mcp_ai_settings` option:**
  `enable_ollama` (must be true — fresh installs default providers to
  disabled), `ollama_endpoint_url` = `http://localhost:11434`,
  `ollama_model` (e.g. `llama3.1:8b`), `ollama_use_openai_compatible_endpoint`
  = false, `default_provider` = `ollama`, `default_model`,
  `provider_priority_list` (ollama first), `default_assistant`.
- **Demo assistant (CPT `mcp_ai_assistant`) meta keys:** `_wp_mcp_ai_provider`,
  `_wp_mcp_ai_model`, `_wp_mcp_ai_temperature`, `_wp_mcp_ai_system_prompt`,
  `_wp_mcp_ai_tools` (array). The plugin ships ~5 default assistants on
  activation; `get_page_by_path()` against the CPT slug is the idempotent
  lookup.
- **Pro SPA v2 shortcode** (Pro, v1.1.68+, ships in the Complete bundle):
  `[nvoos_pro_spa assistant_id="ID" mode="embedded" theme="dark" height="720px"
  guest="0" allow_sensitive_tools="0" show_sidebar="0"]`. Fallback for
  base-only: `[mcp_ai_chat assistant="ID"]`. Use
  `shortcode_exists( 'nvoos_pro_spa' )` to pick at seed time.
- **OLLAMA_ORIGINS is mandatory.** Ollama 403s unknown browser origins by
  default. Windows: `setx OLLAMA_ORIGINS "https://playground.wordpress.net,
  http://localhost,http://127.0.0.1"` + quit the desktop app from the tray and
  relaunch. **Gotcha:** the Ollama Desktop app silently relaunches its own
  server process WITHOUT the env var (kill both `ollama app.exe` and
  `ollama.exe`, then relaunch the app with the env var set, or run
  `OLLAMA_ORIGINS="…" ollama serve` from a console). macOS/Linux:
  `OLLAMA_ORIGINS="…" ollama serve`.
- **Browser policy matrix:** Firefox works out of the box. Chrome/Edge may
  prompt for local-network access or block (Ollama's preflight lacks
  `Access-Control-Allow-Private-Network`); workaround:
  `chrome://flags/#block-insecure-private-network-requests`. Bulletproof:
  `npx @wp-playground/cli server` — a local origin has zero policy friction.
- Verify end-to-end server-side regardless of browser:
  `wp_remote_get( 'http://localhost:11434/api/tags' )` and a non-streaming
  `wp_remote_post( '…/api/chat', { model, messages, stream:false,
  options:{num_predict:12} } )` through Playground's networking bridge.

## 7. Validation harness (CLI)

`npx -y @wp-playground/cli@3.1.54 run-blueprint` boots real WP in Node and
executes the blueprint. The report-file pattern:

1. Copy the blueprint to `_verify.json` and append a throwaway `runPHP`
   step that writes a JSON report to a mounted dir
   (`file_put_contents( '/verify-out/…' )`).
2. Run with the mount: `--mount-dir "C:/Users/<u>/AppData/Local/Temp/nvoos-verify" "/verify-out"`.
   In Git Bash, prefix `MSYS_NO_PATHCONV=1` (MSYS mangles `/verify-out`).
3. Assert: plugin active, settings/assistant/page intact, shortcodes
   registered + rendered, `/api/tags` + `/api/chat` return 200, seed
   idempotency guards present.
4. Lint the embedded code: extract each `runPHP` step's `code` and `php -l`
   it. For inline JS: write the do_shortcode render to the mount, extract
   the script on the host, `node --check` + ASCII assertion (§5 row 2).
5. Delete all throwaway files (`_verify.json`, temp builders, logs).

Known CLI quirks: `runPHP` stdout is not printed (state checks must go
through the report-file pattern); `pathRegexp is not a function` on older
CLI versions → pin `@3.1.54`; `wp_json_encode()` does not exist in plain
PHP CLI (use `json_encode` in host-side throwaway scripts).

## 8. Checklist when adding a demo blueprint

1. Seed snippet: idempotent, `ABSPATH` guard, no namespace, token links map
   to real slugs.
2. Generator in `bin/`; generated JSONs committed; bundle URL pinned.
3. Exclusions: plugin `.distignore` + CI workflow rsync (where applicable) +
   `bin/build-plugin-zip.sh` (both blocks) + root `.distignore` — the
   dev-only `blueprints/` folders must never ship or be scanned.
4. Validate via §7; eyeball browser caveats manually.
5. Branch + PR against `alpha-working` (repo convention); update the
   skill-count bookkeeping per `AGENTS.md` §6.

## References

- Plan + research sources: `plugins/nvoos-content-graph/docs/playground-blueprint-plan.md`
- Demo READMEs: `plugins/nvoos-content-graph/blueprints/README.md`,
  `blueprints/README.md` (repo root)
- wp.org previews: https://developer.wordpress.org/plugins/wordpress-org/previews-and-blueprints/
- Blueprint format/steps: https://developer.wordpress.org/playground/blueprints/
- PCP gate + packaging tri-sync: see the `mcp-ai-wpoos-wporg-submission` skill
