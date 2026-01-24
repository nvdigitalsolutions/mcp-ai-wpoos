# Google Site Kit Integration

## Overview

This document outlines the integration between **NV oOS (Open Operator System)** and **Google Site Kit**, Google's official WordPress plugin for integrating Google services like Analytics, Search Console, AdSense, and PageSpeed Insights.

**Integration Status:** ✅ **Feasible and Recommended**

**Repository:** https://github.com/google/site-kit-wp

## Executive Summary

Based on research conducted in January 2026, integrating NV oOS with Google Site Kit is not only feasible but also highly beneficial for WordPress site owners who want to leverage AI capabilities with their Google analytics and search data.

### Key Findings

1. **Site Kit provides limited but useful hooks and filters** for third-party integration
2. **REST API endpoints** are available for authenticated data access
3. **No direct data export API** - integrations must work with Site Kit's internal structure
4. **Compatible with the NV oOS architecture** using the existing integration pattern
5. **Best approach:** Optional integration that enhances functionality when Site Kit is active

## Integration Approach

### Architecture Pattern

Following NV oOS's existing integration model (similar to WooCommerce, JetEngine, Elementor), the Site Kit integration will be:

- **Part of Base Plugin:** Included in core NV oOS (not Pro-only)
- **Optional:** Works only when Site Kit plugin is active
- **Non-intrusive:** Doesn't modify Site Kit's core functionality
- **Tool-based:** Provides AI assistant tools to access Google data
- **Secure:** Respects Site Kit's permission system and WordPress capabilities
- **Lightweight:** Minimal footprint (~55KB total for complete integration)

### Integration Components

```
includes/integrations/
├── class-wp-mcp-ai-sitekit-integration.php  # Main integration class
└── sitekit-integration-init.php             # Initialization file

includes/tools/ (optional tools - only loaded when Site Kit active)
├── class-wp-mcp-ai-tool-sitekit-analytics.php
├── class-wp-mcp-ai-tool-sitekit-search-console.php
├── class-wp-mcp-ai-tool-sitekit-pagespeed.php
└── class-wp-mcp-ai-tool-sitekit-adsense.php
```

## Technical Capabilities

### What Site Kit Exposes

Based on research, Google Site Kit provides:

1. **Filters:**
   - `googlesitekit_available_modules` - Control which modules are enabled
   - `googlesitekit_print_analytics_gtag` - Control analytics snippet output
   - Various internal filters for REST route registration

2. **REST API Endpoints:**
   - `/wp-json/google-site-kit/v1/core/user/data/`
   - `/wp-json/google-site-kit/v1/modules/analytics/data/`
   - `/wp-json/google-site-kit/v1/modules/search-console/data/`
   - `/wp-json/google-site-kit/v1/modules/pagespeed-insights/data/`
   - `/wp-json/google-site-kit/v1/modules/adsense/data/`

3. **Authentication:**
   - WordPress cookie-based auth for logged-in admin users
   - Respects WordPress capabilities and roles
   - User must have connected their Google account through Site Kit

### What We Can Build

#### Tool 1: Site Kit Analytics Reader
**Capability:** Read Google Analytics data through Site Kit
```php
// Tool definition
{
  "name": "sitekit_get_analytics",
  "description": "Retrieve Google Analytics data from Site Kit",
  "parameters": {
    "metric": ["sessions", "pageviews", "bounce_rate", "avg_session_duration"],
    "date_range": ["last_7_days", "last_28_days", "last_90_days"],
    "url": "optional - filter by specific URL"
  }
}
```

#### Tool 2: Site Kit Search Console Reader
**Capability:** Read Google Search Console data
```php
{
  "name": "sitekit_get_search_console",
  "description": "Retrieve Search Console data (impressions, clicks, CTR, position)",
  "parameters": {
    "metric": ["impressions", "clicks", "ctr", "position"],
    "date_range": ["last_7_days", "last_28_days", "last_90_days"],
    "dimension": ["query", "page", "country", "device"]
  }
}
```

