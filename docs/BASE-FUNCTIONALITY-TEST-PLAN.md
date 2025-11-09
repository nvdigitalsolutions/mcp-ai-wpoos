# Base Functionality Test Plan

## Overview

This document provides a comprehensive testing plan for the base functionality of WP Open Operator System (WP oOS). The base version includes all core features that work without third-party plugin dependencies.

**Version**: 1.0.0  
**Last Updated**: November 9, 2024  
**Test Environment**: Base version mode (`WP_MCP_AI_BASE_VERSION = true`)

## Prerequisites

### Test Environment Setup

1. **WordPress Installation**
   - WordPress 6.0 or higher
   - PHP 7.4 or higher
   - No third-party plugins required (JetEngine, WooCommerce, Elementor are optional)

2. **Plugin Configuration**
   - Enable base version mode: Add to `wp-config.php`:
     ```php
     define( 'WP_MCP_AI_BASE_VERSION', true );
     ```
   - Configure OpenAI API key in plugin settings
   - Enable logging for debugging: Settings → WP oOS → Enable Logging

3. **Test Data**
   - At least one published post
   - At least one user account (editor or administrator)
   - Test assistant created with default settings

## Automated Test Suite

### Running the Comprehensive Base Functionality Tests

```bash
# Run the comprehensive base functionality test suite
vendor/bin/phpunit tests/test-base-functionality-comprehensive.php

# Expected output: 40 tests, all passing
```

### Test Coverage

The automated test suite validates:
- ✅ Plugin initialization and constants
- ✅ Base version detection
- ✅ Assistant CPT registration
- ✅ Tool registry functionality
- ✅ Base tools availability (35+ tools)
- ✅ REST endpoint registration
- ✅ Core classes loaded
- ✅ Integration classes excluded in base mode
- ✅ Security and monitoring components

## Manual Test Scenarios

### 1. Plugin Activation and Initialization

**Objective**: Verify plugin activates correctly and initializes all core components.

**Steps**:
1. Navigate to Plugins → Installed Plugins
2. Activate "WP Open Operator System"
3. Verify no PHP errors or warnings appear
4. Check Settings → WP oOS is available in admin menu
5. Verify plugin version displays correctly (1.0.0)

**Expected Results**:
- ✅ Plugin activates without errors
- ✅ Admin menu item appears
- ✅ Settings page loads successfully
- ✅ No JavaScript console errors

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 2. Assistant Creation and Management

**Objective**: Verify assistant CPT functionality.

**Steps**:
1. Navigate to AI Assistants → Add New
2. Enter assistant title: "Base Test Assistant"
3. Add system prompt: "You are a helpful WordPress assistant."
4. Select model: "gpt-4o-mini"
5. Enable at least 3 base tools (e.g., get_recent_posts, search_content, get_user_info)
6. Publish the assistant
7. Edit the assistant and verify all settings persist
8. Create a second assistant with different settings

**Expected Results**:
- ✅ Assistant can be created successfully
- ✅ All settings save correctly
- ✅ Meta boxes display properly
- ✅ Tool selection UI works
- ✅ Published assistants appear in list view
- ✅ Multiple assistants can coexist

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 3. Tool Registry and Base Tools

**Objective**: Verify all base tools are registered and accessible.

**Steps**:
1. Navigate to Settings → WP oOS → Dashboard
2. Scroll to "Available Tools" section
3. Verify the tool count shows 35+ tools
4. Confirm presence of core base tools:
   - get_recent_posts
   - search_content
   - get_user_info
   - get_site_summary
   - create_post
   - update_post
   - get_post
   - delete_post
   - web_search
   - count_tokens
5. Verify extended tools are NOT shown:
   - get_jetengine_items (requires JetEngine)
   - get_woo_products (requires WooCommerce)
   - create_elementor_template (requires Elementor)

**Expected Results**:
- ✅ Tool count is accurate (35+ in base mode)
- ✅ All core base tools are listed
- ✅ Third-party plugin tools are absent
- ✅ Each tool shows name and description

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 4. REST API Endpoints

