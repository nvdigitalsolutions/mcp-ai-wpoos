# Calendar Booking Toolkit (Phase 2.6)

This directory contains 15 professional calendar booking and appointment management tools for the NV oOS Pro plugin.

## Tool Categories

### Appointment Management (5 tools)
1. **create_appointment** - Create new appointments with client details
2. **update_appointment** - Update existing appointments  
3. **cancel_appointment** - Cancel appointments with notifications
4. **reschedule_appointment** - Reschedule appointments automatically
5. **get_appointment_details** - Retrieve appointment information

### Availability & Scheduling (5 tools)
6. **check_availability** - Check time slot availability
7. **set_availability_rules** - Define availability rules and hours
8. **get_available_slots** - Get list of available time slots
9. **block_time_slot** - Block specific time slots
10. **optimize_schedule** - AI-optimize appointment scheduling

### Calendar Sync & Integration (5 tools)
11. **sync_google_calendar** - Sync with Google Calendar
12. **sync_outlook_calendar** - Sync with Outlook Calendar
13. **send_booking_confirmation** - Send confirmation emails
14. **send_appointment_reminder** - Send automated reminders
15. **generate_booking_link** - Generate public booking links

## Features

- **Comprehensive Appointment Management**: Full CRUD operations for appointments
- **Conflict Detection**: Automatic time slot conflict checking
- **Business Hours Support**: Configurable availability rules per day
- **Calendar Integration**: Google Calendar and Outlook Calendar sync
- **Email Notifications**: Confirmation and reminder emails
- **Public Booking Links**: Shareable booking URLs with expiry
- **AI Optimization**: Smart schedule optimization recommendations
- **Change Tracking**: Full audit trail for all appointment changes

## Requirements

- WordPress 6.0+
- PHP 7.4+
- NV oOS Pro plugin
- Calendar Booking toolkit enabled in settings

## Usage

All tools require `manage_options` capability by default. Tools extend `WP_MCP_AI_Tool_Interface` and follow the standard tool pattern.

### Example: Create an Appointment

```php
$tool = new WP_MCP_AI_Tool_Create_Appointment();
$result = $tool->execute(
    array(
        'client_name'  => 'John Doe',
        'client_email' => 'john@example.com',
        'start_time'   => '2024-02-01 14:00:00',
        'end_time'     => '2024-02-01 15:00:00',
    ),
    array( 'user_id' => 1 )
);
```

## Data Storage

- **Appointments**: Custom post type `mcp_appointment`
- **Blocked Times**: Custom post type `mcp_blocked_time`
- **Booking Links**: Custom post type `mcp_booking_link`
- **Business Hours**: WordPress option `wp_mcp_ai_business_hours`

## Hooks & Filters

### Filters
- `wp_mcp_ai_google_calendar_sync` - Customize Google Calendar sync
- `wp_mcp_ai_outlook_calendar_sync` - Customize Outlook Calendar sync
- `wp_mcp_ai_process_appointment_refund` - Handle appointment refunds

## Security

All tools:
- Check `manage_options` capability
- Validate and sanitize all inputs
- Use WordPress nonces where applicable
- Escape all output
- Log security events

## Capability Flags

Tools are tagged with:
- `pro` - Pro version only
- `database-read` - Reads from database
- `database-write` - Writes to database
- `external-api` - Makes external API calls
- `email` - Sends emails
- `ai-feature` - Uses AI optimization
- `phase-2.6` - Part of Phase 2.6 implementation

## Version

**Phase**: 2.6
- **Tools**: 20 (15 original + 4 no-show/unconfirmed + 1 calendar orchestration)
- **Since**: 2.6.0
- **v2.9.0 additions**: `get_no_show_appointments`, `get_unconfirmed_bookings`, `send_booking_confirmations`, `send_reschedule_invitation`

## Support

For issues or feature requests, see the main plugin documentation.
