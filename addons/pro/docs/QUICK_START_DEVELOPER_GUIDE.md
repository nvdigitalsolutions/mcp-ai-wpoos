# Quick Start Guide - New Pro Toolkits Foundation

**For Developers Building New Toolkit Tools**

This guide helps you quickly understand and work with the new Pro Toolkits foundation.

---

## ⚡ TL;DR (Too Long; Didn't Read)

```bash
# 1. Install dependencies (one time)
cd addons/pro
npm install

# 2. Build vendor packages
npm run build

# 3. Start developing tools
# Create tool files in addons/pro/includes/tools/{toolkit-name}/
# Follow existing tool patterns from other toolkits
```

---

## 📁 What Was Created

### 5 New Toolkits (Service Pattern)

| Toolkit | Directory | Init File | Tools Planned | Status |
|---------|-----------|-----------|---------------|--------|
| 🛒 E-commerce | `tools/ecommerce/` | `ecommerce-toolkit-init.php` | 20 tools | Phase 2 |
| 📱 Social Media | `tools/social-media/` | `social-media-toolkit-init.php` | 15 tools | Phase 3 |
| 📊 Analytics | `tools/analytics/` | `analytics-toolkit-init.php` | 12 tools | Phase 4 |
| 🌍 Multilingual | `tools/multilingual/` | `multilingual-toolkit-init.php` | 10 tools | Phase 5 |
| 🎥 Video Production | `tools/video-production/` | `video-production-toolkit-init.php` | 12 tools | Phase 6 |

### Key Files

```
addons/pro/
├── includes/
│   ├── {toolkit}-init.php (x5)           # Toolkit initialization
│   └── tools/
│       ├── ecommerce/                     # E-commerce tools
│       ├── social-media/                  # Social media tools
│       ├── analytics/                     # Analytics tools
│       ├── multilingual/                  # Multilingual tools
│       └── video-production/              # Video tools
├── package.json                           # +20 new NPM packages
├── scripts/copy-dependencies.js           # Updated with new packages
└── docs/
    ├── TOOLKIT_ARCHITECTURE_PATTERNS.md   # Architecture guide
    ├── NEW_TOOLKITS_NPM_PACKAGES.md      # NPM requirements
    └── FOUNDATION_SETUP_COMPLETE.md       # Setup summary
```

---

## 🏗️ Architecture Pattern

### Service-Based (NO CPTs)

Unlike existing toolkits (ECA, Health & Wellness), the new toolkits are **service-based**:

- ❌ **NO Custom Post Types** - Work with existing data
- ❌ **NO Research & Add Pages** - Not applicable
- ✅ **Settings Pages Only** - API keys, configuration
- ✅ **Tool Files** - Service/integration tools

**Why?**
- E-commerce → Uses WooCommerce's CPTs
- Social Media → External platform data
- Analytics → Read-only reporting
- Multilingual → Enhances existing content
- Video → Processes media library

---

## 🛠️ Creating a New Tool

### 1. Choose Your Toolkit

Each toolkit has a directory in `addons/pro/includes/tools/`:

```php
// E-commerce tool
addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-create-product-advanced.php

// Social media tool  
addons/pro/includes/tools/social-media/class-wp-mcp-ai-tool-post-to-platforms.php

// Analytics tool
addons/pro/includes/tools/analytics/class-wp-mcp-ai-tool-revenue-forecast.php
```

### 2. Use the Tool Template

```php
<?php
/**
 * Tool Name
 *
 * @package WP_MCP_AI_Pro
 */

class WP_MCP_AI_Tool_Your_Tool_Name implements
	WP_MCP_AI_Tool_Interface,
	WP_MCP_AI_Tool_Capability_Flags_Interface {

	public function get_slug() {
		return 'your_tool_slug';
	}

	public function get_name() {
		return __( 'Your Tool Name', 'mcp-ai-wpoos-pro' );
	}

	public function get_description() {
		return __( 'What this tool does', 'mcp-ai-wpoos-pro' );
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'param_name' => array(
					'type'        => 'string',
					'description' => 'Parameter description',
				),
			),
			'required'   => array( 'param_name' ),
		);
	}

	public function get_capability_flags() {
		return array( 'pro', 'your-category' );
	}

	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_your_toolkit'] ) ) {
			return false;
		}

		// Check dependencies (e.g., WooCommerce).
		return true;
	}

	public function execute( array $arguments, array $context ) {
		// Capability check.
		if ( ! current_user_can( 'your_capability' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'Permission denied.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$param = isset( $arguments['param_name'] ) 
			? sanitize_text_field( $arguments['param_name'] ) 
			: '';

		// Execute tool logic.
		// ...

		return array(
			'success' => true,
			'message' => 'Tool executed successfully',
		);
	}
}
```

### 3. Tool Auto-Discovery

Tools are automatically discovered and registered! No manual registration needed.

The plugin scans `addons/pro/includes/tools/` and loads all tool files matching:
`class-wp-mcp-ai-tool-*.php`

---

## 📦 NPM Packages

### All 32 Packages (12 Existing + 20 New)

**Existing Packages:**
```json
"@turf/turf", "chart.js", "docx", "exceljs", "fluent-ffmpeg",
"ics", "katex", "mjml", "pdfkit", "prettier", "sharp"
```

**New E-commerce:**
```json
"@woocommerce/woocommerce-rest-api", "stripe", "currency.js"
```

