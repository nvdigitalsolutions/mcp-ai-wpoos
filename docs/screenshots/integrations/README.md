# Integration Screenshots

This directory contains screenshots of third-party plugin integrations with NV oOS.

## Screenshots Needed

### JetEngine Integration
1. **jetengine-integration.png** - JetEngine Integration settings page
   - Location: Settings → NV oOS → JetEngine (submenu or tab)
   - Should show:
     - CCT synchronization status
     - Assistant CPT → CCT sync toggle
     - Chat transcript CCT configuration
     - JetEngine-specific tool settings
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Requires**: JetEngine plugin installed

2. **jetengine-cct-assistants.png** - Assistants CCT in JetEngine
   - Location: JetEngine → Custom Content Types → assistants
   - Should show:
     - Synchronized assistant data
     - CCT fields mapped from CPT
     - Assistant records
   - Resolution: 1920x1080 minimum
   - Priority: LOW
   - **Requires**: JetEngine with CCT module enabled

3. **jetformbuilder-integration.png** - JetFormBuilder settings
   - Location: Settings → NV oOS → JetFormBuilder (if separate page)
   - Should show:
     - Form access configuration
     - Submission handling settings
     - REST API integration
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Requires**: JetFormBuilder plugin installed

### WooCommerce Integration
4. **woocommerce-integration.png** - WooCommerce Integration page
   - Location: Settings → NV oOS → WooCommerce (submenu)
   - Should show:
     - Product tool settings
     - Order tool configuration
     - E-commerce automation options
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Requires**: WooCommerce plugin installed

5. **woocommerce-tools-enabled.png** - WooCommerce tools in Tools Manager
   - Location: Tools Manager showing WooCommerce tools
   - Should show:
     - create_woo_product tool enabled
     - get_woo_products tool enabled
     - get_woo_recent_orders tool enabled
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Requires**: WooCommerce plugin installed

### Elementor Integration
6. **elementor-integration.png** - Elementor Integration page
   - Location: Settings → NV oOS → Elementor (submenu)
   - Should show:
     - Widget availability settings
     - Template management configuration
     - Elementor-specific options
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Requires**: Elementor plugin installed

7. **elementor-widgets-list.png** - NV oOS widgets in Elementor
   - Location: Elementor page editor → Widget panel
   - Should show:
     - NV oOS Chat widget
     - Chat Intro widget
     - Dashboard widgets category
     - Widget icons and descriptions
   - Resolution: 1280x720 minimum
   - Priority: MEDIUM
   - **Requires**: Elementor plugin installed

### Rank Math SEO Integration
8. **rankmath-integration.png** - Rank Math tools enabled
   - Location: Tools Manager or integration settings
   - Should show:
     - get_rankmath_seo tool enabled
     - SEO analysis capabilities
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Requires**: Rank Math SEO plugin installed

### WPCode Integration
9. **wpcode-integration.png** - WPCode tools enabled
   - Location: Tools Manager showing WPCode tool
   - Should show:
     - create_wpcode_snippet tool
     - Code snippet management integration
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Requires**: WPCode plugin installed

### Simple JWT Login Integration
10. **simple-jwt-integration.png** - JWT token generation
    - Location: Tools Manager or authentication settings
    - Should show:
      - generate_simple_jwt_token tool
      - JWT authentication options
    - Resolution: 1280x720 minimum
    - Priority: LOW
    - **Requires**: Simple JWT Login plugin installed

### Integration Summary Dashboard
11. **integrations-overview.png** - All integrations status
    - Location: Settings → NV oOS → Integrations (if available)
    - Should show:
      - All supported plugins listed
      - Active/inactive status for each
      - Available tools per integration
      - Quick install/activate links
    - Resolution: 1920x1080 minimum
    - Priority: MEDIUM

## Screenshot Guidelines

### Plugin Requirements
- Each integration screenshot requires the respective plugin to be installed
- Show both "plugin active" and "plugin inactive" states where relevant
- Demonstrate graceful degradation when plugins are missing

### Comparison Shots
Consider creating side-by-side comparisons:
- Tools Manager with vs. without WooCommerce
- Tools Manager with vs. without JetEngine
- Show exactly which tools become available

### Documentation Integration
These screenshots should be referenced in:
- `docs/integrations/` documentation files
- Main README.md "Optional Tools & Dependencies" section
- Integration-specific guides

### Priority Notes
- Focus on WooCommerce, JetEngine, and Elementor first (most commonly used)
- Rank Math, WPCode, and JWT Login are lower priority
- Integration overview/summary page is valuable for quick reference
