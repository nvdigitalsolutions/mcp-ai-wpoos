# WebLLM Tool Calling Guide
## Using WordPress Tools with Browser-Based AI

**Feature Status:** Phase 1 (Experimental)  
**Added:** January 2026  
**Requirements:** Chrome 113+, Safari 18+, or Edge 113+

---

## What is Tool Calling?

Tool calling allows the embedded WebLLM model running in your browser to execute WordPress tools directly. This means the AI can:

- ✅ Create posts and pages
- ✅ Search content
- ✅ Get current time/date
- ✅ Fetch data from your site
- ✅ Execute any of the 398+ available WordPress tools

**All processing happens in your browser** - private, fast, and free.

---

## How to Enable

### Step 1: Enable Embedded Provider
1. Go to **Settings → NV oOS → Providers**
2. Enable **"Client-side embedded language models (Pro)"**
3. Select a model (recommended: **Llama 3.2 1B Instruct**)
4. Save settings

### Step 2: Enable Tool Calling (Experimental)
1. In the same settings page, scroll to **Advanced Features**
2. Check **"Enable Tool Calling (Experimental)"**
3. Save settings

### Step 3: Configure Assistant
1. Go to **Assistants → Edit your assistant**
2. Set **Provider** to **"Embedded"**
3. Set **Model** to **"Llama-3.2-1B-Instruct-q4f16_1-MLC"** (or similar)
4. In **Tools** section, select tools you want the AI to use
5. Update assistant

---

## How It Works

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│ USER'S BROWSER                                          │
│                                                         │
│  ┌──────────────┐                                      │
│  │ WebLLM Model │  Runs AI model                       │
│  │ (Llama 3.2)  │  Decides which tool to call          │
│  └──────┬───────┘                                      │
│         │                                               │
│         ▼                                               │
│  ┌──────────────┐                                      │
│  │ Tool Adapter │  Converts tool calls                 │
│  │              │  to WordPress format                 │
│  └──────┬───────┘                                      │
└─────────┼───────────────────────────────────────────────┘
          │
          ▼ (REST API call)
┌─────────────────────────────────────────────────────────┐
│ WORDPRESS SERVER                                        │
│                                                         │
│  ┌──────────────┐                                      │
│  │ Tool         │  Executes tool                       │
│  │ Orchestrator │  (create post, search, etc.)         │
│  └──────┬───────┘                                      │
│         │                                               │
│         ▼                                               │
│  ┌──────────────┐                                      │
│  │ Result       │  Returns result to browser           │
│  └──────────────┘                                      │
└─────────────────────────────────────────────────────────┘
```

### Flow

1. **User sends message** to embedded AI
2. **WebLLM analyzes** and decides if it needs a tool
3. **Tool call generated** with name and arguments
4. **Browser sends** tool execution request to WordPress
5. **WordPress executes** tool (with permission checks)
6. **Result sent back** to browser
7. **WebLLM uses result** to generate final response

---

## Example Usage

### Example 1: Get Current Time

**User:** "What time is it?"

**Behind the scenes:**
```javascript
// WebLLM decides to call tool
{
  "tool": "get_current_time",
  "arguments": {}
}

// WordPress executes and returns
{
  "success": true,
  "time": "2026-01-24 15:30:00",
  "timezone": "America/New_York"
}

// WebLLM responds
"It is currently 3:30 PM EST on January 24, 2026."
```

### Example 2: Create a Post

**User:** "Create a post titled 'Hello World' with content 'This is my first post'"

**Behind the scenes:**
```javascript
// WebLLM generates tool call
{
  "tool": "create_post",
  "arguments": {
    "title": "Hello World",
    "content": "This is my first post",
    "status": "draft"
  }
}

// WordPress creates post
{
  "success": true,
  "post_id": 123,
  "url": "https://yoursite.com/?p=123"
}

