# Displays Dashboard

## Overview
The Displays Dashboard is a new admin page in WP oOS that provides a centralized location to view and manage all available Elementor widgets and Gutenberg blocks. It appears in the admin menu above the JetEngine integration page.

## Location
**WP oOS → Displays Dashboard**

The menu item is positioned first in the submenu (before JetEngine, WooCommerce, Elementor, etc.) by using priority 5 on the `admin_menu` hook.

## Features

### 1. Organized Display Categories
All widgets and blocks are organized into logical categories:

**Elementor Widgets (22 total)**
- Chat Widgets (4)
- Assistant Configuration Widgets (4)
- Dashboard Widgets (7)
- Performance Monitoring Widgets (5)
- System Health Widgets (1)

**Gutenberg Blocks (9+ total)**
- Chat Blocks (1)
- Assistant Blocks (1)
- Dashboard Blocks (7)
- Performance Blocks (1)

### 2. Main Operator Buttons
Quick access buttons at the top of the page:
- **Create New Elementor Template** - Opens Elementor template library (only shown if Elementor is active)
- **Create New Page** - Creates a new WordPress page
- **Settings** - Links to WP oOS settings dashboard
- **Documentation** - Opens GitHub documentation in new tab

### 3. Search & Filter
- Live search functionality filters widgets/blocks as you type
- Categories with no matching results are automatically hidden
- Clear button to reset search
- Shows count of visible vs. total displays

### 4. Interactive Features
- **Click to Copy**: Click any widget/block card to copy its slug to clipboard
- **Category Toggle**: Click category headings to collapse/expand sections
- **Keyboard Shortcuts**:
  - `Ctrl+K` or `Cmd+K` - Focus search box
  - `Escape` - Clear search (when search box is focused)

### 5. Status Indicators
- Shows whether Elementor is active or inactive
- Displays appropriate installation instructions if Elementor is not active
- Gutenberg blocks are always available (WordPress core feature)

### 6. Usage Guide
Built-in instructions for:
- How to use Elementor widgets
- How to use Gutenberg blocks
- Quick links to manage assistants, view tools, and configure integrations

## Widget/Block Cards
Each card displays:
- **Icon** - Visual identifier
- **Name** - Human-readable name
- **Slug** - Technical identifier (click to copy)
- **Description** - What the widget/block does

## Technical Details

### Files
- `includes/admin/class-wp-mcp-ai-admin-displays-dashboard.php` - Main class
- `assets/css/displays-dashboard.css` - Styling
- `assets/js/displays-dashboard.js` - Interactive functionality
- `tests/test-admin-displays-dashboard.php` - Test suite

### Menu Registration
```php
add_action( 'admin_menu', array( $this, 'register_page' ), 5 );
```
Priority 5 ensures this page appears before JetEngine (priority 10).

### Assets
Assets are only loaded on the Displays Dashboard page:
```php
if ( $this->page_hook !== $hook ) {
    return;
}
```

### Localized Data
JavaScript receives:
- `ajaxUrl` - WordPress AJAX endpoint
- `nonce` - Security nonce for AJAX calls

### Security
- Requires `manage_options` capability
- All output properly escaped
- XSS prevention in JavaScript
- CodeQL security scan passed

## Use Cases

### For Developers
- Quick reference for all available displays
- Copy slugs for use in code
- Understand widget/block categories

### For Site Builders
- Discover available components
- Learn how to use Elementor widgets
- Learn how to use Gutenberg blocks
- Access quick links to create pages/templates

### For Administrators
- Overview of all display capabilities
- Check Elementor integration status
- Access related settings and documentation

## Responsive Design
- Desktop: Grid layout with auto-fill columns (min 300px)
- Tablet: Adjusts column count automatically
- Mobile: Single column layout (<782px)

## Browser Compatibility
- Modern browsers (Chrome, Firefox, Safari, Edge)
- IE11+ (with jQuery fallback for clipboard API)

## Future Enhancements
Potential future additions:
- Filter by category
- Sort by name/type
- Preview widget/block output
- Usage statistics
- Direct links to edit pages using each display
