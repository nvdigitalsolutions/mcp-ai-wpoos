# Profession Default Tools Fix - Visual Guide

## Problem Visualization

### Before Fix: Checkboxes Not Pre-Selected ❌

```
┌─────────────────────────────────────────────────────────────┐
│ Edit Profession: Software Developer                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ Expertise & Knowledge                                        │
│                                                              │
│ Default Tools                                                │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [ ] web_search                                          │ │
│ │     Search the web for information                      │ │
│ │                                                          │ │
│ │ [ ] search_content                                      │ │
│ │     Search WordPress content                            │ │
│ │                                                          │ │
│ │ [ ] save_post                                           │ │
│ │     Save content as WordPress post                      │ │
│ │                                                          │ │
│ │ [ ] create_chart                                        │ │
│ │     Create charts and visualizations                    │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                              │
│ ❌ Issue: Even though the profession has default_tools      │
│    defined (web_search, search_content, save_post),         │
│    the checkboxes are NOT checked!                          │
└─────────────────────────────────────────────────────────────┘
```

### After Fix: Checkboxes Properly Pre-Selected ✅

```
┌─────────────────────────────────────────────────────────────┐
│ Edit Profession: Software Developer                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ Expertise & Knowledge                                        │
│                                                              │
│ Default Tools                                                │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ [✓] web_search                                          │ │
│ │     Search the web for information                      │ │
│ │                                                          │ │
│ │ [✓] search_content                                      │ │
│ │     Search WordPress content                            │ │
│ │                                                          │ │
│ │ [✓] save_post                                           │ │
│ │     Save content as WordPress post                      │ │
│ │                                                          │ │
│ │ [ ] create_chart                                        │ │
│ │     Create charts and visualizations                    │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                              │
│ ✅ Fixed: Checkboxes are now correctly pre-selected         │
│    based on the profession's default_tools metadata          │
└─────────────────────────────────────────────────────────────┘
```

## Data Flow Comparison

### Before Fix: Inconsistent Array Handling ❌

```
┌──────────────┐
│  JSON File   │  default_tools: ["web_search", "search_content", "save_post"]
└──────┬───────┘
       │
       ▼
┌──────────────┐
│    Loader    │  ✓ Validates and sanitizes
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ AJAX Handler │  ✓ Processes reseed
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Repository  │  ❌ array_map() only
│    .save()   │  ❌ No array_filter()
└──────┬───────┘  ❌ No array_values()
       │          
       │          Saved: [0 => "web_search", 2 => "search_content", 4 => "save_post"]
       │                 ^ Non-sequential keys! ^
       ▼
┌──────────────┐
│   Database   │  wp_postmeta stores serialized array with non-sequential keys
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Metabox     │  ❌ No sanitization on retrieve
│   .render()  │  ❌ Tool slugs not sanitized
└──────┬───────┘  
       │          Retrieved: [0 => "web_search", 2 => "search_content", 4 => "save_post"]
       │          Checking: in_array("search_content", $saved_tools, true)
       │          Result: MIGHT FAIL due to key mismatch or whitespace!
       ▼
┌──────────────┐
│  Checkboxes  │  ❌ Not checked even though tools are saved
└──────────────┘
```

### After Fix: Consistent Array Handling ✅

```
┌──────────────┐
│  JSON File   │  default_tools: ["web_search", "search_content", "save_post"]
└──────┬───────┘
       │
       ▼
┌──────────────┐
│    Loader    │  ✓ Validates and sanitizes
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ AJAX Handler │  ✓ Processes reseed
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Repository  │  ✅ array_map() + array_filter() + array_values()
│    .save()   │  ✅ Removes empty values
└──────┬───────┘  ✅ Ensures sequential keys
       │          
       │          Saved: [0 => "web_search", 1 => "search_content", 2 => "save_post"]
       │                 ^ Sequential keys! ^
       ▼
┌──────────────┐
│   Database   │  wp_postmeta stores clean, sequential array
└──────┬───────┘
       │
       ▼
┌──────────────┐
│  Metabox     │  ✅ Sanitizes retrieved array
│   .render()  │  ✅ Sanitizes tool slugs during comparison
└──────┬───────┘  
       │          Retrieved: [0 => "web_search", 1 => "search_content", 2 => "save_post"]
       │          Checking: in_array("search_content", $saved_tools, true)
       │          Result: TRUE ✓
       ▼
┌──────────────┐
│  Checkboxes  │  ✅ Correctly checked!
└──────────────┘
```

## Code Changes Visualization

### Repository Save Method