// WebLLM responds
"I've created a draft post titled 'Hello World' for you. You can view it at [link]."
```

---

## Debugging

### Check Tool Calling is Working

1. **Open browser console** (F12 → Console tab)
2. **Send a message** that should trigger a tool (e.g., "What time is it?")
3. **Look for these logs:**

```
[NV oOS WebLLM] Loading available tools...
[NV oOS WebLLM] Loaded 35 tools
[NV oOS WebLLM] Starting chat with 35 tools
[NV oOS Embedded Client] Tools enabled for request: {toolCount: 35, ...}
```

### If Tool Calling Isn't Working

**Check 1: Feature Flag**
```
Settings → NV oOS → Advanced Features
☑ Enable Tool Calling (Experimental)
```

**Check 2: Model Supports Tool Calling**
Only these models support tool calling:
- ✅ Llama-3.2-1B-Instruct-q4f16_1-MLC
- ✅ Llama-3.2-3B-Instruct-q4f16_1-MLC
- ✅ Qwen2.5-1.5B-Instruct-q4f16_1-MLC
- ❌ Phi-3.5-mini (limited support)

**Check 3: Tools Assigned to Assistant**
```
Assistants → Edit → Tools section
Select at least one tool
```

**Check 4: Browser Console Errors**
Look for:
```
[NV oOS WebLLM] Tool execution error: ...
[NV oOS] Failed to fetch tools: ...
```

---

## Limitations

### Current Limitations (Phase 1)

1. **Model Size** - Smaller models (1B-3B) have limited tool calling accuracy
2. **Complex Tools** - Tools with many parameters may confuse small models
3. **Streaming** - Tool calls may interrupt response streaming
4. **Error Handling** - Limited retry logic for failed tool calls

### Recommended Use Cases

✅ **Good for:**
- Simple, single-parameter tools
- Well-defined tasks (get time, search posts)
- Testing and development

⚠️ **Not recommended for:**
- Critical operations (use server-side providers)
- Complex multi-step workflows (coming in Phase 3)
- Production environments (still experimental)

---

## Performance

### Model Load Time
- **First time:** 1-2 minutes (downloads ~800MB model)
- **Subsequent:** < 1 second (from browser cache)

### Tool Execution Speed
- **Browser → WordPress:** 50-200ms
- **Tool execution:** 100ms - 5 seconds (depends on tool)
- **Total overhead:** ~150-500ms per tool call

### Token Usage
Tool calling adds ~50-100 tokens per request:
- Tool definitions: 30-50 tokens each
- Tool call generation: 20-50 tokens
- Tool result processing: 50-200 tokens

---

## Troubleshooting

### "Tool adapter not loaded" Error

**Cause:** Scripts not enqueued properly  
**Fix:**
1. Check Settings → NV oOS → Advanced Features
2. Ensure "Enable Tool Calling" is checked
3. Save settings
4. Clear browser cache
5. Reload page

### "No tools available" Warning

**Cause:** No tools assigned to assistant  
**Fix:**
1. Edit assistant
2. Go to **Tools** section
3. Select at least one tool
4. Update assistant

### Tool Calls Not Generated

**Cause 1:** Model doesn't support tool calling  
**Fix:** Use Llama 3.2 or Qwen 2.5 models

**Cause 2:** Prompt doesn't trigger tool use  
**Fix:** Be more explicit: "Use the get_current_time tool to tell me the time"

**Cause 3:** System prompt overrides  
**Fix:** Check assistant system prompt doesn't disable tools

### Tool Execution Fails

**Check permissions:**
- User must have capability to use tool
- Guest tokens have limited tool access

**Check tool availability:**
- Some tools require plugins (JetEngine, WooCommerce)
- Pro tools require Pro addon

---

## Advanced Configuration

### Feature Flags (wp-config.php)

```php
// Force enable tool calling (bypass settings check)
define( 'WP_MCP_AI_FORCE_WEBLLM_TOOLS', true );

// Disable tool calling globally
define( 'WP_MCP_AI_DISABLE_WEBLLM_TOOLS', true );

// Set tool call timeout (milliseconds)
define( 'WP_MCP_AI_TOOL_CALL_TIMEOUT', 10000 ); // 10 seconds
```

### Filter: Modify Available Tools

```php
add_filter( 'wp_mcp_ai_webllm_tools', function( $tools, $assistant_id ) {
    // Remove sensitive tools from embedded provider
    $excluded = array( 'delete_post', 'update_user', 'execute_code' );
    
    return array_filter( $tools, function( $tool ) use ( $excluded ) {
        return ! in_array( $tool['slug'], $excluded, true );
    } );
}, 10, 2 );
```

### Action: Log Tool Calls

```php
add_action( 'wp_mcp_ai_webllm_tool_called', function( $tool_name, $args, $result ) {
    error_log( sprintf(
        '[WebLLM Tool Call] %s with args: %s resulted in: %s',
        $tool_name,
        wp_json_encode( $args ),
        wp_json_encode( $result )
    ) );
}, 10, 3 );
```

---

## What's Next?

### Phase 2: Multi-Modal Support (Coming Soon)
- Vision models (LLaVA, Qwen2-VL)
- Image analysis tools
- Visual question answering

### Phase 3: LangChain.js Integration (Coming Later)
- Multi-step workflows
- Agent-based reasoning
- Memory and context management

### Phase 4: Web Workers (Coming Later)
- Non-blocking tool execution
- Parallel tool calls
- Better mobile performance

---

## Support

### Getting Help

1. **Check console logs** first (F12 → Console)
2. **Review this guide** for common issues
3. **Check documentation:** `docs/features/ai-providers/embedded/`
4. **Report bugs:** GitHub Issues

### Known Issues

- [ ] Tool calling may fail on slow connections
- [ ] Large tool results may cause memory issues
- [ ] Some tools not compatible with embedded provider

### Feedback

Help us improve! Report:
- Tool calling accuracy issues
- Performance problems
- Feature requests
- Documentation gaps

---

**Last Updated:** January 24, 2026  
**Version:** 1.2.0 (Phase 1)  
**Status:** Experimental - Use in development/testing only
