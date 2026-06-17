# Pro Metaboxes

## Purpose

Holds the WordPress admin metaboxes that the Pro CPTs ship with — appointments, services, staff, ECA programmes, quizzes (incl. quiz research), media templates, media collections, places, and the Project Management AI-Assistant — so that Pro CPT classes own only registration while the edit-screen UI lives in one place.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (see [`CLAUDE.md`](../../../../CLAUDE.md)) |
| **Loaded by** | Each owning Pro CPT class — the `load_metabox_classes()` method on `WP_MCP_AI_Appointment_CPT`, `WP_MCP_AI_Service_CPT`, `WP_MCP_AI_Staff_CPT`, `WP_MCP_AI_ECA_CPT`, `WP_MCP_AI_Quiz_CPT`, `WP_MCP_AI_Media_Template_CPT`, `WP_MCP_AI_Media_Collection_CPT`, and `WP_MCP_AI_Place_CPT` — `require_once`s its metabox files and instantiates the metabox set. The Project Management AI-Assistant metabox is loaded by [`../project-management-toolkit-init.php`](../project-management-toolkit-init.php). |
| **Optional dependencies** | None for the metabox classes themselves. Their parent CPTs are gated on their toolkit flag in `wp_mcp_ai_settings` (`enable_calendar_booking_toolkit`, `enable_eca_toolkit`, `enable_quiz_toolkit`, `enable_media_toolkit`, `enable_place_toolkit`, `enable_project_management_toolkit`); the AI-Assistant metabox additionally gates on Pro being active. |

## Public Surface

Each metabox class is referenced only by its owning CPT loader. The base classes are the cross-folder surface — concrete subclasses are internal to their CPT subsystem.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Appointment_Metabox_Base` (abstract) | `class-wp-mcp-ai-appointment-metabox-base.php` | `WP_MCP_AI_Appointment_Metabox_Details`, `…_Client` |
| `WP_MCP_AI_Appointment_Metabox_Details`, `…_Client` | `class-wp-mcp-ai-appointment-metabox-details.php`, `class-wp-mcp-ai-appointment-metabox-client.php` | `WP_MCP_AI_Appointment_CPT::load_metabox_classes()` |
| `WP_MCP_AI_ECA_Metabox_Base` (abstract) | `class-wp-mcp-ai-eca-metabox-base.php` | `WP_MCP_AI_ECA_Metabox_Details`, `…_Schedule`, `…_Enrollment` |
| `WP_MCP_AI_ECA_Metabox_Details`, `…_Schedule`, `…_Enrollment` | `class-wp-mcp-ai-eca-metabox-*.php` | `WP_MCP_AI_ECA_CPT::load_metabox_classes()` |
| `WP_MCP_AI_Media_Collection_Metabox_Items`, `…_Operations`, `…_Stats` | `class-wp-mcp-ai-media-collection-metabox-*.php` | `WP_MCP_AI_Media_Collection_CPT::load_metabox_classes()` |
| `WP_MCP_AI_Media_Template_Metabox_Base` (abstract), `…_Operation`, `…_Stats` | `class-wp-mcp-ai-media-template-metabox-*.php` | `WP_MCP_AI_Media_Template_CPT::load_metabox_classes()` and reused by the Media Collection metaboxes |
| `WP_MCP_AI_Project_Management_AI_Assistant_Metabox` | `class-wp-mcp-ai-project-management-ai-assistant-metabox.php` | Project Management toolkit init |
| `WP_MCP_AI_Quiz_Metabox_Base` (abstract), `…_Details`, `…_Questions` | `class-wp-mcp-ai-quiz-metabox-*.php` | `WP_MCP_AI_Quiz_CPT::load_metabox_classes()` |
| `WP_MCP_AI_Quiz_Research_Metabox` | `class-wp-mcp-ai-quiz-research-metabox.php` | Quiz research admin page |
| `WP_MCP_AI_Research_Metabox_Base` (abstract) | `class-wp-mcp-ai-research-metabox-base.php` | `WP_MCP_AI_Quiz_Research_Metabox`, `WP_MCP_AI_Place_Research_Metabox` |
| `WP_MCP_AI_Service_Metabox_Base` (abstract), `…_Details` | `class-wp-mcp-ai-service-metabox-*.php` | `WP_MCP_AI_Service_CPT::load_metabox_classes()` |
| `WP_MCP_AI_Staff_Metabox_Base` (abstract), `…_Details` | `class-wp-mcp-ai-staff-metabox-*.php` | `WP_MCP_AI_Staff_CPT::load_metabox_classes()` |
| `WP_MCP_AI_Place_Metabox_Base`, `…_Location`, `…_Contact`, `…_Details`, `…_Research_Metabox` | `places/class-wp-mcp-ai-place-metabox-*.php` | `WP_MCP_AI_Place_CPT::load_metabox_classes()` |

Generic Pro-CPT AI-Assistant metaboxes that apply to *every* Pro CPT (not a single CPT) live with their integrator in [`../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php`](../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php) and the [`../admin/`](../admin/) folder — not here.

## Inputs / Outputs / Neighbors

- **Reads from:** the current `$post` / `$post_type`, the Pro CPT post meta the metabox manages (`_eca_code`, `_appointment_*`, `_service_*`, `_staff_*`, `_quiz_*`, `_media_template_*`, `_place_*`, …), `wp_mcp_ai_settings` for toolkit flags, the assistant CPT for the Project Management AI-Assistant.
- **Writes to:** post meta on the owning Pro CPT (gated by capability + nonce), no other persistent storage.
- **Upstream callers:** `add_meta_boxes` (registration), `save_post_<cpt>` (persistence), `admin_enqueue_scripts` (asset wiring) — wired by each metabox's constructor.
- **Downstream collaborators:** the owning Pro CPT classes in [`../`](../) and [`../calendar-booking/`](../calendar-booking/), [`../data-stores/`](../data-stores/) for relationship lookups, [`../services/`](../services/) for vault / encryption helpers when storing sensitive fields, [`../admin/`](../admin/) for shared script handles.
- **Events fired:** none custom; persistence flows through standard `save_post_*` actions.
- **Events listened to:** `add_meta_boxes`, `save_post_<cpt>`, `admin_enqueue_scripts`.

## Conventions

- Each metabox subsystem ships an abstract `*_Metabox_Base` class that owns the common `add_meta_box()` + `save()` lifecycle; concrete classes only declare fields and meta-key mappings. New metaboxes for an existing CPT MUST extend the matching base class — do not register stand-alone metaboxes here.
- One metabox class per file; the constructor wires its own `add_action` calls so the class is self-bootstrapping once required.
- Every save handler MUST verify a nonce of the form `wp_mcp_ai_<entity>_<scope>_nonce` and check capability before touching post meta. See [`.context/security-checklist.md`](../../../../.context/security-checklist.md).
- Metabox classes are loaded **lazily** by their owning CPT's `load_metabox_classes()` method, not by the Pro bootstrap — keep that pattern when adding a new CPT so Base-mode sites do not pay the cost.
- Place-specific metaboxes nest under `places/` because the Place CPT split them by concern (location, contact, details, research). Other CPTs may adopt the same pattern when their metabox count exceeds ~3.
- The Project Management AI-Assistant metabox is a "specialised" metabox referenced in [`../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php`](../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php) — it intentionally pre-empts the generic AI-Assistant injection for `mcp_ai_pm_*` CPTs.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-eca-cpt.php
vendor/bin/phpunit addons/pro/tests/test-quiz-cpt-admin-notice.php
vendor/bin/phpunit addons/pro/tests/test-media-template-cpt.php
vendor/bin/phpunit addons/pro/tests/test-vault-metaboxes.php
vendor/bin/phpunit addons/pro/tests/test-ai-cpt-management-integration.php
```

