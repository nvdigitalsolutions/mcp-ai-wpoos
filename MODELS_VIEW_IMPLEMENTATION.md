# Models View Implementation Summary

## Overview
Added a new "Models" view to the Orchestration Layer that displays all internal AI models (from the Model Rate Limits CCT) with their complete feature sets including costs, capabilities, and rate limits. The view allows inline editing of constraints to manage PHP environment response sizes and includes a sync feature for regular updates.

## Source of Truth
**`WP_MCP_AI_Model_Rate_Limits_CCT`** (JetEngine Custom Content Type) is the authoritative source for all model information:

### Stored Model Data
1. **Capabilities**: 
   - `supports_streaming` - Streaming response support
   - `supports_function_calling` - Tool/function calling support
   - `supports_vision` - Image processing support

2. **Limits**:
   - `tpm_limit` - Tokens Per Minute limit
   - `rpm_limit` - Requests Per Minute limit
   - `context_window` - Maximum context window size
   - `max_output_tokens` - Maximum output tokens per request

3. **Costing**:
   - `cost_per_1k_input_tokens` - Cost in USD per 1000 input tokens
   - `cost_per_1k_output_tokens` - Cost in USD per 1000 output tokens

4. **Metadata**:
   - `model_name` - Model identifier
   - `provider` - AI provider (OpenAI, Google, Anthropic, Azure, Ollama, LM Studio)
   - `tier` - Account tier (free, tier-1, tier-2, tier-3, scale)
   - `notes` - Additional notes
   - `fallback_model` - High-capacity fallback model for TPM overflow

## Architecture (Separation of Concerns)

### Presentation Layer
**File**: `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php`

**Responsibilities**:
- Renders the Models view UI
- Displays models grouped by provider
- Shows model capabilities, costs, and limits
- Provides editable fields with proper data attributes
- Renders sync button

**Key Methods**:
- `render_models_view()` - Main view renderer
- `get_all_models()` - Retrieves all models from CCT
- `render_provider_models_table()` - Renders table for each provider
- `render_model_row()` - Renders individual model row with editable fields

### Business Logic Layer
**File**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

**Responsibilities**:
- Validates user input and permissions
- Processes constraint updates
- Orchestrates model sync operations
- Logs all operations

**Key Methods**:
- `handle_update_model_constraint()` - Validates and saves constraint updates
- `handle_sync_models()` - Syncs models from defaults, preserving custom modifications

### Persistence Layer
**File**: `includes/class-wp-mcp-ai-model-rate-limits-cct.php`

**Responsibilities**:
- Manages JetEngine CCT for model storage
- Provides CRUD operations
- Handles model variants and fallbacks

**Key Methods**:
- `get_item_handler()` - Gets CCT handler
- `get_model_limits()` - Retrieves model data
- `get_default_model_data()` - Provides default model configurations

### Client-Side Layer
**File**: `assets/js/orchestration-models.js`

**Responsibilities**:
- Handles UI interactions (click-to-edit)
- Manages AJAX calls to backend
- Provides visual feedback
- Handles sync button interactions

**Key Methods**:
- `startEditing()` - Initiates inline editing
- `saveChanges()` - Saves constraint changes via AJAX
- `syncModels()` - Triggers model sync from defaults
- `updateFieldDisplay()` - Updates UI after successful save

**File**: `assets/css/orchestration-models.css`
- Styles for models table
- Editable field indicators
- Loading states and animations
- Provider grouping styles

## Features

### 1. Inline Editing of Constraints
Addresses the requirement to manage response sizes in PHP environments:

**Editable Fields**:
- TPM Limit - Controls tokens per minute to prevent API rate limit issues
- RPM Limit - Controls requests per minute
- Context Window - Maximum input size
- Max Output Tokens - Maximum response size (critical for PHP memory management)
- Input Cost - Cost tracking accuracy
- Output Cost - Cost tracking accuracy

**User Experience**:
- Click any value to edit
- Save with ✓ button or Enter key
- Cancel with ✗ button or Escape key
- Real-time validation
- Visual feedback (loading, success, error)

### 2. Model Sync from Defaults
Addresses the requirement for regular updates as providers change pricing/limits:

**Sync Process**:
1. Retrieves latest default model configurations
2. Compares with existing CCT entries
3. Updates changed fields (pricing, limits, capabilities)
4. Adds new models
5. Preserves custom modifications (notes, custom values)

**Preserved Fields** (not overwritten):
- Custom notes (unless auto-created variant)
- Custom fallback models
- Custom modifications to any field not in defaults

**Updated Fields**:
- Provider
- TPM/RPM limits
- Context window
- Max output tokens
- Tier
- Capabilities (streaming, functions, vision)
- Costs (input/output per 1K tokens)

**Results Display**:
- "X models added, Y updated, Z unchanged"
- Auto-reload after successful sync

### 3. Model Display
**Grouped by Provider**:
- OpenAI
- Google Gemini
- Anthropic
- Azure OpenAI
- Ollama (Local)
- LM Studio (Local)

**Table Columns**:
1. Model - Name, notes, fallback model
2. Capabilities - Streaming, Functions, Vision badges
3. Context - Context window, max output tokens
4. Cost - Input/output costs per 1K tokens
5. Rate Limits - TPM/RPM limits
6. Tier - Account tier badge

## Navigation Pattern
Follows the same pattern as Token Manager (`?page=wp-mcp-ai-dashboard&tab=token_manager&view=per_tool`):

**URL Structure**:
```
?page=wp-mcp-ai-dashboard&tab=orchestration&view=models
```

**Navigation Tabs** (Orchestration):
- Overview - Dashboard with health status and stats
- Settings - Feature toggles and retention settings
- Thresholds - Health monitoring and budget sliders
- **Models** - AI models registry (NEW)

## Security

### Nonce Verification
- `wp_mcp_ai_models_nonce` for all AJAX operations
- Verified on every constraint update and sync operation

### Permission Checks
- `manage_options` capability required
- Checked on AJAX handlers
- Checked on view rendering

### Input Validation
- **Allowed fields whitelist**: Only specific fields can be edited
- **Type validation**: Integer for limits, float for costs
- **Range validation**: All values must be >= 0
- **Sanitization**: All inputs sanitized before database operations

### SQL Injection Prevention
- Uses JetEngine CCT abstraction layer
- No direct SQL queries
- All parameters properly escaped

## Logging

All operations are logged via `WP_MCP_AI_Logger`:

**Events Logged**:
- `model_constraint_updated` - When a constraint is modified
- `models_synced` - When sync operation completes
- Errors with full context and stack traces

**Log Data Includes**:
- User ID
- Model name
- Field modified
- Old and new values
- Timestamp
- Operation counts (for sync)

## File Structure

```
wp-mcp-ai/
├── includes/
│   ├── admin/
│   │   ├── class-wp-mcp-ai-admin-ajax-handlers.php (Business Logic)
│   │   ├── class-wp-mcp-ai-admin-settings.php (Asset Enqueuing)
│   │   └── sections/
│   │       └── class-wp-mcp-ai-section-orchestration.php (Presentation)
│   └── class-wp-mcp-ai-model-rate-limits-cct.php (Persistence)
├── assets/
│   ├── css/
│   │   └── orchestration-models.css (Styling)
│   └── js/
│       └── orchestration-models.js (Client Interactions)
└── MODELS_VIEW_IMPLEMENTATION.md (This file)
```

## Usage

### Accessing the Models View
1. Navigate to **Settings → WP oOS**
2. Click on **Orchestration** tab
3. Click on **Models** sub-tab

### Editing a Constraint
1. Click on any editable value (TPM, RPM, context, cost)
2. Edit the value in the input field
3. Click ✓ Save or press Enter
4. Wait for success confirmation
5. Value updates immediately

### Syncing Models
1. Click **"Sync Models from Defaults"** button
2. Confirm the action in the dialog
3. Wait for sync to complete
4. Review results message
5. Page auto-reloads to show updated models

### Managing Response Sizes
To manage PHP environment response size constraints:

1. **Reduce Max Output Tokens**:
   - Click on "Max out: X" value under Context column
   - Set lower value (e.g., 4000 instead of 16384)
   - Save

2. **Adjust TPM Limits**:
   - Click on TPM value under Rate Limits
   - Set appropriate limit for your environment
   - Save

3. **Set Context Window**:
   - Click on context window value
   - Adjust to match your PHP memory limits
   - Save

## Testing Checklist

- [ ] View renders correctly with all providers
- [ ] Models display with accurate data
- [ ] Inline editing works for all fields
- [ ] Save validation prevents invalid values
- [ ] Cancel button restores original values
- [ ] Sync button adds new models
- [ ] Sync button updates existing models
- [ ] Sync preserves custom modifications
- [ ] Permissions prevent unauthorized access
- [ ] Nonce verification blocks CSRF attacks
- [ ] Logging records all operations
- [ ] UI matches WordPress admin standards
- [ ] Responsive design works on mobile
- [ ] Keyboard navigation (Tab, Enter, Escape) works
- [ ] Error messages display clearly
- [ ] Success messages confirm operations

## Future Enhancements

1. **Bulk Edit**: Select multiple models and edit constraints at once
2. **Import/Export**: Import model configurations from JSON
3. **Version History**: Track changes to model configurations over time
4. **Cost Calculator**: Preview cost impact of constraint changes
5. **Usage Stats**: Show actual usage per model in the table
6. **Alerts**: Notify when model pricing changes detected
7. **Comparison View**: Compare multiple models side-by-side
8. **Custom Models**: Add support for custom/private model endpoints

## Related Documentation

- `docs/ORCHESTRATION-LAYER-ARCHITECTURE.md` - Orchestration layer overview
- `docs/tool-reference.md` - All 65+ tools documented
- `docs/rest-api.md` - REST API reference
- `includes/class-wp-mcp-ai-model-rate-limits-cct.php` - CCT implementation
- `includes/class-wp-mcp-ai-cost-calculator.php` - Cost calculation logic
- `includes/class-wp-mcp-ai-model-selector.php` - Model routing logic
