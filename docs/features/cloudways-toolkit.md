# Cloudways Pro Toolkit

**Added:** May 30, 2026 (v1.1.25)  
**Tier:** Pro only  
**Location:** `addons/pro/includes/cloudways/` (API client), `addons/pro/includes/tools/cloudways/` (tools)

## Overview

The Cloudways Pro Toolkit provides **60 AI-powered tools** for managing Cloudways-hosted servers and applications directly from WordPress. It wraps the [Cloudways API v2](https://api.cloudways.com/api/v2) with an authenticated OAuth client and exposes server management, application deployment, security configuration, and monitoring operations as agent-callable tools.

## Quick Start

1. **Enter credentials** — Navigate to **NV oOS → Settings → Connections → Cloudways** and enter your Cloudways email + API key
2. **Enable toolkit** — Toggle **Enable Cloudways Toolkit** to ON
3. **Verify** — The settings page shows connection status: **Connected** (green) or **Disconnected** (red)
4. **Use** — Agents with `manage_options` capability can now call Cloudways tools

### Obtaining API Credentials

1. Log into your [Cloudways Platform](https://platform.cloudways.com)
2. Navigate to **Profile → API**
3. Generate a new API key
4. Copy the email + key into the NV oOS settings page

## Architecture

### API Client (`WP_MCP_AI_Cloudways_Client`)

A singleton HTTP client that handles:

- **OAuth 2.0 token exchange** — automatic bearer token acquisition and refresh
- **Token caching** — stores token + expiry in `wp_mcp_ai_settings` option
- **HTTP methods** — `get()`, `post()`, `put()`, `delete()` all return `array|WP_Error`
- **Error mapping** — HTTP 401 → `cloudways_unauthorized`, 429 → `cloudways_rate_limited`, 404 → `cloudways_not_found`
- **Timeout** — respects `WP_MCP_AI_Resource_Manager` or falls back to 30s

### Helper Functions

| Function | Returns | Purpose |
|----------|---------|---------|
| `wp_mcp_ai_is_cloudways_toolkit_enabled()` | `bool` | Toolkit toggle check |
| `wp_mcp_ai_cloudways_has_credentials()` | `bool` | Credentials present check |
| `wp_mcp_ai_cloudways_param_*()` | `array` | Shared JSON Schema parameter fragments (server_id, app_id, etc.) |

## Tools (60 total)

### Server Management (~15 tools)
| Tool | Description |
|------|-------------|
| `cloudways_list_servers` | List all servers with status, IP, specs |
| `cloudways_get_server` | Get detailed server info |
| `cloudways_server_settings` | Modify server settings (timezone, locale) |
| `cloudways_server_monitoring` | Get CPU, RAM, disk, bandwidth metrics |
| `cloudways_restart_services` | Restart Nginx, Apache, MySQL, etc. |
| `cloudways_scale_server` | Vertical scaling (RAM, CPU, storage) |
| `cloudways_clone_server` | Clone server configuration |

### Application Management (~15 tools)
| Tool | Description |
|------|-------------|
| `cloudways_list_apps` | List all applications on a server |
| `cloudways_get_app` | Get application details |
| `cloudways_create_app` | Provision a new application |
| `cloudways_delete_app` | Remove an application |
| `cloudways_app_settings` | Modify PHP, Varnish, Redis settings |
| `cloudways_clone_app` | Clone application to another server |
| `cloudways_staging_management` | Push/pull staging environments |

### Security (~10 tools)
| Tool | Description |
|------|-------------|
| `cloudways_bot_protection` | Configure bot protection |
| `cloudways_firewall_rules` | Manage IP whitelist/blacklist |
| `cloudways_ssl_management` | Install/renew Let's Encrypt certificates |
| `cloudways_2fa_settings` | Two-factor authentication config |
| `cloudways_ssh_keys` | Manage SSH key pairs |
| `cloudways_ip_whitelist` | Restrict access by IP |

### Backups & Monitoring (~10 tools)
| Tool | Description |
|------|-------------|
| `cloudways_list_backups` | List available backups |
| `cloudways_create_backup` | Take on-demand backup |
| `cloudways_restore_backup` | Restore from backup |
| `cloudways_backup_schedule` | Configure automated backup schedule |
| `cloudways_monitoring_alerts` | Configure CPU/RAM/disk alerts |
| `cloudways_log_viewer` | Tail application logs |

### Team & Access (~5 tools)
| Tool | Description |
|------|-------------|
| `cloudways_team_members` | List/manage team members |
| `cloudways_invite_member` | Invite new team member |
| `cloudways_access_control` | Role-based access configuration |

### DNS & Domains (~5 tools)
| Tool | Description |
|------|-------------|
| `cloudways_dns_records` | Manage DNS records |
| `cloudways_domain_management` | Add/remove domains from apps |
| `cloudways_cdn_configuration` | Cloudflare Enterprise integration |

## Admin Dashboard

The **Cloudways Dashboard** (under NV oOS → Cloudways) provides:

- **Server Overview** — All servers with real-time status indicators
- **Application Grid** — Per-server app listing with health status
- **Quick Actions** — One-click backup, restart services, view logs
- **Usage Charts** — Bandwidth, CPU, RAM over configurable time windows
- **Alert Feed** — Recent monitoring alerts with severity badges

## Settings

All configurable under **Settings → Connections → Cloudways**:

| Setting | Type | Description |
|---------|------|-------------|
| `cloudways_email` | email | Cloudways account email |
| `cloudways_api_key` | password | Cloudways API key (masked in UI) |
| `enable_cloudways_toolkit` | toggle | Enable/disable all Cloudways tools |
| `cloudways_default_server` | select | Default server for agent operations |

## Filters

| Filter | Type | Description |
|--------|------|-------------|
| `wp_mcp_ai_cloudways_api_base` | string | Override API base URL (default: `https://api.cloudways.com/api/v2`) |
| `wp_mcp_ai_cloudways_oauth_endpoint` | string | Override OAuth endpoint |
| `wp_mcp_ai_cloudways_request_timeout` | int | Override HTTP timeout (seconds) |
| `wp_mcp_ai_cloudways_tool_availability` | bool | Per-tool availability gate |

## Security

- API key stored as WordPress option with `wp_mcp_ai_` prefix
- OAuth tokens are short-lived (1 hour) and never exposed to LLM prompts
- All tools require `manage_options` capability
- HTTP requests use `wp_safe_remote_get`/`post` (SSRF-hardened)
- Rate-limited by Cloudways API (configurable via filter)

## Conventions

- All tools use canonical return envelope: `array` on success, `WP_Error` on failure
- Parameter schemas share common Cloudways fragments via `wp_mcp_ai_cloudways_param_*()` helpers
- Tool slugs follow pattern: `cloudways_{action}_{resource}`
- Folder README at `addons/pro/includes/cloudways/README.md`

## See Also

- [Cloudways API Documentation](https://api.cloudways.com/api/v2)
- [Unified Blueprint System](unified-blueprint-system.md)
- [CRM Toolkit](crm-toolkit.md)
