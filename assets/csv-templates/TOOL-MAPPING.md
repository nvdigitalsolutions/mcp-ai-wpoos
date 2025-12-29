# CSV Template to NV oOS Tool Mapping

This document maps the CSV import templates to corresponding NV oOS plugin tools that can be used for programmatic data manipulation.

## Template to Tool Mapping

### 1. Posts Template → Post Management Tools

**CSV Template**: `posts-template.csv`

**Related NV oOS Tools**:
- `create_post` - Create new WordPress posts
- `save_post` - Update existing posts
- `get_recent_posts` - Retrieve recent posts
- `create_post_validated` - Create posts with enhanced validation
- `save_post_validated` - Update posts with validation

**Workflow**:
1. Import posts via CSV using WP All Import
2. Use `get_recent_posts` to verify imports
3. Use `save_post` to update posts programmatically
4. Use AI assistant to generate/enhance content

---

### 2. Pages Template → Page Management Tools

**CSV Template**: `pages-template.csv`

**Related NV oOS Tools**:
- `create_post` (with `post_type: 'page'`)
- `save_post` - Update existing pages
- `get_recent_posts` (filtered by post_type)

**Workflow**:
1. Import pages via CSV
2. Query pages with `get_recent_posts` filtered by type
3. Update page templates using `save_post`

---

### 3. Custom Post Types Template → CPT Tools

**CSV Template**: `custom-post-types-template.csv`

**Related NV oOS Tools**:
- `create_post` (with custom `post_type`)
- `save_post` - Update CPT entries
- `get_recent_posts` - Retrieve CPT items
- `get_jetengine_items` - For JetEngine-managed CPTs

**Examples**:
```json
// Create portfolio item
{
  "tool": "create_post",
  "arguments": {
    "title": "Project Alpha",
    "post_type": "portfolio",
    "status": "publish"
  }
}

// Get team members
{
  "tool": "get_recent_posts",
  "arguments": {
    "post_type": "team",
    "limit": 10
  }
}
```

---

### 4. Terms/Taxonomy Template → Taxonomy Tools

**CSV Template**: `terms-taxonomy-template.csv`

**Related NV oOS Tools**:
- `create_term` - Create taxonomy terms
- `update_term` - Update existing terms

**Workflow**:
1. Import terms via CSV
2. Use `create_term` for dynamic term creation
3. Assign terms to posts via `save_post`

**Example**:
```json
{
  "tool": "create_term",
  "arguments": {
    "name": "Web Development",
    "taxonomy": "category",
    "description": "Articles about web development"
  }
}
```

---

### 5. Users Template → User Management Tools

**CSV Template**: `users-template.csv`

**Related NV oOS Tools**:
- `get_user_info` - Retrieve user information
- `get_user_info_validated` - Get user info with validation

**Note**: NV oOS doesn't include user creation tools for security reasons. Use dedicated WordPress user import plugins.

**Workflow**:
1. Import users via dedicated user import plugin
2. Query user info with `get_user_info`
3. Assign content authorship during post creation

---

### 6. WooCommerce Products Template → WooCommerce Tools

**CSV Template**: `woocommerce-products-template.csv`

**Related NV oOS Tools**:
- `create_woo_product` - Create WooCommerce products
- `create_woo_product_validated` - Create with validation
- `get_woo_products` - Retrieve products
- `scrape_product` - Scrape product data from URLs
- `scrape_product_validated` - Scrape with validation

**Workflow**:
1. Import products via CSV or use `create_woo_product`
2. Scrape additional product data with `scrape_product`
3. Query products with `get_woo_products`
4. Update inventory and pricing

**Example**:
```json
{
  "tool": "create_woo_product",
  "arguments": {
    "reference": "PROD-001",
    "title": "Premium Headphones",
    "local_price": 149.99,
    "description": "High-quality wireless headphones"
  }
}
```

---

### 7. WooCommerce Orders Template → Order Tools

**CSV Template**: `woocommerce-orders-template.csv`

**Related NV oOS Tools**:
- `get_woo_recent_orders` - Retrieve recent orders

**Note**: Order creation is typically handled by WooCommerce checkout process, not manual import. CSV template is primarily for migration/backup purposes.

**Workflow**:
1. Import historical orders via CSV (migration scenario)
2. Query orders with `get_woo_recent_orders`
3. Analyze order data with AI assistant

---

### 8. Media/Attachments Template → Media Tools

**CSV Template**: `media-attachments-template.csv`

**Related NV oOS Tools**:
- `upload_to_media_library` - Upload files to media library
- `sideload_image` - Download and import images from URLs
- Various tools that accept image URLs

**Workflow**:
1. Import media files via CSV
2. Use `sideload_image` for external images
3. Attach to posts using `save_post` with `featured_image_id`

---

### 9. Comments Template → Comment Tools

**CSV Template**: `comments-template.csv`

**Related NV oOS Tools**:
- Currently no direct comment tools in NV oOS

**Workflow**:
1. Import comments via CSV using WordPress import plugins
2. Query comments via WordPress API
3. Moderate with WordPress native tools

---

### 10. JetEngine CCT Template → JetEngine Tools

**CSV Template**: `jetengine-cct-template.csv`

**Related NV oOS Tools**:
- `get_jetengine_items` - Retrieve JetEngine CCT items
- `list_jetengine_routes` - List available JetEngine REST routes
- `invoke_jetengine_route` - Call JetEngine REST endpoints

**Workflow**:
1. Import CCT data via CSV using WP All Import + JetEngine Add-on
2. Query CCT items with `get_jetengine_items`
3. Manipulate CCT data via `invoke_jetengine_route`

**Example**:
```json
{
  "tool": "get_jetengine_items",
  "arguments": {
    "post_type": "ai_chat_transcripts",
    "limit": 20
  }
}
```

