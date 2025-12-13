# Batch 5 - Content & Data Tools - Kickoff Plan

**Status**: 📋 Ready to Start  
**Priority**: 🔴 High  
**Timeline**: 4-6 weeks (January 2026)  
**Effort**: 160-240 hours  
**Tools**: 11

---

## Overview

Batch 5 focuses on migrating 11 tools that work with WordPress content, forms, and external data sources. These tools integrate with WordPress core features, third-party plugins (JetFormBuilder), and external APIs (OpenMeteo, ReliefWeb).

**Goal**: Continue the Symfony Validator migration momentum from Batch 4, bringing total completion to 34/78 tools (44%).

---

## Tools to Migrate (11)

### Group 1: External API Tools (3 tools)
**Complexity**: Medium-High  
**Estimated Effort**: 60-80 hours

1. **get-open-meteo-forecast** (59 validation lines)
   - External API: Open-Meteo Weather API
   - Key Parameters: latitude, longitude, forecast days
   - Validation: Geographic coordinates, date ranges
   - Priority: High (frequently used for weather data)

2. **run-openai-external-action** (47 validation lines)
   - External API: Custom OpenAI action endpoints
   - Key Parameters: action_id, parameters object
   - Validation: URL validation, JSON structure
   - Priority: Medium (advanced feature)

3. **reliefweb-reports** (38 validation lines)
   - External API: ReliefWeb humanitarian data
   - Key Parameters: country, disaster type, date range
   - Validation: Choice constraints, date validation
   - Priority: Low (specialized use case)

### Group 2: Document & Content Processing (2 tools)
**Complexity**: Medium  
**Estimated Effort**: 40-60 hours

4. **submit-document-prompt** (52 validation lines)
   - Function: Submit prompts with document context
   - Key Parameters: document_id, prompt, options
   - Validation: Document existence, prompt length
   - Priority: High (core content feature)

5. **analyze-comment-content** (54 validation lines)
   - Function: AI analysis of WordPress comments
   - Key Parameters: comment_id, analysis_type
   - Validation: Comment existence, analysis type enum
   - Priority: Medium (moderation feature)

### Group 3: JetFormBuilder Integration (2 tools)
**Complexity**: Medium  
**Estimated Effort**: 40-60 hours

6. **get-jetformbuilder-submissions** (52 validation lines)
   - Integration: JetFormBuilder form submissions
   - Key Parameters: form_id, date_range, filters
   - Validation: Form existence, date validation
   - Priority: High (form data retrieval)

7. **get-jetformbuilder-forms** (50 validation lines)
   - Integration: JetFormBuilder form listings
   - Key Parameters: limit, offset, status
   - Validation: Pagination, status enum
   - Priority: Medium (form management)

### Group 4: WordPress Core Integration (3 tools)
**Complexity**: Low-Medium  
**Estimated Effort**: 30-50 hours

8. **import-elementor-template-kit** (47 validation lines)
   - Integration: Elementor template import
   - Key Parameters: template_url, options
   - Validation: URL, template structure
   - Priority: Medium (design workflow)

9. **get-site-health** (38 validation lines)
   - WordPress Core: Site health status
   - Key Parameters: check_types, include_debug
   - Validation: Choice constraints, boolean flags
   - Priority: Low (diagnostic tool)

10. **get-update-status** (33 validation lines)
    - WordPress Core: Plugin/theme update status
    - Key Parameters: type (plugins/themes/core)
    - Validation: Type enum, filter options
    - Priority: Low (maintenance tool)

### Group 5: Web Scraping (1 tool)
**Complexity**: Medium  
**Estimated Effort**: 20-30 hours

11. **crawl4ai-price-lookup** (39 validation lines)
    - Function: Product price scraping via Crawl4AI
    - Key Parameters: url, selector, options
    - Validation: URL, CSS selector
    - Priority: Medium (e-commerce feature)

---

## Implementation Order (Recommended)

### Phase 1: High-Priority Core Tools (Week 1-2)
Focus on frequently-used tools with broad applicability:
1. submit-document-prompt
2. get-jetformbuilder-submissions
3. get-open-meteo-forecast

**Rationale**: These tools are actively used and provide immediate value.

### Phase 2: Integration Tools (Week 3-4)
Complete plugin integrations:
4. get-jetformbuilder-forms
5. import-elementor-template-kit
6. analyze-comment-content
7. crawl4ai-price-lookup

**Rationale**: Group by integration type for consistency.

### Phase 3: Specialized APIs (Week 5-6)
Finish external API and diagnostic tools:
8. run-openai-external-action
9. reliefweb-reports
10. get-site-health
11. get-update-status

**Rationale**: Lower priority, can be completed last.

