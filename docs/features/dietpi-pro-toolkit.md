# DietPi Pro Toolkit

> **Feature area:** Pro Server Management · **Phase:** Complete (Phases 0–3, v1.1.29)  
> **Scope:** Pro addon only · **Related:** `CLAUDE.md` § "DietPi Pro Toolkit"

## Overview

The DietPi Pro Toolkit provides AI-powered server management for [DietPi](https://dietpi.com/) single-board computers (Raspberry Pi, Odroid, Pine64, etc.). It exposes 19+ AI tools for system administration, backup, provisioning, and remote access through the NV oOS assistant framework.

Registered as an MCP server for remote management from external MCP clients (Claude Desktop, LM Studio, etc.).

## Phase Breakdown

### Phase 0–1: Foundations (19 tools)

Core system management tools:
- **System Info:** CPU temperature, memory usage, disk space, uptime, running services.
- **Package Management:** Install, update, remove packages via `dietpi-software`.
- **Service Control:** Start, stop, restart, enable, disable systemd services.
- **Network Diagnostics:** Ping, traceroute, interface status, bandwidth usage.
- **System Monitoring:** Real-time resource dashboards, log tailing.

### Phase 2: Backup, Update & Storage

Data protection and maintenance:
- **Backup:** Full system backup to local or remote storage (rsync, tar, dd).
- **Restore:** System restore from backup archives.
- **System Update:** `dietpi-update` wrapper with pre/post checks.
- **Storage Management:** Disk partitioning, mount management, filesystem health.
- **Dashboard:** Admin dashboard showing device inventory and health status.

### Phase 3: Provisioning, Blueprints & SSH Proxy

Advanced deployment and remote access:
- **Provisioning:** Automated DietPi installation and initial configuration.
- **Blueprints:** Reusable system configuration templates (packages, services, network).
- **SSH Proxy:** Secure tunneling to DietPi devices behind NAT/firewalls via the WordPress server.
- **Remote Access:** Web-based terminal access through the NV oOS admin interface.

## MCP Server Integration

The DietPi toolkit is registered as an MCP (Model Context Protocol) server:

```json
{
  "mcpServers": {
    "dietpi": {
      "url": "https://yoursite.com/wp-json/mcp-ai/v1/sse",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.YOUR_SECRET"
      }
    }
  }
}
```

This allows external MCP clients (Claude Desktop, LM Studio, etc.) to manage DietPi devices through the WordPress server — no direct SSH access needed.

## Admin Configuration

### Enable the Toolkit

1. Navigate to **Settings → NV oOS → Features → Pro Toolkits**.
2. Toggle **DietPi Pro Toolkit** to ON.
3. Configure device connections in **Tools → Connections**.

### Device Registration

Each DietPi device is registered as a connection:
- **Connection Type:** DietPi (SSH)
- **Host:** IP address or hostname
- **Port:** SSH port (default 22)
- **Authentication:** Password or SSH key

## Tools Reference

| Tool Slug | Description | Phase |
|-----------|-------------|-------|
| `dietpi_system_info` | Get system information (CPU, RAM, disk, temp) | 0–1 |
| `dietpi_package_install` | Install a package via dietpi-software | 0–1 |
| `dietpi_package_update` | Update installed packages | 0–1 |
| `dietpi_service_control` | Start/stop/restart a systemd service | 0–1 |
| `dietpi_network_diag` | Run network diagnostics | 0–1 |
| `dietpi_system_monitor` | Real-time system resource monitoring | 0–1 |
| `dietpi_backup_create` | Create a system backup | 2 |
| `dietpi_backup_restore` | Restore from a backup archive | 2 |
| `dietpi_system_update` | Run dietpi-update | 2 |
| `dietpi_storage_manage` | Manage disks and mount points | 2 |
| `dietpi_device_dashboard` | View device inventory and health | 2 |
| `dietpi_provision` | Provision a new DietPi installation | 3 |
| `dietpi_blueprint_apply` | Apply a system configuration blueprint | 3 |
| `dietpi_ssh_proxy` | Establish SSH proxy tunnel | 3 |
| `dietpi_remote_terminal` | Open web-based terminal session | 3 |

## Security

- All SSH connections use key-based authentication (password auth disabled by default).
- SSH proxy tunnels are encrypted end-to-end.
- Device connections are stored with encrypted credentials in the WordPress database.
- `manage_dietpi` capability required for all DietPi tools.

## Related Files

- `addons/pro/includes/tools/dietpi/` — Tool implementations (19+ files)
- `addons/pro/includes/admin/` — Admin UI and settings
- `docs/project/proposals/dietpi-pro-toolkit.md` — Full implementation proposal

## See Also

- [DietPi Official Documentation](https://dietpi.com/docs/)
- [MCP Server Integration](mcp-servers.md)
- [Pro Addon Overview](../project/ADDON_INVENTORY.md)
