# ECA Management Toolkit

## Overview

The ECA (Extra-Curricular Activities) Management Toolkit is a Pro feature that enables schools to manage clubs, societies, sports squads, and academies through AI assistants. It integrates with iSAMS school management system and SOCS online booking platform.

## Features

- **ECA Management**: Create, update, delete, and list extra-curricular activities
- **Student Bookings**: Manage student preferences and allocations
- **iSAMS Integration**: Synchronize with iSAMS school management system
- **SOCS Integration**: Support for SOCS online booking platform
- **Flexible Booking Types**: Preference-based, first-come-first-served, audition, or pre-selected
- **Capacity Management**: Track and enforce maximum capacity limits
- **Paid Activities**: Support for paid ECAs with cost tracking

## Custom Post Types

### `mcp_ai_eca`
Stores ECA information including clubs, societies, sports squads, and academies.

**Meta Fields:**
- `_eca_code` - ECA identifier (e.g., "1", "2A")
- `_eca_type` - Type: club, society, sport_squad, sport_academy, other
- `_eca_day` - Day of week (Monday-Sunday)
- `_eca_time_start` - Start time (HH:MM format)
- `_eca_time_end` - End time (HH:MM format)
- `_eca_venue` - Venue/location
- `_eca_year_groups` - Array of year groups (e.g., ["Year 7", "Year 8"])
- `_eca_teachers` - Array of teacher names
- `_eca_max_capacity` - Maximum number of students
- `_eca_is_paid` - Boolean for paid activities
- `_eca_cost` - Cost details
- `_eca_booking_type` - preference, first_come_first_served, audition, pre_selected
- `_eca_isams_id` - iSAMS identifier
- `_eca_socs_id` - SOCS identifier

### `mcp_ai_eca_booking`
Stores student bookings for ECAs.

**Meta Fields:**
- `_booking_student_name` - Student name
- `_booking_student_email` - Student/parent email
- `_booking_student_year` - Year group
- `_booking_eca_id` - Associated ECA ID
- `_booking_preference_order` - Preference ranking (1 = first choice)
- `_booking_status` - pending, confirmed, waitlist, cancelled
- `_booking_isams_student_id` - iSAMS student identifier

## Available Tools

### 1. Create ECA (`create_eca`)

Creates a new extra-curricular activity.

**Parameters:**
- `name` (required) - ECA name
- `description` - ECA description
- `eca_code` - ECA code/identifier
- `eca_type` - Type: club, society, sport_squad, sport_academy, other
- `day` - Day of week
- `time_start` - Start time (HH:MM)
- `time_end` - End time (HH:MM)
- `venue` - Venue/location
- `year_groups` - Array of year groups
- `teachers` - Array of teacher names
- `max_capacity` - Maximum students
- `is_paid` - Boolean for paid activities
- `cost` - Cost details
- `booking_type` - Booking method
- `isams_id` - iSAMS identifier
- `socs_id` - SOCS identifier

**Example:**
```json
{
  "name": "Chess Club",
  "description": "A club for chess enthusiasts",
  "eca_code": "9",
  "eca_type": "club",
  "day": "Tuesday",
  "time_start": "14:45",
  "time_end": "15:45",
  "venue": "Room 4",
  "year_groups": ["Year 7", "Year 8", "Year 9"],
  "teachers": ["Mr. Pavithra Athukorale"],
  "max_capacity": 20,
  "is_paid": true,
  "cost": "Rs 7,500 per term"
}
```

### 2. Update ECA (`update_eca`)

Updates an existing ECA. Only provided fields will be updated.

**Parameters:**
- `eca_id` (required) - ECA ID to update
- All other fields from `create_eca` are optional

### 3. Delete ECA (`delete_eca`)

Deletes an ECA permanently.

**Parameters:**
- `eca_id` (required) - ECA ID to delete

### 4. List ECAs (`list_ecas`)

Lists and filters ECAs.

**Parameters:**
- `eca_type` - Filter by type
- `day` - Filter by day of week
- `year_group` - Filter by year group
- `is_paid` - Filter by paid/free
- `booking_type` - Filter by booking type
- `search` - Search by name or description
- `limit` - Maximum results (default: 50, max: 200)

**Example:**
```json
{
  "eca_type": "club",
  "day": "Tuesday",
  "year_group": "Year 7"
}
```

### 5. Manage ECA Bookings (`manage_eca_bookings`)

Manages student bookings for ECAs with multiple actions.

**Parameters:**
- `action` (required) - create, update, list, allocate, cancel

