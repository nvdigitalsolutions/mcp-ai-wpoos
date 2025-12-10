# Symfony Validator Migration Plan

**Created**: December 10, 2025  
**Status**: Planning Phase  
**Current Progress**: 11/78 tools (14%)  
**Target**: 74+ tools by Q3 2026

---

## Executive Summary

This document outlines the plan to migrate all 78 base tools in WP oOS to use Symfony Validator for argument validation. Currently, 11 tools have validated versions. The remaining 67 tools will be migrated in 10 batches over 3 quarters (Q1-Q3 2026).

**Total Effort**: 1,040-1,280 hours (26-32 weeks)  
**Investment**: $156K-$192K (at $150/hour)  
**ROI**: 275% over 5 years (per Symfony Integration Executive Summary)

---

## Current Status (Batch 1-3 Complete)

### Completed Tools (11)
1. ✅ save-post
2. ✅ create-cron-job
3. ✅ search-content
4. ✅ create-assistant
5. ✅ get-recent-posts
6. ✅ get-system-logs
7. ✅ create-chart
8. ✅ send-group-email
9. ✅ create-woo-product
10. ✅ get-user-info
11. ✅ create-post (December 10, 2025)

### Remaining: 67 tools

---

## Migration Batches

### BATCH 4 - High-Complexity API Tools
**Priority**: 🔴 High  
**Timeline**: 6-8 weeks  
**Effort**: 240-320 hours  
**Tools**: 12

These tools have the most complex validation logic (50+ validation lines) and interact with external AI/API services:

| Tool | Validation Lines | Complexity |
|------|-----------------|------------|
| run-crawl4ai-job | 120 | Very High |
| scrape-product | 101 | Very High |
| edit-gemini-image | 79 | High |
| web-search | 78 | High |
| generate-openai-image | 78 | High |
| generate-veo-video | 74 | High |
| generate-gemini-image | 67 | High |
| generate-music | 56 | Medium |
| generate-openai-speech | 54 | Medium |
| generate-image-caption | 43 | Medium |
| generate-image-alt-text | 43 | Medium |
| transcribe-openai-audio | 32 | Medium |

**Validation Challenges**:
- URL validation (image, video, audio URLs)
- AI prompt validation (length, format, content)
- Model parameter validation (size, quality, voice)
- File type/format validation
- External API constraint validation

---

### BATCH 5 - Content & Data Tools
**Priority**: 🔴 High  
**Timeline**: 4-6 weeks  
**Effort**: 160-240 hours  
**Tools**: 11

Tools that work with WordPress content, forms, and external data sources:

| Tool | Validation Lines | Type |
|------|-----------------|------|
| get-open-meteo-forecast | 59 | External API |
| submit-document-prompt | 52 | Document Processing |
| get-jetformbuilder-submissions | 52 | WP Integration |
| get-jetformbuilder-forms | 50 | WP Integration |
| run-openai-external-action | 47 | External API |
| import-elementor-template-kit | 47 | WP Integration |
| analyze-comment-content | 54 | Content Analysis |
| crawl4ai-price-lookup | 39 | Web Scraping |
| reliefweb-reports | 38 | External API |
| get-site-health | 38 | WordPress Core |
| get-update-status | 33 | WordPress Core |

**Validation Challenges**:
- Geographic coordinates (lat/lon)
- Date/time range validation
- Form ID and field validation
- Document format validation
- External API endpoint validation

---

### BATCH 6 - Cache & Performance Tools
**Priority**: 🟡 Medium  
**Timeline**: 2-3 weeks  
**Effort**: 80-120 hours  
**Tools**: 3

Infrastructure and caching tools:

1. **purge-cloudflare-cache** (53 lines)
   - Zone ID validation
   - Cache tag validation
   - API credential validation

2. **purge-varnish-cache** (30 lines)
   - Cache key validation
   - Pattern validation

3. **purge-cache** (general)
   - Cache type enum
   - Scope validation

---

### BATCH 7 - Cron & Scheduling Tools
**Priority**: 🟡 Medium  
**Timeline**: 2 weeks  
**Effort**: 80 hours  
**Tools**: 3

Complement the existing create-cron-job tool:

1. **delete-cron-job**
   - Job ID validation (NotBlank, string)
   - Permission checks (WPCapability)

2. **get-cron-job**
   - Job ID validation
   - Response format

3. **list-cron-jobs**
   - Filter parameters
   - Pagination

**Pattern**: Simple CRUD operations, minimal complexity.

---

