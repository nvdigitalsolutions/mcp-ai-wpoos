# WPCS Restoration Plan - Base Plugin WordPress.org Submission

**Date:** February 1, 2026  
**Current Status:** 155 errors, 489 warnings (Base plugin only, Pro addon excluded)  
**Target:** Address violations for WordPress.org submission  
**Context:** PR #3464 reverted PHPCS compliance work; this plan restores it

---

## Executive Summary

### Current Violation Breakdown (Top 15)

| Rank | Category | Sniff | Count | Type | Priority |
|------|----------|-------|-------|------|----------|
| 1 | **Unused Parameters** | After last used parameter | 90 | WARNING | 🔵 Document |
| 2 | **error_log()** | Development function | 50 | WARNING | 🟡 Review |
| 3 | **Direct DB Queries** | Direct query | 44 | ERROR | 🔴 Critical |
| 4 | **No DB Caching** | Missing caching | 39 | WARNING | 🟡 Review |
| 5 | **File Naming** | Invalid class file name | 38 | ERROR | 🔵 Document |
| 6 | **file_get_contents()** | Alternative function | 31 | WARNING | 🟡 Review |
| 7 | **Yoda Conditions** | Not Yoda format | 29 | WARNING | 🟢 Optional |
| 8 | **Slow Queries** | meta_query usage | 23 | WARNING | 🟡 Review |
| 9 | **current_time()** | Timestamp requested | 23 | WARNING | 🟢 Fix |
| 10 | **Prepared SQL** | Not prepared | 16 | ERROR | 🔴 Critical |
| 11 | **base64_decode** | Obfuscation concern | 16 | WARNING | 🟡 Document |
| 12 | **Commented Code** | Found commented code | 14 | WARNING | 🟢 Cleanup |
| 13 | **base64_encode** | Obfuscation concern | 14 | WARNING | 🟡 Document |
| 14 | **Unknown Capabilities** | Capability unknown | 14 | WARNING | 🟡 Document |
| 15 | **Reserved Keywords** | Parameter name 'default' | 13 | WARNING | 🟢 Fix |

**Total:** 644 violations (155 errors + 489 warnings)

---

## WordPress.org Submission Impact Analysis

### 🔴 BLOCKING for WordPress.org (Must Fix)

**Direct Database Queries (44 errors)**
- **Impact:** WordPress.org reviewers scrutinize direct DB access
- **Reason:** Security and performance concerns
- **Action:** Add phpcs:ignore with detailed justification for each
- **Example:** Complex analytics, JetEngine CCT, vector searches

**Prepared SQL Issues (16 errors)**
- **Impact:** SQL injection risk indicators
- **Reason:** WordPress.org security requirements
- **Action:** Verify all use $wpdb->prepare(), add suppressions where safe

**File Naming (38 errors)**
- **Impact:** Non-standard but acceptable with justification
- **Reason:** Architectural decision for enterprise plugin
- **Action:** Add phpcs:ignore comments explaining logical grouping

**Nonce Verification (4 errors)**
- **Impact:** CSRF protection requirements
- **Reason:** WordPress.org security standards
- **Action:** Review each case, add nonces where needed, document REST API exceptions

### 🟡 MODERATE Priority (Should Address)

**error_log() Usage (50 warnings)**
- **Impact:** Development function in production
- **Action:** Wrap in WP_DEBUG checks or use WP logging API

**file_get_contents() (31 warnings)**
- **Impact:** WordPress.org prefers wp_remote_get()
- **Action:** Use wp_remote_* for external URLs, suppress for local files

**base64_encode/decode (30 warnings)**
- **Impact:** Can indicate obfuscation
- **Action:** Add comments explaining legitimate uses (JWT, OAuth, encryption)

**current_time() (23 warnings)**
- **Impact:** Timezone handling concerns
- **Action:** Review each usage, use proper timestamp methods

### 🟢 LOW Priority (Optional/Stylistic)

**Yoda Conditions (29 warnings)**
- **Impact:** Stylistic preference
- **Action:** Convert for consistency or suppress

**Unused Parameters (90 warnings)**
- **Impact:** Interface/hook compatibility requirements
- **Action:** Already documented in PHPCS_IGNORE_ANALYSIS.md

**Commented Code (14 warnings)**
- **Impact:** Code cleanliness
- **Action:** Remove or uncomment useful code

---

## Restoration Strategy

### Phase 1: Critical Security (Est. 2-3 hours)
**Target:** Fix/document blocking security issues

1. **Nonce Verification (4 errors)** ✅ Priority 1
   ```bash
   vendor/bin/phpcs --sniffs=WordPress.Security.NonceVerification includes/
   ```
   - Review each case
   - Add nonces to admin AJAX handlers
   - Document REST API exceptions

2. **Prepared SQL (16 errors)** ✅ Priority 2
   ```bash
   vendor/bin/phpcs --sniffs=WordPress.DB.PreparedSQL includes/
   ```
   - Verify all use $wpdb->prepare()
   - Add suppressions with security justification

### Phase 2: Database & Performance (Est. 3-4 hours)
**Target:** Document architectural database decisions

3. **Direct Database Queries (44 errors)** ✅ Priority 3
   ```bash
   vendor/bin/phpcs --sniffs=WordPress.DB.DirectDatabaseQuery.DirectQuery includes/
   ```
   - Add phpcs:ignore with detailed reason for each:
     - Complex analytics queries
     - JetEngine CCT operations
     - Vector similarity searches
     - Performance-critical operations

