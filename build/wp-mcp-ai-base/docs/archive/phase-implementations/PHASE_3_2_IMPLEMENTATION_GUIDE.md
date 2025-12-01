# Phase 3.2 Implementation Guide - Chat Controller Extraction

**Status**: Ready to Start 🚀  
**Timeline**: Week 7 (1 week)  
**Risk**: 🟡 Medium (well-mitigated)

---

## Overview

### What We're Doing
Extract chat-related endpoints from the 7,289-line monolithic REST controller into a focused `WP_MCP_AI_REST_Chat_Controller` class.

### Why This Matters
- Chat endpoints are the **most-used** in the entire plugin
- ~800 lines of chat logic currently mixed with other concerns
- Different client types (MCP, Browser, Guest) with different behaviors
- SSE streaming implementation is complex and critical
- Transcript storage has multiple backends (localStorage, CCT)

### What Makes This Safe
✅ Phase 3.1 base controller proven (11 tests passing)  
✅ Template Method pattern established  
✅ Multi-client auth already centralized  
✅ Incremental extraction (one endpoint at a time)  
✅ Comprehensive testing at each step  
✅ 100% backward compatibility maintained

---

## Endpoints to Extract

### 1. `/chat` - MCP Remote Client Chat
**Purpose**: Standards-compliant MCP chat for remote AI clients  
**Clients**: Claude Desktop, LM Studio, other MCP clients  
**Auth**: Bearer token  
**Iteration Limit**: 5 (MCP protocol compliance)  
**Response**: JSON-RPC 2.0 + optional SSE streaming  
**Complexity**: High (protocol compliance, streaming, tool calling)

### 2. `/chat-client` - Browser Client Chat
**Purpose**: WordPress browser-based chat interface  
**Clients**: Site visitors, logged-in users, guest tokens  
**Auth**: WordPress cookie OR guest token  
**Iteration Limit**: 15 (better UX for humans)  
**Response**: SSE streaming  
**Complexity**: Medium (multiple auth types, localStorage sync)

### 3. `/chat-transcripts` - List Transcripts
**Purpose**: Retrieve all chat transcripts for current user  
**Clients**: Browser clients only  
**Auth**: WordPress cookie  
**Response**: JSON array of transcript objects  
**Storage**: Browser localStorage (24h) + optional JetEngine CCT  
**Complexity**: Low (simple list retrieval)

### 4. `/chat-transcripts/{session_key}` - Individual Transcript
**Purpose**: GET (retrieve) or DELETE individual transcript  
**Clients**: Browser clients only  
**Auth**: WordPress cookie  
**Response**: JSON transcript object OR success message  
**Complexity**: Low (CRUD operations)

---

## Architecture

### Current State (Before Phase 3.2)
```
WP_MCP_AI_REST (7,289 lines)
├── handle_chat_request()                 (~200 lines)
├── handle_chat_client_request()          (~300 lines)
├── handle_list_transcripts_request()     (~100 lines)
├── handle_get_transcript_request()       (~100 lines)
├── handle_delete_transcript_request()    (~100 lines)
├── ... 50+ other methods
└── Supporting methods for chat           (~100 lines)
```

### Target State (After Phase 3.2)
```
WP_MCP_AI_REST (6,489 lines)
└── Delegates to Chat Controller

WP_MCP_AI_REST_Chat_Controller (800 lines)
├── Extends: WP_MCP_AI_REST_Controller_Base
├── register_routes()
│   ├── /chat
│   ├── /chat-client
│   ├── /chat-transcripts
│   └── /chat-transcripts/{session_key}
├── handle_chat_request()
├── handle_chat_client_request()
├── handle_list_transcripts_request()
├── handle_get_transcript_request()
├── handle_delete_transcript_request()
└── Supporting methods
```

---

## Step-by-Step Implementation

### Step 1: Create Chat Controller File (30 minutes)

**File**: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

```php
<?php
/**
 * Chat Controller for REST API
 *
 * Handles chat-related endpoints including MCP chat, browser chat,
 * and transcript management.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chat Controller Class
 *
 * Manages all chat-related REST API endpoints.
 */
class WP_MCP_AI_REST_Chat_Controller extends WP_MCP_AI_REST_Controller_Base {

    /**
     * Register chat routes.
     */
    public function register_routes() {
        // /chat - MCP remote client chat
        register_rest_route(
            'mcp-ai/v1',
            '/chat',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_chat_request' ),
                'permission_callback' => array( $this, 'permissions_check_chat' ),
            )
        );

        // /chat-client - Browser client chat
        register_rest_route(
            'mcp-ai/v1',
            '/chat-client',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_chat_client_request' ),
                'permission_callback' => array( $this, 'permissions_check_chat_client' ),
            )
        );

        // /chat-transcripts - List all transcripts
        register_rest_route(
            'mcp-ai/v1',
            '/chat-transcripts',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'handle_list_transcripts_request' ),
                'permission_callback' => array( $this, 'permissions_check_transcripts' ),
            )
        );

        // /chat-transcripts/{session_key} - Individual transcript operations
        register_rest_route(
            'mcp-ai/v1',
            '/chat-transcripts/(?P<session_key>[a-zA-Z0-9_-]+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'handle_get_transcript_request' ),
                    'permission_callback' => array( $this, 'permissions_check_transcripts' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'handle_delete_transcript_request' ),
                    'permission_callback' => array( $this, 'permissions_check_transcripts' ),
                ),
            )
        );
    }

    /**
     * Permission check for /chat endpoint.
     */
    public function permissions_check_chat( $request ) {
        // Use base controller's bearer token authentication
        return $this->check_bearer_authentication( $request );
    }

    /**
     * Permission check for /chat-client endpoint.
     */
    public function permissions_check_chat_client( $request ) {
        // Allow both WordPress users and guest tokens
        return $this->check_chat_client_authentication( $request );
    }

    /**
     * Permission check for transcript endpoints.
     */
    public function permissions_check_transcripts( $request ) {
        // WordPress users only (no guests for transcript management)
        return $this->check_wordpress_authentication( $request );
    }

    /**
     * Handle /chat request (MCP remote clients).
     */
    public function handle_chat_request( $request ) {
        // Extract from main REST controller
        // TODO: Implement in Step 2
    }

    /**
     * Handle /chat-client request (browser clients).
     */
    public function handle_chat_client_request( $request ) {
        // Extract from main REST controller
        // TODO: Implement in Step 2
    }

    /**
     * Handle list transcripts request.
     */
    public function handle_list_transcripts_request( $request ) {
        // Extract from main REST controller
        // TODO: Implement in Step 2
    }

    /**
     * Handle get individual transcript request.
     */
    public function handle_get_transcript_request( $request ) {
        // Extract from main REST controller
        // TODO: Implement in Step 2
    }

    /**
     * Handle delete transcript request.
     */
    public function handle_delete_transcript_request( $request ) {
        // Extract from main REST controller
        // TODO: Implement in Step 2
    }
}
```

**Checklist**:
- [ ] File created in correct location
- [ ] Extends base controller
- [ ] All 4 routes registered
- [ ] Permission callbacks defined
- [ ] Method stubs created
- [ ] PHPDoc comments added

---

### Step 2: Extract Endpoint Handlers (1-2 days)

For each endpoint, follow this pattern:

#### 2.1 Extract `/chat` Handler

**From**: `includes/class-wp-mcp-ai-rest.php` → `handle_chat_request()`  
**To**: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

**Key Considerations**:
- Iteration limit: 5 (MCP protocol compliance)
- SSE streaming support
- Tool calling with MCP protocol
- JSON-RPC 2.0 response format
- Bearer token authentication (already in base controller)

**Dependencies to Inject**:
- Chat Service (for message processing)
- Tool Manager (for tool execution)
- SSE Handler (for streaming)

```php
public function handle_chat_request( $request ) {
    // 1. Extract and validate parameters
    $params = $this->extract_chat_params( $request );
    
    // 2. Get auth context (from base controller)
    $auth_context = $this->get_auth_context();
    
    // 3. Initialize chat service
    $chat_service = $this->get_chat_service();
    
    // 4. Process with 5 iteration limit (MCP compliance)
    $response = $chat_service->process_chat(
        $params['messages'],
        $params['assistant_id'],
        array(
            'max_iterations' => 5,
            'stream'         => $params['stream'] ?? false,
            'tools'          => $params['tools'] ?? array(),
            'auth_context'   => $auth_context,
        )
    );
    
    // 5. Format response (MCP JSON-RPC 2.0)
    return $this->success( $response );
}
```

#### 2.2 Extract `/chat-client` Handler

**Key Differences from `/chat`**:
- Iteration limit: 15 (better UX for humans)
- Always uses SSE streaming
- Supports guest tokens
- Syncs with browser localStorage

#### 2.3 Extract `/chat-transcripts` Handler

**Simpler endpoint**:
- Retrieve from JetEngine CCT if available
- Fall back to returning empty array
- Browser clients only

#### 2.4 Extract Individual Transcript Handlers

**GET and DELETE operations**:
- Session key validation
- User ownership verification
- CCT storage operations

**Checklist**:
- [ ] All 5 handlers extracted
- [ ] Iteration limits correct (5 vs 15)
- [ ] SSE streaming preserved
- [ ] Auth context used
- [ ] Dependencies injected
- [ ] Error handling implemented

---

### Step 3: Update Main REST Controller (1 hour)

**File**: `includes/class-wp-mcp-ai-rest.php`

**Changes**:
1. Remove extracted methods
2. Add Chat Controller instantiation
3. Delegate route registration

```php
// In register_routes() method
$chat_controller = new WP_MCP_AI_REST_Chat_Controller();
$chat_controller->register_routes();
```

**Checklist**:
- [ ] Extracted methods removed
- [ ] Chat Controller instantiated
- [ ] Routes delegated
- [ ] File size reduced by ~800 lines
- [ ] No other functionality affected

---

### Step 4: Add Dependency Injection (1 hour)

**Update Container**: `includes/class-wp-mcp-ai-container.php`

```php
// Register Chat Controller
$this->singleton(
    'rest.chat_controller',
    function ( $container ) {
        return new WP_MCP_AI_REST_Chat_Controller(
            $container->get( 'service.chat' ),
            $container->get( 'service.tool_manager' ),
            $container->get( 'rest.sse_handler' )
        );
    }
);
```

**Update Chat Controller Constructor**:

```php
private $chat_service;
private $tool_manager;
private $sse_handler;

public function __construct(
    $chat_service = null,
    $tool_manager = null,
    $sse_handler = null
) {
    $this->chat_service = $chat_service ?? wp_mcp_ai_get_chat_service();
    $this->tool_manager = $tool_manager ?? wp_mcp_ai_get_tool_manager();
    $this->sse_handler  = $sse_handler ?? new WP_MCP_AI_SSE_Handler();
}
```

**Checklist**:
- [ ] Container registration added
- [ ] Constructor accepts dependencies
- [ ] Null coalescing for backward compatibility
- [ ] Services accessible in handlers

---

### Step 5: Write Comprehensive Tests (1-2 days)

**File**: `tests/test-rest-chat-controller.php`

**Test Categories** (minimum 15 tests):

#### 5.1 Controller Registration Tests
- [ ] Chat controller can be instantiated
- [ ] Chat controller extends base controller
- [ ] All 4 routes registered correctly

#### 5.2 Permission Tests
- [ ] `/chat` requires bearer token
- [ ] `/chat-client` allows WordPress users
- [ ] `/chat-client` allows guest tokens
- [ ] `/chat-transcripts` requires WordPress user
- [ ] Unauthorized requests rejected

#### 5.3 Iteration Limit Tests
- [ ] MCP chat uses 5 iterations
- [ ] Browser chat uses 15 iterations
- [ ] Iteration limit enforced correctly

#### 5.4 SSE Streaming Tests
- [ ] SSE streaming works for MCP chat
- [ ] SSE streaming works for browser chat
- [ ] Non-streaming works for MCP chat

#### 5.5 Transcript Tests
- [ ] List transcripts returns array
- [ ] Get transcript returns correct data
- [ ] Delete transcript removes data
- [ ] User can only access own transcripts

#### 5.6 Backward Compatibility Tests
- [ ] All endpoints work identically to before
- [ ] No breaking changes in responses
- [ ] Auth still works the same way

**Test Example**:

```php
public function test_mcp_chat_uses_5_iterations() {
    // Create test assistant
    $assistant_id = $this->create_test_assistant();
    
    // Create bearer token
    $token = $this->create_bearer_token( $assistant_id );
    
    // Mock chat service to count iterations
    $iterations_used = 0;
    $mock_service = $this->create_mock_chat_service( function( $config ) use ( &$iterations_used ) {
        $iterations_used = $config['max_iterations'];
        return array( 'response' => 'test' );
    } );
    
    // Inject mock service
    $controller = new WP_MCP_AI_REST_Chat_Controller( $mock_service );
    
    // Make request
    $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
    $request->set_header( 'Authorization', "Bearer $token" );
    $request->set_param( 'messages', array( array( 'role' => 'user', 'content' => 'test' ) ) );
    $request->set_param( 'assistant_id', $assistant_id );
    
    $response = $controller->handle_chat_request( $request );
    
    // Verify 5 iterations used (MCP compliance)
    $this->assertEquals( 5, $iterations_used );
}

public function test_browser_chat_uses_15_iterations() {
    // Similar test but for /chat-client endpoint
    // Should verify 15 iterations used
}
```

**Checklist**:
- [ ] All test categories covered
- [ ] Minimum 15 tests written
- [ ] All tests passing
- [ ] Mock services used for isolation
- [ ] Edge cases tested

---

### Step 6: Integration Testing (1 day)

**Manual Testing Checklist**:

