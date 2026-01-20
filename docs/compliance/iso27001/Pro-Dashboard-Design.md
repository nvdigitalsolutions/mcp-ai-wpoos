# NV oOS Pro Dashboard - Design Specification
## ISO/IEC 27001 Compliance Dashboard

**Document Classification:** Internal  
**Version:** 1.0.0  
**Date:** 2026-01-05  
**Status:** Design Proposal

---

## 1. Executive Summary

The **NV oOS Pro Dashboard** is a dedicated WordPress admin section providing enterprise-grade compliance monitoring, reporting, and management tools. It addresses the need for a clean separation between core plugin settings and premium compliance features.

### Problem Statement
The core NV oOS settings page already contains many tabs (Overview, Providers, Authentication, Security, Tools, Integrations, Advanced). Adding pro compliance features would create clutter and confuse free users.

### Solution
Create a separate top-level admin menu "NV oOS Pro Dashboard" that provides:
- Focused compliance workspace for enterprise customers
- Clean separation from core settings
- Room for future pro feature expansion
- Professional branding and presentation

---

## 2. Admin Menu Structure

### 2.1 WordPress Admin Navigation

```
WordPress Admin Sidebar
├── Dashboard
├── Posts
├── Media
├── Pages
├── ...
├── Settings
│   └── NV oOS (Core - Free) ← Existing settings
│       ├── Overview
│       ├── Providers
│       ├── Authentication
│       ├── Security [Shows ISO 27001 certified badge]
│       ├── Tools
│       ├── Integrations
│       └── Advanced
│
└── NV oOS Pro Dashboard (NEW - Top-level menu with icon) 🔒
    ├── Compliance Overview
    ├── ISO 27001 Management
    ├── Audit & Reporting
    ├── Security Monitoring
    ├── Multi-Framework Compliance
    ├── Risk Management
    ├── Incident Management
    └── Professional Services
```

### 2.2 Menu Item Details

**Top-Level Menu:**
- **Label:** "NV oOS Pro"
- **Icon:** Dashboard/shield icon with "Pro" badge
- **Capability Required:** `manage_options` + pro license active
- **Position:** After main WordPress menus, before separator

**Submenu Items:**
```php
add_menu_page(
    'NV oOS Pro Dashboard',
    'NV oOS Pro',
    'manage_options',
    'nvoos-pro-dashboard',
    'render_pro_dashboard',
    'dashicons-shield-alt',
    25
);

add_submenu_page( 'nvoos-pro-dashboard', 'Compliance Overview', 'Overview', ... );
add_submenu_page( 'nvoos-pro-dashboard', 'ISO 27001', 'ISO 27001', ... );
add_submenu_page( 'nvoos-pro-dashboard', 'Audit & Reporting', 'Reports', ... );
// ... etc
```

---

## 3. Dashboard Pages

### 3.1 Compliance Overview (Landing Page)

**Purpose:** At-a-glance compliance status and quick actions

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ NV oOS Pro Dashboard > Compliance Overview            [Pro Badge]│
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │           ISO 27001 Compliance Status: 78%                │  │
│  │  [████████████████████░░░░░░░░]                          │  │
│  │  52 Implemented | 26 Partial | 3 Planned | 12 N/A        │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌───────────────────┐  ┌───────────────────┐  ┌─────────────┐│
│  │ Control Status    │  │ Risk Register     │  │ Recent      ││
│  │ ─────────────────│  │ ─────────────────│  │ Events      ││
│  │ ✅ Implemented 52│  │ 🔴 Critical: 2   │  │ ⚠️ Failed   ││
│  │ 🔄 Partial    26 │  │ 🟠 High: 5       │  │    auth     ││
│  │ 📋 Planned     3 │  │ 🟡 Medium: 12    │  │ ✅ Backup   ││
│  │ ❌ N/A        12 │  │ 🟢 Low: 8        │  │    complete ││
│  └───────────────────┘  └───────────────────┘  └─────────────┘│
│                                                                   │
│  Quick Actions:                                                   │
│  [Generate Compliance Report] [Run Security Scan]                │
│  [Export Audit Trail] [Schedule Assessment]                      │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Control Effectiveness Heatmap (A.5-A.8)                  │  │
│  │                                                            │  │
│  │  A.5 Org   [██████████████████▒▒▒▒▒▒▒▒▒▒▒] 60%          │  │
│  │  A.6 People[██████████▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒] 40%          │  │
│  │  A.7 Phys  [████▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒▒] 20%          │  │
│  │  A.8 Tech  [█████████████████████████▒▒▒▒▒] 90%          │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                   │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Upcoming Reviews & Audits                                 │  │
│  │ • Internal Audit: January 15, 2026                        │  │
│  │ • Management Review: January 30, 2026                     │  │
│  │ • External Certification Audit: March 2026 (Scheduled)    │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

