# OpenPhone Integration Tool Usage Guide

## Overview

The OpenPhone tool (`send_openphone_message`) enables AI assistants to send SMS messages via the OpenPhone (Quo) API. This tool supports intelligent message routing, multi-recipient messaging, and seamless integration with WordPress workflows.

## Prerequisites

1. **OpenPhone Account**: An active OpenPhone (now called Quo) account
2. **API Key**: Generate an API key from your OpenPhone workspace settings (requires Admin/Owner permissions)
3. **Phone Number**: At least one OpenPhone number configured in your account
4. **WordPress Capability**: User must have `manage_options` capability (configurable via filter)

## Basic Usage

### Sending a Simple Message

```json
{
  "tool": "send_openphone_message",
  "arguments": {
    "api_key": "your_openphone_api_key",
    "from": "+15555555555",
    "to": ["+15555555556"],
    "content": "Hello from WordPress! This message was sent via the OpenPhone API."
  }
}
```

### Sending to Multiple Recipients

```json
{
  "tool": "send_openphone_message",
  "arguments": {
    "api_key": "your_openphone_api_key",
    "from": "+15555555555",
    "to": [
      "+15555555556",
      "+15555555557",
      "+15555555558"
    ],
    "content": "Team notification: New order received!"
  }
}
```

### Sending as a Specific User

```json
{
  "tool": "send_openphone_message",
  "arguments": {
    "api_key": "your_openphone_api_key",
    "from": "+15555555555",
    "to": ["+15555555556"],
    "content": "This message is sent as a specific OpenPhone user.",
    "user_id": "openphone_user_id_here"
  }
}
```

## Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `api_key` | string | Yes | Your OpenPhone API key |
| `from` | string | Yes | Sender phone number in E.164 format (e.g., `+15555555555`) |
| `to` | array | Yes | Array of recipient phone numbers in E.164 format |
| `content` | string | Yes | Message content to send |
| `user_id` | string | No | OpenPhone user ID to send the message as |

## Phone Number Format

All phone numbers must be in **E.164 format**:
- Start with `+` (plus sign)
- Include country code
- No spaces, dashes, or other formatting

**Valid Examples:**
- `+15555555555` (US/Canada)
- `+442071234567` (UK)
- `+61412345678` (Australia)

**Invalid Examples:**
- `5555555555` (missing country code and +)
- `+1 (555) 555-5555` (contains formatting - will be sanitized automatically)
- `555-5555` (incomplete number)

## Security & Capabilities

### Default Capability Requirement

By default, the tool requires `manage_options` capability, restricting usage to administrators.

### Custom Capability via Filter

You can customize the required capability:

```php
add_filter( 'wp_mcp_ai_send_openphone_message_capability', function() {
    return 'edit_posts'; // Allow editors to send messages
} );
```

### Multisite Support

The tool validates that users have access to the current site in multisite installations.

### API Key Security

- API keys are sanitized and trimmed
- Keys are never logged in plain text
- Sensitive values are masked in logs (e.g., `+1******55`)

## Error Handling

### Common Errors

| Error Code | Description | Solution |
|------------|-------------|----------|
| `wp_mcp_ai_forbidden` | User lacks required capability | Grant proper capability or adjust filter |
| `wp_mcp_ai_missing_openphone_api_key` | API key not provided | Include valid API key |
| `wp_mcp_ai_missing_openphone_from` | Sender number not provided | Include valid E.164 phone number |
| `wp_mcp_ai_missing_openphone_to` | No recipients provided | Include at least one recipient |
| `wp_mcp_ai_invalid_openphone_recipients` | All recipients invalid | Check E.164 format |
| `wp_mcp_ai_missing_openphone_content` | Message content empty | Include message text |
| `wp_mcp_ai_openphone_http_error` | API request failed | Check network connectivity |
| `wp_mcp_ai_openphone_api_error` | OpenPhone API returned error | Check API key, credits, and carrier registration |

### API Response Errors

The OpenPhone API may return errors for:
- Invalid API key
- Insufficient prepaid credits
- Missing carrier registration (for US numbers)
- Invalid phone number format
- Rate limiting

## Integration Examples

### Order Notification Workflow

