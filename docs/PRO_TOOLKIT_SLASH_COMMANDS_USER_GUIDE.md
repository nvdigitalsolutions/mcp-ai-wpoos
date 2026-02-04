# Pro Toolkit Slash Commands User Guide

## Introduction

This guide provides comprehensive documentation and examples for using the 242 pro toolkit slash commands available in NV oOS. These commands enable powerful automation and workflow orchestration across 19 specialized domains.

## Getting Started

### Prerequisites

- NV oOS plugin installed and activated
- Pro mode enabled (`WP_MCP_AI_BASE_VERSION = false`)
- Appropriate WordPress capabilities for the commands you want to use

### Basic Command Structure

All slash commands follow this pattern:

```
/command-name --parameter="value" --flag
```

- **Command name**: The action you want to perform (e.g., `/aitool-create`)
- **Parameters**: Named arguments with values (e.g., `--name="My Tool"`)
- **Flags**: Boolean options (e.g., `--dry-run`)

### Finding Available Commands

Use the `/help` command to discover available commands:

```
/help                    # List all commands
/help aitool-create      # Get detailed help for a specific command
/help --detailed         # Show detailed information for all commands
```

---

## High-Priority Commands (Fully Implemented)

### AI Tool Builder Toolkit

#### `/aitool-create` - Create New AI Tool

Creates a new AI tool with specified configuration.

**Usage:**
```
/aitool-create --name="SEO Optimizer" --type=prompt --description="Optimizes content for search engines"
```

**Parameters:**
- `--name` (required): Tool name
- `--type` (optional): Tool type (`prompt`, `function`, `workflow`). Default: `prompt`
- `--description` (optional): Tool description

**Example Response:**
```json
{
  "success": true,
  "message": "AI Tool \"SEO Optimizer\" created successfully! Ready for configuration.",
  "data": {
    "tool_id": 12345,
    "name": "SEO Optimizer",
    "type": "prompt",
    "status": "draft",
    "version": "1.0.0",
    "edit_url": "https://yoursite.com/wp-admin/post.php?post=12345&action=edit"
  }
}
```

**Real-World Example:**
```
# Create a custom AI tool for generating product descriptions
/aitool-create --name="Product Description Generator" --type=prompt --description="Creates engaging e-commerce product descriptions with SEO optimization"

# Result: New AI tool created in draft status, ready to configure prompts and parameters
```

---

#### `/prompt-library` - Access Prompt Templates

Browse and search the built-in prompt template library.

**Usage:**
```
/prompt-library                          # List all prompts
/prompt-library --search="SEO"           # Search for SEO-related prompts
/prompt-library --category="Content"     # Filter by category
```

**Parameters:**
- `--search` (optional): Search term to filter prompts
- `--category` (optional): Filter by category (`SEO`, `Content`, `Social Media`, `E-Commerce`, `Email Marketing`, `all`)

**Example Response:**
```json
{
  "success": true,
  "message": "Found 3 prompt templates.",
  "data": {
    "total_prompts": 5,
    "filtered_prompts": 3,
    "search_term": "SEO",
    "category": "all",
    "prompts": [
      {
        "id": "seo-meta-description",
        "name": "SEO Meta Description Generator",
        "category": "SEO",
        "description": "Generate SEO-optimized meta descriptions",
        "template": "Write a compelling meta description (max 160 characters) for: {topic}",
        "tags": ["seo", "meta", "description"]
      }
      // ... more prompts
    ],
    "categories": ["SEO", "Content", "Social Media", "E-Commerce", "Email Marketing"]
  }
}
```

**Real-World Examples:**
```
# Find all email marketing prompts
/prompt-library --category="Email Marketing"

# Search for product-related prompts
/prompt-library --search="product"

# Browse all available prompts
/prompt-library
```

---

### Core Content Commands (Already Implemented)

#### `/content-draft` - Create Content Draft

Start new content with AI assistance.

**Usage:**
```
/content-draft --topic="10 AI Trends in 2024" --type=post --tone=professional
```

**Parameters:**
- `--topic` (required): Content topic or title
- `--type` (optional): Content type (`post`, `page`, `product`). Default: `post`
- `--tone` (optional): Writing tone (`professional`, `casual`, `technical`). Default: `professional`

