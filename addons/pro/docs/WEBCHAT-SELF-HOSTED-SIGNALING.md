# WebChat Self-Hosted Signaling

## Overview

The WebChat integration now supports **self-hosted WebRTC signaling**, eliminating the need for a separate external WebSocket server. This feature uses WordPress REST API and Server-Sent Events (SSE) to facilitate peer-to-peer WebRTC connections.

## Architecture

### Signaling Flow

1. **Peer Registration**: Peers register with the WordPress signaling server via REST API
2. **Presence Tracking**: WordPress tracks active peers using transients with 60-second TTL
3. **Event Relay**: Signaling messages (offers, answers, ICE candidates) are queued as transients
4. **Real-Time Delivery**: SSE stream delivers events to peers in real-time
5. **Connection Establishment**: Once peers exchange signaling data, direct P2P WebRTC connection is established

### REST Endpoints

All endpoints are available at `/wp-json/mcp-ai/v1/webchat/`:

- `POST /peers/register` - Register a peer in a room
- `POST /peers/{peer_id}/heartbeat` - Update peer presence
- `GET /rooms/{room_id}/peers` - List active peers in a room
- `POST /signal` - Exchange WebRTC offer/answer
- `POST /ice` - Exchange ICE candidates
- `GET /stream?room_id={room_id}&peer_id={peer_id}` - SSE stream for real-time events

### Data Storage

- **Peer Presence**: Stored as transients (`wp_mcp_ai_webchat_peer_{hash}`) with 60-second TTL
- **Pending Events**: Stored as transients (`wp_mcp_ai_webchat_events_{hash}`) with 60-second TTL
- **Room Configuration**: Stored as post meta on `mcp_ai_webchat_room` CPT

## Configuration

### Enabling Self-Hosted Signaling

1. Navigate to **WebChat → Settings** in WordPress admin
2. Under "Default WebChat Settings", check **"Enable Self-Hosted Signaling"**
3. Save settings

Self-hosted signaling is **enabled by default** for new installations.

### External WebSocket Server (Optional)

If you prefer to use an external WebSocket server:

1. Uncheck **"Enable Self-Hosted Signaling"**
2. Enter your WebSocket URL in **"External Signaling Server URL"** (e.g., `wss://signaling.example.com`)
3. Save settings

## Advantages of Self-Hosted Signaling

### ✅ Benefits

- **No External Dependencies**: Runs entirely on WordPress infrastructure
- **Simplified Deployment**: No need to deploy and maintain a separate WebSocket server
- **Built-in Security**: Uses WordPress authentication and capabilities
- **Anonymous Support**: Respects WordPress anonymous chat settings
- **Resource Efficient**: Uses transients for temporary data storage
- **Standards Compliant**: Follows WebRTC signaling best practices

### ⚠️ Considerations

- **Scalability**: For very large deployments (100+ concurrent rooms), consider external WebSocket server
- **Keep-Alive**: Relies on SSE which may be terminated by proxies/load balancers (use WebSocket for production at scale)
- **Transient Storage**: Uses WordPress transients which may use database or object cache depending on configuration

## Technical Details

### Security

- **Authentication**: Supports both authenticated WordPress users and anonymous users (if enabled)
- **Capabilities**: Checks WordPress user capabilities for privileged operations
- **Sanitization**: All inputs are sanitized using WordPress sanitization functions
- **Validation**: Peer IDs are validated against regex pattern `[a-zA-Z0-9_-]+`

### Performance

- **Connection Pooling**: SSE connections poll every 2 seconds
- **Heartbeat**: Heartbeat events sent every 15 seconds to keep connections alive
- **Max Duration**: SSE connections auto-close after 5 minutes (reconnect required)
- **Room Capacity**: Maximum 100 peers per room (configurable via `MAX_PEERS_PER_ROOM` constant)

### Browser Compatibility

