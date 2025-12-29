# CSV Import Templates - Index

## 📁 Directory Contents

This directory contains **10 general CSV import templates** plus **9 assistant-specific templates** with **80+ example rows** and **comprehensive documentation** to facilitate data import into WordPress and WooCommerce.

### 📂 Directory Structure
```
csv-templates/
├── README.md                    # Main documentation
├── QUICK-REFERENCE.md          # Quick lookup guide
├── TOOL-MAPPING.md             # NV oOS tool integration
├── INDEX.md                    # This file
├── [10 general CSV templates]
└── by-assistant-type/          # Assistant-specific templates
    ├── README.md               # Assistant template guide
    └── [9 specialized templates]
```

### 📄 General CSV Template Files (10)

**Location**: `./` (this directory)

1. **posts-template.csv** (3 rows, 17 fields)
   - WordPress blog posts and articles
   
2. **pages-template.csv** (5 rows, 13 fields)
   - Static WordPress pages
   
3. **custom-post-types-template.csv** (5 rows, 19 fields)
   - Portfolio, events, team members, testimonials
   
4. **terms-taxonomy-template.csv** (9 rows, 9 fields)
   - Categories, tags, custom taxonomies
   
5. **users-template.csv** (5 rows, 14 fields)
   - WordPress user accounts
   
6. **woocommerce-products-template.csv** (7 rows, 32 fields)
   - WooCommerce product catalog
   
7. **woocommerce-orders-template.csv** (5 rows, 33 fields)
   - WooCommerce order history
   
8. **media-attachments-template.csv** (6 rows, 15 fields)
   - Images, documents, media library
   
9. **comments-template.csv** (6 rows, 13 fields)
   - Comments and reviews
   
10. **jetengine-cct-template.csv** (9 rows, 18 fields)
    - JetEngine Custom Content Types

### 📄 Assistant-Specific CSV Template Files (9)

**Location**: `./by-assistant-type/`

These templates are optimized for specific assistant types and their available tools:

1. **content-writing-posts.csv** (3 rows, 15 fields)
   - SEO-optimized posts for Content Writing assistants
   
2. **ecommerce-products.csv** (4 rows, 24 fields)
   - E-commerce products with rich descriptions
   
3. **ecommerce-orders.csv** (3 rows, 16 fields)
   - Order data for E-commerce assistants
   
4. **analytics-metrics.csv** (3 rows, 13 fields)
   - Analytics data for Data & Analytics assistants
   
5. **media-assets.csv** (4 rows, 13 fields)
   - Media files with extended metadata
   
6. **site-management-cron.csv** (4 rows, 9 fields)
   - Cron job configuration
   
7. **seo-page-analysis.csv** (3 rows, 15 fields)
   - SEO audit and analysis data
   
8. **development-code-snippets.csv** (4 rows, 9 fields)
   - Code snippets for Development assistants
   
9. **marketing-social-posts.csv** (4 rows, 10 fields)
   - Social media posts for Marketing assistants

### 📚 Documentation Files (4)

1. **README.md** (458 lines)
   - Complete documentation for all templates
   - Detailed field descriptions
   - Use cases and examples
   - Troubleshooting guide
   
2. **QUICK-REFERENCE.md** (219 lines)
   - Quick lookup guide
   - Template overview table
   - Common field patterns
   - Best practices
   
3. **TOOL-MAPPING.md** (454 lines)
   - Maps CSV templates to NV oOS tools
   - Workflow examples
   - Integration patterns
   - AI-enhanced workflows

4. **INDEX.md** (this file)
   - Directory overview and navigation

5. **by-assistant-type/README.md** (310 lines)
   - Assistant-specific template documentation
   - Tool mapping by assistant type
   - Optimization guidelines

---

## 🚀 Quick Start

### Choosing Between General and Assistant-Specific Templates

**Use General Templates When:**
- Setting up a new site with comprehensive data
- You need maximum field coverage
- Working with multiple assistant types
- Importing historical/legacy data

**Use Assistant-Specific Templates When:**
- You know your assistant's tool preset
- You want optimized, streamlined fields
- Doing ongoing, specialized imports
- Working with a specific workflow (content, e-commerce, etc.)

### For Beginners
1. Start with **QUICK-REFERENCE.md** for an overview
2. Choose your template from the list above
3. Follow the import guide in **README.md**

### For Developers
1. Review **TOOL-MAPPING.md** for programmatic access
2. Understand NV oOS tool integration
3. Implement AI-enhanced workflows

### For Bulk Imports
1. Download appropriate template(s)
2. Prepare your data using the template structure
3. Use WP All Import or similar plugin
4. Verify with NV oOS tools

---

## 📊 Statistics

- **Total Files**: 24 (19 CSV + 5 MD)
- **General Templates**: 10
- **Assistant-Specific Templates**: 9
- **Total Lines**: ~2,700+
- **Documentation**: ~2,500 lines
- **Example Data Rows**: 80+
- **Total Fields**: 300+ across all templates
- **Directory Size**: ~110 KB

---

## 🎯 Use Case Quick Guide

| Need to Import... | Use Template | Read Documentation |
|-------------------|--------------|-------------------|
| Blog posts | posts-template.csv | README.md → Section 1 |
| Static pages | pages-template.csv | README.md → Section 2 |
| Portfolio/Events | custom-post-types-template.csv | README.md → Section 3 |
| Categories/Tags | terms-taxonomy-template.csv | README.md → Section 4 |
| User accounts | users-template.csv | README.md → Section 5 |
| Products | woocommerce-products-template.csv | README.md → Section 6 |
| Orders | woocommerce-orders-template.csv | README.md → Section 7 |
| Images/Files | media-attachments-template.csv | README.md → Section 8 |
| Comments | comments-template.csv | README.md → Section 9 |
| JetEngine data | jetengine-cct-template.csv | README.md → Section 10 |