**Real-World Example:**
```
# Create a blog post draft
/content-draft --topic="Complete Guide to WordPress Performance" --type=post --tone=technical

# Result: Draft post created with AI-ready placeholder content
```

---

#### `/content-enhance` - Improve Content

Improve existing content for SEO, readability, and engagement.

**Usage:**
```
/content-enhance --post_id=123
```

**Parameters:**
- `--post_id` (required): ID of the post to enhance

**Real-World Example:**
```
/content-enhance --post_id=456

# Result: Content analyzed with suggestions for:
# - Readability improvements (shorter paragraphs)
# - Engagement enhancements (more subheadings)
# - SEO optimization (keyword density)
```

---

#### `/seo-optimize` - SEO Optimization

Apply SEO recommendations to content.

**Usage:**
```
/seo-optimize --post_id=789
```

**Parameters:**
- `--post_id` (required): ID of the post to optimize

**Real-World Example:**
```
/seo-optimize --post_id=789

# Result:
# - Meta description generated
# - Focus keywords highlighted
# - Internal linking suggestions
# - Image alt text recommendations
```

---

## Pro Toolkit Command Categories

### Analytics Pro (12 Commands)

Advanced analytics and business intelligence commands.

#### Quick Reference
```
/analytics-dashboard   # Create custom dashboards
/metric-define        # Define custom metrics
/metric-track         # Track metric performance
/funnel-analyze       # Analyze conversion funnels
/cohort-analyze       # Cohort analysis
/predict-churn        # Churn prediction
/ltv-calculate        # Customer lifetime value
```

**Example Workflow:**
```
# Set up analytics for e-commerce store
/metric-define --name="Conversion Rate" --formula="(orders/visitors)*100"
/funnel-analyze --funnel="checkout"
/analytics-dashboard --name="Sales Overview" --metrics="revenue,orders,conversion_rate"
```

---

### Site Creator (14 Commands)

Automated site building and page creation.

#### Quick Reference
```
/site-research        # Research site best practices
/competitor-analyze   # Analyze competitor sites
/site-plan           # Generate site plan
/page-create         # Create page with AI
/site-scaffold       # Scaffold entire site
```

**Example Workflow:**
```
# Build a new site from scratch
/site-research --industry="fitness" --goals="lead-generation"
/competitor-analyze --competitors="example1.com,example2.com"
/site-plan --site-type="business" --pages=5
/site-scaffold --plan_id=123
```

---

### Social Media (13 Commands)

Social media management and automation.

#### Quick Reference
```
/social-post         # Create social post
/social-schedule     # Schedule posts
/hashtag-suggest     # Suggest hashtags
/post-optimize       # Optimize post content
/social-analytics    # Social media analytics
```

**Example Workflow:**
```
# Create and schedule social media campaign
/social-post --platform="twitter" --content="Check out our new product!" --hashtags="tech,innovation"
/hashtag-suggest --topic="artificial intelligence" --count=10
/social-schedule --post_id=456 --date="2026-02-10 14:00"
/social-analytics --platform="all" --period="last-30-days"
```

---

### E-Commerce Pro (15 Commands)

Advanced e-commerce automation.

#### Quick Reference
```
/product-recommend   # AI product recommendations
/upsell-suggest      # Suggest upsells
/abandoned-recover   # Cart recovery
/fraud-detect        # Fraud detection
/ecom-analytics      # E-commerce analytics
```

**Example Workflow:**
```
# Optimize product recommendations and recovery
/product-recommend --customer_id=123 --count=5
/upsell-suggest --product_id=456
/abandoned-recover --min-cart-value=50 --email-template="recovery-v2"
```

---

### Document Generation (13 Commands)

Template-based document creation and automation.

#### Quick Reference
```
/doc-create          # Create document from template
/pdf-generate        # Generate PDF
/doc-merge           # Merge documents
/doc-sign            # E-signature workflow
```

**Example Workflow:**
```
# Generate contract documents
/doc-create --template="service-agreement" --client_id=789
/variable-fill --doc_id=123 --data='{"client_name":"Acme Corp","date":"2026-02-04"}'
/pdf-generate --doc_id=123
/doc-sign --doc_id=123 --signers="client@example.com,manager@company.com"
```

