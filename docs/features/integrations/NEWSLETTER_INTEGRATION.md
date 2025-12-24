# Newsletter Plugin Integration Guide

This guide covers the Newsletter plugin integration tools available in WP oOS (Open Operator System).

## Overview

The Newsletter plugin integration provides 6 professional tools to manage newsletter subscribers and email campaigns through AI assistants and the MCP protocol. These tools enable automated subscriber management, campaign creation, and statistical analysis.

## Requirements

- Newsletter plugin (https://wordpress.org/plugins/newsletter/) must be installed and active
- User must have `manage_options` capability
- Tools appear in: `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=plugins`

## Available Tools

### 1. Add Newsletter Subscriber (`newsletter_add_subscriber`)

Add or update a subscriber in the Newsletter plugin.

**Parameters:**
- `email` (required, string) - Email address of the subscriber
- `name` (optional, string) - First name of the subscriber
- `surname` (optional, string) - Last name of the subscriber
- `lists` (optional, array) - Array of list IDs (1-40) to subscribe to
- `status` (optional, string) - Subscription status: `confirmed`, `not_confirmed`, or `unsubscribed`. Default: `confirmed`
- `send_emails` (optional, boolean) - Whether to send confirmation/welcome emails. Default: `false`

**Example:**
```json
{
  "email": "user@example.com",
  "name": "John",
  "surname": "Doe",
  "lists": [1, 3],
  "status": "confirmed"
}
```

**Response:**
```json
{
  "success": true,
  "subscriber_id": 123,
  "email": "user@example.com",
  "action": "created",
  "status": "confirmed",
  "message": "Subscriber added successfully."
}
```

**Capability Flags:** `requires-capability`, `modifies-data`, `local-only`

---

### 2. Get Newsletter Subscribers (`newsletter_get_subscribers`)

Retrieve a list of subscribers with filtering and pagination.

**Parameters:**
- `limit` (optional, integer) - Maximum number of subscribers to retrieve (1-100). Default: `20`
- `offset` (optional, integer) - Number of subscribers to skip for pagination. Default: `0`
- `status` (optional, string) - Filter by status: `confirmed`, `not_confirmed`, or `unsubscribed`
- `list_id` (optional, integer) - Filter by list ID (1-40)
- `email` (optional, string) - Search by email (partial match)
- `name` (optional, string) - Search by name or surname (partial match)

**Example:**
```json
{
  "limit": 10,
  "status": "confirmed",
  "list_id": 1
}
```

**Response:**
```json
{
  "subscribers": [
    {
      "id": 123,
      "email": "user@example.com",
      "name": "John",
      "surname": "Doe",
      "status": "confirmed",
      "lists": [1, 3],
      "created": "2025-01-01 10:00:00"
    }
  ],
  "total": 150,
  "limit": 10,
  "offset": 0,
  "count": 10
}
```

**Capability Flags:** `read-only`, `requires-capability`, `local-only`

---

### 3. Unsubscribe Newsletter Subscriber (`newsletter_unsubscribe`)

Unsubscribe or permanently delete a subscriber.

**Parameters:**
- `email` (optional, string) - Email address to unsubscribe
- `subscriber_id` (optional, integer) - Subscriber ID to unsubscribe (alternative to email)
- `action` (optional, string) - Action to perform: `unsubscribe` (set status) or `delete` (remove completely). Default: `unsubscribe`

**Example:**
```json
{
  "email": "user@example.com",
  "action": "unsubscribe"
}
```

**Response:**
```json
{
  "success": true,
  "subscriber_id": 123,
  "email": "user@example.com",
  "action": "unsubscribed",
  "message": "Subscriber unsubscribed successfully."
}
```

**Capability Flags:** `requires-capability`, `modifies-data`, `local-only`

---

### 4. Get Newsletter Subscriber Statistics (`newsletter_get_subscriber_stats`)

Get statistical overview of subscribers including counts by status and lists.

**Parameters:**
- `include_lists` (optional, boolean) - Include subscriber counts per list. Default: `true`

**Example:**
```json
{
  "include_lists": true
}
```

**Response:**
```json
{
  "total_subscribers": 1000,
  "confirmed": 850,
  "not_confirmed": 100,
  "unsubscribed": 50,
  "active_subscribers": 850,
  "subscribers_by_list": {
    "1": 500,
    "2": 300,
    "3": 200
  },
  "active_lists_count": 3
}
```

**Capability Flags:** `read-only`, `requires-capability`, `local-only`

---

### 5. Create Newsletter Email (`newsletter_create_email`)

Create a new newsletter email campaign.

**Parameters:**
- `subject` (required, string) - Email subject line
- `message` (required, string) - Email HTML content/body
- `type` (optional, string) - Email type: `message` (standard) or `followup` (automated). Default: `message`
- `status` (optional, string) - Email status: `new` (draft), `sending`, `sent`, or `paused`. Default: `new`
- `track` (optional, boolean) - Enable click and open tracking. Default: `true`
- `lists` (optional, array) - Target list IDs (1-40). Empty means all confirmed subscribers

**Example:**
```json
{
  "subject": "Monthly Newsletter",
  "message": "<h1>Hello!</h1><p>This is our monthly update.</p>",
  "type": "message",
  "status": "new",
  "track": true,
  "lists": [1, 2]
}
```

**Response:**
```json
{
  "success": true,
  "email_id": 456,
  "subject": "Monthly Newsletter",
  "type": "message",
  "status": "new",
  "message": "Newsletter email created successfully.",
  "edit_url": "https://example.com/wp-admin/admin.php?page=newsletter_emails_edit&id=456"
}
```

**Capability Flags:** `requires-capability`, `modifies-data`, `local-only`

---

### 6. Get Newsletter Emails (`newsletter_get_emails`)

Retrieve newsletter email campaigns with filtering.

**Parameters:**
- `limit` (optional, integer) - Maximum number of emails to retrieve (1-50). Default: `10`
- `offset` (optional, integer) - Number of emails to skip for pagination. Default: `0`
- `status` (optional, string) - Filter by status: `new`, `sending`, `sent`, or `paused`
- `type` (optional, string) - Filter by type: `message` or `followup`

**Example:**
```json
{
  "limit": 5,
  "status": "sent"
}
```

**Response:**
```json
{
  "emails": [
    {
      "id": 456,
      "subject": "Monthly Newsletter",
      "type": "message",
      "status": "sent",
      "track": true,
      "created": "2025-01-01 10:00:00",
      "updated": "2025-01-05 14:30:00",
      "sent_count": 850,
      "edit_url": "https://example.com/wp-admin/admin.php?page=newsletter_emails_edit&id=456"
    }
  ],
  "total": 25,
  "limit": 5,
  "offset": 0,
  "count": 5
}
```

**Capability Flags:** `read-only`, `requires-capability`, `local-only`

---

## Database Tables

The Newsletter plugin uses the following database tables:

- `{prefix}_newsletter` - Stores subscribers
- `{prefix}_newsletter_emails` - Stores email campaigns
- `{prefix}_newsletter_stats` - Stores email statistics
- `{prefix}_newsletter_sent` - Tracks sent emails

All Newsletter tools interact directly with these tables using WordPress's `$wpdb` interface.

## Security Considerations

1. **Capability Checks**: All tools require `manage_options` capability
2. **Input Sanitization**: All user inputs are sanitized using WordPress functions
3. **SQL Injection Protection**: All database queries use prepared statements
4. **Multisite Support**: Tools check blog membership in multisite environments
5. **No External APIs**: All operations are local-only

## Usage Examples

### AI Assistant Prompt Examples

**Add a subscriber:**
> "Add john.doe@example.com to the newsletter with name John Doe and subscribe to lists 1 and 3"

**Get subscriber statistics:**
> "Show me the newsletter subscriber statistics including list breakdown"

**Create a newsletter:**
> "Create a newsletter email with subject 'Special Offer' and HTML content about our new products"

**Find subscribers:**
> "List all confirmed subscribers in list 2"

### REST API Usage

Tools can be accessed via the MCP REST API:

```bash
POST /wp-json/mcp-ai/v1/chat
Authorization: Bearer <token>

{
  "message": "Add subscriber@example.com to newsletter list 1",
  "assistant_id": 123
}
```

## Troubleshooting

### Tool Not Available

If Newsletter tools don't appear:
1. Verify Newsletter plugin is installed and active
2. Check that you're not in base version mode (`WP_MCP_AI_BASE_VERSION` should be `false`)
3. Ensure user has `manage_options` capability
4. Clear any object caches

### Database Errors

If you encounter database errors:
1. Verify Newsletter plugin database tables exist
2. Check WordPress database prefix matches
3. Ensure database user has appropriate permissions
4. Review WordPress debug logs

## Hooks and Filters

### Actions

- `wp_mcp_ai_newsletter_subscriber_added` - Fired after subscriber is added
- `wp_mcp_ai_newsletter_subscriber_deleted` - Fired after subscriber is deleted
- `wp_mcp_ai_newsletter_subscriber_unsubscribed` - Fired after subscriber is unsubscribed
- `wp_mcp_ai_newsletter_email_created` - Fired after email campaign is created

### Example Hook Usage

```php
// Log when subscribers are added via AI
add_action( 'wp_mcp_ai_newsletter_subscriber_added', function( $subscriber_id, $data, $arguments, $context ) {
    error_log( "AI added subscriber: {$data['email']}" );
}, 10, 4 );
```

## Best Practices

1. **Batch Operations**: Use pagination for large subscriber lists
2. **Status Management**: Always set appropriate status when adding subscribers
3. **List Organization**: Use list IDs consistently for segmentation
4. **Email Drafts**: Create emails with status 'new' for review before sending
5. **Statistics Monitoring**: Regularly check subscriber stats for list health

## Future Enhancements

Potential additions to the Newsletter integration:

- Send email campaigns directly (with safety checks)
- Get detailed email statistics (opens, clicks, bounces)
- Manage subscriber lists (create, rename, delete)
- Import/export subscribers in bulk
- Template management
- Automated campaign scheduling

## Support

For issues or feature requests:
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: Check `/docs` directory
- Newsletter Plugin: https://wordpress.org/support/plugin/newsletter/
