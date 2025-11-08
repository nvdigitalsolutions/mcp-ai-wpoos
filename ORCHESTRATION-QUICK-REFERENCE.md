# Orchestration Dashboard - Quick Reference Card

## 🚀 Quick Start

### Access the Orchestration Dashboard
```
WordPress Admin → WP oOS → General Settings → Orchestration tab
```

### Access Individual Integration Pages
```
WordPress Admin → WP oOS → [Integration Name]
```
- JetEngine: `admin.php?page=wp-mcp-ai-jetengine`
- WooCommerce: `admin.php?page=wp-mcp-ai-woocommerce`
- Elementor: `admin.php?page=wp-mcp-ai-elementor`
- Gmail & Crawl4AI: `admin.php?page=wp-mcp-ai-gmail-crawl4ai`

---

## 📊 Orchestration Metrics

### Real-Time Dashboard Shows:
- **Workload Tier**: Low / Medium / High (based on PHP memory)
- **Max Tokens**: Allocated token budget
- **Request Timeout**: API call timeout in seconds
- **Active Cron Jobs**: Number of scheduled tasks

---

## ⚙️ Orchestration Controls

### Feature Toggles:
- ☑️ **Dynamic Budget Management** - Auto-adjust token budgets
- ☑️ **Predictive Optimization** - Prevent resource exhaustion  
- ☑️ **Capability-Based Tool Gating** - Enforce role permissions
- ☑️ **Cron-Based Task Orchestration** - Manage scheduled tasks

---

## 🔧 Integration Configuration

### JetEngine Settings
- `enable_jetengine_cct` - CCT storage (default: true)
- `enable_jetengine_tools` - AI tools (default: true)

**5 Tools Available**:
- `jetengine_create_post_type`
- `jetengine_create_taxonomy`
- `jetengine_query_cct`
- `jetengine_create_cct_item`
- `jetengine_update_cct_item`

### WooCommerce Settings
- `enable_woocommerce_tools` - E-commerce tools (default: true)
- `enable_woo_analytics` - Sales analytics (default: true)

**5 Tools Available**:
- `woo_create_product`
- `woo_update_product`
- `woo_query_orders`
- `woo_get_analytics`
- `woo_manage_inventory`

### Elementor Settings
- `enable_elementor_widgets` - AI widgets (default: true)

**3 Widgets Available**:
- WP oOS Chat
- Assistant Selector
- Chat History

### Gmail & Crawl4AI Settings
- `gmail_client_id` - OAuth Client ID
- `gmail_client_secret` - OAuth Client Secret
- `crawl4ai_base_url` - Crawler service URL
- `crawl4ai_api_key` - Crawler API key

**Gmail Tools**:
- `gmail_send_email`
- `gmail_search_messages`
- `gmail_create_draft`

**Crawl4AI Tools**:
- `crawl_webpage`
- `scrape_structured_data`
- `crawl_sitemap`

---

## 🎯 Quick Actions

From Orchestration tab:
- **[Manage Cron Jobs]** → Cron Manager page
- **[View Token Manager]** → Token Manager tab
- **[Run Diagnostics]** → Diagnostic tools

---

## 📁 File Locations

### Admin Pages
```
includes/admin/
├── class-wp-mcp-ai-admin-jetengine.php
├── class-wp-mcp-ai-admin-woocommerce.php
├── class-wp-mcp-ai-admin-elementor.php
└── class-wp-mcp-ai-admin-gmail-crawl.php
```

### Section Classes
```
includes/admin/sections/
├── class-wp-mcp-ai-section-orchestration.php
├── class-wp-mcp-ai-section-jetengine.php
├── class-wp-mcp-ai-section-woocommerce.php
├── class-wp-mcp-ai-section-elementor.php
└── class-wp-mcp-ai-section-integrations.php
```

### Settings Registry
```
includes/admin/
├── class-wp-mcp-ai-settings-registry.php
└── settings-dashboard-init.php
```

---

## 💾 Settings Storage

All settings saved in WordPress option: `wp_mcp_ai_settings`

**Orchestration Settings**:
- `enable_budget_management`
- `enable_predictive_optimization`
- `enable_capability_gating`
- `enable_cron_orchestration`

**Integration Settings**:
- `enable_jetengine_cct`
- `enable_jetengine_tools`
- `enable_woocommerce_tools`
- `enable_woo_analytics`
- `enable_elementor_widgets`
- `gmail_client_id`
- `gmail_client_secret`
- `crawl4ai_base_url`
- `crawl4ai_api_key`

---

## 🔍 Plugin Detection

Each integration page detects if required plugin is active:

✅ **Green Banner** = Plugin active  
⚪ **Grey Banner** = Plugin inactive (shows setup instructions)

**Detected Plugins**:
- JetEngine: `class_exists( 'Jet_Engine' )`
- WooCommerce: `class_exists( 'WooCommerce' )`
- Elementor: `defined( 'ELEMENTOR_VERSION' )`

---

## 🎓 Documentation Map

1. **Quick Start** → This file
2. **Visual Guide** → `ORCHESTRATION-DASHBOARD-VISUAL-GUIDE.md`
3. **Implementation** → `ORCHESTRATION-DASHBOARD-IMPLEMENTATION.md`
4. **Complete Summary** → `ORCHESTRATION-DASHBOARD-SUMMARY.md`
5. **Architecture** → `docs/ORCHESTRATION-LAYER-ARCHITECTURE.md`

---

## 🛠️ Troubleshooting

### Cron Manager Not Visible?
- Check: Is new dashboard enabled? (`WP_MCP_AI_USE_OLD_SETTINGS` should be `false`)
- Location: `WP oOS → Cron Manager` (submenu)
- Direct URL: `admin.php?page=wp-mcp-ai-cron-manager`

### Integration Page Not Showing?
- Check: Is plugin active?
- Check: Do you have `manage_options` capability?
- Verify: Settings dashboard is enabled

### Settings Not Saving?
- Check: WordPress option `wp_mcp_ai_settings` exists
- Verify: Nonce validation passing
- Test: Save from both tab and dedicated page

---

## 📞 Support

- **Issues**: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- **Documentation**: `/docs/` directory
- **Architecture**: `docs/ORCHESTRATION-LAYER-ARCHITECTURE.md`

---

## 🚦 Status Indicators

### Plugin Status
- **✅ Active** - Green banner, features enabled
- **⚪ Inactive** - Grey banner, setup instructions shown

### Tool Status
- **✓ Active** - Green checkmark, tool available
- **Disabled** - Grey text, tool not available

### Orchestration Metrics
- **Low Tier** - < 128 MB memory, 1,000 tokens
- **Medium Tier** - 128-512 MB memory, 4,000 tokens
- **High Tier** - > 512 MB memory, 16,000 tokens

---

## ⚡ Pro Tips

1. **Dual Access** - Configure via tab OR dedicated page
2. **Quick Actions** - Use buttons to jump between related pages
3. **Status First** - Check plugin status before configuring
4. **Save Often** - Each page saves independently
5. **Full Version** - Set `WP_MCP_AI_BASE_VERSION = false` for all features

---

**Version**: 1.0.0  
**Last Updated**: November 8, 2024  
**Plugin**: WP Open Operator System (WP oOS)
