# Web Browser Pro Tool - Decision Summary

**Status:** ✅ APPROVED  
**Created:** January 9, 2026  
**Decision:** Create new Pro tool `web_browser` with Playwright

## Decision

Create a new **Pro tool** called `web_browser` that provides browser automation capabilities using Playwright, rather than enhancing the existing `web_search` tool.

## Why Pro Tool? (Not Enhancing web_search)

### 1. Base Version Size Concerns ⚠️

The base version is already substantial:
- **118 tools** currently in base
- **17MB** includes directory size
- **165 tool files** in base includes/tools/

Adding Playwright would:
- ❌ Add **~200MB** of dependencies (Playwright + Chromium)
- ❌ Increase plugin size by **>10x**
- ❌ Slow down activation and updates for all users
- ❌ Impact users who don't need browser automation

**Solution:** Keep base lean, add advanced features to Pro addon.

### 2. Clear Separation of Concerns

- `web_search` = API-based search (fast, lightweight)
- `web_browser` = Browser automation (slow, resource-intensive)

These are **fundamentally different operations** with different:
- Performance characteristics
- Resource requirements
- Use cases
- User expectations

### 3. Resource Requirements

Browser automation requires:
- **200-500MB RAM** per browser instance
- **High CPU** usage for rendering
- **Significant disk space** for screenshots/PDFs
- **Long execution times** (15-60 seconds)

This is **not suitable for base plugin** that should be lean and fast.

### 4. Target Audience Alignment

Browser automation is an **advanced feature** needed by:
- Power users automating complex workflows
- Developers testing JavaScript-heavy sites
- Agencies needing screenshot/PDF generation
- Enterprise users with automation requirements

These users are **already Pro customers** or should be.

## What Will web_browser Do?

### Core Capabilities

1. **Navigate & Render**
   - Visit any URL
   - Execute JavaScript
   - Handle SPAs and dynamic content

2. **Extract Content**
   - Get rendered HTML
   - Extract specific elements
   - Read computed styles

3. **Browser Actions**
   - Take screenshots (full page or element)
   - Generate PDFs
   - Click buttons
   - Fill forms
   - Submit forms

4. **Advanced Features**
   - Session management
   - Custom headers
   - Network interception
   - Authentication flows

## Architecture

### External Playwright Service (Recommended)

```
WordPress Plugin → HTTP API → Playwright Service (Node.js + Express)
```

**Why external service?**
- No PHP dependencies for browser automation
- Better performance (native Node.js Playwright)
- Isolated from WordPress process
- Can scale horizontally
- Independent deployment and updates

### Fallback Pattern

Follow Crawl4AI pattern:
1. **Primary:** External Playwright service (configurable URL)
2. **Fallback:** Simple HTTP fetch (degraded mode)
3. **Filter:** `wp_mcp_ai_playwright_service_url` to configure

## Comparison: web_search vs web_browser

| Feature | web_search (Base) | web_browser (Pro) |
|---------|------------------|-------------------|
| **Purpose** | API-based search | Browser automation |
| **Speed** | Fast (<10s) | Slow (15-60s) |
| **Size** | Lightweight | Heavy (~200MB) |
| **JavaScript** | ❌ No | ✅ Yes |
| **Screenshots** | ❌ No | ✅ Yes |
| **Forms** | ❌ No | ✅ Yes |
| **PDFs** | ❌ No | ✅ Yes |
| **Resources** | Low | High |
| **Tier** | Base | **Pro** |

## Use Cases

### When to Use web_search (Existing Base Tool)
- ✅ Finding articles, news, documentation
- ✅ General web searches
- ✅ Quick information lookups
- ✅ Low-latency requirements

### When to Use web_browser (New Pro Tool)
- ✅ JavaScript-heavy sites (React, Vue, Angular)
- ✅ Taking screenshots for documentation
- ✅ Generating PDFs of web pages
- ✅ Filling and submitting forms
- ✅ Testing websites
- ✅ Complex automation workflows
- ✅ Authentication flows

## Examples

### Screenshot a Dashboard
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

### Extract Dynamic Content
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

### Fill and Submit Form
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

## Implementation Plan

### Phase 1: MVP (Weeks 1-2)
- Create Playwright service (Node.js + Express)
- Implement basic navigation, screenshot, extract
- Create Pro tool skeleton
- Security checks and URL validation
- Basic tests

### Phase 2: Core Features (Weeks 3-4)
- Form interactions
- PDF generation
- Session management
- Caching layer
- Rate limiting

### Phase 3: Advanced Features (Weeks 5-6)
- Network interception
- Custom headers/user agents
- Viewport configuration
- Multi-page workflows

### Phase 4: Polish (Weeks 7-8)
- Comprehensive tests
- Documentation
- Admin UI settings
- Performance optimization
- Security audit

## Security

### Threats
- SSRF attacks (malicious URLs)
- Resource exhaustion
- Data exfiltration
- XSS risks
- Cookie theft

### Mitigations
- URL validation and whitelist/blacklist
- Timeouts (max 60s)
- Rate limiting per user
- Capability checks (`manage_options`)
- Browser sandboxing
- Network isolation (block internal IPs)
- Audit logging

## Success Metrics

### Performance
- Response time < 30s
- Screenshot < 15s
- PDF < 20s
- 99% success rate

### Adoption
- 100+ users in first month
- 500+ actions/month
- <5% error rate

### Business
- 20% Pro conversion
- ROI positive in 3 months
- Low support burden

## Files to Create

```
addons/pro/includes/tools/class-wp-mcp-ai-tool-web-browser.php
tests/pro/test-web-browser-tool.php
docs/reference/tools/web-browser-tool.md
```

## Admin Settings

New settings in Pro addon:
- `playwright_service_url` - External service URL
- `playwright_enable_fallback` - Enable HTTP fallback
- `playwright_max_timeout` - Max operation timeout
- `playwright_rate_limit` - Actions per user per hour

## Documentation

Update these docs:
- `docs/reference/tools/tool-reference.md` - Add web_browser entry
- `docs/QUICK_REFERENCE.md` - Add Pro tool examples
- `docs/proposals/PLAYWRIGHT_INTEGRATION_EVALUATION.md` - Full evaluation

## Next Steps

1. ✅ Approve Pro tool approach - **DONE**
2. Set up Playwright service repository
3. Create service deployment guide
4. Implement MVP with core features
5. Write comprehensive tests
6. Document thoroughly
7. Beta test with select Pro users
8. Production deployment

## References

- Full evaluation: [PLAYWRIGHT_INTEGRATION_EVALUATION.md](./PLAYWRIGHT_INTEGRATION_EVALUATION.md)
- Playwright docs: https://playwright.dev/
- Crawl4AI pattern: `includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php`
- Pro tools: `addons/pro/includes/tools/`

---

**✅ APPROVED:** Create `web_browser` as a Pro tool to keep base version lean while providing advanced browser automation to Pro users.
