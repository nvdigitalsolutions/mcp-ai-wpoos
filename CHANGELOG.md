# oOS – Changelog

## [Unreleased]

### Added - February 2026
- **Package Pre-Bundling System (February 12, 2026)**: Enhanced vendor directory pre-bundling for critical npm packages
  - Added pdf-lib ^1.17.1 to vendor copy script for PDF manipulation capabilities
  - Added puppeteer-core ^21.0.0 to vendor copy script (optional) for advanced HTML rendering
  - Added core document generation packages: pdfkit, docx, exceljs, qrcode, turndown, cheerio
  - Updated package detection logic to check vendor directory before node_modules
  - Eliminates need for `npm install` on production servers, faster deployment
  - See [FEBRUARY_2026_UPDATES.md](docs/FEBRUARY_2026_UPDATES.md)

### Fixed - February 2026
- **Product Research Page Rendering (February 10, 2026)**: Fixed admin hook detection pattern causing CSS/JS not to load on Product Consolidate page
  - Changed from CPT pattern `product_page_*` to custom menu pattern `wp-mcp-ai-ecommerce-toolkit_page_*`
  - See [PRODUCT_RESEARCH_FIX_SUMMARY.md](PRODUCT_RESEARCH_FIX_SUMMARY.md)
- **Product Research Tab System (February 11, 2026)**: Fixed all workflow tabs displaying simultaneously
  - Changed hook matching to flexible strpos() check for reliability
  - Added inline display:none styles for defensive fallback
  - Enhanced CSS specificity with !important rules to prevent override
  - See [PRODUCT_RESEARCH_TAB_FIX_SUMMARY.md](PRODUCT_RESEARCH_TAB_FIX_SUMMARY.md)
- **Product Research CSS/JS Loading (February 11, 2026)**: Improved asset enqueuing priority and hook detection
- **Duplicate Menu Item (February 10, 2026)**: Removed duplicate "Research & Add" tab from E-commerce Toolkit settings page
- **Pro Workflow Builder Stability (February 4-5, 2026)**: Multiple fixes for React-based workflow builder
  - Fixed React asset loading and initialization issues
  - Fixed double instantiation causing duplicate DOM elements
  - Fixed initialization timing race conditions
  - Fixed menu placement inconsistencies
  - Fixed empty page display issue
  - See quick reference: `docs/fixes/pro-workflow-builder-fix-quick-reference-2026-02-05.md`
- **OAuth & API Connections (February 3, 2026)**:
  - Fixed Google OAuth approval prompt not displaying to users
  - Fixed Yahoo OAuth redirect URL construction issues
  - Fixed Mailjet API authentication credential handling
- **Admin Menu Priority (February 4, 2026)**: Adjusted menu priority values for consistent ordering across admin interface
- **E-commerce Toolkit (February 10, 2026)**: Now enabled by default for new installations to reduce setup friction

### Documentation - February 2026
- Added comprehensive February 2026 updates summary (`docs/FEBRUARY_2026_UPDATES.md`)
- Added detailed fix documentation for all product research page issues in `docs/fixes/`
- Added Pro Workflow Builder fix quick reference guide with visual flow diagrams
- Created root summaries: PRODUCT_RESEARCH_FIX_SUMMARY.md, PRODUCT_RESEARCH_TAB_FIX_SUMMARY.md

### Slash Commands & Workflow System
- **Slash Commands Implementation - Phase 1 Complete (February 3, 2026)**: Comprehensive slash command system for content management, optimization, and workflow automation
  - **Core Components**: Parser, Handler, Validator, Audit, Performance Optimizer, Workflow Orchestrator
  - **8 Implemented Commands**: `/help`, `/next-task`, `/ship`, `/clean-content`, `/optimize-perf`, `/sync-docs`, `/workflow`
  - **Features**: Command chaining, conditional logic, error handling, parameter validation, result passing between commands
  - **Workflow System**: Multi-step workflow execution with state management and human-in-the-loop checkpoints
  - **Integration**: JavaScript autocomplete, REST API endpoint (`/wp-json/mcp-ai/v1/slash-command`), WP-CLI support
  - **Security**: Capability-based authorization, rate limiting (10 commands/minute), comprehensive logging
  - **Test Coverage**: 45+ test cases covering all commands and workflow functionality
  - **Documentation**: Complete implementation guide with usage examples
  - See [SLASH_COMMANDS_GUIDE.md](docs/SLASH_COMMANDS_GUIDE.md)

- **Pro Toolkit Slash Commands - Phase 2 Complete (February 4, 2026)**: Specialized commands for pro toolkits with automated workflows
  - **21 Commands Implemented**: 
    - E-commerce (6): `/upsell-suggest`, `/abandoned-recover`, `/ecom-analytics`, `/discount-optimize`, `/inventory-forecast`, `/customer-segment`
    - Social Media (6): `/hashtag-suggest`, `/social-analytics`, `/social-schedule`, `/content-calendar`, `/competitor-track`
    - Video Production (6): `/video-subtitle`, `/video-template`, `/video-analytics`, `/video-merge`, `/video-thumbnail`, `/video-compress`
  - **7 Automated Workflows**:
    - Abandoned Cart Recovery Campaign (3 steps)
    - Multi-Platform Social Media Campaign (3 steps)
    - Video Marketing Production (3 steps)
    - E-Commerce Upsell Optimization (2 steps)
    - E-Commerce Inventory Management (3 steps)
    - Social Content Planning (3 steps)
    - Video Post Production (3 steps)
  - **Tool Integration**: Seamless integration with existing pro toolkit tools
  - **Requirements**: WooCommerce for e-commerce commands; appropriate user capabilities
  - **Test Coverage**: 50+ test methods across 4 test files
  - **Documentation**: Complete command reference with workflow examples
  - See [PRO_TOOLKIT_SLASH_COMMANDS.md](docs/PRO_TOOLKIT_SLASH_COMMANDS.md)

### Chat Channels & WebChat Integration
- **Chat Channels Toolkit - Production Ready (February 3, 2026)**: Comprehensive integration with 6 major chat platforms
  - **21 Tools Implemented**: 
    - Telegram (3 tools): `send_telegram_message`, `get_telegram_updates`, `manage_telegram_webhook`
    - WhatsApp (3 tools): `send_whatsapp_message`, `send_whatsapp_template`, `get_whatsapp_messages`
    - Slack (4 tools): `send_slack_message`, `get_slack_channels`, `get_slack_messages`, `create_slack_channel`
    - Discord (4 tools): `send_discord_message`, `get_discord_channels`, `get_discord_messages`, `create_discord_channel`
    - Microsoft Teams (3 tools): `send_teams_message`, `get_teams_channels`, `get_teams_messages`
    - Facebook Messenger (3 tools): `send_messenger_message`, `get_messenger_conversations`, `create_messenger_broadcast`
    - Unified Hub (1 tool): `unified_channel_broadcast` - Broadcast across multiple platforms simultaneously
  - **Admin Interface**: Comprehensive settings page at NV oOS → Chat Channels Toolkit with platform setup guides
  - **Authentication**: Secure API credential management with platform-specific configuration
  - **Testing**: PHP validation, PHPCS compliance, CodeQL security scan passed
  - **Documentation**: Complete implementation guides and troubleshooting
  - See [CHAT_CHANNELS_TOOLKIT.md](addons/pro/docs/CHAT_CHANNELS_TOOLKIT.md) and [CHAT_CHANNELS_README.md](addons/pro/docs/CHAT_CHANNELS_README.md)

- **WebChat Rooms - Production Ready (February 2026)**: Real-time collaborative chat rooms with AI assistant integration
  - **Custom Post Type**: `mcp_ai_webchat` for room management
  - **AI Assistant Assignment**: Dedicated metabox for assigning assistants to specific rooms
  - **Message Persistence**: JetEngine Custom Content Types integration for permanent message storage
  - **WebRTC Support**: Self-hosted WebRTC signaling via WordPress REST API
  - **3 Core Tools**: 
    - `create_webchat_room` - Create new WebChat rooms
    - `get_webchat_messages` - Retrieve room message history
    - `save_webchat_message` - Save messages to room history
    - `send_webchat_message` - Send messages to WebChat rooms
  - **Admin Interface**: WebChat settings page with room management and configuration
  - **Security**: Capability-based access control, nonce verification, proper sanitization
  - **Documentation**: Complete setup guide, troubleshooting, and assistant assignment docs
  - See [WEBCHAT_ASSISTANT_ASSIGNMENT.md](addons/pro/docs/WEBCHAT_ASSISTANT_ASSIGNMENT.md) and [WEBCHAT_TROUBLESHOOTING.md](addons/pro/docs/WEBCHAT_TROUBLESHOOTING.md)

### Toolkit Enhancements
- **Pro Toolkit Infrastructure - Phase 3 Complete (January 22, 2026)**: Comprehensive settings infrastructure for all pro toolkits
  - **13 Active Toolkits**: E-commerce (20 tools), Social Media (19 tools), Analytics (12 tools), Financial Planner (24 tools), Calendar Booking (15 tools), DJ Management (18 tools), Image Production (15 tools), Document Generation (3 tools), Multilingual (10 tools), Video Production (12 tools), AI Tool Builder (10 tools), Architectural Design (16 tools), CRM (1 tool)
  - **Total Pro Tools**: 175 tools across 13 specialized domains
  - **Settings Features**: Overview tabs, configuration tabs, provider setup, research & add capabilities, remote sites support
  - **Multi-Agent Support**: Each toolkit can have dedicated AI assistant; domain-specific specialization
  - **Memory-Based Tracking**: Replaced hard toolkit limits with transparent memory usage tracking
  - **Test Coverage**: Comprehensive test suite for all toolkit features
  - **Documentation**: Complete toolkit architecture and implementation guides
  - See [TOOLKIT_ENHANCEMENT_FINAL_SUMMARY.md](docs/TOOLKIT_ENHANCEMENT_FINAL_SUMMARY.md)

