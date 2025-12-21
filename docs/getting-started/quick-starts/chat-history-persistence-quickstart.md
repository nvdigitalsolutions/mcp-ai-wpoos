# Chat History Persistence - Quick Start Guide

## What's New

Chat conversations are now automatically saved to your browser and restored when you return to the page, preventing data loss from page refreshes or navigation.

## For Users

### How to Use

**No action required!** The feature works automatically:

1. **Start a conversation** with any assistant
2. **Navigate away** or **refresh the page**
3. **Return to the chat** - your conversation will be right where you left it

### What Gets Saved

- All user messages
- All assistant responses
- Tool execution results
- Session context

### How Long Does It Last

Conversations are saved for **24 hours**. After that, they automatically expire to keep your browser storage clean.

### Privacy Notes

- Conversations are stored **only in your browser**
- Each user sees only their own conversations
- Clearing browser data will remove saved conversations
- Use private/incognito mode if you don't want conversations saved

## For Developers

### Testing the Feature

1. **Open a chat page** with an assistant
2. **Send a few messages** to build up a conversation
3. **Refresh the page** - conversation should restore
4. **Check localStorage** in browser DevTools:
   ```javascript
   // View saved data
   localStorage.getItem('wp_mcp_ai_chat_123'); // Replace 123 with assistant ID
   ```

### Storage Key Format

```
wp_mcp_ai_chat_{assistantId}
```

Example keys:
- `wp_mcp_ai_chat_123` - Conversation with assistant ID 123
- `wp_mcp_ai_chat_456` - Conversation with assistant ID 456

### Manual Operations

**Clear a specific conversation:**
```javascript
localStorage.removeItem('wp_mcp_ai_chat_123');
```

**Clear all conversations:**
```javascript
Object.keys(localStorage).forEach(key => {
    if (key.startsWith('wp_mcp_ai_chat_')) {
        localStorage.removeItem(key);
    }
});
```

**Inspect conversation data:**
```javascript
const data = JSON.parse(localStorage.getItem('wp_mcp_ai_chat_123'));
console.log('Conversation:', data.conversation);
console.log('Session Key:', data.sessionKey);
console.log('Saved at:', new Date(data.timestamp));
```

### Code Hooks

The following functions are available in `/assets/js/chat.js`:

```javascript
// Save current conversation
saveConversationToStorage(state);

// Load saved conversation
var saved = loadConversationFromStorage(state);

// Clear saved conversation
clearConversationFromStorage(state);

// Restore UI from saved conversation (called automatically)
restoreConversationFromStorage(state);
```

### Configuration

**Change expiry time** (edit `/assets/js/chat.js`):
```javascript
var STORAGE_EXPIRY_MS = 24 * 60 * 60 * 1000; // Change to desired milliseconds
```

### Debugging

**Enable browser console** to see localStorage operations:

1. Press `F12` to open DevTools
2. Go to **Console** tab
3. Look for any localStorage errors
4. Check **Application** → **Local Storage** to view stored data

**Common issues:**

- **Quota exceeded**: User's localStorage is full
- **Private browsing**: Some browsers don't persist localStorage in private mode
- **Different assistant**: Make sure assistant ID matches

## Integration with JetEngine

This feature works **alongside** JetEngine storage:

| Feature | localStorage | JetEngine |
|---------|-------------|-----------|
| **Storage Location** | Browser | Server database |
| **Duration** | 24 hours | Permanent |
| **Access** | Current user only | All authorized users |
| **Purpose** | Prevent data loss on refresh | Long-term transcript archive |
| **Automatic** | Yes | Yes (if `save_transcript=true`) |

Both systems work together:
1. User sends message → Saved to localStorage
2. Assistant responds → Saved to localStorage
3. If enabled → Also saved to JetEngine for permanent storage

## Troubleshooting

### Conversation Not Restoring

**Check:**
- Are you on the same assistant page?
- Has more than 24 hours passed?
- Did you clear browser data?
- Are you in private/incognito mode?

**Solution:**
```javascript
// Check if data exists
const key = 'wp_mcp_ai_chat_123'; // Your assistant ID
const data = localStorage.getItem(key);
console.log('Saved data:', data ? 'Found' : 'Not found');

if (data) {
    const parsed = JSON.parse(data);
    const age = Date.now() - parsed.timestamp;
    console.log('Age (hours):', age / (60 * 60 * 1000));
}
```

### Storage Quota Error

**Symptom:** Console shows "QuotaExceededError"

**Solution:**
```javascript
// Clear old conversations
Object.keys(localStorage).forEach(key => {
    if (key.startsWith('wp_mcp_ai_chat_')) {
        const data = JSON.parse(localStorage.getItem(key));
        const age = Date.now() - data.timestamp;
        
        // Remove if older than 12 hours
        if (age > 12 * 60 * 60 * 1000) {
            localStorage.removeItem(key);
        }
    }
});
```

### Conversation Restored Incorrectly

**Check data integrity:**
```javascript
const data = JSON.parse(localStorage.getItem('wp_mcp_ai_chat_123'));

// Verify structure
console.log('Has conversation array:', Array.isArray(data.conversation));
console.log('Message count:', data.conversation.length);
console.log('Assistant ID matches:', data.assistantId === 123);

// Inspect messages
data.conversation.forEach((msg, i) => {
    console.log(`Message ${i}:`, msg.role, msg.content.substring(0, 50));
});
```

## Support

For detailed technical documentation, see:
- [docs/chat-history-persistence.md](guides/user/chat/chat-history-persistence.md)

For issues or questions:
- Check browser console for errors
- Verify localStorage is enabled in browser settings
- Test in a different browser
- Clear browser cache and try again
