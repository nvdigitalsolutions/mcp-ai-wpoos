# Professional Test Model Architecture Changes

## Overview

This document describes the changes made to the professional test model to support testing professions without requiring a default assistant to be set, and to implement proper append mode when an associated assistant is used.

## Problem Statement

**Previous behavior:**
- When testing a profession via `profession_123` format:
  - If profession has an associated assistant → use that assistant
  - If NOT → fallback to default assistant (required one to be set)
  - Profession data would **replace** assistant data
  
**Issues:**
1. Required a default assistant to be configured
2. Broke when no default assistant was set
3. Replaced assistant knowledge instead of appending

## Solution

### Changes Made

#### 1. `WP_MCP_AI_REST::resolve_assistant_id()` 

**File:** `includes/class-wp-mcp-ai-rest.php` (lines 4519-4555)

**Old behavior:**
```php
// No valid associated assistant - use default assistant (profession data will be merged).
$settings = WP_MCP_AI_Admin_Settings::get_settings();
$default  = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
return $default;
```

**New behavior:**
```php
// No valid associated assistant - return 0 to allow profession-only testing.
// The profession configuration will be used standalone without an assistant base.
return 0;
```

**Impact:** Professions can now be tested without requiring a default assistant to be configured.

#### 2. `WP_MCP_AI_REST::load_profession_configuration()`

**File:** `includes/class-wp-mcp-ai-rest.php` (lines 4589-4699)

**Key changes:**

**System Prompt Handling:**
- **With assistant base:** Appends profession knowledge to assistant's system prompt
- **Without assistant base:** Uses profession knowledge as primary system prompt

```php
if ( $has_assistant_base ) {
    // Append profession knowledge to assistant's base knowledge
    $assistant_config['system_prompt'] .= "\n\n" . __( 'Professional Role & Expertise:', 'wp-mcp-ai' ) . "\n" . $profession_prompt;
} else {
    // Use profession knowledge as primary
    $assistant_config['system_prompt'] = $profession_prompt;
}
```

**Tools Handling:**
- **With both:** Merges tools from both assistant and profession
- **Without assistant tools:** Uses profession tools
- **Without profession tools:** Keeps assistant tools

```php
if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
    if ( isset( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] ) && ! empty( $assistant_config['tools'] ) ) {
        // Merge tools
        $assistant_config['tools'] = array_unique( array_merge( $assistant_config['tools'], $default_tools ) );
    } else {
        // Use profession tools
        $assistant_config['tools'] = $default_tools;
    }
}
```

**Memory Files Handling:**
Same merge logic as tools.

**Provider/Model/Temperature:**
Profession settings still take priority (for testing specifics).

## Usage Scenarios

### Scenario 1: Profession with Associated Assistant

```
profession_789 → Associated Assistant (ID: 123)
├─ Assistant system prompt: "You are a helpful AI assistant..."
├─ Assistant tools: [general_tool, search]
└─ Profession adds:
   ├─ Appended to prompt: "\n\nProfessional Role & Expertise:\nYou are a tax advisor..."
   └─ Merged tools: [general_tool, search, calculator, tax_forms]

Result: Assistant has main knowledge, profession adds expertise
```

### Scenario 2: Profession Standalone (No Associated Assistant)

```
profession_789 → No Associated Assistant
├─ resolve_assistant_id() returns 0
├─ get_assistant_configuration(0) returns empty array
└─ load_profession_configuration():
   ├─ Primary prompt: "You are a tax advisor..."
   └─ Tools: [calculator, tax_forms]

Result: Profession knowledge is primary, no default assistant fallback
```

### Scenario 3: Profession with Associated Assistant (Empty Config)

```
profession_789 → Associated Assistant with minimal config
├─ Assistant has no system_prompt or empty
└─ load_profession_configuration():
   ├─ Primary prompt: "You are a tax advisor..."
   └─ Tools from profession

Result: Profession knowledge becomes primary
```

## Testing

### Updated Tests

**File:** `tests/test-profession-integration.php`

1. **`test_resolve_assistant_id_with_profession()`**
   - Now expects `0` instead of default assistant ID

2. **`test_profession_configuration_priority()`**
   - Tests append behavior
   - Verifies tools are merged
   - Confirms assistant base is preserved

3. **`test_profession_with_associated_assistant()`** (new)
   - Tests associated assistant flow
   - Validates append mode
   - Verifies tool merging

4. **`test_profession_without_assistant_standalone()`** (new)
   - Tests standalone mode
   - Validates profession-only configuration
   - Confirms no default assistant dependency

### Running Tests

```bash
# Run profession integration tests
vendor/bin/phpunit tests/test-profession-integration.php

# Run full test suite
vendor/bin/phpunit
```

## Migration Guide

### For Existing Installations

**No breaking changes for most scenarios:**

1. **Professions with associated assistants:** Work exactly as before, but now with proper append mode
2. **Professions without associated assistants:** Now work standalone instead of requiring default assistant

**Potential migration needed:**

If your workflow relied on:
- Testing profession without associated assistant
- Expecting it to use default assistant as base

**Solution:** Associate the default assistant with the profession in the profession edit page.

### For Developers

**When creating test professions:**

```php
// Old way (required default assistant)
update_option( 'wp_mcp_ai_default_assistant', $default_id );
$test_assistant_id = 'profession_' . $profession_id;
// This would use default assistant

// New way (standalone)
$test_assistant_id = 'profession_' . $profession_id;
// This works without default assistant

// New way (with association)
update_post_meta( $profession_id, '_wp_mcp_ai_profession_associated_assistant', $assistant_id );
$test_assistant_id = 'profession_' . $profession_id;
// This will use associated assistant + append profession data
```

## API Changes

### `resolve_assistant_id( $assistant_id )`

**Parameters:** Same
**Return value:** Now returns `0` for professions without associated assistants (instead of default assistant ID)

### `load_profession_configuration( $profession_id, $assistant_config )`

**Parameters:** Same
**Behavior changes:**
- Now appends profession prompt to assistant prompt (instead of replacing)
- Merges tools instead of replacing
- Merges memory files instead of replacing
- Provider/model/temperature still override (unchanged)

## Benefits

1. **No Default Assistant Required:** Professions can be tested independently
2. **Proper Knowledge Layering:** Assistant base + profession expertise (not replacement)
3. **Tool Flexibility:** Both assistant and profession tools are available
4. **Clearer Intent:** Code explicitly shows append vs standalone behavior
5. **Better Testing:** Each profession is self-contained for testing

## Backward Compatibility

✅ **Compatible:** Professions with associated assistants (improved append mode)
✅ **Compatible:** Regular assistant usage (unchanged)
✅ **Compatible:** Chat client flow (unchanged)
⚠️ **Changed:** Professions without associated assistant no longer fallback to default assistant

## Related Files

- `includes/class-wp-mcp-ai-rest.php` - Core logic
- `includes/admin/class-wp-mcp-ai-admin-test-profession.php` - Test UI
- `assets/js/admin-test-profession.js` - Test UI JavaScript
- `tests/test-profession-integration.php` - Integration tests
- `tests/test-admin-test-profession-features.php` - Admin page tests

## Verification

See `/tmp/verify-profession-model.php` for a standalone verification script that demonstrates the three key scenarios.