- **Toolkit Enhancement System - Complete (January 30, 2026)**: Advanced toolkit registry and pattern-based orchestration
  - **12 Toolkit Categories**: Comprehensive taxonomy system with metadata-driven tool discovery
  - **8 Multi-Agent Patterns**: Specialized patterns for research, content, e-commerce, development, customer service, analytics, creative, operations
  - **Pattern Workflow Templates**: 8 predefined workflow templates with customization support
  - **12 Core Classes**: ~10,000 LOC implementing registry, patterns, workflows, and integration layer
  - **Test Coverage**: 79 tests across 5 test files (100% passing)
  - **Documentation**: 150KB+ including technical specs, executive summaries, and visual guides
  - See [TOOLKIT_ARCHITECTURE_BEFORE_AFTER.md](docs/TOOLKIT_ARCHITECTURE_BEFORE_AFTER.md)

### Repository Organization
- **Repository Root Cleanup (February 2, 2026)**: Archived historical status files and reorganized structure
  - **Archive Created**: New `archive/` directory with three subdirectories for historical documentation
    - `archive/development-phases/` - Phase completion files (PHASE_2-4_COMPLETE.md, WPCS_RESTORATION_PLAN.md)
    - `archive/production-status/` - Production readiness files (PRODUCTION_READY.md, etc.)
    - `archive/wordpress-org-submission/` - Submission verification files
  - **Tool Status Moved**: `tool-status.txt` relocated from root to `docs/tool-status.txt`
    - Updated 2 PHP files to reference new location (class-wp-mcp-ai-section-tools.php, class-wp-mcp-ai-tool-registry.php)
    - Updated 5 documentation files with corrected paths
  - **Result**: Root directory now contains only 4 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, readme.txt)
  - **Benefits**: Cleaner repository presentation; historical context preserved; improved maintainability

### Security
- **Security Hardening (January 29, 2026)**: Fixed 4 critical and high severity vulnerabilities
  - **SSRF in Webhook Registration (Critical)**: Fixed webhook URL validation to block private IP ranges, AWS metadata endpoints, and restrict to http/https protocols only
    - File: `includes/class-wp-mcp-ai-job-notifier.php`
    - Impact: Prevents Server-Side Request Forgery attacks on internal networks and cloud metadata services
    - Added protocol validation, IP range filtering (RFC 1918, loopback, link-local), and WordPress `wp_http_validate_url()` checks
  - **Broken CSRF Protection (Critical)**: Fixed AJAX refresh rendering non-functional delete links in Cron Manager
    - File: `assets/js/admin-cron-manager.js`
    - Impact: Restores delete functionality with proper nonce-based CSRF protection after page refresh
    - Solution: Render complete HTML form with hidden nonce field instead of broken link
  - **XSS in Error Messages (High)**: Fixed unescaped error messages in admin AJAX handlers
    - Files: `assets/js/admin-cron-manager.js`, `assets/js/admin-crawl4ai-monitor.js`
    - Impact: Eliminates XSS attack vector in admin error message display
    - All error messages now escaped via `escapeHtml()` before DOM insertion
  - **Missing Authorization (High)**: Implemented comprehensive job authorization across multiple entity types
    - File: `includes/class-wp-mcp-ai-job-notifier-rest.php`
    - Impact: Prevents users from accessing other users' job data via ID enumeration
    - Added `is_user_authorized_for_job()` with 7 authorization paths (admin, user, assistant, team, profession, agent, virtual agent)
  - **Security Report**: Complete vulnerability analysis and remediation guide in `docs/security/CODE_REVIEW_SECURITY_FINDINGS_2026-01-29.md`
  - **Files Changed**: 4 files modified, ~400 lines of security hardening code added
  - **Result**: 100% of critical/high vulnerabilities resolved; 2 medium severity issues remain (CORS policy, rate limiting)

### Added
- **Comprehensive Entity Tracking (January 29, 2026)**: Added tracking for 11 entity types in job metadata
  - **New Helper Method**: `ensure_tracking_ids()` automatically captures all relevant context IDs
  - **Tracked Entities**: user_id, assistant_id, team_id, profession_id, agent_id, agent_role, virtual_agent_id, virtual_id, workflow_id, parent_job_id, profession_slug
  - **Applied To**: All job event handlers (started, progress, completed, failed)
  - **Benefits**: Complete audit trail for multi-agent workflows; essential for debugging complex orchestrations; supports team collaboration and multi-tenant scenarios
  - **Files**: `includes/class-wp-mcp-ai-job-notifier.php` (tracking logic), `includes/class-wp-mcp-ai-job-notifier-rest.php` (authorization)

- **Multi-Level Job Authorization (January 29, 2026)**: Implemented flexible authorization system for job access
  - **Authorization Paths**: 7 different ways to authorize job access
    1. Admin capability (`manage_options`)
    2. Direct user ownership (`user_id` match)
    3. Assistant ownership (user owns the assistant)
    4. Team membership (user is team owner or member)
    5. Profession ownership (user owns the profession)
    6. Agent ownership (user owns the agent)
    7. Virtual agent (user is member of parent team)
  - **Enforced In**: `handle_job_status()` and `handle_job_stream()` REST API endpoints
  - **Benefits**: Proper multi-tenant isolation; team collaboration support; flexible access control for complex workflows

- **DeepSeek V4 Agent Memory Tools (January 29, 2026)**: Phase 4/5 state management and memory enhancements
  - **store_agent_context Tool**: Stores important context, learnings, or information for agents to remember across sessions
    - Supports 10 context types: learning, fact, preference, pattern, workflow, decision, result, insight, note, generic
    - Configurable TTL (1 hour to 1 year, default 30 days)
    - Importance levels: low, medium, high, critical
    - Tag-based categorization for easy retrieval
    - Uses WordPress transients for storage with automatic index maintenance
  - **retrieve_agent_memory Tool**: Retrieves previously stored agent context with advanced search
    - Specific context ID retrieval for exact lookup
    - Semantic search with query matching and relevance scoring
    - Advanced filtering: context types, tags, importance levels, date ranges
    - Results ranked by importance and relevance (0-1 scale)
    - Configurable result limits (1-50 contexts)
    - Optional inclusion of expired contexts
  - **Integration**: Added to `agentic_workflow`, `general_purpose`, and `operations_management` tool presets
  - **Test Coverage**: Comprehensive PHPUnit test suite with 12 test methods validating storage, retrieval, search, filtering, and expiration
  - **Documentation**: Complete tool documentation in DEEPSEEK-V4-README.md and DEEPSEEK-V4-USAGE-GUIDE.md
  - These tools complete the DeepSeek V4 Phase 4/5 implementation for persistent agent memory and state management

### Documentation
- **Root Directory Documentation Consolidation (January 29, 2026)**: Major cleanup of root directory documentation
  - **Implementation Summaries**: Moved 19 implementation summary files to `docs/implementation-history/2026/`
    - Admin pages enhancement summaries (3 files)
    - AJAX test implementation summary
    - Chat job status SSE implementation
    - DeepSeek V4 completion summary
    - Site Creator implementation summaries (9 files)
    - System status summaries (2 files)
    - Task summary
    - Generic enhancement and implementation summaries
  - **Deployment Documentation**: Moved 2 deployment guides to `docs/deployment/`
    - PRODUCTION_DEPLOYMENT.md
    - PRODUCTION_READY.md
  - **Security Documentation**: Moved 1 security report to `docs/security/`
    - SECURITY_COMPLIANCE_REPORT.md
  - **Root Directory**: Now contains only 6 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md, DEPENDENCIES_BUNDLING.md) plus 2 supporting files (readme.txt, tool-status.txt)
  - **Updated**: DOCUMENTATION.md to reflect new organization structure
  - **Result**: Cleaner root directory; implementation history properly organized by year; no information lost; improved maintainability

- **Documentation Consolidation (January 22, 2026)**: Organized and consolidated root-level documentation
  - **Menu Fixes**: Consolidated 6 menu-related documents into single comprehensive guide at `docs/fixes/menu-fixes/MENU_FIXES_CONSOLIDATED.md`
    - Removed: `MENU_FIX_SUMMARY.md`, `MENU_REORGANIZATION_SUMMARY.md`, `MENU_STRUCTURE_VISUAL.md`, `REMOTE_SITES_MENU_FIX.md`, `REMOTE_SITES_MENU_FIX_VISUAL.md`, `PR_SUMMARY.md` (temporary)
    - Consolidated: All menu structure fixes, Remote Sites reorganization, visual diagrams, and testing guidelines
  - **Feature Documentation**: Moved `TOOLKIT_MEMORY_TRACKING.md` to `docs/features/` for better organization
  - **Result**: Cleaner root directory; all related documentation in appropriate docs/ subdirectories; no information lost

