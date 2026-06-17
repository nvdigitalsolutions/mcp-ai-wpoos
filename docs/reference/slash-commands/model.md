# /model

> **Added in:** v2.1.0 · **Capability:** `edit_posts`

## Synopsis

```
/model [--list] [--current] [--set=<slug>] [--assistant-id=<n>] [--discover] [--json]
```

List available AI models, view or set the model on an assistant, and trigger model discovery refresh.

## Flags

| Flag | Description | Default |
|------|-------------|---------|
| `--list` | List available models from the catalog (default action) | On |
| `--current` | Show the model for the current/specified assistant | — |
| `--set=<slug>` | Set a model on an assistant (`manage_options` required) | — |
| `--assistant-id=<n>` | Target assistant post ID | Context `assistant_id` |
| `--discover` | Trigger model discovery refresh (`manage_options` required) | — |
| `--json` | Return raw JSON output | Off |

## Examples

```
/model
/model --current --assistant-id=42
/model --set=claude-3-opus --assistant-id=42
/model --discover
/model --json
```

## Required Capability

`edit_posts` (list/current); `manage_options` (set/discover)

## Notes

- Model list source: `WP_MCP_AI_Model_Service::get_available_models()` if available; falls back to `wp_mcp_ai_model_catalog` option.
- Model is stored in `_wp_mcp_ai_model` post meta on the assistant CPT.
