---
type: Skill
name: mcp-ai-wpoos-wporg-submission
description: "Operational guide for WordPress.org submission readiness of NV oOS standalone plugins (nvoos-docs-hub today; base plugin and future standalone addons). Covers the official Plugin Check (PCP) gate in CI and Docker (MySQL service bootstrap, activate-not---require, jq error gate with allowlisted false positives), triaging PCP findings (OffloadedContent false positive, TextDomainMismatch/slug backlog, trait-prefix stubs), packaging exclusion tri-sync, readme.txt wp.org standards (External Services, Screenshots), .wordpress-org asset layout and Playwright screenshot capture with QA verification, and the 18-point compliance checklist. Use when preparing a wp.org submission, fixing the plugin-check CI job, generating listing screenshots/banners, triaging PCP findings, or reviewing the compliance checklist."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.71"
  plugin-version-tested: "1.1.71"
  last-updated: "2026-09-07"
---

# NV oOS WordPress.org Submission — Readiness Playbook

Playbook distilled from the executed Docs Hub 0.4.3 submission-readiness pass
(PR #6403) and the base-plugin gate repair. Covers everything between "this
plugin should ship to wp.org" and "the listing assets are in SVN".

## When to use this skill

- "Prepare <plugin> for wp.org submission" / "run the 18-point checklist"
- The `plugin-check` CI job fails (any workflow) or a release gate is red
- "Capture the wp.org listing screenshots" / "refresh the .wordpress-org assets"
- Triaging `wp plugin check` findings (ERROR vs WARNING, false positives)
- Adding/fixing the packaging exclusions for a standalone ZIP
- Reviewing readme.txt against wp.org standards

## Submission checklist (source of truth)

The 18-point checklist lives in three places — read the checklist doc, then
work it top-down; the CI gate (R-T-04) is only one row:

- `SUBMISSION.md` (repo root) — base-plugin submission state
- `.github/agents/wp-org-compliance-auditor.agent.md` — auditor agent scope
- `docs/operations/compliance/WORDPRESS_ORG_SUBMISSION_CHECKLIST.md` — 18 points
- `docs/project/audits/2026-04/wp-org-submission-checklist.md` — executed
  audit with per-row status + IDs (R-D-01, R-Q-01…, R-S-01…, R-T-04…)

## Running the official Plugin Check locally (Docker)

The QA stack (`oos-wp`, http://localhost:8000) has `plugin-check` active, but
PCP's runtime checks crash on the heavy plugin stack. Use a **fresh WP install**
(one-off container on the `oos-wp_default` network, scratch DB in `oos-wp-db`):

```bash
# 1. Scratch DB + grants (the MYSQL_DATABASE env does this in CI; locally the
#    wordpress user needs explicit grants on any DB beyond `wordpress`).
docker exec oos-wp-db sh -c 'mysql -uroot -pwordpress -e "CREATE DATABASE IF NOT EXISTS wordpress_pluginchk CHARACTER SET utf8mb4; GRANT ALL PRIVILEGES ON wordpress_pluginchk.* TO '\''wordpress'\''@'\''%'\''; FLUSH PRIVILEGES;"'

# 2. One-off WP install + PCP run. Mount the plugin source and a scratch dir.
#    --user 33:33 matters when writing into the wp_core volume (uploads are
#    owned by uid 33; the cli image runs as uid 82).
MSYS_NO_PATHCONV=1 docker run --rm --user 33:33 --network oos-wp_default \
  -v oos-wp_wp_core:/var/www/html \
  -v F:/GITHUB/worktrees/mcp-ai-wpoos/<worktree>/mcp-ai-wpoos:/var/www/html/wp-content/plugins/mcp-ai-wpoos \
  -e WORDPRESS_DB_HOST=oos-wp-db -e WORDPRESS_DB_NAME=wordpress \
  -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress \
  wordpress:cli-php8.2 sh -c \
  'php -d memory_limit=1G /usr/local/bin/wp plugin check <slug> --format=json --severity=5 --path=/var/www/html > /tmp/report.json 2>/dev/null; echo EXIT:$?'
```

Hard rules learned the expensive way:

1. **WordPress cannot boot without a DB.** `wp plugin install` and
   `wp plugin check` load WP — a DB-less bootstrap dies with "Error
   establishing a database connection". Always provision MySQL (service
   container in CI; scratch DB locally).
2. **Activate plugin-check; never `--require` it.** `--require=.../
   plugin-check/plugin.php` loads PCP before WP boots and fatal-errors with
   `Call to undefined function plugin_dir_path()`. `wp plugin install
   plugin-check --activate` is the only working invocation.
3. **`wordpress:cli-php8.2` runs as uid 82** (the Apache image's www-data is
   uid 33). Files in the shared `wp_core` volume are owned by 33 — pass
   `--user 33:33` for any run that writes uploads/cache.
4. **The CLI image's `wp` shim ignores `WP_CLI_PHP_ARGS`.** Memory errors
   (128M cap while loading WooCommerce et al) → prefix every call with
   `php -d memory_limit=512M /usr/local/bin/wp`.
5. **Windows bind-mount readdir truncation.** After heavy writes (e.g. tar
   extraction) through a Windows bind mount, Docker Desktop can serve a
   truncated directory listing to containers (host `find` sees 9365 files,
   container sees 4583). Symptom: PCP report is absurdly small and
   `no_plugin_readme` fires even though readme.txt exists. For full-tree PCP
   sims use **Linux-native docker volumes** (`docker volume create`,
   `chown -R 82:82` first) and verify the file count INSIDE the run container
   before trusting the report. Small plugin trees (docs-hub scale, `cp -r`
   in-container) are unaffected.
6. **PCP CLI caching.** Results can look stale across re-runs; bump the
   plugin's `Version:` header in the sim tree to force fresh results when a
   report doesn't change after fixing files.
7. **Exit code + report shape.** `wp plugin check --format=json` emits one
   `FILE: <path>` header + JSON array **per file with findings** (chunked
   stream, not one array). Gate with the slurp form:
   `jq -s '[.[][] | select(.type=="ERROR" and .code!="<allowlist>")] | length'`.
8. **The `--help` trap:** `wp plugin check --help` fatal-errors (PCP's
   mu-plugin hook runs without a plugin arg). Don't use it; read the source
   under `wp-content/plugins/plugin-check/includes/`.

## The CI gate

Two workflows carry the gate:

- `.github/workflows/build-spa-addons.yml` → `plugin-check` job (docs-hub
  ZIP; active). Runs on PRs touching `addons/docs-hub/**`, gates on
  `ERROR`-severity findings minus the allowlisted `OffloadedContent`, and
  documents the one non-blocking `NonPrefixedTraitFound` warning.
- `.github/workflows/release.yml` → `plugin-check` job (LEGACY `v*` tag
  path, slug `wp-mcp-ai`). Fixed mechanically (DB + activate + no
  `--require` + slurped jq) but **the active release path is
  `build-nvdigital-oos-wporg.yml`** (`nvdigital-oos-v*` tags, slug
  `nvdigital-open-operator-system-oos`, text-domain transform at packaging
  time). The legacy gate will report the TextDomainMismatch backlog below —
  that's the point of the gate; see R-T-04.

Job anatomy (copy this shape, not the old DB-less one):

```yaml
services:
  mysql:
    image: mysql:8.0
    env:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress_pluginchk
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
    ports: ["3306:3306"]
    options: >-
      --health-cmd="mysqladmin ping --silent"
      --health-interval=10s --health-timeout=5s --health-retries=5
# steps: wp core download → wp config create (real creds) → wp core install
# → wp plugin install plugin-check --activate → unzip target ZIP →
# wp plugin check <slug> --format=json --severity=5 → jq -s gate
```

The base-plugin current PCP state (measured on WP 7.1 + PCP 2.1.0 against a
ZIP-shaped tree, Linux volume): **~31k ERRORs, ~3.9k WARNINGs**, dominated by
`WordPress.WP.I18n.TextDomainMismatch` (~30.9k — repo domain `mcp-ai-wpoos`
vs expected `wp-mcp-ai`/wp.org slug). This is a known backlog for the
submission track, NOT something to allowlist. The docs-hub ZIP is clean
(0 blocking errors, 1 documented warning).

### Known PCP findings triage

| Code | Verdict | Handling |
|---|---|---|
| `PluginCheck.CodeAnalysis.Offloading.OffloadedContent` | False positive | Allowlist — flags literal `raw.githubusercontent.com` hosts in the SSRF-hardened, host-allowlisted remote fetcher (disclosed in `readme.txt == External Services ==`) |
| `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedTraitFound` | Intentional (docs-hub) | Warning only — the `WP_MCP_AI_Inline_Async_Tick_Trait` stub must carry the BASE plugin's trait name so the real trait replaces it when NV oOS is active |
| `WordPress.WP.I18n.TextDomainMismatch` | Real blocker (base) | Text domain must equal the wp.org slug/dir name; the repo domain is `mcp-ai-wpoos`. Needs the slug migration track — do not allowlist |
| `no_plugin_readme` | Real or mount artifact | readme.txt must sit at the ZIP root. If it does and PCP still fires, suspect the bind-mount truncation (pitfall 5) |

## Packaging exclusions (tri-sync)

Standalone plugin ZIPs keep dev files out via **three lists that must stay in
sync**: the addon's `.distignore`, the `docs-hub` step of
`bin/build-addon-zips.sh`, and the rsync/tar excludes in the CI workflow
(`build-spa-addons.yml` assemble step). Current sets exclude: `node_modules/`,
`src/`, `tests/`, `vendor/`, `composer.json|lock`, `package*.json`,
esbuild/eslint/tsconfig/vitest configs, `docs/`, `.wordpress-org/`,
`.gitignore`, `.distignore`, `.DS_Store`. Any new dev-only path must be added
to all three.

## .wordpress-org assets

Layout mirrors `plugins/nvoos-content-graph/.wordpress-org/`:

```
.wordpress-org/
├── README.md        # asset checklist + SVN upload runbook
├── assets/          # icon-128x128/256x256.png, banner-772x250/1544x500.png,
│                    # screenshot-{1..N}.png  (committed, excluded from ZIP)
└── source/          # artwork masters (1024x1024 icon, 1344x768 banner)
```

- `.wordpress-org/` maps to the plugin's SVN `assets/` dir — it never ships
  inside the ZIP.
- PNGs are gitignored locally via `.git/info/exclude` (`*.png`) — stage with
  `git add -f` (content-graph precedent).

### Screenshot capture (Playwright)

Templates in `bin/`: `capture-nvoos-content-graph-screenshots.js` (origin),
`capture-nvoos-docs-hub-screenshots.js` (newer, docs-hub specific). Workflow:

1. QA stack up (`docker compose up -d`), plugin active, data seeded
   (docs-hub: remote repo configured + `wp nvoos-docs rebuild --sync`,
   published `[nvoos_docs]` page).
2. Login as admin (`admin`/`password`) for admin shots; guest context for
   frontend (public access). Set generous timeouts — the QA site serves pages
   in 20–60s (`setDefaultTimeout(240000)`).
3. Element captures for settings widgets; viewport/fullPage for the frontend.
4. Verify with a pixel-level QA pass (sub-agent can decode text/layout
   without vision) before committing.

Capture pitfalls (all hit in practice):

- **fullPage texture limit** — Chromium fails `page.screenshot` on very tall
  pages ("Unable to capture screenshot"); use viewport captures for shots
  with overlay UI (search dropdowns).
- **Theme content width** — default themes constrain content to ~620px,
  dropping the docs app below its sidebar/TOC breakpoints. Inject
  `:root { --wp--style--global--content-size: 1240px !important;
  --wp--style--global--wide-size: 1400px !important; }` for the capture.
- **Wait for real content** — the SPA shell paints long before the REST page
  fetch lands; wait for `.dh-content` textContent length (not just the
  container) or you capture a "Loading…" screen.
- **Duplicate roots** — the shortcode emits an empty container div AND the
  rendered app root; select the live one via `[data-theme]`.
- **`locator.screenshot({clip})` is unreliable** — use `page.screenshot`
  with viewport-relative `clip` from `locator.boundingBox()`.
- **Red error rows in listing shots** — clip admin captures to exclude
  broken-link tables (they read negatively at thumbnail size).
- **Icon/banner artwork is manual** — screenshots are scripted; icons and
  banners still need design work (documented in the `.wordpress-org/README.md`).

## readme.txt standards

- `== External Services ==` section: every third-party host contacted,
  server-side-only wording, data-sent statement, Terms + Privacy URLs.
- `== Screenshots ==` section with `1. Alt text` lines matching
  `screenshot-{N}.png` in SVN assets; add when the PNGs land.
- `Stable tag` must equal the plugin header `Version`; `Tested up to` latest WP.
- Trim tags to directory-standard tags (PCP flags exotic tags).
- Text Domain header must equal the slug for the ZIP dir name on wp.org.

## Skill bookkeeping (when adding skills like this one)

Adding a coding-time skill requires count updates in: `AGENTS.md` §1
(inventory table row + coding-time-vs-runtime paragraph),
`.github/copilot-instructions.md` (multi-agent-awareness bullet + repo-tree
comment), and the `README.md` repo-map row. Executed-release lines are left
untouched; the next docs catch-up (`.agents/skills/mcp-ai-wpoos-updates`)
folds the new skill into the next release notes.

## References

- Docker one-off runner patterns: `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md`
  (cross-worktree runs, shared-DB etiquette)
- Plugin operational guide: `.agents/skills/mcp-ai-wpoos-plugin/SKILL.md`
- Maintenance tracks: `.agents/skills/mcp-ai-wpoos-updates/SKILL.md`
- Capture scripts: `bin/capture-nvoos-docs-hub-screenshots.js`,
  `bin/capture-nvoos-content-graph-screenshots.js`,
  `bin/README-SCREENSHOT-TOOLS.md`