### Changed
- **Pro Toolkit Memory-Based Tracking System (January 22, 2026)**: Replaced hard toolkit count limit with transparent memory-based tracking
  - **Previous**: Hard limit of 5 pro toolkits; checkboxes disabled when limit reached; artificial restriction
  - **New**: Memory-based tracking showing estimated MB usage; no hard limits; all toolkits can be enabled
  - **UI Changes**: "Pro Toolkit Memory Usage" heading; displays "X MB estimated memory usage (Y toolkits enabled)"; status badges (Low/Moderate/High Usage)
  - **Memory Requirements**: 20 toolkits mapped to memory usage (24 MB - 256 MB range); total 1,844 MB if all enabled
  - **Status Thresholds**: Low (<500MB), Moderate (500-799MB), High (≥800MB) - informational only, no enforcement
  - **JavaScript**: Real-time memory calculation; dynamic counter updates; no checkbox disabling
  - **Benefits**: Transparency for resource planning; flexibility without artificial limits; informed decision-making
  - **Files Changed**: `includes/admin/sections/class-wp-mcp-ai-section-tools.php` (152 lines changed), `tests/test-section-tools.php` (70 lines added)
  - **Documentation**: Complete implementation guide in [TOOLKIT_MEMORY_TRACKING.md](docs/features/TOOLKIT_MEMORY_TRACKING.md)

