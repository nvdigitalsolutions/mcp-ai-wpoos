# Google Workspace Tools

> Pro integration tools for Google Workspace services — Gmail, Google Drive, Google Calendar, and Google Analytics.

## Purpose

Search, create, and report across Google Workspace. Each tool gates on its respective OAuth / service-account configuration.

## Tools

| Tool class | Slug | Domain |
|---|---|---|
| `WP_MCP_AI_Pro_Tool_Search_Gmail` | `search_gmail` | Gmail message search |
| `WP_MCP_AI_Pro_Tool_Search_Drive` | `search_drive` | Google Drive file search |
| `WP_MCP_AI_Pro_Tool_Create_Google_Calendar_Event` | `create_google_calendar_event` | Calendar event creation |
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
- Google service-account helper (`WP_MCP_AI_Pro_Google_Service_Account`) is shared with [`tools/chat-channels/`](../chat-channels/) tools.

## See Also

- Sibling: [`tools/chat-channels/`](../chat-channels/)
- Sibling: [`tools/email-marketing/`](../email-marketing/)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md)
