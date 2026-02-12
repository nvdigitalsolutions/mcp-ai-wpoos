# Documentation Organization Guide

This guide explains the organization of documentation in the NV oOS repository.

## Root Directory Documentation

The root directory contains **3 essential documentation files** that every developer/user should see first:

### 📘 Essential Markdown Files (Only)

1. **README.md** (~210KB) - Comprehensive project overview
   - System architecture
   - Installation and configuration
   - Features and capabilities
   - Development setup
   - API documentation

2. **CHANGELOG.md** (~60KB) - Version history and release notes
   - Chronological list of changes
   - Bug fixes and enhancements
   - Breaking changes
   - Deprecations

3. **CONTRIBUTING.md** (~6KB) - Developer contribution guide
   - Getting started
   - Development workflow
   - Testing requirements
   - Pull request process

### 📝 Supporting Files (Non-Markdown)

- **SECURITY.md** (7KB) - Security policy and reporting
  - Supported versions
  - Vulnerability reporting
  - Security best practices
  - Security checklist

- **BUILD.md** (19KB) - Build and deployment process
  - Build commands
  - Asset compilation
  - PHP dependencies (Composer)
  - Release process

- **DEPENDENCIES_BUNDLING.md** (10KB) - Dependency management
  - NPM dependencies
  - Pro addon dependencies
  - Vendor directory pattern
  - Git strategy

- **readme.txt** (23KB) - WordPress.org plugin description
  - WordPress plugin repository format
  - Short description for plugin directory
  - Installation instructions
  - FAQ and changelog

- **tool-status.txt** (7KB) - Tool status labels
  - 215 tools with status indicators
  - Stable/beta/dev/experimental/bug classifications
  - Used by Tools Manager UI

## Documentation Directory Structure

All other documentation is organized in the `docs/` directory:

```
docs/
├── README.md                       # Documentation overview
├── DOCUMENTATION_INDEX.md          # Comprehensive file index
├── QUICK_REFERENCE.md              # Quick start guide
│
├── admin/                          # Admin UI documentation
├── api/                            # REST API documentation
├── architecture/                   # System architecture
├── deployment/                     # Deployment guides
├── features/                       # Feature-specific docs
│   └── TOOLKIT_MEMORY_TRACKING.md  # Pro toolkit memory system
├── fixes/                          # Bug fix documentation
│   └── menu-fixes/                 # Menu structure fixes
│       ├── MENU_FIXES_CONSOLIDATED.md  # All menu fixes
│       └── README.md
├── guides/                         # How-to guides
├── implementation-history/         # Development history
├── proposals/                      # Feature proposals
├── reference/                      # Technical references
├── troubleshooting/               # Troubleshooting guides
└── [many more...]

Total: 650+ files
```

## Recent Reorganizations

### February 12, 2026 - Fix Summaries Archive

**Archived**: 3 completed fix summary files moved from root to archive

