# AI Model Research & Configuration Tools

This document describes the three new tools that enable self-service AI model research and configuration in the NV oOS plugin.

## Overview

The plugin can now research and add AI models to its orchestration configuration without requiring code changes. This is accomplished through three interconnected tools that work together:

1. **research_model** - Research model specifications using AI
2. **add_model_config** - Add researched models to configuration
3. **discover_new_models** - Auto-discover new models from providers

## Tools

### 1. Research Model (`research_model`)

Uses the plugin's own AI capabilities to research model specifications from provider documentation and APIs.

#### Parameters

- `model_id` (string, required): Model identifier (e.g., "gpt-4.5-turbo")
- `provider` (string, required): Provider name (openai, anthropic, gemini, huggingface, ollama, lm_studio, cloudflare)
- `use_web_search` (boolean, optional): Whether to use web search for documentation (default: true)

#### Returns

Configuration object with:
- `name`: Display name
- `provider`: Provider identifier
- `context_window`: Maximum context tokens
- `tpm`, `rpm`, `tpd`, `rpd`: Rate limits
- `cost_per_1k`: Average cost per 1000 tokens
- `status`: Model status (active/deprecated/experimental)
- `fallback_model`: Suggested fallback
- `_research_metadata`: Research details (confidence, sources, capabilities, etc.)

#### Example Usage

```json
{
  "tool": "research_model",
  "arguments": {
    "model_id": "gpt-4.5-turbo",
    "provider": "openai",
    "use_web_search": true
  }
}
```

#### Response Example

```json
{
  "name": "GPT-4.5 Turbo",
  "provider": "openai",
  "context_window": 128000,
  "tpm": 80000,
  "rpm": 500,
  "tpd": 5000000,
  "rpd": 10000,
  "cost_per_1k": 0.01,
  "status": "active",
  "fallback_model": "gpt-4o",
  "_research_metadata": {
    "researched_at": "2026-01-08 21:00:00",
    "research_model": "gpt-4o",
    "research_provider": "openai",
    "confidence": 95,
    "sources": ["https://platform.openai.com/docs/models"],
    "capabilities": ["vision", "multimodal", "function-calling"],
    "description": "Advanced multimodal model with improved reasoning",
    "release_date": "2025-12-15"
  }
}
```

### 2. Add Model Config (`add_model_config`)

Adds or updates an AI model configuration in the orchestration layer.

#### Parameters

- `model_id` (string, required): Unique model identifier
- `config` (object, required): Model configuration object (typically from research_model)
  - `name` (string, required): Human-readable name
  - `provider` (string, required): Provider name
  - `context_window` (integer, required): Maximum context window
  - `tpm`, `rpm`, `tpd`, `rpd` (integer, optional): Rate limits
  - `cost_per_1k` (number, optional): Cost per 1000 tokens
  - `status` (string, optional): Model status
  - `fallback_model` (string, optional): Fallback model ID
- `overwrite` (boolean, optional): Whether to overwrite existing config (default: false)

#### Returns

Success response with:
- `success`: Boolean
- `message`: Success message
- `model_id`: Model identifier
- `config`: Full configuration saved
- `action`: "added" or "updated"

#### Example Usage

```json
{
  "tool": "add_model_config",
  "arguments": {
    "model_id": "gpt-4.5-turbo",
    "config": {
      "name": "GPT-4.5 Turbo",
      "provider": "openai",
      "context_window": 128000,
      "tpm": 80000,
      "rpm": 500,
      "cost_per_1k": 0.01,
      "status": "active"
    },
    "overwrite": false
  }
}
```

### 3. Discover New Models (`discover_new_models`)

Discovers newly released AI models by querying provider APIs and comparing against existing configurations.

#### Parameters

- `providers` (array, optional): List of providers to check. If empty, checks all configured providers.
- `auto_research` (boolean, optional): Whether to automatically research discovered models (default: false)

#### Returns

Discovery results with:
- `discovered`: Array of newly found models
- `already_exists`: Array of models already configured
- `errors`: Map of provider errors
- `recommendations`: Suggested models to add with confidence scores

#### Example Usage

```json
{
  "tool": "discover_new_models",
  "arguments": {
    "providers": ["openai", "gemini"],
    "auto_research": true
  }
}
```

#### Response Example

```json
{
  "discovered": [
    {
      "model_id": "gpt-4.5-turbo",
      "provider": "openai",
      "name": "GPT-4.5 Turbo",
      "research": { /* researched config if auto_research=true */ }
    },
    {
      "model_id": "gemini-3-pro-preview",
      "provider": "gemini",
      "name": "Gemini 3 Pro (Preview)"
    }
  ],
  "already_exists": [
    {
      "model_id": "gpt-4o",
      "provider": "openai",
      "name": "GPT-4o"
    }
  ],
  "recommendations": [
    {
      "model_id": "gpt-4.5-turbo",
      "provider": "openai",
      "name": "GPT-4.5 Turbo",
      "action": "research_and_add",
      "confidence": 85
    }
  ],
  "errors": {}
}
```

## Complete Workflow Examples

### Example 1: Research and Add a Specific Model

An administrator can ask an assistant:

> "Research and add the new gpt-4.5-turbo model to the system"

