================================================================================
  NV oOS (mcp-ai-wpoos) - TEST ERROR REPORT
  Generated: 2026-06-09
================================================================================

SCOPE: Base + Pro plugin test suite
  - tests/ (base plugin tests) + addons/pro/tests/ (pro addon tests)
  - addons/canvas-toolkit/tests/ + addons/media-studio/tests/ + addons/saas-controller/tests/
  
TOTAL TEST FILES: 1,225 (after excluding helpers, fixtures, manual, bootstrap)
TOTAL TESTS:    12,869 (as reported by PHPUnit test discovery)

RUN STATUS: Partial completion
  - Files 1-1170 tested (95.5% coverage)
  - Files 1171-1225 (~55 files, mostly veo/video/web/workflow) could not be
    completed within agent time limits (30 min max per invocation)

================================================================================
  OVERALL SUMMARY
================================================================================

Unique failing test classes:  102
Total failure assertions:     1,695 (across 24 failing batches)
Batches with no failures:     15
Batches with failures:        24
Batches with errors only:     0

Note: This report extracts assertion FAILURES only. PHP Errors (E) and 
Warnings (W) from the test progress bar indicate test crashes or setup 
problems, but their details are not fully captured by the batch runner.

================================================================================
  TOP 30 FAILING TEST CLASSES (by failure count)
================================================================================

114  Test_Healthcare_Imaging_Toolkit
 90  Test_CRM_Toolkit
 84  Test_Graphify_Connectors
 48  Test_Graphify_Remote
 48  Test_Git_Stash_Operations
 42  Test_Healthcare_Interop
 42  Test_Graphify_Saas_Connectors
 42  Test_Graphify_Field_Map_Validator
 39  Test_Graphify_Embeddings_On_Ingest
 36  Test_Graphify_Saas_Connectors_Batch2
 36  Test_Graphify_S3_Driver
 33  Test_NVOOS_SaaS_Controller_REST_Stripe_Webhook
 33  Test_Media_Toolkit_Tools
 33  Test_AJAX_Handlers_Registered
 30  Test_Graphify_Schema_Org_Mapper
 30  Test_Architectural_Tools_Phase_E
 27  WP_MCP_AI_Test_Chat_Performance_Optimizations
 27  Test_Graphify_Saas_Connectors_Batch3
 27  Test_Graphify_Generic_SQL_Driver
 27  Test_ECA_Sync_From_ISAMS
 24  WP_MCP_AI_Chat_Transcript_Save_Endpoint_Test
 24  WP_MCP_AI_Chat_Transcript_Pagination_Test
 24  Test_Performance_Section_AJAX
 24  Test_Media_Toolkit_Integration
 24  Test_Cloudways_Client
 24  Test_Agent_Memory_Tools
 21  Test_Graphify_Generic_GraphQL_Driver
 21  Test_Cron_Status_Controls
 21  Test_Analytics_Engine
 18  WP_MCP_AI_Chat_Transcript_Guest_Tokens_Test
 18  WP_MCP_AI_Chat_Image_Only_Messages_Test
 18  Test_Performance_Security_Check
 18  Test_Performance_Section_Health_Status
 18  Test_Chat_Transcript_Get_By_Session_Key
 18  Test_Chat_Conversation_CCT_Integration
 18  Test_Approvals_AJAX

================================================================================
  CATEGORIES OF FAILURES
================================================================================

1. HEALTHCARE TOOLS (addons/pro/tests/healthcare/) - ~156 failures
   - Test_Healthcare_Imaging_Toolkit (114)
   - Test_Healthcare_Interop (42)
   Most appear to be related to DICOM handling, FHIR bundles, HL7v2 parsing,
   audit logging, and EHR connectivity. Likely caused by missing test data
   or configuration prerequisites.