```php
// When a new WooCommerce order is placed
add_action( 'woocommerce_new_order', function( $order_id ) {
    $order = wc_get_order( $order_id );
    
    // Use AI assistant to send notification
    $result = WP_MCP_AI_Tool_Registry::get_instance()->execute_tool(
        'send_openphone_message',
        array(
            'api_key' => get_option( 'openphone_api_key' ),
            'from' => '+15555555555',
            'to' => array( '+15555555556' ), // Store owner
            'content' => sprintf(
                'New order #%d from %s. Total: %s',
                $order->get_order_number(),
                $order->get_billing_first_name(),
                $order->get_formatted_order_total()
            ),
        ),
        array( 'user_id' => get_current_user_id() )
    );
} );
```

### AI-Powered Customer Service

AI assistants can use this tool to:
- Send appointment reminders
- Notify customers about order status
- Send delivery notifications
- Respond to customer inquiries via SMS
- Send promotional messages

### Multi-Channel Communication

Combine with other messaging tools:

```javascript
// AI can intelligently choose the best channel
{
  "strategy": "multi-channel",
  "primary": "send_openphone_message",
  "fallback": "send_telegram_message",
  "last_resort": "send_group_email"
}
```

## Rate Limiting

The OpenPhone API has rate limits. The tool includes the `rate-limited` capability flag to help orchestration systems manage concurrent requests.

### Timeout Configuration

Default timeout is 20 seconds. Customize via filter:

```php
add_filter( 'wp_mcp_ai_send_openphone_message_timeout', function() {
    return 30; // 30 seconds
} );
```

## Logging & Debugging

The tool logs all requests and responses via `WP_MCP_AI_Logger`:

- **Success**: Logs message sent with masked phone numbers
- **Failure**: Logs error details with HTTP status codes
- **Event Type**: `openphone_send_message_request`

Enable logging in **Settings → WP oOS → Enable Logging** to view detailed activity.

## Best Practices

1. **Store API Keys Securely**: Use WordPress options or environment variables
2. **Validate Numbers**: Always use E.164 format
3. **Rate Limiting**: Implement delays between bulk messages
4. **Error Handling**: Check return values and handle errors gracefully
5. **User Consent**: Ensure recipients have opted in to receive messages
6. **Carrier Registration**: US numbers require carrier registration for SMS
7. **Monitor Credits**: OpenPhone uses prepaid credits (pay-per-use model)

## Carrier Registration (US Numbers)

For sending to US phone numbers, you must:
1. Register with US carriers through OpenPhone
2. Comply with TCPA regulations
3. Maintain an opt-out mechanism
4. Include sender identification

See OpenPhone's documentation for carrier registration requirements.

## Advanced Configuration

### Storing API Keys

```php
// In wp-config.php
define( 'OPENPHONE_API_KEY', 'your_api_key_here' );

// In your code
$api_key = defined( 'OPENPHONE_API_KEY' ) ? OPENPHONE_API_KEY : get_option( 'openphone_api_key' );
```

### Custom Error Messages

Translate error messages for international use:

```php
add_filter( 'gettext', function( $translation, $text, $domain ) {
    if ( $domain === 'wp-mcp-ai' && strpos( $text, 'OpenPhone' ) !== false ) {
        // Customize error messages
    }
    return $translation;
}, 10, 3 );
```

## Related Tools

- **Send Telegram Message**: For Telegram bot notifications
- **Send WhatsApp Message**: For WhatsApp Cloud API
- **Send Group Email**: For email notifications
- **Schedule Notify SMS**: For scheduled SMS via Notify.lk

## API Documentation

- [OpenPhone API Reference](https://www.openphone.com/docs/api-reference/introduction)
- [Send Message Endpoint](https://www.openphone.com/docs/mdx/api-reference/send-your-first-message)
- [Authentication Guide](https://www.openphone.com/docs/mdx/api-reference/authentication)
- [Webhooks Documentation](https://support.openphone.com/core-concepts/integrations/webhooks)

## Support

For OpenPhone-specific issues:
- OpenPhone Support: https://support.openphone.com
- API Status: Check OpenPhone's status page

For WP oOS integration issues:
- Plugin Issues: GitHub repository
- Documentation: `docs/` directory in plugin
