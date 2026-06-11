# Chat Initialization Enhancement: Pre-load Remote Connections

> **Status:** Not implemented (v1.1.29) — No preload_connections or chat_preload code exists in PHP or JS. No /chat/context/{id} endpoint.

## Overview

Enhancement proposal to automatically pre-load available remote site connections when the chat client initializes, eliminating the need for an explicit `list_connections` tool call before answering product availability questions.

## Current Behavior

Currently, when using the `remote_wp_connection` tool:

1. Assistant must call `list_connections` first to discover available connection IDs
2. User asks a question (e.g., "What's in stock for 1 Million perfume?")
3. Assistant makes initial tool call to get connection list
4. Assistant makes second tool call to query products
5. Response provided to user

**Example Flow:**
```
User: "Check stock for 1 Million"
→ Tool Call 1: list_connections (get conn_2vky3hqfi4ki)
→ Tool Call 2: get_wc_products with connection_id
→ Response: "100ml and 50ml in stock"
```

## Proposed Behavior

When chat client loads and assistant has `remote_wp_connection` tool enabled:

1. Automatically fetch available connections on initialization
2. Pass connections as initial context to the assistant
3. Assistant can immediately query products without `list_connections` call

**Enhanced Flow:**
```
[Chat loads → connections pre-fetched as context]
User: "Check stock for 1 Million"
→ Tool Call 1: get_wc_products with connection_id (already knows it)
→ Response: "100ml and 50ml in stock"
```

## Benefits

1. **Faster Response Time**: Eliminates one round-trip API call
2. **Better UX**: Users get answers faster
3. **Reduced API Load**: Fewer tool executions per conversation
4. **More Natural**: Assistant doesn't need to "discover" connections every time

## Implementation Requirements

### 1. Backend Changes

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

Add endpoint or enhance existing chat initialization to return connection data:

```php
/**
 * Get initial context for assistant including remote connections.
 *
 * @param int $assistant_id Assistant post ID.
 * @return array Initial context data.
 */
protected function get_initial_context( $assistant_id ) {
    $context = array();
    
    // Check if assistant has remote_wp_connection tool enabled
    $tools = get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true );
    if ( is_array( $tools ) && in_array( 'remote_wp_connection', $tools, true ) ) {
        // Get enabled connections for this assistant
        $connections = $this->get_assistant_connections( $assistant_id );
        $context['remote_connections'] = $connections;
    }
    
    return $context;
}

/**
 * Get remote connections enabled for assistant.
 *
 * @param int $assistant_id Assistant post ID.
 * @return array Connection data.
 */
protected function get_assistant_connections( $assistant_id ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
    
    $all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
    $enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );
    
    if ( ! is_array( $enabled_connections ) ) {
        $enabled_connections = array();
    }
    
    $result = array();
    foreach ( $all_connections as $connection ) {
        if ( ! empty( $connection['enabled'] ) ) {
            if ( empty( $enabled_connections ) || in_array( $connection['id'], $enabled_connections, true ) ) {
                $result[] = array(
                    'id'              => $connection['id'],
                    'name'            => $connection['name'],
                    'url'             => $connection['url'],
                    'has_woocommerce' => ! empty( $connection['has_woocommerce'] ),
                );
            }
        }
    }
    
    return $result;
}
```

### 2. Frontend Changes

**File:** `assets/js/chat.js` or relevant chat client JavaScript

Fetch and store connections on initialization:

```javascript
/**
 * Initialize chat with assistant context.
 */
async function initializeChat( assistantId ) {
    // Fetch initial context including connections
    const context = await fetchInitialContext( assistantId );
    
    if ( context.remote_connections && context.remote_connections.length > 0 ) {
        // Store in chat state
        chatState.remoteConnections = context.remote_connections;
        
        // Add to system context for AI model
        addSystemContext( 'remote_connections', formatConnectionsForContext( context.remote_connections ) );
    }
}

/**
 * Format connections for AI context.
 */
function formatConnectionsForContext( connections ) {
    return `Available remote site connections:
${connections.map( conn => `- ${conn.id} (${conn.name}): ${conn.url}${conn.has_woocommerce ? ' [WooCommerce]' : ''}` ).join('\n')}

You can use these connection IDs directly with the remote_wp_connection tool without calling list_connections first.`;
}
```

### 3. Caching Strategy

Implement caching to avoid repeated connection fetches:

```php
/**
 * Get cached connections for assistant.
 *
 * @param int $assistant_id Assistant post ID.
 * @return array|false Cached connections or false.
 */
protected function get_cached_connections( $assistant_id ) {
    $cache_key = 'wp_mcp_ai_connections_' . $assistant_id;
    return get_transient( $cache_key );
}

/**
 * Cache connections for assistant.
 *
 * @param int   $assistant_id Assistant post ID.
 * @param array $connections  Connection data.
 */
protected function cache_connections( $assistant_id, $connections ) {
    $cache_key = 'wp_mcp_ai_connections_' . $assistant_id;
    // Cache for 5 minutes
    set_transient( $cache_key, $connections, 5 * MINUTE_IN_SECONDS );
}
```