#### 6.1 Test with Remote MCP Client
- [ ] Claude Desktop can chat
- [ ] 5 iteration limit enforced
- [ ] Tool calling works
- [ ] SSE streaming works
- [ ] Bearer token auth works

#### 6.2 Test with Browser Client
- [ ] WordPress user can chat
- [ ] Guest token works
- [ ] 15 iteration limit enforced
- [ ] SSE streaming works
- [ ] localStorage sync works

#### 6.3 Test Transcript Management
- [ ] Can list transcripts
- [ ] Can view individual transcript
- [ ] Can delete transcript
- [ ] Only own transcripts accessible

#### 6.4 Test Error Scenarios
- [ ] Invalid bearer token rejected
- [ ] Missing parameters handled
- [ ] Network errors handled
- [ ] Tool errors handled gracefully

---

## Success Criteria

### Code Quality
- [ ] PHP syntax check passes
- [ ] WordPress Coding Standards met
- [ ] PHPDoc comments complete
- [ ] No hard-coded dependencies
- [ ] Proper error handling

### Functionality
- [ ] All 4 endpoints work identically
- [ ] Iteration limits correct (5 vs 15)
- [ ] SSE streaming preserved
- [ ] Multi-client auth works
- [ ] Transcript storage works

### Testing
- [ ] Minimum 15 unit tests
- [ ] All tests passing
- [ ] Integration tests passed
- [ ] Backward compatibility verified

### Architecture
- [ ] Chat Controller properly extends base
- [ ] Dependency injection used
- [ ] Container registration correct
- [ ] Main REST controller reduced by ~800 lines

### Documentation
- [ ] PHPDoc comments complete
- [ ] Implementation guide updated
- [ ] Completion document created

---

## Risk Mitigation

### Risk: SSE Streaming Breaks
**Mitigation**:
- Use same SSE Handler from base controller
- Test streaming extensively
- Compare output byte-by-byte with original

### Risk: Iteration Limits Wrong
**Mitigation**:
- Add specific tests for iteration counts
- Verify with both client types
- Document why different limits used

### Risk: Auth Confusion
**Mitigation**:
- Use base controller auth methods
- Test all 3 auth types separately
- Clear comments on which endpoint uses which auth

### Risk: Breaking Changes
**Mitigation**:
- Comprehensive backward compatibility tests
- Side-by-side testing before/after
- Incremental rollout possible

---

## Timeline Breakdown

**Day 1**: Setup & Structure (4 hours)
- Create Chat Controller file
- Register routes
- Set up dependency injection

**Day 2**: Extract MCP Chat (4 hours)
- Extract `/chat` handler
- Preserve iteration limit
- Test with MCP client

**Day 3**: Extract Browser Chat (4 hours)
- Extract `/chat-client` handler
- Preserve SSE streaming
- Test with browser

**Day 4**: Extract Transcripts (4 hours)
- Extract transcript endpoints
- Test CRUD operations
- Verify storage works

**Day 5**: Testing (6 hours)
- Write unit tests
- Run full test suite
- Fix any issues

**Day 6**: Integration Testing (4 hours)
- Manual testing with real clients
- Performance testing
- Edge case testing

**Day 7**: Buffer & Documentation (2 hours)
- Address any remaining issues
- Update documentation
- Create completion document

**Total**: 28 hours over 7 days

---

## Next Steps After Phase 3.2

Once Phase 3.2 is complete:

1. **Create PHASE_3_2_COMPLETE.md** documenting:
   - What was achieved
   - Metrics (lines reduced, tests added)
   - Lessons learned
   - Next phase preparation

2. **Proceed to Phase 3.3**: MCP Protocol Controller
   - Extract `/mcp`, `/sse`, `/assistants`
   - ~600 lines
   - Similar pattern to Phase 3.2

3. **Continue Pattern**: Phases 3.4 and 3.5
   - Tools & Admin Controllers
   - Final cleanup
   - Main REST controller becomes router

---

## Questions & Answers

**Q: Why start with Chat Controller?**  
A: Chat endpoints are most-used, highest value, clear boundaries, and success here validates pattern for remaining controllers.

**Q: Can we extract multiple controllers at once?**  
A: No - incremental is safer. One controller at a time, fully tested before moving to next.

**Q: What if we find issues during extraction?**  
A: Stop, fix issues, re-test, then continue. Never proceed with known issues.

**Q: How do we ensure no breaking changes?**  
A: Comprehensive tests comparing before/after behavior, byte-by-byte response comparison for critical endpoints.

---

**Status**: Ready to Implement ✅  
**Risk**: 🟡 Medium (well-mitigated by base controller)  
**Timeline**: 1 week  
**Confidence**: High 💯

**Ready?** Create your branch and start with Step 1!
