# Cloudways Toolkit Tools

This directory contains all 60 MCP tools for the Cloudways Pro Toolkit.

## Tool Categories

### Phase 1 — Read-Only Monitoring & Discovery (14 tools)
- [x] cloudways_list_servers — List all servers
- [x] cloudways_get_server — Get server details
- [x] cloudways_list_apps — List server applications
- [x] cloudways_get_app — Get application details
- [x] cloudways_service_status — Check service health
- [x] cloudways_server_monitor_summary — Bandwidth/disk usage
- [x] cloudways_app_monitor_summary — App usage metrics
- [x] cloudways_server_settings_get — PHP/MySQL config
- [x] cloudways_app_traffic_analytics — Traffic analysis
- [x] cloudways_app_php_analytics — PHP slow pages
- [x] cloudways_app_mysql_analytics — MySQL slow queries
- [x] cloudways_app_vulnerabilities_list — Security scan results
- [x] cloudways_list_projects — Account projects
- [x] cloudways_get_operation_status — Async task status

### Phase 2 — Safe Actions (10 tools)
- [x] cloudways_purge_app_cache — Purge all cache layers
- [x] cloudways_restart_service — Restart nginx/mysql/php-fpm
- [x] cloudways_create_app_backup — Backup an app
- [x] cloudways_create_server_backup — Backup a server
- [x] cloudways_update_server_label — Rename server
- [x] cloudways_update_app_label — Rename app
- [x] cloudways_git_pull — Deploy latest commits
- [x] cloudways_git_history_get — Deployment log
- [x] cloudways_app_cron_list_get — Cron job list
- [x] cloudways_app_credentials — SSH/SFTP credentials

### Phase 3 — Provisioning & Destructive (15 tools)
- [x] cloudways_server_start/stop/restart — Server power control
- [x] cloudways_server_scale — Resize CPU/RAM
- [x] cloudways_server_clone — Clone server
- [x] cloudways_server_create — Provision new server
- [x] cloudways_server_delete — Delete server (confirm required)
- [x] cloudways_app_create — Create application
- [x] cloudways_app_clone/_to_server — Clone applications
- [x] cloudways_app_delete — Delete app (confirm required)
- [x] cloudways_app_restore/_rollback — Backup restore
- [x] cloudways_app_cname_update — Change domain
- [x] cloudways_server_scale_volume — Resize disk

### Phase 4 — Add-ons, DNS, Cloudflare, SSH, Git, Copilot, Advanced (21 tools)
- [x] cloudways_addon_list/activate — Add-on management
- [x] cloudways_cloudflare_details/add_domain — Cloudflare CDN
- [x] cloudways_dns_list_domains/records, add/delete_record — DNS Made Easy
- [x] cloudways_ssh_key_create/delete/list — SSH key management
- [x] cloudways_git_generate_key/get/branches/clone — Full Git deployment
- [x] cloudways_copilot_insights_list — AI infrastructure insights
- [x] cloudways_app_fpm_settings_get/update — PHP-FPM tuning
- [x] cloudways_app_varnish_settings_get/update — Varnish cache config
- [x] cloudways_app_cors_headers_update — CORS configuration

## Implementation Status

**Phase 1:** ✅ Complete (14/14 tools)
**Phase 2:** ✅ Complete (10/10 tools)
**Phase 3:** ✅ Complete (15/15 tools)
**Phase 4:** ✅ Complete (21/21 tools)

**Total:** 60 tools — honoring the "58+" doc claim.

## Dependencies

- Cloudways account with API v2 credentials
- Pro addon active with `enable_cloudways_toolkit` setting enabled
- `WP_MCP_AI_Cloudways_Client` (in `../cloudways/class-wp-mcp-ai-cloudways-client.php`)
- `WP_MCP_AI_Tool_Cloudways_Base` (in `class-wp-mcp-ai-tool-cloudways-base.php`)