**For `create` action:**
- `student_name` (required) - Student name
- `student_email` (required) - Student/parent email
- `eca_id` (required) - ECA to book
- `student_year` - Year group
- `preference_order` - Preference ranking
- `isams_student_id` - iSAMS identifier

**For `update` action:**
- `booking_id` (required) - Booking to update
- `status` - pending, confirmed, waitlist, cancelled
- `preference_order` - Update preference

**For `list` action:**
- `filter_eca_id` - Filter by ECA
- `filter_status` - Filter by status
- `filter_student_email` - Filter by email
- `limit` - Maximum results

**For `allocate` action:**
- `booking_id` (required) - Booking to confirm

**For `cancel` action:**
- `booking_id` (required) - Booking to cancel

**Example - Create booking:**
```json
{
  "action": "create",
  "student_name": "John Smith",
  "student_email": "john.smith@example.com",
  "student_year": "Year 7",
  "eca_id": 123,
  "preference_order": 1
}
```

### 6. iSAMS/SOCS Sync (`isams_sync`)

Synchronizes ECA and booking data with iSAMS and SOCS.

**Parameters:**
- `action` (required) - test_connection, import_students, export_ecas, import_bookings, sync_allocations
- `isams_api_url` - iSAMS API endpoint (uses saved settings if not provided)
- `isams_api_key` - iSAMS API key (uses saved settings if not provided)
- `socs_school_id` - SOCS school identifier
- `term` - Academic term (e.g., "Lent 2026")
- `year_groups` - Filter by year groups
- `dry_run` - Validate only without making changes (default: false)

**Example - Test connection:**
```json
{
  "action": "test_connection",
  "isams_api_url": "https://api.isams.com/v1",
  "isams_api_key": "your-api-key"
}
```

**Example - Import students:**
```json
{
  "action": "import_students",
  "year_groups": ["Year 7", "Year 8", "Year 9"],
  "term": "Lent 2026",
  "dry_run": true
}
```

## Enabling ECA Management

To enable ECA Management in your WordPress site:

1. Add to `wp-config.php` or use a code snippet:
```php
$settings = get_option( 'wp_mcp_ai_settings', array() );
$settings['enable_eca_management'] = true;
update_option( 'wp_mcp_ai_settings', $settings );
```

2. Or add via WP-CLI:
```bash
wp option patch update wp_mcp_ai_settings enable_eca_management true
```

## Use Cases

### 1. Importing ECAs from SOCS Documentation

Use the `create_eca` tool to import ECAs from SOCS documentation:

```
Create the following ECAs from the Lent Term 2026 schedule:
1. Chess Club on Tuesday 2:45-3:45pm in Room 4, for Years 7-13, max 20 students, paid Rs7,500
2. Book Club on Wednesday lunch break in Room 14, for Years 7-13, max 20 students, free
```

### 2. Managing Student Bookings

Create and manage student bookings:

```
Create a booking for student Jane Doe (jane.doe@example.com, Year 8) for Chess Club as her first preference.
```

### 3. Generating Timetables

List all ECAs by day to create timetables:

```
List all ECAs that occur on Tuesday, ordered by start time.
```

### 4. Syncing with iSAMS

Synchronize data with iSAMS:

```
Sync all confirmed ECA bookings with iSAMS for the Lent 2026 term.
```

## Integration with SOCS

The toolkit supports integration with SOCS (School Clubs System):
- Online booking system accessible at `https://www.socscms.com/login/[school-id]/parent`
- Parent login with registered email
- ECA preference submission
- Booking window management
- Allocation confirmation

## iSAMS Integration

The toolkit provides placeholder support for iSAMS integration:
- Student data import
- ECA export to iSAMS
- Booking synchronization
- Allocation management

**Note:** The current implementation includes mock responses. Production deployment requires:
1. iSAMS API credentials (URL and API key)
2. Implementation of actual API calls
3. Error handling for API failures
4. Webhook support for real-time updates

## Permissions

- **Create/Update/Delete ECAs**: Requires `edit_posts` capability
- **List ECAs**: Requires `read` capability
- **Manage Bookings**: Requires `edit_posts` capability
- **iSAMS Sync**: Requires `manage_options` capability (administrator only)

## Database Schema

ECAs and bookings are stored as WordPress custom post types with metadata for flexible querying and filtering. This approach provides:
- Native WordPress admin UI
- REST API support
- Easy backup and export
- Standard WordPress hooks and filters

## Future Enhancements

Potential future additions:
- Attendance tracking
- Parent notifications
- Payment integration
- Conflict detection (student double-booking)
- Waiting list management
- Automated allocation based on preferences
- Reports and analytics
- Export to calendar formats (iCal, Google Calendar)