#### Tool 3: Site Kit PageSpeed Insights
**Capability:** Get PageSpeed performance data
```php
{
  "name": "sitekit_get_pagespeed",
  "description": "Get PageSpeed Insights scores and recommendations",
  "parameters": {
    "url": "required - page URL to analyze",
    "strategy": ["mobile", "desktop"]
  }
}
```

#### Tool 4: Site Kit AdSense Reader
**Capability:** Read AdSense earnings and performance data
```php
{
  "name": "sitekit_get_adsense",
  "description": "Retrieve AdSense earnings and performance metrics",
  "parameters": {
    "date_range": ["last_7_days", "last_28_days", "last_90_days"],
    "metric": ["earnings", "impressions", "clicks", "ctr", "rpm"]
  }
}
```

## Implementation Plan

### Phase 1: Core Integration (Base Version)
- [ ] Create Site Kit detection utility
- [ ] Implement base integration class
- [ ] Add capability checks for Site Kit data access
- [ ] Create initialization file following existing pattern

### Phase 2: Analytics & Search Console Tools
- [ ] Implement Analytics data reader tool
- [ ] Implement Search Console data reader tool
- [ ] Add proper error handling for disconnected accounts
- [ ] Add permission checks (user must have manage_options capability)

### Phase 3: Performance & AdSense Tools (Base Plugin)
- [ ] Implement PageSpeed Insights tool
- [ ] Implement AdSense reader tool
- [ ] Add caching to reduce API calls

### Phase 4: AI Assistant Features
- [ ] Create pre-built prompts for analytics insights
- [ ] Add shortcut commands for common queries
- [ ] Create example assistant configurations
- [ ] Document use cases in user guide

## Use Cases

### 1. Analytics Insights Assistant
```
User: "How has my website traffic been doing this month?"
AI: [Uses sitekit_get_analytics tool]
"Your website had 15,234 sessions in the last 28 days, up 12% from 
the previous period. Top pages are..."
```

### 2. SEO Performance Monitor
```
User: "What are my top performing keywords in Google?"
AI: [Uses sitekit_get_search_console tool]
"Your top 5 keywords by clicks are: 1) 'wordpress ai plugin' (234 clicks)..."
```

### 3. Content Performance Analyzer
```
User: "Which of my blog posts are getting the most organic traffic?"
AI: [Uses sitekit_get_search_console with dimension=page]
"Based on Search Console data, your top performing posts are..."
```

### 4. Site Speed Advisor
```
User: "How fast is my homepage loading?"
AI: [Uses sitekit_get_pagespeed tool]
"Your homepage has a mobile PageSpeed score of 87. Key recommendations..."
```

## Security Considerations

### Data Access Control
- ✅ **Respect WordPress capabilities** - Only users with `manage_options` can access Google data
- ✅ **Leverage Site Kit's auth** - Don't implement separate Google OAuth
- ✅ **No credential storage** - All auth handled by Site Kit
- ✅ **Audit logging** - Log all Site Kit tool usage in NV oOS logs

### API Rate Limiting
- ✅ **Implement caching** - Cache Site Kit responses for 5-15 minutes
- ✅ **Respect quotas** - Don't make excessive API calls
- ✅ **Error handling** - Gracefully handle quota exceeded errors

### Data Privacy
- ✅ **No data storage** - Don't persist Google data in database
- ✅ **Respect user consent** - Only access data user has connected
- ✅ **GDPR compliance** - Include in privacy policy documentation

## Best Practices

### 1. Detection Pattern
```php
// Check if Site Kit is active and properly configured
function wp_mcp_ai_is_sitekit_active() {
    if ( ! class_exists( 'Google\\Site_Kit\\Plugin' ) ) {
        return false;
    }
    
    // Check if user has connected Google account
    // Implementation depends on Site Kit's API
    return true;
}
```

