# Tools Manager - WP oOS

## Overview

The **Tools Manager** is a comprehensive interface for viewing and managing all 65+ AI tools available in Open Operator System. Located in the Tools & Features tab of the settings dashboard, it provides administrators with a centralized view of all registered tools, their availability status, and dependencies.

## Accessing the Tools Manager

1. Navigate to **Settings → WP oOS** in the WordPress admin
2. Click on the **Tools & Features** tab
3. The **Tools Manager** subtab is shown by default

Alternatively, you can access it directly via URL:
```
/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=tools_manager
```

## Features

### 1. Categorized Tool Display

Tools are organized into three main categories:

- **WordPress Core** (25+ tools) - Work with base WordPress installation without external dependencies
- **WordPress Plugins** (12+ tools) - Require specific third-party WordPress plugins
- **External Tools** (28+ tools) - Require external API credentials or service integrations

Each category shows:
- Number of tools in that category
- Expandable table with tool details
- Color-coded headers for easy identification

### 2. Tool Information

For each tool, the following information is displayed:

| Column | Description |
|--------|-------------|
| **Tool Name** | Human-readable name (auto-generated from slug) |
| **Slug** | Technical identifier used in code |
| **Description** | Brief explanation of what the tool does |
| **Status** | Available (green) or Unavailable (red) |
| **Actions** | Quick status indicators |

### 3. Dependency Checking

The Tools Manager automatically checks each tool's dependencies:

- **Available** - All requirements met, tool ready to use
- **Unavailable** - Missing dependencies shown below description
- **Missing dependencies** - Listed for each unavailable tool

#### Supported Dependency Checks

- Elementor Plugin
- WooCommerce Plugin
- JetEngine Plugin
- JetFormBuilder Plugin
- Rank Math SEO Plugin
- WPCode Plugin
- Simple JWT Login Plugin

### 4. Search & Filter

#### Search Tools
Use the search box to find tools by:
- Tool name (e.g., "search content")
- Tool slug (e.g., "get_woo_products")
- Description keywords (e.g., "email", "social media")

#### Filter by Category
Use the category dropdown to show only:
- All Categories (default)
- WordPress Core
- WordPress Plugins
- External Tools
- Other

#### Clear Filters
Click the **Clear** button to reset search and filters.

## Use Cases

### 1. Check Available Tools

**Scenario:** You want to see which tools are available for your assistants to use.

**Steps:**
1. Open Tools Manager
2. Review the status column for each tool
3. Green "Available" badges indicate ready-to-use tools
4. Red "Unavailable" badges show tools with missing dependencies

### 2. Identify Missing Plugins

**Scenario:** You want to know which plugins to install to unlock more tools.

**Steps:**
1. Open Tools Manager
2. Look for tools with "Unavailable" status
3. Check the "Missing:" section under each unavailable tool
4. Install the required plugins from WordPress.org

### 3. Find Specific Tool Type

**Scenario:** You need to find all WooCommerce-related tools.

**Steps:**
1. Open Tools Manager
2. Use search box: type "woo" or "woocommerce"
3. View filtered results showing only WooCommerce tools
4. Or select "WordPress Plugins" category to see all plugin-dependent tools

### 4. Audit Tool Availability

**Scenario:** Before configuring an assistant, you want to audit which tools are available.

**Steps:**
1. Open Tools Manager
2. Filter by "WordPress Core" to see always-available tools
3. Switch to "WordPress Plugins" to check plugin-dependent tools
4. Review "External Tools" to verify API integrations needed

## Technical Details

### Tool Categories

#### WordPress Core Tools
These tools work with any WordPress installation:
- Content management (search, create, update posts)
- User management (get user info, authentication)
- Site administration (health checks, logs, updates)
- Cron scheduling (create, list, manage jobs)
- Email functionality (send group emails)
- AI features (token counting, AI comments/media)

**Total:** 25+ tools

#### WordPress Plugins Tools
These tools require specific plugins:

| Plugin | Tools Available |
|--------|-----------------|
| Elementor | 1 tool |
| WooCommerce | 3 tools |
| JetEngine | 3 tools |
| JetFormBuilder | 2 tools |
| Rank Math SEO | 1 tool |
| WPCode | 1 tool |
| Simple JWT Login | 1 tool |

**Total:** 12+ tools