### BATCH 8 - Image Manipulation Tools
**Priority**: 🟡 Medium  
**Timeline**: 3-4 weeks  
**Effort**: 120-160 hours  
**Tools**: 4

All extend `WP_MCP_AI_Tool_Image_Base`:

1. **convert-image-format**
   - Source format validation
   - Target format enum (jpg, png, webp, gif)
   - Quality parameter (0-100)

2. **crop-image**
   - X, Y coordinate validation (Positive)
   - Width, height validation (Positive)
   - Boundary validation (custom constraint)

3. **resize-image**
   - Width, height validation
   - Aspect ratio preservation flag
   - Max dimensions

4. **rotate-image**
   - Angle validation (-360 to 360)
   - Direction enum (cw, ccw)

**Shared Validation**:
- Attachment ID (WPPostExists, image type)
- Image format validation

---

### BATCH 9 - WordPress Integration Tools
**Priority**: 🟡 Medium  
**Timeline**: 2-3 weeks  
**Effort**: 80-120 hours  
**Tools**: 7

Tools that integrate with popular WordPress plugins:

1. **get-jetengine-items** - Query JetEngine CCT
2. **invoke-jetengine-route** - Call JetEngine REST routes
3. **list-jetengine-routes** - List available routes
4. **get-elementor-templates** - Fetch Elementor templates
5. **get-rankmath-seo** - Get Rank Math SEO data
6. **get-woo-products** - Query WooCommerce products
7. **get-woo-recent-orders** - Get recent WooCommerce orders

**Validation Patterns**:
- Post/Product ID validation
- Query parameter validation (filters, sorting)
- Pagination (page, per_page)
- Date range validation

---

### BATCH 10 - Location & Search Tools
**Priority**: 🟢 Low  
**Timeline**: 2 weeks  
**Effort**: 80 hours  
**Tools**: 5

Geographic and search utilities:

1. **geocode-address** - Address to coordinates
2. **search-places** - Search for places/POI
3. **search-attachments** - Search media library
4. **get-gdacs-events** - Get disaster events (GDACS API)
5. **get-nhc-active-storms** - Get hurricane data (NHC API)

**Validation**:
- Address string validation
- Latitude/longitude ranges
- Search query length
- Result limit validation

---

### BATCH 11 - Authentication & Assistant Tools
**Priority**: 🟢 Low  
**Timeline**: 2 weeks  
**Effort**: 80 hours  
**Tools**: 4

Auth token generation and assistant probing:

1. **generate-auth0-token** - Create Auth0 JWT
2. **generate-simple-jwt-token** - Create simple JWT
3. **probe-chat** - Test chat functionality
4. **probe-remote-mcp** - Test remote MCP connection

**Validation**:
- Credential validation (secrets, API keys)
- Token expiry validation
- URL validation for remote endpoints
- Payload structure validation

---

### BATCH 12 - Utility & Monitoring Tools
**Priority**: 🟢 Low  
**Timeline**: 2 weeks  
**Effort**: 80 hours  
**Tools**: 7

System utilities and monitoring:

1. **check-site-security** - Security audit
2. **check-video-status** - Video processing status
3. **analyze-video** - Video content analysis
4. **count-tokens** - Count AI tokens in text
5. **get-environment-status** - Environment diagnostics
6. **open-openai-logs** - Access OpenAI logs
7. **open-openai-usage** - View OpenAI usage stats

**Validation**:
- ID validation (video ID, session ID)
- Text validation (length, format)
- Date range validation
- Filter parameters

---

### BATCH 13 - Profession & Query Tools
**Priority**: 🟢 Low  
**Timeline**: 1-2 weeks  
**Effort**: 40-80 hours  
**Tools**: 7

Specialized content and query tools:

1. **get-profession** - Retrieve profession data
2. **save-profession** - Save profession data
3. **list-professions** - List all professions
4. **profession-stats** - Profession statistics
5. **query-mesh-intelligent** - Intelligent content query
6. **query-remote-site** - Query external WordPress site
7. **get-site-summary** - Get site summary data

**Validation**:
- Profession ID validation
- Profession data schema
- Query string validation
- Remote URL validation

---

## Implementation Timeline

### Q1 2026 (January - March)
**Goal**: Migrate high-priority, high-complexity tools

- **Month 1 (Jan)**: Batch 4 - Part 1 (6 tools)
  - run-crawl4ai-job
  - scrape-product
  - edit-gemini-image
  - web-search
  - generate-openai-image
  - generate-veo-video