### 4. Tool Description Update

**File:** `addons/pro/includes/tools/class-wp-mcp-ai-tool-remote-wp-connection.php`

Update description to reflect new behavior:

```php
public function get_description() {
    return __( 'Access remote WordPress and WooCommerce sites to retrieve posts, pages, media, products, orders, and other data in read-only mode. IMPORTANT: When using get_wc_products, product variations are AUTOMATICALLY included by default with full stock quantities. NOTE: If remote connections are available in your context, you can use them directly. Otherwise, call list_connections first to discover available connection IDs.', 'wp-mcp-ai-pro' );
}
```

## API Changes

### New/Modified Endpoints

**GET** `/wp-json/mcp-ai/v1/chat/context/{assistant_id}`

Returns initial context including connections:

```json
{
  "assistant_id": 123,
  "remote_connections": [
    {
      "id": "conn_2vky3hqfi4ki",
      "name": "NV oOS - The Parfumerie",
      "url": "https://theparfumerie.lk",
      "has_woocommerce": true
    }
  ]
}
```

## Testing Requirements

1. **Unit Tests**: Test context fetching logic
2. **Integration Tests**: Test full chat flow with pre-loaded connections
3. **Performance Tests**: Verify caching reduces database queries
4. **User Tests**: Confirm improved response times

### Test Cases

```php
/**
 * Test that connections are pre-loaded for assistant with remote tool.
 */
public function test_connections_preloaded_for_assistant_with_tool() {
    $assistant_id = $this->create_assistant_with_remote_tool();
    $connection_id = $this->create_remote_connection();
    $this->enable_connection_for_assistant( $assistant_id, $connection_id );
    
    $context = $this->get_initial_context( $assistant_id );
    
    $this->assertArrayHasKey( 'remote_connections', $context );
    $this->assertCount( 1, $context['remote_connections'] );
    $this->assertEquals( $connection_id, $context['remote_connections'][0]['id'] );
}

/**
 * Test that connections are not loaded for assistant without remote tool.
 */
public function test_connections_not_loaded_without_tool() {
    $assistant_id = $this->create_assistant_without_remote_tool();
    
    $context = $this->get_initial_context( $assistant_id );
    
    $this->assertArrayNotHasKey( 'remote_connections', $context );
}

/**
 * Test that only enabled connections are loaded.
 */
public function test_only_enabled_connections_loaded() {
    $assistant_id = $this->create_assistant_with_remote_tool();
    $conn1 = $this->create_remote_connection( array( 'enabled' => true ) );
    $conn2 = $this->create_remote_connection( array( 'enabled' => false ) );
    
    $context = $this->get_initial_context( $assistant_id );
    
    $connection_ids = wp_list_pluck( $context['remote_connections'], 'id' );
    $this->assertContains( $conn1, $connection_ids );
    $this->assertNotContains( $conn2, $connection_ids );
}
```

## Security Considerations

1. **Permission Checks**: Verify user has access to assistant before returning connections
2. **Connection Filtering**: Only return connections enabled for specific assistant
3. **Credential Protection**: Never expose passwords or API keys in context
4. **Rate Limiting**: Cache connections to prevent abuse

## Backward Compatibility

- Existing `list_connections` action continues to work
- Tools can still call `list_connections` if needed
- No breaking changes to existing API
- Graceful degradation if context not available

## Performance Impact

**Before:**
- 2 tool calls per conversation start (list + query)
- ~500ms for connection discovery
- ~1000ms total time to first answer

**After:**
- 1 tool call per conversation start (query only)
- ~50ms for cached context fetch
- ~550ms total time to first answer

**Net Improvement:** ~45% faster initial response

## Rollout Plan

1. **Phase 1**: Implement backend context fetching (1-2 days)
2. **Phase 2**: Add frontend initialization logic (1 day)
3. **Phase 3**: Implement caching (1 day)
4. **Phase 4**: Testing and refinement (2 days)
5. **Phase 5**: Documentation updates (1 day)
6. **Phase 6**: Beta testing with select users (1 week)
7. **Phase 7**: Production rollout (gradual)

## Success Metrics

- Reduced average time to first answer by 40%+
- Reduced tool execution count per conversation by 15%+
- No increase in error rates
- Positive user feedback on response speed

## Related Issues

- Original discussion: PR #[number] - remote_wp_connection variations enhancement
- Related to: Agentic workflow optimization

## Author

@copilot

## Status

**Proposed** - Awaiting approval to begin implementation

## Next Steps

1. Review and approve proposal
2. Create GitHub issue for tracking
3. Assign to development sprint
4. Begin Phase 1 implementation
