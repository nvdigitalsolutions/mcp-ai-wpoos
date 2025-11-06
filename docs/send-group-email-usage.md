# Send Group Email Tool - Usage Guide

## Overview

The `send_group_email` tool enables AI assistants to send email campaigns to multiple recipients using WordPress's built-in mail system. It supports both structured (JSON) and plain text formats for defining email content, recipients, and headers.

**Tool Slug:** `send_group_email`

**Requires Capability:** `publish_posts` (default, configurable)

**Maximum Recipients:** 100 (default, configurable)

## Features

- **Flexible Input Formats**: Accepts JSON or plain text email definitions
- **Multiple Attachment Support**: Combine multiple email definition files
- **CC/BCC Support**: Full email header control including carbon copy fields
- **Capability-Based Access Control**: Configurable permission requirements
- **Recipient Limiting**: Prevent abuse with configurable maximum recipient counts
- **Duplicate Detection**: Automatic deduplication of email addresses
- **Custom Headers**: Support for additional custom email headers
- **WordPress Integration**: Uses `wp_mail()` for reliable delivery
- **Filter Hooks**: Extensible via WordPress filters and actions

## Configuration

### Admin Settings

Navigate to **Settings → WP oOS** to configure:

1. **Group Email Capability** (default: `publish_posts`)
   - Controls who can send group emails
   - Options:
     - Any logged-in user (no capability required)
     - `read` - Any authenticated user
     - `edit_posts` - Contributors and above
     - `publish_posts` - Authors and above
     - `edit_pages` - Editors and above
     - `manage_options` - Administrators only

2. **Group Email Recipient Limit** (default: 100)
   - Maximum number of recipients per request
   - Set to `0` to disable the limit
   - Helps prevent abuse and server overload

### Programmatic Configuration

```php
// Filter the required capability
add_filter( 'wp_mcp_ai_send_group_email_capability', function( $capability, $context, $arguments, $tool ) {
    // Require administrator access for emails with more than 50 recipients
    if ( isset( $arguments['recipients'] ) && count( $arguments['recipients'] ) > 50 ) {
        return 'manage_options';
    }
    return $capability;
}, 10, 4 );

// Filter the maximum recipient count
add_filter( 'wp_mcp_ai_send_group_email_max_recipients', function( $max, $context, $arguments, $tool ) {
    // Allow administrators to send to unlimited recipients
    if ( current_user_can( 'manage_options' ) ) {
        return 0; // No limit
    }
    return $max;
}, 10, 4 );
```

## Tool Parameters

### Schema

```json
{
  "type": "object",
  "properties": {
    "subject": {
      "type": "string",
      "description": "Optional subject override for the email"
    },
    "message": {
      "type": "string",
      "description": "Optional message to prepend to the file contents"
    },
    "recipients": {
      "type": "array",
      "description": "Optional list of recipients",
      "items": {
        "oneOf": [
          { "type": "string" },
          {
            "type": "object",
            "properties": {
              "email": { "type": "string" },
              "name": { "type": "string" }
            },
            "required": ["email"]
          }
        ]
      }
    },
    "attachment_id": {
      "type": ["integer", "string"],
      "description": "WordPress attachment ID describing the email payload"
    },
    "attachment_ids": {
      "type": "array",
      "description": "List of WordPress attachment IDs describing email payloads",
      "items": {
        "type": ["integer", "string"]
      }
    },
    "from_email": {
      "type": "string",
      "description": "Optional override for the from email address"
    },
    "from_name": {
      "type": "string",
      "description": "Optional override for the from name"
    },
    "headers": {
      "type": "array",
      "description": "Additional headers to merge into the outgoing message",
      "items": {
        "type": "string"
      }
    }
  },
  "additionalProperties": false
}
```

### Parameter Details

#### Required Parameters

At least one of these must be provided:
- `attachment_id` - Single attachment ID containing email definition
- `attachment_ids` - Array of attachment IDs to combine

