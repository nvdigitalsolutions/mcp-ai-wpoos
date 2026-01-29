# Pro Add-on Installation Instructions

**Package:** nvdigital-oos-pro-1.0.0.zip  
**Version:** 1.0.0  
**Type:** Commercial/Self-hosted Distribution  
**Requires:** Base plugin installed and activated

---

## Prerequisites

**IMPORTANT:** The base plugin MUST be installed first!

1. Install base plugin from WordPress.org:
   - Go to: https://wordpress.org/plugins/nvdigital-open-operator-system-oos/
   - OR: Plugins → Add New → Search "NV Digital Open Operator System"

2. Activate the base plugin

3. Verify base plugin is working:
   - Go to: WordPress Admin → NV oOS → Settings
   - Configure at least one AI provider (OpenAI, Gemini, or Ollama)
   - Test chat functionality

---

## Installation Steps

### Method 1: WordPress Admin (Recommended)

1. Go to: WordPress Admin → Plugins → Add New → Upload Plugin
2. Click "Choose File"
3. Select: `nvdigital-oos-pro-1.0.0.zip`
4. Click "Install Now"
5. Click "Activate Plugin"
6. Verify: Go to NV oOS → Tools Manager (should see 70+ additional Pro tools)

### Method 2: Manual Upload

1. Extract `nvdigital-oos-pro-1.0.0.zip`
2. Upload the extracted folder to: `/wp-content/plugins/`
3. Go to: WordPress Admin → Plugins
4. Find "NV Digital Open Operator System (oOS) - Pro Add-on"
5. Click "Activate"

### Method 3: WP-CLI

```bash
wp plugin install nvdigital-oos-pro-1.0.0.zip --activate
```

---

## Verification

After activation, verify Pro features are available:

1. **Tool Count:**
   - Go to: NV oOS → Tools Manager
   - Should see 197+ total tools (127 base + 70 Pro)

2. **Pro Tools Visible:**
   - WooCommerce tools (if WooCommerce installed)
   - Social media tools (Facebook, Twitter, LinkedIn)
   - GitHub integration tools
   - Google services tools
   - Document generation tools (PDF, Word, Excel)

3. **Pro Badge:**
   - Look for "Pro" badges on tools in the tool manager
   - Pro tools have yellow/gold badge indicator

---

## Troubleshooting

### "Base plugin is required" Error

**Problem:** You see an error message about base plugin being required.

**Solution:**
1. Make sure base plugin is installed: `NV Digital Open Operator System (oOS)`
2. Make sure base plugin is activated
3. Deactivate and reactivate Pro add-on
4. Clear any caching plugins

### Pro Tools Not Showing

**Problem:** Only 127 tools showing, Pro tools missing.

**Solution:**
1. Deactivate Pro add-on
2. Reactivate Pro add-on
3. Go to: NV oOS → Settings → Advanced → Clear tool cache
4. Refresh page
5. Check tool count: should be 197+ tools

### Version Mismatch Warning

**Problem:** Warning about base/Pro version compatibility.

**Solution:**
1. Update base plugin to latest version
2. Update Pro add-on to matching version
3. Pro version should match or be compatible with base version

---

## Support

For technical support:
- Email: support@nvdigitalsolutions.com
- Documentation: https://nvdigitalsolutions.com/wpoos-pro/docs
- Issue Tracker: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

## License

This is proprietary software. All rights reserved.
Patent Pending (Application #19/410,504).

Use is subject to the license agreement provided with your purchase.

