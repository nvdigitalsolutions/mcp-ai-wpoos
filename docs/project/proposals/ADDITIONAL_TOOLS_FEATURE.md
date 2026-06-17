# Additional Tools Feature

> **Status:** ✅ Implemented (v1.1.29) — Full implementation in class-wp-mcp-ai-shortcode.php. additional_tools attribute parsed, sanitized, merged for OpenAI and embedded paths. Used by 13+ Pro research pages.

## Overview

The `additional_tools` feature allows specific contexts (like research pages) to inject tools that are available regardless of the assistant's configured tool list. This ensures that context-specific tools are always available where needed.

## Problem Statement

Previously, if an assistant didn't have `research_quiz_topic` configured in its tool list, users couldn't use that tool on the Quiz Research page, even though the page is specifically designed for quiz research. This created a poor user experience where the research page wouldn't work properly.

## Solution

Implement a flexible system to inject additional tools at the context level (shortcode/page level) rather than requiring them to be in the assistant's configuration.

## Architecture

### Data Flow

```
Research Page (PHP)
    ↓ (shortcode attribute)
Shortcode Renderer
    ↓ (JavaScript config)
Frontend Chat Widget
    ↓ (HTTP request payload)
REST API Endpoint
    ↓ (merge with assistant tools)
Tool Execution System
```

### Components

#### 1. Shortcode Attribute (PHP)

**File**: `includes/class-wp-mcp-ai-shortcode.php`

The shortcode accepts an `additional_tools` attribute:

```php
[mcp_ai_chat assistant="123" additional_tools="research_quiz_topic,another_tool"]
```

**Processing**:
- Parse comma-separated tool slugs
- Sanitize each slug with `sanitize_key()`
- Pass to JavaScript config as `additionalTools` array

```php
if ( ! empty( $atts['additional_tools'] ) ) {
    $additional_tools_raw = sanitize_text_field( $atts['additional_tools'] );
    $additional_tools     = array_map( 'trim', explode( ',', $additional_tools_raw ) );
    $additional_tools     = array_filter( array_map( 'sanitize_key', $additional_tools ) );
    
    if ( ! empty( $additional_tools ) ) {
        $config['additionalTools'] = array_values( $additional_tools );
    }
}
```

#### 2. Research Page Implementation (PHP)

**File**: `addons/pro/includes/admin/class-wp-mcp-ai-quiz-research-page.php`

The quiz research page ensures `research_quiz_topic` is always available:

```php
echo do_shortcode(
    '[mcp_ai_chat assistant="' . absint( $assistant_id ) . 
    '" cpt_actions="' . esc_attr( base64_encode( wp_json_encode( $cpt_actions ) ) ) . 
    '" additional_tools="research_quiz_topic"]'
);
```

#### 3. Frontend Integration (JavaScript)

**File**: `assets/js/chat.js`

The chat widget includes additional tools in the request payload:

```javascript
// Include additional tools if provided (for context-specific tools like research pages).
if (state.config.additionalTools && Array.isArray(state.config.additionalTools)) {
    payload.additional_tools = state.config.additionalTools;
}
```

This is added to the payload sent to the REST API alongside messages, session_key, etc.

#### 4. Backend Processing (PHP)

**File**: `includes/class-wp-mcp-ai-rest.php`

The REST API merges additional tools into the assistant's configuration:

```php
// If additional_tools are provided (for context-specific tools like research pages), 
// merge them into the assistant's tools.
$additional_tools = $request->get_param( 'additional_tools' );
if ( ! empty( $additional_tools ) && is_array( $additional_tools ) ) {
    // Sanitize the additional tools array.
    $additional_tools = array_filter( array_map( 'sanitize_key', $additional_tools ) );
    
    if ( ! empty( $additional_tools ) ) {
        // Merge with existing tools, ensuring no duplicates.
        if ( ! isset( $assistant_config['tools'] ) || ! is_array( $assistant_config['tools'] ) ) {
            $assistant_config['tools'] = array();
        }
        $assistant_config['tools'] = array_values( 
            array_unique( 
                array_merge( $assistant_config['tools'], $additional_tools ) 
            ) 
        );
    }
}
```

**Key Points**:
- Sanitizes tool slugs to prevent injection
- Merges with existing tools (doesn't replace)
- Removes duplicates using `array_unique()`
- Reindexes array with `array_values()`

## Usage Examples

### Basic Usage

Ensure a single tool is available:

```php
echo do_shortcode('[mcp_ai_chat assistant="123" additional_tools="research_quiz_topic"]');
```

### Multiple Tools

Ensure multiple tools are available (comma-separated):

```php
echo do_shortcode('[mcp_ai_chat assistant="123" additional_tools="research_quiz_topic,web_search,analyze_content"]');
```

### Combined with CPT Actions

Use with CPT action buttons:

```php
$actions = array(
    array(
        'label'   => 'Add to Database',
        'action'  => 'create_quiz',
        'classes' => 'button button-primary',
        'icon'    => 'dashicons-database-add',
    ),
);

echo do_shortcode(
    '[mcp_ai_chat assistant="' . absint( $assistant_id ) . 
    '" cpt_actions="' . esc_attr( base64_encode( wp_json_encode( $actions ) ) ) . 
    '" additional_tools="research_quiz_topic"]'
);
```

## Security Considerations

### Tool Capability Checks

Additional tools are **not** a security bypass. Tools still enforce their capability requirements:

```php
class WP_MCP_AI_Tool_Research_Quiz_Topic {
    public function execute( array $arguments = array(), array $context = array() ) {
        $user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
        
        // Check permissions - requires read capability.
        if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
            return new WP_Error(
                'wp_mcp_ai_forbidden',
                __( 'You do not have permission to research quiz topics.', 'mcp-ai-wpoos-pro' )
            );
        }
        // ... rest of tool execution
    }
}
```

### Sanitization

All tool slugs are sanitized:
- Frontend: Tool slugs are predefined in config (no user input)
- Backend: `sanitize_key()` ensures only valid characters
- No SQL injection risk (tool slugs used for array lookups, not queries)

### Use Cases

Additional tools should be used for:
- ✅ Context-specific functionality (research pages)
- ✅ Specialized workflows (quiz creation, place research)
- ✅ Enhanced user experience in specific admin pages
- ❌ NOT for bypassing tool access restrictions
- ❌ NOT for exposing sensitive tools to untrusted users

## Benefits

### 1. Better User Experience
Users don't need to configure assistants with specific tools to use specialized pages.

### 2. Consistent Functionality
Research pages work consistently regardless of which assistant is selected.

### 3. Flexible Architecture
New research pages can easily inject their required tools.

### 4. Clean Separation of Concerns
- Assistant configuration: General-purpose tools
- Context configuration: Context-specific tools

### 5. Reusability
The same pattern can be used for:
- Place research pages → `research_place` tool
- ECA research pages → `research_eca` tool
- Policy research pages → `research_policy` tool
- Custom admin workflows → Any required tools

## Future Enhancements

### 1. Tool Groups

Define tool groups for common contexts:

```php
define( 'WP_MCP_AI_RESEARCH_TOOLS', 'research_quiz_topic,web_search,analyze_content' );

echo do_shortcode('[mcp_ai_chat assistant="123" additional_tools="' . WP_MCP_AI_RESEARCH_TOOLS . '"]');
```

### 2. Contextual Tool Restrictions

Allow specifying tools that should be REMOVED in certain contexts:

```php
echo do_shortcode('[mcp_ai_chat assistant="123" additional_tools="research_quiz_topic" disabled_tools="delete_quiz,update_quiz"]');
```

### 3. Tool Visibility Control

Hide certain tools from shortcuts while keeping them available:

```php
$config['additionalTools'] = array(
    array(
        'slug' => 'research_quiz_topic',
        'visible' => true,  // Show in shortcuts
    ),
    array(
        'slug' => 'create_quiz',
        'visible' => false, // Available but hidden
    ),
);
```

## Comparison with Alternatives

### Alternative 1: Modify Assistant Configuration

**Approach**: Require users to add tools to assistant config

**Problems**:
- ❌ Poor UX (users must configure assistants)
- ❌ Not scalable (many assistants to configure)
- ❌ Breaks when users switch assistants

### Alternative 2: Global Tool Injection

**Approach**: Make certain tools globally available

**Problems**:
- ❌ Tools available in wrong contexts
- ❌ Clutters tool list everywhere
- ❌ No context awareness

### Alternative 3: UI-Level Tool Execution

**Approach**: Execute tools from frontend without REST API

**Problems**:
- ❌ Bypasses security checks
- ❌ Duplicates tool execution logic
- ❌ Harder to maintain
- ❌ No audit trail

### Our Solution: Additional Tools ✅

**Advantages**:
- ✅ Context-specific availability
- ✅ Maintains security model
- ✅ Clean architecture
- ✅ Reusable pattern
- ✅ No special-case logic
- ✅ Works with existing tool system

## Testing

### Manual Test Cases

**Test 1: Tool Available on Research Page**
1. Create assistant WITHOUT `research_quiz_topic` in tools
2. Navigate to Quiz Research page
3. Ask "Research quiz about World War II"
4. **Expected**: Tool executes successfully, preview shows

**Test 2: Tool NOT Available on Regular Chat**
1. Use same assistant from Test 1
2. Navigate to regular chat page (not research page)
3. Ask "Research quiz about World War II"
4. **Expected**: Assistant responds without using tool (tool not available)

**Test 3: Multiple Additional Tools**
1. Create shortcode with `additional_tools="tool1,tool2,tool3"`
2. Send message
3. **Expected**: All three tools available alongside assistant's configured tools

**Test 4: Duplicate Prevention**
1. Create assistant WITH `research_quiz_topic` in tools
2. Navigate to Quiz Research page (also includes `research_quiz_topic`)
3. Check backend logs
4. **Expected**: Tool appears only once in final tool list

**Test 5: Security Check**
1. Create assistant with `additional_tools="sensitive_tool"`
2. Log in as user without required capability
3. Try to use the sensitive tool
4. **Expected**: Permission denied error (capability check still enforced)

### Automated Tests

```php
class Test_Additional_Tools extends WP_UnitTestCase {
    public function test_additional_tools_merged_with_assistant_tools() {
        $assistant_id = $this->create_assistant_with_tools( array( 'tool1', 'tool2' ) );
        
        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'additional_tools', array( 'tool3', 'tool4' ) );
        
        // Get processed config
        $config = $this->get_processed_config( $request );
        
        // Assert all tools present
        $this->assertContains( 'tool1', $config['tools'] );
        $this->assertContains( 'tool2', $config['tools'] );
        $this->assertContains( 'tool3', $config['tools'] );
        $this->assertContains( 'tool4', $config['tools'] );
    }
    
    public function test_no_duplicate_tools() {
        $assistant_id = $this->create_assistant_with_tools( array( 'tool1', 'tool2' ) );
        
        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_param( 'additional_tools', array( 'tool1', 'tool3' ) ); // tool1 is duplicate
        
        $config = $this->get_processed_config( $request );
        
        // Assert tool1 appears only once
        $tool1_count = count( array_keys( $config['tools'], 'tool1' ) );
        $this->assertEquals( 1, $tool1_count );
    }
}
```

## Troubleshooting

### Issue: Additional tool not executing

**Check**:
1. Tool slug is correct (check `get_slug()` method)
2. Tool is registered in tool registry
3. User has required capability
4. JavaScript console for payload inspection

**Debug**:
```javascript
// In browser console
console.log(window.wpMcpAiChatInstances);
// Check config.additionalTools array
```

### Issue: Tool appears multiple times

**Check**:
- Array deduplication in REST API
- Tool slug consistency (case-sensitive)

**Fix**: Already handled by `array_unique()` in backend

### Issue: Tools not available in REST API

**Check**:
- Payload includes `additional_tools` parameter
- Backend receives and processes parameter
- Network tab shows request payload

## Documentation

- Main feature doc: This file
- Testing guide: `tests/manual-testing-quiz-research.md`
- Architecture: `docs/architecture.md` (if exists)
- REST API: `docs/rest-api.md` (if exists)

## Related Features

- **CPT Actions**: Context-specific action buttons
- **Tool Shortcuts**: Predefined tool prompts
- **Professional Prompts**: Context-specific system prompts

All three work together to provide context-aware functionality while maintaining clean architecture.