#### Optional Parameters

- `subject` - Email subject line (can also be defined in attachment)
- `message` - Additional message content to prepend
- `recipients` - Recipient list (can also be defined in attachment)
- `from_email` - Custom sender email address
- `from_name` - Custom sender name
- `headers` - Array of additional email headers (e.g., `["Reply-To: support@example.com"]`)

## Email Definition Formats

### JSON Format

Upload a JSON file with the following structure:

```json
{
  "subject": "Weekly Newsletter",
  "message": "Hello team,\n\nHere's this week's update...",
  "recipients": [
    "alice@example.com",
    "bob@example.com",
    {
      "email": "charlie@example.com",
      "name": "Charlie Smith"
    }
  ],
  "cc": [
    "manager@example.com"
  ],
  "bcc": [
    "archive@example.com"
  ]
}
```

**JSON Field Aliases:**
- `body` or `content` can be used instead of `message`
- `to` can be used instead of `recipients`

### Plain Text Format

Upload a text file with email-style headers:

```
Subject: Weekly Newsletter
To: alice@example.com, bob@example.com
Cc: manager@example.com
Bcc: archive@example.com

Hello team,

Here's this week's update...

Best regards,
The Team
```

**Plain Text Features:**
- Headers are parsed using `Header: Value` format
- Multiple emails can be separated by commas, semicolons, or spaces
- Everything after headers becomes the message body
- If no headers are found, the entire content is treated as a recipient list

### Email-Only Format

For simple recipient lists, create a text file with just email addresses:

```
alice@example.com
bob@example.com
charlie@example.com
```

## Usage Examples

### Example 1: Basic Group Email

```javascript
// 1. Create a JSON file with email definition
const emailDefinition = {
  "subject": "Team Meeting Tomorrow",
  "message": "Don't forget about our team meeting tomorrow at 10 AM!",
  "recipients": [
    "alice@example.com",
    "bob@example.com",
    "charlie@example.com"
  ]
};

// 2. Upload as attachment (returns attachment_id: 123)

// 3. Send the email
const result = await assistant.callTool('send_group_email', {
  attachment_id: 123
});

// Result:
// {
//   "sent": true,
//   "recipients": ["alice@example.com", "bob@example.com", "charlie@example.com"],
//   "subject": "Team Meeting Tomorrow",
//   "cc": [],
//   "bcc": []
// }
```

### Example 2: Email with Custom Sender

```javascript
const result = await assistant.callTool('send_group_email', {
  attachment_id: 123,
  from_email: "noreply@example.com",
  from_name: "AI Assistant"
});
```

### Example 3: Override Subject and Add Message

```javascript
const result = await assistant.callTool('send_group_email', {
  attachment_id: 123,
  subject: "URGENT: Updated Meeting Time",
  message: "UPDATE: Meeting has been moved to 2 PM."
  // The attachment message will be appended after this
});
```

### Example 4: Combining Multiple Attachments

```javascript
// Upload multiple email definition files
// File 1 (attachment_id: 100): Contains main message
// File 2 (attachment_id: 101): Contains additional recipients
// File 3 (attachment_id: 102): Contains more recipients

const result = await assistant.callTool('send_group_email', {
  attachment_ids: [100, 101, 102]
});

// All recipients are combined and deduplicated
// Messages are concatenated with double line breaks
```

### Example 5: Custom Headers

```javascript
const result = await assistant.callTool('send_group_email', {
  attachment_id: 123,
  headers: [
    "Reply-To: support@example.com",
    "X-Priority: 1",
    "X-Mailer: WP oOS AI Assistant"
  ]
});
```

### Example 6: Direct Recipients (No Attachment)

```javascript
const result = await assistant.callTool('send_group_email', {
  subject: "Quick Update",
  message: "This is a quick message without using an attachment file.",
  recipients: [
    "alice@example.com",
    "bob@example.com"
  ],
  // Still requires a dummy attachment due to validation
  attachment_id: 123 // Can be a minimal JSON file: {}
});
```

