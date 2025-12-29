# CSV Import Templates for NV oOS

This directory contains CSV template files for importing various types of WordPress content into your site. These templates are designed to work with popular WordPress import plugins like **WP All Import**, **WP Ultimate CSV Importer**, and other CSV import tools.

## 📋 Available Templates

### 1. **posts-template.csv**
Template for importing WordPress blog posts.

**Key Fields:**
- `title` - Post title (required)
- `content` - Post content with HTML support
- `post_type` - Type of post (default: "post")
- `status` - publish, draft, pending, or private
- `excerpt` - Post excerpt/summary
- `slug` - URL-friendly post slug
- `author_id` - WordPress user ID of the author
- `categories` - Pipe-separated category names (e.g., "Cat1|Cat2")
- `tags` - Pipe-separated tag names
- `featured_image_url` - URL to featured image
- `post_date` - Publication date (YYYY-MM-DD HH:MM:SS)
- `comment_status` - "open" or "closed"
- `ping_status` - "open" or "closed"
- `meta_key_1`, `meta_value_1` - Custom field key-value pairs

**Use Cases:**
- Bulk import blog posts from another platform
- Migrate content from external CMS
- Import archived articles

---

### 2. **pages-template.csv**
Template for importing WordPress pages.

**Key Fields:**
- `title` - Page title (required)
- `content` - Page content with HTML
- `status` - publish, draft, pending, or private
- `slug` - URL slug
- `author_id` - Author user ID
- `parent_page` - Parent page ID (0 for top-level)
- `page_template` - Template file (e.g., "page-templates/full-width.php")
- `menu_order` - Menu display order
- `comment_status` - "open" or "closed"
- Custom meta fields

**Use Cases:**
- Create site structure pages
- Import static content pages
- Set up landing pages

---

### 3. **custom-post-types-template.csv**
Template for importing custom post types (CPTs).

**Key Fields:**
- `title` - CPT entry title (required)
- `content` - Main content
- `post_type` - Custom post type slug (required)
- `status` - Post status
- `excerpt` - Brief description
- `slug` - URL slug
- `author_id` - Author user ID
- `featured_image_url` - Featured image URL
- `post_date` - Publication date
- `taxonomy_1`, `terms_1` - First taxonomy and its terms (pipe-separated)
- `taxonomy_2`, `terms_2` - Second taxonomy and its terms
- Multiple custom meta fields

**Use Cases:**
- Import portfolio items
- Add team member profiles
- Import testimonials
- Create event listings
- Import product showcases

---

### 4. **terms-taxonomy-template.csv**
Template for importing taxonomy terms (categories, tags, custom taxonomies).

**Key Fields:**
- `name` - Term name (required)
- `slug` - URL-friendly slug
- `taxonomy` - Taxonomy type (e.g., "category", "post_tag", "product_cat")
- `description` - Term description
- `parent` - Parent term ID (0 for top-level)
- Custom term meta fields

**Use Cases:**
- Set up category structures
- Import product categories (WooCommerce)
- Create tag hierarchies
- Import custom taxonomy terms

---

### 5. **users-template.csv**
Template for importing WordPress users.

**Key Fields:**
- `username` - Unique username (required)
- `email` - User email address (required)
- `first_name` - First name
- `last_name` - Last name
- `role` - User role (administrator, editor, author, contributor, subscriber)
- `password` - User password (will be hashed on import)
- `display_name` - Display name
- `nickname` - Nickname
- `description` - User bio
- `website` - Website URL
- Custom user meta fields

**Use Cases:**
- Import team members
- Migrate users from another site
- Bulk user creation
- Set up contributor accounts

⚠️ **Security Note:** Be careful with password fields. Use strong passwords and consider forcing password reset on first login.

---

### 6. **woocommerce-products-template.csv**
Template for importing WooCommerce products.

**Key Fields:**
- `sku` - Stock Keeping Unit (unique identifier)
- `name` - Product name (required)
- `product_type` - simple, variable, grouped, external
- `status` - publish, draft, pending, private
- `description` - Full product description (HTML supported)
- `short_description` - Short product summary
- `regular_price` - Regular price (decimal)
- `sale_price` - Sale price (optional)
- `manage_stock` - yes/no
- `stock_quantity` - Number in stock
- `stock_status` - instock, outofstock, onbackorder
- `weight`, `length`, `width`, `height` - Dimensions
- `categories` - Pipe-separated product categories
- `tags` - Pipe-separated product tags
- `image_urls` - Pipe-separated image URLs
- `virtual` - yes/no (for digital products)
- `downloadable` - yes/no
- `tax_status` - taxable, shipping, none
- `tax_class` - standard, reduced-rate, zero-rate
- `sold_individually` - yes/no
- `reviews_allowed` - yes/no
- `upsell_ids` - Pipe-separated product IDs
- `cross_sell_ids` - Pipe-separated product IDs
- `purchase_note` - Note to customer after purchase
- `menu_order` - Display order
- Custom meta fields