```php
// ❌ BEFORE (Non-sequential keys, no filtering)
if ( isset( $data['default_tools'] ) && is_array( $data['default_tools'] ) ) {
    update_post_meta( 
        $post_id, 
        WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, 
        array_map( 'sanitize_key', $data['default_tools'] )
        //          ↑ Sanitizes but preserves keys [0, 2, 4] ❌
    );
}

// ✅ AFTER (Sequential keys, filtered)
if ( isset( $data['default_tools'] ) && is_array( $data['default_tools'] ) ) {
    $sanitized_tools = array_map( 'sanitize_key', $data['default_tools'] );
    $sanitized_tools = array_filter( $sanitized_tools ); // Removes empty ✓
    update_post_meta( 
        $post_id, 
        WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, 
        array_values( $sanitized_tools )
        //          ↑ Makes sequential [0, 1, 2] ✅
    );
}
```

### Metabox Render Method

```php
// ❌ BEFORE (No sanitization)
$default_tools = get_post_meta( $post->ID, META_DEFAULT_TOOLS, true );

if ( ! is_array( $default_tools ) ) {
    $default_tools = array();
}
// Retrieved: ["web_search", "search_content ", "save_post"]
//                                          ↑ Whitespace! ❌

// ✅ AFTER (Sanitized and filtered)
$default_tools = get_post_meta( $post->ID, META_DEFAULT_TOOLS, true );

if ( ! is_array( $default_tools ) ) {
    $default_tools = array();
}
$default_tools = array_filter( array_map( 'sanitize_key', $default_tools ) );
//               ↑ Removes empty    ↑ Sanitizes each value
// Result: ["web_search", "search_content", "save_post"] ✅
```

### Tool Slug Comparison

```php
// ❌ BEFORE (No sanitization during comparison)
foreach ( $available_tools as $tool ) {
    $tool_slug  = method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : '';
    //            ↑ Might have whitespace: "web_search " ❌
    $is_checked = in_array( $tool_slug, $default_tools, true );
    //            ↑ Strict comparison will fail! ❌
}

// ✅ AFTER (Sanitized comparison)
foreach ( $available_tools as $tool ) {
    $tool_slug  = method_exists( $tool, 'get_slug' ) 
                  ? sanitize_key( trim( $tool->get_slug() ) ) 
                  : '';
    //            ↑ Clean: "web_search" ✅
    $is_checked = in_array( $tool_slug, $default_tools, true );
    //            ↑ Matches perfectly! ✅
}
```

## Array Structure Examples

### Problem: Non-Sequential Keys

```php
// What might have been saved before the fix:
$saved_tools = [
    0 => 'web_search',
    2 => 'search_content',  // Gap in keys! ❌
    5 => 'save_post'        // Non-sequential ❌
];

// Serialized in database:
// a:3:{i:0;s:10:"web_search";i:2;s:14:"search_content";i:5;s:9:"save_post";}
//      ↑ Key 0       ↑ Key 2 (skipped 1!)       ↑ Key 5 (skipped 3, 4!)
```

### Solution: Sequential Keys

```php
// What is saved after the fix:
$saved_tools = [
    0 => 'web_search',
    1 => 'search_content',  // Sequential ✅
    2 => 'save_post'        // Sequential ✅
];

// Serialized in database:
// a:3:{i:0;s:10:"web_search";i:1;s:14:"search_content";i:2;s:9:"save_post";}
//      ↑ Key 0       ↑ Key 1 (sequential!)      ↑ Key 2 (sequential!)
```

## Testing Workflow

```
Developer                          System                    Database
    │                                │                          │
    │  1. Click "Reseed"            │                          │
    ├──────────────────────────────>│                          │
    │                                │  2. Load JSON           │
    │                                │  3. Process data        │
    │                                │  4. Save to DB          │
    │                                ├─────────────────────────>│
    │                                │                          │
    │                                │  ✅ Sequential array     │
    │                                │  ✅ No empty values      │
    │                                │                          │
    │  5. Navigate to edit page     │                          │
    ├──────────────────────────────>│                          │
    │                                │  6. Retrieve data       │
    │                                │<─────────────────────────┤
    │                                │                          │
    │                                │  ✅ Sanitize array       │
    │                                │  ✅ Sanitize tool slugs  │
    │                                │                          │
    │  7. See pre-checked boxes ✅  │                          │
    │<───────────────────────────────┤                          │
    │                                │                          │
```

## Prevention Checklist

When working with arrays in postmeta:

- [ ] Use `array_values()` before saving to ensure sequential keys
- [ ] Use `array_filter()` to remove empty values
- [ ] Sanitize consistently at both save and retrieve points
- [ ] Use strict comparison (`true`) in `in_array()` for reliability
- [ ] Test with non-sequential arrays to catch edge cases
- [ ] Document array structure expectations in comments

## Summary

| Aspect | Before | After |
|--------|--------|-------|
| **Array Keys** | Non-sequential (0, 2, 5) | Sequential (0, 1, 2) |
| **Empty Values** | Not filtered | Filtered out |
| **Sanitization** | Inconsistent | Consistent |
| **Comparison** | Unreliable | Reliable |
| **UI Result** | Checkboxes unchecked ❌ | Checkboxes checked ✅ |
| **Code Changes** | - | 5 lines (minimal!) |
| **Tests Added** | - | 3 methods + CLI script |
| **Documentation** | - | Full guide |
