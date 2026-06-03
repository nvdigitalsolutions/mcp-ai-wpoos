# Mailjet Integration Guide

## Overview

The Mailjet integration provides comprehensive email sending and management capabilities through the Mailjet API. This integration uses **Basic Authentication** (API Key + Secret Key), not OAuth.

## Authentication

Mailjet uses Basic Authentication with API Key and Secret Key credentials:

- **API Key**: Your public Mailjet API key
- **Secret Key**: Your private Mailjet secret key
- **Authentication Method**: HTTP Basic Auth (API Key as username, Secret Key as password)

### Getting Your API Credentials

1. Log in to your [Mailjet account](https://app.mailjet.com/)
2. Go to **Account → API Keys → Primary API Key**
3. Copy the **API Key** and **Secret Key**
4. In WordPress, go to **NV oOS → Tools & Features → Connections → Mailjet**
5. Enter your credentials and save

## Available Tools

The Mailjet integration provides three Pro tools:

### 1. Send Mailjet Email (`send_mailjet_email`)

Send transactional emails through Mailjet with full control over recipients, content, and metadata.

**Parameters:**
- `subject` (required): Email subject line
- `to` (required): Array of recipient emails or objects with email and name
- `text` (optional): Plain-text email body
- `html` (optional): HTML email body
- `cc` (optional): CC recipients
- `bcc` (optional): BCC recipients
- `from_email` (optional): Sender email (overrides default)
- `from_name` (optional): Sender name (overrides default)
- `reply_to_email` (optional): Reply-to email address
- `reply_to_name` (optional): Reply-to name
- `custom_id` (optional): Custom identifier for tracking

**Example:**
```json
{
  "subject": "Welcome to our service!",
  "to": [
    {"email": "user@example.com", "name": "John Doe"}
  ],
  "html": "<h1>Welcome!</h1><p>Thanks for signing up.</p>",
  "text": "Welcome! Thanks for signing up.",
  "custom_id": "welcome-email-001"
}
```

### 2. Get Mailjet Statistics (`get_mailjet_statistics`)

Retrieve email sending statistics and metrics from your Mailjet account.

**Parameters:**
- `counter_source` (optional): Source type (APIKey, Campaign, ContactsList, User). Default: APIKey
- `counter_timing` (optional): Timing period (Event, Message). Default: Message
- `from_ts` (optional): Start timestamp (UNIX or ISO 8601)
- `to_ts` (optional): End timestamp (UNIX or ISO 8601)

**Example:**
```json
{
  "counter_source": "APIKey",
  "counter_timing": "Message"
}
```

**Returns:**
- Sent count
- Delivered count
- Open count
- Click count
- Bounce count
- Spam count
- Unsubscribe count

### 3. Manage Mailjet Contacts (`manage_mailjet_contacts`)

Manage contacts and contact lists in your Mailjet account.

**Actions:**
- `list_contacts`: List all contacts
- `add_contact`: Add a new contact
- `remove_contact`: Remove a contact
- `list_contactlists`: List all contact lists

**Parameters:**
- `action` (required): Action to perform
- `email` (required for add/remove): Contact email address
- `name` (optional): Contact name (for add_contact)
- `list_id` (optional): Contact list ID
- `is_excluded` (optional): Add to exclusion list (default: false)
- `limit` (optional): Number of results (1-1000, default: 10)

**Examples:**

List contacts:
```json
{
  "action": "list_contacts",
  "limit": 50
}
```

Add contact:
```json
{
  "action": "add_contact",
  "email": "newuser@example.com",
  "name": "New User",
  "list_id": 12345
}
```

Remove contact:
```json
{
  "action": "remove_contact",
  "email": "olduser@example.com"
}
```

List contact lists:
```json
{
  "action": "list_contactlists",
  "limit": 20
}
```

## Webhook Integration

The Mailjet integration includes webhook support for real-time email event tracking.

### Supported Events

- **sent**: Email was sent
- **open**: Email was opened
- **click**: Link in email was clicked
- **bounce**: Email bounced (hard or soft)
- **blocked**: Email was blocked
- **spam**: Email marked as spam
- **unsubscribe**: Recipient unsubscribed

### Setting Up Webhooks

1. In WordPress, go to **NV oOS → Tools & Features → Connections → Mailjet**
2. Copy the **Webhook URL** displayed (e.g., `https://yoursite.com/wp-json/mcp-ai/v1/webhooks/mailjet`)
3. Log in to your [Mailjet account](https://app.mailjet.com/)
4. Go to **Account → Event Tracking (triggers)**
5. For each event type you want to track:
   - Click **Add Event Type**
   - Select the event type (e.g., "open", "click", "bounce")
   - Enter your webhook URL
   - Set **Version** to **2** (for grouped events)
   - Save

### Webhook Security (Optional)

For additional security, you can configure a webhook secret:

1. In your Mailjet dashboard, configure your webhook with a custom secret
2. In WordPress, enter this secret in **Mailjet Webhook Secret** field
3. Save settings

When configured, the plugin will verify the `X-Mailjet-Signature` header on incoming webhook requests.

### Accessing Webhook Events

Webhook events are stored in WordPress and can be accessed via action hooks:

```php
// Listen for all Mailjet events
add_action('wp_mcp_ai_mailjet_event', function($event_type, $email, $event) {
    error_log("Mailjet event: $event_type for $email");
}, 10, 3);

// Listen for specific event types
add_action('wp_mcp_ai_mailjet_event_open', function($email, $event) {
    error_log("Email opened by: $email");
}, 10, 2);

add_action('wp_mcp_ai_mailjet_event_click', function($email, $event) {
    error_log("Link clicked by: $email");
}, 10, 2);

add_action('wp_mcp_ai_mailjet_event_bounce', function($email, $event) {
    error_log("Email bounced for: $email");
}, 10, 2);
```

Get recent webhook events programmatically:

```php
$events = WP_MCP_AI_Mailjet_Webhook_Handler::get_recent_events(10);
foreach ($events as $event) {
    echo "Event: " . $event['event'] . " for " . $event['email'] . "\n";
}
```

## API Documentation

For detailed information about the Mailjet API, refer to the official documentation:

- [Send API v3.1](https://dev.mailjet.com/email/guides/send-api-v3-1/)
- [Event API (Webhooks)](https://dev.mailjet.com/email/guides/webhooks/)
- [Statistics API](https://dev.mailjet.com/email/reference/statistics/)
- [Contacts API](https://dev.mailjet.com/email/reference/contacts/)
- [Parse API (Inbound Email)](https://dev.mailjet.com/email/guides/parse-api/)

## Best Practices

### Sender Verification

Before sending emails, verify your sender email address in Mailjet:

1. Go to **Senders & domains** in your Mailjet account
2. Add and verify your sender email address
3. Wait for verification to complete
4. Use only verified sender addresses in the `from_email` field

### Rate Limiting

Mailjet enforces rate limits on API requests:

- **Default**: 15,000 API calls per hour
- **Burst**: Short bursts allowed, but sustained high rates may be throttled

If you exceed rate limits, implement exponential backoff and retry logic.

### Email Deliverability

To maximize deliverability:

1. **Warm up your IP**: Gradually increase sending volume if using a dedicated IP
2. **Maintain good sender reputation**: Monitor bounces and spam complaints
3. **Use double opt-in**: Confirm subscriber consent before adding to lists
4. **Provide easy unsubscribe**: Include clear unsubscribe links in emails
5. **Monitor engagement**: Track opens and clicks to gauge audience interest

### Testing

Mailjet provides a sandbox mode for testing without sending real emails:

1. In your Mailjet account, enable **Sandbox Mode**
2. Test emails will be processed but not delivered
3. You can still see statistics and webhook events

## Feature Classification

**Mailjet integration is a PRO feature:**

- Tool location: `/addons/pro/includes/src/Tools/`
- Requires: Pro addon to be active
- Type: External API integration (requires internet connectivity)
- Permissions: Requires `manage_options` capability by default

## Troubleshooting

### "Mailjet API credentials have not been configured"

**Solution**: Enter your API Key and Secret Key in the Mailjet settings (NV oOS → Tools & Features → Connections → Mailjet).

### "From email address must be configured"

**Solution**: Set a default "from" email address in the Mailjet settings, or provide `from_email` in your tool parameters.

### "Sender email is not verified"

**Solution**: Verify your sender email address in your Mailjet account (Senders & domains section).

### "API request failed to complete"

**Possible causes:**
- Network connectivity issues
- Mailjet API outage
- Rate limiting

**Solution**: Check your network connection, verify Mailjet status at [status.mailjet.com](https://status.mailjet.com), and implement retry logic with exponential backoff.

### Webhooks not receiving events

**Solution:**
1. Verify webhook URL is correct and accessible publicly
2. Check webhook configuration in Mailjet dashboard
3. Ensure webhook endpoint returns HTTP 200 status
4. Review WordPress error logs for webhook processing issues
5. Test webhook manually using a tool like Postman

## Security Considerations

1. **Protect API credentials**: Store API Key and Secret Key securely, never commit to version control
2. **Use HTTPS**: Always access WordPress over HTTPS to protect credentials in transit
3. **Webhook secret**: Configure webhook secret for additional security
4. **Capability checks**: Tools enforce `manage_options` capability by default
5. **Input validation**: All parameters are sanitized and validated before use
6. **Rate limiting**: Implement rate limiting on your side to prevent abuse

## Support

For integration issues:
- Check the [Mailjet Help Center](https://documentation.mailjet.com/)
- Review [Mailjet API status](https://status.mailjet.com/)
- Contact NV Digital Solutions support

For Mailjet account issues:
- Contact [Mailjet support](https://www.mailjet.com/support/)