2. GRAPHIFY / SAAS CONNECTORS (addons/pro/tests/) - ~290+ failures
   - Test_Graphify_Connectors (84)
   - Test_Graphify_Remote (48)
   - Test_Graphify_Saas_Connectors (42)
   - Test_Graphify_Field_Map_Validator (42)
   - Test_Graphify_Embeddings_On_Ingest (39)
   - Test_Graphify_Saas_Connectors_Batch2 (36)
   - Test_Graphify_S3_Driver (36)
   - Test_Graphify_Schema_Org_Mapper (30)
   - Test_Graphify_Saas_Connectors_Batch3 (27)
   - Test_Graphify_Generic_SQL_Driver (27)
   - Test_Graphify_Generic_GraphQL_Driver (21)
   This is the largest cluster of failures. These are all Graphify integration
   tests that likely depend on external services or specific database configurations.

3. CRM / MEDIA / PERFORMANCE TOOLS (addons/pro/tests/) - ~189 failures
   - Test_CRM_Toolkit (90)
   - Test_Media_Toolkit_Tools (33)
   - Test_Media_Toolkit_Integration (24)
   - Test_Performance_Section_AJAX (24)
   - Test_Performance_Security_Check (18)
   - Test_Performance_Section_Health_Status (18)

4. CHAT TRANSCRIPTS / GUEST TOKENS (tests/) - ~108 failures
   - WP_MCP_AI_Chat_Transcript_Save_Endpoint_Test (24)
   - WP_MCP_AI_Chat_Transcript_Pagination_Test (24)
   - WP_MCP_AI_Chat_Transcript_Guest_Tokens_Test (18)
   - WP_MCP_AI_Chat_Transcript_Get_By_Session_Key (18)
   - WP_MCP_AI_Chat_Conversation_CCT_Integration (18)
   - WP_MCP_AI_Chat_Image_Only_Messages_Test (18)

5. GIT / ARCHITECTURAL / ECA (addons/pro/tests/) - ~123 failures
   - Test_Git_Stash_Operations (48)
   - Test_Architectural_Tools_Phase_E (30)
   - Test_ECA_Sync_From_ISAMS (27)
   - Test_ECA_Tools_Integration (15)

6. CLOUDWAYS / PERFORMANCE / MISC (addons/pro/tests/) - ~60 failures
   - Test_Cloudways_Client (24)
   - Test_AJAX_Handlers_Registered (33)
   - Test_Analytics_Engine (21)
   - Test_Cron_Status_Controls (21)

7. AGENT MEMORY / CHAT PERFORMANCE (tests/) - ~51 failures
   - Test_Agent_Memory_Tools (24)
   - WP_MCP_AI_Test_Chat_Performance_Optimizations (27)

8. BASE VERSION / PRESET / SMALLER FAILURES - scattered across ~40+ files
   - Various smaller failures (1-15 each) across base plugin tests

================================================================================
  COMMON FAILURE PATTERNS
================================================================================

Based on the error messages observed:

a) Tool registration / capability issues:
   - Tools not found in registry when expected
   - Capability flag mismatches
   - Tools available when they should be gated

b) Authentication / permission issues:
   - REST endpoints returning 401/403 instead of expected status codes
   - Nonce verification failures in AJAX handlers
   - Bearer token validation returning unexpected codes

c) Database / CCT integration issues:
   - JetEngine CCT operations failing (likely requires JetEngine plugin)
   - Duplicate key errors in WordPress test database
   - Deadlock errors during wp_install

d) External service dependencies:
   - Many Graphify/SaaS/Cloudways tests likely require live API credentials
   - HTTP "Connection refused" errors (no mock server running)
   - OAuth token issues (no actual OAuth tokens)

e) Test data / fixture issues:
   - Expected values not matching actual output
   - Assertion on empty arrays when data should exist
   - "Failed asserting that actual size 0 matches expected size N"

f) Known infrastructure issues:
   - WordPress database deadlocks during test setup (multiple tests)
   - Duplicate entry errors during wp_install_defaults
   - OOS orchestrator pre-warm failure (type mismatch in OpenAiClient constructor)

================================================================================
  UNTESTED FILES (~55 files, positions 1170-1225)
================================================================================

These are alphabetically last test files, mostly VEO video generation,
web search, WooCommerce, workflow, and verifier tests:

