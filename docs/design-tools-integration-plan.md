# Design Tools Integration Plan

## Overview

This document outlines the systematic integration of all 9 design professional tools with the plugin's infrastructure: token manager, orchestration layer, error logging, agentic workflow support, AJAX handlers, and transient caching for multiple chat client compatibility.

## Integration Architecture

### Already Integrated (At Framework Level)

These integrations are automatically applied to ALL tools via the REST controller:

1. **Token Manager** - `WP_MCP_AI_Credentials` validates all tool requests
2. **Orchestration Layer** - `WP_MCP_AI_REST::handle_tool_request()` manages tool execution
3. **Base Error Logging** - `WP_MCP_AI_Logger::log_tool_execution()` logs all tool results
4. **Multiple Provider Support** - `WP_MCP_AI_Language_Model_Router` supports OpenAI/Gemini

### Tool-Specific Integrations Required

Each tool needs these enhancements:

1. **Internal Error Logging** - Log events at key points (start, validation errors, success)
2. **Transient Storage** - Cache job data for async operations
3. **AJAX Support** - Use centralized AJAX handler for downloads and status checks
4. **Progress Tracking** - Update job status for long-running operations

## Integration Status

### ✅ Completed Tools

#### 1. CAD Drawing Generator
- [x] Internal logging (start, errors, success)
- [x] Assistant ID tracking
- [x] AJAX download endpoint configured
- [x] Transient storage pattern ready

**Files Modified:**
- `includes/tools/class-wp-mcp-ai-tool-cad-drawing-generator.php`

**Logging Points:**
- `cad_tool_start` - Tool execution begins
- `cad_tool_success` - Drawing generated successfully
- Error logging for permission and validation failures

#### 2. AI Rendering Assistant
- [x] Internal logging (start, errors, success)
- [x] Assistant ID tracking
- [x] AJAX download endpoint configured
- [x] Transient storage pattern ready

**Files Modified:**
- `includes/tools/class-wp-mcp-ai-tool-ai-rendering-assistant.php`

**Logging Points:**
- `rendering_tool_start` - Tool execution begins
- `rendering_tool_success` - Rendering queued successfully
- Error logging for permission and attachment validation failures

### ⏳ In Progress

#### 3. 3D Model Generator
- [x] Basic logging structure added
- [ ] Complete error logging
- [ ] Transient integration
- [ ] AJAX endpoint integration

**Next Steps:**
1. Add logging at validation points
2. Store job data in transient after generation
3. Update download URLs to use AJAX endpoint

### 📋 Planned Updates

The following tools will be updated systematically with the same pattern:

#### 4. Logo Generator
**Priority:** High (user-facing, file generation)

**Required Changes:**
- Add `WP_MCP_AI_Logger` calls at execution start
- Add error logging for validation failures
- Store logo generation job in transient
- Update download URLs to AJAX endpoints
- Log successful generation

**Estimated Impact:** Medium

#### 5. Vector Design Assistant
**Priority:** High (user-facing, multiple operations)

**Required Changes:**
- Add logging for each operation type (create, modify, convert, optimize, extract)
- Add transient storage for operation results
- Update download URLs
- Track operation progress for complex conversions

**Estimated Impact:** Medium

#### 6. Brand Identity Generator
**Priority:** Medium (comprehensive output, less time-critical)

**Required Changes:**
- Add logging at generation start
- Store brand identity data in transient
- Add error logging for validation
- Log successful generation

**Estimated Impact:** Low (mostly synchronous)

#### 7. Icon Set Generator
**Priority:** Medium (set generation, multiple exports)

**Required Changes:**
- Add logging for icon set generation
- Store set data in transient
- Support multi-format downloads via AJAX
- Track generation progress for large sets

**Estimated Impact:** Medium

#### 8. Material & Color Recommendations
**Priority:** Low (synchronous, recommendation-based)

**Required Changes:**
- Add basic logging (start, success)
- Error logging for validation
- Optional transient caching for repeated requests

**Estimated Impact:** Low (mostly synchronous)

#### 9. Cost Estimation Tool
**Priority:** Low (synchronous, calculation-based)

**Required Changes:**
- Add logging for estimation requests
- Error logging for validation
- Optional transient caching for complex estimates

**Estimated Impact:** Low (mostly synchronous)

## Implementation Pattern

### Standard Integration Template

```php
/**
 * {@inheritdoc}
 */
public function execute( array $arguments = array(), array $context = array() ) {
    $user_id      = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
    $assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

    // 1. Log tool execution start
    WP_MCP_AI_Logger::log_event(
        'tool_name_start',
        'Tool execution started',
        array(
            'user_id'      => $user_id,
            'assistant_id' => $assistant_id,
            'key_params'   => $some_key_param,
        )
    );

    // 2. Permission checks with error logging
    if ( ! $user_id || ! user_can( $user_id, 'required_capability' ) ) {
        WP_MCP_AI_Logger::log_error( 'Permission denied', array( 'user_id' => $user_id ) );
        return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'wp-mcp-ai' ) );
    }

    // 3. Validation with error logging
    if ( $validation_fails ) {
        WP_MCP_AI_Logger::log_error( 
            'Validation failed', 
            array( 'details' => $validation_details )
        );
        return new WP_Error( 'wp_mcp_ai_invalid', __( 'Validation failed.', 'wp-mcp-ai' ) );
    }

    // 4. Generate job ID
    $job_id = wp_generate_uuid4();

    // 5. Process and generate result
    $result = array(
        'job_id'    => $job_id,
        'status'    => 'processing', // or 'completed' for synchronous
        'user_id'   => $user_id,
        // ... other result data
    );

    // 6. Store in transient for async operations
    if ( 'processing' === $result['status'] ) {
        WP_MCP_AI_Design_Tools_AJAX::store_job_data( $job_id, $result );
    }

    // 7. Log successful execution
    WP_MCP_AI_Logger::log_event(
        'tool_name_success',
        'Tool executed successfully',
        array(
            'job_id'  => $job_id,
            'user_id' => $user_id,
        )
    );

    // 8. Fire action hook
    do_action( 'wp_mcp_ai_tool_name_completed', $result, $arguments, $user_id );

    return $result;
}
```

## AJAX Integration

### Centralized AJAX Handler

All design tools use the centralized `WP_MCP_AI_Design_Tools_AJAX` class:

**Available Endpoints:**
- `wp_mcp_ai_check_design_job_status` - Check job status
- `wp_mcp_ai_download_cad` - CAD drawing downloads
- `wp_mcp_ai_download_rendering` - Rendering downloads
- `wp_mcp_ai_download_3d_model` - 3D model downloads
- `wp_mcp_ai_download_logo` - Logo downloads
- `wp_mcp_ai_download_vector` - Vector design downloads
- `wp_mcp_ai_download_icon_set` - Icon set downloads

**Authentication:**
- Logged-in users: Standard WordPress nonce
- External clients: Token-based authentication via `WP_MCP_AI_Credentials`

### Transient Storage Pattern

**Key Format:** `wp_mcp_ai_design_job_{job_id}`

**Expiration:** 24 hours (DAY_IN_SECONDS)

**Data Structure:**
```php
array(
    'job_id'       => 'uuid-string',
    'type'         => 'cad|rendering|3d_model|logo|vector|icon_set|...',
    'status'       => 'processing|completed|failed',
    'user_id'      => 123,
    'assistant_id' => 456,
    'created_at'   => '2025-11-24 12:00:00',
    'updated_at'   => '2025-11-24 12:05:00',
    // Tool-specific data...
)
```

## Chat Client Compatibility

### Supported Clients

1. **OpenAI Chat Completions API**
   - Standard REST endpoint integration
   - Tool calls via function calling
   - Streaming support (future)

2. **Google Gemini API**
   - Via `WP_MCP_AI_Gemini_Client`
   - Tool call translation
   - Response format normalization

3. **Custom Chat Clients**
   - Token-based authentication
   - Standard REST API access
   - AJAX polling for async operations

### Client-Specific Considerations

**OpenAI:**
- Tool definitions auto-generated from schema
- Native function calling support
- Async operations via polling

**Gemini:**
- Tool call format translation
- Response structure normalization
- Same async operation pattern

**External Clients:**
- Token authentication required
- AJAX endpoints for status checks
- Download links with embedded tokens

## Testing Checklist

### Per-Tool Testing

- [ ] Tool executes successfully via REST API
- [ ] Logging events appear in error log
- [ ] Permission checks work correctly
- [ ] Transient storage works for async operations
- [ ] AJAX status check returns correct data
- [ ] Download endpoint works (authenticated)
- [ ] Download endpoint works (token-based)
- [ ] Error conditions log properly
- [ ] Works with OpenAI chat client
- [ ] Works with Gemini chat client

### Integration Testing

- [ ] Multiple tools can run concurrently
- [ ] Transient data doesn't interfere between tools
- [ ] AJAX endpoints handle errors gracefully
- [ ] Token authentication works across all endpoints
- [ ] Logging doesn't impact performance
- [ ] Old transients are cleaned up properly

## Rollout Plan

### Phase 1: Critical Tools (Current)
- [x] CAD Drawing Generator
- [x] AI Rendering Assistant
- [x] AJAX Handler Infrastructure

### Phase 2: High-Priority Tools (Next)
- [ ] 3D Model Generator (complete integration)
- [ ] Logo Generator
- [ ] Vector Design Assistant

### Phase 3: Medium-Priority Tools
- [ ] Brand Identity Generator
- [ ] Icon Set Generator

### Phase 4: Low-Priority Tools
- [ ] Material & Color Recommendations
- [ ] Cost Estimation Tool

### Phase 5: Testing & Documentation
- [ ] Integration testing with both AI providers
- [ ] Performance testing with concurrent requests
- [ ] Update user documentation
- [ ] Add developer examples

## Success Metrics

- ✅ All 9 tools have logging integration
- ✅ All async operations use transient storage
- ✅ All download operations use AJAX endpoints
- ✅ Zero errors in WordPress error log during normal operation
- ✅ Response times under 2 seconds for sync operations
- ✅ Status checks work for async operations
- ✅ Compatible with both OpenAI and Gemini clients

## Notes

- Integration is backward compatible with existing REST API usage
- AJAX layer is optional - tools work fine without it via REST
- Transient storage is for convenience and doesn't affect core functionality
- All enhancements are additive, no breaking changes
