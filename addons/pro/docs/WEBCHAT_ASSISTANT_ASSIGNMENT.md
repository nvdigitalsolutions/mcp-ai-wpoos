# WebChat Assistant Assignment Feature

## Overview

This feature allows WordPress administrators to assign an AI assistant to a WebChat room. The assistant can monitor and respond to messages in the room automatically, enabling use cases like:

- Automated moderation
- Intelligent customer support
- FAQ answering
- Community management

## Architecture

### Components

1. **WebChat Room CPT** (`mcp_ai_webchat`)
   - Custom Post Type for managing P2P chat rooms
   - Located: `addons/pro/includes/class-wp-mcp-ai-webchat-cpt.php`

2. **Assistant Assignment Metabox**
   - New metabox for assigning assistants to rooms
   - Located: `addons/pro/includes/metaboxes/class-wp-mcp-ai-webchat-metabox-assistant.php`
   - Displays dropdown of available assistants
   - Saves selection to `_mcp_ai_webchat_assigned_assistant` meta key

3. **WebChat Messages CCT** (JetEngine)
   - Stores all chat messages persistently
   - Located: `addons/pro/includes/class-wp-mcp-ai-jetengine-webchat-messages-cct.php`
   - Schema includes: room_id, peer_id, user_id, sender_name, message, timestamp, etc.

4. **Available Tools**
   - `get_webchat_messages` - Retrieve chat history from CCT
   - `save_webchat_message` - Save messages to CCT for persistence
   - `send_webchat_message` - Send messages to active WebChat rooms via signaling

## How It Works

### Assistant Assignment Flow

1. Admin navigates to **WebChat Rooms** → Edit a room
2. In the **AI Assistant** metabox (sidebar), select an assistant from dropdown
3. Save the room
4. The assistant ID is saved to `_mcp_ai_webchat_assigned_assistant` post meta

### Message Flow with Assistant

```
User sends message in WebChat
    ↓
Message arrives via WebRTC signaling
    ↓
[Optional] Message saved to CCT via save_webchat_message tool
    ↓
If room has assigned assistant:
    ↓
Assistant receives message context
    ↓
Assistant processes message (can use get_webchat_messages for history)
    ↓
Assistant responds via send_webchat_message tool
    ↓
Response delivered to all room participants
```

### Data Storage

#### Post Meta (WebChat Room)
- `_mcp_ai_webchat_assigned_assistant` - Assistant post ID (integer)
- `_mcp_ai_webchat_room_id` - Unique room identifier
- `_mcp_ai_webchat_status` - Room status (active/inactive/archived)
- `_mcp_ai_webchat_max_participants` - Maximum participants
- `_mcp_ai_webchat_allow_anonymous` - Allow anonymous users

#### CCT (WebChat Messages) - via JetEngine
- `room_id` - Links to mcp_ai_webchat post ID
- `peer_id` - WebRTC peer identifier
- `user_id` - WordPress user ID (0 for anonymous)
- `sender_name` - Display name
- `message` - Message content
- `message_type` - text/image/file/system
- `timestamp` - Message timestamp
- `is_encrypted` - E2E encryption flag
- `metadata` - Additional JSON data

## Usage Examples

### Example 1: Assign Assistant to Room

```php
// Get or create a room
$room_id = 123; // WebChat room post ID

// Get an assistant
$assistant_id = 456; // Assistant post ID

// Assign assistant to room
update_post_meta( $room_id, '_mcp_ai_webchat_assigned_assistant', $assistant_id );
```

### Example 2: Retrieve Assigned Assistant

```php
// Using the helper method
$room_id = 123;
$assistant_id = WP_MCP_AI_WebChat_CPT::get_assigned_assistant( $room_id );

if ( $assistant_id > 0 ) {
    $assistant = get_post( $assistant_id );
    echo "Room is monitored by: " . $assistant->post_title;
}
```

### Example 3: Assistant Retrieves Chat History

When an assistant is processing a message, it can use the `get_webchat_messages` tool:

```json
{
  "tool": "get_webchat_messages",
  "arguments": {
    "room_id": 123,
    "limit": 50,
    "message_type": "text"
  }
}
```

### Example 4: Assistant Sends Response

```json
{
  "tool": "send_webchat_message",
  "arguments": {
    "room_id": 123,
    "message": "Hello! I'm the AI assistant for this room. How can I help?",
    "sender_name": "Support Bot"
  }
}
```

## Configuration

### Enable WebChat Integration

1. Navigate to **Settings** → **NV oOS** → **Tools & Features**
2. Click **Features** tab
3. Check **"Enable WebChat Integration"**
4. Save changes

### Requires

- **WordPress 6.0+**
- **PHP 7.4+**
- **NV oOS Plugin** (Base or Full version)
- **Pro Addon** for full WebChat features
- **JetEngine** (optional, for persistent message storage in CCT)

### Self-Hosted Signaling (Recommended)

The plugin includes a self-hosted WebRTC signaling server using WordPress REST API:

- Endpoint: `/wp-json/mcp-ai/v1/webchat/`
- No external WebSocket server required
- Server-Sent Events (SSE) for real-time updates
- Supports peer discovery, offer/answer exchange, ICE candidates

## API Reference

### Helper Methods

#### `WP_MCP_AI_WebChat_CPT::get_assigned_assistant( $post_id )`

