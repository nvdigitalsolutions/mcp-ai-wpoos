# Chat History Persistence

## Overview

The WP oOS chat interface now automatically persists chat conversations to the browser's localStorage, preventing data loss when users navigate away from the page or refresh it. This feature works seamlessly with the existing JetEngine storage backend.

## How It Works

### Automatic Persistence

The chat conversation state is automatically saved to localStorage in the following scenarios:

1. **After user sends a message** - The conversation is immediately saved when the user submits a message
2. **After assistant responds** - Once the assistant's response is received and processed, the conversation is saved again
3. **When loading from history** - If a user loads a previous conversation from the history panel, that conversation is also saved to localStorage

### Storage Structure

The conversation data is stored with the following structure:

```javascript
{
  conversation: [
    { role: 'user', content: 'Hello' },
    { role: 'assistant', content: 'Hi there!' }
    // ... more messages
  ],
  sessionKey: 'unique-session-identifier',
  timestamp: 1699000000000,
  assistantId: 123
}
```

### Storage Keys

Each assistant's conversation is stored separately using a unique key:
- Format: `wp_mcp_ai_chat_{assistantId}`
- Example: `wp_mcp_ai_chat_123`

This means users can have different conversations with different assistants, and each will be preserved independently.

## Automatic Restoration

When a user returns to a chat page:

1. The chat interface checks localStorage for saved conversation data
2. If valid data exists (not expired, matches current assistant), it's automatically loaded
3. All messages are rendered in the UI exactly as they appeared before
4. The conversation context is restored, allowing users to continue where they left off

## Data Expiration

To prevent stale data from accumulating:

- **Expiry Period**: 24 hours (configurable in code)
- **Automatic Cleanup**: Expired conversations are automatically removed when accessed
- **Per-Assistant**: Each assistant's conversation expires independently

## Integration with JetEngine Storage

This localStorage persistence works **in addition to** the existing JetEngine storage:

- **JetEngine**: Provides server-side permanent storage for transcripts
- **localStorage**: Provides client-side temporary storage for active conversations
- **Complementary**: Both systems work together to provide the best user experience

### Flow Diagram

```
User sends message
       ↓
Saved to localStorage (immediate)
       ↓
Sent to server/assistant
       ↓
Assistant responds
       ↓
Response saved to localStorage
       ↓
If save_transcript=true → Saved to JetEngine
```

## Browser Compatibility

localStorage is supported in all modern browsers:
- Chrome/Edge 4+
- Firefox 3.5+
- Safari 4+
- Opera 10.5+
- Internet Explorer 8+

The implementation gracefully handles browsers without localStorage support by silently failing.

## Privacy & Security

### Data Location
- All localStorage data is stored locally in the user's browser
- Data is **not** transmitted to other users or systems
- Each user only sees their own conversation history

### Data Scope
- localStorage is scoped to the domain (e.g., `example.com`)
- Data persists across browser sessions (until expired or cleared)
- Users can manually clear localStorage through browser settings

### Sensitive Information
- Avoid sharing sensitive information in chats on public/shared computers
- Users should clear browser data when using public devices
- Consider implementing additional encryption for highly sensitive data

## Manual Control

### Clearing Conversation Data

Users or developers can clear conversation data:

```javascript
// Clear specific assistant's conversation
localStorage.removeItem('wp_mcp_ai_chat_123');

// Clear all WP oOS conversations
Object.keys(localStorage).forEach(key => {
    if (key.startsWith('wp_mcp_ai_chat_')) {
        localStorage.removeItem(key);
    }
});
```

### Developer Console

For debugging, developers can inspect the stored data:

```javascript
// View stored conversation
const data = JSON.parse(localStorage.getItem('wp_mcp_ai_chat_123'));
console.log(data);
```

## Troubleshooting

### Conversation Not Restoring

**Check these common issues:**

1. **Different Assistant**: Ensure you're on the same assistant page
2. **Expired Data**: Conversation may have expired (>24 hours old)
3. **Browser Cache Cleared**: User may have cleared browser data
4. **Private/Incognito Mode**: localStorage may not persist in private browsing

### Storage Quota Exceeded

If localStorage quota is exceeded:
- The save operation silently fails
- Older conversations should be manually cleared
- Consider reducing the conversation history length

### Browser Console Errors

Enable browser console to see any localStorage errors:
```
F12 → Console tab
```

Look for errors containing "localStorage" or "quota"

## Code Reference

The localStorage persistence is implemented in `/assets/js/chat.js`:

- `saveConversationToStorage(state)` - Saves conversation to localStorage
- `loadConversationFromStorage(state)` - Loads conversation from localStorage
- `clearConversationFromStorage(state)` - Removes conversation from localStorage
- `restoreConversationFromStorage(state)` - Restores UI from saved conversation

## Future Enhancements

Potential improvements for future versions:

1. **Configurable Expiry**: Allow admins to set custom expiration periods
2. **Compression**: Compress conversation data to save space
3. **IndexedDB**: Use IndexedDB for larger storage capacity
4. **Sync Indicator**: Show users when conversation is saved/loaded
5. **Manual Save/Load**: Provide UI buttons for explicit save/load actions
6. **Export/Import**: Allow users to export/import conversations as JSON

## Testing

You can test the localStorage functionality using your browser's Developer Console:

```javascript
// Create a test conversation
var testState = {
    config: { assistantId: 999, sessionKey: 'test-session' },
    conversation: [
        { role: 'user', content: 'Test message' },
        { role: 'assistant', content: 'Test response' }
    ]
};

// Save it
saveConversationToStorage(testState);

// Load it back
var loaded = loadConversationFromStorage(testState);
console.log('Loaded:', loaded);

// Clear it
clearConversationFromStorage(testState);
```

Or manually inspect localStorage:

```javascript
// View all saved conversations
Object.keys(localStorage).forEach(key => {
    if (key.startsWith('wp_mcp_ai_chat_')) {
        console.log(key, localStorage.getItem(key));
    }
});
```