### 2. Graceful Degradation
```php
// Tool should provide helpful message if Site Kit not available
if ( ! $this->is_sitekit_configured() ) {
    return array(
        'error' => 'Google Site Kit is not active or configured',
        'message' => 'Please install and configure the Google Site Kit plugin',
        'help_url' => 'https://sitekit.withgoogle.com/documentation/',
    );
}
```

### 3. Caching Strategy
```php
// Cache Site Kit responses to reduce API calls
$cache_key = 'wp_mcp_ai_sitekit_analytics_' . md5( serialize( $args ) );
$cached = get_transient( $cache_key );

if ( false !== $cached ) {
    return $cached;
}

// Make API call...
set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
```

### 4. Error Handling
```php
// Provide actionable error messages
try {
    $result = $this->fetch_analytics_data( $args );
} catch ( Exception $e ) {
    return array(
        'error' => true,
        'message' => 'Failed to fetch analytics data',
        'details' => $e->getMessage(),
        'action' => 'Please check your Google Site Kit connection in WordPress admin',
    );
}
```

## Testing Strategy

### Unit Tests
```php
// Test Site Kit detection
public function test_sitekit_detection() {
    $this->assertFalse( wp_mcp_ai_is_sitekit_active() );
    // Mock Site Kit class...
    $this->assertTrue( wp_mcp_ai_is_sitekit_active() );
}

// Test tool registration
public function test_sitekit_tools_registered() {
    // Only when Site Kit active
    $tools = wp_mcp_ai_get_available_tools();
    $this->assertArrayHasKey( 'sitekit_get_analytics', $tools );
}
```

### Integration Tests
```php
// Test API calls (with mocked responses)
public function test_analytics_tool_execution() {
    // Mock Site Kit REST API response
    // Execute tool
    // Verify proper data transformation
}
```

### Manual Testing Checklist
- [ ] Site Kit not installed - tools should not appear
- [ ] Site Kit installed but not configured - helpful error message
- [ ] Site Kit configured - tools work correctly
- [ ] Invalid date ranges - proper validation errors
- [ ] API errors - graceful error handling
- [ ] Caching works - subsequent calls use cache
- [ ] Different user roles - only admins can access

## Documentation Updates Needed

### User-Facing Documentation
1. **Integration guide** (`docs/integrations/google-site-kit-setup.md`)
   - Installation steps
   - Configuration walkthrough
   - Example queries
   - Troubleshooting

2. **Tool reference** (`docs/tool-reference.md`)
   - Add Site Kit tools section
   - Document parameters
   - Provide examples

3. **Use case guide** (`docs/examples/sitekit-use-cases.md`)
   - Analytics monitoring scenarios
   - SEO optimization workflows
   - Content performance analysis
   - Client reporting automation

### Technical Documentation
1. **Integration architecture** (this document)
2. **API reference** for Site Kit tools
3. **Development guide** for extending Site Kit integration

## Configuration

### WordPress Admin Settings

Add Site Kit section to NV oOS settings:

```
Settings → NV oOS → Integrations → Google Site Kit
```

Options:
- ✅ Enable Site Kit Integration (checkbox)
- ✅ Cache Duration (dropdown: 5min, 15min, 30min, 1hour)
- ✅ Default Date Range (dropdown: 7 days, 28 days, 90 days)
- ✅ Enable Detailed Logging (checkbox)

### Assistant Configuration

Pre-built assistant templates:

1. **Analytics Insights Assistant**
   - Tools: sitekit_get_analytics, sitekit_get_search_console
   - Prompt: "You are an analytics expert. Help users understand their website traffic..."

2. **SEO Advisor Assistant**
   - Tools: sitekit_get_search_console, sitekit_get_pagespeed
   - Prompt: "You are an SEO specialist. Analyze search performance..."

3. **Content Performance Assistant**
   - Tools: sitekit_get_analytics, sitekit_get_search_console, get_posts
   - Prompt: "You help content creators understand which posts perform best..."