**Objective**: Verify REST API endpoints are accessible and functional.

**Steps**:
1. Use a REST client (Postman, cURL, browser) to test:

   **List Assistants**:
   ```bash
   GET /wp-json/mcp-ai/v1/assistants
   Header: X-WP-Nonce: [nonce from logged-in session]
   ```

   **List Tools**:
   ```bash
   GET /wp-json/mcp-ai/v1/tools
   Header: X-WP-Nonce: [nonce]
   ```

   **Chat Endpoint** (simple test):
   ```bash
   POST /wp-json/mcp-ai/v1/chat
   Header: X-WP-Nonce: [nonce]
   Body: {
     "assistant_id": 123,
     "messages": [
       {"role": "user", "content": "Hello"}
     ]
   }
   ```

2. Verify response codes and data structure

**Expected Results**:
- ✅ `/assistants` returns 200 with array of assistants
- ✅ `/tools` returns 200 with array of tool definitions
- ✅ `/chat` returns 200 with streaming response
- ✅ Unauthorized requests return 401
- ✅ Invalid assistant_id returns 404
- ✅ All responses are properly formatted JSON

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 5. Authentication Mechanisms

**Objective**: Test all authentication methods work correctly.

**Steps**:
1. **WordPress Nonce Authentication**:
   - Make a REST API call with valid nonce
   - Make a REST API call with invalid nonce
   - Verify appropriate responses

2. **Assistant Credentials**:
   - Navigate to assistant editor
   - Generate new API credentials
   - Copy bearer token
   - Test REST API with: `Authorization: Bearer [token]`
   - Regenerate credentials and verify old token is invalidated

3. **Guest Token** (if enabled):
   - Add shortcode with `allow_guests="true"`
   - Verify guest token is generated
   - Test chat functionality while logged out

**Expected Results**:
- ✅ Valid nonce grants access
- ✅ Invalid nonce returns 401
- ✅ Bearer token authentication works
- ✅ Credentials can be regenerated
- ✅ Old credentials are invalidated on regeneration
- ✅ Guest tokens work when enabled
- ✅ Capability checks are enforced

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 6. Chat Shortcode Functionality

**Objective**: Verify frontend chat interface works via shortcode.

**Steps**:
1. Create a new page or post
2. Add shortcode: `[wp_mcp_ai_chat assistant_id="123"]`
3. Publish and view the page
4. Test chat functionality:
   - Enter a simple message: "Hello"
   - Verify assistant responds
   - Enter a message that triggers a tool: "Show me recent posts"
   - Verify tool is executed and results appear
   - Upload an image (if supported by model)
   - Test streaming responses

5. Test shortcode variations:
   ```
   [wp_mcp_ai_chat assistant_id="123" width="600px"]
   [wp_mcp_ai_chat assistant_id="123" height="400px"]
   [wp_mcp_ai_chat assistant_id="123" theme="dark"]
   [wp_mcp_ai_chat assistant_id="123" allow_guests="true"]
   ```

**Expected Results**:
- ✅ Chat interface renders correctly
- ✅ Messages send and receive successfully
- ✅ Responses stream in real-time
- ✅ Tool executions display results
- ✅ Shortcode parameters work (width, height, theme)
- ✅ Guest mode works when enabled
- ✅ No JavaScript errors in console
- ✅ UI is responsive on mobile devices

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 7. OpenAI Integration

**Objective**: Verify OpenAI API integration functions correctly.

**Steps**:
1. Navigate to Settings → WP oOS → OpenAI
2. Enter valid API key
3. Save settings
4. Create a test assistant using GPT-4o-mini model
5. Send a chat message
6. Verify response is received
7. Test different models:
   - gpt-4o
   - gpt-4o-mini
   - gpt-4-turbo
8. Monitor API usage in logs

**Expected Results**:
- ✅ API key saves successfully
- ✅ Connection to OpenAI successful
- ✅ Chat completions work
- ✅ Different models can be selected
- ✅ Streaming responses work
- ✅ Token usage is tracked
- ✅ API errors are handled gracefully
- ✅ Rate limits are respected

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 8. Gemini Integration

