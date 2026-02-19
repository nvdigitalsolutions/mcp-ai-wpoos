# WhatsApp Business Integration Setup Guide

Complete guide for setting up WhatsApp Business API integration with the NV oOS plugin, following industry best practices and Meta's official guidelines.

## Overview

This plugin provides comprehensive WhatsApp Business API integration through:
- **Webhook Handler**: Real-time message processing with signature validation
- **Messaging Tools**: Send text, interactive, template, and media messages
- **Security**: HMAC-SHA256 signature validation, webhook verification
- **Interactive Features**: Reply buttons, list messages, quick replies
- **Media Support**: Images, videos, audio, documents, stickers

## Prerequisites

### 1. Meta Business Account
- Create a Meta Business Account at [business.facebook.com](https://business.facebook.com)
- Complete business verification (required for production access)

### 2. WhatsApp Business App
- Go to [Meta for Developers](https://developers.facebook.com/)
- Create a new app or select existing app
- Add **WhatsApp** product to your app

### 3. WordPress Requirements
- **HTTPS Required**: Valid SSL certificate (Let's Encrypt works)
- **PHP 7.4+**: Required by NV oOS
- **WordPress 6.0+**: Minimum WordPress version
- **Public Access**: Webhook endpoint must be accessible from internet

## Setup Steps

### Step 1: Get WhatsApp Credentials

1. **Access Meta Developer Dashboard**
   - Go to your app's WhatsApp product page
   - Navigate to **Configuration** section

2. **Get Phone Number ID**
   - Click on the phone number you want to use
   - Copy the **Phone Number ID** (15-digit number)

3. **Get Access Token**
   - In the same configuration page, generate a **Temporary Access Token**
   - For production, create a **Permanent Access Token** via System Users

4. **Get App Secret**
   - Go to **Settings → Basic** in your app dashboard
   - Copy the **App Secret** (click Show to reveal)
   - **IMPORTANT**: This is used for webhook signature validation

5. **Create Verify Token**
   - Generate a random, secure string (e.g., `wp_whatsapp_verify_token_abc123xyz`)
   - Save this - you'll use it in WordPress and Meta dashboard

### Step 2: Configure WordPress Connection

1. **Navigate to Remote Sites**
   ```
   WordPress Admin → NV oOS → Pro Dashboard → Remote Sites
   ```

2. **Add New Connection**
   - Click **Add New Connection**
   - Fill in the form:

   **Connection Name**: `WhatsApp Business`
   
   **Site URL**: `https://graph.facebook.com/v19.0/` (auto-filled)
   
   **Connection Type**: Select `WhatsApp Business (Chat Channel)`

3. **Enter Credentials**
   ```
   Access Token: [Your WhatsApp Access Token]
   App Secret: [Your WhatsApp App Secret]
   Phone Number ID: [Your 15-digit Phone Number ID]
   Business Account ID: [Optional]
   Verify Token: [Your random verify token]
   ```

4. **Copy Webhook URL**
   - The webhook URL will be displayed:
   ```
   https://yoursite.com/wp-json/mcp-ai/v1/webhooks/whatsapp
   ```
   - Copy this URL for the next step

5. **Save Connection**
   - Click **Save Connection**
   - Test the connection if desired

### Step 3: Configure Webhook in Meta Dashboard

1. **Open Webhook Configuration**
   - In your app's WhatsApp product page
   - Navigate to **Configuration → Webhook**
   - Click **Edit**

2. **Enter Webhook Details**
   ```
   Callback URL: https://yoursite.com/wp-json/mcp-ai/v1/webhooks/whatsapp
   Verify Token: [Same token you entered in WordPress]
   ```

3. **Verify Webhook**
   - Click **Verify and Save**
   - Meta will send a GET request to verify your endpoint
   - You should see "Webhook verified successfully" message

4. **Subscribe to Webhook Fields**
   - Check these fields:
     - ✅ **messages** (incoming messages)
     - ✅ **message_status** (delivery and read receipts)
   - Click **Subscribe**

### Step 4: Test the Integration

#### Test 1: Send a Simple Message

Using WordPress admin or AI assistant:

```json
{
  "tool": "send_whatsapp_message",
  "arguments": {
    "access_token": "YOUR_ACCESS_TOKEN",
    "phone_number_id": "YOUR_PHONE_NUMBER_ID",
    "to": "+1234567890",
    "text": "Hello from WordPress! This is a test message.",
    "preview_url": true
  }
}
```

#### Test 2: Send Interactive Buttons

```json
{
  "tool": "send_whatsapp_interactive",
  "arguments": {
    "access_token": "YOUR_ACCESS_TOKEN",
    "phone_number_id": "YOUR_PHONE_NUMBER_ID",
    "to": "+1234567890",
    "type": "button",
    "body": "How can we help you today?",
    "buttons": [
      {
        "type": "reply",
        "reply": {
          "id": "btn_support",
          "title": "Get Support"
        }
      },
      {
        "type": "reply",
        "reply": {
          "id": "btn_sales",
          "title": "Talk to Sales"
        }
      }
    ]
  }
}
```

#### Test 3: Receive Messages

1. Send a message to your WhatsApp Business number from your personal WhatsApp
2. Check WordPress logs to see webhook received:
   ```
   WordPress Admin → NV oOS → Settings → Logging
   ```
3. Look for `whatsapp_incoming_message` events

## Features & Capabilities

### 1. Text Messages
- Plain text up to 4096 characters
- WhatsApp markdown formatting:
  - `*bold*` → **bold**
  - `_italic_` → *italic*
  - `~strikethrough~` → ~~strikethrough~~
  - `` `code` `` → `code`
- URL preview support

### 2. Interactive Messages

#### Reply Buttons
- Up to 3 buttons per message
- Button titles: max 20 characters
- Ideal for quick responses (Yes/No, Select option, etc.)

#### List Messages
- Up to 10 sections
- Up to 10 rows per section
- Row titles: max 24 characters
- Row descriptions: max 72 characters
- Perfect for menus, catalogs, or multiple options

### 3. Media Messages
Supported types:
- **Images**: JPG, PNG (max 5MB)
- **Videos**: MP4, 3GP (max 16MB)
- **Audio**: AAC, MP4, MPEG, AMR, OGG (max 16MB)
- **Documents**: PDF, DOC, XLS, PPT, TXT, ZIP (max 100MB)
- **Stickers**: WebP (max 500KB)

### 4. Template Messages
- Pre-approved message templates
- Required for proactive messaging (outside 24-hour window)
- Create templates in Meta Business Manager

### 5. Webhook Events Handled
- Incoming messages (text, media, interactive responses)
- Message status updates (sent, delivered, read, failed)
- Template status updates
- Account updates

## Best Practices

### Security
1. **Never Share Tokens**: Keep access tokens and app secrets secure
2. **Use Environment Variables**: Don't hardcode in source code
3. **Rotate Tokens**: Regularly rotate access tokens
4. **Monitor Logs**: Check for suspicious activity

### Message Quality
1. **Be Concise**: Keep messages short and actionable
2. **Use Interactive Elements**: Buttons and lists improve UX
3. **Test Templates**: Always test template messages before sending to users
4. **Respect Opt-Outs**: Honor user preferences and opt-out requests

### Rate Limiting
WhatsApp has tiered rate limits:
- **Tier 1**: 1,000 unique contacts per 24 hours (new accounts)
- **Tier 2**: 10,000 unique contacts per 24 hours
- **Tier 3**: 100,000 unique contacts per 24 hours
- **Unlimited**: Available after sustained usage

Monitor your tier status in Meta Business Manager.

### Compliance
1. **24-Hour Window**: You can only respond to users within 24 hours of their last message (unless using templates)
2. **Opt-In Required**: Users must opt-in to receive messages
3. **GDPR**: Comply with data protection regulations
4. **Content Policy**: Follow WhatsApp Business Policy guidelines

## Troubleshooting

### Webhook Not Receiving Messages

1. **Check SSL Certificate**
   ```bash
   curl -I https://yoursite.com/wp-json/mcp-ai/v1/webhooks/whatsapp
   ```
   Should return `200 OK` or `405 Method Not Allowed` (GET without params)

2. **Verify Token Matches**
   - WordPress connection verify token
   - Meta dashboard verify token
   - These must be identical

3. **Check Webhook Subscription**
   - In Meta dashboard, ensure fields are subscribed
   - Check webhook status (should be green)

4. **Review Logs**
   - Check WordPress error logs
   - Check NV oOS activity logs

### Signature Validation Failing

1. **Verify App Secret**
   - Ensure app secret in WordPress matches Meta dashboard
   - Check for extra spaces or typos

2. **Check Payload**
   - Log incoming payload for inspection
   - Verify signature header is present

### Messages Not Sending

1. **Check Access Token**
   - Temporary tokens expire after 24 hours
   - Use permanent tokens for production

2. **Verify Phone Number Status**
   - Phone number must be verified in Meta dashboard
   - Check quality rating (should be green)

3. **Review Rate Limits**
   - Check if you've exceeded tier limits
   - Review Meta Business Manager for messaging limits

### Common Error Codes

| Code | Error | Solution |
|------|-------|----------|
| 100 | Invalid parameter | Check parameter format and values |
| 131000 | Access token invalid | Generate new token or check permissions |
| 131009 | User phone number not on WhatsApp | Verify recipient has WhatsApp |
| 131026 | Message too long | Reduce message length |
| 131042 | Invalid template | Check template name and parameters |
| 131047 | Re-engagement message required | User hasn't messaged in 24h, use template |
| 133008 | Rate limit exceeded | Wait and retry, or upgrade tier |

## Advanced Configuration

### Auto-Reply Setup

Add a filter to enable auto-replies:

```php
add_filter( 'wp_mcp_ai_whatsapp_should_auto_reply', function( $should_reply, $message_data, $context ) {
    // Auto-reply to all messages
    return true;
}, 10, 3 );

add_action( 'wp_mcp_ai_whatsapp_auto_reply', function( $message_data, $context ) {
    // Implement your auto-reply logic
    // E.g., call an AI assistant to generate response
}, 10, 2 );
```

### Custom Message Handling

Hook into incoming messages:

```php
add_action( 'wp_mcp_ai_whatsapp_message_received', function( $message_data, $message, $context ) {
    // Custom processing
    $from = $message_data['from'];
    $text = $message_data['content'];
    $type = $message_data['type'];
    
    // Your logic here
}, 10, 3 );
```

### Status Update Tracking

Monitor message delivery:

```php
add_action( 'wp_mcp_ai_whatsapp_message_status', function( $status ) {
    $message_id = $status['id'];
    $status_val = $status['status']; // sent, delivered, read, failed
    
    // Track in database, send notification, etc.
}, 10 );
```

## API Version Updates

This integration uses **Graph API v19.0**. When Meta releases new versions:

1. Check [WhatsApp Cloud API Changelog](https://developers.facebook.com/docs/whatsapp/changelog)
2. Test new features in development environment
3. Update `GRAPH_API_VERSION` constant if needed
4. Review deprecated features

## Resources

- [WhatsApp Cloud API Documentation](https://developers.facebook.com/docs/whatsapp/cloud-api)
- [WhatsApp Business Policy](https://www.whatsapp.com/legal/business-policy/)
- [Meta for Developers](https://developers.facebook.com/)
- [NV oOS Documentation](https://nvdigitalsolutions.com/docs/)

## Support

For issues with:
- **WhatsApp API**: [Meta Developer Community](https://developers.facebook.com/community/)
- **NV oOS Plugin**: Contact NV Digital Solutions support

---

**Last Updated**: February 2026  
**API Version**: v19.0  
**Plugin Version**: 1.0.0+