4. **DB Caching (39 warnings)** ⚠️ Priority 4
   - Review caching opportunities
   - Add wp_cache_* where beneficial
   - Suppress where caching not applicable

### Phase 3: Code Quality (Est. 2-3 hours)
**Target:** Clean up development artifacts

5. **error_log() Usage (50 warnings)** ⚠️ Priority 5
   ```bash
   vendor/bin/phpcs --sniffs=WordPress.PHP.DevelopmentFunctions.error_log includes/
   ```
   - Wrap in `if ( WP_DEBUG )` checks
   - Convert to `wp_debug_log()` where appropriate

6. **File Naming (38 errors)** ✅ Priority 6
   - Add phpcs:ignore to tool base classes
   - Document logical grouping rationale

7. **Commented Code (14 warnings)** ✅ Priority 7
   - Review and remove dead code
   - Uncomment useful code
   - Document why code is commented

### Phase 4: WordPress Best Practices (Est. 2-3 hours)
**Target:** Align with WordPress standards

8. **file_get_contents() (31 warnings)** ⚠️ Priority 8
   ```bash
   vendor/bin/phpcs --sniffs=WordPress.WP.AlternativeFunctions.file_get_contents includes/
   ```
   - Use wp_remote_* for external URLs
   - Suppress for local file operations
   - Document use cases

9. **current_time() (23 warnings)** ✅ Priority 9
   - Review timestamp usage
   - Use proper WP time functions

10. **base64_encode/decode (30 warnings)** ⚠️ Priority 10
    - Add inline comments explaining:
      - JWT token handling
      - OAuth flow
      - Encryption operations
      - NOT code obfuscation

### Phase 5: Stylistic (Est. 1-2 hours)
**Target:** Optional improvements

11. **Yoda Conditions (29 warnings)** 🟢 Optional
    - Convert `$var === $value` to `$value === $var`
    - Or suppress with justification

12. **Reserved Keywords (13 warnings)** ✅ Quick fix
    - Rename `$default` to `$default_value`
    - Rename other reserved keyword parameters

---

## Implementation Commands

### Run Specific Sniff Checks
```bash
# Critical security
vendor/bin/phpcs --sniffs=WordPress.Security.NonceVerification includes/
vendor/bin/phpcs --sniffs=WordPress.DB.PreparedSQL includes/

# Database
vendor/bin/phpcs --sniffs=WordPress.DB.DirectDatabaseQuery includes/
vendor/bin/phpcs --sniffs=WordPress.DB.DirectDatabaseQuery.NoCaching includes/

# Code quality
vendor/bin/phpcs --sniffs=WordPress.PHP.DevelopmentFunctions.error_log includes/
vendor/bin/phpcs --sniffs=Squiz.PHP.CommentedOutCode includes/

# WordPress best practices
vendor/bin/phpcs --sniffs=WordPress.WP.AlternativeFunctions includes/
vendor/bin/phpcs --sniffs=WordPress.DateTime.CurrentTimeTimestamp includes/
```

### Auto-Fix Where Possible
```bash
# Fix current_time() issues
vendor/bin/phpcbf --sniffs=WordPress.DateTime.CurrentTimeTimestamp includes/

# Fix reserved keyword parameters (some)
vendor/bin/phpcbf --sniffs=Universal.NamingConventions.NoReservedKeywordParameterNames includes/
```

---

## Documentation Updates Required

After implementing fixes, update these documents:

1. **WORDPRESS_ORG_SUBMISSION_READINESS.md**
   - Update from "0 errors" to actual status
   - Document remaining justified violations

2. **WORDPRESS_ORG_COMPLIANCE_CERTIFICATION.md**
   - Update certification status
   - Add section on architectural exceptions

3. **SUBMISSION_QUICK_SUMMARY.md**
   - Update compliance score
   - Reflect realistic assessment

4. **WPCS_STATUS_REPORT.md**
   - Update with current violations
   - Document fixes applied

---

## Success Criteria

### Minimum for WordPress.org Submission
- ✅ 0 critical security errors (nonce, SQL injection)
- ✅ All direct DB queries documented with justification
- ✅ All development functions (error_log) wrapped or removed
- ✅ All base64 usage explained (not obfuscation)
- ✅ File naming exceptions documented
- ✅ <100 total errors (from 155)
- ✅ <400 total warnings (from 489)

### Ideal State
- ✅ <50 total errors
- ✅ <300 total warnings
- ✅ All remaining violations documented with rationale
- ✅ Certification documents updated and accurate

---

## Estimated Timeline

| Phase | Effort | Calendar Time |
|-------|--------|---------------|
| Phase 1: Critical Security | 2-3 hours | 1 day |
| Phase 2: Database | 3-4 hours | 1 day |
| Phase 3: Code Quality | 2-3 hours | 1 day |
| Phase 4: Best Practices | 2-3 hours | 1 day |
| Phase 5: Stylistic | 1-2 hours | 0.5 days |
| Documentation | 1-2 hours | 0.5 days |
| **Total** | **11-17 hours** | **5 days** |

---

## Notes

- **Pro Addon:** Excluded from base plugin WPCS check via phpcs.xml.dist
- **Previous Work:** PR #3454 had fixes that were reverted in PR #3464
- **Current Branch:** copilot/wpcs-review-of-plugin
- **Auto-Fixed:** 19 violations already fixed (155 errors, 489 warnings remain)

---

**Status:** Ready to begin Phase 1  
**Next Action:** Start with nonce verification fixes  
**Document Updated:** February 1, 2026
