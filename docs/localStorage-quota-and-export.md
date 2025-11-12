# localStorage Quota Monitoring and Conversation Export

## Overview

This document describes the localStorage quota monitoring and conversation export features added to WP Open Operator System (WP oOS).

## Features

### 1. localStorage Quota Monitoring

#### Description
Real-time monitoring of browser localStorage usage with visual indicators and warnings.

#### Implementation
- **File**: `assets/js/chat.js`
- **Function**: `getLocalStorageQuota()`, `updateQuotaMonitor()`
- **CSS**: `assets/css/chat.css` (`.wp-mcp-ai-chat__quota-monitor`)

#### Usage
The quota monitor automatically displays when the following element exists in the chat widget:

```html
<div class="wp-mcp-ai-chat__quota-monitor"></div>
```

#### Features
- **Automatic Updates**: Updates every 30 seconds
- **Color-coded Status**:
  - Green (OK): 0-74% used
  - Orange (Warning): 75-89% used
  - Red (Critical): 90-100% used
- **Detailed Tooltip**: Hover for breakdown of WP oOS vs. total storage

#### Storage Calculation
- Estimates total quota at 5MB (conservative browser standard)
- Tracks total localStorage usage
- Separates WP oOS chat data from other localStorage data
- Uses prefix `wp_mcp_ai_chat_` for identification

### 2. Conversation Export

#### Description
Export chat conversations in multiple formats for backup, sharing, or archival purposes.

#### Implementation
- **File**: `assets/js/chat.js`
- **Function**: `exportConversation()`, `handleExportConversation()`, `downloadFile()`

#### Supported Formats

##### JSON Format
- **Filename**: `chat-{assistant_id}-{timestamp}.json`
- **MIME Type**: `application/json`
- **Contents**:
  ```json
  {
    "assistant_id": "123",
    "session_key": "wp-mcp-ai-session-abc123",
    "exported_at": "2025-01-01T12:00:00.000Z",
    "messages": [
      {
        "role": "user",
        "content": "Hello"
      },
      {
        "role": "assistant",
        "content": "Hi there!"
      }
    ]
  }
  ```

##### Markdown Format
- **Filename**: `chat-{assistant_id}-{timestamp}.md`
- **MIME Type**: `text/markdown`
- **Contents**:
  ```markdown
  # Chat Conversation
  
  **Assistant ID:** 123
  **Session Key:** wp-mcp-ai-session-abc123
  **Exported:** 1/1/2025, 12:00:00 PM
  
  ---
  
  ## User
  
  Hello
  
  ## Assistant
  
  Hi there!
  ```

##### Plain Text Format
- **Filename**: `chat-{assistant_id}-{timestamp}.txt`
- **MIME Type**: `text/plain`
- **Contents**:
  ```
  Chat Conversation
  
  Assistant ID: 123
  Session Key: wp-mcp-ai-session-abc123
  Exported: 1/1/2025, 12:00:00 PM
  
  ----------------------------------------
  
  USER:
  Hello
  
  ASSISTANT:
  Hi there!
  ```

#### Usage

Add an export button to the chat widget:

```html
<button class="wp-mcp-ai-chat__export">Export Conversation</button>
```

The button will:
1. Check if there's a conversation to export
2. Prompt user for format (json/markdown/text)
3. Generate the export file
4. Trigger browser download
5. Display success message

## API Reference

### JavaScript Functions

#### `getLocalStorageQuota()`

Returns object with localStorage usage statistics:

```javascript
{
  used: 12345,              // Total bytes used
  wpMcpAiUsed: 5678,       // Bytes used by WP oOS
  total: 5242880,          // Total estimated quota (5MB)
  percentage: 25.5,        // Percentage used
  available: true,         // Whether localStorage is available
  formattedUsed: "12.1 KB",
  formattedWpMcpAiUsed: "5.5 KB",
  formattedTotal: "5 MB"
}
```

#### `exportConversation(state, format)`

Exports conversation in specified format.

**Parameters:**
- `state` (Object): Chat state object
- `format` (String): Export format - 'json', 'markdown', or 'text'

