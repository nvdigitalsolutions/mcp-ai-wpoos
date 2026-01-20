# Model Manager UI Integration - TODO

## New Requirement
Add a "Model Manager" view to the Token Manager tab at:
`wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=token_manager&view=model_manager`

## Implementation Plan

### 1. Update Token Manager Section
File: `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`

Add navigation tab (around line 103-104):
```php
<a href="<?php echo esc_url( $this->get_view_url( 'model_manager' ) ); ?>" 
   class="wp-mcp-ai-token-manager__nav-item <?php echo 'model_manager' === $active_view ? 'active' : ''; ?>">
    <span class="dashicons dashicons-update"></span>
    <?php esc_html_e( 'Model Manager', 'mcp-ai-wpoos' ); ?>
</a>
```

Add case in switch statement (around line 130):
```php
case 'model_manager':
    $this->render_model_manager_view();
    break;
```

Add render method (after line 1170):
```php
private function render_model_manager_view() {
    // UI for discovering, researching, and adding models
    // See full implementation in MODEL-MANAGER-UI.md
}
```

### 2. Create AJAX Handler File
File: `includes/admin/class-wp-mcp-ai-model-manager-ajax.php`

Create a new class to handle AJAX requests:
- `wp_mcp_ai_discover_models` - Calls discover_new_models tool
- `wp_mcp_ai_research_model` - Calls research_model tool
- `wp_mcp_ai_add_model_config` - Calls add_model_config tool

### 3. UI Components Needed

#### Discovery Section
- Button to trigger model discovery
- Display results showing:
  - Newly discovered models
  - Already configured models
  - Recommendations with confidence scores
- Action buttons to research and add each model

#### Research Section
- Input field for model ID
- Dropdown for provider selection
- Button to trigger research
- Display research results:
  - Model name and specifications
  - Context window, rate limits, cost
  - Confidence score
  - Add button to save to configuration

#### Current Models Summary
- Grid showing configured models by provider
- Link to "Per Models" view for editing

### 4. JavaScript Functionality
- AJAX calls to execute tools via REST API or admin-ajax.php
- Real-time result display
- Success/error handling
- Loading spinners during operations
- Automatic refresh after adding models

### 5. Security
- Nonce verification for all AJAX requests
- Capability check: `manage_options` required
- Sanitize all inputs
- Validate responses before displaying

## Benefits
- Centralized model management interface
- Visual workflow for adding new models
- No need to use tools manually via chat
- Immediate feedback on operations
- Integrated into existing admin UI

## Files to Modify/Create
1. `/home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos/includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`
2. `/home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos/includes/admin/class-wp-mcp-ai-model-manager-ajax.php` (new)
3. `/home/runner/work/mcp-ai-wpoos/mcp-ai-wpoos/assets/css/admin/model-manager.css` (new, optional)

## Reference
- See full UI mockup in `MODEL-MANAGER-UI.md`
- Tool documentation: `docs/features/MODEL-RESEARCH-TOOLS.md`
- Usage examples: `assets/examples/model-research-tools-usage.php`
