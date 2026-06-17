# Place Metaboxes

## Purpose

Five metabox classes for the Place CPT edit screen — base abstraction, contact info, details, location/geo, and AI-powered research — that together provide the full Place data-entry surface.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/mcp-ai-wpoos-pro.php` → `addons/pro/includes/metaboxes/metaboxes-init.php` — metaboxes are registered on `add_meta_boxes` for `mcp_ai_place` |
| **Optional dependencies** | none — Place CPT registration is handled in Base; these metaboxes only register when the CPT exists |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Place_Metabox_Base` (abstract) | `class-wp-mcp-ai-place-metabox-base.php` | All concrete place metaboxes |
| `WP_MCP_AI_Place_Metabox_Contact` | `class-wp-mcp-ai-place-metabox-contact.php` | Place CPT edit screen (side context) |
| `WP_MCP_AI_Place_Metabox_Details` | `class-wp-mcp-ai-place-metabox-details.php` | Place CPT edit screen |
| `WP_MCP_AI_Place_Metabox_Location` | `class-wp-mcp-ai-place-metabox-location.php` | Place CPT edit screen |
| `WP_MCP_AI_Place_Research_Metabox` | `class-wp-mcp-ai-place-research-metabox.php` | Place CPT edit screen — extends `WP_MCP_AI_Research_Metabox_Base` from [`../`](../) |

The base class provides `render_permission_denied()`, `render_documentation_link()`, and capability-check helpers (`can_view()`) that all concrete metaboxes inherit.

## Inputs / Outputs / Neighbors

- **Reads from:** Place CPT post meta (`_place_phone`, `_place_email`, `_place_website`, `_place_address`, `_place_latitude`, `_place_longitude`, `_place_rating`, `_place_price_level`, `_place_google_place_id`, `_place_street`, `_place_city`, `_place_state`, `_place_country`, `_place_postal_code`).
- **Writes to:** the same post meta keys (via `update_post_meta`) on `save_post`, guarded by per-metabox nonce + capability check.
- **Upstream callers:** WordPress core (`add_meta_boxes`, `save_post_mcp_ai_place`).
- **Downstream collaborators:** [`../../research-add/`](../../research-add/) (the Research Metabox delegates AI lookups via `WP_MCP_AI_Research_Metabox_Base`).
- **Events fired:** the standard `wp_verify_nonce` flow per metabox save.
- **Events listened to:** `add_meta_boxes`, `save_post`.

## Conventions

- Every concrete metabox MUST extend `WP_MCP_AI_Place_Metabox_Base` (or `WP_MCP_AI_Research_Metabox_Base` for research). Do not subclass `WP_List_Table` or WP core metabox classes directly.
- Each metabox declares its own nonce action (e.g. `wp_mcp_ai_place_contact_nonce`) — never reuse a nonce across metaboxes.
- Save methods MUST gate on `edit_post` for the target post ID plus the metabox-specific nonce. Return early if either fails.
- The Contact metabox lives in the `side` context; Details, Location, and Research use `normal`. Keep that layout; do not change contexts without updating the Place CPT edit-screen UX.
- Post-meta keys use the `_place_` prefix. Add new keys to the Place CPT schema in Base before referencing them here.

## Tests

```bash
vendor/bin/phpunit tests/test-places-management-cpt-registration.php
```

Place metabox save/render coverage is implicit in the Place CPT integration test above. Dedicated metabox unit tests live alongside other Pro CPT metabox tests under `addons/pro/tests/` (see `test-*-metabox.php` patterns).

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — nonces, capability gates, meta sanitisation (always)
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro metabox placement rules
- [`CLAUDE.md`](../../../../../CLAUDE.md) — PHP-compat (8.1+) + two-gate sanitisation

## See Also

- Parent folder: [`addons/pro/includes/metaboxes/`](../) — shared base classes and other Pro CPT metaboxes
- Research base: [`addons/pro/includes/research-add/`](../../research-add/) — `WP_MCP_AI_Research_Metabox_Base` that `WP_MCP_AI_Place_Research_Metabox` extends
- Place CPT definition: Base [`includes/`](../../../../../includes/) (CPT registration)