## Security Features

### Input Sanitization

All inputs are sanitized to prevent security issues:

1. **Email Addresses**: Validated using `sanitize_email()`
2. **Subject Lines**: Sanitized using `sanitize_text_field()`
3. **Messages**: Sanitized using `wp_kses_post()` to allow safe HTML
4. **Headers**: Strictly validated to prevent header injection
   - Only valid header names (alphanumeric and hyphens)
   - No newline characters allowed
   - Control characters stripped from header values

### Attachment Access Control

Before processing, the tool verifies:
1. Attachment exists and is accessible
2. User has permission to access the attachment
3. File size is within limits (default: 1 MB, filterable)
4. File is readable

### Capability Checks

- **User Authentication**: Requires valid user_id in context
- **Capability Validation**: User must have configured capability
- **Multisite Support**: Validates user is member of current site
- **Per-Request Filtering**: Capability can be adjusted per request via filters

### Recipient Limits

- **Maximum Recipients**: Enforced to prevent abuse
- **Deduplication**: Email addresses are automatically deduplicated
- **Case-Insensitive Matching**: Prevents duplicates with different cases
- **CC/BCC Filtering**: Recipients in CC/BCC are excluded from TO field

## Advanced Usage

### Workflow: AI-Generated Newsletter

```javascript
// Step 1: AI generates newsletter content
const content = await assistant.generateContent({
  prompt: "Create a weekly newsletter about recent blog posts"
});

// Step 2: Create email definition
const emailDef = {
  subject: "Weekly Newsletter - " + new Date().toISOString().split('T')[0],
  message: content,
  recipients: await getSubscriberEmails() // From database
};

// Step 3: Upload as attachment
const attachmentId = await uploadJSON(emailDef);

// Step 4: Send email
const result = await assistant.callTool('send_group_email', {
  attachment_id: attachmentId,
  from_name: "Newsletter Team"
});
```

### Workflow: Multi-Segment Campaign

```javascript
// Send different messages to different segments
const segments = [
  { name: "Premium", file: premiumAttachmentId },
  { name: "Free", file: freeAttachmentId },
  { name: "Trial", file: trialAttachmentId }
];

for (const segment of segments) {
  const result = await assistant.callTool('send_group_email', {
    attachment_id: segment.file,
    subject: `Special Offer for ${segment.name} Members`
  });
  
  console.log(`Sent to ${result.recipients.length} ${segment.name} members`);
}
```

### Using Pre-send Hook for Testing

```php
// Intercept emails during testing
add_filter( 'wp_mcp_ai_send_group_email_pre_send', function( $pre_send, $mail_args, $arguments, $context ) {
    // Log instead of sending
    error_log( 'Would send email to: ' . implode( ', ', $mail_args['to'] ) );
    error_log( 'Subject: ' . $mail_args['subject'] );
    error_log( 'Message: ' . $mail_args['message'] );
    
    // Return true to skip actual sending
    return true;
}, 10, 4 );
```

## Filter and Action Hooks

### Filters

#### `wp_mcp_ai_send_group_email_capability`
Modify the required capability for sending group emails.

```php
add_filter( 'wp_mcp_ai_send_group_email_capability', function( $capability, $context, $arguments, $tool ) {
    // Your custom logic
    return $capability;
}, 10, 4 );
```

**Parameters:**
- `$capability` (string) - Default capability requirement
- `$context` (array) - Execution context with user_id
- `$arguments` (array) - Tool arguments
- `$tool` (WP_MCP_AI_Tool_Send_Group_Email) - Tool instance

#### `wp_mcp_ai_send_group_email_max_recipients`
Adjust the maximum recipient limit.

```php
add_filter( 'wp_mcp_ai_send_group_email_max_recipients', function( $max, $context, $arguments, $tool ) {
    // Your custom logic
    return $max;
}, 10, 4 );
```

