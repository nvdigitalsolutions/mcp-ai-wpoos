# Testing Chat Performance Optimizations

This guide covers manual testing procedures for the chat performance optimizations in both shortcode and Elementor widget implementations.

## Prerequisites

1. WordPress site with WP oOS plugin active
2. At least one published AI Assistant
3. Browser with DevTools (Chrome, Firefox, or Edge)
4. (Optional) Elementor plugin for widget testing

## Test Environment Setup

### 1. Create Test Assistant

1. Go to **AI Assistants → Add New**
2. Title: "Performance Test Assistant"
3. Select at least one tool (e.g., "Get Site Summary")
4. Publish the assistant
5. Note the Assistant ID from the URL

### 2. Create Test Pages

#### Shortcode Test Page

1. Create a new page: **Pages → Add New**
2. Title: "Chat Shortcode Test"
3. Add the shortcode:
   ```
   [mcp_ai_chat assistant="YOUR_ASSISTANT_ID"]
   ```
4. Publish the page

#### Widget Test Page (if using Elementor)

1. Create a new page with Elementor
2. Title: "Chat Widget Test"
3. Add the **WP oOS Chat** widget
4. Configure:
   - Select your test assistant
   - Enable/disable guest access as needed
5. Publish the page

## Test 1: Verify Optimizations Are Active (Default)

### Shortcode Test

1. Open the shortcode test page
2. Open browser DevTools (F12)
3. Go to **Console** tab
4. Type and run:
   ```javascript
   console.log('Debug Mode:', window.wpMcpAiChatDebugMode);
   console.log('Optimizations:', !window.wpMcpAiChatDebugMode);
   ```
5. **Expected:** Debug mode should be `undefined` or `false`, optimizations enabled

### Widget Test

1. Open the widget test page
2. Open browser DevTools (F12)
3. Verify same JavaScript config as shortcode test
4. **Expected:** Same behavior as shortcode

### Behavior to Verify

1. **Type a message** in the chat
2. **Before hitting send**, open DevTools → **Application** → **Local Storage**
3. **Send the message**
4. **Expected:** localStorage update should be debounced (may not appear immediately)
5. **Wait 300ms** and refresh the localStorage view
6. **Expected:** Conversation data should now be saved

## Test 2: Disable Optimizations (Debug Mode)

### JavaScript Debug Mode

1. Open browser DevTools Console
2. Run before page loads (or add to theme):
   ```javascript
   window.wpMcpAiChatDebugMode = true;
   ```
3. Refresh the page
4. Verify debug mode is active:
   ```javascript
   console.log('Debug Mode:', window.wpMcpAiChatDebugMode);
   ```

### Test Shortcode in Debug Mode

1. Type and send a message
2. Check localStorage immediately
3. **Expected:** Conversation saves immediately (no debouncing)
4. **Expected:** Console may show more immediate DOM operations

### Test Widget in Debug Mode

1. Repeat same test with widget implementation
2. **Expected:** Same immediate-save behavior as shortcode
3. Verify both implementations behave identically

## Test 3: PHP Cache Optimization

### Enable Cache Bypass

Add to `wp-config.php`:

```php
define('WP_MCP_AI_DISABLE_CACHE', true);
```

### Test API Performance

1. Install and activate **Query Monitor** plugin (optional but helpful)
2. Send a chat message
3. Check database queries in Query Monitor
4. **Expected:** Each assistant validation triggers a new `get_post()` query

### With Cache Enabled (Default)

1. Remove or comment out the constant from `wp-config.php`
2. Send another chat message
3. Check database queries
4. **Expected:** Repeated assistant validations use cached result (fewer queries)

## Test 4: Message Rendering Performance

### Test with Multiple Attachments

1. Create a test assistant with **Search Attachments** tool
2. Upload 5-10 test files to Media Library
3. Send message: "Search for all attachments"
4. Open DevTools → **Performance** tab
5. Click **Record**
6. Wait for response with attachments to render
7. Stop recording
8. **Expected:** DOM operations should be batched (look for single appendChild)

### Compare Debug Mode

1. Enable debug mode: `window.wpMcpAiChatDebugMode = true`
2. Repeat the test
3. **Expected:** May see individual appendChild operations instead of batched

## Test 5: Message Bundling

### Rapid Message Test (Optimizations Enabled)

1. **Open the chat interface** (shortcode or widget)
2. **Open DevTools** → **Network** tab
3. **Filter by:** XHR or Fetch requests
4. **Type and send 3 messages rapidly** (within 1-2 seconds):
   - Message 1: "Hello"
   - Message 2: "How are you?"
   - Message 3: "What can you do?"
5. **Expected behavior:**
   - All 3 messages appear in the UI immediately
   - Status shows "Preparing to send…" briefly
   - Only 1 API request is sent after 800ms delay
   - The request contains all 3 user messages in the conversation

### Single Message Test (Baseline)

1. **Type and send one message:** "Test message"
2. **Wait 2 seconds**
3. **Expected:**
   - Message appears in UI
   - Status shows "Preparing to send…" for 800ms
   - Then API request is sent
   - Response is displayed normally