Self-hosted signaling requires:
- **Server-Sent Events (SSE)**: Supported in all modern browsers
- **WebRTC**: Chrome, Firefox, Safari, Edge
- **HTTPS**: Required for WebRTC media streams

## API Usage Example

### Register Peer

```javascript
const response = await fetch('/wp-json/mcp-ai/v1/webchat/peers/register', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    peer_id: 'peer-abc123',
    room_id: 'room-xyz789',
    user_name: 'Alice'
  })
});

const data = await response.json();
console.log(data.peers); // List of other peers in room
```

### Stream Events

```javascript
const eventSource = new EventSource(
  '/wp-json/mcp-ai/v1/webchat/stream?room_id=room-xyz789&peer_id=peer-abc123'
);

eventSource.addEventListener('signal', (event) => {
  const data = JSON.parse(event.data);
  console.log('Received signaling data:', data);
  // Process WebRTC offer/answer
});

eventSource.addEventListener('ice_candidate', (event) => {
  const data = JSON.parse(event.data);
  console.log('Received ICE candidate:', data);
  // Add ICE candidate to peer connection
});

eventSource.addEventListener('peer_joined', (event) => {
  const data = JSON.parse(event.data);
  console.log('Peer joined:', data.peer_id);
});
```

### Send WebRTC Offer

```javascript
await fetch('/wp-json/mcp-ai/v1/webchat/signal', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    from_peer: 'peer-abc123',
    to_peer: 'peer-xyz456',
    room_id: 'room-xyz789',
    type: 'offer',
    sdp: offerSdp
  })
});
```

## Troubleshooting

### SSE Connection Drops

**Problem**: SSE connection closes unexpectedly

**Solutions**:
- Check proxy/load balancer configuration for SSE support
- Verify PHP `max_execution_time` is sufficient (300+ seconds)
- Check server logs for connection abortion
- Consider using external WebSocket server for production

### Peers Not Discovering Each Other

**Problem**: Peers cannot see each other in room

**Solutions**:
- Verify peer registration is successful (check REST response)
- Check transient storage is working (`wp_mcp_ai_webchat_peer_*` transients)
- Ensure heartbeat requests are being sent regularly
- Verify room_id matches exactly between peers

### High Database Load

**Problem**: Many database queries from transient operations

**Solutions**:
- Enable persistent object cache (Redis, Memcached)
- Consider external WebSocket server for high-traffic sites
- Increase transient TTL if appropriate
- Monitor transient cleanup

## Migration Guide

### From External WebSocket to Self-Hosted

1. Backup existing WebChat room configuration
2. Enable self-hosted signaling in WebChat settings
3. Update WebChat browser extension configuration (if customized)
4. Test with new rooms before migrating existing ones
5. No data migration needed - rooms automatically use new signaling method

### From Self-Hosted to External WebSocket

1. Deploy external WebSocket server
2. Configure WebSocket URL in WebChat settings
3. Disable self-hosted signaling
4. Existing rooms will automatically use external server
5. Monitor WebSocket server logs for connectivity

## Integration with AI Assistants

AI assistants can query signaling configuration:

```
Tool: get_webchat_status

Response:
{
  "settings": {
    "signaling_type": "self-hosted",
    "self_hosted_enabled": true,
    "self_hosted_endpoint": "https://example.com/wp-json/mcp-ai/v1/webchat/",
    "external_signaling_server": "",
    ...
  }
}
```

When creating rooms, the tool automatically uses the configured signaling method:

```
Tool: create_webchat_room
Arguments: {
  "title": "Team Meeting",
  "max_participants": 10
}

Response:
{
  "signaling_type": "self-hosted",
  "signaling_server": "https://example.com/wp-json/mcp-ai/v1/webchat/",
  ...
}
```

## Future Enhancements

- [ ] WebSocket transport option (in addition to SSE)
- [ ] Redis backend for transient storage at scale
- [ ] Connection quality monitoring
- [ ] Automatic failover to external server
- [ ] Turn server configuration UI
- [ ] Room encryption key management