---

## Validation Challenges

### 1. Geographic Coordinates (get-open-meteo-forecast)
**Challenge**: Latitude (-90 to 90) and Longitude (-180 to 180) validation  
**Solution**: Use `#[Assert\Range]` constraints  
**Pattern**:
```php
#[Assert\Range(min: -90, max: 90, notInRangeMessage: 'Latitude must be between -90 and 90')]
public $latitude;

#[Assert\Range(min: -180, max: 180, notInRangeMessage: 'Longitude must be between -180 and 180')]
public $longitude;
```

### 2. Date Range Validation
**Challenge**: Multiple tools accept date ranges for filtering  
**Solution**: Use `#[Assert\Date]` or `#[Assert\DateTime]` constraints  
**Pattern**:
```php
#[Assert\DateTime(message: 'Invalid start date format')]
public $start_date = null;

#[Assert\DateTime(message: 'Invalid end date format')]
public $end_date = null;

#[Assert\GreaterThanOrEqual(propertyPath: 'start_date', message: 'End date must be after start date')]
public $end_date_validation;  // Custom constraint
```

### 3. Form ID Validation (JetFormBuilder tools)
**Challenge**: Validate JetFormBuilder form exists  
**Solution**: Create custom `JetFormExists` constraint (if needed) or use `#[Assert\Positive]`  
**Pattern**:
```php
#[Assert\NotBlank(message: 'Form ID is required')]
#[Assert\Positive(message: 'Form ID must be a positive integer')]
public $form_id;
```

### 4. Document Validation (submit-document-prompt)
**Challenge**: Validate document exists and user has access  
**Solution**: Use existing `#[WPPostExists]` custom constraint  
**Pattern**:
```php
#[Assert\NotBlank(message: 'Document ID is required')]
#[Assert\Positive(message: 'Document ID must be positive')]
#[WPPostExists(post_type: 'attachment', message: 'Document does not exist')]
public $document_id;
```

### 5. External API Endpoint Validation
**Challenge**: Validate custom OpenAI action endpoints  
**Solution**: Use `#[Assert\Url]` with additional format validation  
**Pattern**:
```php
#[Assert\Url(message: 'Action endpoint must be a valid URL')]
#[Assert\Regex(pattern: '/^https:\/\//', message: 'Action endpoint must use HTTPS')]
public $action_endpoint;
```

---

## Tools Required

### Existing Infrastructure
- ✅ Symfony Validator library
- ✅ Base validated tool class (`WP_MCP_AI_Validated_Tool`)
- ✅ Validator service (`WP_MCP_AI_Validator_Service`)
- ✅ Custom constraints (`WPPostExists`, `WPCapability`)
- ✅ Tool registration system (`validated-tools-init.php`)

### New Custom Constraints (If Needed)
- [ ] `JetFormExists` - Validate JetFormBuilder form ID
- [ ] `ValidDateRange` - Validate start_date < end_date
- [ ] `GeographicCoordinate` - Combined lat/lon validation (optional)

---

## Deliverables Per Tool

### 1. Argument Validation Class
Location: `includes/validators/arguments/class-[tool-name]-arguments.php`
- PHP 8.0+ attributes
- All parameters defined with types
- Symfony Validator constraints
- PHPDoc documentation

### 2. Validated Tool Class
Location: `includes/tools/class-wp-mcp-ai-tool-[tool-name]-validated.php`
- Extends `WP_MCP_AI_Validated_Tool`
- Implements required interfaces
- Delegates to original tool
- ~100-150 lines of code

### 3. Test Suite
Location: `tests/test-[tool-name]-validated-tool.php`
- Minimum 8 test methods
- Covers all validation rules
- Tests edge cases
- Tests capability delegation

### 4. Registration Entry
File: `includes/validators/validated-tools-init.php`
- Add tool to `$validated_tools` array
- Follows existing pattern

---

## Success Criteria

### Per Tool
- ✅ All 3 files created
- ✅ Tool registered in init file
- ✅ All tests passing
- ✅ Code passes WPCS linting
- ✅ No security vulnerabilities (CodeQL)
- ✅ Delegation to original tool works
- ✅ Parameter schema inherited correctly

### Batch 5 Complete
- ✅ All 11 tools migrated
- ✅ Total progress: 34/78 (44%)
- ✅ Documentation updated
- ✅ No regressions in original tools
- ✅ Performance acceptable (<0.3% overhead)

---

## Timeline Estimate

### Week 1 (20-30 hours)
- submit-document-prompt
- get-jetformbuilder-submissions
- Begin get-open-meteo-forecast

