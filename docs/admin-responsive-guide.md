# Admin UI Mobile Responsiveness Guide

This guide explains the mobile responsiveness enhancements made to the NV oOS plugin admin interface and how to use the responsive utilities.

## Overview

All admin tables, dashboards, and settings pages have been enhanced for mobile devices (screens < 782px). The plugin now uses a comprehensive responsive utilities system to ensure all UI elements work well on mobile, tablet, and desktop screens.

## Responsive Breakpoints

The plugin follows WordPress admin breakpoints:

- **Desktop**: > 1280px
- **Tablet**: 782px - 1280px 
- **Mobile**: < 782px (primary responsive target)
- **Small Mobile**: < 600px (enhanced optimizations)

## Responsive Utilities CSS

A new shared CSS file `assets/css/admin-responsive-utilities.css` provides reusable responsive patterns:

### Table Wrappers

#### Horizontal Scroll Wrapper
For wide tables that need to scroll horizontally on mobile:

```html
<div class="wp-mcp-ai-table-wrapper">
    <table class="wp-list-table widefat">
        <!-- table content -->
    </table>
</div>
```

#### Stacked Table Pattern
For tables that should transform into mobile-friendly cards:

```html
<div class="wp-mcp-ai-table-wrapper">
    <table class="wp-mcp-ai-table-responsive">
        <thead>
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td data-label="Name">John Doe</td>
                <td data-label="Email">john@example.com</td>
                <td data-label="Actions">
                    <button>Edit</button>
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

**Note**: Add `data-label` attributes to `<td>` elements for proper mobile display.

### Grid Utilities

Responsive grids that adapt to screen size:

```html
<div class="wp-mcp-ai-grid-2">
    <!-- 2 columns on desktop, 1 on mobile -->
</div>

<div class="wp-mcp-ai-grid-3">
    <!-- 3 columns on desktop, 1 on mobile -->
</div>

<div class="wp-mcp-ai-grid-4">
    <!-- 4 columns on desktop, 2 on tablet, 1 on mobile -->
</div>
```

### Flexbox Utilities

```html
<div class="wp-mcp-ai-flex-wrap">
    <!-- Flex container with wrapping -->
</div>

<div class="wp-mcp-ai-flex-column-mobile">
    <!-- Horizontal on desktop, vertical on mobile -->
</div>
```

### Visibility Utilities

```html
<div class="wp-mcp-ai-hide-mobile">
    <!-- Hidden on mobile only -->
</div>

<div class="wp-mcp-ai-hide-desktop">
    <!-- Hidden on desktop only -->
</div>
```

### Button Groups

```html
<div class="wp-mcp-ai-button-group">
    <button class="button">Action 1</button>
    <button class="button">Action 2</button>
    <!-- Buttons stack vertically on mobile (<600px) -->
</div>
```

### Stat Cards

```html
<div class="wp-mcp-ai-stat-cards">
    <div class="wp-mcp-ai-card">
        <h3>Total Users</h3>
        <p>1,234</p>
    </div>
    <!-- Auto-responsive grid -->
</div>
```

## Enhanced CSS Files

The following CSS files have been enhanced for mobile responsiveness:

### Core Admin CSS
- `admin-settings.css` - Form tables and settings sections
- `settings-dashboard.css` - Dashboard layout and stats
- `admin-responsive-utilities.css` - Shared utilities (NEW)

### Component CSS
- `analytics-dashboard.css` - Charts and analytics widgets
- `datasets-admin.css` - Dataset browser grid
- `tools-manager.css` - Tools list table
- `mcp-diagnostic.css` - Diagnostic pages tables

### Modal CSS
- `admin-test-assistant.css` - Already responsive
- `admin-test-profession.css` - Already responsive  
- `admin-test-team.css` - Already responsive

## PHP Files Enhanced

Tables in these files have been wrapped with responsive containers:

1. `class-wp-mcp-ai-admin-token-manager.php` - Token list table
2. `class-wp-mcp-ai-section-token-manager.php` - 8 tables wrapped
3. `class-wp-mcp-ai-model-config-renderer.php` - Model config table

## Form Responsiveness

All form inputs automatically become full-width on mobile:

```css
@media screen and (max-width: 782px) {
    .wp-mcp-ai-form-responsive input[type="text"],
    .wp-mcp-ai-form-responsive select,
    .wp-mcp-ai-form-responsive textarea {
        width: 100%;
        max-width: 100%;
    }
}
```

Or use the responsive wrapper:
```html
<form class="wp-mcp-ai-form-responsive">
    <!-- All inputs auto full-width on mobile -->
