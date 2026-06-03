# Image Templates Menu Structure - Before and After

## BEFORE (Incorrect)

```
WordPress Admin Menu:
├── Dashboard
├── Posts
├── Media
│   ├── Library
│   ├── Add New
│   └── Image Settings  ← WRONG LOCATION
├── Pages
└── Image Templates
    ├── All Templates
    ├── Add New
    ├── Template Categories
    └── Research & Add  ← Only this one was correct
```

**Problem**: The "Image Settings" page was appearing under the Media menu because the parent slug was set to `upload.php`.

## AFTER (Fixed)

```
WordPress Admin Menu:
├── Dashboard
├── Posts
├── Media
│   ├── Library
│   └── Add New
├── Pages
└── Image Templates
    ├── All Templates
    ├── Add New
    ├── Template Categories
    ├── Image Settings  ← NOW IN CORRECT LOCATION
    └── Research & Add  ← Still in correct location
```

**Solution**: Changed the parent slug from `upload.php` to `edit.php?post_type=mcp_ai_image_tpl`.

## Code Change

### File: `addons/pro/includes/admin/class-wp-mcp-ai-image-production-cpt-settings-page.php`

**Before:**
```php
public function add_settings_page() {
    // Image templates are under upload.php (Media menu).
    add_submenu_page(
        'upload.php',  // ← Wrong parent
        $this->page_title,
        $this->menu_title,
        'manage_options',
        $this->page_slug,
        array( $this, 'render_settings_page' )
    );
}
```

**After:**
```php
public function add_settings_page() {
    // Image templates have their own CPT menu.
    add_submenu_page(
        'edit.php?post_type=mcp_ai_image_tpl',  // ← Correct parent
        $this->page_title,
        $this->menu_title,
        'manage_options',
        $this->page_slug,
        array( $this, 'render_settings_page' )
    );
}
```

## WordPress Admin Menu Parent Slugs Reference

For future reference, here are common WordPress admin menu parent slugs:

| Menu Location | Parent Slug |
|--------------|-------------|
| Dashboard | `index.php` |
| Posts | `edit.php` |
| Media | `upload.php` |
| Pages | `edit.php?post_type=page` |
| Comments | `edit-comments.php` |
| Appearance | `themes.php` |
| Plugins | `plugins.php` |
| Users | `users.php` |
| Tools | `tools.php` |
| Settings | `options-general.php` |
| Custom Post Type | `edit.php?post_type={post_type_slug}` |

## Result

Now when users navigate to the Image Templates section, they will see all related pages properly organized in one place:

1. **All Templates** - List of all image templates
2. **Add New** - Create a new template manually
3. **Template Categories** - Manage template categories
4. **Image Settings** - Configure AI assistant and default settings (FIXED)
5. **Research & Add** - AI-assisted template creation

This provides a much better user experience and logical organization of features.
