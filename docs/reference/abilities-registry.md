# NV oOS Abilities Registry

Auto-generated catalog of NV oOS tools registered as WordPress Abilities for AI agent discovery via the MCP Adapter.

**Generated:** 2026-08-06 | **Total:** 41 abilities across 5 categories | **WP Requirement:** 6.9+ (gracefully degrades on earlier versions)

---

## Category: nvoos-site — Site Information (6 abilities)

| Ability ID | Tool Slug | Capability |
|---|---|---|
| `nvoos/get-site-summary` | `get_site_summary` | `edit_posts` |
| `nvoos/get-user-info` | `get_user_info` | `edit_posts` |
| `nvoos/get-environment-status` | `get_environment_status` | `edit_posts` |
| `nvoos/get-profession` | `get_profession` | `edit_posts` |
| `nvoos/get-system-logs` | `get_system_logs` | `edit_posts` |
| `nvoos/get-update-status` | `get_update_status` | `edit_posts` |

---

## Category: nvoos-content — Content & Publishing (12 abilities)

| Ability ID | Tool Slug | Capability |
|---|---|---|
| `nvoos/get-post` | `get_post` | `edit_posts` |
| `nvoos/get-recent-posts` | `get_recent_posts` | `edit_posts` |
| `nvoos/get-post-type-schema` | `get_post_type_schema` | `edit_posts` |
| `nvoos/get-rankmath-seo` | `get_rankmath_seo` | `edit_posts` |
| `nvoos/search-content` | `search_content` | `edit_posts` |
| `nvoos/get-jetengine-items` | `get_jetengine_items` | `edit_posts` |
| `nvoos/get-elementor-templates` | `get_elementor_templates` | `edit_posts` |
| `nvoos/get-jetformbuilder-forms` | `get_jetformbuilder_forms` | `edit_posts` |
| `nvoos/get-jetformbuilder-submissions` | `get_jetformbuilder_submissions` | `edit_posts` |
| `nvoos/get-elementor-form-submissions` | `get_elementor_form_submissions` | `edit_posts` |
| `nvoos/get-all-form-submissions` | `get_all_form_submissions` | `edit_posts` |
| `nvoos/list-jetengine-rest-routes` | `list_jetengine_rest_routes` | `edit_posts` |

---

## Category: nvoos-media — Media & Images (1 ability)

| Ability ID | Tool Slug | Capability |
|---|---|---|
| `nvoos/search-attachments` | `search_attachments` | `edit_posts` |

---

## Category: nvoos-system — System & Diagnostics (13 abilities)

| Ability ID | Tool Slug | Capability |
|---|---|---|
| `nvoos/get-site-health` | `get_site_health` | `edit_posts` |
| `nvoos/list-cron-jobs` | `list_cron_jobs` | `edit_posts` |
| `nvoos/get-cron-job` | `get_cron_job` | `edit_posts` |
| `nvoos/list-vector-stores` | `list_vector_stores` | `read` |
| `nvoos/list-professions` | `list_professions` | `edit_posts` |
| `nvoos/get-all-import-status` | `get_all_import_status` | `edit_posts` |
| `nvoos/get-batch-status` | `get_batch_status` | `edit_posts` |
| `nvoos/get-openai-file-details` | `get_openai_file_details` | `edit_posts` |
| `nvoos/list-all-export-templates` | `list_all_export_templates` | `edit_posts` |
| `nvoos/list-all-import-templates` | `list_all_import_templates` | `edit_posts` |
| `nvoos/list-batches` | `list_batches` | `edit_posts` |
| `nvoos/list-openai-files` | `list_openai_files` | `edit_posts` |
| `nvoos/list-jetengine-rest-routes` | `list_jetengine_rest_routes` | `edit_posts` |

---

## Category: nvoos-discovery — AI Model Discovery (10 abilities)

| Ability ID | Tool Slug | Capability |
|---|---|---|
| `nvoos/list-available-models` | `list_available_models` | `edit_posts` |
| `nvoos/get-model-information` | `get_model_information` | `edit_posts` |
| `nvoos/suggest-best-model` | `suggest_best_model` | `edit_posts` |
| `nvoos/discover-new-models` | `discover_new_models` | `edit_posts` |
| `nvoos/count-tokens` | `count_tokens` | `edit_posts` |
| `nvoos/get-woo-products` | `get_woo_products` | `edit_posts` |
| `nvoos/get-nhc-active-storms` | `get_nhc_active_storms` | `edit_posts` |
| `nvoos/get-open-meteo-forecast` | `get_open_meteo_forecast` | `edit_posts` |
| `nvoos/get-gdacs-events` | `get_gdacs_events` | `edit_posts` |
| `nvoos/search-places` | `search_places` | `edit_posts` |

---

## Annotation Summary

All 41 abilities have explicit MCP annotations set from their capability flags:

| Annotation | Tools with `true` |
|---|---|
| `readOnlyHint` | 41 (all are `get_*`, `list_*`, or `search_*` tools) |
| `destructiveHint` | 0 (none are write/delete tools) |
| `idempotentHint` | 41 (all read-only tools are idempotent) |
| `openWorldHint` | 19 (tools calling `external-api`, `network-dependent`, or `long-running`) |

## Execution Hooks

| Hook | Parameters |
|---|---|
| `wp_mcp_ai_before_ability_execute` | `$ability_id`, `$tool_slug`, `$input` |
| `wp_mcp_ai_after_ability_execute` | `$ability_id`, `$tool_slug`, `$input`, `$result`, `$duration` |

## Backward Compatibility

All registrations guarded by `function_exists('wp_register_ability')`. On WP < 6.9: no abilities registered, no errors, existing MCP endpoint unchanged.