</form>
```

## Modal Responsiveness

Modals automatically adapt to mobile:

```css
@media screen and (max-width: 782px) {
    .wp-mcp-ai-modal-content {
        width: 95% !important;
        margin: 5% auto !important;
        max-height: 90vh !important;
    }
}
```

## Code Blocks

Pre and code blocks automatically handle overflow:

```css
@media screen and (max-width: 782px) {
    pre, code {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        word-wrap: break-word;
    }
}
```

## Testing Responsive Layouts

To test responsive layouts:

1. **Browser DevTools**: Open DevTools (F12) and toggle device emulation
2. **Test Breakpoints**: 
   - 320px (small mobile)
   - 600px (mobile)
   - 782px (tablet/WordPress breakpoint)
   - 1024px (tablet landscape)
   - 1280px+ (desktop)

3. **Test Scenarios**:
   - Navigate to Settings Dashboard
   - View Token Manager tables
   - Check Analytics widgets
   - Test modal popups
   - Verify form inputs

## Best Practices

### DO:
✅ Always wrap wide tables with `.wp-mcp-ai-table-wrapper`  
✅ Use utility classes from `admin-responsive-utilities.css`  
✅ Add `data-label` attributes for stacked tables  
✅ Test at multiple breakpoints  
✅ Use WordPress standard breakpoint (782px)  

### DON'T:
❌ Don't use fixed widths without responsive alternatives  
❌ Don't create new responsive CSS from scratch  
❌ Don't ignore the 782px breakpoint  
❌ Don't use pixel-perfect layouts  
❌ Don't forget touch target sizes (minimum 44x44px)

## Future Enhancements

Consider these improvements for future releases:

1. **Touch Gestures**: Swipe actions for mobile tables
2. **Lazy Loading**: Load table data progressively on mobile
3. **Sticky Headers**: Keep table headers visible while scrolling
4. **Improved Filters**: Mobile-optimized filter interfaces
5. **Collapsible Sections**: More accordion-style layouts

## Support

For issues or questions about mobile responsiveness:

1. Check this guide first
2. Review `admin-responsive-utilities.css` for available utilities
3. Test with browser DevTools device emulation
4. Report issues to the development team

## Example: Converting a Table

**Before (not responsive)**:
```php
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th>User</th>
            <th>Email</th>
            <th>Role</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>John Doe</td>
            <td>john@example.com</td>
            <td>Editor</td>
            <td><button>Edit</button></td>
        </tr>
    </tbody>
</table>
```

**After (responsive)**:
```php
<div class="wp-mcp-ai-table-wrapper">
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td data-label="User">John Doe</td>
                <td data-label="Email">john@example.com</td>
                <td data-label="Role">Editor</td>
                <td data-label="Actions"><button>Edit</button></td>
            </tr>
        </tbody>
    </table>
</div>
```

## Changelog

### Version 1.0 (Current)
- Created `admin-responsive-utilities.css` with comprehensive utilities
- Enhanced 6 core CSS files for mobile
- Wrapped 10+ tables with responsive containers
- Added responsive enqueue to Settings Dashboard
- Created this documentation

---

**Last Updated**: January 2026  
**Author**: NV Digital Solutions  
**Plugin**: NV oOS (Open Operator System)
