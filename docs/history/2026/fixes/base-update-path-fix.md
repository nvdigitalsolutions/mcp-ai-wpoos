# Base Update Path & In-Place Install — Fix Details

## Problem Description

Three related updater defects surfaced after the v1.1.56 release:

1. **wp.org base installs had no update path.** The updater only offered the
   complete build (`nvdigital-open-operator-system-oos-complete`) and the Pro
   addon. A site running the plain base package
   (`nvdigital-open-operator-system-oos`) could only "update" by upgrading to
   the complete build — there was no way to update the base plugin in place.
2. **ZIP folder-name mismatches broke updates.** Updates ran through
   WordPress's `Plugin_Upgrader`, which requires the ZIP's top-level folder to
   match the installed plugin directory. The complete package extracts to
   `nvdigital-open-operator-system-oos-complete`, while wp.org base installs
   live in `nvdigital-open-operator-system-oos` — so the upgrade path could
   fail (or misplace files) depending on which package was installed.
3. **Pro version display drifted.** The admin page and `wp mcp-ai pro status`
   read the manually maintained `WP_MCP_AI_PRO_VERSION` constant, which had
   drifted from the shipped version (e.g. Pro releases built at 1.1.54 still
   carried the constant value 1.1.50).

## Root Cause

- `find_asset_url()` had no matcher for the plain base asset, so
  `check_for_update()` could never resolve a base-only download URL.
- `install_update()` delegated file replacement to `Plugin_Upgrader::upgrade()`,
  which enforces plugin-slug/folder-name consistency and renames the live
  plugin directory during install — a failure mode on mismatched folder names.
- The Pro version was sourced exclusively from the `WP_MCP_AI_PRO_VERSION`
  constant, which the build script does not stamp at release time (only the
  plugin header is stamped).

## Solution Implemented

File: `includes/class-wp-mcp-ai-plugin-updater.php` (+ related admin/CLI views)

1. **`ASSET_BASE` asset pattern + base matcher** — the plain base package
   (`nvdigital-open-operator-system-oos-{version}.zip`, plus legacy
   `mcp-ai-wpoos-base-*` naming) is now resolvable, excluding the
   complete/pro/core variants that share the prefix.
2. **Base-only update flow** — `check_for_base_update()` compares the GitHub
   latest against `WP_MCP_AI_VERSION`, and two new AJAX handlers
   (`wp_mcp_ai_check_base_update`, `wp_mcp_ai_start_base_update`) power the
   new "Base Version" panel in Settings → Advanced. A separately installed Pro
   addon is untouched by base updates.
3. **Copy-in-place install** — `install_update()` now calls
   `replace_plugin_from_zip()`: download → extract to temp → snapshot current
   files to a sibling backup directory → copy new files over the live
   directory → run the post-install integrity check. On failure the backup is
   restored. The live plugin directory is never renamed, so the running
   request keeps autoloading classes. When upgrading base → complete, a
   separately installed Pro addon is deactivated first to avoid double-loading
   Pro on the next request.
4. **Pro version from plugin header** — `get_pro_installed_version()` reads
   the Version header of the installed Pro addon (`WP_MCP_AI_PRO_FILE`),
   falling back to `WP_MCP_AI_PRO_VERSION` for bundled Pro builds. The imaging
   admin page (`class-wp-mcp-ai-imaging-admin-page.php`) and the Pro CLI status
   command now display the same source.

## Test Coverage

- Existing updater/pro-settings tests pass; base-update asset matching is
  covered by the `ASSET_BASE` matcher's exclusion rules (complete/pro/core
  variants rejected).
- `phpcs.xml.dist` gained annotations for the reworked updater file.

## Related

- [PR #5871](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5871)
- [docs/features/plugin-updater.md](../../features/plugin-updater.md)