Returns the assistant post ID assigned to a room, or 0 if none.

**Parameters:**
- `$post_id` (int) - WebChat room post ID

**Returns:** (int) Assistant post ID or 0

#### `WP_MCP_AI_WebChat_CPT::get_room_id( $post_id )`

Returns the unique room identifier for a WebChat room.

**Parameters:**
- `$post_id` (int) - WebChat room post ID

**Returns:** (string) Room ID

#### `WP_MCP_AI_WebChat_CPT::get_room_status( $post_id )`

Returns the room status.

**Parameters:**
- `$post_id` (int) - WebChat room post ID

**Returns:** (string) 'active', 'inactive', or 'archived'

### AI Tools for WebChat

#### `get_webchat_messages`

Retrieves messages from the webchat_messages CCT for a specific room.

**Required:** JetEngine Custom Content Types module

**Parameters:**
- `room_id` (integer, required) - WebChat room post ID
- `limit` (integer, optional) - Max messages to retrieve (default: 50, max: 100)
- `offset` (integer, optional) - Number of messages to skip (default: 0)
- `message_type` (string, optional) - Filter by type: text, image, file, system

#### `save_webchat_message`

Saves a message to the webchat_messages CCT for persistent storage.

**Required:** JetEngine Custom Content Types module

**Parameters:**
- `room_id` (integer, required) - WebChat room post ID
- `peer_id` (string, required) - WebRTC peer identifier
- `sender_name` (string, required) - Display name of sender
- `message` (string, required) - Message content
- `user_id` (integer, optional) - WordPress user ID (default: 0 for anonymous)
- `message_type` (string, optional) - Type: text, image, file, system (default: text)
- `is_encrypted` (boolean, optional) - E2E encryption flag (default: false)
- `metadata` (string, optional) - Additional JSON metadata

#### `send_webchat_message`

Sends a message to WebChat P2P rooms on this site.

**Parameters:**
- `message` (string, required) - Message text to broadcast
- `room_id` (string, optional) - Target room ID (if omitted, broadcasts to all active rooms)
- `sender_name` (string, optional) - Display name for sender (default: "WordPress Assistant")

## UI Components

### Assistant Assignment Metabox

Located in the sidebar of the WebChat room edit screen:

**Features:**
- Dropdown list of all published assistants
- "None" option to unassign
- Visual indicator when assistant is active
- Information box explaining how it works
- Documentation link

**Visual States:**
- No assistant assigned: Shows standard dropdown
- Assistant assigned: Shows dropdown + green info box with checkmark
- Helpful tips section explaining assistant capabilities

## Testing

Run the test suite:

```bash
vendor/bin/phpunit addons/pro/tests/test-webchat-assistant-assignment.php
```

**Test Coverage:**
- Metabox class exists
- Save assistant assignment
- Retrieve assigned assistant
- No assistant returns 0
- Metabox instantiation

## Troubleshooting

### Assistant Not Responding

1. **Check Assignment:** Verify assistant is assigned in room settings
2. **Check Room Status:** Ensure room status is "active"
3. **Check Assistant Status:** Verify assistant post is published
4. **Check Tools:** Ensure assistant has `send_webchat_message` tool enabled
5. **Check Logs:** Enable debug logging in NV oOS settings

### Messages Not Persisting

1. **Check JetEngine:** Verify JetEngine plugin is active
2. **Check CCT Module:** Ensure Custom Content Types module is enabled
3. **Check CCT:** Navigate to JetEngine → Custom Content Types → verify `webchat_messages` exists
4. **Check Tools:** Verify `save_webchat_message` tool is available

### Metabox Not Showing

1. **Check Pro Addon:** Verify Pro addon is active
2. **Check WebChat Setting:** Ensure "Enable WebChat Integration" is checked
3. **Check Version:** Verify not running in Base Version mode without Pro
4. **Check Screen Options:** Click "Screen Options" at top and ensure metabox is checked

## Security Considerations

- **Capability Checks:** All tools check user capabilities before execution
- **Nonce Verification:** Metabox save operations verify WordPress nonces
- **Input Sanitization:** All inputs are sanitized before storage
- **Output Escaping:** All output is properly escaped for security
- **E2E Encryption:** WebChat supports end-to-end encryption for messages
- **Anonymous Access:** Can be disabled per-room for security

## Performance

- **CCT Storage:** Efficient indexing via JetEngine for fast queries
- **Signaling Server:** Lightweight REST API implementation
- **SSE Streaming:** Server-Sent Events for real-time updates without polling
- **Caching:** Room settings cached via WordPress object cache
- **Lazy Loading:** Metaboxes only load on relevant screens

## Future Enhancements

Possible future improvements:

- [ ] Bulk assign assistant to multiple rooms
- [ ] Assistant scheduling (active hours)
- [ ] Message triggers/filters for assistant activation
- [ ] Analytics dashboard for assistant performance
- [ ] Multi-assistant support per room
- [ ] Auto-assign assistant based on room topic/category

## Related Documentation

- [WebChat Self-Hosted Summary](../../../WEBCHAT-SELF-HOSTED-SUMMARY.md)
- [Tool Reference](../../../docs/tool-reference.md)
- [REST API Documentation](../../../docs/rest-api.md)

## Support

For issues or questions:
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs
