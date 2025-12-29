# Assistant-Specific CSV Templates

This directory contains CSV templates tailored to specific assistant types and their tool capabilities. Each template is optimized for the tools available to that assistant preset.

## 📁 Templates by Assistant Type

### 1. Content Writing Assistant
**File**: `content-writing-posts.csv`

**Tools Available**: 
- `create_post`, `save_post`, `get_recent_posts`
- `search_content`, `semantic_content_search`
- `generate_image_caption`, `generate_image_alt_text`
- `moderate_content`, `web_search`

**Template Fields**:
- Standard post fields (title, content, status, etc.)
- **SEO-focused**: `seo_title`, `seo_description`, `meta_keywords`
- **Content metrics**: `word_count`, `reading_time`
- Optimized for content creators and bloggers

**Use Cases**:
- Blog post creation with SEO optimization
- Article writing with readability metrics
- Content planning and scheduling

---

### 2. E-commerce Assistant - Products
**File**: `ecommerce-products.csv`

**Tools Available**:
- `create_woo_product`, `get_woo_products`
- `scrape_product`, `lookup_product_price`
- `vision_product_search`

**Template Fields**:
- Complete WooCommerce product fields (32 fields)
- **E-commerce specific**: `brand`, `upsell_ids`, `cross_sell_ids`
- **SEO optimization**: `seo_title`, `seo_description`, `meta_keywords`
- **Rich descriptions**: HTML-formatted descriptions with features

**Use Cases**:
- Product catalog imports
- SEO-optimized product listings
- Product data enrichment

---

### 3. E-commerce Assistant - Orders
**File**: `ecommerce-orders.csv`

**Tools Available**:
- `get_woo_recent_orders`
- Order management and reporting

**Template Fields**:
- Streamlined order fields (16 key fields)
- Focus on essential order data
- Customer information and payment details

**Use Cases**:
- Order history imports
- Sales reporting data
- Customer purchase analysis

---

### 4. Data & Analytics Assistant
**File**: `analytics-metrics.csv`

**Tools Available**:
- `get_jetengine_items`, `invoke_jetengine_route`
- `google_analytics_report`
- `create_chart`

**Template Fields**:
- JetEngine CCT format for analytics data
- Metrics: `conversion_rate`, `bounce_rate`, `page_views`
- Traffic source tracking
- Device type analytics

**Use Cases**:
- Store analytics data in JetEngine CCT
- Track website performance metrics
- Campaign performance analysis

---

### 5. Media Assistant
**File**: `media-assets.csv`

**Tools Available**:
- `generate_openai_image`, `generate_gemini_image`
- `resize_image`, `crop_image`, `convert_image_format`
- `generate_image_alt_text`, `generate_image_caption`
- `remove_background`, `vision_object_localization`

**Template Fields**:
- Extended media metadata
- **Visual data**: `image_width`, `image_height`, `color_palette`
- **SEO**: `keywords`, `intended_use`
- Support for images and videos

**Use Cases**:
- Media library organization
- Asset management with metadata
- Image optimization workflows

---

### 6. Site Management Assistant
**File**: `site-management-cron.csv`

**Tools Available**:
- `create_cron_job`, `list_cron_jobs`, `delete_cron_job`
- `get_site_health`, `get_update_status`
- `purge_cache`

**Template Fields**:
- Cron job configuration
- Schedule definitions
- Callback functions and arguments
- Status tracking

**Use Cases**:
- Automated task scheduling
- Maintenance job configuration
- Backup scheduling

---

### 7. SEO & Marketing Assistant
**File**: `seo-page-analysis.csv`

**Tools Available**:
- `get_rankmath_seo`, `web_search`
- Social media posting tools
- Analytics integration

**Template Fields**:
- Complete SEO audit data
- Meta tags and Open Graph data
- Schema markup
- **Performance metrics**: `seo_score`, `readability_score`
- Issues and recommendations

**Use Cases**:
- SEO audit tracking
- Page optimization planning
- Content performance monitoring

---

### 8. Development Assistant
**File**: `development-code-snippets.csv`

**Tools Available**:
- `create_wpcode_snippet`
- `check_wp_cli`
- `github_repository_operations`

**Template Fields**:
- Code snippet management
- Multiple snippet types (PHP, HTML, CSS, JS)
- Scope and priority settings
- Auto-insert configuration

**Use Cases**:
- Code snippet library
- Custom functionality deployment
- Development workflow optimization

---

### 9. Marketing Assistant - Social Media
**File**: `marketing-social-posts.csv`

**Tools Available**:
- `post_facebook_instagram`, `post_linkedin_update`
- `post_tiktok_video`, `send_telegram_message`
- Social media insights tools

**Template Fields**:
- Multi-platform social posting
- Scheduling and campaign tracking
- Hashtags and targeting
- Engagement goals

**Use Cases**:
- Social media content calendar
- Multi-platform campaign management
- Scheduled post planning

---

## 🎯 How to Choose the Right Template

