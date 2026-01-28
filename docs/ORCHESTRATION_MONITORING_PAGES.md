# Orchestration and Monitoring Pages

This document clarifies the three separate orchestration/monitoring pages in NV oOS.

## Overview

There are **three distinct** monitoring and orchestration pages, each serving a different purpose:

1. **Orchestration Dashboard** (Base Plugin)
2. **Real-Time Monitor (Pro)** (Pro Addon)
3. **Monitoring Tab** (Pro Dashboard)

## 1. Orchestration Dashboard (Base Plugin)

**Location:** NV oOS → Orchestration  
**URL:** `/wp-admin/admin.php?page=mcp-ai-orchestration-dashboard`  
**File:** `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php`  
**Class:** `WP_MCP_AI_Admin_Orchestration_Dashboard`

### Purpose
Manages DeepSeek V4 multi-agent orchestration system configuration and workflows.

### Features
- View orchestration statistics and system overview
- Seed orchestration metadata and agent roles
- Configure multi-agent workflows
- Monitor workflow execution status
- Execute and restart workflows
- View orchestration health metrics

### Key Characteristics
- Available in **base plugin** (no Pro required)
- Focus on workflow **configuration and management**
- Provides seeding and setup tools
- Shows high-level orchestration statistics

## 2. Real-Time Monitor (Pro)

**Location:** NV oOS → Monitor (Pro)  
**URL:** `/wp-admin/admin.php?page=mcp-ai-orchestration`  
**File:** `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`  
**Class:** `WP_MCP_AI_Orchestration_Dashboard`

### Purpose
Real-time monitoring and management of autonomous AI sessions using Server-Sent Events (SSE).

### Features
- Live monitoring of active autonomous sessions
- Real-time session status updates via SSE
- Control session execution (pause, resume, stop)
- Trigger workflows directly from the dashboard
- View session metrics and performance data
- Ralph Wiggum pattern monitoring

### Key Characteristics
- Requires **Pro addon**
- Focus on **real-time monitoring** with SSE
- Live session control and management
- Autonomous session tracking
- 5-second auto-refresh interval

## 3. Monitoring Tab (Pro Dashboard)

**Location:** NV oOS Pro → Monitoring  
**URL:** `/wp-admin/admin.php?page=nvoos-pro-dashboard&tab=monitoring`  
**File:** `includes/admin/class-wp-mcp-ai-pro-dashboard.php`  
**Method:** `render_monitoring_tab()`

### Purpose
ISO/IEC 27001 compliance monitoring for security events and system health.

### Features
- Authentication event monitoring
- File integrity monitoring
- Access control event tracking
- Configuration change monitoring
- Supplier security event monitoring
- Compliance-related activity logs
- Event categorization and statistics

### Key Characteristics
- Part of **Pro Dashboard** (ISO 27001 compliance system)
- Focus on **security and compliance monitoring**
- Tracks security events, not orchestration workflows
- 24-hour event summaries
- Critical event highlighting

## Comparison Matrix

| Feature | Orchestration Dashboard | Real-Time Monitor (Pro) | Monitoring Tab (Pro) |
|---------|------------------------|------------------------|---------------------|
| **Availability** | Base Plugin | Pro Addon | Pro Dashboard |
| **Purpose** | Workflow Management | Session Monitoring | Security Monitoring |
| **Update Type** | Manual/On-demand | Real-time (SSE) | Event-driven |
| **Focus** | Configuration | Live Sessions | Compliance |
| **Menu Location** | NV oOS → Orchestration | NV oOS → Monitor (Pro) | NV oOS Pro → Monitoring |
| **Primary Users** | Admins configuring workflows | Admins monitoring sessions | Security/Compliance officers |

## When to Use Each Page

### Use Orchestration Dashboard When:
- Setting up new orchestration workflows
- Seeding orchestration metadata
- Viewing overall orchestration statistics
- Configuring agent roles and team structures
- Managing workflow execution settings

### Use Real-Time Monitor (Pro) When:
- Monitoring active autonomous AI sessions
- Needing live status updates of running sessions
- Controlling session execution in real-time
- Triggering workflows on-demand
- Tracking Ralph Wiggum autonomous patterns

### Use Monitoring Tab When:
- Reviewing security and compliance events
- Auditing authentication and access events
- Checking file integrity monitoring results
- Generating compliance reports
- Investigating security incidents

## Technical Implementation

### Menu Registration Order
1. **Priority 10**: Settings Dashboard creates parent menu `wp-mcp-ai-dashboard`
2. **Priority 20**: Base Orchestration Dashboard adds submenu
3. **Priority 25**: Pro Real-Time Monitor adds submenu
4. **Priority 999**: Menu reordering function runs

### File Structure
```
includes/admin/
├── class-wp-mcp-ai-admin-orchestration-dashboard.php  (Base)
├── class-wp-mcp-ai-pro-dashboard.php                  (Pro - Monitoring Tab)

addons/pro/includes/admin/
└── class-wp-mcp-ai-orchestration-dashboard.php        (Pro - Real-Time Monitor)
```

### Menu Slugs
- Base Orchestration: `mcp-ai-orchestration-dashboard`
- Pro Real-Time Monitor: `mcp-ai-orchestration`
- Pro Dashboard Monitoring: `nvoos-pro-dashboard` + `&tab=monitoring`

## Related Documentation
- [DeepSeek V4 Integration](../DEEPSEEK-V4-README.md)
- [Orchestration Dashboard Implementation](../ORCHESTRATION_DASHBOARD_IMPLEMENTATION_SUMMARY.md)
- [ISO 27001 Compliance](../WORDPRESS_ORG_COMPLIANCE_CERTIFICATION.md)
- [Pro Dashboard Architecture](../architecture/core/ARCHITECTURE-CORE-PRO.md)
