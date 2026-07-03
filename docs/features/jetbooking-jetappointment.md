# JetBooking/JetAppointment Integration

> Adapter layer for Crocoblock JetBooking and JetAppointment plugins — 8 new AI tools + 4 enhanced calendar tools.

## Overview

The JetBooking/JetAppointment integration bridges Crocoblock's booking and appointment management plugins with the NV oOS AI assistant framework. This adapter layer adds 8 new AI tools for full CRUD operations on bookings and appointments, and enhances 4 existing Calendar Booking toolkit tools with booking/appointment awareness.

## Prerequisites

| Requirement | Details |
|---|---|
| **WordPress** | 6.0+ |
| **NV oOS Pro** | v1.1.36+ |
| **JetEngine** | Active (Crocoblock) |
| **JetBooking** | Active (Crocoblock) |
| **JetAppointment** | Active (Crocoblock, optional) |

## New Tools (8)

| Tool | Slug | Description |
|---|---|---|
| Create Booking | `create_booking` | Create a new booking with customer details, date/time, and service selection |
| Update Booking | `update_booking` | Modify existing booking details, status, or reschedule |
| Delete Booking | `delete_booking` | Cancel and remove a booking |
| List Bookings | `list_bookings` | Query bookings by date range, customer, service, or status |
| Get Booking | `get_booking` | Retrieve full details of a specific booking |
| Create Appointment | `create_appointment` | Create a new appointment with provider, date/time, and service |
| Update Appointment | `update_appointment` | Modify existing appointment or reschedule |
| List Appointments | `list_appointments` | Query appointments by provider, date, customer, or status |

## Enhanced Calendar Tools (4)

The following existing Calendar Booking tools now include booking/appointment data:

| Tool | Enhancements |
|---|---|
| `calendar_create_event` | Now accepts booking/appointment references, auto-creates linked bookings |
| `calendar_update_event` | Syncs event changes with linked bookings/appointments |
| `calendar_list_events` | Includes booking/appointment status in event listings |
| `calendar_get_availability` | Checks against existing bookings and appointments for conflict detection |

## Example Natural Language Prompts

**Booking management:**
- "Create a booking for John Doe at 2 PM tomorrow for the Executive Suite"
- "List all bookings for next week"
- "Cancel booking #1234 and notify the customer"
- "Show me all pending bookings that need confirmation"

**Appointment management:**
- "Schedule a consultation appointment with Dr. Smith on Friday at 10 AM"
- "What appointments does Dr. Smith have today?"
- "Reschedule appointment #567 to next Tuesday at 3 PM"

**Availability queries:**
- "Is the Conference Room available on July 10th from 9 AM to 12 PM?"
- "Show me all available time slots for a haircut this Saturday"
- "What services have availability tomorrow afternoon?"

**Calendar integration:**
- "Add the new booking to the team calendar"
- "Sync all confirmed bookings with Google Calendar"
- "Show me conflicts between bookings and existing calendar events"

## Architecture

```
JetBooking Plugin ──┐
                    ├──▶ Adapter Layer ──▶ AI Tools ──▶ Assistant
JetAppointment ─────┘         │
                              ▼
                    Calendar Booking Toolkit
                    (4 enhanced tools)
```

The adapter layer normalizes the different data structures and APIs of JetBooking and JetAppointment into a consistent interface for AI tools, while preserving each plugin's specific features (provider management, service catalogs, buffer times, etc.).

## Configuration

### Enable Integration

1. Go to **NV oOS → Settings**
2. Under **Pro Toolkits**, check **Enable JetBooking/JetAppointment Integration**
3. Save settings

### Tool Assignment

Assign the new tools to assistants via:
- **Assistant Editor** → Tools tab → Calendar Booking section
- **Tool Presets** → Calendar & Booking preset (auto-selects all 12 tools)

## Compatibility

| Plugin | Version | Status |
|---|---|---|
| JetEngine | 3.0+ | Required |
| JetBooking | 2.0+ | Required for booking tools |
| JetAppointment | 1.5+ | Required for appointment tools |

**Note:** Either JetBooking or JetAppointment can be used independently — the adapter auto-detects which plugins are active and only registers the relevant tools.

## See Also

- [Calendar Booking Toolkit](../../addons/pro/includes/tools/calendar-booking/README.md)
- [JetBooking Documentation](https://crocoblock.com/plugins/jetbooking/)
- [JetAppointment Documentation](https://crocoblock.com/plugins/jetappointment/)
- [Unified Blueprint System](unified-blueprint-system.md)
