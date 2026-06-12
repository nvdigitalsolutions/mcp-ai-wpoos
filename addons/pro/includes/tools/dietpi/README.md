# DietPi Toolkit — Tool Classes

> 19 tools for Raspberry Pi / DietPi server and media app management via SSH and REST APIs.

## Purpose

Houses every DietPi Pro Toolkit tool implementation — system-level SSH command tools and per-app REST API tools for Transmission, Jackett, Sonarr, Radarr, Plex, and Jellyfin.

## Tool Inventory

| Tool | Slug | Type |
|------|------|------|
| Send SSH Command | `dietpi_send_ssh_command` | System |
| List Services | `dietpi_list_services` | System |
| Control Service | `dietpi_control_service` | System |
| System Info | `dietpi_system_info` | System |
| System Stats | `dietpi_system_stats` | System |
| List Transmission Torrents | `dietpi_list_transmission` | Transmission |
| Add Transmission Torrent | `dietpi_add_transmission` | Transmission |
| Control Transmission Torrents | `dietpi_control_transmission` | Transmission |
| Search Jackett Indexers | `dietpi_search_jackett` | Jackett |
| List Jackett Indexers | `dietpi_list_jackett_indexers` | Jackett |
| List Sonarr Series | `dietpi_list_sonarr_series` | Sonarr |
| Add Sonarr Series | `dietpi_add_sonarr_series` | Sonarr |
| Manage Sonarr | `dietpi_manage_sonarr` | Sonarr |
| List Radarr Movies | `dietpi_list_radarr_movies` | Radarr |
| Add Radarr Movie | `dietpi_add_radarr_movie` | Radarr |
| Manage Radarr | `dietpi_manage_radarr` | Radarr |
| Media Center | `dietpi_media_center` | Plex / Jellyfin |
| Health Check | `dietpi_health_check` | Cross-app |
| Media Request Flow | `dietpi_media_request_flow` | Cross-app |
| Backup System | `dietpi_backup_system` | System (Phase 2) |
| Update System | `dietpi_update_system` | System (Phase 2) |
| Manage Storage | `dietpi_manage_storage` | System (Phase 2) |
| Dashboard Summary | `dietpi_dashboard_summary` | System (Phase 2) |
| Provision New App | `dietpi_provision_new_app` | System (Phase 3) |

## Dependencies

- `WP_MCP_AI_Tool_DietPi_Base` (abstract base)
- `WP_MCP_AI_DietPi_SSH_Client` (SSH system interaction)
- `WP_MCP_AI_DietPi_App_Client` (HTTP API interaction)
- `WP_MCP_AI_DietPi_Service_Catalogue` (service registry)
- `WP_MCP_AI_DietPi_Helpers` (gate functions, schema fragments)

## Registration

Tools are registered in `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.  The conditional loader lives in `addons/pro/includes/dietpi-toolkit-init.php`.

## See Also

- [DietPi client classes](../../dietpi/)
- [DietPi Toolkit Settings Page](../../admin/class-wp-mcp-ai-dietpi-settings-page.php)
- [Proposal](../../../../docs/project/proposals/dietpi-pro-toolkit.md)