The remaining metabox classes (appointment, service, staff, place, media-collection, quiz-research, project-management AI-Assistant) are exercised indirectly through their owning CPT integration tests under [`addons/pro/tests/`](../../tests/) — there is no per-metabox PHPUnit suite for those yet.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — nonce + capability rules for `save_post_*` (always)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — toolkit-flag gating
- [`.context/testing.md`](../../../../.context/testing.md) — CPT + metabox test patterns
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP compat, two-gate sanitisation

## See Also

- Owning CPTs: [`../class-wp-mcp-ai-eca-cpt.php`](../class-wp-mcp-ai-eca-cpt.php), [`../class-wp-mcp-ai-quiz-cpt.php`](../class-wp-mcp-ai-quiz-cpt.php), [`../class-wp-mcp-ai-media-template-cpt.php`](../class-wp-mcp-ai-media-template-cpt.php), [`../class-wp-mcp-ai-media-collection-cpt.php`](../class-wp-mcp-ai-media-collection-cpt.php), [`../class-wp-mcp-ai-place-cpt.php`](../class-wp-mcp-ai-place-cpt.php), [`../calendar-booking/`](../calendar-booking/)
- Generic CPT AI-Assistant injection: [`../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php`](../admin/class-wp-mcp-ai-pro-cpt-ai-integration.php)
- Vault edit-screen metaboxes: [`../vault/`](../vault/) — encrypted-credential metaboxes ship inside the vault subsystem
- Base counterpart: [`includes/metaboxes/`](../../../../includes/metaboxes/) — generic content-assistant metabox