### Debug Mode (No Bundling)

1. **Enable debug mode:**
   ```javascript
   window.wpMcpAiChatDebugMode = true;
   ```
2. **Refresh the page**
3. **Send 3 messages rapidly** (same as above)
4. **Expected:**
   - Status shows "Sending…" immediately (not "Preparing to send…")
   - 3 separate API requests are made
   - Each message is sent as soon as it's submitted

### Visual Feedback Verification

1. **Without debug mode**, send a message
2. **Look for status text:** Should show "Preparing to send…"
3. **After 800ms:** Status changes to "Sending…"
4. **Expected:** Clear visual distinction between bundling and sending states

## Test 6: localStorage Debouncing

### Rapid Message Test

1. **Without Debug Mode** (optimizations enabled):
   - Type and send 5 messages rapidly (every 100ms)
   - Monitor localStorage writes in DevTools
   - **Expected:** Fewer than 5 writes due to debouncing

2. **With Debug Mode**:
   - Enable debug mode
   - Send 5 messages rapidly
   - **Expected:** 5 immediate writes to localStorage

### Test Both Implementations

Repeat for both:
- Shortcode implementation
- Elementor widget implementation

**Expected:** Both should behave identically

## Test 7: Cross-Browser Testing

Test on multiple browsers:

### Chrome/Edge (Chromium)
1. Test all scenarios above
2. Verify DevTools shows expected behavior

### Firefox
1. Test all scenarios above
2. Verify DevTools shows expected behavior

### Safari (if available)
1. Test all scenarios above
2. Verify Web Inspector shows expected behavior

**Expected:** Consistent behavior across all browsers

## Test 8: Mobile Responsiveness

### Mobile Test (Real Device or DevTools Emulation)

1. Open shortcode test page in mobile view
2. Test chat functionality
3. **Expected:** Optimizations don't affect mobile UX

4. Open widget test page in mobile view
5. Test chat functionality
6. **Expected:** Same behavior as shortcode

## Test 9: Performance Comparison

### Measure Load Time

1. Open DevTools → **Network** tab
2. Hard refresh the page (Ctrl+Shift+R)
3. Note the load time

### Measure Interaction Speed

1. Start **Performance** recording
2. Type and send a message
3. Wait for response
4. Stop recording
5. Analyze:
   - Time to send
   - Time to render response
   - localStorage operation time

### Compare With/Without Optimizations

Run the same tests with:
- Optimizations enabled (default)
- Debug mode enabled (optimizations disabled)

**Expected:** Optimized version should show:
- Reduced localStorage operation time
- Faster DOM rendering for multi-attachment messages
- Lower overall interaction time

## Test 10: Error Handling

### Test with Network Issues

1. Open DevTools → **Network** tab
2. Enable **Throttling** → **Slow 3G**
3. Send a message
4. **Expected:** Optimizations don't interfere with error handling

### Test with localStorage Quota

1. Open DevTools Console
2. Fill localStorage:
   ```javascript
   try {
       let i = 0;
       while(true) {
           localStorage.setItem('test' + i, 'x'.repeat(100000));
           i++;
       }
   } catch(e) {
       console.log('Quota reached');
   }
   ```
3. Try to send a chat message
4. **Expected:** Should fail gracefully (no crashes)

## Test 11: Compatibility Testing

### With Other Plugins

Test chat with commonly used plugins:
- Caching plugins (W3 Total Cache, WP Super Cache)
- Security plugins (Wordfence, iThemes Security)
- Performance plugins (Autoptimize, WP Rocket)

**Expected:** No conflicts, optimizations work normally

### With Different Themes

Test on:
- Default WordPress theme (Twenty Twenty-Four)
- Popular page builder themes
- Custom themes

**Expected:** Consistent behavior across all themes

## Success Criteria

### Shortcode Implementation ✓

- [ ] Optimizations active by default
- [ ] Debug mode disables optimizations
- [ ] Message bundling groups rapid inputs (800ms window)
- [ ] localStorage saves are debounced
- [ ] DOM operations are batched
- [ ] PHP cache reduces queries
- [ ] No errors in console
- [ ] Works on all browsers
- [ ] Mobile responsive

### Widget Implementation ✓

- [ ] Optimizations active by default
- [ ] Debug mode disables optimizations
- [ ] Message bundling works identically to shortcode
- [ ] Behavior identical to shortcode
- [ ] No Elementor conflicts
- [ ] Works in preview and live mode
- [ ] No errors in console
- [ ] Works on all browsers
- [ ] Mobile responsive

## Reporting Issues

If any test fails, report with:

1. **Test number and description**
2. **Implementation tested** (shortcode or widget)
3. **WordPress version**
4. **PHP version**
5. **Browser and version**
6. **Debug mode status**
7. **Console errors** (if any)
8. **Expected vs actual behavior**
9. **Steps to reproduce**

## Additional Resources

- [Chat Performance Optimizations Documentation](features/performance/chat-performance-optimizations.md)
- [Quick Reference Guide](QUICK_REFERENCE.md)
- [Troubleshooting Guide](troubleshooting/deployment/deployment-troubleshooting.md)
