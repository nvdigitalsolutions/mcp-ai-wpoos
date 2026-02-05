# WebChat Troubleshooting Guide

## Common Issues and Solutions

### WebRTC Not Supported Error

**Error Message:**
```
Uncaught (in promise) Error: WebRTC is not supported by this browser
```

**Cause:**
This error originates from the WebChat browser extension when it attempts to initialize WebRTC functionality on a page. The error occurs when:
1. The browser does not support WebRTC
2. The browser has WebRTC disabled
3. The browser extension is installed but the page is being viewed in an incompatible browser

**Is This a Problem?**
No, this error is **harmless** if you're not actively using the WebChat feature. It does not affect:
- WordPress functionality
- Your plugin's other features
- Page performance
- User experience (unless actively using WebChat rooms)

**Solutions:**

#### Option 1: Use a WebRTC-Compatible Browser
Ensure you're using a modern browser with WebRTC support:

| Browser | Minimum Version | Recommended Version |
|---------|----------------|---------------------|
| Chrome  | 23+            | Latest              |
| Edge    | 23+            | Latest              |
| Firefox | 22+            | Latest              |
| Safari  | 11+            | Latest              |
| Opera   | 18+            | Latest              |

**Note:** Internet Explorer does not support WebRTC.

#### Option 2: Disable the WebChat Extension on Non-WebChat Pages
If you're not using WebChat rooms on certain pages:

1. Click the WebChat extension icon in your browser
2. Select "Disable on this site" or "Disable on this page"
3. Reload the page

#### Option 3: Enable WebRTC in Browser Settings
If your browser supports WebRTC but it's disabled:

**Chrome/Edge:**
1. Navigate to `chrome://flags` (or `edge://flags`)
2. Search for "WebRTC"
3. Ensure WebRTC features are enabled
4. Restart browser

**Firefox:**
1. Navigate to `about:config`
2. Search for `media.peerconnection.enabled`
3. Ensure it's set to `true`
4. Restart browser

**Safari:**
1. Safari → Preferences → Advanced
2. Check "Show Develop menu in menu bar"
3. Develop → Experimental Features
4. Ensure "WebRTC" is enabled
5. Restart browser

### HTTPS Required Error

**Error Message:**
```
WebRTC requires HTTPS
```

**Cause:**
WebRTC media streams (video/audio) require a secure HTTPS connection for security reasons.

**Solution:**
1. Ensure your WordPress site uses HTTPS
2. Install an SSL certificate if you haven't already
3. Configure WordPress to use HTTPS:
   - Settings → General
   - Update "WordPress Address (URL)" and "Site Address (URL)" to use `https://`

**Testing Locally:**
For local development, WebRTC allows `localhost` without HTTPS:
- `http://localhost:8000` - ✅ Works
- `http://192.168.1.100` - ❌ Requires HTTPS
- `https://yoursite.com` - ✅ Works

### Signaling Server Connection Failed

**Error Message:**
```
Failed to connect to signaling server
```

**Cause:**
The WebRTC signaling server is unreachable or misconfigured.

**Solutions:**

#### For Self-Hosted Signaling (Recommended)
1. Verify self-hosted signaling is enabled:
   - Go to **WebChat → Settings**
   - Ensure "Enable Self-Hosted Signaling" is checked
   - Save settings

2. Check REST API accessibility:
   - Navigate to: `https://yoursite.com/wp-json/mcp-ai/v1/webchat/`
   - You should see a JSON response, not a 404 error

3. Verify permalink settings:
   - Settings → Permalinks
   - Ensure you're using "Post name" or another pretty permalink structure
   - Click "Save Changes" to flush rewrite rules

#### For External WebSocket Server
1. Verify the WebSocket URL is correct
2. Ensure the external server is running
3. Check firewall rules allow WebSocket connections
4. Test WebSocket connectivity: `wss://your-signaling-server.com`

### Room Not Found

**Error Message:**
```
Room not found or inactive
```

**Cause:**
The WebChat room doesn't exist or is not in "active" status.

**Solutions:**
1. Verify the room exists:
   - Go to **WebChat Rooms** in WordPress admin
   - Check that the room is published

2. Check room status:
   - Edit the WebChat room
   - In "Room Details" metabox, ensure Status is "Active"
   - Save the room

3. Verify Room ID:
   - The Room ID in the metabox must match the ID used by the extension
   - Copy the exact Room ID from the metabox

### Peer Connection Failed

**Error Message:**
```
Failed to establish peer connection
```

**Cause:**
Direct peer-to-peer connection could not be established due to network restrictions.

**Solutions:**

#### Check Firewall and NAT
1. Ensure UDP ports are not blocked by firewall
2. Configure router to allow WebRTC connections
3. Use STUN/TURN servers for NAT traversal

#### Configure TURN Server (For Restrictive Networks)
If users are behind strict firewalls or symmetric NATs:

1. Set up a TURN server (e.g., coturn)
2. Configure in WebChat room settings:
   ```json
   {
     "iceServers": [
       {
         "urls": "stun:stun.l.google.com:19302"
       },
       {
         "urls": "turn:your-turn-server.com:3478",
         "username": "user",
         "credential": "pass"
       }
     ]
   }
   ```

### Browser Extension Not Detected

**Symptom:**
WebChat rooms don't appear or chat widget doesn't load.

