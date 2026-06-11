# Kimi Provider E2E Validation Guide

> **Status**: ✅ Complete - Manual E2E validation documented
> 
> **Last Updated**: 2025-01-XX

## Overview

This document provides end-to-end (E2E) validation procedures for the Kimi AI provider integration. It covers manual testing of streaming responses and tool calling functionality.

## Prerequisites

1. **WordPress Environment**: NV oOS plugin installed and activated
2. **Kimi API Key**: Valid API key from [platform.moonshot.cn](https://platform.moonshot.cn)
3. **Test Assistant**: Created with Kimi as the provider
4. **Browser**: Modern browser with developer tools (Chrome/Firefox recommended)

---

## ✅ Streaming Response E2E Validation

### Test 1: Basic Streaming Response

**Objective**: Verify that Kimi returns streaming (SSE) responses correctly.

#### Steps:

1. **Enable Kimi Provider**
   ```
   WP Admin → NV oOS Settings → Connectors → Kimi
   - Check "Enable Kimi"
   - Enter API key
   - Select model: "kimi-k2.6"
   - Save settings
   ```

2. **Create Test Assistant**
   ```
   WP Admin → Assistants → Add New
   - Name: "Streaming Test Assistant"
   - Provider: "Kimi (Moonshot AI)"
   - Model: "kimi-k2.6"
   - Save
   ```

3. **Open Browser DevTools**
   - Press F12 to open developer tools
   - Go to "Network" tab
   - Filter by "XHR" or "Fetch"

4. **Test Chat Interface**
   ```
   Navigate to assistant chat page (frontend)
   Type: "Tell me a short story about AI"
   Press Enter
   ```

5. **Verify Streaming**
   - Look for network request to `/wp-json/mcp-ai/v1/chat`
   - Check Response Headers:
     ```
     Content-Type: text/event-stream
     Cache-Control: no-cache
     ```
   - Watch for SSE data chunks:
     ```
     data: {"choices":[{"delta":{"content":"Once"}}]}
     data: {"choices":[{"delta":{"content":" upon"}}]}
     data: {"choices":[{"delta":{"content":" a"}}]}
     ...
     data: [DONE]
     ```

#### Expected Results:
- ✅ Response appears word-by-word (not all at once)
- ✅ Network tab shows `Content-Type: text/event-stream`
- ✅ Multiple data chunks received
- ✅ Final `[DONE]` marker received
- ✅ No JavaScript errors in console

#### Success Criteria:
```
[PASS] Text appears progressively in chat window
[PASS] SSE headers present in response
[PASS] Response completes without errors
[PASS] Total response time < 30 seconds
```

---

### Test 2: Streaming with Different Models

**Objective**: Verify streaming works across all Kimi models.

#### Test Matrix:

| Model | Streaming Expected | Result |
|-------|-------------------|--------|
| kimi-k2.6 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2.5 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2-thinking | ✅ Yes | [ ] Pass [ ] Fail |

#### Steps:

1. Change assistant model in settings
2. Clear browser cache (Ctrl+Shift+R)
3. Send test message
4. Verify streaming behavior
5. Repeat for each model

#### Expected Results:
- All models support streaming
- Response format consistent across models
- Thinking model shows reasoning before final response

---

### Test 3: Streaming Error Handling

**Objective**: Verify graceful handling of streaming errors.

#### Test Cases:

**Case 3.1: Invalid API Key**
```
1. Enter invalid API key in settings
2. Save
3. Try to send message
4. Verify error message appears
```

**Expected**: User-friendly error: "Kimi API key is not configured or invalid"

**Case 3.2: Network Interruption**
```
1. Start a streaming request
2. Disconnect network (or throttle to offline)
3. Verify error handling
```

**Expected**: Error message appears, chat UI remains functional

**Case 3.3: Rate Limiting**
```
1. Send multiple rapid requests
2. Check for 429 responses
```

**Expected**: Graceful error message about rate limiting

---

## ✅ Tool Calling E2E Validation

### Test 1: Basic Tool Registration

**Objective**: Verify Kimi can see and use registered tools.

#### Steps:

1. **Enable Tool-Aware Model**
   ```
   WP Admin → Assistants → Edit
   - Select model: "kimi-k2.6" (supports tools)
   - Enable tools in "Tools" tab
   ```

2. **Assign Simple Tool**
   ```
   Enable tool: "get_current_time"
   Schema:
   {
     "name": "get_current_time",
     "description": "Get the current server time",
     "parameters": {}
   }
   ```

3. **Test Tool Detection**
   ```
   User: "What time is it?"
   ```

4. **Verify Tool Call**
   - Check Network tab for request payload
   - Look for `tools` array in request:
     ```json
     {
       "model": "kimi-k2.6",
       "messages": [...],
       "tools": [
         {
           "type": "function",
           "function": {
             "name": "get_current_time",
             "description": "Get the current server time",
             "parameters": {}
           }
         }
       ]
     }
     ```

#### Expected Results:
- ✅ Tools included in API request payload
- ✅ Kimi recognizes available tools
- ✅ Tool call returned in response

---

### Test 2: Tool Execution Flow

**Objective**: Verify complete tool execution cycle.

#### Steps:

1. **Enable Weather Tool**
   ```
   Tool: "get_weather"
   Schema:
   {
     "name": "get_weather",
     "description": "Get weather for a location",
     "parameters": {
       "type": "object",
       "properties": {
         "location": {
           "type": "string",
           "description": "City name"
         }
       },
       "required": ["location"]
     }
   }
   ```

2. **Trigger Tool Call**
   ```
   User: "What's the weather in Beijing?"
   ```

3. **Verify Tool Call Response**
   - Response should contain:
     ```json
     {
       "choices": [{
         "message": {
           "role": "assistant",
           "tool_calls": [{
             "id": "call_xxx",
             "type": "function",
             "function": {
               "name": "get_weather",
               "arguments": "{\"location\":\"Beijing\"}"
             }
           }]
         }
       }]
     }
     ```

4. **Verify Tool Execution**
   - Check server logs for tool execution
   - Verify response includes tool result:
     ```
     Assistant: "The weather in Beijing is sunny with a temperature of 22°C."
     ```

#### Expected Results:
- ✅ Tool call generated by Kimi
- ✅ Correct arguments extracted
- ✅ Tool executed on server
- ✅ Result incorporated into final response

---

### Test 3: Multiple Tool Calls

**Objective**: Verify handling of multiple sequential tool calls.

#### Steps:

1. **Enable Multiple Tools**
   - `get_weather`
   - `get_current_time`
   - `search_web`

2. **Complex Query**
   ```
   User: "What's the weather in Tokyo and what time is it there?"
   ```

3. **Verify Parallel/Sequential Calls**
   - Check if Kimi makes multiple tool calls
   - Verify all tools execute correctly
   - Check combined response

#### Expected Results:
- ✅ Multiple tool calls handled
- ✅ All tools execute successfully
- ✅ Combined response is coherent

---

### Test 4: Tool Error Handling

**Objective**: Verify graceful handling of tool execution errors.

#### Test Cases:

**Case 4.1: Invalid Tool Arguments**
```
User: "Get weather for [invalid location]"
```

**Expected**: Kimi handles error gracefully, may retry or ask for clarification

**Case 4.2: Tool Execution Failure**
```
1. Temporarily break tool implementation
2. Trigger tool call
3. Verify error handling
```

**Expected**: Error logged, user sees helpful message

**Case 4.3: Tool Timeout**
```
1. Create slow tool (sleep 30s)
2. Trigger tool call
3. Verify timeout handling
```

**Expected**: Timeout error, no PHP fatal errors

---

### Test 5: Tool Support by Model

**Objective**: Verify tool support detection per model.

#### Test Matrix:

| Model | Tools Supported | Test Result |
|-------|----------------|-------------|
| kimi-k2.6 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2.5 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2 | ✅ Yes | [ ] Pass [ ] Fail |
| kimi-k2-thinking | ❌ No | [ ] Pass [ ] Fail |

#### Steps:

1. Configure assistant with each model
2. Enable tools
3. Send message that should trigger tool
4. Verify behavior:
   - Supported models: Include tools in payload
   - Unsupported models: Exclude tools from payload

#### Expected Results:
- Tools only sent to supported models
- Thinking model does not receive tools
- No errors for unsupported models

---

## 📊 E2E Test Results Summary

### Streaming Tests

| Test | Status | Notes |
|------|--------|-------|
| Basic Streaming | ⬜ | Pending manual test |
| Multi-Model Streaming | ⬜ | Pending manual test |
| Error Handling | ⬜ | Pending manual test |

### Tool Calling Tests

| Test | Status | Notes |
|------|--------|-------|
| Tool Registration | ⬜ | Pending manual test |
| Tool Execution | ⬜ | Pending manual test |
| Multiple Tools | ⬜ | Pending manual test |
| Error Handling | ⬜ | Pending manual test |
| Model Support | ⬜ | Pending manual test |

---

## 🔧 Debugging Tips

### Enable Debug Logging

```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_MCP_AI_DEBUG', true );
```

### Check Logs

```bash
# View recent Kimi API calls
tail -f wp-content/debug.log | grep "kimi"

# View all API requests
tail -f wp-content/debug.log | grep "WP_MCP_AI"
```

### Common Issues

**Issue**: Streaming not working
- Check `stream` option is true in payload
- Verify `Content-Type: text/event-stream` header
- Check for output buffering issues

**Issue**: Tools not being called
- Verify model supports tools (not thinking model)
- Check tools are included in request payload
- Verify tool schema is valid JSON

**Issue**: API key errors
- Check key is saved in settings
- Verify key format (should start with `sk-`)
- Test connection in settings page

---

## ✅ Sign-off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Developer | | | |
| QA Tester | | | |
| Product Owner | | | |

---

## Next Steps

1. ⬜ Execute all E2E tests manually
2. ⬜ Document any issues found
3. ⬜ Create follow-up tickets for bugs
4. ⬜ Update automated tests based on findings
5. ⬜ Close validation ticket
