# WP oOS – Changelog

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
- **Code Review Documentation**: Added comprehensive code review for December 22-24, 2025
  - Complete analysis of recent changes and code quality
  - Security review (10/10 score - no vulnerabilities found)
  - Documentation quality assessment (9/10 score)
  - Architecture review and recommendations
  - See `docs/implementation-history/2025/code-reviews/CODE_REVIEW_2025-12-24.md`

### Fixed
- Version number inconsistencies across documentation files (1.0.0 → 1.1.0)
- Tool count discrepancies in README.md and other docs
- Last updated dates in documentation index files (now December 24, 2025)

## [Unreleased]

### Added

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
  - See [Gemini Geospatial Documentation](docs/GEMINI_GEOSPATIAL.md)

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
  - Timeout can be further increased via Settings → WP oOS → Request Timeout if needed【F:includes/class-resource-manager.php†L189-L220】【F:includes/class-wp-mcp-ai-ollama-client.php†L111-L119】【F:includes/class-wp-mcp-ai-lm-studio-client.php†L253-L261】
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