**New Social Media:**
```json
"twitter-api-v2", "facebook-node-sdk", "linkedin-api-client", "axios"
```

**New Analytics:**
```json
"d3", "mathjs", "regression", "fast-csv"
```

**New Multilingual:**
```json
"i18next", "franc", "google-translate-api-x", "iso-639-1"
```

**New Video:**
```json
"ffmpeg-static", "ffprobe-static", "gif-encoder", "video-stitch", "subtitle"
```

### Using NPM Packages in Tools

Packages are copied to `addons/pro/assets/vendor/{package-name}/` and can be accessed via Node.js microservices pattern (see existing tools for examples).

---

## 🔧 Settings Integration

### Settings Keys

Each toolkit needs a settings key in `wp_mcp_ai_settings` option:

```php
// E-commerce Toolkit
$settings['enable_ecommerce_toolkit'] = true;

// Social Media Toolkit  
$settings['enable_social_media_toolkit'] = true;

// Analytics Toolkit
$settings['enable_analytics_toolkit'] = true;

// Multilingual Toolkit
$settings['enable_multilingual_toolkit'] = true;

// Video Production Toolkit
$settings['enable_video_production_toolkit'] = true;
```

### Creating Settings Page (Phase 2)

Settings pages will be created in Phase 2+ following this pattern:

```php
// addons/pro/includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php
class WP_MCP_AI_Ecommerce_Settings_Page {
	// Register with Pro settings tab system
	// Render settings form
	// Handle save/validation
}
```

---

## 🧪 Testing Your Tool

### 1. Enable the Toolkit

```php
// In wp-config.php or via WordPress admin
update_option( 'wp_mcp_ai_settings', array(
	'enable_ecommerce_toolkit' => true,
	// ... other settings
) );
```

### 2. Check Tool Registration

```bash
# Use WP-CLI to list registered tools
wp eval "var_dump(array_keys(wp_mcp_ai_get_tool_registry()->get_all_tools()));"
```

### 3. Test Tool Execution

Create a test assistant that uses your tool, or use the REST API:

```bash
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/chat \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{
    "message": "Use your_tool_slug with param_name: test",
    "assistant_id": 123
  }'
```

---

## 📚 Documentation References

| Document | Purpose |
|----------|---------|
| `TOOLKIT_ARCHITECTURE_PATTERNS.md` | Understanding toolkit patterns |
| `NEW_TOOLKITS_NPM_PACKAGES.md` | NPM package requirements |
| `PRO_TOOLKITS_IMPLEMENTATION_PLAN.md` | Complete implementation spec |
| `NEW_TOOLKITS_INTEGRATION_GUIDE.md` | Integration with existing toolkits |
| `FOUNDATION_SETUP_COMPLETE.md` | Foundation setup summary |

---

## 🚀 Next Steps

### Immediate (Phase 2 - E-commerce)
1. Create 20 e-commerce tools in `tools/ecommerce/`
2. Create settings page for API keys (Stripe, WooCommerce)
3. Write PHPUnit tests
4. Update user documentation

### Future Phases
- Phase 3: Social Media toolkit (15 tools)
- Phase 4: Analytics toolkit (12 tools)
- Phase 5: Multilingual toolkit (10 tools)
- Phase 6: Video Production toolkit (12 tools)

---

## 💡 Tips

### Follow Existing Patterns

Look at these tools for inspiration:
- **WooCommerce integration**: `class-wp-mcp-ai-tool-research-product.php`
- **External API calls**: `class-wp-mcp-ai-tool-generic-rest-api.php`
- **Media processing**: `class-wp-mcp-ai-tool-optimize-image-sharp.php`
- **Document generation**: `tools/document-generation/` directory

### Security Best Practices

1. **Always check capabilities**:
   ```php
   if ( ! current_user_can( 'manage_woocommerce' ) ) {
       return new WP_Error( 'permission_denied', 'No permission' );
   }
   ```

2. **Sanitize all inputs**:
   ```php
   $name = sanitize_text_field( $arguments['name'] );
   $email = sanitize_email( $arguments['email'] );
   ```

3. **Escape all outputs**:
   ```php
   echo esc_html( $user_input );
   echo esc_url( $url );
   ```

4. **Validate before processing**:
   ```php
   if ( empty( $required_param ) ) {
       return new WP_Error( 'missing_param', 'Required parameter missing' );
   }
   ```

---

## ❓ FAQ

**Q: Do I need Node.js installed?**  
A: Only for development. End users don't need Node.js as packages are pre-built.

**Q: Where do tools get registered?**  
A: Automatically! Just place tool files in the toolkit directory.

**Q: Can I create a CPT for my toolkit?**  
A: You can, but the new toolkits follow the service pattern (no CPTs). See `TOOLKIT_ARCHITECTURE_PATTERNS.md`.

**Q: How do I use NPM packages in my tool?**  
A: Follow the Node.js microservices pattern from existing tools (Prettier, MJML, etc.).

**Q: When will settings pages be created?**  
A: Phase 2+ implementation will include settings pages for each toolkit.

---

## 🆘 Need Help?

1. Check existing tool implementations in `addons/pro/includes/tools/`
2. Review documentation in `addons/pro/docs/`
3. Look at base plugin tools in `includes/tools/`
4. Refer to WordPress Coding Standards: https://developer.wordpress.org/coding-standards/

---

**Foundation Complete**: ✅  
**Ready for Development**: ✅  
**Node.js 18+ Compatible**: ✅  
**Pre-packaged for Users**: ✅