- **Month 2 (Feb)**: Batch 4 - Part 2 (6 tools) + Batch 5 - Part 1 (5 tools)
  - Batch 4: generate-gemini-image, generate-music, generate-openai-speech, generate-image-caption, generate-image-alt-text, transcribe-openai-audio
  - Batch 5: get-open-meteo-forecast, submit-document-prompt, get-jetformbuilder-submissions, get-jetformbuilder-forms, run-openai-external-action

- **Month 3 (Mar)**: Batch 5 - Part 2 (6 tools)
  - import-elementor-template-kit
  - analyze-comment-content
  - crawl4ai-price-lookup
  - reliefweb-reports
  - get-site-health
  - get-update-status

**Q1 Output**: 23 validated tools (34 total, 44% complete)

---

### Q2 2026 (April - June)
**Goal**: Migrate medium-priority WordPress integration and infrastructure tools

- **Month 4 (Apr)**: Batches 6 & 7 (6 tools)
  - Batch 6: purge-cloudflare-cache, purge-varnish-cache, purge-cache
  - Batch 7: delete-cron-job, get-cron-job, list-cron-jobs

- **Month 5 (May)**: Batch 8 (4 tools)
  - convert-image-format
  - crop-image
  - resize-image
  - rotate-image

- **Month 6 (Jun)**: Batch 9 (7 tools)
  - get-jetengine-items
  - invoke-jetengine-route
  - list-jetengine-routes
  - get-elementor-templates
  - get-rankmath-seo
  - get-woo-products
  - get-woo-recent-orders

**Q2 Output**: 17 validated tools (51 total, 65% complete)

---

### Q3 2026 (July - September)
**Goal**: Complete remaining low-priority utility and specialized tools

- **Month 7 (Jul)**: Batches 10 & 11 (9 tools)
  - Batch 10: geocode-address, search-places, search-attachments, get-gdacs-events, get-nhc-active-storms
  - Batch 11: generate-auth0-token, generate-simple-jwt-token, probe-chat, probe-remote-mcp

- **Month 8 (Aug)**: Batch 12 (7 tools)
  - check-site-security
  - check-video-status
  - analyze-video
  - count-tokens
  - get-environment-status
  - open-openai-logs
  - open-openai-usage

- **Month 9 (Sep)**: Batch 13 (7 tools)
  - get-profession
  - save-profession
  - list-professions
  - profession-stats
  - query-mesh-intelligent
  - query-remote-site
  - get-site-summary

**Q3 Output**: 23 validated tools (74 total, 95% complete)

---

## Total Effort & Investment

| Metric | Value |
|--------|-------|
| Total Tools to Migrate | 67 |
| Total Batches | 10 (Batch 4-13) |
| Total Weeks | 26-32 |
| Total Hours | 1,040-1,280 |
| Cost (@ $150/hr) | $156K-$192K |
| Timeline | 9 months (Q1-Q3 2026) |
| Expected ROI | 275% over 5 years |

---

## Per-Batch Deliverables

Each batch includes:

1. **Argument Validation Classes**
   - One class per tool
   - Symfony Validator attributes
   - Custom WordPress constraints where needed
   - PHPDoc documentation

2. **Validated Tool Classes**
   - Extends `WP_MCP_AI_Validated_Tool`
   - Implements `get_validation_class()`
   - Implements `execute_validated()`
   - Delegates to original tool

3. **Registration**
   - Added to `validated-tools-init.php`
   - Version check (PHP 8.0+)
   - Proper file inclusion

4. **Test Suite**
   - Minimum 8 test methods per tool
   - Test metadata (slug, name, description)
   - Test successful validation
   - Test validation failures
   - Test edge cases
   - Test capability flags
   - Test schema compatibility

5. **Documentation**
   - Update tool reference docs
   - Migration notes
   - Breaking changes (if any)

---

## Success Criteria

### Code Quality
- ✅ All validation uses Symfony Validator attributes
- ✅ No manual validation in execute_validated()
- ✅ Follows existing validated tool patterns
- ✅ Passes WPCS linting
- ✅ No security vulnerabilities (CodeQL)

### Testing
- ✅ Minimum 8 test methods per tool
- ✅ 100% test pass rate
- ✅ Code coverage >80% for validation logic
- ✅ All edge cases covered

### Documentation
- ✅ Clear PHPDoc on all classes/methods
- ✅ Validation rules documented
- ✅ Migration guide updated
- ✅ Tool reference updated

### Performance
- ✅ No regression vs manual validation
- ✅ Validation time <10ms per tool
- ✅ Memory usage stable

