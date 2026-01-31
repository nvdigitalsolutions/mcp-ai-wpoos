# Repository Organization

**Last Updated:** January 31, 2026  
**Organization Date:** January 31, 2026

This document describes the organization of the NV oOS repository structure and where different types of files are located.

---

## 📁 Root Directory Structure

The root directory contains only essential documentation and configuration files:

### Essential Documentation (3 files)
1. **[README.md](../README.md)** - Main plugin documentation, features, installation, usage
2. **[CHANGELOG.md](../CHANGELOG.md)** - Version history and changes
3. **[CONTRIBUTING.md](../CONTRIBUTING.md)** - Contribution guidelines

### Additional Essential Files (moved to docs/)
4. **[SECURITY.md](security/SECURITY.md)** - Security policy and vulnerability reporting (in docs/security/)
5. **[LICENSE](../LICENSE)** - GPLv3 license (in root)
6. **[BUILD.md](BUILD.md)** - Build and deployment instructions (in docs/)
7. **[DEPENDENCIES_BUNDLING.md](DEPENDENCIES_BUNDLING.md)** - NPM dependency management guide (in docs/)
8. **[CODEOWNERS](../CODEOWNERS)** - Code ownership and review assignments (in root)

### Configuration Files
- **package.json** / **package-lock.json** - NPM dependencies for base plugin
- **composer.json** / **composer.lock** - PHP dependencies
- **phpunit.xml.dist** - PHPUnit test configuration
- **phpcs.xml.dist** - PHP CodeSniffer configuration
- **jest.config.js** - JavaScript test configuration
- **tsconfig.json** - TypeScript configuration
- **babel.config.js** - Babel transpiler configuration
- **esbuild.config.js** / **esbuild.config.pro.js** - Build configurations
- **.eslintrc.json** / **.eslintignore** - ESLint configuration
- **.editorconfig** - Editor configuration
- **.nvmrc** - Node version specification
- **docker-compose.yml** - Local development Docker setup
- **tool-status.txt** - Tool status labels for Tools Manager

