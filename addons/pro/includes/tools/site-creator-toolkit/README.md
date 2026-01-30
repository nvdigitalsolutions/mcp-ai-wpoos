# Site Creator Toolkit

Advanced AI-powered site creation toolkit with page/section/widget builder capabilities, template management, and integration with Architect Agent for automated development.

## Overview

The Site Creator Toolkit provides comprehensive tools for automated WordPress site creation, following industry best practices and modern standards. It integrates with the Architect Agent to enable self-editing capabilities and automated development workflows.

## Key Features

### 1. Research & Best Practices Foundation
- Web search integration for industry standards
- Best practices knowledge base
- Template pattern library
- Component design standards

### 2. Page Builder Capabilities
- AI-powered page generation from natural language
- Template-based page creation
- Responsive design optimization
- SEO-optimized structure
- Accessibility compliance (WCAG 2.2)
- Performance optimization (Core Web Vitals)

### 3. Section Builder Tools
- Hero section generation
- Feature sections
- Testimonial layouts
- Call-to-action blocks
- Gallery/portfolio sections
- Contact form sections

### 4. Widget Builder
- Custom widget generation
- Dynamic content widgets
- Interactive elements
- Form widgets
- Navigation components
- Footer widgets

### 5. Template Management
- Save and reuse templates
- Import/export functionality
- Version control
- Template variations
- Theme integration

### 6. Architect Agent Integration
- Automated code generation
- Self-editing capabilities
- Version control integration
- Quality assurance checks
- Automated testing

## Industry Standards Implementation

Based on 2025 best practices research:

### Performance Optimization
- Lightweight code generation
- Minimal JavaScript/CSS bloat
- Lazy loading implementation
- Core Web Vitals optimization
- Global styles and reusable blocks

### Accessibility
- WCAG 2.2 compliance
- Keyboard navigation
- Screen reader support
- ARIA labels
- Responsive design (mobile-first)

### Modern Features
- AI-enhanced workflows
- Dynamic content controls
- Block-based design (Gutenberg compatible)
- **Comprehensive theme.json support (2025 standards)**
- Dark mode support
- Global design tokens and CSS variables
- Theme building capabilities with full FSE support
- Fluid typography and responsive spacing scales
- Shadow and border presets for consistent elevation

### Integration & Compatibility
- Elementor integration
- WooCommerce support
- Multilingual compatibility
- Marketing tool integration
- Analytics integration

### Security & Maintenance
- Input sanitization
- Output escaping
- Capability checks
- Nonce verification
- Audit logging

## Tool Categories

### Research & Discovery Tools (4 tools)
1. **research_site_best_practices** - Web search for site building best practices
2. **analyze_competitor_sites** - Analyze and learn from existing sites
3. **generate_site_plan** - Create comprehensive site development plans
4. **suggest_template_patterns** - Recommend templates based on requirements

### Page Building Tools (5 tools)
5. **generate_landing_page** - AI-powered landing page creation
6. **create_homepage_layout** - Homepage with modern design patterns
7. **build_about_page** - About page with company story sections
8. **create_service_pages** - Service/product pages with CTAs
9. **generate_blog_layout** - Blog listing and detail page layouts

### Section Building Tools (6 tools)
10. **create_hero_section** - Hero sections with various styles
11. **generate_feature_section** - Feature showcase sections
12. **build_testimonial_section** - Customer testimonial layouts
13. **create_cta_section** - Call-to-action sections
14. **generate_gallery_section** - Image/portfolio galleries
15. **build_contact_section** - Contact forms and info sections

### Widget Building Tools (4 tools)
16. **create_custom_widget** - Generate custom WordPress widgets
17. **build_navigation_menu** - Smart navigation components
18. **generate_sidebar_widget** - Sidebar widgets with dynamic content
19. **create_footer_widget** - Footer widgets and sections

### Template Management Tools (4 tools)
20. **save_site_template** - Save complete site as template
21. **import_site_template** - Import and apply templates
22. **export_template_kit** - Export for distribution
23. **manage_template_versions** - Version control for templates

### Integration Tools (3 tools)
24. **integrate_with_architect** - Connect to Architect Agent for automation
25. **scaffold_theme_structure** - Generate theme files with AI
26. **automate_development_workflow** - Automated build and deploy

## Implementation Status

- ✅ Research completed (industry best practices)
- ✅ Directory structure created
- 🔄 Tool implementations in progress
- ⏳ CPT and taxonomy setup pending
- ⏳ Admin interface pending
- ⏳ Architect Agent integration pending

## Architecture

### Custom Post Types
- **wp_site_template** - Site template storage
- **wp_page_template** - Individual page templates
- **wp_section_template** - Reusable sections
- **wp_widget_template** - Widget configurations

