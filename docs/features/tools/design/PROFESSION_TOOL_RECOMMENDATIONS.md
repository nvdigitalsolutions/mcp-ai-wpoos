# Profession Tool Recommendations System

## Overview

The Profession Tool Recommendations system intelligently maps the plugin's 100+ available tools to specific professions based on their category, role, and typical workflow needs. This enhancement helps AI assistants understand which tools are most relevant for their assigned profession and provides contextual guidance on how to use them effectively.

## Architecture

### Components

1. **`WP_MCP_AI_Profession_Tool_Recommender`** - Service class that handles tool recommendations
2. **`WP_MCP_AI_Profession_Playbook_Loader`** - Enhanced to integrate tool recommendations into playbooks
3. **Tool-to-Profession Mapping** - Comprehensive mapping of tools to professions and categories

## How It Works

### Tool Recommendation Layers

The system uses a three-tier recommendation strategy:

1. **Core Tools** (All Professions)
   - `web_search` - Web research and fact-checking
   - `search_content` - WordPress content search
   - `get_recent_posts` - Latest posts retrieval
   - `save_post` - Content creation/editing
   - `count_tokens` - Token estimation

2. **Category Tools** (By Profession Category)
   - **Technical**: Security, system admin, cache management
   - **Creative**: Media generation, image/video manipulation
   - **Financial**: Charts, analytics, scheduled reports
   - **Legal**: Content search, document management
   - **Healthcare**: Research, reports, weather data
   - **Advisory**: Data visualization, external reports

3. **Profession-Specific Tools** (Individual Professions)
   - Custom tool sets for 40+ professions
   - Tailored to specific workflow needs
   - Examples:
     - Software engineers: `check_site_security`, `get_site_summary`
     - Graphic designers: `generate_openai_image`, `resize_image`, `crop_image`
     - E-commerce managers: `get_woo_products`, `create_woo_product`, `scrape_product`
     - Emergency managers: `get_gdacs_events`, `get_nhc_active_storms`

### Tool Availability Filtering

The recommender automatically filters tools based on availability:
- Checks the tool registry to verify tool registration
- Respects base vs. full version configurations
- Excludes unavailable tools from recommendations

### Usage Guidance

For each recommended tool, the system provides:
- **Default guidance**: Generic tool description and use cases
- **Profession-specific guidance**: Contextual advice for the profession
- **Functional grouping**: Tools organized by category (Core, Media, Admin, etc.)

#### Example: web_search Tool Guidance

**Default:**
> Search the web for current information, research, and fact-checking.

**For Journalists:**
> Essential for fact-checking, finding sources, researching breaking news, and verifying claims before publication.

**For Researchers:**
> Critical for literature reviews, finding recent studies, and staying current with field developments.

## Usage

### Getting Tool Recommendations

```php
// Initialize the recommender
$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();
$recommender = new WP_MCP_AI_Profession_Tool_Recommender( $tool_registry );

// Get recommended tools for a profession
$tools = $recommender->get_recommended_tools( 'software_engineer', 'technical' );
// Returns: ['web_search', 'search_content', 'save_post', 'check_site_security', ...]
```

### Generating Tool Guidance

```php
// Get usage guidance for specific tools
$guidance = $recommender->get_tool_usage_guidance( 
    'journalist',
    ['web_search', 'transcribe_openai_audio', 'save_post']
);
// Returns formatted markdown with profession-specific guidance
```

### Building Playbooks with Tool Recommendations

```php
// Tool recommendations are automatically included in playbooks
$loader = new WP_MCP_AI_Profession_Playbook_Loader();
$playbook = $loader->build_playbook( $profession_post_id );
// Playbook now includes "Recommended Tools & How to Use Them" section
```

## Playbook Integration

The enhanced playbook structure now includes:

```
# [Profession Title] - Professional Playbook
Generated: [timestamp]
---

## Global Guidelines
[from global.txt]
---

## [Category] Category Guidelines
[from categories/[category].txt]
---

## [Profession] Specific Guidelines
[from professions/[slug].txt]
---

## Recommended Tools & How to Use Them

This profession has access to [N] recommended tools...

### Core Tools
**tool_name** - Description and usage guidance

### [Category Name]
**tool_name** - Description and usage guidance

### Tool Usage Best Practices
1. Verify permissions
2. Test first
3. Provide context
4. Check responses
5. Document usage
6. Stay updated

---
[footer]
```

## Tool Categories

Tools are organized into functional categories:

1. **Core Tools** - Essential tools for all professions
2. **Content Management** - WordPress content operations
3. **Media Generation** - AI-powered media creation
4. **Media Manipulation** - Image/video editing
5. **Data & Analytics** - Charts and data visualization
6. **E-commerce** - WooCommerce integration
7. **SEO & Marketing** - SEO and marketing tools
8. **System Administration** - Site management and security
9. **External Data & APIs** - Third-party integrations
10. **Communication** - Email and messaging
11. **Automation & Scheduling** - Cron and task automation