#### `wp_mcp_ai_send_group_email_mail_args`
Modify mail arguments before sending.

```php
add_filter( 'wp_mcp_ai_send_group_email_mail_args', function( $mail_args, $arguments, $context, $email_request, $tool ) {
    // Modify $mail_args['to'], ['subject'], ['message'], ['headers'], ['attachments']
    return $mail_args;
}, 10, 5 );
```

**Parameters:**
- `$mail_args` (array) - Array with 'to', 'subject', 'message', 'headers', 'attachments'
- `$arguments` (array) - Original tool arguments
- `$context` (array) - Execution context
- `$email_request` (array) - Parsed email data with 'recipients', 'cc', 'bcc'
- `$tool` (WP_MCP_AI_Tool_Send_Group_Email) - Tool instance

#### `wp_mcp_ai_send_group_email_pre_send`
Intercept or prevent email sending.

```php
add_filter( 'wp_mcp_ai_send_group_email_pre_send', function( $pre_send, $mail_args, $arguments, $context, $email_request, $tool ) {
    // Return null to proceed with normal sending
    // Return true to skip sending but report success
    // Return WP_Error to report failure
    return $pre_send;
}, 10, 6 );
```

#### `wp_mcp_ai_email_definition_attachment_max_bytes`
Adjust maximum attachment file size.

```php
add_filter( 'wp_mcp_ai_email_definition_attachment_max_bytes', function( $max_bytes, $attachment_id, $tool ) {
    // Default is 1 MB (1024 * 1024)
    return 2 * 1024 * 1024; // 2 MB
}, 10, 3 );
```

### Actions

#### `wp_mcp_ai_send_group_email_after_send`
Triggered after successful email sending.

```php
add_action( 'wp_mcp_ai_send_group_email_after_send', function( $mail_args, $arguments, $context, $email_request, $tool ) {
    // Log successful sends
    error_log( sprintf(
        'Group email sent to %d recipients by user %d',
        count( $mail_args['to'] ),
        $context['user_id']
    ) );
}, 10, 5 );
```

**Parameters:**
- `$mail_args` (array) - Final mail arguments used
- `$arguments` (array) - Original tool arguments
- `$context` (array) - Execution context
- `$email_request` (array) - Parsed email data
- `$tool` (WP_MCP_AI_Tool_Send_Group_Email) - Tool instance

## Error Handling

### Common Errors

| Error Code | Description | Resolution |
|------------|-------------|------------|
| `wp_mcp_ai_forbidden` | User lacks required capability | Grant appropriate capability or adjust settings |
| `wp_mcp_ai_wrong_site` | User not member of multisite blog | Ensure user has access to the site |
| `wp_mcp_ai_missing_attachment` | No attachment provided | Provide `attachment_id` or `attachment_ids` |
| `wp_mcp_ai_invalid_attachment` | Attachment ID invalid or not accessible | Verify attachment exists and is accessible |
| `wp_mcp_ai_attachment_forbidden` | User can't access attachment | Check attachment permissions |
| `wp_mcp_ai_missing_file` | Attachment file not found on disk | Re-upload the attachment |
| `wp_mcp_ai_attachment_too_large` | File exceeds size limit | Reduce file size or adjust limit |
| `wp_mcp_ai_unreadable_file` | File cannot be read | Check file permissions |
| `wp_mcp_ai_missing_recipients` | No recipients specified | Add recipients to file or parameters |
| `wp_mcp_ai_recipient_limit_exceeded` | Too many recipients | Reduce recipient count or adjust limit |
| `wp_mcp_ai_missing_subject` | Subject not found | Add subject to file or parameters |
| `wp_mcp_ai_missing_message` | Message not found | Add message to file or parameters |
| `wp_mcp_ai_invalid_mail_args` | Mail args invalid after filtering | Check mail args filter implementation |
| `wp_mcp_ai_mail_failed` | `wp_mail()` returned false | Check server mail configuration |

