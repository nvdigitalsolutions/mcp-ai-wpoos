# Message Bundling Feature

## Overview

Message bundling is a client-side optimization that groups rapid user inputs within a short time window (800ms) before sending them to the server as a single API request. This reduces server load, API costs, and improves the experience for users who prefer to send multiple short messages in quick succession.

## How It Works

1. **User sends a message** - The message is displayed in the chat UI immediately
2. **800ms timer starts** - The system waits to see if additional messages arrive
3. **More messages within window** - Each new message resets the 800ms timer
4. **Timer expires** - All queued messages are sent together in one API request
5. **Server receives bundle** - The server processes all messages in the conversation normally

## Visual Feedback

When message bundling is active, users see:
- **"Preparing to send…"** - Messages are being queued during the 800ms window
- **"Sending…"** - The bundled messages are now being transmitted to the server

This provides clear feedback that the system is working, even during the brief delay.

## Benefits

### For Users
- **Natural chat flow** - Works great for users who type in short bursts
- **Mobile-friendly** - Ideal for mobile users who send rapid tap-to-talk messages
- **No waiting** - Messages appear in the UI instantly, bundling happens in the background

### For Site Owners
- **Reduced API costs** - Fewer requests mean lower costs for pay-per-request APIs
- **Lower server load** - Fewer requests to process and respond to
- **Better performance** - Reduced network traffic and server processing time

### For Developers
- **Backward compatible** - Server code unchanged, same payload format
- **Easy to test** - Debug mode disables bundling for testing
- **Configurable** - Delay constant can be adjusted if needed

## Use Cases

Message bundling is particularly effective for:

1. **Mobile chat interfaces** - Users who tap-type multiple short messages
2. **Copy-paste workflows** - When users paste multiple messages quickly
3. **Power users** - Who prefer to send thoughts as separate messages
4. **High-latency connections** - Reduces the impact of network round-trips
5. **Cost-sensitive deployments** - Where API usage costs are a concern

## Configuration

### Default Settings

```javascript
const MESSAGE_BUNDLE_DELAY_MS = 800; // 800ms delay window
const OPTIMIZATIONS_ENABLED = !DEBUG_MODE; // Bundling enabled by default
```

### Adjusting the Delay

To change the bundling delay, edit `assets/js/chat.js`:

```javascript
// Shorter delay (400-600ms) - More responsive but bundles fewer messages
const MESSAGE_BUNDLE_DELAY_MS = 500;

// Longer delay (1000-1500ms) - Bundles more messages but feels less responsive
const MESSAGE_BUNDLE_DELAY_MS = 1200;
```

**Recommended range:** 600-1000ms for most use cases.

### Disabling Bundling

To disable message bundling for testing or debugging:

```javascript
// In browser console or before page loads
window.wpMcpAiChatDebugMode = true;
```

Or in your theme/plugin:

```php
add_action('wp_footer', function() {
    ?>
    <script>
        window.wpMcpAiChatDebugMode = true;
    </script>
    <?php
}, 1);
```

## Technical Details

### Implementation

- **Location:** `assets/js/chat.js`
- **Functions:** `queueMessageForBundling()`, `sendBundledMessages()`
- **State properties:** `pendingMessageBundle`, `messageBundleTimer`
- **Pattern:** Similar to existing localStorage debouncing optimization

### Message Flow

1. `handleSubmit()` - Captures user input
2. `queueMessageForBundling()` - Adds to queue, sets/resets timer
3. `sendBundledMessages()` - Sends all queued messages when timer expires
4. `sendChat()` - Transmits to server (unchanged)

### Server Compatibility

The bundling is transparent to the server:
- Messages already added to `state.conversation` array during `handleSubmit()`
- Server receives the same conversation payload format
- No changes needed to REST API endpoints
- Tool executions and responses work normally

## Testing

See [TESTING-CHAT-OPTIMIZATIONS.md](TESTING-CHAT-OPTIMIZATIONS.md) for comprehensive testing procedures.

### Quick Test

1. Open the chat interface
2. Open browser DevTools → Network tab
3. Send 3 messages rapidly (within 1-2 seconds)
4. **Expected:** Only 1 API request after 800ms delay

### Debug Mode Test

1. Enable debug mode: `window.wpMcpAiChatDebugMode = true`
2. Refresh the page
3. Send 3 messages rapidly
4. **Expected:** 3 separate API requests sent immediately

## Troubleshooting

### Messages not bundling
- Check that debug mode is disabled: `console.log(window.wpMcpAiChatDebugMode)`
- Verify you're sending messages within 800ms of each other
- Check browser console for JavaScript errors

### Bundling feels too slow
- Consider reducing `MESSAGE_BUNDLE_DELAY_MS` to 500-600ms
- Remember that users see messages instantly in the UI

### Bundling causes issues
- Enable debug mode to disable bundling
- Report issues with browser, WordPress version, and console errors

## Related Documentation

- [Chat Performance Optimizations](chat-performance-optimizations.md) - Complete optimization guide
- [Testing Guide](TESTING-CHAT-OPTIMIZATIONS.md) - Comprehensive testing procedures
- [Quick Reference](QUICK_REFERENCE.md) - Fast access to common tasks
- [Troubleshooting](deployment-troubleshooting.md) - Common issues and solutions

## Future Enhancements

Potential improvements for message bundling:

1. **Adaptive delay** - Adjust delay based on user typing patterns
2. **User preference** - Allow users to enable/disable bundling
3. **Smart bundling** - Don't bundle when attachments are present
4. **Metrics** - Track bundling effectiveness and API savings
5. **Visual countdown** - Show timer progress during bundling window