The assistant would:

1. Call `research_model`:
```json
{
  "model_id": "gpt-4.5-turbo",
  "provider": "openai",
  "use_web_search": true
}
```

2. Review the research results and call `add_model_config`:
```json
{
  "model_id": "gpt-4.5-turbo",
  "config": { /* researched configuration */ },
  "overwrite": false
}
```

3. Respond with: "Successfully added GPT-4.5 Turbo to the model configuration. It's now available for selection in assistants and orchestration settings."

### Example 2: Discover All New Models

An administrator asks:

> "Check all providers for new models and add any that look promising"

The assistant would:

1. Call `discover_new_models`:
```json
{
  "providers": [],
  "auto_research": true
}
```

2. Review the discovered models and recommendations

3. For each high-confidence recommendation, call `add_model_config`

4. Respond with a summary of models added

### Example 3: Update Existing Model Configuration

> "The pricing for gpt-4o has changed. Update it to $0.0075 per 1K tokens"

The assistant would:

1. Get current config (already exists)

2. Call `add_model_config` with updated config:
```json
{
  "model_id": "gpt-4o",
  "config": {
    "name": "GPT-4o",
    "provider": "openai",
    "context_window": 128000,
    "cost_per_1k": 0.0075,
    /* other existing fields */
  },
  "overwrite": true
}
```

## Security & Permissions

All three tools require the `manage_options` capability (WordPress administrator). This ensures that only administrators can:

- Research model specifications
- Add/update model configurations
- Discover new models

## Caching

### Research Results
- Cached for 7 days using `wp_cache_set()`
- Cache key: `model_research_{provider}_{model_id}`
- Cache group: `wp_mcp_ai_model_research`

### Model Configurations
- Cached via `WP_MCP_AI_Model_Config` (5 minutes)
- Cache cleared on add/update operations

## Storage

Model configurations are stored in:
- **Primary**: WordPress option `wp_mcp_ai_model_configs`
- **Optional**: JetEngine CCT `ai_model_configs` (if JetEngine is active)

## Metadata Tracking

Each added/updated configuration includes metadata:

```php
'_metadata' => array(
  'added_by' => 123,        // User ID who added it
  'added_at' => '2026-01-08 21:00:00',
  'added_via' => 'add_model_config_tool',
  'is_custom' => true,      // Not a default model
  'updated_by' => 456,      // If updated
  'updated_at' => '2026-01-09 10:00:00'
)
```

Research metadata is also preserved:

```php
'_research_metadata' => array(
  'researched_at' => '2026-01-08 21:00:00',
  'research_model' => 'gpt-4o',
  'research_provider' => 'openai',
  'confidence' => 95,
  'sources' => array( /* URLs */ ),
  'capabilities' => array( /* Model capabilities */ ),
  'description' => 'Model description',
  'release_date' => '2025-12-15'
)
```

## Provider Support

### Fully Supported
- ✅ **OpenAI**: List models via API, research specifications
- ✅ **Gemini**: List models via API, research specifications
- ✅ **HuggingFace**: List models via API, research specifications

### Partially Supported
- ⚠️ **Anthropic**: No public list API, uses known models, can research
- ⚠️ **Ollama**: Local models only, no discovery
- ⚠️ **LM Studio**: Local models only, no discovery

## Best Practices

### When to Use Research Tool
- New model released by provider
- Need accurate specifications
- Uncertain about rate limits or pricing
- Want to verify model capabilities

### When to Use Discovery Tool
- Regular maintenance (monthly/quarterly)
- After provider announcements
- Before upgrading assistants
- Proactive model availability monitoring

### Configuration Guidelines
1. **Always research first** - Don't guess specifications
2. **Verify pricing** - AI research may have outdated pricing
3. **Set appropriate fallbacks** - Choose similar models
4. **Update existing configs carefully** - Use `overwrite: true`
5. **Monitor confidence scores** - Only add high-confidence (>70%) models

## Troubleshooting

### "No AI provider configured"
- Ensure OpenAI, Gemini, or Anthropic API keys are configured
- Check Settings → NV oOS → AI Provider Configuration

### "Model configuration already exists"
- Use `overwrite: true` to update existing configurations
- Or delete the existing config first

### "Failed to parse AI response"
- AI model may have returned invalid JSON
- Try again with different research model
- Manually verify and format the response

### Low confidence score (<50%)
- Research may be uncertain about specifications
- Verify results manually before adding
- Use web search for better results

## Future Enhancements

Planned improvements:

1. **Admin UI** - Visual interface for model management
2. **Scheduled Discovery** - Automatic weekly/monthly discovery
3. **Version Comparison** - Compare model versions
4. **Provider Notifications** - Alert when new models released
5. **Bulk Operations** - Research and add multiple models at once
6. **Configuration Export/Import** - Share configurations between sites

## Related Documentation

- [Model Configuration System](../reference/technical/MODEL-CONFIG.md)
- [Orchestration Layer](../reference/technical/ORCHESTRATION.md)
- [Tool Reference](../reference/TOOL-REFERENCE.md)
- [AI Provider Configuration](../guides/PROVIDER-SETUP.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/` directory
- Security: See `SECURITY.md`
