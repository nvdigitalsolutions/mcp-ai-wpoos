# Veo Video Generation - Job ID Flow Documentation

## Overview

The `generate_veo_video` tool supports three execution contexts with different async handling strategies to optimize for performance, reliability, and conversation continuity.

## Execution Contexts

### 1. Direct Execution (Default)

**Scenario**: User/LLM directly calls the tool via REST API or chat interface

```
┌─────────────────┐
│   Client Call   │
│ generate_veo_   │
│     video       │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Tool: should_use_async()           │
│  ✓ No in_async_executor flag        │
│  ✓ No agentic_loop flag             │
│  → Returns TRUE (default async)     │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Gemini Service: generate_video()   │
│  • Submits to Veo API               │
│  • Creates job_id: veo_xxxxx        │
│  • Saves transient                  │
│  • Schedules cron poll              │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Return to Client                   │
│  {                                  │
│    "async": true,                   │
│    "job_id": "veo_xxxxx",          │
│    "status": "pending"              │
│  }                                  │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Client Polls                       │
│  GET /cron-status/veo_xxxxx         │
│  • Returns status: pending/polling  │
│  • Eventually: status: completed    │
└─────────────────────────────────────┘
```

**Job ID**: `veo_*` (Gemini service manages)  
**Async**: Yes (tool-level)  
**Polling**: Client polls `/cron-status/veo_*`

---

### 2. Orchestrator Async Execution

**Scenario**: Orchestrator detects tool has timeout risk and queues for async execution

```
┌─────────────────┐
│   Client Call   │
│ generate_veo_   │
│     video       │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  REST: execute_tool_call_internal() │
│  • Orchestrator checks flags        │
│  • has_timeout_risk() → TRUE        │
│  • should_execute_async() → TRUE    │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Async Executor: queue_tool()       │
│  • Creates job_id: async_xxxxx      │
│  • Saves metadata transient         │
│  • Schedules cron execution         │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Return to Client                   │
│  {                                  │
│    "async": true,                   │
│    "job_id": "async_xxxxx",        │
│    "status": "pending"              │
│  }                                  │
└─────────────────────────────────────┘
         │
         ├─ Client polls GET /cron-status/async_xxxxx
         │
         └─▶ Cron executes async_xxxxx
             │
             ▼
        ┌─────────────────────────────────────┐
        │  Async Executor: execute_async_tool()│
        │  • Sets context: in_async_executor=1 │
        │  • Calls tool->execute()             │
        └────────┬────────────────────────────┘
                 │
                 ▼
        ┌─────────────────────────────────────┐
        │  Tool: should_use_async()            │
        │  ✓ in_async_executor = true          │
        │  → Returns FALSE (prevent double!)   │
        └────────┬────────────────────────────┘
                 │
                 ▼
        ┌─────────────────────────────────────┐
        │  Gemini Service: generate_video()    │
        │  • async = false (sync mode)         │
        │  • Polls Veo API internally          │
        │  • Returns completed video           │
        └────────┬────────────────────────────┘
                 │
                 ▼
        ┌─────────────────────────────────────┐
        │  Async Executor stores result        │
        │  • Updates async_xxxxx metadata      │
        │  • status: completed                 │
        │  • result: {video data}              │
        └─────────────────────────────────────┘
```

**Job ID**: `async_*` (Orchestrator manages)  
**Async**: Yes (orchestrator-level)  
**Double-Async Prevention**: ✅ `in_async_executor` flag  
**Polling**: Client polls `/cron-status/async_*`

---

### 3. Agentic Loop Execution

**Scenario**: AI is in multi-turn conversation loop and needs actual results to continue

```
┌─────────────────┐
│  Agentic Loop   │
│  LLM requests   │
│  tool execution │
└────────┬────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  REST: execute_tool_call_internal() │
│  • context: agentic_loop = true     │
│  • Orchestrator: should_async?      │
│  • FORCED TO SYNC (line 7283)       │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Tool->execute() called directly    │
│  context = {                         │
│    agentic_loop: true,              │
│    iteration: 0,                    │
│    user_id: X                       │
│  }                                  │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Tool: should_use_async()            │
│  ✓ agentic_loop = true              │
│  → Returns FALSE (prevent nested!)   │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Gemini Service: generate_video()    │
│  • async = false (sync mode)         │
│  • Submits to Veo API               │
│  • Polls internally for completion   │
│  • (May take 60-120 seconds)        │
│  • Returns completed video           │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  Return to Agentic Loop              │
│  {                                  │
│    "success": true,                 │
│    "attachment_id": 123,            │
│    "url": "https://...",            │
│    "message": "Video generated"     │
│  }                                  │
└────────┬────────────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│  LLM receives ACTUAL video data     │
│  Can reference it in response:      │
│  "I've created your anime video...  │
│   You can view it here: [link]"     │
└─────────────────────────────────────┘
```