### Taxonomies
- **template_category** - Template categorization
- **template_style** - Design style (modern, classic, minimal, etc.)
- **template_industry** - Industry-specific templates
- **template_purpose** - Purpose (landing, ecommerce, blog, etc.)

### Settings Structure
```php
array(
    'enable_site_creator_toolkit' => true,
    'enable_research_tools' => true,
    'enable_page_builder' => true,
    'enable_section_builder' => true,
    'enable_widget_builder' => true,
    'enable_template_management' => true,
    'enable_architect_integration' => true,
    'default_design_style' => 'modern',
    'enable_accessibility_checks' => true,
    'enable_performance_optimization' => true,
)
```

## Integration with Architect Agent

The Site Creator Toolkit deeply integrates with the Architect Agent for:

1. **Automated Code Generation**
   - Generate PHP, CSS, JavaScript files
   - Create WordPress plugin/theme files
   - Generate custom post types and taxonomies

2. **Self-Editing Capabilities**
   - Read existing code patterns
   - Modify and improve generated code
   - Apply best practices automatically

3. **Version Control Integration**
   - Automatic git commits
   - Branch management
   - Code review integration

4. **Quality Assurance**
   - Automated linting (WPCS)
   - Accessibility checks
   - Performance testing
   - Security scanning

5. **Development Workflow**
   - Build automation
   - Test execution
   - Deployment pipelines
   - Continuous improvement

## Usage Example

```php
// Research and plan
$registry = wp_mcp_ai_get_tool_registry();
$research_tool = $registry->get_tool( 'research_site_best_practices' );

$best_practices = $research_tool->execute(
    array(
        'query' => 'modern ecommerce site best practices 2025',
        'focus_areas' => array( 'performance', 'conversion', 'accessibility' ),
    ),
    array( 'user_id' => get_current_user_id() )
);

// Generate site plan
$plan_tool = $registry->get_tool( 'generate_site_plan' );
$site_plan = $plan_tool->execute(
    array(
        'site_type' => 'ecommerce',
        'requirements' => 'Modern fashion store with product catalog',
        'best_practices' => $best_practices,
    ),
    array( 'user_id' => get_current_user_id() )
);

// Create homepage using Architect Agent integration
$homepage_tool = $registry->get_tool( 'create_homepage_layout' );
$result = $homepage_tool->execute(
    array(
        'plan' => $site_plan,
        'style' => 'modern',
        'use_architect_agent' => true, // Enable automated code generation
    ),
    array( 'user_id' => get_current_user_id() )
);

// Save as template
$save_tool = $registry->get_tool( 'save_site_template' );
$template = $save_tool->execute(
    array(
        'template_name' => 'Fashion Store Template',
        'pages' => $result['pages'],
        'sections' => $result['sections'],
        'widgets' => $result['widgets'],
    ),
    array( 'user_id' => get_current_user_id() )
);
```

## Requirements

- PHP 7.4+
- WordPress 6.0+
- NV oOS Pro addon
- OpenAI API key (for AI-powered features)
- Architect Agent Toolkit (for automated development)
- Optional: Elementor (for enhanced page building)
- Optional: JetEngine (for CCT storage)

## Security

All tools implement strict security measures:
- `manage_options` capability requirement
- Input sanitization (sanitize_text_field, esc_url, etc.)
- Output escaping (esc_html, esc_attr, etc.)
- Nonce verification for all state changes
- Path validation (no directory traversal)
- Audit logging for all operations

## Performance Considerations

- Tools generate optimized code following WP standards
- Lazy loading for assets
- Minimal external dependencies
- Caching for research results
- Async execution for long-running operations
- Background processing for large sites

## Documentation

- Setup Guide: `docs/guides/setup/SITE_CREATOR_TOOLKIT_SETUP.md`
- Tool Reference: `docs/reference/tools/site-creator-tools.md`
- Best Practices: `docs/guides/user/site-creator-best-practices.md`
- Integration Guide: `docs/guides/developer/architect-agent-integration.md`

## Related Systems

- **Architectural Design Toolkit** - Similar admin structure pattern
- **Architect Agent Toolkit** - Self-editing and automation capabilities
- **Site Creator Section** - Base settings in admin
- **Research & Add System** - Research functionality pattern

## Contributing

When adding new tools:
1. Follow WordPress coding standards (WPCS)
2. Implement `WP_MCP_AI_Tool_Interface`
3. Add comprehensive PHPDoc documentation
4. Include security checks and sanitization
5. Add capability flags for orchestration
6. Update this README with tool information
7. Add tests in `tests/test-site-creator-toolkit.php`

## License

Part of NV Digital Open Operator System (NV oOS) Pro addon.  
Copyright (c) 2025 NV Digital Solutions. All rights reserved.