**Use Cases:**
- Import product catalog
- Migrate from another e-commerce platform
- Bulk product creation
- Update product inventory

---

### 7. **woocommerce-orders-template.csv**
Template for importing WooCommerce orders (primarily for data migration/testing).

**Key Fields:**
- `order_id` - Leave empty for new orders
- `order_status` - completed, processing, on-hold, cancelled, refunded, failed
- `customer_email` - Customer email (required)
- `customer_first_name`, `customer_last_name` - Customer name
- Billing address fields (address_1, address_2, city, state, postcode, country, phone)
- Shipping address fields (same structure as billing)
- `payment_method` - Payment gateway ID
- `payment_method_title` - Display name for payment method
- `transaction_id` - Payment transaction ID
- `order_date` - Order date (YYYY-MM-DD HH:MM:SS)
- `order_total` - Total order amount
- `order_tax` - Tax amount
- `order_shipping` - Shipping cost
- `order_discount` - Discount amount
- `currency` - Currency code (USD, EUR, GBP, etc.)
- `customer_note` - Customer's order note
- `line_items` - Format: "SKU:Name:Quantity:Price|SKU:Name:Quantity:Price"
- `shipping_method` - Shipping method name
- `coupon_code` - Applied coupon code
- Custom order meta fields

**Use Cases:**
- Migrate historical orders
- Import test order data
- Restore order backup

⚠️ **Note:** Most WooCommerce imports are better handled by dedicated WooCommerce import plugins.

---

### 8. **media-attachments-template.csv**
Template for importing media files into the WordPress Media Library.

**Key Fields:**
- `file_path` - URL or server path to the file (required)
- `file_name` - Filename
- `title` - Media title
- `alt_text` - Image alt text (important for SEO)
- `caption` - Media caption
- `description` - Full description
- `post_date` - Upload date
- `post_author` - Author user ID
- `post_parent` - Attached to post ID (0 for unattached)
- `menu_order` - Display order
- `comment_status` - "open" or "closed"
- Custom attachment meta fields

**Use Cases:**
- Bulk import images
- Import document library
- Migrate media from another site
- Import product images

---

### 9. **comments-template.csv**
Template for importing WordPress comments.

**Key Fields:**
- `post_id` - Post ID to attach comment to (required)
- `comment_author` - Commenter name
- `comment_author_email` - Commenter email
- `comment_author_url` - Commenter website
- `comment_content` - Comment text (required)
- `comment_date` - Comment date (YYYY-MM-DD HH:MM:SS)
- `comment_approved` - 1 (approved), 0 (pending), spam
- `comment_parent` - Parent comment ID (0 for top-level)
- `comment_agent` - User agent string
- `comment_type` - Comment type (empty for regular comment)
- `comment_author_ip` - IP address
- Custom comment meta fields

**Use Cases:**
- Import comments from another platform
- Restore comment backup
- Import product reviews

---

### 10. **jetengine-cct-template.csv**
Template for importing JetEngine Custom Content Types (CCT).

**Key Fields:**
- `item_id` - Leave empty for new items, or specify ID to update
- `cct_slug` - CCT slug (e.g., "ai_chat_transcripts", "custom_cct_events")
- `status` - publish, draft, pending, private
- `item_title` - Title/name of the CCT item
- `field_1` through `field_8` - Custom fields defined in your CCT
- `author_id` - User ID who created the item
- `date_created` - Creation date (YYYY-MM-DD HH:MM:SS)
- Custom meta fields

**Important Notes:**
- Field names depend on your specific CCT configuration in JetEngine
- Fields 1-8 are placeholders; replace with your actual field slugs
- Each CCT can have different field structures
- Consult your JetEngine CCT configuration for exact field names

**Common CCT Examples:**
- `ai_chat_transcripts` - AI conversation records
- Custom events, bookings, team members
- Testimonials, locations, courses
- Properties, portfolios, case studies

**Use Cases:**
- Import JetEngine custom data structures
- Migrate CCT content between sites
- Bulk create CCT items
- Restore JetEngine data backups

**Requirements:**
- JetEngine plugin must be installed and active
- CCT must be configured in JetEngine before import
- Field slugs must match your CCT configuration exactly

---

## 🚀 How to Use These Templates

### Step 1: Choose Your Import Plugin
We recommend:
- **WP All Import** (Premium) - Most powerful and flexible
- **WP Ultimate CSV Importer** (Free/Premium)
- **Import Export WordPress Users** (for users)
- **Product Import Export for WooCommerce** (for WooCommerce)

### Step 2: Prepare Your Data
1. Download the appropriate template from this directory
2. Open in Excel, Google Sheets, or a text editor
3. Replace the example data with your actual content
4. Ensure required fields are filled
5. Save as CSV (UTF-8 encoding recommended)