### Error Response Format

```json
{
  "error": {
    "code": "wp_mcp_ai_forbidden",
    "message": "You do not have permission to send group emails.",
    "data": {
      "status": 403
    }
  }
}
```

## Performance Considerations

### Attachment File Size

- Default limit: 1 MB per attachment
- Large files increase memory usage and processing time
- Use `wp_mcp_ai_email_definition_attachment_max_bytes` filter to adjust

### Recipient Count

- More recipients = longer processing time
- WordPress `wp_mail()` sends to all recipients at once
- Consider using queue plugins for very large lists
- Respect server resource limits

### Multisite

- Each site has independent settings
- Attachment access is site-specific
- User capabilities are site-specific

## Testing

The tool includes comprehensive test coverage:

```bash
# Run all tests
composer run test

# Run only send_group_email tests
vendor/bin/phpunit tests/test-send-group-email-tool.php
```

### Test Coverage

- ✅ Permission requirements
- ✅ Attachment access control
- ✅ JSON format parsing
- ✅ Plain text format parsing
- ✅ Capability configuration
- ✅ Recipient limiting
- ✅ Header injection prevention
- ✅ Attachment size limits
- ✅ Deduplication
- ✅ CC/BCC handling
- ✅ Filter hooks
- ✅ Custom headers

## Troubleshooting

### Email Not Sending

1. **Check WordPress Mail Configuration**
   ```php
   // Test basic wp_mail()
   wp_mail( 'test@example.com', 'Test', 'Test message' );
   ```

2. **Enable Debug Logging**
   - Set `WP_MCP_AI_DEBUG` constant to true
   - Check recent errors in admin settings

3. **Verify SMTP Configuration**
   - Install SMTP plugin if needed
   - Verify SMTP credentials

### Recipients Not Receiving

1. **Check Spam Folders**
2. **Verify Email Addresses**
   - Must be valid format
   - Must pass `sanitize_email()`
3. **Check Server Logs**
   - PHP error log
   - Mail server logs

### Attachment Not Found

1. **Verify Attachment ID**
   ```php
   $attachment = get_post( $attachment_id );
   var_dump( $attachment );
   ```

2. **Check File Path**
   ```php
   $file_path = get_attached_file( $attachment_id );
   var_dump( file_exists( $file_path ) );
   ```

3. **Verify Permissions**
   - File must be readable by web server
   - User must have access to attachment

## Best Practices

1. **Always Test First**
   - Use pre_send filter during development
   - Test with small recipient lists
   - Verify message formatting

2. **Respect User Privacy**
   - Use BCC for mass emails to hide recipient lists
   - Comply with email marketing regulations (CAN-SPAM, GDPR)
   - Provide unsubscribe mechanisms

3. **Monitor Performance**
   - Log successful sends
   - Track failures
   - Monitor server resources

4. **Use Appropriate Limits**
   - Set reasonable recipient limits
   - Implement rate limiting if needed
   - Consider batch processing for large lists

5. **Handle Errors Gracefully**
   - Log errors for debugging
   - Provide helpful error messages
   - Implement retry logic if appropriate

## Related Tools

- **Send Mailjet Email** (`send_mailjet_email`) - Transactional email via Mailjet API
- **Send Telegram Message** (`send_telegram_message`) - Telegram notifications
- **Send WhatsApp Message** (`send_whatsapp_message`) - WhatsApp messaging
- **Schedule Notify.lk SMS** (`schedule_notify_sms`) - SMS notifications

## Support

For issues or questions:

1. Check [plugin documentation](../README.md)
2. Review [troubleshooting guide](../docs/deployment-troubleshooting.md)
3. Submit [GitHub issues](https://github.com/nvdigitalsolutions/wp-mcp-ai/issues)

## License

This tool is part of WP Open Operator System (WP oOS) and is licensed under GPLv3 or later.