### Backward Compatibility
- ✅ Original tools remain functional
- ✅ No breaking changes to APIs
- ✅ Graceful degradation (PHP <8.0)

---

## Risk Assessment

### Technical Risks

1. **PHP Version Compatibility** (Medium Risk)
   - **Issue**: Attributes require PHP 8.0+
   - **Mitigation**: Keep original tools functional, version check in validated-tools-init.php
   - **Fallback**: Users on PHP 7.4 use original tools

2. **Custom Constraint Complexity** (Medium Risk)
   - **Issue**: Some tools need complex WordPress-specific validation
   - **Mitigation**: Create reusable custom constraints (WPPostExists, WPCapability, etc.)
   - **Examples**: Already implemented WPPostExists, WPCapability

3. **External API Validation** (Low Risk)
   - **Issue**: API parameters change over time
   - **Mitigation**: Use flexible validation, version documentation
   - **Monitoring**: Track API changes

4. **Performance Regression** (Low Risk)
   - **Issue**: Symfony Validator could be slower
   - **Mitigation**: Benchmark each batch, optimize if needed
   - **Baseline**: Current benchmark shows <5% overhead

### Resource Risks

1. **Development Capacity** (Medium Risk)
   - **Issue**: 1,040-1,280 hours is significant
   - **Mitigation**: Phased approach, can pause between batches
   - **Buffer**: 20% time buffer built into estimates

2. **Developer Learning Curve** (Low Risk)
   - **Issue**: Team needs to learn Symfony Validator patterns
   - **Mitigation**: Pattern established, documentation available
   - **Training**: First 3 batches serve as training

### Business Risks

1. **User Impact** (Low Risk)
   - **Issue**: Changes could break user workflows
   - **Mitigation**: Backward compatible, validated tools are opt-in
   - **Rollback**: Original tools always available

2. **Timeline Delays** (Medium Risk)
   - **Issue**: Could take longer than 9 months
   - **Mitigation**: Buffer time, can extend Q3
   - **Priority**: High-priority batches first

---

## Monitoring & Metrics

### During Migration

Track per batch:
- Tools migrated
- Lines of validation code eliminated
- Test coverage percentage
- Time spent vs estimated
- Bugs found/fixed

### Post-Migration

Monitor:
- Validation error rates
- Performance metrics (validation time)
- User adoption (validated vs original tool usage)
- Bug reports related to validation
- Developer productivity (time to add new tools)

---

## Dependencies & Prerequisites

### Required Before Starting

1. ✅ Symfony Validator library installed (Done)
2. ✅ Base validation infrastructure (Done)
3. ✅ Custom WordPress constraints (WPPostExists, WPCapability) (Done)
4. ✅ Test infrastructure (Done)
5. ✅ Example migrations (11 tools completed) (Done)

### Nice to Have

1. ⚠️ Automated migration script (could reduce effort by 20%)
2. ⚠️ Validation rule generator from parameter schema
3. ⚠️ Bulk test generator

---

## Recommendations

### Immediate Actions (December 2025)

1. **Approve Plan**: Stakeholder review and approval
2. **Allocate Resources**: Assign developers for Q1 2026
3. **Set Up Tracking**: Project board for batch progress
4. **Create Templates**: Standardize argument class template

### Q1 2026 Start

1. **Batch 4 Kickoff**: Start with highest-priority tools
2. **Weekly Check-ins**: Track progress, blockers
3. **Code Reviews**: After each tool, not just batch
4. **Documentation**: Update as you go, not at end

### Optimization Opportunities

1. **Parallel Development**: Multiple developers can work on different tools simultaneously
2. **Code Generation**: Create scaffolding scripts to generate boilerplate
3. **Shared Constraints**: Build library of reusable custom constraints
4. **Test Templates**: Create test templates for common patterns

---

## Conclusion

Migrating all 78 tools to Symfony Validator is a significant but achievable effort. With a phased approach over 9 months, the project can deliver:

- **Cleaner Codebase**: 5,000-7,000 lines of validation code eliminated
- **Better DX**: Self-documenting validation, easier to maintain
- **Type Safety**: PHP 8+ attributes provide compile-time checks
- **Consistency**: Standardized validation across all tools
- **Foundation**: Ready for future tool development

**Total Investment**: $156K-$192K  
**Expected ROI**: 275% over 5 years  
**Break-Even**: ~16 months  

**Recommendation**: Proceed with phased migration starting Q1 2026.

---

**Document Status**: Draft for Review  
**Next Steps**: Stakeholder approval, resource allocation  
**Contact**: Development team lead
