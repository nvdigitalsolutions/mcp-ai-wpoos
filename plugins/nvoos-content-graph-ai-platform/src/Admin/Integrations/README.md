# Integrations (Admin)

## Purpose

Wave E-UI-3 port surface. Holds the base operator **integrations
screens** as they land — profession research + settings, team research
+ settings, Elementor/JetEngine/WooCommerce integration pages — each
an aligned port of the matching `WP_MCP_AI_Admin_*` class in the base
plugin's `includes/admin/`. Sub-cluster 1 (`ProfessionResearchPage` +
`ProfessionSettings`) is the aligned port of
`WP_MCP_AI_Admin_Profession_Research_Page` and
`WP_MCP_AI_Admin_Profession_Settings`: byte-identical page slugs
(`research-profession`, `wp-mcp-ai-profession-settings`), the
three-workflow research surface (chat embed + no-assistant notice,
import form, review quality dashboard) with the `wpMcpAiResearchPage`
envelope, the three registered profession default settings, the
tabbed settings render (overview/configuration/tools/help), and the
nonce-gated save flow with the temperature clamp + settings-updated
redirect.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerIntegrationsScreens()` — standalone-only (`! defined('WP_MCP_AI_PATH')`) |
| **Optional dependencies** | None (profession CPT posts + options) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Admin\Integrations\ProfessionResearchPage` | `ProfessionResearchPage.php` | `Plugin::registerIntegrationsScreens()` — standalone menu/enqueue wiring (static `init()`) |
| `NvoosContentGraphAiPlatform\Admin\Integrations\ProfessionSettings` | `ProfessionSettings.php` | `Plugin::registerIntegrationsScreens()` — standalone menu/settings wiring |

## Inputs / Outputs / Neighbors

- **Reads from:** the profession CPT (`Professions\ProfessionCpt` —
  the pre-ported platform class; `POST_TYPE` byte-identical), the
  `wp_mcp_ai_profession_settings` + `wp_mcp_ai_profession_default_*`
  options, assistant posts for the chat embed (monolith chat
  shortcode)
- **Writes to:** the three profession default settings (save flow)
- **Upstream callers:** `Plugin::registerIntegrationsScreens()`
  (standalone wiring), admin requests
- **Downstream consumers:** the base admin loader owns the same pages
  monolith (the ported classes stay unwired there)

## Conventions

- Per-mode discriminator is always `defined( 'WP_MCP_AI_PATH' )` —
  never bare `class_exists()` for base-owned classes. Collaborators
  resolve through `protected static` seams (profession post type,
  chat shortcode — base monolith / platform or null standalone).
- These are the E-UI-3 **integrations screens**, distinct from the
  E-UI-1 dashboards and E-UI-2 managers — same standalone-only wiring
  discipline, one sub-cluster per PR.
- Own assets live in the platform `assets/` tree (byte-identical
  copies of the base files).

## Tests

- `tests/test-profession-pages.php` — characterization suite covering
  the byte-identical slugs, per-mode menu registration under the
  profession CPT menu, init/register idempotence (hook-registry dedup
  delta), the per-mode post-type and shortcode seams, the enqueue
  gate + `wpMcpAiResearchPage` envelope, the render surface (common
  surface, no-assistant notice, import form, review quality metrics
  with seeded professions), the settings registrations, the settings
  tab renders (overview/configuration/tools/help), the capability
  gate, and the nonce-gated save flow (options + clamp + redirect).
  Runs in both matrices.

## Also Load

- [`../Managers/README.md`](../Managers/README.md) — the E-UI-2
  manager family (same wiring discipline)
- [`../../Professions/README.md`](../../Professions/README.md) — the
  profession subsystem these pages manage (if present)
- [`../../Plugin.php`](../../Plugin.php) —
  `registerIntegrationsScreens()` wiring
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping + capability checks

## See Also

- Base originals: `includes/admin/class-wp-mcp-ai-admin-profession-research-page.php`, `class-wp-mcp-ai-admin-profession-settings.php`, `class-wp-mcp-ai-admin-team-research-page.php`, `class-wp-mcp-ai-admin-team-settings.php`, `class-wp-mcp-ai-admin-elementor-integration.php`, `class-wp-mcp-ai-admin-jetengine-integration.php`, `class-wp-mcp-ai-admin-woocommerce-integration.php`, `class-wp-mcp-ai-admin-plugins-integration.php`
- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E-UI-3 row status