### Added
- **DeepSeek V4 Multi-Agent Orchestration Enhancement (January 2026)**: Comprehensive multi-agent coordination framework inspired by DeepSeek V4's orchestration patterns
  - **Agent Role System**: Four specialized roles (Planner, Executor, Critic, Specialist) with role-specific capabilities and workflows
  - **Agent Team Orchestrator**: Manages team composition, coordinated workflow execution, and performance tracking (921 lines)
  - **Agent Communication Service**: Structured message passing and result aggregation with 5 aggregation strategies (consensus, weighted, hierarchical, first, best)
  - **Agent Coordination Tools**: Three new MCP-compliant tools (`create_agent_team`, `delegate_to_agent`, `aggregate_agent_results`)
  - **Profession CPT Integration**: 8 new orchestration meta fields for agent roles, capabilities, task patterns, and performance metrics
  - **Team CPT Integration**: 3 new orchestration meta fields for execution modes (single/sequential/parallel/swarm), workflow templates (JSON), and aggregation strategies
  - **Orchestration Seeder**: Intelligent agent role assignment for 200+ professions with WP-CLI commands (`wp profession seed-orchestration`, `wp profession orchestration-stats`)
  - **Multi-Agent Workflows**: Predefined team templates for research, content, e-commerce, and development workflows
  - **Implementation Status**: 85-90% complete with comprehensive test suite (12 PHPUnit tests, 9 integration tests)
  - **Documentation**: Complete documentation suite (55.3KB across 6 files) including usage guide, validation results, and workflow examples
  - **PHP Workaround Extension**: Extends core orchestration layer's "persistent-behavior illusion" to enable distributed agent coordination in stateless PHP environment
  - See [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/architecture/orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md#-6-multi-agent-orchestration-deepseek-v4-inspired-enhancement) for complete technical documentation
  - See [DEEPSEEK-V4-README.md](docs/DEEPSEEK-V4-README.md) for documentation suite overview

### Fixed
- **Token Manager Save Issue (January 21, 2026)**: Fixed tool settings not persisting despite success messages
  - **Root Cause**: Triple-sanitization in AJAX handler causing data loss (array structure lost after multiple sanitization passes)
  - **Solution**: Removed redundant sanitization in save loops; single sanitization point in setter methods
  - **Impact**: All tool limits, multipliers, and model preferences now save correctly
  - **Files Changed**: `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (61 lines changed)
  - **Testing**: Manual testing verified all settings persist across page reloads
  - See [docs/fixes/token-manager-save-issue-fix-2026-01-21.md](docs/fixes/token-manager-save-issue-fix-2026-01-21.md)

- **Provider Keys Clearing on Tab Navigation (January 20, 2026)**: Fixed API keys being cleared when navigating between admin tabs
  - **Root Cause**: Double-sanitization via WordPress Settings API callback on `update_option()` clearing sensitive data
  - **Solution**: Removed `sanitize_callback` from `register_setting()`; manual sanitization only in save handler
  - **Impact**: Provider configurations persist across tab navigation; no data loss
  - **Files Changed**: `includes/admin/class-wp-mcp-ai-admin-settings.php`
  - See [docs/fixes/provider-keys-clearing-fix-2026-01-20.md](docs/fixes/provider-keys-clearing-fix-2026-01-20.md)

- **Unified Team Transcript Recording (January 18, 2026)**: Fixed transcripts failing to save for unified team chats and individual member chats
  - **Root Causes**: Missing pattern recognition for team member assistant IDs; endpoint validation only accepting integers
  - **Solution**: Updated `extract_profession_id()` to recognize both `profession_XXX` and `team_XXX_member_YYY` patterns; changed REST endpoint to accept string assistant IDs
  - **Impact**: Transcripts save correctly for all team chat types (unified_team_*, team_*_member_*)
  - **Files Changed**: `includes/class-wp-mcp-ai-transcript-manager.php`, REST endpoint registration
  - See [docs/fixes/unified-team-transcript-recording-fix-2026-01-18.md](docs/fixes/unified-team-transcript-recording-fix-2026-01-18.md)

- **Tool Preset Multiplier Application (January 18, 2026)**: Fixed broken "Apply Preset" button on Token Manager page (PR #2990)
  - **Root Cause**: `get_all_recommendations()` only queried tool registry which returned empty array during preset application
  - **Solution**: Modified method to iterate through `$tool_categories` static property first (200+ tools), then check registry for dynamic tools
  - **Impact**: Preset application now works correctly for Conservative, Balanced, Performance, and Aggressive presets
  - **Files Changed**: `includes/class-wp-mcp-ai-tool-recommendations.php` (refactored into 2 new private helper methods)
  - **Testing**: Comprehensive manual testing plan in `docs/fixes/TOOL_PRESET_MULTIPLIER_TESTING_PLAN.md`
  - **Documentation**: Complete fix details in `docs/fixes/TOOL_PRESET_MULTIPLIER_FIX.md`
  - Broke after PR #2984 which updated tool recommendations system
  - Zero security vulnerabilities introduced, maintains backward compatibility
  - Better code organization and maintainability

- **HuggingFace Model Max Completion Tokens (January 17, 2026)**: Fixed Qwen3-Coder model failing with "max_completion_tokens limited to 8192" error
  - **Root Cause**: Using old `max_tokens` parameter instead of OpenAI-compatible `max_completion_tokens`; Resource Manager could request up to 32,000 tokens
  - **Solution**: Updated `WP_MCP_AI_Huggingface_Client::build_payload()` to use `max_completion_tokens`; added model-specific limits in `WP_MCP_AI_Model_Config`
  - **Impact**: Qwen models now work correctly with proper token limits enforced
  - **Files Changed**: `includes/class-wp-mcp-ai-huggingface-client.php`, `includes/class-wp-mcp-ai-model-config.php` (added 4 Qwen models with limits)
  - **Tests**: 5 test cases added to verify the fix
  - See [docs/fixes/huggingface-max-completion-tokens-fix-2026-01-17.md](docs/fixes/huggingface-max-completion-tokens-fix-2026-01-17.md)

- **OAuth Redirect URI Mismatch (January 17, 2026)**: Fixed Gmail OAuth failing with `redirect_uri_mismatch` error
  - **Root Cause**: Inconsistent URL construction in OAuth flow (direct query string concatenation vs. WordPress URL helpers)
  - **Solution**: Standardized redirect URI generation using WordPress's `add_query_arg()` instead of direct concatenation
  - **Impact**: OAuth flows now consistent across all WordPress installations (subdirectory, subdomain, custom ports)
  - **Files Changed**: `includes/integrations/class-wp-mcp-ai-oauth-manager.php`, `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`
  - See [docs/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md](docs/fixes/oauth-redirect-uri-mismatch-fix-2026-01-17.md)

- **Model Dropdown in Base + Pro Mode (January 16, 2026)**: Fixed model dropdown failing when both base and pro plugins active
  - **Root Cause**: Script localization lost when multiple metaboxes enqueued same script (two separate plugin instances)
  - **Solution**: Created `WP_MCP_AI_Admin_Scripts` class for global script registration with consistent localization (priority 5 on `admin_enqueue_scripts`)
  - **Impact**: Model dropdown works in all deployment modes (cloned repo, base+pro separate plugins, base only)
  - **Files Changed**: NEW `includes/admin/class-wp-mcp-ai-admin-scripts.php` (91 lines), updated 3 metabox files
  - **Code Improvement**: Simplified from 54 to 17 lines net reduction through centralization
  - See [docs/fixes/model-dropdown-base-pro-mode-fix-2026-01-16.md](docs/fixes/model-dropdown-base-pro-mode-fix-2026-01-16.md)

- **Audio Transcription MIME Type (January 11, 2026)**: Fixed transcription button creating video files instead of audio files
  - Added `getSupportedAudioMimeType()` helper function to check browser support
  - MediaRecorder now explicitly requests audio-only MIME types (audio/webm, audio/ogg, etc.)
  - Prefers audio formats over video container formats to avoid confusion
  - Maintains backward compatibility with fallback to video/webm if needed
  - Affects both transcribe and voice chat recording features
  - OpenAI Whisper API accepts both audio and video files with audio tracks

### Added
- **Pro Toolkit Infrastructure - Phase 3 Complete (January 15-22, 2026)**: Implemented comprehensive settings infrastructure for all 13 Pro toolkits
  - **13 Active Toolkits**: E-commerce (20 tools), Social Media (19 tools - updated January 2026 with 4 new analytics tools), Analytics (12 tools), Multilingual (10 tools), Video Production (12 tools), Financial Planner (24 tools), Document Generation (3 tools), Calendar Booking (15 tools), DJ Management (18 tools), Image Production (15 tools), AI Tool Builder (10 tools), Architectural Design (16 tools), CRM (1 tool)
  - **Total Pro Tools**: 175 tools across 13 specialized domains
  - **0 Planned Toolkits**: All previously "planned" toolkits (Calendar, DJ, Image Production, AI Tool Builder) are actually fully implemented
  - **Settings Features**: Overview tabs, configuration tabs, provider setup, research & add capabilities, remote sites support, WP-CLI integration
  - **Multi-Agent Functionality**: Each toolkit can have dedicated AI assistant; up to 5 concurrent agents (one per active toolkit)
  - **Specialization**: Domain-specific agents (product expert, content creator, translator, video editor, financial advisor, DJ, architect, etc.)
  - See [docs/implementation-history/2026/january/PHASE_3_IMPLEMENTATION_COMPLETE.md](docs/implementation-history/2026/january/PHASE_3_IMPLEMENTATION_COMPLETE.md)

- **Social Media Analytics Tools (January 15-22, 2026)**: Added 4 new analytics tools to Social Media Toolkit
  - **Get Cross-Platform Analytics** (`get_cross_platform_analytics`) - Unified metrics dashboard aggregating data from multiple platforms (623 lines)
  - **Track Hashtag Performance** (`track_hashtag_performance`) - Hashtag analysis with reach, engagement, and trend data (586 lines)
  - **Competitor Analysis** (`analyze_competitor_social`) - Track competitor metrics and compare performance (711 lines)
  - **Influencer Identification** (`identify_influencers`) - Find brand influencers based on reach and engagement criteria (759 lines)
  - All tools support Facebook, Instagram, Twitter, LinkedIn, and YouTube platforms
  - Built-in caching for performance (12-hour default)
  - Comprehensive error handling and validation
  - See Social Media Toolkit settings page for configuration

- **Cloudflare Image Generation Models (January 11, 2026)**: Added support for new Cloudflare Workers AI image generation models (PR #2785)
  - **Flux-2 Dev** (`@cf/black-forest-labs/flux-2-dev`) - Advanced image generation model
  - **Leonardo AI Models**: Lucid Origin (`@cf/leonardo/lucid-origin`) and Phoenix 1.0 (`@cf/leonardo/phoenix-1.0`)
  - All models support configurable dimensions (256-2048px), diffusion steps (1-20), and guidance parameters
  - Compatible with existing `cloudflareai_text_to_image` tool
  - Join Stable Diffusion XL Base/Lightning, Flux-1 Schnell, and Dreamshaper 8 LCM models
  - See [Cloudflare Image Generation Tool](includes/tools/class-wp-mcp-ai-tool-generate-cloudflareai-image.php)

- **ISO 27001/SOC 2/HIPAA Compliance - January 6, 2026**: Achieved 100% ISO 27001:2022 compliance (PR #2645, #2631, #2630)
  - ISO 27001: 100% (83 of 83 applicable controls) - up from 56%
  - SOC 2: 100% (54 of 54 Trust Services Criteria)
  - HIPAA: 98% (42 of 43 Security Rule safeguards)
  - ~90KB documentation across 14 comprehensive procedures
  - Dynamic compliance dashboard calculations
  - Complete control mappings for all three frameworks
  - See [Weekly Summary](docs/implementation-history/2026/WEEKLY_SUMMARY_2026-01-06.md)

- **Pro CPT Documentation - January 6, 2026**: Created comprehensive documentation for Pro custom post types
  - Events, Quizzes, and Places CPT overview (21 tools total)
  - Events: 5 tools including Google Calendar integration
  - Quizzes: 9 tools with JetEngine CCT integration
  - Places: 7 tools with Google Places API integration
  - See [PRO_CPT_OVERVIEW.md](docs/features/pro-cpt/PRO_CPT_OVERVIEW.md)

### Documentation
- **Code Review Documentation (January 18, 2026)**: Comprehensive review of January 11-18 changes
  - Reviewed 5 major changes: Token Manager fix, Provider Keys fix, OAuth fix, HuggingFace fix, Model Dropdown fix
  - All changes passed security and quality checks
  - Status: Production ready
  - See [docs/implementation-history/2026/CODE_REVIEW_DOCUMENTATION_UPDATE_2026-01-18.md](docs/implementation-history/2026/CODE_REVIEW_DOCUMENTATION_UPDATE_2026-01-18.md)

- **Root Directory Reorganization (January 13, 2026)**: Cleaned up root directory by moving documentation files
  - Moved 20+ markdown files to organized subdirectories
  - Root now contains only 5 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md)
  - Files organized into: `docs/fixes/`, `docs/implementation-history/2026/`, `docs/implementation-summaries/`
  - Zero information loss during reorganization
  - See [docs/implementation-history/2026/ROOT_DIRECTORY_ORGANIZATION_2026-01-13.md](docs/implementation-history/2026/ROOT_DIRECTORY_ORGANIZATION_2026-01-13.md)

- **Fix Documentation (January 15-21, 2026)**: Created comprehensive documentation for all recent fixes
  - 6 detailed fix documentation files created
  - Each includes root cause analysis, solution details, testing verification
  - Total documentation: ~12KB across fix documentation files
  - All fixes cross-referenced in CHANGELOG.md

### Changed
- **Pro Dashboard Modernization - January 6, 2026**: Refactored Pro Dashboard with industry-standard patterns (PR #2641)
  - Implemented Singleton pattern with lazy initialization
  - Added type-safe class constants for delegates
  - Centralized delegate management with configuration-driven approach
  - Enhanced error handling and observability
  - Public API for delegate access
  - 100% backward compatible
  - See [INDUSTRY_STANDARDS_ENHANCEMENTS.md](docs/implementation-summaries/INDUSTRY_STANDARDS_ENHANCEMENTS.md)

- **Text Domain Migration - January 6, 2026**: Complete migration from wp-mcp-ai to mcp-ai-wpoos (PR #2635)
  - Updated 12,773 instances across PHP and JavaScript
  - Separate text domains: mcp-ai-wpoos (main), mcp-ai-wpoos-pro, mcp-ai-wpoos-core, mcp-ai-wpoos-base
  - Zero references to old domain remain
  - Enables proper POT file generation

- **Documentation Organization - January 6, 2026**: Root directory cleanup (PR #2644)
  - Moved 25 markdown files to organized subdirectories
  - Root now contains only 5 essential files (83% reduction)
  - Files organized into: implementation-summaries/, fixes/, visual-guides/, troubleshooting/
  - Fixed 2 incorrect local file paths in plugin code
  - Zero broken links

- **Production Deployment - January 6, 2026**: Removed dev dependencies from vendor (PR #2638)
  - Executed `composer install --no-dev`
  - Repository ready for production cloning
  - Dev tools reinstallable via `composer install` when needed

### Fixed
- **Hugging Face Model Pricing - January 8, 2026**: Fixed $0 cost display for Hugging Face models
  - Added DeepSeek-V3.2 pricing ($0.28 input / $0.42 output per 1M tokens)
  - Added default fallback pricing for unknown models ($0.50 per 1M tokens)
  - Updated `get_model_pricing()` to support default pricing for Huggingface (similar to ollama/lm_studio)
  - Included pricing for 6 additional models: Llama 3.3 70B, Llama 3.1 8B, Mistral 7B, Phi-3 Mini, Qwen 2.5 72B, Qwen 2.5 7B
  - Pricing ranges from $0.10 to $1.00 per 1M tokens (input/output)
  - Added comprehensive test coverage for Hugging Face cost calculations
  - Resolves issue where DeepSeek-V3.2 and other unknown models showed no cost information
  - See `includes/class-wp-mcp-ai-cost-calculator.php` (lines 295-337)

- **PM Assistant Fixes - January 6, 2026**: Six critical modal and chat fixes (PRs #2629, #2632, #2633, #2636, #2637, #2626)
  - Modal Rendering: Added missing CSS for proper overlay display
  - Chat Localization: Ensured wpMcpAiChat global availability
  - Nested Form Fix: Changed form structure for WordPress compatibility
  - Validation Blocking: Always render modal HTML with error messages
  - Diagnostics: Added version tracking and debug logging
  - HTML5 Validation: Removed conflicting required attributes

- **WordPress 6.7+ Translation Compatibility - January 6, 2026**: Fixed translation loading timing (PRs #2640, #2639)
  - Moved 4 registration functions from `init` to `admin_init`
  - Removed translation functions from plugin action links
  - Eliminated WordPress 6.7+ timing warnings

- **Code Review - January 2, 2026**: Comprehensive code review of all features and tools
  - Overall grade: A- (92/100) - Production ready
  - Security: 10/10 - Zero vulnerabilities found
  - JavaScript: 10/10 - ESLint passes cleanly (0 errors)
  - PHP Code Style: 7.5/10 - 1,083 errors, 1,294 warnings (235 auto-fixable)
  - Architecture: 9.5/10 - Clean design patterns maintained
  - Documentation: 9.5/10 - 659 comprehensive files
  - Test Coverage: 8.5/10 - 565 test files
  - Tool inventory verified: 217 tool files (151 base + 66 Pro)
  - See [CODE_REVIEW_2026-01-02.md](docs/implementation-history/2025/code-reviews/CODE_REVIEW_2026-01-02.md)

### Changed
- **Root Directory Organization**: Cleaned up root directory by moving troubleshooting documentation files (January 10, 2026)
  - Moved `CLOUDFLARE-SYSTEM-PROMPT-TEST.md` from root to `docs/troubleshooting/common/`
  - Moved `MODEL-MANAGER-FIX-VERIFICATION.md` from root to `docs/troubleshooting/common/`
  - Root directory now contains only 5 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md)
  - Zero information loss during reorganization

- **Root Directory Organization**: Cleaned up root directory by moving fix and implementation summary files (PR #XXXX)
  - Moved 6 remote connection fix files from root to `docs/fixes/`
  - Moved 2 vectorizer implementation summaries from root to `docs/implementation-summaries/`
  - Root directory now contains only 7 essential files (README.md, CHANGELOG.md, CONTRIBUTING.md, SECURITY.md, BUILD.md, readme.txt, tool-status.txt)
  - Updated `docs/fixes/README.md` with sections for remote connection and vectorizer fixes
  - Added `docs/implementation-summaries/README.md` to document implementation summaries
  - Updated all cross-references to point to new file locations
  - Zero information loss during reorganization

### Fixed
- **Chart Tool Display**: Fixed 3x3 pixel canvas issue in `create_chart` tool
  - Chart.js responsive mode was causing canvas to shrink to 3x3 pixels during iframe initialization
  - Added `responsive: false` and `maintainAspectRatio: false` as default Chart.js options
  - Reduced default chart dimensions from 800x400 to 600x350 to better fit chat interface (typical chat width ~720px)
  - Users can still override these defaults by explicitly providing responsive options or custom dimensions
  - Added comprehensive tests to verify the fix
  - Updated `CHART_FIX_TESTING.md` with testing guide for the 3x3 pixel issue
  - See `includes/tools/class-wp-mcp-ai-tool-create-chart.php` (lines 250-252, 261-268)

### Changed
- **Plugin Rename**: Updated plugin name from "Open Operator System (NV oOS)" to "NV Digital Open Operator System (oOS)"
  - Updated all plugin headers in main files and core/pro versions
  - Updated README.md, readme.txt, and all documentation references
  - Updated build scripts to generate correct plugin names
  - No breaking changes: text domains, function prefixes, and slugs remain unchanged
  - This is purely a branding update with no functionality changes

## [1.1.0] - 2025-12-24

### Changed
- **Documentation Reorganization**: Completed comprehensive documentation restructuring (PR #2400)
  - Reorganized 40 files from root and docs/ directories into logical categories
  - Created clear category structure: archive/, features/, guides/, reference/, troubleshooting/
  - Maintained zero information loss during reorganization
  - Added `DOCUMENTATION_REORGANIZATION_SUMMARY.md` tracking document (now in `docs/implementation-history/2025/documentation/`)
  - Clean root directory maintained (6 essential MD files only)
  - Well-organized subdirectories with clear navigation via `docs/DOCUMENTATION_INDEX.md`
- **Tool Count Clarification**: Updated documentation to accurately reflect tool counts
  - 95 unique base tools (119 tool files including 24 validated variants)
  - 64 Pro tools (34 in src/Tools/ + 30 in tools/)
  - Total: 159 unique tools across base and Pro
  - Added clear note about validated variants being counted separately
- **Version Consistency**: Updated all documentation files to reflect current version 1.1.0
  - Updated `README.md`, `docs/README.md`, `docs/DOCUMENTATION_INDEX.md`
  - Ensured consistency across all version references

### Added
- **Code Review Documentation**: Added comprehensive code review for December 22-25, 2025
  - December 24: Complete analysis of recent changes and code quality
    - Security review (10/10 score - no vulnerabilities found)
    - Documentation quality assessment (9/10 score)
    - Architecture review and recommendations
    - See `docs/implementation-history/2025/code-reviews/CODE_REVIEW_2025-12-24.md`
  - December 25: Complete codebase analysis and comprehensive review
    - Full PHP linting with WordPress Coding Standards (470 files)
    - JavaScript linting with ESLint (52 files, all passed)
    - Security scan (10/10 - zero vulnerabilities found)
    - Architecture assessment (9.5/10)
    - Overall grade: A- (92/100) - Production Ready
    - See `docs/implementation-history/2025/code-reviews/COMPREHENSIVE_CODE_REVIEW_2025-12-25.md`
    - See `docs/implementation-history/2025/code-reviews/CODE_REVIEW_SUMMARY_2025-12-25.md`

### Fixed
- Version number inconsistencies across documentation files (1.0.0 → 1.1.0)
- Tool count discrepancies in README.md and other docs
- Last updated dates in documentation index files (now December 24, 2025)

#### Gemini Geospatial API Integration (December 22, 2025)
- **AI-Powered Location Queries**: Integrated Gemini Geospatial API for contextual, location-based queries with Google Maps grounding
  - **New Client Method**: Added `create_geospatial_query()` to `WP_MCP_AI_Gemini_Client`
    - Natural language queries about places, directions, and local information
    - Google Maps grounding with access to 250M+ places database
    - Optional location context (latitude/longitude) for better results
    - Returns `googleMapsWidgetContextToken` for frontend map visualization
  - **New Tool**: `gemini_geospatial_query` - Location-based AI queries for assistants
    - Ask about restaurants, attractions, routes, and area information
    - Supports multimodal responses with map context tokens
    - Configurable temperature and model selection
    - Proper capability checks and authentication
  - **Google Maps Integration**: Responses include context tokens for Google Maps JavaScript API
  - **Contextual View Component**: Enable interactive map visualizations in frontend
  - **Reduced Hallucinations**: Factual grounding with real-time Google Maps data
  - **Use Cases**: Location discovery, route planning, local recommendations, area exploration
  - **WordPress Integration**: User authentication, capability checks, multisite support
  - **Test Coverage**: 8 comprehensive test cases covering all functionality
  - **Comprehensive Documentation**: Complete usage guide with examples
  - See [Gemini Geospatial Documentation](docs/features/ai-providers/gemini/GEMINI_GEOSPATIAL.md)

#### OpenAI Batch API Integration (December 21, 2025)
- **Batch Processing for Cost Savings**: Integrated OpenAI Batch API for asynchronous bulk operations with 50% cost reduction
  - **New Client Methods**: Added 4 Batch API methods to `WP_MCP_AI_OpenAI_Client`
    - `create_batch()` - Create asynchronous batch processing jobs
    - `retrieve_batch()` - Get batch job status and results
    - `cancel_batch()` - Cancel running batch jobs
    - `list_batches()` - List and filter batch jobs with pagination
  - **New Tools**: 4 batch management tools for WordPress integration
    - `create_batch` - Create batch jobs via WordPress
    - `get_batch_status` - Monitor batch progress and completion
    - `list_batches` - List and manage batch jobs
    - `monitor_batch` - **NEW** Automatic batch monitoring with WordPress cron
      - Periodic status checking (hourly, twice daily, or daily)
      - Email notifications on completion/failure
      - Custom callback hooks for automation
      - Auto-download results option
      - Background processing via WordPress cron
  - **Supported Endpoints**: `/v1/chat/completions`, `/v1/embeddings`, `/v1/moderations`
  - **Cost Savings**: 50% reduced cost compared to synchronous API calls
  - **Higher Rate Limits**: Dedicated quota and much higher throughput
  - **24-Hour SLA**: Guaranteed completion within 24 hours
  - **Automated Monitoring**: Set-and-forget batch monitoring with cron integration
  - **Use Cases**: Bulk content generation, mass embeddings creation, large-scale moderation, dataset processing
  - **WordPress Integration**: Proper capability checks (requires `manage_options`)
  - **Comprehensive Results**: Status tracking, progress monitoring, output file IDs
  - **Test Coverage**: 15 comprehensive test cases covering all functionality
  - **Documentation**: Complete usage guide with examples
  - See [OpenAI Batch API Usage](docs/examples/openai-batch-api-usage.md)
  - See [OpenAI Batch API Documentation](https://platform.openai.com/docs/guides/batch)

#### OpenAI Moderation API Integration (December 21, 2025)
- **Content Safety & Compliance**: Integrated OpenAI Moderation API for automated content moderation
  - **New Tool**: `moderate_content` - Analyzes text and images for policy violations
  - **14 Violation Categories**: Checks for sexual content, hate speech, harassment, self-harm, violence, and illicit content
  - **Multimodal Support**: Works with both text and images via `omni-moderation-latest` model
  - **Batch Processing**: Can moderate multiple items in a single API call for efficiency
  - **Confidence Scores**: Returns probability scores (0-1) for each category
  - **Free API**: Moderation API is free to use with no token costs
  - **WordPress Integration**: Proper capability checks and error handling
  - **Comprehensive Results**: Includes formatted results, safety summaries, and actionable recommendations
  - **Client Method**: Added `moderate_content()` method to `WP_MCP_AI_OpenAI_Client`
  - **Test Coverage**: 9 comprehensive test cases covering all functionality
  - **Documentation**: Complete usage guide with WordPress integration examples
  - See [OpenAI Moderation API Usage](docs/examples/openai-moderation-api-usage.md)
  - See [OpenAI Moderation Documentation](https://platform.openai.com/docs/guides/moderation)

#### Gemini API Integration Gap Analysis (December 20, 2025, PR #2267)
- **Comprehensive Gemini Integration Review**: Complete gap analysis and documentation of Gemini API capabilities
  - **Analysis Documents**: 6 comprehensive documentation files created
    - `GEMINI_INTEGRATION_GAP_ANALYSIS.md` - Detailed 14-gap analysis across 5 categories
    - `GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md` - High-level overview with ROI analysis
    - `GEMINI_CAPABILITIES_MATRIX.md` - Feature comparison matrix
    - `GEMINI_INTEGRATION_ANALYSIS_INDEX.md` - Navigation guide
    - `GEMINI_OPENAI_TOOLS_ARCHITECTURE.md` - Tool architecture documentation
    - `GEMINI_TOOL_SANITIZATION_FIX.md` - Tool sanitization implementation
  - **Current State**: 15 of 30 major API endpoints implemented (50% coverage)
  - **Key Features Documented**: Chat, streaming, image generation/editing, video (Veo 3.1), music (Lyria), file API
  - **Enhancement Opportunities Identified**: Batch embeddings, context caching, thinking mode, masks, video analysis
  - **Cost Savings Potential**: Context caching can reduce costs by 68% for cached tokens
  - See [GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md](docs/features/ai-providers/gemini/GEMINI_INTEGRATION_EXECUTIVE_SUMMARY.md)

#### OpenAI GPT-Image-1.5 Model Support (December 20, 2025)
- **OpenAI GPT-Image-1.5 Image Generation**: Added support for the latest GPT-Image-1.5 model
  - **4× Faster**: Generation speed significantly improved compared to GPT-Image-1
  - **20% Cost Reduction**: New pricing structure with lower costs across all quality tiers
    - Low quality (1024×1024): $0.009 (was $0.011)
    - Medium quality (1024×1024): $0.034 (was $0.042)
    - High quality (1024×1024): $0.133 (was $0.167)
  - **Quality Parameters**: Supports low, medium, high, and auto quality settings
  - **Supported Sizes**: 1024×1024, 1024×1536, 1536×1024, and auto
  - **Default Model**: GPT-Image-1.5 is now the default image generation model
  - **Backward Compatible**: GPT-Image-1, DALL-E 3, and DALL-E 2 remain available
  - Updated cost estimation for accurate usage tracking
  - See [OpenAI Image Generation Documentation](https://platform.openai.com/docs/guides/image-generation)

#### GPT-5.2 Model Support (December 16, 2025)
- **OpenAI GPT-5.2 Model Family**: Added support for the latest GPT-5.2 models with 400K context window
  - **Base Model**: `gpt-5.2` - Standard flagship model ($0.00175 per 1K tokens)
  - **Pro Model**: `gpt-5.2-pro` - Advanced reasoning model with enhanced capabilities ($0.021 per 1K tokens)
  - **Instant Variant**: `gpt-5.2-instant` - High throughput optimized for volume work
  - **Thinking Variant**: `gpt-5.2-thinking` - Deeper analysis with reasoning time dial
  - **Dated Versions**: `gpt-5.2-2025-12-11` and `gpt-5.2-pro-2025-12-11` for version pinning
  - All models feature 400,000 token context window (2x larger than GPT-5.1)
  - Max output: 128,000 tokens per response
  - Knowledge cutoff: August 31, 2025
  - Properly configured rate limits (TPM, RPM, TPD, RPD)
  - Fallback chain configured for graceful degradation
  - Added comprehensive test coverage in `tests/test-model-config.php`
  - See [OpenAI GPT-5.2 Documentation](https://platform.openai.com/docs/models/gpt-5.2) and [GPT-5.2 Pro Documentation](https://platform.openai.com/docs/models/gpt-5.2-pro)

#### Symfony Process Component Integration (December 9, 2025, PR #2091)
- **Symfony Phase 2B Complete**: Migrated all Pro addon exec-based tools and services to Symfony Process component
  - **Process Service Created**: New `WP_MCP_AI_Process_Service` provides WordPress-friendly wrapper around Symfony Process【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】
  - **6 Pro Tools Migrated**: 
    - `check_jukebox_status` - Meta AI Jukebox status checking
    - `check_wp_cli` - WP-CLI environment inspection  
    - `extract_video_frames` - FFmpeg frame extraction
    - `generate_jukebox_music` - Meta AI Jukebox music generation
    - `get_video_metadata` - FFmpeg metadata extraction
    - `remove_background` - Python rembg background removal
  - **2 Services Migrated**:
    - `WP_MCP_AI_Jukebox_Service` - Jukebox execution service
    - `WP_MCP_AI_Video_Frame_Extractor_Service` - FFmpeg operations service
  - **Benefits**: Enhanced security, proper timeout handling, better error reporting, process control
  - Replaced 14 direct `exec()` calls across Pro addon
  - All migrated tools maintain backward compatibility
  - See `docs/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md` for migration details

#### Settings UI Enhancements (December 8, 2025, PR #2072)
- **27 New Settings Exposed in Admin UI**: Made previously hidden settings accessible with proper UI organization
  - **Media**: MIME type allowlists for file and image uploads
  - **OpenAI TTS**: Text-to-speech model, voice, and format configuration
  - **High Token Fallback**: Auto-switch to fallback model when token limits exceeded
  - **Tool Configuration**: Web search provider selection, group email controls, Varnish cache toggle
  - **Cloudways**: Application and server ID fields for Cloudways integration
  - **Google Analytics 4**: Service account JSON credentials field
  - **Federation & Mesh Networking**: New subtab with 9 settings for distributed computing
    - Federation directory participation toggle
    - Regional routing configuration (geographic regions, data tags)
    - Rate limiting controls (QPS, burst capacity)
    - Mesh peer site configuration
    - Auto-generated inbound API key for peer authentication
  - Fixed naming inconsistencies between default settings and UI fields
  - Removed duplicate integration settings from Tools section
  - See `docs/CODE_REVIEW_2025-12-08.md` for complete details

### Changed

#### Pro Tool Reorganization (December 8, 2025, PR #2073)
- **Moved 6 Exec Service Tools to Pro Addon**: Tools requiring external executables now properly designated as Pro-only
  - `check_wp_cli` - WP-CLI environment inspection
  - `extract_video_frames` - FFmpeg frame extraction
  - `get_video_metadata` - FFmpeg metadata reader
  - `remove_background` - Python rembg / remove.bg API background removal
  - `generate_jukebox_music` - OpenAI Jukebox audio generation
  - `check_jukebox_status` - Jukebox installation status checker
  - Added `'pro'` capability flag to all 6 tools
  - Registered tools in Pro addon instead of base plugin
  - Removed from base tool registry to prevent duplicate registration
  - **Note**: The Pro addon contains **38 total tools**, including these 6 exec-based tools plus 32 other Pro tools for social media, Google services, GitHub, WooCommerce, JetEngine, and more
  - **Breaking Change**: Base version users no longer have access to these 6 exec-based tools
  - Pro addon now required for exec-based media processing and WP-CLI tools
  - See `docs/CODE_REVIEW_2025-12-08.md` for impact analysis

### Documentation
- **Documentation Status Updates (December 20, 2025)**: Systematic review and update of documentation completion status
  - Updated 8 major documentation files with accurate completion status
  - Quality scores updated: 95/100 → 98/100 (reflects December 2025 improvements)
  - High-priority gaps: 5 → 1 remaining (4 completed: output escaping, CI/CD gates, test env, integration tests)
  - Created `docs/DOCUMENTATION_UPDATE_STATUS_2025-12-20.md` - Tracking document for systematic review of 549 documentation files
  - Updated `docs/GAP_ANALYSIS_EXECUTIVE_SUMMARY.md` - Marked completed work, updated metrics
  - Updated `docs/ACTION_ITEMS.md` - Security and JavaScript items marked complete
  - Updated `docs/QUICK_WINS_GAP_FIXES.md` - CI/CD and error documentation sections completed
  - Updated `docs/PLUGIN_GAP_ANALYSIS.md` - PHP and JavaScript sections marked resolved
  - Updated `docs/REMAINING_ISSUES.md` - Current code quality score 98/100, ~40 issues remaining (97.5% reduction)
  - **Tool Count Correction**: Updated README.md from 71 → 95 core tools (total 109 → 133 tools)
  - Documented completion: Output escaping (66 fixes), CI/CD gates, security scanning (CodeQL), error documentation
- **Code Review December 8, 2025**: Comprehensive review of recent commits with recommendations
  - Created `docs/CODE_REVIEW_2025-12-08.md` - Analysis of PR #2073 and PR #2072
  - Overall grade: A - Excellent code quality, thorough testing
  - Identified documentation updates needed for tool changes and new settings
- **Comprehensive Documentation Consolidation (December 7, 2025)**: Consolidated ALL bug reports, fixes, code reviews, and session summaries into master documents
  - Created `docs/CONSOLIDATED_SESSION_SUMMARIES.md` - All development sessions from December 2025, November 2025, and archived sessions
  - Updated `docs/CONSOLIDATED_BUGS_AND_FIXES.md` - Added output escaping work, site creator fix, December code review
  - Created `SESSION_SUMMARIES_ARCHIVE_NOTE.md` - Guide to consolidated documentation
  - Updated `docs/DOCUMENTATION_INDEX.md` - Added master documents section with ⭐ highlights
  - Updated `README.md` - Added new Documentation section with links to master documents
  - **Nothing lost**: All original session files preserved for reference, all content consolidated for better access
  - **Benefits**: Single source of truth, complete history, better organization, easier maintenance
  - See `CONSOLIDATION_COMPLETE_SUMMARY.md` for full details

### Fixed

#### Test Team Modal Feature Activation (December 8, 2025)
- **Test Team Page Initialization**: Activated the test team modal feature by adding missing initialization in main plugin file
  - Added `admin.test_team` service loading in `mcp-ai-wpoos.php` (lines 558-560)
  - Feature was 90% complete (all components built) but not initialized
  - Now accessible via **Teams → Test Team** admin menu
  - Allows testing AI teams with temporary assistants for each team member
  - All components verified: admin page, JavaScript, CSS, REST API, tests, documentation

#### Async Tool Execution & VEO Video Generation (November 26-27, 2025)
- **Async Tool Result Display Fix (PR #1739, #1755)**: Fixed async tool results (VEO video generation) not appearing in chat interface
  - Dynamically create assistant message when `tool_results` present but no LLM message exists
  - Fixed `handleChatResponse()` to process tool results even when no `choices` array is returned
  - Added `startAsyncToolPolling` helper function to reduce code duplication
  - See `docs/archive/fixes/ASYNC_TOOL_RESULT_FIX.md` for technical details

- **Async Tool ID Mismatch Fix (PR #1772)**: Fixed subsequent API failures after async video generation
  - Skip pending async tool results when adding to conversation (will be added on completion)
  - Pass original `tool_call_id` through entire async polling chain
  - Update `displayAsyncToolResult` to use provided `tool_call_id`
  - Prevents duplicate tool messages with mismatched IDs causing API errors

- **Unified Job ID Handling (PR #1758, #1760)**: Fixed metadata overwrites in unified async job flow
  - VEO service now merges metadata with existing async executor metadata instead of overwriting
  - Preserves critical fields (tool_slug, context, arguments) needed for permission checks
  - Async executor refreshes metadata from transient before updating to preserve veo-specific fields

- **Delegation Chain Propagation (PR #1759)**: Fixed delegated async job failures not propagating
  - Added `handle_delegation_chain()` method for proper delegation chain handling
  - Failed delegated jobs now propagate failure to parent job
  - Completed delegated jobs propagate result to parent
  - Added comprehensive tests for delegation chain scenarios

- **Async Tool Timeout Configuration (PR #1761, #1763)**: Added configurable async tool timeout
  - New settings under Orchestration → Async Tool Timeout
  - Constants for default (120s) and maximum (600s) timeout values
  - Extracted timeout logic to helper method for DRY compliance

#### SSE Streaming Improvements (November 26-27, 2025)
- **SSE Message Handling Fix (PR #1768)**: Fixed choices structure overwriting content in final messages
  - Check for final response (`data.data`) FIRST before extracting streaming chunks
  - Only extract streaming chunks if NOT a final response
  - Follows OpenAI SSE streaming best practices

- **SSE Stream Completion Fix (PR #1746)**: Fixed network errors when final data already captured
  - Handle network errors gracefully during stream completion
  - Fixed `ob_flush/flush` order in SSE handler for proper buffer flushing
  - Added `ob_flush` to `send_sse_done` for consistent behavior

- **SSE Cron-Status Authentication (PR #1774)**: Fixed 401 Unauthorized error in SSE cron-status endpoint
  - Added authentication query parameters for EventSource connections
  - Fixed guest token and nonce passing for SSE endpoints

- **WP_Error Normalization (PR #1736, #1737)**: Fixed SSE streaming failures with tool errors
  - Added recursive `normalize_data_recursive()` method for WP_Error objects
  - Applied normalization across SSE streams, job status, and cron status service
  - Prevents JSON encoding failures when tool results contain WP_Error objects

#### Chat UI & Status Updates (November 26-27, 2025)
- **Tool Completion Status Fix (PR #1750, #1752, #1753)**: Fixed chat status stuck on "Tool is processing"
  - Updated PHP localized strings from "Tool response ready" to "Tool completed successfully"
  - Added 'success' type to UI utilities with checkmark icon
  - Status now correctly shows completion for finished tools, processing for async pending

- **Duplicate Assistant Messages Fix (PR #1738)**: Fixed duplicate messages in chat streaming
  - Move streaming message removal BEFORE `handleChatResponse` to prevent duplicates
  - Added missing delete button to fallback path

- **Truncated Tool Results Fix (PR #1756)**: Fixed truncated results not showing in chat
  - Handle string results in `normaliseToolResultForDisplay()`
  - Properly filter async pending results from display

#### Job Notification System (November 26-27, 2025)
- **Job Event Bus Integration (PR #1771)**: Unified cron-status and chat async tool coordination
  - Added `job-event-bus.js` with mitt-compatible API (on, off, emit, all)
  - Update cron-status-service to emit job updates through event bus
  - Chat.js now listens for job completions via event bus
  - Prevents SSE connection conflicts between job bar and chat interface

- **Cron Job Status Bar Filtering (PR #1744)**: Fixed multi-widget isolation
  - Filter job status by assistant ID for proper multi-widget support

#### Security & Authentication (November 27, 2025)
- **Job Notifier Auth Support (PR #1762)**: Comprehensive authentication for job notifier REST endpoints
  - Added mesh key, local token, guest token, bearer token, and nonce authentication
  - Return 503 when authenticator unavailable instead of allowing unvalidated tokens
  - Explicit success check for bearer token validation

- **Path Traversal Prevention**: Fixed regex for `sanitize_job_id`
  - Changed `/\\.\\.+/` to `/\\.{2,}/` to correctly match path traversal patterns
  - Applied fix to both job notifier REST and tools controller

#### Message Bundling (November 27, 2025)
- **Form State During Bundling (PR #1765)**: Fixed form remaining enabled during message bundling delay
  - Disable form when first message queued, re-enable on cancel
  - Provides consistent UI feedback during 800ms bundling window
  - Added comprehensive tests for message bundling behavior

#### Development Environment (November 27, 2025)
- **Codex Startup Script (PR #1773)**: Fixed dev dependency installation
  - Ensure dev dependencies installed when vendor was installed with `--no-dev`

- **Multisite Context Fix (PR #1767)**: Fixed async tool execution in multisite
  - Interface files moved to proper location
  - Fixed multisite context preservation for async tools

#### Async Tool Logging & Observability (November 27, 2025)
- **Improved Sync/Async Logging (PR #1769)**: Enhanced tool execution observability
  - Fixed misleading `hasChoices` logging for final SSE messages
  - Added observability logging for async tool detection and polling initiation
  - Improved tool_call matching debug output
  - Added JSDoc documentation for sync vs async tool execution flow

- **WP_LANG_DIR Constant Warning (November 24, 2025)**
  - Fixed PHP warning: "Constant WP_LANG_DIR already defined" during plugin activation and performance tests
  - Applied Composer patch to wp-phpunit package to add guard check before defining WP_LANG_DIR
  - Warning occurred when WordPress core had already defined the constant before wp-phpunit bootstrap ran
  - Affected scenarios: plugin activation via WP-CLI, performance tests via admin UI
  - Patch automatically applied during `composer install` via cweagans/composer-patches
  - See `patches/README.md` for technical details
  
- **Chat Client Attachment Visibility (PR #1630, November 24, 2025)**
  - Fixed issue where file attachments (images, PDFs, etc.) were appearing in the chat UI but not being passed to OpenAI
  - Added `input_image` and `input_file` segment types to REST validator's processable types
  - Users can now attach files in chat-client and AI providers will properly receive them
  - Preserves agentic workflow - no manual re-uploading needed
  - Added 2 comprehensive unit tests (`test_sanitize_messages_processes_input_image_segments` and `test_sanitize_messages_processes_input_file_segments`)
  - Backward compatible with all existing segment types
  - See `docs/archive/fixes/CHAT_CLIENT_ATTACHMENT_FIX.md` for technical details

### Documentation
- **Documentation Organization (November 27, 2025)**: Continued documentation cleanup
  - Moved 13 additional fix documentation files from root to `docs/archive/fixes/`
  - Files moved: ASYNC_TOOL_RESULT_FIX.md, FILE_BASED_POLLING_IMPLEMENTATION.md, FIX_SUMMARY.md, IMAGE_ATTACHMENT_URL_FIX.md, IMAGE_EDIT_403_FIX.md, ISSUE_RESOLUTION_SUMMARY.md, PULL_REQUEST_SUMMARY.md, ROTATE_IMAGE_FIX.md, ROTATE_IMAGE_FIX_SUMMARY.md, VEO_FILENAME_FIX.md, VEO_NOTIFICATION_FLOW.md, VEO_TOOL_CALL_ID_AND_COST_FIX.md, VIDEO_EXTRACTION_FIX_SUMMARY.md
  - Root directory now contains only 5 essential files (README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, BUILD.md)

- **Documentation Organization (November 24, 2025)**: Completed initial documentation cleanup
  - Moved 62 non-essential markdown files from root to `docs/archive/` subdirectories
  - Root directory now contains only 5 essential files (README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, BUILD.md)
  - Organized files into logical categories:
    - `docs/archive/fixes/` - Bug fix summaries and technical details (31 files)
    - `docs/archive/features/` - Feature implementation documentation (14 files)
    - `docs/archive/implementations/` - Implementation summaries (5 files)
    - `docs/archive/phases/` - Phase documents (4 files)
    - `docs/archive/testing/` - Test guides and summaries (3 files)
    - `docs/archive/summaries/` - General summaries (1 file)
  - All documentation preserved (nothing deleted, only organized)
  - Easier navigation and discovery of relevant documentation
  - Completes the documentation reorganization initiated on November 18, 2025

### Added
- **Phase 2.1: File Management & Caching for Video Analysis (November 20, 2025)**
  - File caching to avoid re-uploading same videos (transient-based with 24-hour expiration)
  - File tracking in WordPress options for lifecycle management
  - Automated cleanup of old files via daily WordPress cron job (`wp_mcp_ai_cleanup_gemini_files`)
  - Cache key generation with file modification time detection for attachments
  - Support for both URL-based and attachment-based caching
  - Comprehensive test coverage (21 new unit tests in `tests/test-gemini-file-service-caching.php`)
  - All features follow proper Separation of Concerns (Service layer handles business logic)
  - Reduces API costs and improves performance by avoiding duplicate uploads
  - **Status**: Phase 2.1 Complete ✅ (see `docs/archive/VIDEO_ANALYSIS_ROADMAP.md`)

### Documentation
- **Documentation Reorganization (November 18, 2025)**: Comprehensive cleanup and consolidation of documentation
  - Consolidated bug reports into single comprehensive `docs/TESTING_AND_QUALITY_REPORT.md` (753 lines)
    - Merged BUG_REPORT.md and BUG_REPORT_SUMMARY.md
    - Includes test suite results (2,106 tests, 73.4% pass rate)
    - Code quality analysis (2,120 linting issues categorized)
    - Security audit findings and recommendations
    - Prioritized action items with time estimates
  - Reorganized 95+ documentation files into logical archive structure
    - Created `docs/archive/implementations/` for implementation summaries
    - Created `docs/archive/phases/` for phase documents
    - Created `docs/archive/fixes/` for fix summaries and issue resolutions
    - Created `docs/archive/features/` for feature documentation
    - Created `docs/archive/code-reviews/` for code review reports
    - Created `docs/archive/testing/` for test infrastructure docs
  - **Root directory now contains only 5 essential files**:
    - README.md - Main plugin documentation
    - CONTRIBUTING.md - Contribution guidelines
    - SECURITY.md - Security policy
    - CHANGELOG.md - This file
    - BUILD.md - Build and development instructions
  - All documentation preserved (nothing deleted, only organized)
  - Easier navigation and discovery of relevant documentation

### Added
- **LM Studio Function Calling Support**: Added OpenAI-compatible function calling to LM Studio provider (#1360)
  - Preserves OpenAI-compatible message structure for assistant messages with `tool_calls`
  - Maintains tool role messages with `tool_call_id` and `name` fields
  - Added `normalise_tools_for_payload()` method for consistent tool formatting
  - Streaming explicitly disabled when tools are present for reliable execution
  - Full backward compatibility - non-tool scenarios work exactly as before
  - Target model: qwen/qwen3-coder-30b
  - Comprehensive test coverage (4 new tests)

### Fixed
- **Code Quality Improvements**: Comprehensive code review and documentation update (November 16, 2025)
  - Performed complete code review confirming 95/100 code quality score (Excellent)
  - Verified security: No critical vulnerabilities identified, excellent input sanitization and output escaping
  - Verified SOC compliance: No new violations introduced, existing violations tracked in improvement plan
  - Verified documentation: All 69 documentation files current and comprehensive
  - JavaScript linting: Clean (only vendor file warning - expected)
  - PHP linting: Non-critical documentation and style warnings identified for future improvement
  - Test suite: Comprehensive 60+ test files ready for execution
  - Auto-fixed 362 PHP coding standard violations across 32 files (previous review)
  - Fixed critical security issue: added wp_unslash() to $_POST data sanitization in AJAX handlers (previous review)
  - Renamed admin integration files to match WordPress coding standards (previous review)
  - Fixed inline comment formatting to comply with WordPress standards (previous review)
  - Improved code consistency and readability across the plugin
- **Orchestration Capability Flags System**: Properly restored from PR #1142 with crash fix
  - Original implementation caused site crashes due to incomplete interface implementations
  - Verified all 21 tools with capability flags have complete method implementations
  - Restored 4 orchestration interfaces safely (Capability_Flags, Rules, Flow_Stage, Context_Restrictions)
  - No tools declare interfaces without implementing required methods (crash cause)
  - All syntax validations pass

### Added
- **MCP 2024-11-05 Specification Support**: Updated documentation to align with the latest Model Context Protocol specification
  - OAuth 2.1 security enhancements (PKCE, token rotation, mandatory HTTPS)
  - Streamable HTTP transport for better reconnection and bidirectional communication
  - Progress notifications with descriptive status messages during tool execution
  - Tool annotations for read-only, destructive, and permission-based operations
  - Session management via `Mcp-Session-Id` header for state recovery
  - JSON-RPC batching support for efficient parallel task processing
  - Multimodal content support (audio data streams)
  - Completions capability for argument autocompletion
- **Root Security Key**: Optional wp-config.php constant (`WP_MCP_AI_ROOT_SECURITY_KEY`) that can be enabled during emergency shutdown to require authentication before re-initializing the plugin. Includes rate limiting (5 attempts per 5 minutes), automatic lockout (15 minutes), and comprehensive audit logging. Provides additional protection against unauthorized reactivation after security incidents.【F:docs/root-security-key.md†L1-L511】【F:includes/class-wp-mcp-ai-root-security-key.php†L1-L360】
- **Token Usage Management Dashboard**: Admin settings page now displays comprehensive token usage statistics with per-user and global views, breakdown by provider and model, and reset capabilities for administrators
- **Job Notification System**: Real-time SSE streaming and webhook notifications for async operations
- **Message Bundling**: Client-side 800ms message bundling to reduce API calls and server load
- **Agentic Loop Token Management**: Three-tier intelligent handling (detection, auto-switching, truncation) for token overflow
- **MCP JSON-RPC 2.0 Endpoint**: Standards-compliant `/mcp` endpoint for remote MCP client communication (now supporting 2024-11-05 specification)
- **Async Crawl4AI Polling**: Server-side WP-Cron polling for long-running crawl jobs with job status tracking
- **LM Studio Model Fetching**: Fixed data structure mismatch for "Fetch Models" feature
- **CPT-CCT Synchronization**: Automatic bidirectional sync between WordPress CPT and JetEngine CCT for assistants
- Automatic activation of JetEngine Data Stores module when JetEngine is installed and active
- **JetEngine API Compatibility Layer**: Added backward-compatible query_items() implementation to support both JetEngine 3.3+ (new db->query() API) and older versions (Item_Handler->query_items())

### Changed
- **Documentation Updates**: Comprehensive updates to MCP-related documentation files
  - `docs/mcp-endpoint.md`: Added 2024-11-05 features, implementation status, and upgrade recommendations
  - `docs/MCP-AND-SSE.md`: Added Streamable HTTP transport info, protocol enhancements, and migration guide
  - `docs/mcp-server-authentication.md`: Added OAuth 2.1 security enhancements section
  - `docs/DOCUMENTATION_INDEX.md`: Updated with MCP version and enhanced documentation references
  - `README.md`: Added MCP version badge and enhanced MCP section with 2024-11-05 features
  - `docs/jetengine-api-compatibility.md`: New comprehensive guide for JetEngine API compatibility
  - `docs/deployment-troubleshooting.md`: Added JetEngine v3.3+ compatibility troubleshooting
- Chat interface now provides visual feedback for message bundling ("Preparing to send…", "Sending…")
- Token overflow scenarios automatically switch to higher-capacity models (gpt-4o-mini → Gemini 2.0 Flash)
- SSE endpoint modernized with automatic reconnection, event IDs, and HTTP/2 compatibility

### Fixed
- **JetEngine API Compatibility**: Fixed fatal error "Call to undefined method Item_Handler::query_items()" when using JetEngine 3.3+
  - Updated Performance Monitor CCT to use new db->query() API with fallback to old Item_Handler API
  - Updated Performance Reporter to use compatibility layer
  - Updated Elementor performance widgets to use compatibility layer
  - Added comprehensive test suite (12 tests) for query compatibility
- **PHP Version Compatibility**: Added defensive PHP version checks to all WooCommerce-related files to prevent parse errors on PHP < 7.4. This ensures that if any WooCommerce logging or error handling mechanism attempts to load plugin files directly, they will gracefully exit instead of causing "unexpected token private/protected" fatal errors on older PHP versions.
- **LM Studio & Ollama Timeout Issues**: Fixed "WordPress timed out waiting for a response" errors for local AI providers
  - Increased minimum timeout for completion requests from 30s to 120s
  - Resource Manager now allows bypassing PHP `max_execution_time` constraint for external HTTP requests
  - Local AI models can now take the time they need (60-240s+) without timing out
  - Timeout can be further increased via Settings → NV oOS → Request Timeout if needed【F:includes/class-resource-manager.php†L189-L220】【F:includes/class-wp-mcp-ai-ollama-client.php†L111-L119】【F:includes/class-wp-mcp-ai-lm-studio-client.php†L253-L261】
- JavaScript lint errors: Fixed 6 linting errors including unused function parameters in admin-settings.js and camelcase identifier warnings in settings-dashboard.js
- JavaScript lint error for unused `waitForCrawl4aiTask` function (documented as reserved for future use)
- 164 PHP coding standard violations auto-fixed across 19 files
- Tool registry validation ensuring correct slug-to-class mappings

## [1.0.0] – 2025-10-23
### Changed
- Expanded chat interaction logging to keep structured message content while trimming oversized payloads.
- Front-end chat client now preserves assistant replies that contain non-text content like images or tool results.
- Bundled the ChatKit integration directly inside the core plugin, replacing the standalone add-on workflow.

## [0.9.0] – 2025-10-21
### Added
- Initial beta release
- AI Assistant custom post type
- OpenAI GPT-4o-mini integration
- REST chat endpoint `/wp-json/mcp-ai/v1/chat`
- Tool registry with default tools
- WooCommerce & JetEngine conditional tools
- Admin settings for API key
- Shortcode `[mcp_ai_chat assistant="ID"]`

### Notes
- Stable for development & testing.
- Production hardening will follow post-feedback.
