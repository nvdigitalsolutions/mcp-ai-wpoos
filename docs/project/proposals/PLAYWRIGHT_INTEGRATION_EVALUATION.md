# Playwright Integration Evaluation

**Status:** ✅ DECISION APPROVED - Pro Tool  
**Created:** January 9, 2026  
**Author:** Copilot Workspace Agent  
**Decision:** Create new Pro tool `web_browser` with Playwright

## Executive Summary

This document evaluates whether to enhance the existing `web_search` tool with Playwright or create a new Pro tool. 

**✅ DECISION: Create a new Pro tool called `web_browser`** that provides browser automation capabilities complementing the existing `web_search` tool.

### Key Decision Factors

1. **Base version size management** - The base version already has 118 tools and 17MB of includes. Adding browser automation would significantly increase base plugin size and complexity.
2. **Resource-intensive operations** - Playwright requires ~200MB dependencies and substantial CPU/memory, making it unsuitable for the base version.
3. **Clear separation of concerns** - Search vs browser automation are distinct operations.
4. **Pro feature justification** - Advanced browser automation capabilities align with Pro tier value proposition.

## Problem Statement

The current `web_search` tool provides excellent API-based web search capabilities through Brave Search and DuckDuckGo, but it has limitations:

- ❌ Cannot render JavaScript-heavy websites (SPAs, dynamic content)
- ❌ Cannot interact with web forms or click buttons
- ❌ Cannot take screenshots or generate PDFs
- ❌ Cannot handle authentication flows or sessions
- ❌ Cannot execute complex browser automation workflows
- ❌ Cannot scrape content from sites that require JavaScript execution

## Analysis

### Option 1: Enhance Existing web_search Tool

**Pros:**
- Single tool for all web-related operations
- Users don't need to choose between tools
- Consolidated web access point

**Cons:**
- ❌ **Semantic mismatch**: `web_search` implies searching, not browser automation
- ❌ **Breaking change**: Significantly changes tool behavior and capabilities
- ❌ **Complexity**: Mixing two distinct concerns (search vs automation)
- ❌ **Performance**: Playwright adds ~200MB dependencies and is much slower
- ❌ **Resource usage**: Browser automation requires significant system resources
- ❌ **Cost implications**: Different pricing model needed
- ❌ **API incompatibility**: Current schema doesn't support browser actions

### Option 2: Create New Pro Tool (✅ APPROVED)

**Pros:**
- ✅ **Clear separation of concerns**: Search vs browser automation
- ✅ **Semantic clarity**: Tool name matches functionality
- ✅ **No breaking changes**: Existing tool unchanged
- ✅ **Performance isolation**: Heavy operations don't impact lightweight searches
- ✅ **Feature gating**: Pro-level functionality for premium users
- ✅ **Independent evolution**: Each tool can be optimized separately
- ✅ **Better pricing model**: Can charge for resource-intensive operations
- ✅ **Base version size control**: Keeps base plugin lean (currently 118 tools, 17MB)
- ✅ **Dependency management**: Avoids adding heavy dependencies to base version
- ✅ **Target audience alignment**: Advanced users who need Pro features

**Cons:**
- Users need to choose which tool to use
- Two tools to maintain

## Approved Solution: Create New Pro Tool

### Current Plugin Size Context

**Base Version:**
- **118 tools** in base version
- **17MB** includes directory
- **165 tool files** in `includes/tools/`
- Already substantial in size and functionality

**Pro Addon:**
- **27 tools** in Pro addon (current)
- **488KB** pro tools directory
- **42 tool files** in `addons/pro/includes/tools/`
- Room for growth and advanced features

**Implication:** Adding browser automation to base would:
- ❌ Add ~200MB of Playwright dependencies
- ❌ Increase base plugin size by >10x
- ❌ Slow down plugin activation and updates
- ❌ Impact all users, even those who don't need it
- ❌ Complicate dependency management