### Hidden Configuration
- **.gitignore** - Git ignore rules
- **.distignore** - Distribution ignore rules (WordPress.org)
- **.codecov.yml** - Code coverage configuration
- **.git-branch-info** - Git branch information
- **.github/** - GitHub Actions workflows, issue templates
- **.vscode/** - VS Code workspace settings
- **.wordpress-org/** - WordPress.org assets (banners, icons, screenshots)
- **.devcontainer/** - VS Code dev container configuration
- **.codex/** - Codex development environment

---

## 📂 Directory Structure

### `/docs/` - Documentation

The central location for all plugin documentation:

#### `/docs/proposals/` - Proposals & Planning
- **64 proposal documents** for features, enhancements, research
- **Status tracking:** [PROPOSALS_COMPLETION_STATUS.md](proposals/PROPOSALS_COMPLETION_STATUS.md)
- Categories: DeepSeek V4, WebLLM, Toolkit Enhancement, WordPress Integration, Bitwarden, Firefly III, Ralph Wiggum, etc.

#### `/docs/implementation-history/` - Implementation Records
- **2026/** - Current year implementation summaries
  - **january/** - January 2026 implementations (7 files moved from root)
    - `IMPLEMENTATION_COMPLETE.md` - Toolkit Enhancement System
    - `IMPLEMENTATION_SUMMARY.md` - Regulatory Registration Toolkit
    - `PR_SUMMARY.md` - PR completion summaries
    - `ORCHESTRATION_MODES_ENHANCEMENT_SUMMARY.md` - Teams dashboard
    - `REGULATORY_TOOLKIT_ENHANCEMENT.md` - Settings page enhancement
    - `TOOLKIT_ENHANCEMENT_README.md` - Toolkit overview
    - `VISUAL_GUIDE.md` - UI guides
  - **migrations/** - Database/data migration records
  - **settings/** - Settings system changes
- **2025/** - Previous year implementation records

### `/docs/deployment/` - Deployment Documentation
- **PRODUCTION_READY.md** - Production deployment guide
- **PRODUCTION_COMPOSER.md** - Composer production dependencies management
- **PRODUCTION_COMPOSER_SETUP.md** - Setup instructions
- Deployment procedures, optimization, and production configuration

#### `/docs/security/` - Security Documentation
- Security findings, vulnerability reports, security procedures
- **CODE_REVIEW_SECURITY_FINDINGS_2026-01-29.md** - Latest security audit

#### `/docs/compliance/` - Compliance Documentation
- ISO 27001, SOC 2, HIPAA compliance procedures (~90KB)
- 14 comprehensive procedures with control mappings

#### `/docs/architecture/` - Architecture Documentation
- System architecture, orchestration layer, design patterns
- **orchestration/** - Orchestration layer architecture docs

#### `/docs/fixes/` - Bug Fix Documentation
- Detailed documentation of bug fixes and patches
- Fix summaries, testing plans, implementation details
- **/federation/** - Federation directory and mesh computing fixes
  - `FEDERATION_DIRECTORY_DEBUG.md` - Federation directory checkbox persistence fix

#### `/docs/features/` - Feature Documentation
- Feature-specific implementation and usage documentation
- **/multi-agent/** - Multi-agent system documentation
  - `README-MULTI-AGENT-SYSTEM.md` - Multi-agent orchestration implementation summary
  - `MULTI_AGENT_DASHBOARD_PREVIEW.md` - Dashboard overview
  - `MULTI_AGENT_ORCHESTRATION_IMPLEMENTATION.md` - Technical implementation

#### Other `/docs/` Subdirectories:
- **api/** - API documentation and examples
- **guides/** - User guides and tutorials
  - **/admin/** - Administrative guides
    - `FEDERATION_SETUP_GUIDE.md` - Federation and mesh computing setup
- **tools/** - Tool documentation and reference
- **integrations/** - Third-party integration guides
- And 25+ other specialized documentation directories

### `/includes/` - Core Plugin Classes

WordPress plugin core functionality:

- **/admin/** - Admin UI and settings pages
- **/assistants/** - Assistant CPT and CCT management
- **/tools/** - 127+ built-in tool implementations (base version)
- **/elementor/** - Elementor widget integrations
- **/integrations/** - Third-party plugin integrations
- **/crawler/** - Crawl4AI integration
- **/orchestration/** - Multi-agent orchestration framework
- Core classes: REST API, authentication, job management, logging, etc.

### `/addons/` - Plugin Addons

Optional addon functionality:

#### `/addons/pro/` - Pro Addon
- **70 Pro tools** including social media, GitHub, Google services, exec-based tools
- **includes/src/Tools/** - Pro tool implementations
- **includes/admin/** - Pro admin pages
- **package.json** - Pro-specific NPM dependencies
- Toolkits: Media, Project Management, Site Creator, Regulatory Registration

### `/assets/` - Frontend Assets

Public-facing assets:

- **/js/** - JavaScript files
  - **chat.js** - Chat UI implementation
  - **vendor/** - Vendored libraries (Chart.js, vectorizer)
  - **admin-*.js** - Admin JavaScript
- **/css/** - Stylesheets
- **/images/** - Images and icons

### `/tests/` - Test Suite

Comprehensive test coverage:

- **/rest/** - REST API endpoint tests
- **/rest-api/** - REST API integration tests
- **/helpers/** - Helper function tests
- **/memory/** - Memory and caching tests
- **/crawler/** - Crawl4AI integration tests
- **/manual/** - Manual test scripts (e.g., `test-toolkit-registry.php`)
- PHPUnit test files for all components

### `/examples/` - Code Examples

Reference implementations and code snippets:

- **wpcode-snippet.php** - WPCode snippet example for plugin tracking endpoint
- Other example code and integrations

### `/bin/` - Development Scripts

Development and deployment automation:

- **setup-dev.sh** - Development environment setup
- **codex-startup.sh** - Codex environment startup
- Build scripts, packaging scripts, deployment helpers

### `/build/` - Build Output

Compiled and processed files (not tracked in git):

- Minified JavaScript bundles
- Compiled CSS
- Other build artifacts

### `/vendor/` - PHP Dependencies

Composer-installed PHP packages (not tracked in git):

- Symfony components
- Guzzle HTTP client
- OAuth2 libraries
- tiktoken-php
- And more (~28 production packages)

### `/node_modules/` - NPM Dependencies

NPM-installed JavaScript packages (not tracked in git):

- LangChain packages
- Chart.js
- Web-LLM
- And more

### `/languages/` - Translations

Plugin translation files:

- **.pot** - Translation template
- **.po/.mo** - Translation files for different languages

### `/core/` - Core Shared Code

Shared core functionality:

- Core utilities and base classes

### `/shared/` - Shared Resources

Shared resources across plugin components

### `/patches/` - Composer Patches

Composer patches for third-party packages:

- **patches.lock.json** - Patch lockfile

---

## 🔄 Recent Organization Changes (January 30, 2026)

### Files Moved from Root to Organized Locations

**Implementation/Summary Files → `docs/implementation-history/2026/january/`:**
1. `IMPLEMENTATION_COMPLETE.md` - Toolkit Enhancement System completion
2. `IMPLEMENTATION_SUMMARY.md` - Regulatory Registration Toolkit
3. `PR_SUMMARY.md` - PR completion summaries
4. `ORCHESTRATION_MODES_ENHANCEMENT_SUMMARY.md` - Teams dashboard enhancement
5. `REGULATORY_TOOLKIT_ENHANCEMENT.md` - Settings page enhancement
6. `TOOLKIT_ENHANCEMENT_README.md` - Toolkit enhancement overview
7. `VISUAL_GUIDE.md` - Admin menu and UI guides

**Test Files → `tests/manual/`:**
- `test-toolkit-registry.php` - Manual test script for toolkit registry

**Example Files → `examples/`:**
- `wpcode-snippet.php` - WPCode snippet reference implementation

**Duplicate Files Removed:**
- `PRODUCTION_READY.md` (already exists in `docs/deployment/`)

### New Documentation Created

**Proposals Status Tracking:**
- `docs/proposals/PROPOSALS_COMPLETION_STATUS.md` - Comprehensive status tracking for all 64 proposals

**Repository Organization:**
- `docs/REPOSITORY_ORGANIZATION.md` (this file) - Repository structure documentation

### Files Confirmed in Root

**Per stakeholder request:**
- `DEPENDENCIES_BUNDLING.md` - NPM dependency management guide

---

## 📋 File Organization Guidelines

### When to Add Files to Root

Files should only be in root if they are:

1. **Essential documentation** that every developer/user needs immediately (README, CHANGELOG, CONTRIBUTING, SECURITY, LICENSE)
2. **Build/deployment documentation** required for setup (BUILD.md, DEPENDENCIES_BUNDLING.md)
3. **Configuration files** required by tools (package.json, composer.json, phpunit.xml.dist, etc.)

### When to Add Files to `/docs/`

Files should be in `/docs/` if they are:

1. **Implementation summaries** - After completing features
2. **Proposals** - Feature proposals, research, planning
3. **Technical documentation** - Architecture, API, guides
4. **Compliance documentation** - ISO 27001, SOC 2, HIPAA
5. **Security reports** - Security findings, vulnerability reports
6. **Fix documentation** - Detailed bug fix documentation

### When to Add Files to `/tests/`

Files should be in `/tests/` if they are:

1. **PHPUnit test files** - Automated tests
2. **Manual test scripts** - Scripts for manual testing
3. **Test fixtures** - Test data and fixtures

### When to Add Files to `/examples/`

Files should be in `/examples/` if they are:

1. **Code examples** - Reference implementations
2. **Integration examples** - Third-party integration examples
3. **Snippet examples** - Reusable code snippets

---

## 🎯 Benefits of This Organization

### Improved Discoverability
- Essential docs immediately visible in root
- Related documentation grouped by category
- Clear separation of concerns

### Better Maintainability
- Implementation history preserved in organized structure
- Proposals tracked with status
- Easy to find historical context

### Cleaner Repository
- Root directory no longer cluttered
- Clear directory structure
- Professional appearance

### Easier Navigation
- Logical grouping of related files
- Consistent naming conventions
- Comprehensive status tracking

---

## 📚 Related Documentation

- **[README.md](../README.md)** - Main plugin documentation
- **[DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)** - Complete documentation map (659 files)
- **[DEPENDENCIES_BUNDLING.md](../DEPENDENCIES_BUNDLING.md)** - NPM dependency management
- **[proposals/PROPOSALS_COMPLETION_STATUS.md](proposals/PROPOSALS_COMPLETION_STATUS.md)** - Proposal status tracking
- **[BUILD.md](../BUILD.md)** - Build and deployment instructions

---

## 🔄 Maintenance

This document should be updated when:

- **Directory structure changes** - New directories added or reorganized
- **Major file moves** - Significant reorganization of files
- **New documentation categories** - New types of documentation added
- **Organization guidelines change** - Updates to file organization rules

**Last major reorganization:** January 30, 2026  
**Next review recommended:** February 2026 or after significant structural changes
