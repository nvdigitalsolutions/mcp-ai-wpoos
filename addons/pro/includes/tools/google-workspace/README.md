# Google Workspace Tools

> Pro integration tools for Google Workspace services — Gmail, Google Drive, Google Calendar, and Google Analytics.

## Purpose

Search, create, and report across Google Workspace. Each tool gates on its respective OAuth / service-account configuration.

## Tools

| Tool class | Slug | Domain |
|---|---|---|
| `WP_MCP_AI_Pro_Tool_Search_Gmail` | `search_gmail` | Gmail message search |
| `WP_MCP_AI_Pro_Tool_Search_Drive` | `search_drive` | Google Drive file search |
| `WP_MCP_AI_Pro_Tool_Get_Drive_File` | `get_drive_file` | Read one file (Docs export, folder children) |
| `WP_MCP_AI_Pro_Tool_List_Drive_Connections` | `list_drive_connections` | Discover connection IDs (redacted) |
| `WP_MCP_AI_Pro_Tool_Create_Google_Calendar_Event` | `create_google_calendar_event` | Calendar event creation |
| `WP_MCP_AI_Pro_Tool_List_Google_Calendars` | `list_google_calendars` | Calendar discovery |
| `WP_MCP_AI_Pro_Tool_List_Google_Calendar_Events` | `list_google_calendar_events` | Calendar event listing (paginated) |
| `WP_MCP_AI_Pro_Tool_Update_Google_Calendar_Event` | `update_google_calendar_event` | Calendar event update (full replace or PATCH) |
| `WP_MCP_AI_Pro_Tool_Delete_Google_Calendar_Event` | `delete_google_calendar_event` | Calendar event deletion |
| `WP_MCP_AI_Pro_Tool_Check_Google_Calendar_Availability` | `check_google_calendar_availability` | Calendar freeBusy lookup (max 50 calendars) |
| `WP_MCP_AI_Pro_Tool_Quick_Add_Google_Calendar_Event` | `quick_add_google_calendar_event` | Calendar event creation from natural language |
| `WP_MCP_AI_Pro_Tool_Get_Google_Analytics_Report` | `get_google_analytics_report` | GA4 reporting |

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` |
| **Optional dependencies** | Google Cloud service account / OAuth 2.0 credentials |

## Conventions

- Canonical return envelope enforced.
- Two-gate sanitisation rule applies.
- Every tool implements `WP_MCP_AI_Tool_Interface`.
- Drive tools share `WP_MCP_AI_Pro_Google_Drive_Client` (credentials, token refresh, text export, folder listing).
- Google service-account helper (`WP_MCP_AI_Pro_Google_Service_Account`) is shared with [`tools/chat-channels/`](../chat-channels/) tools.

## See Also

- Sibling: [`tools/chat-channels/`](../chat-channels/)
- Sibling: [`tools/email-marketing/`](../email-marketing/)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