**Solution:** Keep base lean, add to Pro addon instead.

### Tool Design: `web_browser`

#### Tool Metadata
```php
Slug: web_browser
Name: Web Browser Automation
Location: addons/pro/includes/tools/class-wp-mcp-ai-tool-web-browser.php
Category: Pro Tool (requires Pro addon)
```

#### Capabilities
1. **Page Navigation & Rendering**
   - Navigate to any URL
   - Execute JavaScript
   - Wait for dynamic content to load
   - Handle SPAs and AJAX-heavy sites

2. **Content Extraction**
   - Extract rendered HTML after JS execution
   - Get page text content
   - Extract specific elements via CSS selectors
   - Get computed styles

3. **Browser Actions**
   - Take screenshots (full page or element)
   - Generate PDFs
   - Click buttons and links
   - Fill form fields
   - Submit forms

4. **Session Management**
   - Maintain cookies across requests
   - Handle authentication flows
   - Session persistence

5. **Advanced Features**
   - Network request interception
   - Response modification
   - Custom headers and user agents
   - Viewport configuration

#### Parameters Schema
```json
{
  "type": "object",
  "properties": {
    "url": {
      "type": "string",
      "description": "URL to navigate to",
      "format": "uri"
    },
    "action": {
      "type": "string",
      "enum": ["navigate", "screenshot", "pdf", "extract", "click", "type", "submit"],
      "description": "Action to perform"
    },
    "selector": {
      "type": "string",
      "description": "CSS selector for element-specific actions"
    },
    "wait_for": {
      "type": "string",
      "enum": ["load", "domcontentloaded", "networkidle"],
      "default": "load",
      "description": "When to consider navigation complete"
    },
    "timeout": {
      "type": "integer",
      "minimum": 5000,
      "maximum": 60000,
      "default": 30000,
      "description": "Operation timeout in milliseconds"
    },
    "extract_selector": {
      "type": "string",
      "description": "CSS selector to extract content from"
    },
    "screenshot_options": {
      "type": "object",
      "properties": {
        "full_page": {"type": "boolean", "default": true},
        "type": {"type": "string", "enum": ["png", "jpeg"], "default": "png"}
      }
    }
  },
  "required": ["url", "action"]
}
```

#### Capability Flags
```php
return array(
    'requires-credentials',  // May need API keys for external service
    'requires-capability',   // Requires elevated permissions
    'read-only',            // Can only read, not modify external sites
    'external-api',         // Makes external HTTP requests
    'rate-limited',         // Subject to rate limits
    'may-timeout',          // Operations can be slow
    'async',                // Long-running operations
    'resource-intensive',   // High CPU/memory usage
    'network-dependent',    // Requires internet connectivity
    'non-deterministic',    // Results may vary
    'pro-only',             // Requires Pro addon
);
```

## Implementation Architecture

### External Playwright Service (RECOMMENDED)

Similar to Crawl4AI integration:

**Architecture:**
```
WordPress Plugin → HTTP API → External Playwright Service (Node.js)
```

**Advantages:**
- ✅ No PHP dependencies for browser automation
- ✅ Better performance (Node.js native Playwright)
- ✅ Isolation from WordPress process
- ✅ Horizontal scaling possible
- ✅ Can use official Playwright packages
- ✅ Independent deployment and updates

**Implementation:**
1. Create separate Playwright service (Node.js + Express)
2. Expose REST API endpoints
3. WordPress tool makes HTTP requests
4. Service returns results (HTML, screenshots, PDFs)
5. WordPress caches and stores results

**Service Endpoints:**
```
POST /api/navigate   - Navigate and extract
POST /api/screenshot - Take screenshot
POST /api/pdf        - Generate PDF
POST /api/interact   - Click/type/submit
GET  /api/status     - Health check
```

### Local Fallback Pattern

Follow Crawl4AI pattern with external service + local fallback:

