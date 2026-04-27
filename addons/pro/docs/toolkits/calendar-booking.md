# Calendar Booking Toolkit

> Appointment scheduling, availability rules, calendar sync (Google / Outlook), and
> booking confirmations. 15 tools.

| | |
|---|---|
| **Activation setting** | `enable_calendar_booking_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Calendar Booking |
| **Tools** | 15 |

---

## Tool categories

- **Appointment management:** `create_appointment`, `update_appointment`,
  `cancel_appointment`, `reschedule_appointment`, `get_appointment_details`
- **Availability:** `check_availability`, `set_availability_rules`,
  `get_available_slots`, `block_time_slot`, `optimize_schedule`
- **Sync & notifications:** `sync_google_calendar`, `sync_outlook_calendar`,
  `send_booking_confirmation`, `send_appointment_reminder`, `generate_booking_link`

Tool source: `addons/pro/includes/tools/calendar-booking/`.

---

## Activation

1. Activate the Pro add-on.
2. Toggle **Calendar Booking Toolkit** under **NV oOS → Settings → Pro Features**.
3. Connect Google Calendar and / or Outlook OAuth on the toolkit settings page; store
   secrets in the [Password Vault](password-vault.md).

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/calendar-booking/README.md`](../../includes/tools/calendar-booking/README.md)
- [Project Management](project-management.md) — for project-level events vs. appointment slots