**Cause:**
The WebChat browser extension is not installed or is disabled.

**Solutions:**
1. Install the WebChat extension:
   - Chrome/Edge: [Chrome Web Store Link]
   - Firefox: [Firefox Add-ons Link]
   - Visit: https://github.com/molvqingtai/WebChat

2. Verify extension is enabled:
   - Check browser extensions page
   - Ensure WebChat extension has a checkmark
   - Reload the page after enabling

3. Grant necessary permissions:
   - Extension may need permissions to access your site
   - Click the extension icon and approve any permission requests

## Browser Console Errors Explained

### "WebRTC is not supported by this browser"
**Impact:** Low  
**Action:** Use a WebRTC-compatible browser or disable extension on non-WebChat pages  
**Affects Plugin?** No - Error is from browser extension, not the WordPress plugin

### "Failed to load resource: net::ERR_CONNECTION_REFUSED"
**Impact:** High  
**Action:** Check signaling server configuration  
**Affects Plugin?** Yes - Chat rooms won't connect

### "Mixed Content" warning
**Impact:** High  
**Action:** Ensure entire site uses HTTPS  
**Affects Plugin?** Yes - WebRTC requires secure context

## Performance Optimization

### High CPU Usage During Video Chat
**Cause:** Multiple video streams being processed simultaneously.

**Solutions:**
1. Limit max participants in room settings
2. Reduce video quality in WebChat extension settings
3. Disable video when only audio is needed
4. Use modern hardware-accelerated browser

### Signaling Server Performance
**For Self-Hosted Signaling:**
1. Enable object caching (Redis/Memcached) for transients
2. Use a dedicated application server for high-traffic sites
3. Monitor WordPress transient queries
4. Consider external WebSocket server for 100+ concurrent rooms

**For External WebSocket Server:**
1. Deploy on separate server from WordPress
2. Use horizontal scaling for high availability
3. Monitor WebSocket connection count
4. Implement connection rate limiting

## Debugging Tips

### Enable Browser Debug Logs
**Chrome/Edge:**
1. Open DevTools (F12)
2. Go to Console tab
3. Enable "Verbose" log level
4. Look for WebRTC-related messages

**Firefox:**
1. Open DevTools (F12)
2. Go to Console tab
3. Enable "Logs" in filter
4. Search for "WebRTC" or "RTCPeerConnection"

### Check WordPress Debug Log
Enable WordPress debugging to see server-side errors:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check log file: `wp-content/debug.log`

### Test WebRTC Connectivity
Use online tools to test your browser's WebRTC support:
- https://test.webrtc.org/
- https://webrtc.github.io/samples/

### Verify REST API Endpoints
Test signaling server endpoints:

```bash
# Test peer registration
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/webchat/peers/register \
  -H "Content-Type: application/json" \
  -d '{
    "peer_id": "test-peer",
    "room_id": "test-room",
    "user_name": "Test User"
  }'

# Test room peers list
curl https://yoursite.com/wp-json/mcp-ai/v1/webchat/rooms/test-room/peers
```

## Getting Help

### Before Requesting Support
1. Check browser console for error messages
2. Verify browser compatibility
3. Test with HTTPS enabled
4. Try disabling other browser extensions
5. Clear browser cache and cookies
6. Test in incognito/private mode

### Information to Provide
When requesting support, include:
- Browser name and version
- Operating system
- WordPress version
- Plugin version
- WebChat extension version
- Exact error message from console
- Steps to reproduce the issue
- Whether HTTPS is enabled
- Whether self-hosted or external signaling is used

### Support Channels
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://nvdigital.solutions/docs/mcp-ai-wpoos/
- WebChat Extension: https://github.com/molvqingtai/WebChat

## FAQ

### Q: Can I use WebChat without the browser extension?
**A:** No, the WebChat browser extension is required for users to participate in P2P chat rooms. However, AI assistants can send messages to rooms without the extension using the `send_webchat_message` tool.

### Q: Does the WebRTC error affect other WordPress functionality?
**A:** No, WebRTC errors from the browser extension do not affect WordPress, your plugin, or other site features. They are isolated to the WebChat extension's initialization.

### Q: Why do I see the error even when not using WebChat?
**A:** The browser extension attempts to initialize WebRTC on all pages where it's active. You can disable the extension on specific pages where you don't need WebChat functionality.

### Q: Is self-hosted signaling sufficient for production?
**A:** Yes, for most use cases. Self-hosted signaling works well for up to 100 concurrent rooms. For larger deployments, consider an external WebSocket server for better performance.

### Q: What browsers are NOT supported?
**A:** Internet Explorer does not support WebRTC and cannot be used for WebChat. All other modern browsers (Chrome, Firefox, Safari, Edge, Opera) support WebRTC.

### Q: Can I hide the WebRTC error from the console?
**A:** The error comes from the browser extension's `content.js`, not from the WordPress plugin. To prevent it, either use a WebRTC-compatible browser or disable the extension on pages where WebChat is not needed.

## Related Documentation
- [WebChat Self-Hosted Signaling](WEBCHAT-SELF-HOSTED-SIGNALING.md)
- [WebChat Assistant Assignment](WEBCHAT_ASSISTANT_ASSIGNMENT.md)
- [Pro Toolkit Documentation](NEW_TOOLKITS_README.md)