**Implementation:**
1. Primary: External Playwright service
2. Fallback: Simple HTTP fetch with timeout (like current web_search)
3. Filter: `wp_mcp_ai_playwright_service_url` to configure

**Advantages:**
- ✅ Works without external service (degraded mode)
- ✅ Familiar pattern (matches Crawl4AI)
- ✅ Easy testing without infrastructure

## Comparison with Existing Tools

| Feature | web_search | run_crawl4ai_job | web_browser (Pro) |
|---------|-----------|------------------|-------------------|
| **Purpose** | API-based search | Web scraping | Browser automation |
| **Speed** | Fast (<10s) | Medium (10-30s) | Slow (15-60s) |
| **JS Execution** | ❌ No | ✅ Yes | ✅ Yes |
| **Screenshots** | ❌ No | ❌ No | ✅ Yes |
| **Form Interaction** | ❌ No | ❌ No | ✅ Yes |
| **PDF Generation** | ❌ No | ❌ No | ✅ Yes |
| **Authentication** | ❌ No | ❌ Limited | ✅ Yes |
| **Resource Usage** | Low | Medium | High |
| **Pricing Tier** | Base | Base | **Pro** |

## Use Cases

### When to Use Each Tool

**web_search** (Existing):
- General web search queries
- Finding articles, documentation, news
- Quick lookups without interaction
- Low-latency requirements

**run_crawl4ai_job** (Existing):
- Web scraping multiple pages
- Content extraction from known URLs
- Batch processing
- Data mining

**web_browser** (New Pro Tool):
- JavaScript-heavy sites (SPAs)
- Form submissions and interactions
- Taking screenshots for documentation
- Generating PDFs of web pages
- Authentication flows
- Complex automation workflows
- Testing websites

### Example Workflows

**Screenshot a Dashboard:**
```json
{
  "tool": "web_browser",
  "arguments": {
    "url": "https://example.com/dashboard",
    "action": "screenshot",
    "screenshot_options": {
      "full_page": true,
      "type": "png"
    }
  }
}
```

**Fill and Submit Form:**
```json
{
  "tool": "web_browser",
  "arguments": {
    "url": "https://example.com/contact",
    "action": "submit",
    "form_data": {
      "name": "John Doe",
      "email": "john@example.com",
      "message": "Hello!"
    }
  }
}
```

**Extract Dynamic Content:**
```json
{
  "tool": "web_browser",
  "arguments": {
    "url": "https://spa-website.com",
    "action": "extract",
    "wait_for": "networkidle",
    "extract_selector": ".product-list"
  }
}
```

## Security Considerations

### Threats
1. **SSRF Attacks**: Malicious URLs targeting internal network
2. **Resource Exhaustion**: Long-running browser sessions
3. **Data Exfiltration**: Extracting sensitive information
4. **XSS Risks**: Executing malicious JavaScript
5. **Cookie Theft**: Session hijacking

### Mitigations
1. **URL Validation**: Strict URL whitelist/blacklist
2. **Timeouts**: Enforce maximum execution time (60s)
3. **Rate Limiting**: Limit requests per user/assistant
4. **Capability Checks**: Require `manage_options` or custom capability
5. **Sandboxing**: Run browser in isolated environment
6. **Content Security**: Disable unnecessary features (plugins, popups)
7. **Network Isolation**: Block access to internal IPs
8. **Logging**: Audit all browser automation requests

## Cost & Resource Implications

### Resource Usage
- **CPU**: High (browser rendering)
- **Memory**: 200-500MB per browser instance
- **Disk**: ~200MB Playwright dependencies + screenshots/PDFs
- **Network**: Moderate (external service calls)

### Pricing Model
Suggested pricing tiers:
- **Free tier**: 10 browser actions/month
- **Pro tier**: 100 browser actions/month
- **Enterprise**: Unlimited with rate limiting

