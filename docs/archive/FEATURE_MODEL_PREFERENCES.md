# Tool Model Preferences Feature

## Overview

This feature allows administrators to set a preferred AI model for each tool in the Token Usage Manager. This provides fine-grained control over which AI model is used for specific tools, allowing optimization for different use cases.

## User Interface Location

The feature is accessible in:
**WordPress Admin → Settings → WP oOS → Token Manager Tab → Per Tool View**

## How It Works

### UI Changes

1. A new "Preferred Model" column has been added to the Token Limits by Tool table
2. The column appears to the left of the "Multiplier" column
3. Each tool has a dropdown select showing:
   - **Default** option (uses assistant/global settings)
   - Available models grouped by provider:
     - OpenAI models (GPT-4o, GPT-4o Mini, etc.)
     - Anthropic models (Claude 3.5 Sonnet, Claude 3.5 Haiku, etc.)
     - Google Gemini models (Gemini 2.0 Flash, Gemini 1.5 Pro, etc.)
     - Ollama models (if configured)
     - LM Studio models (if configured)

### Data Storage

Model preferences are stored in WordPress options table:
- **Option name**: `wp_mcp_ai_tool_model_preferences`
- **Data format**: Array of tool_slug => model_id pairs
- **Example**: 
  ```php
  array(
      'run_crawl4ai_job' => 'gpt-4o',
      'search_content' => 'claude-3-5-sonnet-20241022',
      'web_search' => 'default'
  )
  ```

### API Methods

New methods added to `WP_MCP_AI_Tool_Token_Limits` class:

#### `get_tool_model_preference( $tool_slug )`
Get the model preference for a specific tool.
- **Parameters**: `$tool_slug` (string) - Tool identifier
- **Returns**: (string) Model identifier or 'default'

#### `get_tool_model_preferences()`
Get all tool model preferences.
- **Returns**: (array) Tool slug => model preference pairs

#### `set_tool_model_preference( $tool_slug, $model )`
Set the model preference for a specific tool.
- **Parameters**: 
  - `$tool_slug` (string) - Tool identifier
  - `$model` (string) - Model identifier or 'default'
- **Returns**: (bool) Success status

#### `get_available_models()`
Get all available models from configured providers.
- **Returns**: (array) Grouped array of available models
- **Structure**:
  ```php
  array(
      'default' => 'Default (use assistant/global setting)',
      'openai_group' => array(
          'label' => 'OpenAI',
          'options' => array(
              'gpt-4o' => 'GPT-4o',
              // ... more models
          )
      ),
      // ... more provider groups
  )
  ```

### AJAX Integration

The existing `wp_mcp_ai_save_tool_limits` AJAX action has been enhanced to:
1. Accept a new `model_preferences` parameter
2. Validate model selections
3. Detect changes in model preferences
4. Save preferences alongside multipliers

### JavaScript Changes

The `handleSaveToolSettings` method in `settings-dashboard.js` now:
1. Collects model preferences from `.wp-mcp-ai-tool-model-input` elements
2. Sends them to the server in the `model_preferences` payload
3. Displays success/error messages appropriately

## Usage Example

### Setting a Model Preference via Code

```php
// Set GPT-4o as preferred model for the Crawl4AI tool
WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( 'run_crawl4ai_job', 'gpt-4o' );

// Get the current preference
$preference = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preference( 'run_crawl4ai_job' );
echo $preference; // Outputs: 'gpt-4o'

// Reset to default
WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( 'run_crawl4ai_job', 'default' );
```

### Filtering Available Models

Developers can filter the available models using the `wp_mcp_ai_available_tool_models` filter:

```php
add_filter( 'wp_mcp_ai_available_tool_models', function( $models ) {
    // Add a custom model group
    $models['custom_group'] = array(
        'label' => 'Custom Models',
        'options' => array(
            'custom-model-1' => 'Custom Model 1',
            'custom-model-2' => 'Custom Model 2',
        ),
    );
    return $models;
} );
```

## Benefits

1. **Performance Optimization**: Use faster models for simple tools, powerful models for complex ones
2. **Cost Control**: Assign cheaper models to high-volume tools
3. **Provider-Specific Features**: Leverage unique capabilities of different providers
4. **Flexibility**: Override global settings on a per-tool basis
5. **Backwards Compatible**: Defaults to existing assistant/global settings when not specified

## Technical Notes

- Model preferences only affect tools when they actually execute AI requests
- The "default" option means the tool will use the model specified in:
  1. The assistant configuration (if tool is called by an assistant)
  2. The global provider default model (as fallback)
- Model availability in the dropdown is determined by which providers have API keys configured
- Preferences persist across WordPress updates and plugin updates

## Future Enhancements

Potential improvements for future versions:
- Per-user model preferences (not just global)
- Model performance analytics (cost and speed tracking)
- Model recommendation engine based on tool usage patterns
- Bulk assignment of models to multiple tools