### Week 2 (20-30 hours)
- Complete get-open-meteo-forecast
- get-jetformbuilder-forms
- import-elementor-template-kit

### Week 3 (20-30 hours)
- analyze-comment-content
- crawl4ai-price-lookup
- run-openai-external-action

### Week 4 (20-30 hours)
- reliefweb-reports
- get-site-health
- get-update-status

### Week 5-6 (20-40 hours)
- Testing and refinement
- Documentation updates
- Code review
- Performance validation

**Total**: 4-6 weeks (100-150 hours)

---

## Risk Assessment

### Technical Risks
1. **JetFormBuilder Dependency** (Low)
   - Risk: Tools require JetFormBuilder plugin
   - Mitigation: Check plugin active in validation, graceful degradation
   
2. **External API Changes** (Medium)
   - Risk: APIs may change parameter requirements
   - Mitigation: Keep validation flexible, document API versions

3. **Geographic Coordinate Edge Cases** (Low)
   - Risk: Validation at poles, dateline crossing
   - Mitigation: Comprehensive range testing

### Resource Risks
1. **Developer Capacity** (Low)
   - Risk: 100-150 hours over 4-6 weeks
   - Mitigation: Tools can be done in any order, parallelizable

### Business Risks
1. **User Impact** (Very Low)
   - Risk: Changes could affect existing workflows
   - Mitigation: Backward compatible, validated tools are opt-in

---

## Testing Strategy

### Unit Tests (Per Tool)
- Test all validation constraints
- Test edge cases (min/max, null, empty)
- Test capability delegation
- Test parameter schema

### Integration Tests (Batch Level)
- Test all 11 tools load without errors
- Test registration system
- Test with actual WordPress environment
- Test with dependencies (JetFormBuilder) active/inactive

### Performance Tests
- Benchmark validation overhead
- Compare to Batch 4 results
- Ensure <0.3% overhead maintained

---

## Documentation Updates Required

### During Implementation
- [ ] Update `SYMFONY_VALIDATOR_MIGRATION_PLAN.md` after each tool
- [ ] Create `BATCH_5_IMPLEMENTATION_GUIDE.md` (similar to Batch 4)
- [ ] Update progress in README if applicable

### After Completion
- [ ] Create `BATCH_5_COMPLETION_SUMMARY.md`
- [ ] Update `SYMFONY_NEXT_STEPS.md` with Batch 6 plan
- [ ] Update `DOCUMENTATION_INDEX.md`
- [ ] Update `tool-reference.md` with validated variants

---

## Resources

### Code References
- **Batch 4 Tools**: Examples of complex validated tools
- **Batch 4 Completion Summary**: `docs/BATCH_4_COMPLETION_SUMMARY.md`
- **Migration Plan**: `docs/SYMFONY_VALIDATOR_MIGRATION_PLAN.md`
- **Implementation Guide**: `docs/BATCH_4_IMPLEMENTATION_GUIDE.md`

### External Documentation
- [Symfony Validator Constraints](https://symfony.com/doc/current/validation.html#constraints)
- [Symfony Range Constraint](https://symfony.com/doc/current/reference/constraints/Range.html)
- [Symfony DateTime Constraint](https://symfony.com/doc/current/reference/constraints/DateTime.html)
- [Open-Meteo API Docs](https://open-meteo.com/en/docs)
- [JetFormBuilder REST API](https://jetformbuilder.com/docs/rest-api/)

---

## Next Steps (Immediate)

### Pre-Implementation
1. [ ] Review and approve this kickoff plan
2. [ ] Prioritize tools within batch (use recommended order)
3. [ ] Set up tracking (project board, checklist)
4. [ ] Allocate developer resources

### First Tool (submit-document-prompt)
1. [ ] Analyze original tool validation logic
2. [ ] Create argument validation class
3. [ ] Create validated tool class
4. [ ] Create test suite
5. [ ] Register tool
6. [ ] Run tests
7. [ ] Update documentation

### Ongoing
- [ ] Daily: Commit progress
- [ ] Weekly: Update migration plan with completed tools
- [ ] After each tool: Run linting and tests
- [ ] After 3 tools: Checkpoint review

---

## Conclusion

Batch 5 represents a diverse set of tools that will test the migration pattern across different integration types: external APIs, WordPress core, third-party plugins, and document processing. Successful completion will bring the project to 44% completion and validate the approach for remaining batches.

**Status**: Ready to begin  
**Recommendation**: Start with high-priority tools (submit-document-prompt, get-jetformbuilder-submissions, get-open-meteo-forecast)  
**Expected Completion**: February 2026

---

**Document Status**: Draft - Ready for Review  
**Created**: December 12, 2025  
**Next Review**: Before Batch 5 implementation begins