tests/test-veo-chat-response.php          tests/test-veo-content-policy.php
tests/test-veo-double-async-fix.php       tests/test-veo-duration-fix.php
tests/test-veo-file-based-polling.php     tests/test-veo-file-name.php
tests/test-veo-filename-consistency.php   tests/test-veo-inline-kick.php
tests/test-veo-integration-flow.php       tests/test-veo-job-completion-order.php
tests/test-veo-job-id-in-delegation-message.php
tests/test-veo-job-notifier-integration.php
tests/test-veo-job-permission-context.php tests/test-veo-local-url.php
tests/test-veo-media-lookup.php           tests/test-veo-model-selection.php
tests/test-veo-parent-job-completion.php  tests/test-veo-response-structure.php
tests/test-veo-rest-service-integration.php
tests/test-veo-schema-minimum-fix.php     tests/test-veo-settings-defaults.php
tests/test-veo-timeout-async-fallback.php
tests/test-veo-tool-integration-verification.php
tests/test-veo-transient-workflow.php
tests/test-veo-unified-job-metadata-merge.php
tests/test-veo-validation.php             tests/test-veo-video-download-auth.php
tests/test-veo-video-generation-no-audio.php
tests/test-video-frame-extractor.php      tests/test-video-tools.php
tests/test-virtual-agent-delegation.php   tests/test-vision-tools.php
tests/test-vitals-log-cct-upsert.php      tests/test-web-search-exa-perplexity.php
tests/test-web-search-tool.php            tests/test-web-search-validated-tool.php
tests/test-webhook-permission-callbacks.php
tests/test-webhook-status-page.php        tests/test-whatsapp-webhook-controller.php
tests/test-wizard-ajax.php                tests/test-woo-tools.php
tests/test-workflow-ajax-handlers.php     tests/test-workflow-capability-validation.php
tests/test-workflow-health-check.php      tests/test-workflow-presets-section-rendering.php
tests/test-wp-capability-checker.php      tests/test-wp-cli-new-commands.php
tests/test-wp-cli-tool.php                tests/test-wp-http-client.php
tests/test-wp-options-store.php           tests/test-yahoo-oauth-integration.php
tests/test-yahoo-sports-connection.php
tests/verifiers/test-llm-judge-verifier.php
tests/verifiers/test-rule-verifier.php
tests/verifiers/test-schema-verifier.php

Partial results from VEO tests already tested:
  test-veo-chat-response.php: 4 tests, 2 failures
  test-veo-* (first 15 files): 65 tests, 11 failures

================================================================================
  NOTES FOR FIXING
================================================================================

1. The most impactful fix would be addressing the Graphify/SaaS connector
   tests (~290 failures). These may need mock servers or test doubles
   instead of depending on live external services.

2. Healthcare toolkit tests (~156 failures) likely need test fixtures
   (sample DICOM files, FHIR bundles, HL7v2 messages).

3. Chat transcript tests (~108 failures) appear to have authentication
   flow issues. Check credential/token handling in the REST endpoints.

4. Many "Expected 200, got 401" patterns suggest the test admin user
   setup via wp_mcp_ai_setup_test_environment() may not be properly
   establishing authentication for all endpoints.

5. The database deadlock errors during wp_install suggest the test
   database is not being properly cleaned between test runs. Consider
   using transactions or resetting the database between batches.

6. The OOS orchestrator pre-warm failure (type mismatch in OpenAiClient
   constructor) is a known issue that appears in every test run but
   doesn't seem to affect test outcomes.

================================================================================
  FILES WITH ERROR LOGS
================================================================================

Full error logs available at:
  /tmp/test-results/batch-errors.txt       (batches 1-13, files 0-389)
  /tmp/test-results/batch-errors-p2.txt    (batches 14-26, files 390-779)
  /tmp/test-results/batch-errors-p3.txt    (batches 27-39, files 780-1169)

The batch runner script is at:
  bin/run-tests-batched.php

To continue testing remaining files:
  php bin/run-tests-batched.php --batch-size=5 --start=1170
