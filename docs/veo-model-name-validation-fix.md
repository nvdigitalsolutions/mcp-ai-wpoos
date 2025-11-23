# Veo Model Name Validation Fix

## Problem Summary
The Gemini API integration was returning an "unexpected model name" error when using the Veo video generation tool. This occurred because the assistant's chat model name (e.g., `gemini-2.0-flash-exp`) could potentially be passed to the video generation service, which only accepts specific Veo model identifiers.

## Root Cause Analysis

### How Model Names Flow Through the System

1. **Assistant Configuration** → Contains the assistant's chat model (e.g., `gemini-2.0-flash-exp`)
2. **Tool Call Arguments** → AI providers may include a `model` parameter in tool calls
3. **Tool Schema Filtering** → The `filter_tool_arguments_by_schema()` method only filters parameters not defined in the schema
4. **Veo Tool Schema** → Accepts a `model` parameter with enum values
5. **Video Generation Service** → Expects specific Veo model identifiers

### The Issue
The Veo tool's schema defined a `model` parameter, so if an AI provider passed the assistant's chat model name in the tool call arguments, it would pass through the schema filter and reach the video generation service, causing an API error.

## Solution Implemented

### 1. Model Name Validation in Tool (Primary Fix)
**File:** `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

Added validation logic that:
- Checks if a `model` parameter is present in the arguments
- Validates it against a whitelist of valid Veo model names
- Filters out invalid model names (like chat model names)
- Logs filtered models for debugging

```php
if ( ! empty( $arguments['model'] ) ) {
    $model = sanitize_text_field( $arguments['model'] );
    $valid_models = array( 'veo-2.0', 'veo-3.1', 'veo-2.0-generate-001', 'veo-3.1-generate-preview' );
    if ( in_array( $model, $valid_models, true ) ) {
        $generation_args['model'] = $model;
    } else {
        // Log and filter out invalid model name
    }
}
```

### 2. Enhanced Service Model Handling
**File:** `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

Updated the service to:
- Accept BOTH simplified names ('veo-2.0', 'veo-3.1') AND full API identifiers
- Convert simplified names to full API names internally
- Document the model name translation layer

```php
$force_veo_3 = isset( $args['model'] ) && 
    ( 'veo-3.1' === $args['model'] || self::VEO_3_MODEL === $args['model'] );
$force_veo_2 = isset( $args['model'] ) && 
    ( 'veo-2.0' === $args['model'] || self::VEO_MODEL === $args['model'] );
```

### 3. Updated Tool Schema
**File:** `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

Expanded the `model` parameter enum to include all valid formats:
```php
'enum' => array( 'veo-2.0', 'veo-3.1', 'veo-2.0-generate-001', 'veo-3.1-generate-preview' )
```

## Model Name Formats

### Simplified Names (User-Friendly)
- `veo-2.0` → Maps to `veo-2.0-generate-001`
- `veo-3.1` → Maps to `veo-3.1-generate-preview`

### Full API Names (Actual Google API Identifiers)
- `veo-2.0-generate-001` (Veo 2.0 API endpoint)
- `veo-3.1-generate-preview` (Veo 3.1 API endpoint)

## Model Selection Hierarchy

The system uses a clear hierarchy for model selection:

1. **Explicit model in tool arguments** (validated by the tool)
   - If valid Veo model name → use it
   - If invalid → filter out and use default

2. **Settings value:** `default_gemini_video_model`
   - Configured in: Settings → Providers → Google Gemini
   - Options: 'veo-2.0' or 'veo-3.1'

3. **Constant default:** `VEO_MODEL` = 'veo-2.0-generate-001'
   - Used when no settings configured

4. **Automatic fallback** to alternate model on quota/availability errors
   - If Veo 2.0 fails → Try Veo 3.1
   - If Veo 3.1 fails → Try Veo 2.0

## Benefits of This Approach

### 1. Security
- Prevents invalid model names from reaching the API
- Logs filtered attempts for security monitoring
- Validates all model parameters before processing

### 2. Flexibility
- Accepts both simplified and full API names
- Future-proof if Google changes API identifiers
- Easy to add new Veo models (just update constants and validation arrays)

### 3. User Experience
- Clear, user-friendly model names in settings
- Descriptive error logging for troubleshooting
- Automatic fallback ensures reliability

### 4. Maintainability
- Centralized model name validation
- Well-documented translation layer
- Clear separation of concerns

## Testing

### Test Coverage
Created comprehensive tests in `tests/test-veo-model-name-validation.php`:

1. **Invalid Model Filtering Test**
   - Verifies chat model names are filtered out
   - Confirms logging of filtered models

2. **Valid Model Name Acceptance Test**
   - Confirms simplified names work
   - Verifies full API names work
   - Tests conversion logic

3. **Default Fallback Test**
   - Validates default when no settings configured
   - Confirms fallback to veo-2.0-generate-001

4. **Schema Validation Test**
   - Ensures all valid formats are in schema enum
   - Verifies schema structure

## Impact

### What This Fixes
✅ Prevents "unexpected model name" errors from Gemini API  
✅ Filters out assistant chat model names from video generation  
✅ Provides clear logging for debugging  
✅ Ensures only valid Veo models reach the API  

### What This Doesn't Change
- Existing functionality remains unchanged
- Default model behavior is preserved
- Fallback logic continues to work
- Settings configuration remains the same

## Example Scenarios

### Scenario 1: AI Passes Invalid Model
```
AI sends: { model: "gemini-2.0-flash-exp", prompt: "..." }
Tool validates: "gemini-2.0-flash-exp" not in valid list
Tool filters out: model parameter removed
Tool logs: Invalid model filtered
Service uses: Default from settings (veo-2.0-generate-001)
```

### Scenario 2: AI Passes Valid Simplified Name
```
AI sends: { model: "veo-3.1", prompt: "..." }
Tool validates: "veo-3.1" in valid list
Tool passes: model = "veo-3.1"
Service converts: "veo-3.1" → "veo-3.1-generate-preview"
API receives: veo-3.1-generate-preview
```

### Scenario 3: AI Passes Valid Full API Name
```
AI sends: { model: "veo-2.0-generate-001", prompt: "..." }
Tool validates: "veo-2.0-generate-001" in valid list
Tool passes: model = "veo-2.0-generate-001"
Service uses: "veo-2.0-generate-001" directly
API receives: veo-2.0-generate-001
```

### Scenario 4: No Model Specified
```
AI sends: { prompt: "..." }
Tool: No model parameter to validate
Service: Uses get_default_model()
Settings: default_gemini_video_model = "veo-2.0"
Service converts: "veo-2.0" → "veo-2.0-generate-001"
API receives: veo-2.0-generate-001
```

## Related Files

- `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php` - Tool validation
- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php` - Service logic
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` - Settings UI
- `tests/test-veo-model-name-validation.php` - Test coverage

## Future Considerations

1. **Additional Veo Models**: When Google releases new Veo models, update:
   - Constants in service class
   - Valid models array in tool class
   - Enum in tool schema
   - Settings dropdown

2. **Model Capabilities**: Consider adding model capability checks (e.g., 1080p support)

3. **Error Messages**: Could enhance error messages to suggest valid model names

4. **Monitoring**: Track filtered model attempts to identify patterns