**Features:**
- Real-time compliance percentage
- Visual status indicators (colors, progress bars)
- Quick action buttons
- Heatmap visualization
- Upcoming events calendar
- Recent security events feed

### 3.2 ISO 27001 Management

**Purpose:** Detailed control management and status tracking

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ NV oOS Pro Dashboard > ISO 27001 Management                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ Filter: [All Controls ▼] [Status: All ▼] [Search controls...]   │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Annex A.5: Organizational Controls (37 controls)             ││
│ │                                                               ││
│ │ ├─ A.5.1 Policies for Information Security                   ││
│ │ │  Status: ✅ Implemented | Evidence: [View] | [Edit]       ││
│ │ │  Last Review: 2026-01-05 | Next Review: 2026-04-05        ││
│ │ │                                                             ││
│ │ ├─ A.5.2 Information Security Roles                          ││
│ │ │  Status: ✅ Implemented | Evidence: [View] | [Edit]       ││
│ │ │                                                             ││
│ │ ├─ A.5.8 Information Security in Project Management          ││
│ │ │  Status: 🔄 Partial (60%) | [Complete Implementation]     ││
│ │ │  Gaps: Formal security architecture reviews                ││
│ │ │  Action: [Create Action Plan] | Owner: Security Team       ││
│ │ │                                                             ││
│ │ └─ A.5.10 Acceptable Use of Information                      ││
│ │    Status: 📋 Planned | Target: Q2 2026                     ││
│ │    Action: [Create Policy] | [Assign Owner]                  ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│ Actions: [Export Statement of Applicability] [Generate Gap Report]│
└─────────────────────────────────────────────────────────────────┘
```

**Features:**
- Expandable/collapsible control categories
- Status badges (Implemented, Partial, Planned, N/A)
- Progress indicators for partial controls
- Evidence document links
- Action plan management
- Review date tracking
- Export capabilities

### 3.3 Audit & Reporting

**Purpose:** Generate compliance reports and export audit data

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ NV oOS Pro Dashboard > Audit & Reporting                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Report Generator                                             ││
│ │                                                               ││
│ │ Report Type: [ISO 27001 Status Report ▼]                    ││
│ │                                                               ││
│ │ Options:                                                      ││
│ │ ☑ Include control status                                     ││
│ │ ☑ Include risk register                                      ││
│ │ ☑ Include recent incidents                                   ││
│ │ ☑ Include recommendations                                    ││
│ │ ☐ Include detailed evidence                                  ││
│ │                                                               ││
│ │ Date Range: [Last 90 days ▼]                                ││
│ │ Format: [PDF ▼] [DOCX] [Excel]                              ││
│ │                                                               ││
│ │ [Generate Report] [Schedule Recurring Report]                ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Pre-configured Reports                                       ││
│ │                                                               ││
│ │ [Executive Summary]     - High-level compliance overview     ││
│ │ [Control Status Report] - Detailed control implementation    ││
│ │ [Gap Analysis]          - Identify control gaps              ││
│ │ [Risk Register Report]  - Current risk status                ││
│ │ [Audit Trail Export]    - Security event logs                ││
│ │ [Evidence Package]      - All evidence documents             ││
│ │ [Third-Party Audit Kit] - Certification preparation          ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Recent Reports                                               ││
│ │ • ISO 27001 Status Report - 2026-01-05.pdf [Download]       ││
│ │ • Executive Summary - 2026-01-01.pdf [Download]              ││
│ │ • Gap Analysis - 2025-12-15.xlsx [Download]                 ││
│ └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

**Features:**
- Custom report builder
- Pre-configured report templates
- Multiple export formats (PDF, DOCX, Excel)
- Scheduled reports (email delivery)
- Report history and archive
- White-label options (Enterprise tier)

### 3.4 Risk Management

**Purpose:** Interactive risk register and assessment tools

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│ NV oOS Pro Dashboard > Risk Management                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Risk Heatmap (5x5 Matrix)                                    ││
│ │                                                               ││
│ │     Impact →                                                  ││
│ │ L  │ 1-VL │ 2-L  │ 3-M  │ 4-H  │ 5-C  │                     ││
│ │ i  ├──────┼──────┼──────┼──────┼──────┤                     ││
│ │ k  │      │      │      │  🔴  │  🔴  │ 5-VH                 ││
│ │ e  ├──────┼──────┼──────┼──────┼──────┤                     ││
│ │ l  │      │      │      │  🟠  │      │ 4-H                  ││
│ │ i  ├──────┼──────┼──────┼──────┼──────┤                     ││
│ │ h  │      │  🟡  │  🟡  │  🟡  │      │ 3-M                  ││
│ │ o  ├──────┼──────┼──────┼──────┼──────┤                     ││
│ │ o  │  🟢  │  🟢  │  🟢  │      │      │ 2-L                  ││
│ │ d  ├──────┼──────┼──────┼──────┼──────┤                     ││
│ │    │      │      │      │      │      │ 1-VL                 ││
│ │    └──────┴──────┴──────┴──────┴──────┘                     ││
│ └─────────────────────────────────────────────────────────────┘│
│                                                                   │
│ [Add New Risk] [Import Risks] [Export Register]                 │
│                                                                   │
│ ┌─────────────────────────────────────────────────────────────┐│
│ │ Risk Register (27 total risks)                               ││
│ │                                                               ││
│ │ Filter: [Critical/High ▼] [Status: Open ▼] [Search...]      ││
│ │                                                               ││
│ │ ┌───────────────────────────────────────────────────────────┴│
│ │ │ RISK-001: API Key Exposure                         🔴 High ││
│ │ │ Inherent: 15 | Residual: 8 | Treatment: In Progress       ││
│ │ │ Owner: Security Team | Review: 2026-03-01                  ││
│ │ │ [View Details] [Update] [Close]                            ││
│ │ ├────────────────────────────────────────────────────────────││
│ │ │ RISK-002: SQL Injection                            🟢 Low  ││
│ │ │ Inherent: 20 | Residual: 5 | Treatment: Implemented       ││
│ │ │ Owner: Development Team | Review: 2026-02-01               ││
│ │ │ [View Details] [Update]                                    ││
│ │ └────────────────────────────────────────────────────────────││
│ └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
```