## Adding New Tool Mappings

### For a New Profession

Edit `class-wp-mcp-ai-profession-tool-recommender.php`:

```php
protected function get_profession_specific_tools( $profession_slug ) {
    $profession_tool_map = array(
        // ... existing mappings
        
        'my_new_profession' => array(
            'tool_slug_1',
            'tool_slug_2',
            'tool_slug_3',
        ),
    );
    
    return isset( $profession_tool_map[ $profession_slug ] ) 
        ? $profession_tool_map[ $profession_slug ] 
        : array();
}
```

### For a New Category

```php
protected function get_category_tools( $category ) {
    $category_tool_map = array(
        // ... existing categories
        
        'my_category' => array(
            'tool_slug_1',
            'tool_slug_2',
        ),
    );
    
    return isset( $category_tool_map[ $category ] ) 
        ? $category_tool_map[ $category ] 
        : array();
}
```

### Adding Custom Tool Guidance

```php
protected function get_single_tool_guidance( $tool_slug, $profession_slug ) {
    $guidance_map = array(
        'my_tool' => array(
            'default' => '**my_tool** - Generic description',
            'my_profession' => '**my_tool** - Profession-specific context',
        ),
    );
    
    // ... lookup logic
}
```

## Best Practices

### Tool Selection Guidelines

1. **Be Conservative**: Only recommend tools that are clearly relevant
2. **Consider Capabilities**: Include tools the profession likely has permission for
3. **Think Workflows**: Map tools to common profession workflows
4. **Avoid Overlap**: Don't duplicate functionality unnecessarily
5. **Update Regularly**: Review and update mappings as tools evolve

### Guidance Writing Guidelines

1. **Be Specific**: Explain *how* to use the tool for this profession
2. **Provide Context**: Explain *why* this tool is useful
3. **Include Examples**: Give concrete use cases when helpful
4. **Keep Brief**: 1-2 sentences per tool
5. **Stay Current**: Update guidance as tool capabilities change

## Examples

### Technical Profession (Software Engineer)

**Recommended Tools (15 total):**
- Core: web_search, search_content, save_post, count_tokens
- System Admin: check_site_security, get_site_summary, purge_cache
- Automation: create_cron_job, list_cron_jobs
- Analytics: create_chart

**Key Guidance:**
- `check_site_security`: "Essential for security audits, identifying vulnerabilities, and compliance verification"
- `purge_cache`: "Clear caches after deployments or content updates to ensure changes are visible"

### Creative Profession (Graphic Designer)

**Recommended Tools (13 total):**
- Core: web_search, search_content, save_post
- Media Generation: generate_openai_image, generate_gemini_image
- Media Manipulation: resize_image, crop_image, rotate_image, convert_image_format
- Analytics: create_chart (for design metrics)

**Key Guidance:**
- `generate_openai_image`: "Generate concept art, mockups, and visual inspiration for design projects"
- `resize_image`: "Prepare images for different deliverables (web, print, social media) with proper dimensions"

### Business Profession (E-commerce Manager)

**Recommended Tools (12 total):**
- Core: web_search, search_content, save_post
- E-commerce: get_woo_products, create_woo_product, scrape_product, crawl4ai_price_lookup
- Analytics: create_chart
- Automation: create_cron_job

**Key Guidance:**
- `get_woo_products`: "Monitor inventory, analyze product catalog, and manage merchandising"
- `crawl4ai_price_lookup`: "Compare wholesale pricing across major retailers for competitive analysis"

## Testing

Run the tool recommender tests:

```bash
vendor/bin/phpunit tests/test-profession-tool-recommender.php
```

Test coverage includes:
- Core tool inclusion for all professions
- Category-specific tool recommendations
- Profession-specific tool recommendations
- Tool deduplication
- Availability filtering
- Guidance generation
- Playbook integration

## Performance Considerations

- Tool recommendations are computed on-demand during playbook generation
- Results are cached as part of the playbook attachment file
- No database queries beyond profession metadata retrieval
- Tool registry check is O(n) where n = number of recommended tools

## Extensibility

The system is designed for easy extension:

1. **Custom Tool Registries**: Pass custom registry to recommender
2. **Filter Hooks**: Add filters for tool recommendation modification
3. **Guidance Override**: Extend `get_single_tool_guidance()` for custom advice
4. **Category Addition**: Add new categories with minimal code changes

## Related Documentation

- [Tool Reference](../../../reference/tools/tool-reference.md) - Complete tool catalog
- [Profession Playbooks README](../../../../includes/knowledge-base/profession-playbooks/README.md) - Playbook system overview
- [Tool Selection Presets](../../../guides/user/assistants/tool-selection-presets.md) - UI for tool management

## Changelog

### 2025-12-19 - Initial Implementation
- Created `WP_MCP_AI_Profession_Tool_Recommender` service
- Integrated tool recommendations into playbook generation
- Added mappings for 40+ professions across 7 categories
- Created comprehensive test suite
- Added profession-specific tool guidance for key tools