---

## Bulk Import with WP All Import Integration

### WP All Import Tools

**Related NV oOS Tools**:
- `trigger_all_import` - Trigger import execution
- `list_all_import_templates` - List configured imports
- `get_all_import_status` - Check import status

**Workflow**:
1. Configure import template in WP All Import
2. Use `list_all_import_templates` to get template ID
3. Trigger with `trigger_all_import`
4. Monitor with `get_all_import_status`

**Example**:
```json
// List imports
{
  "tool": "list_all_import_templates",
  "arguments": {
    "limit": 20
  }
}

// Trigger import
{
  "tool": "trigger_all_import",
  "arguments": {
    "import_id": 123
  }
}

// Check status
{
  "tool": "get_all_import_status",
  "arguments": {
    "import_id": 123
  }
}
```

---

## AI-Enhanced Import Workflows

### Scenario 1: Content Enhancement After Import

1. Import posts via `posts-template.csv`
2. Use `get_recent_posts` to retrieve imported content
3. AI assistant generates improved descriptions
4. Update with `save_post`

### Scenario 2: Product Data Enrichment

1. Import basic products via `woocommerce-products-template.csv`
2. Use `scrape_product` to gather additional data
3. AI generates marketing copy
4. Update products with enhanced data

### Scenario 3: Taxonomy Auto-Generation

1. Import posts via CSV
2. AI analyzes content
3. Use `create_term` to generate relevant categories
4. Update posts with appropriate term assignments

### Scenario 4: JetEngine Data Processing

1. Import CCT data via `jetengine-cct-template.csv`
2. Use `get_jetengine_items` to retrieve data
3. AI processes and analyzes data
4. Use `invoke_jetengine_route` to update records

---

## Tool Availability Matrix

| Template | Base Tools | Pro Tools | Requires Plugin |
|----------|-----------|-----------|----------------|
| Posts | ✅ create_post, save_post | ✅ Various | None |
| Pages | ✅ create_post, save_post | ✅ Various | None |
| Custom Post Types | ✅ create_post, save_post | ✅ Various | Varies |
| Terms/Taxonomy | ✅ create_term, update_term | - | None |
| Users | ⚠️ get_user_info only | - | None |
| WooCommerce Products | ✅ create_woo_product | ✅ Advanced | WooCommerce |
| WooCommerce Orders | ✅ get_woo_recent_orders | - | WooCommerce |
| Media | ✅ upload_to_media_library | - | None |
| Comments | ❌ No tools | - | None |
| JetEngine CCT | ✅ get_jetengine_items | ✅ Advanced | JetEngine |

**Legend**:
- ✅ Available
- ⚠️ Partial support
- ❌ Not available

---

## Combined Import & Tool Usage Examples

### Example 1: Blog Migration

```bash
# Step 1: Import posts via CSV
# Use WP All Import with posts-template.csv

# Step 2: Verify imports
Tool: get_recent_posts
Arguments: { "limit": 10, "post_type": "post" }

# Step 3: Create missing categories
Tool: create_term
Arguments: { "name": "Technology", "taxonomy": "category" }

# Step 4: Update post categories
Tool: save_post
Arguments: { "id": 123, "categories": ["Technology", "News"] }
```

### Example 2: E-commerce Setup

```bash
# Step 1: Import products via CSV
# Use Product Import Export for WooCommerce

# Step 2: Query products
Tool: get_woo_products
Arguments: { "limit": 50 }

# Step 3: Enhance product descriptions with AI
# AI generates better descriptions

# Step 4: Update products
Tool: create_woo_product
Arguments: { "sku": "PROD-001", "description": "Enhanced description..." }
```

### Example 3: JetEngine Content Population

```bash
# Step 1: Import CCT data via CSV
# Use WP All Import with JetEngine Add-on

# Step 2: List available routes
Tool: list_jetengine_routes
Arguments: {}

# Step 3: Retrieve imported items
Tool: get_jetengine_items
Arguments: { "post_type": "custom_events", "limit": 20 }

# Step 4: Update via REST
Tool: invoke_jetengine_route
Arguments: { "route": "/custom_events", "method": "POST", "data": {...} }
```

---

## Best Practices for Combined Usage

### 1. Import First, Validate Second
- Import bulk data via CSV
- Use NV oOS tools to verify and validate
- AI assistant reviews data quality

### 2. Programmatic Updates
- Use CSV for initial data
- Use tools for ongoing updates
- Leverage AI for content enhancement

### 3. Hybrid Workflows
- Manual CSV imports for historical data
- Tool-based creation for new content
- AI-powered content generation

### 4. Error Handling
- Monitor imports with status tools
- Use validation variants of tools
- Implement retry logic for failures

---

## Security Considerations

### CSV Imports
- Validate file source
- Sanitize all input data
- Review data before import
- Use staging environment

### Tool Usage
- Check user capabilities
- Validate all parameters
- Log all operations
- Monitor for abuse

### AI Processing
- Review AI-generated content
- Implement approval workflows
- Track token usage
- Audit changes

---

## Performance Optimization

### Large Imports
1. Use CSV for bulk initial import
2. Use tools for incremental updates
3. Batch operations in chunks
4. Monitor memory usage

### Recommended Batch Sizes
- **CSV Import**: 100-500 rows per batch
- **Tool Operations**: 10-20 items per call
- **AI Processing**: 5-10 items per request

---

## Additional Resources

- **NV oOS Tool Reference**: See `/docs/tool-reference.md`
- **REST API Documentation**: See `/docs/rest-api.md`
- **CSV Templates**: See `README.md` in this directory

---

**Version**: 1.0.0  
**Last Updated**: 2024-01-20  
**Maintained by**: NV Digital Solutions