**Moved from root to archive/2025/fixes/**:
- PRODUCT_RESEARCH_FIX_SUMMARY.md - Product consolidate page rendering fix (Feb 10)
- PRODUCT_RESEARCH_TAB_FIX_SUMMARY.md - Tab system displaying simultaneously fix (Feb 11)
- VARIABLE_PRODUCT_FIX_SUMMARY.md - WooCommerce variable product support (Feb 12)

**Updates made**:
- Created comprehensive `/archive/2025/fixes/README.md` with index of all 18 archived fix summaries
- Updated `/archive/README.md` to reference new archived files
- Updated `README.md` and `CHANGELOG.md` to point to detailed documentation in `/docs/fixes/`
- Updated `/docs/DOCUMENTATION_INDEX.md` with February 12 update note

**Result**: Root directory now contains **only 3 essential markdown files** (README.md, CHANGELOG.md, CONTRIBUTING.md)

### January 29, 2026 - Root Directory Documentation Consolidation

**Consolidated**: 22 summary and implementation files moved from root to organized subdirectories

**Moved from root to docs/implementation-history/2026/**:
- ADMIN_PAGES_ENHANCEMENT.md
- ADMIN_PAGE_JOB_TRACKING_TEST_COVERAGE.md
- ADMIN_PAGE_TEST_FLOW_VISUAL.md
- AJAX_TEST_IMPLEMENTATION_SUMMARY.md
- CHAT_JOB_STATUS_SSE_IMPLEMENTATION.md
- DEEPSEEK-V4-COMPLETION-SUMMARY.md
- ENHANCEMENT_SUMMARY.md
- IMPLEMENTATION_SUMMARY.md
- SITE_CREATOR_CLEANUP_SUMMARY.md
- SITE_CREATOR_IMPLEMENTATION_COMPLETE.md
- SITE_CREATOR_MENU_CHANGES.md
- SITE_CREATOR_MENU_UI_PREVIEW.md
- SITE_CREATOR_SETTINGS_REORGANIZATION.md
- SITE_CREATOR_TOOLKIT_COMPLETE.md
- SITE_CREATOR_TOOLKIT_IMPLEMENTATION.md
- SITE_CREATOR_TOOLKIT_VISUAL_SUMMARY.md
- SYSTEM-STATUS-PR-SUMMARY.md
- SYSTEM-STATUS-SUMMARY.md
- TASK_SUMMARY.md

**Moved from root to docs/deployment/**:
- PRODUCTION_DEPLOYMENT.md
- PRODUCTION_READY.md

**Moved from root to docs/security/**:
- SECURITY_COMPLIANCE_REPORT.md

**Result**: 
- Root directory now contains only 4 essential documentation files plus 1 supporting file (readme.txt)
- tool-status.txt moved to docs/ directory for better organization
- Implementation summaries properly grouped by year (2026)
- Historical phase/status files archived in archive/ directory
- Deployment and security documentation in appropriate subdirectories
- No information lost
- Cleaner, more maintainable root directory

### January 26, 2026 - Implementation History & Fix Documentation Consolidation

**Consolidated**: 17 implementation and fix summary files moved from root to organized subdirectories

**Moved from root to docs/implementation-history/**:
- IMPLEMENTATION_SUMMARY.md
- IMPLEMENTATION_SUMMARY_EMBEDDED_CHAT_LOGGING.md
- IMPLEMENTATION_SUMMARY_WEBLLM_INIT.md
- IMPLEMENTATION_SUMMARY_WEBLLM_PROFESSIONAL_PROMPTS.md
- PR_SUMMARY.md
- PR_SUMMARY_WEBLLM_PROFESSIONAL_PROMPTS.md
- EMBEDDED_INTEGRATION_COMPLETE.md
- OPENAI_COMPATIBILITY_COMPLETE.md
- RAG_IMPLEMENTATION_COMPLETE.md
- SOLUTION_SUMMARY.md

**Moved from root to docs/fixes/**:
- FIX_SUMMARY_EMBEDDED_CHAT.md
- FIX_SUMMARY_EMBEDDED_PROVIDER_INIT.md
- FIX_SUMMARY_OPENAI_COMPATIBILITY.md
- QUICK_FIX_EMBEDDED_CONTEXT.md

**Moved from root to docs/security/**:
- SECURITY_AUTHENTICATION_COORDINATION.md
- SECURITY_REPORT.md

**Moved from root to docs/deployment/**:
- PRODUCTION_SETUP.md

**Moved from root to docs/proposals/**:
- FUTURE_SERVICE_WORKER_SUPPORT.md

**New Consolidated Document**:
- docs/proposals/WEBLLM-IMPLEMENTATION-STATUS.md - Complete WebLLM Phase 1 status and roadmap

**Result**: 
- Root directory now contains only 9 essential documentation files
- Related documentation properly grouped
- WebLLM proposal status updated to reflect Phase 1 completion
- No information lost
- Easier to maintain and navigate

### January 22, 2026 - Menu Documentation Consolidation

**Consolidated**: 6 menu-related files into single comprehensive guide

**Removed from root**:
- MENU_FIX_SUMMARY.md
- MENU_REORGANIZATION_SUMMARY.md
- MENU_STRUCTURE_VISUAL.md
- REMOTE_SITES_MENU_FIX.md
- REMOTE_SITES_MENU_FIX_VISUAL.md
- PR_SUMMARY.md (temporary)

**New location**: `docs/fixes/menu-fixes/MENU_FIXES_CONSOLIDATED.md`

**Moved feature doc**: `TOOLKIT_MEMORY_TRACKING.md` → `docs/features/`

**Result**: 
- Cleaner root directory
- Related documentation grouped together
- No information lost
- Easier to maintain

### January 18, 2026 - Root Directory Cleanup

Moved 14 MD files from root to proper subdirectories:
- Implementation summaries → `docs/implementation-history/`
- Fix documentation → `docs/fixes/`
- Migration reports → `docs/migrations/`

### January 13, 2026 - Major Organization

- Gmail OAuth files consolidated
- FlowHub fixes moved to `docs/fixes/`
- 9 migration files organized

### January 8, 2026 - Week 2 Organization

- 19 temporary fix documents consolidated
- Gap analysis documentation organized
- Code review files moved to proper locations

## Finding Documentation

### By Topic

1. **Getting Started** → `README.md`, `docs/QUICK_REFERENCE.md`
2. **Installation** → `README.md`, `docs/deployment/`
3. **Features** → `docs/features/`, `README.md`
4. **API** → `docs/api/`, `README.md`
5. **Development** → `CONTRIBUTING.md`, `BUILD.md`, `docs/development/`
6. **Troubleshooting** → `docs/troubleshooting/`, `docs/fixes/`
7. **Architecture** → `docs/architecture/`
8. **Security** → `SECURITY.md`, `docs/security/`

### By File Type

1. **Guides** → `docs/guides/`
2. **Reference** → `docs/reference/`
3. **API Docs** → `docs/api/`
4. **Fix Documentation** → `docs/fixes/`
5. **Proposals** → `docs/proposals/`

### Using the Index

The most comprehensive way to find documentation:

**docs/DOCUMENTATION_INDEX.md** - 650+ files indexed by:
- Topic
- Date
- Component
- Feature

## Documentation Standards

### File Naming

- **Root files**: UPPERCASE.md (e.g., README.md, CHANGELOG.md)
- **Docs directory**: lowercase-with-dashes.md or UPPERCASE_WITH_UNDERSCORES.md
- **Dates**: YYYY-MM-DD format in filenames when relevant

### Structure

All documentation files should include:
1. Clear title/heading
2. Table of contents (for longer docs)
3. Overview/introduction
4. Main content with proper headings
5. Code examples where appropriate
6. Links to related documentation
7. Last updated date

### Cross-References

- Use relative paths for internal links
- Include context when linking (not just "click here")
- Keep links up to date when moving files

## Maintaining Documentation

### When Adding New Documentation

1. Place in appropriate `docs/` subdirectory
2. Update `docs/DOCUMENTATION_INDEX.md`
3. Add cross-references from related docs
4. Update `CHANGELOG.md` if significant
5. Keep root directory clean

### When Moving Documentation

1. Update all cross-references
2. Update `docs/DOCUMENTATION_INDEX.md`
3. Add redirect note in old location if necessary
4. Update `CHANGELOG.md`

### When Removing Documentation

1. Only remove truly obsolete documentation
2. Consider archiving instead of deleting
3. Update all references
4. Document removal in `CHANGELOG.md`

### Documentation Quality

### Metrics (as of January 29, 2026)

- **Total Files**: 650+
- **Root Files**: 6 essential + 2 supporting
- **Documentation Grade**: A (96/100)
- **Feature Coverage**: 100%
- **Organization Quality**: Excellent

### Recent Improvements

1. ✅ Root directory decluttered (Jan 2026)
2. ✅ Menu documentation consolidated (Jan 22, 2026)
3. ✅ Feature docs properly organized (Jan 22, 2026)
4. ✅ Fix documentation grouped by topic (Jan 2026)
5. ✅ Implementation history archived (Jan 2026)
6. ✅ 17 files moved from root to organized subdirectories (Jan 26, 2026)
7. ✅ WebLLM proposal status updated (Jan 26, 2026)
8. ✅ 22 files consolidated from root to docs/ subdirectories (Jan 29, 2026)

## See Also

- [Documentation Index](DOCUMENTATION_INDEX.md) - Complete file listing
- [Quick Reference](QUICK_REFERENCE.md) - Fast reference guide
- [Documentation Review](DOCUMENTATION_REVIEW_SUMMARY.md) - Quality assessment
- [README](../README.md) - Project overview
- [Documentation Directory README](README.md) - Quick navigation

---

**Last Updated**: January 29, 2026  
**Maintained By**: NV Digital Solutions
