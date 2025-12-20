# Quick Guide: Updating Tool Mappings for Professions

## TL;DR

To add tools to a profession's recommendations:

1. Edit `includes/services/class-wp-mcp-ai-profession-tool-recommender.php`
2. Find the appropriate method (`get_profession_specific_tools` or `get_category_tools`)
3. Add your tool slugs to the array
4. Optionally add custom guidance in `get_single_tool_guidance`
5. Reseed playbooks in WordPress admin

## Common Tasks

### Add Tools to a Single Profession

**Location:** `get_profession_specific_tools()` method

```php
'software_engineer' => array( 
    'check_site_security',  // existing
    'my_new_tool',          // ← ADD THIS
),
```

### Add Tools to an Entire Category

**Location:** `get_category_tools()` method

```php
'technical' => array(
    'check_site_security',  // existing
    'my_new_tool',          // ← ADD THIS
),
```

### Add Custom Tool Guidance

**Location:** `get_single_tool_guidance()` method

```php
'my_new_tool' => array(
    'default' => '**my_new_tool** - Generic description for all professions',
    'software_engineer' => '**my_new_tool** - Specific guidance for software engineers',
),
```

## Tool Categories Reference

When adding tools, consider which category they belong to:

```php
'Core Tools'                => ['web_search', 'search_content', 'count_tokens'],
'Content Management'        => ['save_post', 'get_recent_posts', 'search_attachments'],
'Media Generation'          => ['generate_openai_image', 'generate_sora_video'],
'Media Manipulation'        => ['resize_image', 'crop_image', 'edit_gemini_image'],
'Data & Analytics'          => ['create_chart', 'query_mesh_intelligent'],
'E-commerce'                => ['get_woo_products', 'create_woo_product'],
'SEO & Marketing'           => ['get_rankmath_seo', 'search_places'],
'System Administration'     => ['check_site_security', 'purge_cache'],
'External Data & APIs'      => ['reliefweb_reports', 'get_gdacs_events'],
'Communication'             => ['send_group_email', 'schedule_notify_sms'],
'Automation & Scheduling'   => ['create_cron_job', 'list_cron_jobs'],
```

Update `group_tools_by_category()` if your tool doesn't fit existing categories.

## Profession Categories

Available profession categories:

- `technical` - Software engineers, developers, IT professionals
- `creative` - Designers, artists, content creators
- `advisory` - Consultants, advisors
- `financial` - Accountants, financial advisors
- `legal` - Lawyers, paralegals
- `healthcare` - Medical professionals
- `other` - All other professions

## Step-by-Step: Adding a New Profession with Tools

1. **Create profession** in WordPress admin or via seeder
2. **Choose category** (technical, creative, financial, etc.)
3. **Add to recommender** in `get_profession_specific_tools()`:

```php
'data_analyst' => array(
    'create_chart',          // Visualize data
    'search_content',        // Find reports
    'web_search',            // Research
),
```

4. **Add custom guidance** (optional):

```php
'create_chart' => array(
    'default' => '**create_chart** - Generate interactive charts',
    'data_analyst' => '**create_chart** - Visualize data insights, trends, and patterns for reports',
),
```

5. **Reseed playbooks** in WordPress admin:
   - Go to Settings → WP oOS → Advanced
   - Click "Reseed Professions"
   - Choose "Update" (not "Replace")

## Example: Real-World Addition

**Scenario:** Add scraping and price comparison tools for merchandisers

```php
// In get_profession_specific_tools()
'merchandiser' => array(
    'get_woo_products',      // View product catalog
    'create_woo_product',    // Create products
    'scrape_product',        // Scrape competitor products
    'crawl4ai_price_lookup', // Compare wholesale prices
),
```

**Add guidance:**

```php
// In get_single_tool_guidance()
'crawl4ai_price_lookup' => array(
    'default' => '**crawl4ai_price_lookup** - Compare wholesale pricing across retailers',
    'merchandiser' => '**crawl4ai_price_lookup** - Essential for competitive pricing analysis and margin optimization',
),
```

## Tips

✅ **DO:**
- Map tools to actual workflow needs
- Provide profession-specific context in guidance
- Test recommendations after changes
- Keep tool lists focused (10-20 tools per profession)

❌ **DON'T:**
- Add tools "just because" - every tool should have a clear use case
- Duplicate functionality (e.g., both search_content and get_recent_posts for simple searches)
- Forget to reseed playbooks after changes
- Add tools that require capabilities the profession won't have

## Testing Your Changes

### Programmatically

```php
$recommender = new WP_MCP_AI_Profession_Tool_Recommender();
$tools = $recommender->get_recommended_tools( 'my_profession', 'technical' );
print_r( $tools );
```

### Via Playbook

```php
$loader = new WP_MCP_AI_Profession_Playbook_Loader();
$playbook = $loader->build_playbook( $profession_post_id );
echo $playbook; // Check "Recommended Tools" section
```

### Via WordPress Admin

1. Create test assistant with the profession
2. Open assistant in chat
3. Check if tools are available
4. Test tool execution

## Common Tool Combinations

**Content Creator:**
```php
'web_search', 'save_post', 'generate_openai_image', 
'get_rankmath_seo', 'count_tokens'
```

**System Administrator:**
```php
'check_site_security', 'get_site_health', 'purge_cache',
'create_cron_job', 'get_system_logs'
```

**Business Analyst:**
```php
'create_chart', 'search_content', 'web_search',
'send_group_email'
```

**E-commerce:**
```php
'get_woo_products', 'create_woo_product', 
'scrape_product', 'crawl4ai_price_lookup'
```

## Troubleshooting

**Tools not appearing in playbook:**
- Verify tool slug is correct (check tool-reference.md)
- Confirm tool is registered in tool registry
- Ensure profession category is correct
- Reseed playbooks after changes

**Wrong tools showing up:**
- Check for typos in tool slugs
- Verify profession is in correct category
- Review inheritance (core → category → profession)

**Playbook generation fails:**
- Check PHP syntax errors
- Verify tool_recommender initialization
- Check profession post exists and has valid category

## Quick Reference: File Locations

```
includes/services/class-wp-mcp-ai-profession-tool-recommender.php
    ├── get_core_tools()               # Tools for ALL professions
    ├── get_category_tools()           # Tools by category
    ├── get_profession_specific_tools() # Tools by profession
    ├── get_single_tool_guidance()     # Custom guidance
    └── group_tools_by_category()      # Tool categorization

tests/test-profession-tool-recommender.php
    └── Test coverage for recommender

docs/tool-reference.md
    └── Complete tool catalog with slugs
```

## Need Help?

- See full docs: `docs/PROFESSION_TOOL_RECOMMENDATIONS.md`
- Tool catalog: `docs/tool-reference.md`
- Run tests: `vendor/bin/phpunit tests/test-profession-tool-recommender.php`
