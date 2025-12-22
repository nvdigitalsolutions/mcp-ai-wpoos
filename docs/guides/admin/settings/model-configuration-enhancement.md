# Model Configuration Page Enhancement

## Overview
This document describes the enhancements made to the Model Configuration page in the Orchestration tab to add model expansion and capability-based filtering to the fallback model selection.

## Changes Summary

### Before
- Fallback model field was a simple text input
- No validation or suggestions for fallback models
- Users had to manually type model IDs
- No filtering based on model capabilities

### After
- Fallback model field is a grouped dropdown (select element)
- Models are organized by provider (OpenAI, Anthropic, Gemini, etc.)
- Automatic capability-based filtering ensures compatible fallbacks
- Models cannot select themselves as fallbacks
- Better user experience with clear visual grouping

## Technical Details

### Capability Detection

The system automatically detects model capabilities:

#### OpenAI Models
- **Text-only**: o1 series, o3-mini, GPT-4, GPT-3.5 Turbo
- **Vision + Multimodal**: GPT-4o series, GPT-4 Turbo, GPT-4 Vision

#### Anthropic Models
- **All Claude models**: Vision + Multimodal capable

#### Google Gemini Models
- **Text-only**: Gemini Pro, Gemma series
- **Vision**: Gemini Pro Vision
- **Vision + Multimodal**: Gemini 2.x series, Gemini 1.5 series, Gemini Experimental

#### Ollama & LM Studio
- **Text-only** (assumed, unless specified otherwise)

### Capability-Based Filtering

When selecting a fallback model, the dropdown automatically filters models based on the source model's capabilities:

1. **Text-only model** → Can use any available model as fallback
2. **Vision-capable model** → Only shows vision-capable models as fallback options
3. **Multimodal model** → Only shows multimodal-capable models as fallback options

This ensures that if a vision-capable model fails, it will fall back to another vision-capable model, maintaining the same capabilities.

## User Interface

### Dropdown Structure
```html
<select>
  <option value="">None</option>
  <optgroup label="OpenAI">
    <option value="gpt-4o">GPT-4o</option>
    <option value="gpt-4o-mini">GPT-4o Mini</option>
    ...
  </optgroup>
  <optgroup label="Anthropic (Claude)">
    <option value="claude-3-5-sonnet-20241022">Claude 3.5 Sonnet</option>
    ...
  </optgroup>
  <optgroup label="Google Gemini">
    <option value="gemini-2.5-flash">Gemini 2.5 Flash</option>
    ...
  </optgroup>
</select>
```

### CSS Improvements
- Select dropdowns have a max-width of 250px (vs 150px for text inputs)
- Consistent styling with other form elements
- Better visual hierarchy with optgroups

## Implementation Files

### Modified Files
1. **`includes/admin/class-wp-mcp-ai-model-config-renderer.php`**
   - Added `get_model_capability_flags()` method
   - Added `get_available_models_for_fallback()` method
   - Added `get_basic_model_list()` fallback method
   - Updated `render_model_row()` to use select dropdown
   - Updated CSS for better select styling

### New Files
2. **`tests/test-model-config-renderer.php`**
   - Comprehensive unit tests for the renderer
   - Tests for capability detection
   - Tests for model filtering
   - Tests for HTML output

## Benefits

1. **Improved UX**: Users can easily see and select from available models
2. **Prevents Errors**: Cannot select incompatible fallback models
3. **Consistency**: Same UI pattern as Tool Model Preferences
4. **Maintainability**: Reuses existing capability filtering logic from `WP_MCP_AI_Tool_Token_Limits`
5. **Flexibility**: Falls back to basic model list if filtering class unavailable

## Related Pull Requests

- PR #1341: Added capability-based filtering to tool model selection (foundation for this feature)
- Current PR: Extends same functionality to model configuration page

## Testing

Run the unit tests:
```bash
composer test -- tests/test-model-config-renderer.php
```

Or test all model-related tests:
```bash
composer test -- --filter=Model
```

## Screenshots

See the PR for screenshots showing:
1. Before: Text input for fallback model
2. After: Grouped dropdown with provider organization
3. After: Capability-based filtering in action