---

## 🔗 Related NV oOS Tools

### Import Management
- `trigger_all_import` - Execute imports programmatically
- `list_all_import_templates` - List configured imports
- `get_all_import_status` - Monitor import progress

### Content Management
- `create_post` - Create posts/pages/CPTs
- `save_post` - Update existing content
- `create_term` - Create taxonomy terms
- `get_recent_posts` - Query content

### WooCommerce
- `create_woo_product` - Create products
- `get_woo_products` - Query products
- `get_woo_recent_orders` - Query orders

### JetEngine
- `get_jetengine_items` - Query CCT items
- `list_jetengine_routes` - List REST routes
- `invoke_jetengine_route` - Execute REST calls

**Full tool documentation**: See `/docs/tool-reference.md` in the plugin root

---

## 🔄 Recommended Workflow

### Phase 1: Preparation
1. Review documentation (README.md, QUICK-REFERENCE.md)
2. Choose appropriate template(s)
3. Prepare your data in spreadsheet
4. Validate required fields

### Phase 2: Import
1. Install import plugin (WP All Import recommended)
2. Upload CSV file
3. Map fields
4. Run import on staging site first

### Phase 3: Validation
1. Use NV oOS tools to verify imports
2. Check data integrity
3. Test functionality
4. Review with AI assistant if needed

### Phase 4: Enhancement (Optional)
1. Use NV oOS AI tools to enhance content
2. Generate additional metadata
3. Optimize for SEO
4. Enrich product descriptions

---

## 📖 Documentation Navigation

### By Experience Level

**Beginners**
1. QUICK-REFERENCE.md (overview)
2. README.md (detailed guide)
3. Choose template and import

**Intermediate**
1. README.md (specific template section)
2. QUICK-REFERENCE.md (best practices)
3. Import and validate

**Advanced/Developers**
1. TOOL-MAPPING.md (integration patterns)
2. README.md (reference)
3. Implement automated workflows

### By Task

**Setting up imports**
- README.md → "How to Use These Templates"
- QUICK-REFERENCE.md → "Quick Start Guide"

**Understanding fields**
- README.md → Individual template sections
- Template CSV → Header row

**Troubleshooting**
- README.md → "Troubleshooting" section
- QUICK-REFERENCE.md → "Troubleshooting Tips"

**Automation**
- TOOL-MAPPING.md → "Tool Availability Matrix"
- TOOL-MAPPING.md → "Combined Usage Examples"

**AI Enhancement**
- TOOL-MAPPING.md → "AI-Enhanced Import Workflows"

---

## 🛠 Supported Import Plugins

### Recommended
- **WP All Import** (Premium) - Most features, best support
- **WP Ultimate CSV Importer** (Free/Pro) - Good alternative

### Specialized
- **Product Import Export for WooCommerce** - For products
- **Import Export WordPress Users** - For users
- **WP All Import + JetEngine Add-on** - For JetEngine CCT

### Built-in WordPress
- WordPress native importer (limited functionality)

---

## ⚠️ Important Notes

### Security
- Validate all CSV data before import
- Test on staging site first
- Sanitize user-generated content
- Review imported data

### Encoding
- Always use **UTF-8** encoding
- BOM (Byte Order Mark) may be required
- Test with small batch first

### Performance
- Import in batches (100-500 rows)
- Monitor memory usage
- Use command-line for large imports
- Schedule off-peak hours

### Backup
- Backup database before import
- Keep original CSV files
- Document customizations
- Version control templates

---

## 🆕 What's Included

### CSV Templates
✅ 10 production-ready templates
✅ 60+ example data rows
✅ Comprehensive field coverage
✅ Real-world use cases
✅ Proper CSV formatting
✅ UTF-8 encoded

### Documentation
✅ 1,131 lines of documentation
✅ Step-by-step guides
✅ Field descriptions
✅ Use case examples
✅ Troubleshooting tips
✅ Best practices

### Integration
✅ NV oOS tool mappings
✅ Workflow examples
✅ AI enhancement patterns
✅ Security guidelines
✅ Performance tips

---

## 📞 Support & Resources

### In this directory
- README.md - Complete reference
- QUICK-REFERENCE.md - Quick lookup
- TOOL-MAPPING.md - Integration guide

### In plugin docs
- `/docs/tool-reference.md` - All NV oOS tools
- `/docs/rest-api.md` - REST API reference
- `/docs/QUICK_REFERENCE.md` - Plugin quick start

### External
- [WP All Import Documentation](https://www.wpallimport.com/documentation/)
- [WordPress Codex: Importing Content](https://codex.wordpress.org/Importing_Content)
- [WooCommerce CSV Import Guide](https://woocommerce.com/document/product-csv-importer-exporter/)

---

## 📝 Version History

### v1.0.0 (2024-01-20)
- ✨ Initial release
- 📄 10 CSV templates created
- 📚 3 documentation files
- ✅ 60+ example rows
- 📖 1,191 lines of content

---

## 🤝 Contributing

Improvements welcome! Consider contributing:
- Additional template examples
- More use case scenarios
- Enhanced documentation
- Bug fixes or corrections
- Translation support

---

## 📄 License

Part of NV oOS (Open Operator System)  
Licensed under GPLv3 or later

---

**Quick Links**:
- [Main README](./README.md)
- [Quick Reference](./QUICK-REFERENCE.md)
- [Tool Mapping](./TOOL-MAPPING.md)
- [Plugin Documentation](../../docs/)

---

**Created**: 2024-01-20  
**Version**: 1.0.0  
**Maintained by**: NV Digital Solutions  
**Plugin**: NV oOS (Open Operator System)