### Infrastructure Requirements
- Node.js 18+ server
- 2GB+ RAM per instance
- Chrome/Chromium installation
- Load balancer for scaling

## Implementation Roadmap

### Phase 1: MVP (Week 1-2)
- [ ] Create external Playwright service (Node.js)
- [ ] Implement basic endpoints (navigate, screenshot, extract)
- [ ] Create Pro tool skeleton in `addons/pro/includes/tools/`
- [ ] Add URL validation and security checks
- [ ] Basic integration tests

### Phase 2: Core Features (Week 3-4)
- [ ] Form interaction (click, type, submit)
- [ ] PDF generation
- [ ] Session management
- [ ] Caching layer
- [ ] Rate limiting
- [ ] Error handling

### Phase 3: Advanced Features (Week 5-6)
- [ ] Network interception
- [ ] Custom headers/user agents
- [ ] Viewport configuration
- [ ] Multi-page workflows
- [ ] Screenshot annotations

### Phase 4: Polish & Documentation (Week 7-8)
- [ ] Comprehensive tests
- [ ] User documentation
- [ ] Admin UI settings
- [ ] Performance optimization
- [ ] Security audit

## Documentation Requirements

### User Documentation
- Tool reference entry in `docs/reference/tools/tool-reference.md`
- Usage examples in `docs/guides/`
- Troubleshooting guide
- Security best practices

### Developer Documentation
- API specification for Playwright service
- Deployment guide
- Architecture diagrams
- Testing strategy

### Admin Documentation
- Settings configuration
- Resource requirements
- Monitoring and logging
- Cost estimation

## Success Metrics

### Performance
- Response time < 30s for most operations
- Screenshot generation < 15s
- PDF generation < 20s
- 99% success rate for valid URLs

### Adoption
- 100+ users in first month
- 500+ browser actions/month
- <5% error rate
- Positive user feedback

### Business
- 20% Pro conversion rate
- ROI positive within 3 months
- Low support burden (<2 tickets/week)

## Conclusion

### ✅ Approved Decision: Create New Pro Tool

**Rationale:**

1. ✅ **Base version size management** - Base already has 118 tools (17MB). Adding Playwright (~200MB) would bloat the plugin
2. ✅ **Clear separation of concerns** - Search vs automation are distinct operations
3. ✅ **No breaking changes** - Existing `web_search` tool remains unchanged
4. ✅ **Better user experience** - Users get specialized tools for specific tasks
5. ✅ **Pro feature gating** - Resource-intensive operations justify Pro tier
6. ✅ **Performance isolation** - Heavy browser automation doesn't impact lightweight searches
7. ✅ **Independent evolution** - Each tool can be optimized independently
8. ✅ **Better architecture** - External service pattern proven with Crawl4AI
9. ✅ **Dependency management** - Keeps base plugin dependencies minimal
10. ✅ **Target audience** - Advanced users who need Pro features are the right audience

### Implementation Approach

- **Architecture**: External Playwright service (Node.js + Express)
- **Location**: `addons/pro/includes/tools/class-wp-mcp-ai-tool-web-browser.php`
- **Timeline**: 8 weeks to full production
- **Priority**: High - fills significant capability gap

### Next Steps

1. ✅ Get stakeholder approval for Pro tool approach - **APPROVED**
2. Set up Playwright service repository
3. Implement MVP with core features
4. Create comprehensive test suite
5. Document thoroughly
6. Beta test with select users
7. Production deployment

## References

- [Playwright Documentation](https://playwright.dev/)
- [Crawl4AI Integration Pattern](../../includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php)
- [Pro Tools Architecture](../../addons/pro/includes/tools/)
- [Tool Registry](../../includes/class-wp-mcp-ai-tool-registry.php)
- [WordPress HTTP API](https://developer.wordpress.org/plugins/http-api/)

---

**✅ DECISION APPROVED**: Create new `web_browser` Pro tool with external Playwright service architecture.