**Job ID**: N/A (No async job created)  
**Async**: No (forced synchronous)  
**Loop Continuity**: ✅ LLM gets actual results  
**Timeout Handling**: Extended PHP execution limit (60s default)

---

## Context Flags Priority

The `should_use_async()` method checks flags in this order:

1. **`in_async_executor`** (Highest Priority)
   - Prevents double-async execution
   - Set by: `WP_MCP_AI_Tool_Async_Executor`
   - Effect: Forces sync execution

2. **`agentic_loop`** (Equal Priority)
   - Ensures conversation continuity
   - Set by: REST controller in agentic loop
   - Effect: Forces sync execution

3. **`arguments['async']`** (User Override)
   - Explicit user/LLM parameter
   - Can request sync: `async: false`
   - Can request async: `async: true`

4. **Default Behavior** (Lowest Priority)
   - Returns `true` (async by default)
   - Optimizes for reliability

### Code Reference

```php
protected function should_use_async( $arguments, $context = array() ) {
    // Priority 1 & 2: Prevent double-async
    if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
        return false;
    }
    
    if ( isset( $context['agentic_loop'] ) && $context['agentic_loop'] ) {
        return false;
    }
    
    // Priority 3: User override
    if ( isset( $arguments['async'] ) ) {
        return (bool) $arguments['async'];
    }
    
    // Priority 4: Default to async
    return true;
}
```

---

## Job ID Prefixes

| Prefix | Created By | Managed By | Transient Key | Endpoint |
|--------|-----------|------------|---------------|----------|
| `veo_*` | Gemini Video Service | Gemini Service | `wp_mcp_ai_veo_async_*` | `/cron-status/veo_*` |
| `async_*` | Async Tool Executor | Orchestrator | `wp_mcp_ai_async_meta_*` | `/cron-status/async_*` |

---

## Error Scenarios

### Scenario 1: HTTP 500 on Job Status Poll

**Before Fix**:
```
Client → Poll /cron-status/veo_xxx
      ← HTTP 500 (job not saved yet - race condition)
      ← JavaScript shows: "generate_veo_video failed: HTTP 500"
```

**After Fix**:
1. Transient saved BEFORE cron schedule (race condition mitigated)
2. Better error messages: Extract actual error from JSON
3. 404 handled gracefully (job not ready yet)

### Scenario 2: Nested Async in Agentic Loop

**Before Fix**:
```
Agentic Loop → Execute tool (forced sync by orchestrator)
              → Tool still returns async job_id
              → LLM gets: {"async": true, "job_id": "veo_xxx"}
              → Cannot continue conversation ❌
```

**After Fix**:
```
Agentic Loop → Execute tool (forced sync by orchestrator)
              → Tool detects agentic_loop flag
              → Runs synchronously, polls internally
              → LLM gets: {"success": true, "url": "...", "attachment_id": 123}
              → Continues conversation with actual video ✅
```

---

## Testing

Run tests with:
```bash
vendor/bin/phpunit tests/test-veo-double-async-fix.php
```

Tests validate:
- ✅ `in_async_executor` prevents async
- ✅ `agentic_loop` prevents async  
- ✅ Both flags work independently and together
- ✅ Explicit `async` parameter still works
- ✅ Default behavior unchanged (async by default)

---

## Related Files

- **Tool**: `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`
- **Service**: `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`
- **Orchestrator**: `includes/services/class-wp-mcp-ai-async-tool-orchestrator.php`
- **Executor**: `includes/services/class-wp-mcp-ai-tool-async-executor.php`
- **REST**: `includes/class-wp-mcp-ai-rest.php` (execute_tool_call_internal)
- **Status**: `includes/services/class-wp-mcp-ai-cron-status-service.php`
- **Tests**: `tests/test-veo-double-async-fix.php`
- **JS Client**: `assets/js/chat.js` (fetchAsyncToolResult)

---

## Summary

The job ID flow is **correct and validated** for all three execution contexts:

1. **Direct Execution**: Creates `veo_*` job, client polls → ✅ Works
2. **Orchestrator Async**: Creates `async_*` job, prevents double-async → ✅ Works  
3. **Agentic Loop**: Forces sync, returns actual results → ✅ Fixed in this PR

The system correctly handles:
- ✅ Different async mechanisms (tool-level vs orchestrator-level)
- ✅ Double-async prevention via context flags
- ✅ Conversation continuity in agentic loops
- ✅ User override via explicit `async` parameter
- ✅ Error handling and messaging