---

### CRM (14 Commands)

Customer relationship management and sales pipeline.

#### Quick Reference
```
/lead-add            # Add new lead
/lead-qualify        # Qualify leads
/deal-create         # Create deal
/follow-up           # Schedule follow-up
/pipeline-view       # View sales pipeline
```

**Example Workflow:**
```
# Manage sales pipeline
/lead-add --name="John Doe" --email="john@example.com" --source="website"
/lead-qualify --lead_id=456 --score=85
/deal-create --lead_id=456 --value=10000 --probability=60
/follow-up --deal_id=789 --date="2026-02-15" --type="email"
```

---

## Workflow Orchestration

### Command Chaining

Execute multiple commands in sequence automatically.

**Syntax:**
```
/workflow --name="content_pipeline" --steps="step1,step2,step3"
```

**Example - Content Publishing Pipeline:**
```
# Define workflow
/workflow-create --name="blog_post_pipeline" --description="Complete blog post workflow"

# Workflow steps:
# 1. Create draft
# 2. Enhance content
# 3. Optimize SEO
# 4. Review
# 5. Schedule

# Execute workflow
/workflow --name="blog_post_pipeline" --topic="AI in Healthcare"
```

### Conditional Workflows

Execute commands based on conditions.

**Example - Smart E-Commerce Workflow:**
```
# If cart value > $100, offer free shipping
# If cart abandoned for 2 hours, send recovery email
# If customer is VIP, apply special discount

/workflow-create --name="smart_checkout" --conditions='[
  {"if":"cart_value>100","then":"apply_free_shipping"},
  {"if":"abandoned_2h","then":"send_recovery_email"},
  {"if":"customer_vip","then":"apply_vip_discount"}
]'
```

---

## Performance Optimization Tips

### 1. Use Batch Operations

Instead of running commands one at a time, use batch operations:

```
# Bad: Multiple individual commands
/image-optimize --attachment_id=1
/image-optimize --attachment_id=2
/image-optimize --attachment_id=3

# Good: Batch operation
/image-batch-edit --attachment_ids="1,2,3" --operation="optimize"
```

### 2. Cache Command Results

Commands automatically cache results for 5 minutes to improve performance:

```
# First call: Executes command
/analytics-dashboard --name="Sales"

# Second call within 5 minutes: Returns cached result
/analytics-dashboard --name="Sales"
```

### 3. Use Dry-Run Mode

Test commands without making changes:

```
/site-scaffold --plan_id=123 --dry-run
# Shows what would be created without actually creating it
```

---

## Error Handling

### Common Error Responses

#### Insufficient Permissions
```json
{
  "success": false,
  "error": "insufficient_permissions",
  "message": "You do not have permission to create AI tools."
}
```

**Solution:** Ensure you have the required WordPress capability.

#### Missing Required Parameter
```json
{
  "success": false,
  "error": "missing_required_param",
  "message": "Missing required parameter: name"
}
```

**Solution:** Provide all required parameters for the command.

#### Invalid Parameter Value
```json
{
  "success": false,
  "error": "invalid_parameter",
  "message": "Invalid value for parameter 'type'. Expected: prompt, function, or workflow."
}
```

**Solution:** Check the command documentation for valid parameter values.

---

## Best Practices

### 1. Start with Help

Always check command help before using:
```
/help command-name
```

### 2. Use Descriptive Names

When creating resources, use clear, descriptive names:
```
# Bad
/aitool-create --name="Tool1"

# Good
/aitool-create --name="Blog Post SEO Optimizer"
```

### 3. Test with Dry-Run

Use `--dry-run` flag when available:
```
/site-scaffold --plan_id=123 --dry-run
```

### 4. Leverage Workflows

Create reusable workflows for common tasks:
```
/workflow-create --name="monthly_reports" --steps="collect_data,generate_charts,create_pdf,email_stakeholders"
```

### 5. Monitor Performance

Use analytics commands to track usage:
```
/aitool-analytics --tool_id=123 --period="last-30-days"
```

---

## Frequently Asked Questions

### Q: Which commands are fully implemented?

