# CPT Settings and Research Pages - Assistant Integration Changes

## Summary

This document describes the changes made to integrate assistant configuration from settings pages into the Research & Add pages for all Custom Post Types (CPTs).

## Changes Overview

### Problem
The Research & Add pages were automatically selecting the first available assistant instead of using the assistant configured in their respective settings pages. Additionally, the settings pages included provider and model fields that were redundant since these are already configured in the assistant itself.

### Solution
1. **Research Pages**: Now read the configured assistant from their settings option
2. **Settings Pages**: Removed provider/model fields, kept only assistant selection
3. **Additional Settings**: Added CPT-specific settings where appropriate
4. **Fallback Behavior**: If no assistant is configured or the configured assistant is invalid, fall back to the first available published assistant

## Modified Files

### Quiz CPT
- **Research Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-quiz-research-page.php`
  - Now reads `wp_mcp_ai_quiz_settings` option for `assistant_id`
  
- **Settings Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-quiz-settings-page.php`
  - Removed: `provider` and `model` fields
  - Added: `default_time_limit` (minutes)
  - Added: `default_passing_score` (percentage, 0-100)
  - Added: `enable_research` (toggle)
  - Kept: `assistant_id` selection

### Place CPT
- **Research Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-place-research-page.php`
  - Now reads `wp_mcp_ai_place_settings` option for `assistant_id`
  
- **Settings Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-place-settings-page.php`
  - Removed: `provider` and `model` fields
  - Added: `enable_research` (toggle)
  - Kept: `assistant_id` selection

### ECA CPT
- **Research Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-eca-research-page.php`
  - Now reads `wp_mcp_ai_eca_settings` option for `assistant_id`
  
- **Settings Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-eca-settings-page.php`
  - Removed: `provider` and `model` fields
  - Added: `enable_research` (toggle)
  - Kept: `assistant_id` selection

### Policy CPT
- **Research Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-policy-research-page.php`
  - Now reads `wp_mcp_ai_policy_settings` option for `assistant_id`
  
- **Settings Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-policy-settings-page.php`
  - Removed: `provider` and `model` fields
  - Added: `enable_research` (toggle)
  - Kept: `assistant_id` selection

### Project CPT
- **Settings Page**: `/addons/pro/includes/admin/class-wp-mcp-ai-project-settings-page.php`
  - Removed: `provider` and `model` fields
  - Kept: `assistant_id` selection
  - Note: Project CPT does not have a research page

## Testing Instructions

### Manual Testing

#### 1. Test Settings Pages

For each CPT (Quiz, Place, ECA, Policy, Project):

1. Navigate to the CPT's settings page:
   - Quiz: `/wp-admin/edit.php?post_type=mcp_ai_quiz&page=quiz-settings`
   - Place: `/wp-admin/edit.php?post_type=mcp_ai_place&page=place-settings`
   - ECA: `/wp-admin/edit.php?post_type=mcp_ai_eca&page=eca-settings`
   - Policy: `/wp-admin/edit.php?post_type=mcp_ai_policy&page=policy-settings`
   - Project: `/wp-admin/edit.php?post_type=mcp_ai_project&page=project-settings`

2. Verify the following fields are present:
   - ✅ Assistant dropdown (with auto-select option)
   - ❌ Provider field (should NOT be present)
   - ❌ Model field (should NOT be present)

3. For Quiz settings specifically, also verify:
   - ✅ Default Time Limit field
   - ✅ Default Passing Score field
   - ✅ Enable Research & Add checkbox

4. For Place, ECA, and Policy settings, verify:
   - ✅ Enable Research & Add checkbox

5. Save settings and verify they persist correctly

#### 2. Test Research Pages

For each CPT with a research page (Quiz, Place, ECA, Policy):

1. Create at least 2 assistants with different names
2. Navigate to the CPT's settings page and select a specific assistant
3. Save the settings
4. Navigate to the Research & Add page:
   - Quiz: `/wp-admin/edit.php?post_type=mcp_ai_quiz&page=research-quiz`
   - Place: `/wp-admin/edit.php?post_type=mcp_ai_place&page=research-place`
   - ECA: `/wp-admin/edit.php?post_type=mcp_ai_eca&page=research-eca`
   - Policy: `/wp-admin/edit.php?post_type=mcp_ai_policy&page=research-policy`

5. Verify the chat interface loads with the selected assistant
6. Send a test message to confirm the assistant responds

#### 3. Test Fallback Behavior

1. In settings, select "-- Auto-select first available --" (assistant_id = 0)
2. Navigate to the research page
3. Verify that it uses the most recently created assistant (by date DESC)

4. In settings, select an assistant and save
5. Delete that assistant
6. Navigate to the research page
7. Verify it falls back to the first available published assistant

#### 4. Test Quiz-Specific Settings

1. Navigate to Quiz Settings
2. Set Default Time Limit to `30` minutes
3. Set Default Passing Score to `75` percent
4. Save settings
5. Create a new quiz (manually or via research)
6. Verify the new quiz has the default values applied

### Automated Testing

Run the test suite:

```bash
composer test
```

Specific test file for this feature:
```bash
vendor/bin/phpunit addons/pro/tests/test-cpt-settings-assistant-integration.php
```

## Database Options

The following WordPress options are used:

- `wp_mcp_ai_quiz_settings` - Quiz CPT settings
- `wp_mcp_ai_place_settings` - Place CPT settings
- `wp_mcp_ai_eca_settings` - ECA CPT settings
- `wp_mcp_ai_policy_settings` - Policy CPT settings
- `wp_mcp_ai_project_settings` - Project CPT settings

Each option stores an array with the following structure:

### Quiz Settings
```php
array(
    'assistant_id'          => int,    // Assistant post ID (0 = auto-select)
    'default_time_limit'    => int,    // Minutes (0 = no limit)
    'default_passing_score' => int,    // Percentage (0-100)
    'enable_research'       => bool,   // Enable/disable research page
)
```

### Place/ECA/Policy Settings
```php
array(
    'assistant_id'    => int,  // Assistant post ID (0 = auto-select)
    'enable_research' => bool, // Enable/disable research page
)
```

### Project Settings
```php
array(
    'assistant_id' => int, // Assistant post ID (0 = auto-select)
)
```

## Backwards Compatibility

- **Settings Migration**: No migration needed. Old settings with `provider` and `model` will be ignored.
- **Research Pages**: Will continue to work with auto-selection if no assistant is configured.
- **Default Behavior**: All changes maintain backward compatibility with existing installations.

## Security Considerations

- All settings are sanitized using `absint()` for IDs and `(bool)` for checkboxes
- Passing score is validated to be between 0-100
- Assistant ID validation includes checking if the post exists and is published
- Fallback behavior ensures the system always has a valid assistant to use

## Known Limitations

- The `enable_research` toggle is saved in settings but not currently enforced in the UI (research pages are always accessible if the menu is registered)
- Default quiz settings (time limit, passing score) are stored but not yet automatically applied to new quizzes created via the research page (would need integration with the create_quiz tool)

## Future Enhancements

1. Enforce `enable_research` toggle to hide/show research menu items
2. Apply default quiz settings when creating quizzes via research page
3. Add similar default settings for other CPTs (e.g., default place categories)
4. Add settings page UI to show which assistant is currently selected
5. Add validation warning if selected assistant is deleted
