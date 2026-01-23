# Pro Toolkits Architecture Patterns

This document explains the two architectural patterns used in Pro Toolkits.

## Pattern 1: Domain-Specific Data Toolkits (with CPTs)

These toolkits manage **domain-specific entities** that need WordPress storage.

### Characteristics
- ✅ Custom Post Types (CPTs) for data storage
- ✅ Research & Add admin pages
- ✅ Settings pages for configuration
- ✅ Admin metaboxes for CPT editing
- ✅ CRUD tools for their CPTs

### Examples
1. **ECA Management Toolkit**
   - CPTs: `mcp_ai_eca`, `mcp_ai_student`
   - Research page: Find and create ECAs
   - Settings page: ECA system configuration
   - Tools: create_eca, update_eca, list_ecas, etc.

2. **Health & Wellness Toolkit**
   - CPTs: `mcp_ai_member`, `mcp_ai_policy`, `mcp_ai_medical_record`, etc.
   - Research page: Find and create policies
   - Settings page: Health system configuration
   - Tools: create_member, update_policy, etc.

3. **Places Management Toolkit**
   - CPT: `mcp_ai_place`
   - Research page: Find and add places
   - Settings page: Places configuration
   - Tools: create_place, search_places, etc.

4. **Project Management Toolkit**
   - CPTs: `mcp_ai_project`, `mcp_ai_task`
   - Research page: Project research
   - Settings page: PM configuration
   - Tools: create_project, create_task, etc.

5. **Media Toolkit**
   - CPTs: `mcp_ai_media_template`, `mcp_ai_media_collection`
   - Design page: Media template designer
   - Settings page: Media toolkit configuration
   - Tools: create_media_template, apply_template, etc.

---

## Pattern 2: Service/Integration Toolkits (NO CPTs)

These toolkits provide **services or integrate with external systems**, without needing WordPress data storage.

### Characteristics
- ❌ NO Custom Post Types (work with existing data)
- ❌ NO Research & Add pages (not applicable)
- ✅ Settings pages (for API keys, configuration)
- ✅ Service tools for external integrations
- ✅ Processing/utility tools

### New Toolkits (Service Pattern)

#### 1. E-commerce Toolkit
- **NO CPTs** - Uses WooCommerce's existing CPTs (`product`, `shop_order`, `shop_coupon`)
- **NO Research page** - Works with WooCommerce admin UI
- ✅ **Settings page** - API keys (Stripe), configuration
- **Tools**: Product management, order processing, inventory tools
- **Why No CPTs**: WooCommerce already provides complete e-commerce data model

#### 2. Social Media Management Toolkit  
- **NO CPTs** - Manages external platform content (Facebook, Twitter, LinkedIn)
- **NO Research page** - Content lives on external platforms
- ✅ **Settings page** - Platform API keys, account connections
- **Tools**: Posting, scheduling, analytics, engagement tools
- **Why No CPTs**: Data lives on social media platforms, not WordPress

#### 3. Advanced Analytics Toolkit
- **NO CPTs** - Analyzes existing WordPress/WooCommerce data
- **NO Research page** - Read-only analytics
- ✅ **Settings page** - Analytics configuration, data warehouse connections
- **Tools**: Reporting, forecasting, segmentation tools
- **Why No CPTs**: Reads existing data, doesn't store new entities

#### 4. Multi-language Content Toolkit
- **NO CPTs** - Enhances existing posts/pages/products with translations
- **NO Research page** - Works with existing content
- ✅ **Settings page** - Translation API keys (Google, DeepL), language settings
- **Tools**: Translation, localization, QA tools
- **Why No CPTs**: Translations are post meta, not separate entities

#### 5. Video Production Toolkit
- **NO CPTs** - Processes WordPress media library attachments
- **NO Research page** - Works with media library
- ✅ **Settings page** - FFmpeg paths, quality presets, storage settings
- **Tools**: Video editing, optimization, analysis tools
- **Why No CPTs**: Videos are attachments in media library

---

## Init File Structure Comparison

### Pattern 1 (Domain Data Toolkit) - Example: ECA Management

```php
<?php
// Load CPT class
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-eca-cpt.php';

// Load admin pages
if ( is_admin() ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-eca-research-page.php';
    require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-eca-settings-page.php';
}

// Enqueue admin styles for CPT edit screens
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_eca_management_admin_styles' );
```

### Pattern 2 (Service Toolkit) - Example: E-commerce

```php
<?php
// Check dependencies (WooCommerce)
if ( ! class_exists( 'WooCommerce' ) ) {
    return;
}

// Load admin settings page ONLY
if ( is_admin() ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php';
}

// No CPT class
// No research page
// Tools auto-loaded from tools/ecommerce/ directory
```

---

## Component Matrix

| Toolkit | CPT | Research Page | Settings Page | Admin Styles | Metaboxes |
|---------|-----|---------------|---------------|--------------|-----------|
| **ECA Management** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Health & Wellness** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Places Management** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Project Management** | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Media Toolkit** | ✅ | ✅ (Design) | ✅ | ✅ | ✅ |
| **E-commerce** | ❌ | ❌ | ✅ | ❌ | ❌ |
| **Social Media** | ❌ | ❌ | ✅ | ❌ | ❌ |
| **Analytics** | ❌ | ❌ | ✅ | ❌ | ❌ |
| **Multilingual** | ❌ | ❌ | ✅ | ❌ | ❌ |
| **Video Production** | ❌ | ❌ | ✅ | ❌ | ❌ |

---

## When to Use Which Pattern

### Use Pattern 1 (Domain Data with CPTs) When:
- ✅ You need to store new domain-specific entities in WordPress
- ✅ Entities have complex relationships and metadata
- ✅ Users need CRUD operations in WordPress admin
- ✅ Data should be searchable/filterable in WordPress
- ✅ You want WordPress's revision system, trash, etc.

### Use Pattern 2 (Service/Integration) When:
- ✅ Working with external APIs or services
- ✅ Processing existing WordPress data (posts, users, media)
- ✅ Providing utility/helper functions
- ✅ Integrating with existing plugin CPTs (like WooCommerce)
- ✅ Read-only or analytical operations

---

## Settings Page Requirements

**ALL toolkits need a settings page** for:
- API key management
- Feature enable/disable toggles
- Default configurations
- Connection status

Settings pages are lighter weight than Research pages and only require:
1. A settings class file
2. Registration with Pro settings tabs system
3. Settings form HTML

---

## Summary

- **5 Existing Toolkits**: All use Pattern 1 (Domain Data with CPTs)
- **5 New Toolkits**: All use Pattern 2 (Service/Integration without CPTs)
- **Common to All**: Settings pages for configuration

This architectural separation keeps the codebase clean and avoids unnecessary complexity.