**A:** Currently, the following commands have full implementations:
- `/aitool-create` - Create AI tools
- `/prompt-library` - Access prompt templates
- `/content-draft` - Create content drafts
- `/content-enhance` - Enhance content
- `/seo-optimize` - SEO optimization

All other commands use placeholder handlers and return a "coming soon" message.

### Q: How do I know which toolkit a command belongs to?

**A:** Use `/help command-name` to see the toolkit and full details:
```
/help aitool-create
# Shows: Toolkit: AI Tool Builder
```

### Q: Can I create custom commands?

**A:** Yes! Use the AI Tool Builder toolkit to create custom tools that can be executed as commands.

### Q: What's the difference between core and pro commands?

**A:** Core commands (12 toolkits) are always available. Pro commands (19 toolkits, 242 commands) are only available when `WP_MCP_AI_BASE_VERSION` is set to `false`.

### Q: How do I report a bug or request a feature?

**A:** Visit the GitHub repository issues page: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

## Advanced Examples

### Example 1: Complete Blog Post Workflow

```bash
# 1. Research topic
/site-research --topic="WordPress Performance" --depth="comprehensive"

# 2. Create outline
/content-draft --topic="Complete Guide to WordPress Performance" --type=post

# 3. Enhance content
/content-enhance --post_id=NEW_POST_ID

# 4. Optimize for SEO
/seo-optimize --post_id=NEW_POST_ID

# 5. Review content
/publish-review --post_id=NEW_POST_ID

# 6. Schedule publication
/content-schedule --post_id=NEW_POST_ID --date="2026-02-15 09:00"
```

### Example 2: E-Commerce Product Launch

```bash
# 1. Create product
/product-create --name="Premium Widget" --price=99.99

# 2. Generate description
/doc-create --template="product-description" --product_id=NEW_PRODUCT_ID

# 3. Set up recommendations
/product-recommend --product_id=NEW_PRODUCT_ID --type="related"

# 4. Configure upsells
/upsell-suggest --product_id=NEW_PRODUCT_ID

# 5. Create social media posts
/social-post --platform="all" --content="Launching our new Premium Widget!"

# 6. Set up analytics
/ecom-analytics --product_id=NEW_PRODUCT_ID --track="conversions,revenue"
```

### Example 3: Lead Management Pipeline

```bash
# 1. Add lead from form
/lead-add --name="Jane Smith" --email="jane@example.com" --source="landing-page"

# 2. Qualify lead
/lead-qualify --lead_id=NEW_LEAD_ID --criteria="budget,timeline,authority"

# 3. Create deal
/deal-create --lead_id=NEW_LEAD_ID --value=25000

# 4. Schedule follow-up
/follow-up --deal_id=NEW_DEAL_ID --date="+3days" --type="call"

# 5. Create email sequence
/email-sequence --deal_id=NEW_DEAL_ID --template="nurture-sequence"
```

---

## Command Reference Summary

### By Priority Level

**High Priority (Implemented):**
- `/aitool-create` - Create AI tools
- `/prompt-library` - Access prompts
- `/content-draft` - Create drafts
- `/content-enhance` - Enhance content
- `/seo-optimize` - SEO optimization

**Medium Priority (Placeholder):**
- Analytics Pro (12 commands)
- Site Creator (14 commands)
- Social Media (13 commands)
- CRM (14 commands)

**Lower Priority (Placeholder):**
- All other pro toolkit commands (175+ commands)

---

## Getting Help

### In-App Help
```
/help                # List all commands
/help command-name   # Get specific command help
```

### Documentation
- GitHub: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- Docs: `docs/PRO_TOOLKIT_SLASH_COMMANDS_IMPLEMENTATION.md`

### Support
- Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Discussions: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/discussions

---

## Changelog

### Version 1.3.0 (2026-02-04)
- ✅ Added 242 pro toolkit command definitions
- ✅ Implemented `/aitool-create` handler
- ✅ Implemented `/prompt-library` handler
- ✅ Added workflow orchestration support
- ✅ Created comprehensive user documentation

### Coming Soon
- Full implementation of remaining handlers
- Advanced workflow orchestration
- Command marketplace
- Custom command builder UI

---

**Last Updated:** February 4, 2026  
**Version:** 1.3.0  
**Total Commands:** 242 pro + 12 core = 254 commands