#### External Tools
These tools require API credentials or external services:
- Image generation (OpenAI DALL-E, Gemini Imagen)
- Speech & transcription (OpenAI TTS, Whisper)
- Social media posting (Facebook, Instagram, LinkedIn, TikTok)
- Communication (WhatsApp, Telegram, SMS)
- Email services (Mailjet, Gmail)
- Business services (Google Calendar, Analytics, QuickBooks)
- Web scraping & search (Crawl4AI, web search)
- Weather & events (Open-Meteo, GDACS, NHC storms)
- Cache management (Cloudflare, Varnish)

**Total:** 28+ tools

### Dependency Detection

The Tools Manager checks dependencies using:

```php
// Class exists check
class_exists( 'WooCommerce' )
class_exists( '\Elementor\Plugin' )

// Function exists check
function_exists( 'jet_engine' )
function_exists( 'rank_math' )
```

### Performance

- Tool data is loaded once per page load
- No database queries for dependency checks
- Category grouping done in PHP (no extra queries)
- Search and filtering performed client-side

## Best Practices

### For Site Administrators

1. **Regular Audits** - Check Tools Manager after plugin updates
2. **Document Requirements** - Note which tools need specific plugins
3. **Plan Installations** - Before enabling features, verify dependencies
4. **Monitor Availability** - Use search to quickly check tool status

### For Developers

1. **Reference Tool Slugs** - Use Tools Manager to find exact tool slugs
2. **Check Dependencies** - Verify requirements before using tools in code
3. **Test Availability** - Confirm tools are available in your environment
4. **Document Custom Tools** - If adding custom tools, ensure they appear here

### For Assistant Creators

1. **Verify Tools First** - Check Tools Manager before selecting tools for assistants
2. **Use Core Tools** - Prefer WordPress Core tools for maximum compatibility
3. **Document Plugin Needs** - If assistant needs plugins, note in description
4. **Test Thoroughly** - Confirm all selected tools show as "Available"

## Troubleshooting

### Tool Shows as Unavailable

**Problem:** A tool you need shows red "Unavailable" status

**Solutions:**
1. Check "Missing:" section under tool description
2. Install required plugin from WordPress.org
3. Activate the plugin
4. Refresh Tools Manager page
5. Verify tool now shows "Available"

### Search Returns No Results

**Problem:** Search doesn't find expected tools

**Solutions:**
1. Try shorter search terms (e.g., "post" instead of "create post")
2. Search by slug instead of name
3. Clear search and browse by category
4. Check spelling of search term

### Category Shows Empty

**Problem:** A category appears to have no tools

**Solutions:**
1. Clear any active search filter
2. Check if you're on the right tab (Tools & Features)
3. Verify plugin installation is complete
4. Try refreshing the page

### Missing Tools

**Problem:** Expected tools don't appear in list

**Solutions:**
1. Check if Base Version mode is enabled (filters some tools)
2. Verify WordPress and plugin versions are compatible
3. Check for PHP errors in debug.log
4. Confirm tool is registered in tool registry

## Related Documentation

- [Tool Reference](./tool-reference.md) - Complete list of all 65+ tools with examples
- [Tool Grouping](./tool-grouping.md) - Detailed categorization system
- [Tool Selection Presets](./tool-selection-presets.md) - Pre-configured tool sets for assistants
- [High Token Tool Handling](./high-token-tool-handling.md) - Managing resource-intensive tools
- [TOOL-TOKEN-LIMITS.md](./TOOL-TOKEN-LIMITS.md) - Token usage limits per tool

## Future Enhancements

Planned features for Tools Manager:

- [ ] Enable/disable individual tools globally
- [ ] Tool usage statistics and analytics
- [ ] Export tool list as CSV/JSON
- [ ] Tool dependency tree visualization
- [ ] Bulk tool actions
- [ ] Tool testing interface
- [ ] Custom tool registration UI

## Support

For questions or issues with the Tools Manager:

1. Check this documentation first
2. Review [tool-reference.md](./tool-reference.md) for tool details
3. Check [QUICK_REFERENCE.md](./QUICK_REFERENCE.md) for common tasks
4. Open an issue on GitHub
5. Contact support team

---

**Version:** 1.0.0  
**Last Updated:** November 2024  
**Component:** WP_MCP_AI_Section_Tools