**Features:**
- Interactive 5x5 risk heatmap
- Click to drill down into risks
- Risk register table with filtering
- Risk assessment wizard
- Treatment plan tracking
- Automatic risk score calculation
- Trend analysis and reporting

---

## 4. Technical Implementation

### 4.1 File Structure

```
addons/pro/includes/admin/
├── class-wp-mcp-ai-pro-dashboard.php          (Main dashboard class)
├── class-wp-mcp-ai-pro-dashboard-overview.php (Overview page)
├── class-wp-mcp-ai-pro-dashboard-iso27001.php (ISO controls)
├── class-wp-mcp-ai-pro-dashboard-reports.php  (Reporting)
├── class-wp-mcp-ai-pro-dashboard-risk.php     (Risk management)
└── class-wp-mcp-ai-pro-dashboard-monitoring.php

addons/pro/assets/
├── css/
│   └── pro-dashboard.css                       (Dashboard styles)
└── js/
    ├── pro-dashboard.js                        (Main dashboard JS)
    ├── compliance-charts.js                    (Chart.js integration)
    └── risk-heatmap.js                         (Interactive heatmap)
```

### 4.2 Class Structure

```php
/**
 * NV oOS Pro Dashboard main class
 */
class WP_MCP_AI_Pro_Dashboard {
    
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }
    
    public function register_menu() {
        // Only if pro license active
        if ( ! $this->is_pro_active() ) {
            return;
        }
        
        add_menu_page(
            __( 'NV oOS Pro Dashboard', 'wp-mcp-ai' ),
            __( 'NV oOS Pro', 'wp-mcp-ai' ),
            'manage_options',
            'nvoos-pro-dashboard',
            array( $this, 'render_overview' ),
            'dashicons-shield-alt',
            25
        );
        
        // Submenu pages
        $this->register_submenus();
    }
    
    private function register_submenus() {
        $pages = array(
            'overview' => __( 'Overview', 'wp-mcp-ai' ),
            'iso27001' => __( 'ISO 27001', 'wp-mcp-ai' ),
            'reports' => __( 'Reports', 'wp-mcp-ai' ),
            'monitoring' => __( 'Security Monitoring', 'wp-mcp-ai' ),
            'frameworks' => __( 'Multi-Framework', 'wp-mcp-ai' ),
            'risk' => __( 'Risk Management', 'wp-mcp-ai' ),
            'incidents' => __( 'Incidents', 'wp-mcp-ai' ),
            'services' => __( 'Professional Services', 'wp-mcp-ai' ),
        );
        
        foreach ( $pages as $slug => $title ) {
            add_submenu_page(
                'nvoos-pro-dashboard',
                $title,
                $title,
                'manage_options',
                'nvoos-pro-' . $slug,
                array( $this, 'render_' . $slug )
            );
        }
    }
}
```

