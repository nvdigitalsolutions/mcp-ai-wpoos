# CSV Templates Quick Reference

## Template Files Overview

| Template File | Use Case | Key Fields | Example Rows |
|--------------|----------|------------|--------------|
| **posts-template.csv** | Blog posts & articles | title, content, status, categories, tags | 2 |
| **pages-template.csv** | Static pages | title, content, parent_page, page_template | 4 |
| **custom-post-types-template.csv** | Portfolio, events, team members | title, post_type, taxonomies, custom fields | 4 |
| **terms-taxonomy-template.csv** | Categories, tags, custom taxonomies | name, taxonomy, slug, parent | 8 |
| **users-template.csv** | User accounts | username, email, role, password | 4 |
| **woocommerce-products-template.csv** | WooCommerce products | sku, name, price, stock, categories | 6 |
| **woocommerce-orders-template.csv** | WooCommerce orders | customer_email, items, status, payment | 4 |
| **media-attachments-template.csv** | Images, documents, media | file_path, title, alt_text, caption | 5 |
| **comments-template.csv** | Comments & reviews | post_id, author, content, status | 5 |
| **jetengine-cct-template.csv** | JetEngine custom content | cct_slug, custom fields, metadata | 8 |

**Total: 10 templates | 50+ example data rows**

---

## Common Field Patterns

### Status Fields
- **Posts/Pages/CPT**: `publish`, `draft`, `pending`, `private`
- **WooCommerce Products**: `publish`, `draft`, `pending`, `private`
- **WooCommerce Orders**: `completed`, `processing`, `on-hold`, `cancelled`, `refunded`, `failed`
- **Comments**: `1` (approved), `0` (pending), `spam`

### Date Format
All date fields use: `YYYY-MM-DD HH:MM:SS`
- Example: `2024-01-15 10:30:00`

### Multiple Values (Arrays)
Use pipe separator (`|`) for multiple values:
- Categories: `Category1|Category2|Category3`
- Tags: `tag1|tag2|tag3`
- Image URLs: `url1.jpg|url2.jpg|url3.jpg`

### Required Fields by Template

| Template | Required Fields |
|----------|----------------|
| Posts | `title` |
| Pages | `title` |
| Custom Post Types | `title`, `post_type` |
| Terms/Taxonomy | `name`, `taxonomy` |
| Users | `username`, `email` |
| WooCommerce Products | `sku`, `name` |
| WooCommerce Orders | `customer_email`, `line_items` |
| Media | `file_path` |
| Comments | `post_id`, `comment_content` |
| JetEngine CCT | `cct_slug`, `item_title` |

---

## Import Plugin Recommendations

### General WordPress Content
1. **WP All Import** (Premium) - Most powerful
2. **WP Ultimate CSV Importer** (Free/Pro) - Good all-rounder
3. **Really Simple CSV Importer** (Free) - Basic imports

### WooCommerce Specific
1. **Product Import Export for WooCommerce** (Free)
2. **WooCommerce Customer/Order Export** (Premium)

### Users
1. **Import Export WordPress Users** (Free)
2. **WP All Import** with User Add-on (Premium)

### JetEngine
1. **WP All Import** with JetEngine Add-on (Premium)
2. **JetEngine Built-in Import** (Included with JetEngine)

---

## Quick Start Guide

### 1. Choose Template
Select the appropriate CSV template for your content type.

### 2. Prepare Data
- Download template file
- Open in Excel/Google Sheets
- Replace example data with your content
- Ensure required fields are filled
- Save as UTF-8 encoded CSV

### 3. Import
- Install import plugin
- Upload CSV file
- Map columns to fields
- Configure settings
- Run import

### 4. Verify
- Check imported content
- Verify images loaded
- Test category assignments
- Review custom fields

---

## Common Customizations

### Add Custom Fields
```csv
title,content,...,custom_field_1,custom_value_1
```

### Hierarchical Taxonomies
```csv
name,taxonomy,parent
"Parent Category","category",0
"Child Category","category","Parent Category"
```

### Meta Fields Pattern
```csv
...,meta_key_1,meta_value_1,meta_key_2,meta_value_2
```

---

## Troubleshooting Tips

### Character Encoding Issues
- Always save as **UTF-8**
- Use BOM (Byte Order Mark) if needed
- Test with small batch first

### Images Not Importing
- Use full URLs: `https://example.com/image.jpg`
- Check URL accessibility
- Verify file permissions
- Consider server upload for large sets

### Duplicates Created
- Enable "check for existing" in plugin
- Use unique identifiers (ID, slug, SKU)
- Consider update mode instead of create

### Memory Errors
- Import in smaller batches (100-500 rows)
- Increase PHP memory: `define('WP_MEMORY_LIMIT', '256M');`
- Use WP-CLI for large imports

---

## File Encoding Standards

- **Encoding**: UTF-8
- **Line Endings**: Unix (LF) or Windows (CRLF)
- **Field Separator**: Comma (`,`)
- **Text Qualifier**: Double quotes (`"`)
- **Escape Character**: Backslash (`\`) or double quote (`""`)

---

## Best Practices

✅ **DO:**
- Test with 5-10 rows first
- Backup database before import
- Use staging site for testing
- Keep original CSV as backup
- Validate data before import
- Use consistent date formats
- Document custom field mappings

❌ **DON'T:**
- Import large files without testing
- Skip required fields
- Mix date formats
- Use special characters in slugs
- Import to production directly
- Forget to verify after import

---

## Integration with NV oOS Tools

The plugin includes tools that can work with these templates:

### WP All Import Tools
- `trigger_all_import` - Trigger import programmatically
- `list_all_import_templates` - List configured imports
- `get_all_import_status` - Check import progress

### Content Management Tools
- `create_post` - Create single posts
- `save_post` - Update existing posts
- `create_term` - Create taxonomy terms
- `create_woo_product` - Create WooCommerce products
- `get_jetengine_items` - Retrieve JetEngine CCT items

---

## Support Resources

- **Main Documentation**: `README.md` in this directory
- **NV oOS Documentation**: `/docs` directory
- **Import Plugin Docs**: Check individual plugin documentation
- **WordPress Codex**: https://codex.wordpress.org/Importing_Content

---

## Template Version Information

- **Version**: 1.0.0
- **Last Updated**: 2024-01-20
- **Templates Count**: 10
- **Example Rows**: 50+
- **Compatible With**: WordPress 6.0+, WooCommerce 7.0+, JetEngine 3.0+

---

**Need help?** See the comprehensive README.md file in this directory for detailed documentation on each template.