### Step 3: Import Process
1. Install and activate your chosen import plugin
2. Navigate to the import section in WordPress admin
3. Upload your prepared CSV file
4. Map CSV columns to WordPress fields
5. Configure import settings (update existing, skip duplicates, etc.)
6. Run the import
7. Review imported content

### Step 4: Validation
- Check imported content for accuracy
- Verify images loaded correctly
- Test category/tag assignments
- Review custom field data
- Check for any error logs

---

## 📝 Important Notes

### Character Encoding
- Always use **UTF-8 encoding** for CSV files
- This ensures proper handling of special characters and international characters

### Field Separators
- Default separator: comma (`,`)
- For values containing commas, wrap in double quotes (`"value, with, commas"`)
- Use pipe (`|`) for multiple values in array fields (categories, tags, etc.)

### Date Format
- Use format: `YYYY-MM-DD HH:MM:SS` (e.g., `2024-01-15 10:30:00`)
- Time is optional; defaults to 00:00:00 if omitted

### URL Fields
- Use full absolute URLs for images and files
- External URLs will be downloaded during import (if plugin supports)
- Local paths can be used if files are on the server

### Meta Fields
- Templates include `meta_key_1`, `meta_value_1`, etc.
- Add more pairs as needed (`meta_key_2`, `meta_value_2`, etc.)
- Some import plugins support unlimited meta fields

### Taxonomy Terms
- Use pipe separator for multiple terms: `Term1|Term2|Term3`
- Terms will be created automatically if they don't exist (in most plugins)
- Use IDs or names (names are more portable)

---

## 🔧 Customization

### Adding Custom Fields
Each template can be extended with additional columns:
```csv
title,content,...,custom_field_name,another_custom_field
```

### Modifying Templates
1. Add columns as needed for your use case
2. Maintain required fields
3. Update documentation for your team
4. Test with a small data set first

### Advanced Usage
- **Conditional Logic**: Some import plugins support PHP code for conditional field mapping
- **Data Transformation**: Use formulas in Excel/Google Sheets before export
- **Regular Expressions**: Advanced plugins support regex for data cleanup
- **Scheduled Imports**: Set up cron jobs for recurring imports

---

## 🛠 Troubleshooting

### Common Issues

**Import Fails Completely**
- Check file encoding (must be UTF-8)
- Verify CSV format is valid
- Check for special characters in field values
- Review plugin error logs

**Images Not Importing**
- Verify image URLs are accessible
- Check file permissions
- Ensure plugin has "allow_url_fopen" enabled
- Consider uploading images to server first

**Categories/Tags Not Created**
- Ensure plugin setting "Create terms if not found" is enabled
- Check for typos in taxonomy names
- Verify user has permission to create terms

**Duplicates Created**
- Enable "Check for existing items" in plugin settings
- Use unique identifiers (ID, slug, SKU)
- Consider updating instead of creating new

**Custom Fields Not Saving**
- Verify meta key names match exactly
- Check for reserved WordPress meta keys
- Ensure custom fields are registered (if using ACF/Meta Box)

**Memory Errors**
- Import in smaller batches
- Increase PHP memory limit in wp-config.php
- Use command-line import if available

---

## 🔗 Related WordPress Tools

### Import Plugins
- [WP All Import](https://www.wpallimport.com/) - Premium, powerful
- [WP Ultimate CSV Importer](https://wordpress.org/plugins/wp-ultimate-csv-importer/) - Free/Pro
- [Really Simple CSV Importer](https://wordpress.org/plugins/really-simple-csv-importer/) - Free, simple
- [Import Export WordPress Users](https://wordpress.org/plugins/import-users-from-csv-with-meta/) - User import

### WooCommerce Plugins
- [Product Import Export for WooCommerce](https://wordpress.org/plugins/product-import-export-for-woo/) - Free
- [WooCommerce Customer / Order / Coupon Export](https://woocommerce.com/products/export-customer-order-coupon/) - Premium

### Built-in Tools
The NV oOS plugin includes tools that work with these templates:
- `trigger_all_import` - Trigger WP All Import programmatically
- `list_all_import_templates` - List configured import templates
- `get_all_import_status` - Check import status

---

## 📚 Additional Resources

- [WordPress Codex: Importing Content](https://codex.wordpress.org/Importing_Content)
- [WooCommerce CSV Import Guide](https://woocommerce.com/document/product-csv-importer-exporter/)
- [WP All Import Documentation](https://www.wpallimport.com/documentation/)

---

## 🤝 Contributing

Found an issue or have a suggestion for these templates?
- Submit an issue on GitHub
- Contribute improvements via pull request
- Share your custom templates with the community

---

## 📄 License

These CSV templates are part of the NV oOS (Open Operator System) plugin.
Licensed under GPLv3 or later.

---

**Version:** 1.0.0  
**Last Updated:** 2024-01-20  
**Maintained by:** NV Digital Solutions