### 4.3 Data Storage

**Options Table:**
```
wp_options:
├── nvoos_pro_compliance_status        (Serialized compliance data)
├── nvoos_pro_risk_register            (Risk register data)
├── nvoos_pro_control_status           (93 controls status)
├── nvoos_pro_audit_schedule           (Scheduled audits)
└── nvoos_pro_report_history           (Generated reports)
```

**Custom Tables (if needed for performance):**
```sql
CREATE TABLE {$wpdb->prefix}nvoos_pro_compliance_log (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    event_type varchar(50) NOT NULL,
    control_id varchar(10),
    status varchar(20),
    details text,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY control_id (control_id),
    KEY event_type (event_type),
    KEY created_at (created_at)
);

CREATE TABLE {$wpdb->prefix}nvoos_pro_risk_register (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    risk_id varchar(20) UNIQUE NOT NULL,
    title varchar(255) NOT NULL,
    description text,
    likelihood int(1),
    impact int(1),
    inherent_risk int(2),
    residual_risk int(2),
    treatment varchar(50),
    status varchar(20),
    owner varchar(100),
    review_date date,
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY risk_id (risk_id),
    KEY status (status),
    KEY review_date (review_date)
);
```

### 4.4 REST API Endpoints

```
GET  /wp-json/nvoos-pro/v1/compliance/status
GET  /wp-json/nvoos-pro/v1/compliance/controls
PUT  /wp-json/nvoos-pro/v1/compliance/controls/{control_id}
GET  /wp-json/nvoos-pro/v1/risk/register
POST /wp-json/nvoos-pro/v1/risk/register
PUT  /wp-json/nvoos-pro/v1/risk/register/{risk_id}
POST /wp-json/nvoos-pro/v1/reports/generate
GET  /wp-json/nvoos-pro/v1/reports/download/{report_id}
```

---

## 5. User Experience Flow

### 5.1 First-Time Pro User

1. User activates pro license
2. WordPress admin menu shows new "NV oOS Pro" item with badge
3. Click opens Compliance Overview with onboarding wizard
4. Wizard walks through:
   - ISO 27001 compliance goals
   - Initial control assessment
   - Risk register setup
   - Report scheduling
5. Dashboard shows current state with actionable next steps

### 5.2 Regular Usage

1. Daily: Check Compliance Overview dashboard
2. Weekly: Review security monitoring alerts
3. Monthly: Generate compliance report for management
4. Quarterly: Conduct internal audit using dashboard
5. Annually: Prepare for external certification audit

---

## 6. Benefits Summary

### For Free Users
- ✅ No clutter in core settings
- ✅ Clear what's free vs pro
- ✅ ISO 27001 certification badge visible in core Security tab

### For Pro Users
- ✅ Dedicated professional workspace
- ✅ All compliance tools in one place
- ✅ Enterprise-grade UI and features
- ✅ Clear value for premium investment

### For Development Team
- ✅ Clean code separation (core vs pro)
- ✅ Easier to maintain and extend
- ✅ Better testing isolation
- ✅ Flexible pro feature additions

---

## 7. Implementation Timeline

**Phase 1: Core Dashboard (Month 10)**
- Basic menu structure
- Compliance Overview page
- ISO 27001 control status viewer

**Phase 2: Reporting (Month 11)**
- Report generator
- PDF export
- Pre-configured templates

**Phase 3: Advanced Features (Month 12)**
- Risk management dashboard
- SIEM integrations
- Multi-framework support

**Phase 4: Polish & Launch (Month 13)**
- UX refinements
- Documentation
- Marketing launch

---

## 8. Success Metrics

- **Adoption Rate:** % of free users who upgrade to pro
- **Usage Frequency:** Logins to pro dashboard per week
- **Report Generation:** Average reports generated per user
- **Customer Satisfaction:** NPS score for pro dashboard
- **Support Tickets:** Reduction in compliance-related support

---

## Approval

| Role | Recommendation | Date |
|------|---------------|------|
| Product | Approve Design | 2026-01-05 |
| UX/Design | Approve Layout | 2026-01-05 |
| Engineering | Feasible | 2026-01-05 |

---

**Status:** APPROVED - Ready for Development