### By Assistant Type
1. **Content Writing**: Use `content-writing-posts.csv`
2. **E-commerce**: Use `ecommerce-products.csv` or `ecommerce-orders.csv`
3. **Data Analytics**: Use `analytics-metrics.csv`
4. **Media Management**: Use `media-assets.csv`
5. **Site Management**: Use `site-management-cron.csv`
6. **SEO/Marketing**: Use `seo-page-analysis.csv` or `marketing-social-posts.csv`
7. **Development**: Use `development-code-snippets.csv`

### By Tool Availability
Check which tools your assistant has access to:
1. Go to **Assistants → Edit Assistant**
2. Check the **Tools** metabox
3. Note the selected preset (AI/ML, Media, Content Writing, etc.)
4. Choose the corresponding template from this directory

---

## 🔗 Relationship to WP oOS Tools

Each template is designed to work with specific WP oOS tools:

| Template | Primary Tools | Import Method |
|----------|--------------|---------------|
| content-writing-posts.csv | `create_post`, `save_post` | WP All Import → create_post tool |
| ecommerce-products.csv | `create_woo_product` | WooCommerce Import → create_woo_product |
| ecommerce-orders.csv | `get_woo_recent_orders` | WooCommerce Import (read-only) |
| analytics-metrics.csv | `get_jetengine_items` | JetEngine Import |
| media-assets.csv | Media tools, `generate_image_alt_text` | WordPress Media Import |
| site-management-cron.csv | `create_cron_job` | Manual import → create_cron_job |
| seo-page-analysis.csv | `get_rankmath_seo` | Rank Math data export |
| development-code-snippets.csv | `create_wpcode_snippet` | WPCode import |
| marketing-social-posts.csv | Social posting tools | Buffer/Hootsuite export format |

---

## 📊 Field Optimization by Assistant

### Content Writing Assistant
- ✅ Enhanced SEO fields (`seo_title`, `seo_description`)
- ✅ Content metrics (`word_count`, `reading_time`)
- ✅ Rich text formatting support
- ❌ Minimal e-commerce fields
- ❌ No technical/development fields

### E-commerce Assistant
- ✅ Complete product attributes
- ✅ Inventory management fields
- ✅ Upsell/cross-sell relationships
- ✅ Rich product descriptions
- ❌ Minimal SEO content fields
- ❌ No social media fields

### Data Analytics Assistant
- ✅ Metric tracking fields
- ✅ JetEngine CCT compatibility
- ✅ Traffic source analysis
- ✅ Conversion tracking
- ❌ No content creation fields
- ❌ No product/order fields

---

## 🚀 Quick Start Guide

### Step 1: Identify Your Assistant Type
Check your assistant's tool preset in WordPress admin.

### Step 2: Download Appropriate Template
Choose the template that matches your assistant's capabilities.

### Step 3: Prepare Your Data
Fill in the CSV with your actual data, following the field descriptions.

### Step 4: Import
Use the appropriate import method for your template type.

### Step 5: Verify with Tools
Use the assistant's tools to verify the imported data:
```json
// For content writing
{
  "tool": "get_recent_posts",
  "arguments": { "limit": 10 }
}

// For e-commerce
{
  "tool": "get_woo_products",
  "arguments": { "limit": 20 }
}

// For analytics
{
  "tool": "get_jetengine_items",
  "arguments": { "post_type": "analytics_metrics" }
}
```

---

## 💡 Tips for Best Results

### Match Template to Tools
- ✅ Use templates designed for your assistant's available tools
- ✅ Check tool availability before importing
- ❌ Don't try to import data for tools your assistant doesn't have

### Field Optimization
- **Content Writing**: Focus on SEO and readability
- **E-commerce**: Complete product details and images
- **Analytics**: Accurate metrics and date ranges
- **Media**: Rich metadata and keywords
- **SEO**: Complete meta tags and schema

### Data Quality
- Use real, meaningful data in examples
- Test with small batches first
- Validate required fields
- Check for proper formatting

---

## 🔄 Comparison with General Templates

### General Templates (`/assets/csv-templates/`)
- ✅ Comprehensive field coverage
- ✅ Universal compatibility
- ✅ Good for mixed-use scenarios
- ❌ May include unnecessary fields
- ❌ Not optimized for specific assistants

### Assistant-Specific Templates (This Directory)
- ✅ Optimized field selection
- ✅ Matched to tool capabilities
- ✅ Focused on specific use cases
- ✅ Streamlined for efficiency
- ❌ Less flexible across assistants

### When to Use Each
- **General templates**: Initial setup, mixed assistants, comprehensive imports
- **Assistant-specific templates**: Ongoing operations, specialized tasks, optimized workflows

---

## 📚 Additional Resources

- **General CSV Templates**: `/assets/csv-templates/`
- **Tool Documentation**: `/docs/tool-reference.md`
- **Assistant Configuration**: WordPress Admin → Assistants
- **Tool Presets**: See `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`

---

## 🤝 Contributing

When adding new assistant-specific templates:
1. Identify the assistant preset and its tools
2. Create template with relevant fields only
3. Include 3-5 example rows with realistic data
4. Document in this README
5. Link to corresponding WP oOS tools

---

**Version**: 1.0.0  
**Created**: 2024-01-20  
**Last Updated**: 2024-01-20  
**Maintained by**: NV Digital Solutions