**Returns:**
```javascript
{
  success: true,
  content: "...",          // Export content
  filename: "chat-123-2025-01-01.json",
  mimeType: "application/json"
}
```

#### `downloadFile(content, filename, mimeType)`

Triggers browser download of file.

**Parameters:**
- `content` (String): File content
- `filename` (String): Download filename
- `mimeType` (String): File MIME type

## Configuration

### Quota Monitor Update Interval

The default update interval is 30 seconds. To change:

```javascript
// In assets/js/chat.js, find:
setInterval(function() {
    updateQuotaMonitor(quotaMonitor);
}, 30000); // Change this value (in milliseconds)
```

### Storage Quota Estimate

The default estimated quota is 5MB. To change:

```javascript
// In getLocalStorageQuota() function:
const estimatedQuota = 5 * 1024 * 1024; // Change this value
```

### Warning Thresholds

Default thresholds for quota warnings:

- **Warning** (orange): 75%
- **Critical** (red): 90%

To change thresholds, modify the `updateQuotaMonitor()` function:

```javascript
if (percentage >= 90) {
    statusClass = 'wp-mcp-ai-chat__quota-critical';
} else if (percentage >= 75) {  // Change this threshold
    statusClass = 'wp-mcp-ai-chat__quota-warning';
}
```

## Browser Compatibility

### localStorage Quota
- **Chrome/Edge**: 10MB per origin
- **Firefox**: 10MB per origin
- **Safari**: 5MB per origin (iOS may be less)
- **IE**: 5-10MB depending on version

The implementation uses a conservative 5MB estimate to work across all browsers.

### File Downloads
- Modern browsers: Uses Blob API and `URL.createObjectURL()`
- Fallback: None required - all target browsers support Blob API

## Security Considerations

### Data Privacy
- **localStorage**: Data is stored in plain text in browser localStorage
- **No Encryption**: Conversation data is not encrypted at rest
- **Same-Origin**: Data is accessible only from same domain
- **User-Controlled**: Users can clear localStorage at any time

### Export Security
- **Client-Side Only**: Export happens entirely in browser
- **No Server Upload**: Export files are not sent to server
- **User Initiated**: Export requires explicit user action
- **Format Validation**: Export format is validated before processing

## Troubleshooting

### Quota Monitor Not Showing

1. Verify element exists: `<div class="wp-mcp-ai-chat__quota-monitor"></div>`
2. Check console for JavaScript errors
3. Ensure localStorage is available (check browser settings)

### Export Not Working

1. Check console for JavaScript errors
2. Verify conversation data exists
3. Test browser's download capability
4. Check popup blocker settings

### Quota Shows 0%

1. Browser may not support localStorage
2. Private/Incognito mode may limit localStorage
3. Browser storage may be disabled
4. Check browser console for errors

## Best Practices

### For Users

1. **Regular Exports**: Export important conversations before clearing browser data
2. **Monitor Quota**: Watch for warnings and export/delete old conversations
3. **Format Choice**: Use JSON for data portability, Markdown for readability
4. **Browser Limits**: Be aware of browser localStorage limits

### For Developers

1. **Test Across Browsers**: Different browsers have different limits
2. **Handle Errors Gracefully**: localStorage can fail for many reasons
3. **Provide Feedback**: Always inform users of success/failure
4. **Respect Privacy**: localStorage is not secure storage

## Future Enhancements

Potential improvements for future versions:

1. **Bulk Export**: Export multiple sessions at once
2. **Cloud Backup**: Optional cloud storage integration
3. **Encrypted Storage**: Client-side encryption for localStorage
4. **Import Conversations**: Import previously exported conversations
5. **PDF Export**: Generate formatted PDF exports
6. **Email Integration**: Email conversations directly from chat
7. **Selective Export**: Choose specific messages to export
8. **Template Exports**: Customizable export templates

## Related Documentation

- [Storage Management](./storage-management.md)
- [Session Management](./session-management.md)
- [REST API Documentation](./rest-api.md)
- [Frontend Architecture](./frontend-architecture.md)