## Limitations & Considerations

### Known Limitations

1. **No Direct Data API**: Site Kit doesn't provide a public API for data access. We must work with its REST endpoints which may change.

2. **User Must Be Connected**: The WordPress user executing the tool must have connected their Google account through Site Kit.

3. **Admin Only**: By default, only administrators can access Google data through Site Kit.

4. **Rate Limits**: Google APIs have rate limits. We must implement caching and be respectful.

5. **Historical Data**: Limited by what Site Kit caches. Very old data may not be available.

### Alternatives Considered

1. **Direct Google API Integration**
   - ❌ More complex - requires OAuth implementation
   - ❌ Duplicate effort if user already has Site Kit
   - ✅ More control over data access
   - **Verdict:** Not recommended as primary approach

2. **Separate Analytics Plugin**
   - ❌ Reinvents the wheel
   - ❌ Users would have duplicate plugins
   - **Verdict:** Not recommended

3. **Site Kit Integration (Recommended)**
   - ✅ Leverages existing user setup
   - ✅ No duplicate authentication
   - ✅ Consistent with WordPress best practices
   - ✅ Easier for users
   - **Verdict:** ✅ **Recommended approach**

## Maintenance & Updates

### Monitoring Site Kit Changes

- Subscribe to Site Kit release notes: https://github.com/google/site-kit-wp/releases
- Monitor breaking changes in REST API
- Test with each major Site Kit update
- Update integration as needed

### Version Compatibility

| Site Kit Version | NV oOS Version | Status | Notes |
|-----------------|----------------|---------|-------|
| 1.x.x           | 1.1.0+        | ✅ Compatible | Initial integration |
| 2.x.x           | Future        | 🔄 TBD | Monitor for breaking changes |

## Next Steps

1. **Immediate Actions:**
   - ✅ Complete this documentation
   - ⏳ Get stakeholder approval for integration
   - ⏳ Prioritize in development roadmap

2. **Implementation (Estimated 2-3 days):**
   - Day 1: Core integration class and detection
   - Day 2: Analytics and Search Console tools
   - Day 3: Testing, documentation, examples

3. **Future Enhancements:**
   - Tag Manager integration
   - Optimize module tools
   - Custom dashboard widgets
   - AI-powered insights reports

## Resources

### Official Documentation
- [Site Kit Documentation](https://sitekit.withgoogle.com/documentation/)
- [Site Kit GitHub Repository](https://github.com/google/site-kit-wp)
- [WordPress Plugin Page](https://wordpress.org/plugins/google-site-kit/)

### Research References
- [Site Kit REST API Discussion](https://github.com/google/site-kit-wp/blob/develop/includes/Core/REST_API/REST_Routes.php)
- [Third-Party Integration Support Thread](https://wordpress.org/support/topic/can-i-integrate-the-site-kit-with-an-custom-plugin/)
- [Available Filters Discussion](https://github.com/google/site-kit-wp/issues/199)

### Related NV oOS Documentation
- [Integration Architecture](../architecture/integrations.md)
- [Tool Development Guide](../development/creating-tools.md)
- [Optional Dependencies](../reference/optional-dependencies.md)

## Conclusion

**Recommendation: ✅ PROCEED WITH INTEGRATION**

The Google Site Kit integration is:
- ✅ **Technically feasible** - REST API and hooks available
- ✅ **User-friendly** - Leverages existing Site Kit setup
- ✅ **Valuable** - Provides AI access to critical Google data
- ✅ **Low risk** - Optional integration, no core modifications
- ✅ **Maintainable** - Follows existing integration patterns

This integration will significantly enhance NV oOS's value proposition for WordPress site owners who use Google services, enabling AI-powered insights and automation for analytics, search performance, and site optimization.

---

**Document Version:** 1.0  
**Last Updated:** January 24, 2026  
**Author:** NV Digital Solutions  
**Status:** Research Complete - Ready for Implementation