**Objective**: Verify Google Gemini API integration.

**Steps**:
1. Navigate to Settings → WP oOS → Gemini
2. Enter valid Gemini API key
3. Save settings
4. Create assistant using Gemini model (e.g., gemini-1.5-pro)
5. Send chat message
6. Verify response

**Expected Results**:
- ✅ Gemini API key saves correctly
- ✅ Gemini models are selectable
- ✅ Chat completions work
- ✅ Responses stream correctly
- ✅ Tools work with Gemini models

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 9. Ollama Integration (Local AI)

**Objective**: Verify Ollama local AI integration.

**Steps**:
1. Ensure Ollama is running locally (http://localhost:11434)
2. Navigate to Settings → WP oOS → Ollama
3. Enter Ollama base URL
4. Save settings
5. Create assistant using Ollama model
6. Test chat functionality

**Expected Results**:
- ✅ Ollama base URL saves correctly
- ✅ Local models are accessible
- ✅ Chat completions work
- ✅ No external API calls are made
- ✅ Privacy is maintained (local processing)

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 10. Tool Execution

**Objective**: Verify base tools execute correctly.

**Steps**:
Test each major tool category:

1. **Content Tools**:
   - "Show me the 5 most recent posts"
   - "Search for posts about WordPress"
   - "Create a new post titled 'Test Post'"

2. **User Tools**:
   - "Get information about user ID 1"
   - "List all users with editor role"

3. **Site Tools**:
   - "Give me a summary of this website"
   - "Count tokens in this text: [sample text]"

4. **Search Tools**:
   - "Search the web for WordPress plugins"

**Expected Results**:
- ✅ All tools execute without errors
- ✅ Results are formatted correctly
- ✅ Capability checks are enforced
- ✅ Tool results are incorporated into chat
- ✅ Error messages are clear when tools fail
- ✅ Tool execution is logged

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 11. Token Budget Management

**Objective**: Verify token budget limits work correctly.

**Steps**:
1. Configure assistant with token budget:
   - Budget: 10,000 tokens
   - Window: 1 hour
2. Send multiple messages to consume tokens
3. Verify budget tracking in logs
4. Exceed budget limit
5. Verify 429 error is returned
6. Wait for window to expire
7. Verify budget resets

**Expected Results**:
- ✅ Token usage is tracked accurately
- ✅ Budget limits are enforced
- ✅ 429 error message is user-friendly
- ✅ Time until reset is communicated
- ✅ Budget resets after window expires
- ✅ Multiple users have independent budgets

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 12. Rate Limiting

**Objective**: Verify rate limiting protects against abuse.

**Steps**:
1. Send rapid succession of API requests
2. Verify rate limiting kicks in
3. Check for exponential backoff
4. Verify retry-after headers
5. Monitor rate limit logs

**Expected Results**:
- ✅ Rate limits are enforced
- ✅ 429 status codes returned when exceeded
- ✅ Retry-After header is set
- ✅ Exponential backoff works
- ✅ Rate limits reset after cooldown
- ✅ Rate limiting is logged

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 13. Security Features

**Objective**: Verify security protections are active.

**Steps**:
1. **Capability Checks**:
   - Test REST API as unauthenticated user
   - Test with user lacking capabilities
   - Test with user having correct capabilities

2. **Input Sanitization**:
   - Send malicious input in chat
   - Send SQL injection attempts
   - Send XSS attempts

3. **Output Escaping**:
   - Verify tool results are escaped
   - Check chat responses for XSS vulnerabilities

4. **Nefarious Usage Monitor**:
   - Trigger suspicious patterns
   - Verify monitoring logs activity
   - Test emergency shutdown

**Expected Results**:
- ✅ Unauthorized access is denied
- ✅ Capability checks work correctly
- ✅ All input is sanitized
- ✅ All output is escaped
- ✅ XSS attempts are blocked
- ✅ SQL injection is prevented
- ✅ Nefarious activity is detected
- ✅ Emergency shutdown works

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 14. Logging and Monitoring

**Objective**: Verify logging system works correctly.

**Steps**:
1. Enable logging in settings
2. Perform various actions:
   - Send chat messages
   - Execute tools
   - Trigger errors
   - Hit rate limits
3. Check logs at Settings → WP oOS → Logs
4. Verify log entries are created
5. Test log filtering and search
6. Clear logs and verify they're deleted

**Expected Results**:
- ✅ Logging can be enabled/disabled
- ✅ All major events are logged
- ✅ Log entries have timestamps
- ✅ Logs include relevant context
- ✅ Errors are logged with stack traces
- ✅ Logs can be viewed in admin
- ✅ Logs can be filtered
- ✅ Logs can be cleared

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 15. Admin Settings

**Objective**: Verify all settings can be configured and persist.

**Steps**:
1. Navigate through all settings tabs:
   - General
   - OpenAI
   - Gemini
   - Ollama
   - Authentication
   - Rate Limiting
   - Advanced

2. For each setting:
   - Change the value
   - Save
   - Reload page
   - Verify value persisted

3. Test settings validation:
   - Enter invalid API key format
   - Enter negative numbers for limits
   - Enter invalid URLs

**Expected Results**:
- ✅ All settings save correctly
- ✅ Values persist after save
- ✅ Validation prevents invalid input
- ✅ Error messages are clear
- ✅ Success messages appear on save
- ✅ Defaults can be restored
- ✅ No PHP warnings or errors

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 16. Chat Transcripts

**Objective**: Verify chat transcript functionality.

**Steps**:
1. Enable transcript recording in settings
2. Send several chat messages
3. Check if transcripts are saved
4. View transcript history
5. Test transcript privacy controls
6. Delete transcripts
7. Test localStorage persistence (24h)

**Expected Results**:
- ✅ Transcripts are recorded
- ✅ Transcripts include full context
- ✅ Privacy controls work
- ✅ localStorage persists for 24h
- ✅ Transcripts can be deleted
- ✅ User-specific transcripts are isolated

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 17. Message Attachments

**Objective**: Verify file attachment functionality.

**Steps**:
1. Create assistant with vision-capable model (gpt-4o)
2. In chat interface, upload an image
3. Ask question about the image
4. Verify image is processed
5. Test various file types:
   - PNG image
   - JPEG image
   - PDF document (if supported)

**Expected Results**:
- ✅ Images can be uploaded
- ✅ Upload progress is shown
- ✅ Image is sent with message
- ✅ Assistant can analyze image
- ✅ File type validation works
- ✅ File size limits are enforced
- ✅ Error handling for invalid files

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 18. Server-Sent Events (SSE)

**Objective**: Verify SSE streaming works correctly.

**Steps**:
1. Enable SSE in settings
2. Send chat message
3. Monitor network tab for SSE connection
4. Verify streaming response
5. Test SSE reconnection on disconnect
6. Test fallback when SSE disabled

**Expected Results**:
- ✅ SSE connection establishes
- ✅ Responses stream in real-time
- ✅ Reconnection works on disconnect
- ✅ Fallback works when SSE unavailable
- ✅ No memory leaks from SSE connections
- ✅ SSE works across different browsers

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 19. Multi-Model Support

**Objective**: Verify multiple AI models can be used.

**Steps**:
1. Create assistants with different models:
   - OpenAI GPT-4o
   - OpenAI GPT-4o-mini
   - Gemini 1.5 Pro
   - Ollama Llama2 (if available)

2. Test each assistant
3. Verify model-specific features work
4. Test model switching

**Expected Results**:
- ✅ Multiple models are supported
- ✅ Each model works correctly
- ✅ Model-specific features work
- ✅ Token counting is model-aware
- ✅ Rate limits are model-specific
- ✅ Model can be changed per assistant

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

### 20. Error Handling

**Objective**: Verify error handling is robust.

**Steps**:
Test various error scenarios:

1. **API Errors**:
   - Invalid API key
   - Network timeout
   - API rate limit exceeded
   - Invalid model name

2. **Tool Errors**:
   - Tool execution failure
   - Missing required parameters
   - Capability check failure

3. **Input Errors**:
   - Empty messages
   - Malformed JSON
   - Missing required fields

**Expected Results**:
- ✅ All errors are caught and handled
- ✅ Error messages are user-friendly
- ✅ Technical errors are logged
- ✅ No white screens of death
- ✅ Graceful degradation
- ✅ Recovery from errors possible
- ✅ Stack traces in logs only

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

## Performance Testing

### Load Testing

**Objective**: Verify plugin performs well under load.

**Steps**:
1. Send 100 concurrent chat messages
2. Monitor response times
3. Check memory usage
4. Verify no timeouts
5. Check database query count

**Expected Results**:
- ✅ Response time < 3 seconds average
- ✅ Memory usage stays reasonable
- ✅ No PHP timeouts
- ✅ Database queries are optimized
- ✅ Caching reduces redundant queries

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

## Browser Compatibility

Test in multiple browsers:
- [ ] Chrome/Chromium (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Mobile Chrome (Android)

**Expected**: All features work identically across browsers.

---

## Accessibility Testing

**Objective**: Verify WCAG 2.1 AA compliance.

**Steps**:
1. Test with screen reader (NVDA, JAWS, or VoiceOver)
2. Verify keyboard navigation
3. Check color contrast
4. Test with browser zoom
5. Verify ARIA labels

**Expected Results**:
- ✅ Screen reader announces all elements
- ✅ Full keyboard navigation support
- ✅ Color contrast meets WCAG AA
- ✅ UI works at 200% zoom
- ✅ Proper ARIA labels present

**Status**: [ ] Pass [ ] Fail [ ] N/A

---

## Regression Testing

After any code changes, re-run:
1. Automated test suite (40 tests)
2. Core manual scenarios (1-10)
3. Security testing (13)
4. API endpoint testing (4)

---

## Test Results Summary

### Automated Tests
- Total Tests: 40
- Passed: ___
- Failed: ___
- Skipped: ___

### Manual Tests
- Total Scenarios: 20
- Passed: ___
- Failed: ___
- N/A: ___

### Critical Issues Found
1. ___
2. ___
3. ___

### Non-Critical Issues Found
1. ___
2. ___
3. ___

---

## Sign-Off

**Tester Name**: _______________  
**Date**: _______________  
**Version Tested**: _______________  
**Overall Status**: [ ] PASS [ ] FAIL [ ] CONDITIONAL PASS

**Notes**:
_______________________________________________
_______________________________________________
_______________________________________________

---

## Appendix A: Test Data Templates

### Sample Assistant Configuration
```
Title: Base Test Assistant
System Prompt: You are a helpful WordPress assistant with access to site content and user information.
Model: gpt-4o-mini
Temperature: 0.7
Max Tokens: 4096
Tools Enabled:
- get_recent_posts
- search_content
- get_user_info
- get_site_summary
- create_post
- web_search
```

### Sample Test Messages
1. "Hello, can you help me?"
2. "Show me the 5 most recent posts"
3. "Search for posts about WordPress"
4. "What can you tell me about this website?"
5. "Create a draft post titled 'Test from AI'"

---

## Appendix B: Common Issues and Solutions

### Issue: Chat not loading
**Solution**: Check browser console for errors, verify API key is configured, check network tab for failed requests.

### Issue: Tools not executing
**Solution**: Verify assistant has tools enabled, check user capabilities, review error logs.

### Issue: Streaming not working
**Solution**: Check SSE is enabled, verify server supports SSE, test with SSE disabled as fallback.

### Issue: 429 Rate limit errors
**Solution**: Check token budget settings, verify rate limits aren't too restrictive, wait for budget window to reset.

---

**Document Version**: 1.0  
**Last Updated**: November 9, 2024  
**Maintained By**: WP oOS Testing Team
