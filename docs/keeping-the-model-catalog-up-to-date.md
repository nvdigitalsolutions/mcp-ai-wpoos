# Keeping the Model Catalog Up to Date

> Last reviewed: April 2026

NV oOS ships with a JSON-driven catalog of every AI model it knows about — provider, lineup, rate limits, pricing, lifecycle status, and fallback. This page describes how the catalog is structured and how to keep it current.

## Where the catalog lives

The single source of truth is `includes/data/model-catalog.json`. Each entry is an associative object with:

| Key | Description |
| --- | --- |
| `model_name` | Provider-side identifier (e.g., `gpt-4.1`, `claude-opus-4-7`). Used as the lookup key everywhere. |
| `provider` | One of `openai`, `anthropic`, `gemini`, `nvidia`, `cloudflare`, `huggingface`, `ollama`, `lm_studio`, `azure`, `webllm`, `embedded`, `google`. |
| `name` | Human-readable label shown in dropdowns. |
| `tpm` / `rpm` / `tpd` / `rpd` | Per-minute and per-day token / request limits. |
| `context_window` / `max_output_tokens` | Limits used by the chunker and request builder. |
| `supports_function_calling` / `supports_vision` | Capability flags. |
| `cost_per_1k_input_tokens` / `cost_per_1k_output_tokens` | USD pricing used by the cost calculator. |
| `fallback_model` | Successor used when this model is unavailable. |
| `status` | `active`, `deprecated`, or `legacy`. Only `active` entries appear in fallback dropdowns. |
| `sunset_date` | YYYY-MM-DD string when applicable; otherwise empty. |
| `notes` | Free-form context surfaced in the admin UI. |

## How the loader works

`WP_MCP_AI_Model_Rate_Limits_CCT::load_catalog()` reads the JSON once per request, applies the `wp_mcp_ai_model_catalog` filter, caches the result via `wp_cache_*` (group `wp_mcp_ai_model_catalog`), and returns the array. Callers like `Model_Config::get_default_configs()` and the Pricing Checker consume that array — there is no other source of model data.

## Adding or overriding entries

You have three options, in order of recommendation:

### 1. Filter (preferred for site-specific overrides)

```php
add_filter( 'wp_mcp_ai_model_catalog', function ( $catalog ) {
    $catalog[] = array(
        'model_name'                => 'my-finetune-2026-04',
        'provider'                  => 'openai',
        'name'                      => 'My Finetune (April 2026)',
        'tpm'                       => 100000,
        'rpm'                       => 500,
        'context_window'            => 128000,
        'max_output_tokens'         => 16384,
        'supports_function_calling' => true,
        'supports_vision'           => false,
        'cost_per_1k_input_tokens'  => 0.002,
        'cost_per_1k_output_tokens' => 0.008,
        'fallback_model'            => 'gpt-4o-mini',
        'status'                    => 'active',
        'sunset_date'               => '',
        'notes'                     => 'Org-internal finetune.',
    );
    return $catalog;
} );
```

After saving, click **NV oOS → Models → Reload model catalog** (or call `WP_MCP_AI_Model_Rate_Limits_CCT::flush_catalog_cache()`).

### 2. Drop-in JSON file

Place a fully-formed catalog at `wp-content/uploads/mcp-ai/model-catalog.json` and the loader auto-detects it. Useful for staging environments that need to override the bundled catalog without code changes.

To point at a different path entirely, use the `wp_mcp_ai_model_catalog_source_path` filter:

```php
add_filter( 'wp_mcp_ai_model_catalog_source_path', function () {
    return WPMU_PLUGIN_DIR . '/our-org-models.json';
} );
```

### 3. Edit the bundled JSON

Only do this when contributing upstream. PR against `includes/data/model-catalog.json` and bump the `version` field so the migration routine (`WP_MCP_AI_Model_Catalog_Migration`) re-runs for upgraders.

## Automatic discovery

A daily WP-Cron event `wp_mcp_ai_model_catalog_discovery` queries each enabled provider's `/models`-style endpoint and writes a diff (additions, sunsets, price changes) into the `wp_mcp_ai_model_catalog_suggestions` option. Suggestions are surfaced in the admin **Models → Suggestions** panel — they are *never* auto-applied. Admins click "Apply" to merge a suggestion into their overrides via the existing model editor code path.

Filters:
- `wp_mcp_ai_model_discovery_enabled` (default `true`) — set to `false` to disable.
- `wp_mcp_ai_model_discovery_interval` (default `'daily'`) — accepts any registered schedule slug.

Action:
- `wp_mcp_ai_model_catalog_suggestions_updated` fires with the diff payload after each run, so advanced users can opt into auto-apply via their own code.

## Migration of stored references

When a refresh removes ids, `WP_MCP_AI_Model_Catalog_Migration` runs once per catalog `version` on `init` and rewrites references in:

- `wp_mcp_ai_model_configs` option (keys + `fallback_model` fields)
- `_wp_mcp_ai_model` post meta on assistant CPT entries
- `wp_mcp_ai_settings.default_model`

Mappings (`get_legacy_id_map()`) are documented and tested. A removed id without a documented successor is not auto-rewritten.

## Debug switch

Set `define( 'WP_MCP_AI_FORCE_HARDCODED_CATALOG', true );` to bypass the JSON loader entirely. This is for support scenarios when the JSON is suspected of being corrupt; the loader falls back to an empty array (a logged error) and the rest of the plugin degrades gracefully without fatal errors.
