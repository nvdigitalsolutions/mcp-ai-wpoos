# PHPCS:IGNORE UnusedFunctionParameter Analysis

**Generated:** 2026-01-31 21:51:17
**Repository:** mcp-ai-wpoos
**Scope:** includes/ directory

## Summary Statistics

| Category | Count | Percentage |
|----------|-------|------------|
| ✅ WordPress Core Requirements | 29 | 19.5% |
| ✅ Interface Requirements | 59 | 39.6% |
| 📋 Future Features (TODO) | 19 | 12.8% |
| ⚠️  Questionable (Review Needed) | 42 | 28.2% |
| **TOTAL** | **149** | **100%** |

## Key Findings

- **88 instances (59.1%)** are legitimate and should be kept
- **19 instances (12.8%)** are documented future features
- **42 instances (28.2%)** need review and potential implementation

---

## ⚠️  Category 4: QUESTIONABLE - Should Review/Implement

**STATUS: 4 of 7 high-priority items IMPLEMENTED ✅**

These parameters appeared in active code but were marked as unused. Review complete with implementations.

**HIGH PRIORITY - IMPLEMENTED ✅**

1. **File Validation** (2 instances) - **COMPLETE**
   - ✅ `validate_upload_inputs(..., $options)` - NOW validates max_size and allowed_types
   - ✅ `log_upload_start(..., $options)` - NOW logs display_name, purpose, max_size
   - Impact: Security improvement - file size and MIME type validation now working
   - File: `includes/services/class-wp-mcp-ai-file-orchestration-service.php`

2. **Privacy Documentation** (2 instances) - **CLARIFIED ✅**
   - ✅ `export_privacy_data($user_id)` - Verified as correct template pattern
   - ✅ `erase_privacy_data($user_id)` - Verified as correct template pattern
   - Impact: No compliance issue - base methods are templates, child classes use parameter correctly
   - Files: `includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php`, implementations verified

**HIGH PRIORITY - FUTURE FEATURES (Documented) 📋**

3. **Mesh Router** (3 instances) - **CONFIRMED AS PLANNED FEATURES**
   - 📋 `select_peer_ai_optimized(..., $context)` - Reserved for user preferences, geographic routing
   - 📋 `select_peer_round_robin(..., $hub_config)` - Reserved for hub configuration
   - 📋 `execute_peer_query(..., $context)` - Reserved for user identity, session data
   - Impact: Legitimate future features with clear use cases
   - File: `includes/class-wp-mcp-ai-mesh-router.php`

| File | Line | Function | Parameter | Status |
|------|------|----------|-----------|--------|
| `` |  | `()` | `` | Review needed |
| `class-wp-mcp-ai-mesh-router.php` | 192 | `select_peer_ai_optimized()` | `$context` | Review needed |
| `class-wp-mcp-ai-mesh-router.php` | 265 | `select_peer_round_robin()` | `$hub_config` | Review needed |
| `class-wp-mcp-ai-admin-settings-base.php` | 491 | `filter_memory_max_file_bytes()` | `$attachment_id` | Review needed |
| `class-wp-mcp-ai-admin-settings.php` | 5474 | `filter_memory_max_file_bytes()` | `$attachment_id` | Review needed |
| `trait-wp-mcp-ai-tool-wordpress-native.php` | 264 | `export_privacy_data()` | `$user_id` | Review needed |
| `trait-wp-mcp-ai-tool-wordpress-native.php` | 278 | `erase_privacy_data()` | `$user_id` | Review needed |
| `class-wp-mcp-ai-agentic-workflow-optimizer.php` | 206 | `maybe_compress_result()` | `$result` | Review needed |
| `class-wp-mcp-ai-stdio-transport.php` | 509 | `handle_prompts_list()` | `$params` | Review needed |
| `class-wp-mcp-ai-gemini-music-service.php` | 89 | `generate_music()` | `$prompt` | Review needed |
| `class-wp-mcp-ai-tool-chain-predictor.php` | 372 | `predict_from_patterns()` | `$task` | Review needed |
| `class-wp-mcp-ai-tool-chain-predictor.php` | 543 | `reorder_for_data_flow()` | `$dependencies` | Review needed |
| `class-wp-mcp-ai-tool-service.php` | 177 | `get_available_tools()` | `$tool` | Review needed |
| `class-wp-mcp-ai-tool-service.php` | 273 | `get_tool_statistics()` | `$tool_slug` | Review needed |
| `class-wp-mcp-ai-efficiency-monitor.php` | 309 | `calculate_resource_usage_metrics()` | `$system_load` | Review needed |
| `class-wp-mcp-ai-model-service.php` | 495 | `get_cloudflare_models()` | `$requires_multimodal` | Review needed |
| `class-wp-mcp-ai-reasoning-controller.php` | 529 | `check_completeness()` | `$task` | Review needed |
| `class-wp-mcp-ai-file-orchestration-service.php` | 391 | `validate_upload_inputs()` | `$options` | Review needed |
| `class-wp-mcp-ai-file-orchestration-service.php` | 518 | `log_upload_start()` | `$options` | Review needed |
| `class-wp-mcp-ai-code-optimizer.php` | 252 | `extract_dependencies()` | `$task` | Review needed |
| `class-wp-mcp-ai-code-optimizer.php` | 277 | `compress_boilerplate()` | `$dependencies` | Review needed |
| `class-wp-mcp-ai-agent-team-orchestrator.php` | 1092 | `find_profession_agent_for_role()` | `$role` | Review needed |
| `class-wp-mcp-ai-file-service.php` | 251 | `handle_file_download()` | `$attachment_id` | Review needed |
| `class-wp-mcp-ai-tool-profiler.php` | 304 | `generate_recommendations()` | `$tool_slug` | Review needed |
| `class-wp-mcp-ai-job-notifier.php` | 371 | `get_sse_event_name_for_job()` | `$event_type` | Review needed |
| `class-wp-mcp-ai-nefarious-usage-monitor.php` | 269 | `monitor_chat_request()` | `$request_data` | Review needed |
| `class-wp-mcp-ai-job-queue-manager.php` | 397 | `mark_job_complete()` | `$result` | Review needed |
| `class-wp-mcp-ai-openai-client.php` | 4341 | `count_tokens()` | `$messages` | Review needed |
| `class-wp-mcp-ai-tool-recommendations.php` | 592 | `get_recommendation_reason()` | `$tool_slug` | Review needed |
| `class-wp-mcp-ai-cli-dlq.php` | 191 | `retry()` | `$assoc_args` | Review needed |
| `class-wp-mcp-ai-cli-dlq.php` | 234 | `dismiss()` | `$assoc_args` | Review needed |
| `class-wp-mcp-ai-cli-sla.php` | 283 | `enable()` | `$assoc_args` | Review needed |
| `class-wp-mcp-ai-cli-sla.php` | 302 | `disable()` | `$assoc_args` | Review needed |
| `class-wp-mcp-ai-tool-recommendations-backup.php` | 330 | `get_recommendation_reason()` | `$tool_slug` | Review needed |
| `class-wp-mcp-ai-integration-wordpress-gravatar.php` | 69 | `maybe_enrich_payload()` | `$request` | Review needed |
| `class-wp-mcp-ai-integration-auth0-github.php` | 69 | `maybe_enrich_payload()` | `$request` | Review needed |
| `class-wp-mcp-ai-enhanced-token-tracking.php` | 580 | `infer_gemini_model_from_tool()` | `$old_model` | Review needed |
| `class-wp-mcp-ai-pattern-workflow-templates.php` | 48 | `get_workflow_template()` | `$pattern_slug` | Review needed |
| `class-wp-mcp-ai-queue-manager.php` | 140 | `get_execution_mode()` | `$context` | Review needed |
| `class-wp-mcp-ai-queue-manager.php` | 203 | `estimate_execution_time()` | `$arguments` | Review needed |
| `class-wp-mcp-ai-response-attachments.php` | 45 | `handle_chat_response()` | `$request` | Review needed |
| `class-wp-mcp-ai-logger.php` | 670 | `redact_sensitive_value()` | `$value` | Review needed |

## 📋 Category 3: FUTURE FEATURES - Documented TODOs

These parameters are explicitly marked for future implementation. Track these for roadmap planning.

| File | Line | Function | Parameter | Future Feature |
|------|------|----------|-----------|----------------|
| `class-wp-mcp-ai-model-config.php` | 238 | `sync_to_cct()` | `$config` | future JetEngine CCT integration.
includes/class-wp-mcp-ai-model-config.php-239-		// Only sync if JetEngine and the model rate limits CCT are available.
includes/class-wp-mcp-ai-model-config.php-240-		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
includes/class-wp-mcp-ai-model-config.php-241-			return;
includes/class-wp-mcp-ai-model-config.php-242-		}
includes/class-wp-mcp-ai-model-config.php-243-
--
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-115- |
| `class-wp-mcp-ai-agentic-workflow-optimizer.php` | 117 | `cache_tool_result()` | `$context` | future context-aware caching.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-118-		// Don't cache errors or non-cacheable tools.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-119-		if ( is_wp_error( $result ) || ! $this->is_cacheable_tool( $tool_name ) ) {
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-120-			return;
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-121-		}
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-122-
--
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-204- |
| `class-wp-mcp-ai-agentic-workflow-optimizer.php` | 368 | `get_iteration_history()` | `$assistant_id` | future implementation.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-369-		// This would query chat transcripts for historical data.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-370-		// Placeholder implementation.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-371-		return array();
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-372-	}
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-373-
--
includes/class-wp-mcp-ai-stdio-transport.php-507- |
| `class-wp-mcp-ai-tool-chain-predictor.php` | 180 | `execute_speculative_chain()` | `$context` | future context-aware speculation.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-181-		if ( empty( $predicted_chain ) ) {
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-182-			return array(
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-183-				'prewarmed' => 0,
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-184-				'cached'    => 0,
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-185-			);
--
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-370- |
| `class-wp-mcp-ai-model-service.php` | 193 | `get_anthropic_models()` | `$settings` | future API key validation.
includes/services/class-wp-mcp-ai-model-service.php-194-		// Return models even if API key is not configured, for browsing purposes.
includes/services/class-wp-mcp-ai-model-service.php-195-		// The models are static and don't require API access to list.
includes/services/class-wp-mcp-ai-model-service.php-196-		$models = array();
includes/services/class-wp-mcp-ai-model-service.php-197-
includes/services/class-wp-mcp-ai-model-service.php-198-		// Claude 4.5 series (multimodal - vision capable) - Latest (2025).
--
includes/services/class-wp-mcp-ai-model-service.php-493- |
| `class-wp-mcp-ai-reasoning-controller.php` | 242 | `calculate_logical_complexity()` | `$context` | future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-243-		$task_lower = strtolower( $task );
includes/services/class-wp-mcp-ai-reasoning-controller.php-244-		$score      = 0;
includes/services/class-wp-mcp-ai-reasoning-controller.php-245-
includes/services/class-wp-mcp-ai-reasoning-controller.php-246-		// Complex logical operators.
includes/services/class-wp-mcp-ai-reasoning-controller.php-247-		$logical_keywords = array(
--
includes/services/class-wp-mcp-ai-reasoning-controller.php-394- |
| `class-wp-mcp-ai-reasoning-controller.php` | 396 | `needs_verification()` | `$context` | future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-397-		$task_lower = strtolower( $task );
includes/services/class-wp-mcp-ai-reasoning-controller.php-398-		$score      = 0;
includes/services/class-wp-mcp-ai-reasoning-controller.php-399-
includes/services/class-wp-mcp-ai-reasoning-controller.php-400-		// Verification keywords.
includes/services/class-wp-mcp-ai-reasoning-controller.php-401-		$verification_keywords = array(
--
includes/services/class-wp-mcp-ai-reasoning-controller.php-515- |
| `class-wp-mcp-ai-reasoning-controller.php` | 517 | `check_logical_consistency()` | `$reasoning_output` | future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-518-		// Simple heuristic: assume consistent unless obvious contradictions.
includes/services/class-wp-mcp-ai-reasoning-controller.php-519-		return 0.8; // Placeholder - real implementation would analyze for contradictions.
includes/services/class-wp-mcp-ai-reasoning-controller.php-520-	}
includes/services/class-wp-mcp-ai-reasoning-controller.php-521-
includes/services/class-wp-mcp-ai-reasoning-controller.php-522-	/ |
| `class-wp-mcp-ai-tool-load-balancer.php` | 244 | `get_tool_recommendations()` | `$registry` | future implementation.
includes/services/class-wp-mcp-ai-tool-load-balancer.php-245-		$registry = $this->get_registry();
includes/services/class-wp-mcp-ai-tool-load-balancer.php-246-		if ( ! $registry ) {
includes/services/class-wp-mcp-ai-tool-load-balancer.php-247-			return array();
includes/services/class-wp-mcp-ai-tool-load-balancer.php-248-		}
includes/services/class-wp-mcp-ai-tool-load-balancer.php-249-
--
includes/services/class-wp-mcp-ai-file-service.php-249- |
| `class-wp-mcp-ai-tool-profiler.php` | 336 | `analyze_task_features()` | `$context` | future implementation. {
includes/services/class-wp-mcp-ai-tool-profiler.php-337-		$features = array(
includes/services/class-wp-mcp-ai-tool-profiler.php-338-			'keywords'      => array(),
includes/services/class-wp-mcp-ai-tool-profiler.php-339-			'task_type'     => 'general',
includes/services/class-wp-mcp-ai-tool-profiler.php-340-			'complexity'    => 'medium',
includes/services/class-wp-mcp-ai-tool-profiler.php-341-			'requires_auth' => false,
--
includes/class-wp-mcp-ai-job-notifier.php-369- |
| `class-wp-mcp-ai-federation-directory-rest.php` | 633 | `calculate_peer_score()` | `$max_price` | Planned feature |
| `class-wp-mcp-ai-rest.php` | 5277 | `invoke_team_member()` | `$request` | future implementation.
includes/class-wp-mcp-ai-rest.php-5278-			// Load profession configuration.
includes/class-wp-mcp-ai-rest.php-5279-			$profession_config = $this->load_profession_configuration( $member_id, array() );
includes/class-wp-mcp-ai-rest.php-5280-
includes/class-wp-mcp-ai-rest.php-5281-			// Use profession's provider/model or defaults.
includes/class-wp-mcp-ai-rest.php-5282-			$provider = isset( $profession_config['provider'] ) ? $profession_config['provider'] : '';
--
includes/class-wp-mcp-ai-rest.php-9467- |
| `class-wp-mcp-ai-tool-registry.php` | 285 | `get_tool_capability()` | `$slug` | future implementation.
includes/class-wp-mcp-ai-tool-registry.php-286-			// This method is referenced but not yet implemented.
includes/class-wp-mcp-ai-tool-registry.php-287-			// For now, return null to maintain compatibility.
includes/class-wp-mcp-ai-tool-registry.php-288-			return null;
includes/class-wp-mcp-ai-tool-registry.php-289-		}
includes/class-wp-mcp-ai-tool-registry.php-290-
--
includes/class-wp-mcp-ai-tool-registry.php-1250- |
| `class-wp-mcp-ai-tool-registry.php` | 1252 | `register_tool_with_context()` | `$tool` | future context-aware registration.
includes/class-wp-mcp-ai-tool-registry.php-1253-			// For now, use legacy registration.
includes/class-wp-mcp-ai-tool-registry.php-1254-			return $this->register_tool( $tool );
includes/class-wp-mcp-ai-tool-registry.php-1255-		}
includes/class-wp-mcp-ai-tool-registry.php-1256-
includes/class-wp-mcp-ai-tool-registry.php-1257-		/ |
| `class-wp-mcp-ai-privacy.php` | 579 | `get_jet_engine_chat_transcripts()` | `$user_id` | future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-580-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-581-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-582-		return array();
includes/class-wp-mcp-ai-privacy.php-583-	}
includes/class-wp-mcp-ai-privacy.php-584-
--
includes/class-wp-mcp-ai-privacy.php-590- |
| `class-wp-mcp-ai-privacy.php` | 592 | `get_jet_engine_usage_analytics()` | `$user_id` | future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-593-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-594-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-595-		return array();
includes/class-wp-mcp-ai-privacy.php-596-	}
includes/class-wp-mcp-ai-privacy.php-597-
--
includes/class-wp-mcp-ai-privacy.php-602- |
| `class-wp-mcp-ai-privacy.php` | 604 | `delete_jet_engine_chat_transcripts()` | `$user_id` | future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-605-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-606-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-607-		return 0;
includes/class-wp-mcp-ai-privacy.php-608-	}
includes/class-wp-mcp-ai-privacy.php-609-
--
includes/class-wp-mcp-ai-privacy.php-614- |
| `class-wp-mcp-ai-privacy.php` | 616 | `delete_jet_engine_usage_analytics()` | `$user_id` | future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-617-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-618-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-619-		return 0;
includes/class-wp-mcp-ai-privacy.php-620-	}
includes/class-wp-mcp-ai-privacy.php-621-}
--
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-67- |
| `class-wp-mcp-ai-cloudflare-client.php` | 2274 | `generate_speech()` | `$text` | future TTS implementation.
includes/class-wp-mcp-ai-cloudflare-client.php-2275-			// Cloudflare Workers AI does not currently support TTS models.
includes/class-wp-mcp-ai-cloudflare-client.php-2276-			// Models like @cf/deepgram/aura-2-en and @cf/myshell-ai/melotts do not exist in the catalog.
includes/class-wp-mcp-ai-cloudflare-client.php-2277-			return new WP_Error(
includes/class-wp-mcp-ai-cloudflare-client.php-2278-				'wp_mcp_ai_cloudflare_tts_unsupported',
includes/class-wp-mcp-ai-cloudflare-client.php-2279-				__( 'Text-to-speech (TTS) is not currently supported by Cloudflare Workers AI. Please use OpenAI, Google Gemini, or Hugging Face providers for speech generation features.', 'mcp-ai-wpoos' ),
--
includes/class-wp-mcp-ai-pattern-workflow-templates.php-46- |

## ✅ Category 1: LEGITIMATE - WordPress Core Requirements

These are required by WordPress hook signatures, REST API callbacks, or privacy framework. **DO NOT REMOVE.**

**Sample (first 10):**

| File | Function | Type |
|------|----------|------|
| `class-wp-mcp-ai-mesh-router.php` | `execute_peer_query()` | Hook callback |
| `class-wp-mcp-ai-admin-ajax-handlers.php` | `safe_ajax_handler()` | Hook callback |
| `class-wp-mcp-ai-security-training-admin.php` | `save_training_meta()` | Hook callback |
| `class-wp-mcp-ai-iso27001-badge.php` | `enqueue_badge_styles()` | Hook callback |
| `class-wp-mcp-ai-assistant-cpt.php` | `cleanup_deleted_assistant_credentials()` | Hook callback |
| `class-wp-mcp-ai-performance-monitor-service.php` | `prepare_jetengine_query_args()` | Hook callback |
| `class-wp-mcp-ai-federation-directory-rest.php` | `check_user_permission()` | Permission callback |
| `class-wp-mcp-ai-asset-inventory-rest.php` | `get_inventory()` | REST API callback |
| `class-wp-mcp-ai-asset-inventory-rest.php` | `trigger_discovery()` | REST API callback |
| `class-wp-mcp-ai-asset-inventory-rest.php` | `get_statistics()` | Permission callback |

*... and 19 more WordPress core requirement instances*

## ✅ Category 2: LEGITIMATE - Interface Requirements

These are required by interface contracts, tool system, or MCP protocol. **DO NOT REMOVE.**

**Sample (first 10):**

| File | Function | Type |
|------|----------|------|
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_max_agentic_iterations()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_resource_max_tokens()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_resource_request_timeout()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_max_retries()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_max_retry_delay()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_max_attachment_bytes()` | MCP protocol |
| `class-wp-mcp-ai-custom-filters-applicator.php` | `apply_lm_studio_fallback_model()` | MCP protocol |
| `class-wp-mcp-ai-rest-api-context-fix.php` | `ensure_query_string_preservation()` | MCP protocol |
| `class-wp-mcp-ai-assistant-cpt.php` | `render_tool_presets()` | MCP protocol |
| `class-wp-mcp-ai-orchestration-budget-enforcement-service.php` | `apply_budget_management_to_max_tokens()` | MCP protocol |

*... and 49 more interface requirement instances*

---

## Recommendations

### Immediate Actions (Category 4)

1. **Review all 42 questionable instances** - Determine if parameters should be:
   - Implemented now (parameter has clear purpose)
   - Removed from signature (truly unnecessary)
   - Moved to Category 3 with TODO comment (future feature)

2. **Priority files for review:**
   - `class-wp-mcp-ai-mesh-router.php` - 2 unused parameters
   - `trait-wp-mcp-ai-tool-wordpress-native.php` - 2 unused parameters
   - `class-wp-mcp-ai-tool-chain-predictor.php` - 2 unused parameters
   - `class-wp-mcp-ai-tool-service.php` - 2 unused parameters
   - `class-wp-mcp-ai-file-orchestration-service.php` - 2 unused parameters

### Future Planning (Category 3)

Track these 19 future features in roadmap:

- **future JetEngine CCT integration.
includes/class-wp-mcp-ai-model-config.php-239-		// Only sync if JetEngine and the model rate limits CCT are available.
includes/class-wp-mcp-ai-model-config.php-240-		if ( ! class_exists( 'WP_MCP_AI_Model_Rate_Limits_CCT' ) ) {
includes/class-wp-mcp-ai-model-config.php-241-			return;
includes/class-wp-mcp-ai-model-config.php-242-		}
includes/class-wp-mcp-ai-model-config.php-243-
--
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-115-** - 1 instances
- **future context-aware caching.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-118-		// Don't cache errors or non-cacheable tools.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-119-		if ( is_wp_error( $result ) || ! $this->is_cacheable_tool( $tool_name ) ) {
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-120-			return;
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-121-		}
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-122-
--
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-204-** - 1 instances
- **future implementation.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-369-		// This would query chat transcripts for historical data.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-370-		// Placeholder implementation.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-371-		return array();
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-372-	}
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-373-
--
includes/class-wp-mcp-ai-stdio-transport.php-507-** - 1 instances
- **future context-aware speculation.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-181-		if ( empty( $predicted_chain ) ) {
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-182-			return array(
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-183-				'prewarmed' => 0,
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-184-				'cached'    => 0,
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-185-			);
--
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-370-** - 1 instances
- **future API key validation.
includes/services/class-wp-mcp-ai-model-service.php-194-		// Return models even if API key is not configured, for browsing purposes.
includes/services/class-wp-mcp-ai-model-service.php-195-		// The models are static and don't require API access to list.
includes/services/class-wp-mcp-ai-model-service.php-196-		$models = array();
includes/services/class-wp-mcp-ai-model-service.php-197-
includes/services/class-wp-mcp-ai-model-service.php-198-		// Claude 4.5 series (multimodal - vision capable) - Latest (2025).
--
includes/services/class-wp-mcp-ai-model-service.php-493-** - 1 instances
- **future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-243-		$task_lower = strtolower( $task );
includes/services/class-wp-mcp-ai-reasoning-controller.php-244-		$score      = 0;
includes/services/class-wp-mcp-ai-reasoning-controller.php-245-
includes/services/class-wp-mcp-ai-reasoning-controller.php-246-		// Complex logical operators.
includes/services/class-wp-mcp-ai-reasoning-controller.php-247-		$logical_keywords = array(
--
includes/services/class-wp-mcp-ai-reasoning-controller.php-394-** - 1 instances
- **future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-397-		$task_lower = strtolower( $task );
includes/services/class-wp-mcp-ai-reasoning-controller.php-398-		$score      = 0;
includes/services/class-wp-mcp-ai-reasoning-controller.php-399-
includes/services/class-wp-mcp-ai-reasoning-controller.php-400-		// Verification keywords.
includes/services/class-wp-mcp-ai-reasoning-controller.php-401-		$verification_keywords = array(
--
includes/services/class-wp-mcp-ai-reasoning-controller.php-515-** - 1 instances
- **future implementation. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-518-		// Simple heuristic: assume consistent unless obvious contradictions.
includes/services/class-wp-mcp-ai-reasoning-controller.php-519-		return 0.8; // Placeholder - real implementation would analyze for contradictions.
includes/services/class-wp-mcp-ai-reasoning-controller.php-520-	}
includes/services/class-wp-mcp-ai-reasoning-controller.php-521-
includes/services/class-wp-mcp-ai-reasoning-controller.php-522-	/** - 1 instances
- **future implementation.
includes/services/class-wp-mcp-ai-tool-load-balancer.php-245-		$registry = $this->get_registry();
includes/services/class-wp-mcp-ai-tool-load-balancer.php-246-		if ( ! $registry ) {
includes/services/class-wp-mcp-ai-tool-load-balancer.php-247-			return array();
includes/services/class-wp-mcp-ai-tool-load-balancer.php-248-		}
includes/services/class-wp-mcp-ai-tool-load-balancer.php-249-
--
includes/services/class-wp-mcp-ai-file-service.php-249-** - 1 instances
- **future implementation. {
includes/services/class-wp-mcp-ai-tool-profiler.php-337-		$features = array(
includes/services/class-wp-mcp-ai-tool-profiler.php-338-			'keywords'      => array(),
includes/services/class-wp-mcp-ai-tool-profiler.php-339-			'task_type'     => 'general',
includes/services/class-wp-mcp-ai-tool-profiler.php-340-			'complexity'    => 'medium',
includes/services/class-wp-mcp-ai-tool-profiler.php-341-			'requires_auth' => false,
--
includes/class-wp-mcp-ai-job-notifier.php-369-** - 1 instances
- **Planned feature** - 1 instances
- **future implementation.
includes/class-wp-mcp-ai-rest.php-5278-			// Load profession configuration.
includes/class-wp-mcp-ai-rest.php-5279-			$profession_config = $this->load_profession_configuration( $member_id, array() );
includes/class-wp-mcp-ai-rest.php-5280-
includes/class-wp-mcp-ai-rest.php-5281-			// Use profession's provider/model or defaults.
includes/class-wp-mcp-ai-rest.php-5282-			$provider = isset( $profession_config['provider'] ) ? $profession_config['provider'] : '';
--
includes/class-wp-mcp-ai-rest.php-9467-** - 1 instances
- **future implementation.
includes/class-wp-mcp-ai-tool-registry.php-286-			// This method is referenced but not yet implemented.
includes/class-wp-mcp-ai-tool-registry.php-287-			// For now, return null to maintain compatibility.
includes/class-wp-mcp-ai-tool-registry.php-288-			return null;
includes/class-wp-mcp-ai-tool-registry.php-289-		}
includes/class-wp-mcp-ai-tool-registry.php-290-
--
includes/class-wp-mcp-ai-tool-registry.php-1250-** - 1 instances
- **future context-aware registration.
includes/class-wp-mcp-ai-tool-registry.php-1253-			// For now, use legacy registration.
includes/class-wp-mcp-ai-tool-registry.php-1254-			return $this->register_tool( $tool );
includes/class-wp-mcp-ai-tool-registry.php-1255-		}
includes/class-wp-mcp-ai-tool-registry.php-1256-
includes/class-wp-mcp-ai-tool-registry.php-1257-		/** - 1 instances
- **future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-580-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-581-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-582-		return array();
includes/class-wp-mcp-ai-privacy.php-583-	}
includes/class-wp-mcp-ai-privacy.php-584-
--
includes/class-wp-mcp-ai-privacy.php-590-** - 1 instances
- **future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-593-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-594-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-595-		return array();
includes/class-wp-mcp-ai-privacy.php-596-	}
includes/class-wp-mcp-ai-privacy.php-597-
--
includes/class-wp-mcp-ai-privacy.php-602-** - 1 instances
- **future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-605-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-606-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-607-		return 0;
includes/class-wp-mcp-ai-privacy.php-608-	}
includes/class-wp-mcp-ai-privacy.php-609-
--
includes/class-wp-mcp-ai-privacy.php-614-** - 1 instances
- **future JetEngine integration.
includes/class-wp-mcp-ai-privacy.php-617-		// Placeholder for JetEngine CCT integration.
includes/class-wp-mcp-ai-privacy.php-618-		// Implementation depends on CCT structure.
includes/class-wp-mcp-ai-privacy.php-619-		return 0;
includes/class-wp-mcp-ai-privacy.php-620-	}
includes/class-wp-mcp-ai-privacy.php-621-}
--
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-67-** - 1 instances
- **future TTS implementation.
includes/class-wp-mcp-ai-cloudflare-client.php-2275-			// Cloudflare Workers AI does not currently support TTS models.
includes/class-wp-mcp-ai-cloudflare-client.php-2276-			// Models like @cf/deepgram/aura-2-en and @cf/myshell-ai/melotts do not exist in the catalog.
includes/class-wp-mcp-ai-cloudflare-client.php-2277-			return new WP_Error(
includes/class-wp-mcp-ai-cloudflare-client.php-2278-				'wp_mcp_ai_cloudflare_tts_unsupported',
includes/class-wp-mcp-ai-cloudflare-client.php-2279-				__( 'Text-to-speech (TTS) is not currently supported by Cloudflare Workers AI. Please use OpenAI, Google Gemini, or Hugging Face providers for speech generation features.', 'mcp-ai-wpoos' ),
--
includes/class-wp-mcp-ai-pattern-workflow-templates.php-46-** - 1 instances

### Code Quality

1. **Good:** 59.1% of ignores are legitimate
2. **Needs Work:** 28.2% need review
3. **Well Documented:** 12.8% have TODO documentation

---

## Detailed Findings (Category 4 - Questionable)

Below are all 42 questionable instances that need review:

### 1. `` (Line )

**Function:** `()`

**Parameter:** ``

**Context:**
```php
includes/class-wp-mcp-ai-mesh-router.php-190-	 * @return array Selected peer configuration.
includes/class-wp-mcp-ai-mesh-router.php-191-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 2. `includes/class-wp-mcp-ai-mesh-router.php` (Line 192)

**Function:** `select_peer_ai_optimized()`

**Parameter:** `$context`

**Context:**
```php
includes/class-wp-mcp-ai-mesh-router.php:192:	protected static function select_peer_ai_optimized( $healthy_peers, $prompt, $hub_config, $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/class-wp-mcp-ai-mesh-router.php-193-		// Analyze prompt complexity.
includes/class-wp-mcp-ai-mesh-router.php-194-		$complexity_score = self::analyze_prompt_complexity( $prompt );
includes/class-wp-mcp-ai-mesh-router.php-195-
includes/class-wp-mcp-ai-mesh-router.php-196-		// Score each peer based on multiple factors.
includes/class-wp-mcp-ai-mesh-router.php-197-		$scored_peers = array();
--
includes/class-wp-mcp-ai-mesh-router.php-263-	 * @return array Selected peer configuration.
includes/class-wp-mcp-ai-mesh-router.php-264-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 3. `includes/class-wp-mcp-ai-mesh-router.php` (Line 265)

**Function:** `select_peer_round_robin()`

**Parameter:** `$hub_config`

**Context:**
```php
includes/class-wp-mcp-ai-mesh-router.php:265:	protected static function select_peer_round_robin( $healthy_peers, $hub_config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/class-wp-mcp-ai-mesh-router.php-266-		$stats      = get_option( self::ROUTING_STATS_OPTION, array() );
includes/class-wp-mcp-ai-mesh-router.php-267-		$last_index = isset( $stats['last_round_robin_index'] ) ? (int) $stats['last_round_robin_index'] : -1;
includes/class-wp-mcp-ai-mesh-router.php-268-
includes/class-wp-mcp-ai-mesh-router.php-269-		$next_index = ( $last_index + 1 ) % count( $healthy_peers );
includes/class-wp-mcp-ai-mesh-router.php-270-
--
includes/class-wp-mcp-ai-mesh-router.php-458-	 * @return array|WP_Error Response or error.
includes/class-wp-mcp-ai-mesh-router.php-459-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 4. `includes/admin/class-wp-mcp-ai-admin-settings-base.php` (Line 491)

**Function:** `filter_memory_max_file_bytes()`

**Parameter:** `$attachment_id`

**Context:**
```php
includes/admin/class-wp-mcp-ai-admin-settings-base.php:491:		public function filter_memory_max_file_bytes( $max_bytes, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/admin/class-wp-mcp-ai-admin-settings-base.php-492-			$settings = self::get_settings();
includes/admin/class-wp-mcp-ai-admin-settings-base.php-493-			if ( isset( $settings['memory_max_file_bytes'] ) && $settings['memory_max_file_bytes'] > 0 ) {
includes/admin/class-wp-mcp-ai-admin-settings-base.php-494-				return absint( $settings['memory_max_file_bytes'] );
includes/admin/class-wp-mcp-ai-admin-settings-base.php-495-			}
includes/admin/class-wp-mcp-ai-admin-settings-base.php-496-			return $max_bytes;
--
includes/admin/class-wp-mcp-ai-custom-filters-applicator.php-96-		 * @return int
includes/admin/class-wp-mcp-ai-custom-filters-applicator.php-97-		 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 5. `includes/admin/class-wp-mcp-ai-admin-settings.php` (Line 5474)

**Function:** `filter_memory_max_file_bytes()`

**Parameter:** `$attachment_id`

**Context:**
```php
includes/admin/class-wp-mcp-ai-admin-settings.php:5474:		public function filter_memory_max_file_bytes( $max_bytes, $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/admin/class-wp-mcp-ai-admin-settings.php-5475-			$settings = self::get_settings();
includes/admin/class-wp-mcp-ai-admin-settings.php-5476-			$limit    = isset( $settings['memory_max_file_bytes'] ) ? absint( $settings['memory_max_file_bytes'] ) : 0;
includes/admin/class-wp-mcp-ai-admin-settings.php-5477-
includes/admin/class-wp-mcp-ai-admin-settings.php-5478-			if ( $limit > 0 ) {
includes/admin/class-wp-mcp-ai-admin-settings.php-5479-				return $limit;
--
includes/class-wp-mcp-ai-rest-api-context-fix.php-151-	 * @return bool
includes/class-wp-mcp-ai-rest-api-context-fix.php-152-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 6. `includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php` (Line 264)

**Function:** `export_privacy_data()`

**Parameter:** `$user_id`

**Context:**
```php
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php:264:	protected function export_privacy_data( $user_id  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for user filtering. {
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-265-		return array();
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-266-	}
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-267-
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-268-	/**
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-269-	 * Erase privacy data for user.
--
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-276-	 * @return array Erasure result with 'items_removed', 'items_retained', 'messages'.
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-277-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 7. `includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php` (Line 278)

**Function:** `erase_privacy_data()`

**Parameter:** `$user_id`

**Context:**
```php
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php:278:	protected function erase_privacy_data( $user_id  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for user filtering. {
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-279-		return array(
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-280-			'items_removed'  => 0,
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-281-			'items_retained' => 0,
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-282-			'messages'       => array(),
includes/traits/trait-wp-mcp-ai-tool-wordpress-native.php-283-		);
--
includes/class-wp-mcp-ai-model-config.php-236-	 * @param array  $config Model configuration.
includes/class-wp-mcp-ai-model-config.php-237-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 8. `includes/class-wp-mcp-ai-agentic-workflow-optimizer.php` (Line 206)

**Function:** `maybe_compress_result()`

**Parameter:** `$result`

**Context:**
```php
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php:206:	public function maybe_compress_result( $content, $result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for content-type detection.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-207-		// Only compress if content is large enough.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-208-		if ( strlen( $content ) < self::COMPRESSION_THRESHOLD ) {
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-209-			return $content;
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-210-		}
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-211-
--
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-366-	 * @return array Array of iteration counts.
includes/class-wp-mcp-ai-agentic-workflow-optimizer.php-367-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 9. `includes/class-wp-mcp-ai-stdio-transport.php` (Line 509)

**Function:** `handle_prompts_list()`

**Parameter:** `$params`

**Context:**
```php
includes/class-wp-mcp-ai-stdio-transport.php:509:	protected function handle_prompts_list( $params ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for filtering.
includes/class-wp-mcp-ai-stdio-transport.php-510-		$prompts = array();
includes/class-wp-mcp-ai-stdio-transport.php-511-
includes/class-wp-mcp-ai-stdio-transport.php-512-		$query = new WP_Query(
includes/class-wp-mcp-ai-stdio-transport.php-513-			array(
includes/class-wp-mcp-ai-stdio-transport.php-514-				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
--
includes/assistants/class-wp-mcp-ai-assistant-cpt.php-116-		 * @param array $selected_tools Currently selected tool slugs.
includes/assistants/class-wp-mcp-ai-assistant-cpt.php-117-		 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 10. `includes/services/class-wp-mcp-ai-gemini-music-service.php` (Line 89)

**Function:** `generate_music()`

**Parameter:** `$prompt`

**Context:**
```php
includes/services/class-wp-mcp-ai-gemini-music-service.php:89:	public function generate_music( $prompt, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for advanced music options.
includes/services/class-wp-mcp-ai-gemini-music-service.php-90-		$prompt = trim( (string) $prompt );
includes/services/class-wp-mcp-ai-gemini-music-service.php-91-
includes/services/class-wp-mcp-ai-gemini-music-service.php-92-		if ( empty( $prompt ) ) {
includes/services/class-wp-mcp-ai-gemini-music-service.php-93-			return new WP_Error(
includes/services/class-wp-mcp-ai-gemini-music-service.php-94-				'wp_mcp_ai_empty_music_prompt',
--
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-178-	 * @return array Speculation status.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-179-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 11. `includes/services/class-wp-mcp-ai-tool-chain-predictor.php` (Line 372)

**Function:** `predict_from_patterns()`

**Parameter:** `$task`

**Context:**
```php
includes/services/class-wp-mcp-ai-tool-chain-predictor.php:372:	protected function predict_from_patterns( $patterns, $available_tools, $task ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for semantic matching.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-373-		if ( empty( $patterns ) ) {
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-374-			return array();
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-375-		}
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-376-
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-377-		// Get most frequent pattern.
--
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-541-	 * @return array Reordered chain.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-542-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 12. `includes/services/class-wp-mcp-ai-tool-chain-predictor.php` (Line 543)

**Function:** `reorder_for_data_flow()`

**Parameter:** `$dependencies`

**Context:**
```php
includes/services/class-wp-mcp-ai-tool-chain-predictor.php:543:	protected function reorder_for_data_flow( $tool_chain, $dependencies ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for topological sorting.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-544-		// For now, preserve original order as dependencies are built from it.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-545-		// More sophisticated topological sorting could be added here.
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-546-		return $tool_chain;
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-547-	}
includes/services/class-wp-mcp-ai-tool-chain-predictor.php-548-
--
includes/services/class-wp-mcp-ai-tool-service.php-175-	 * @return array List of tools.
includes/services/class-wp-mcp-ai-tool-service.php-176-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 13. `includes/services/class-wp-mcp-ai-tool-service.php` (Line 177)

**Function:** `get_available_tools()`

**Parameter:** `$tool`

**Context:**
```php
includes/services/class-wp-mcp-ai-tool-service.php:177:	public function get_available_tools( $assistant_id = null  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for assistant-specific logic. {
includes/services/class-wp-mcp-ai-tool-service.php-178-		$all_tools = $this->registry->get_tools();
includes/services/class-wp-mcp-ai-tool-service.php-179-		$tools     = array();
includes/services/class-wp-mcp-ai-tool-service.php-180-
includes/services/class-wp-mcp-ai-tool-service.php-181-		foreach ( $all_tools as $tool ) {
includes/services/class-wp-mcp-ai-tool-service.php-182-			if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
--
includes/services/class-wp-mcp-ai-tool-service.php-271-	 * @return array Tool statistics.
includes/services/class-wp-mcp-ai-tool-service.php-272-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 14. `includes/services/class-wp-mcp-ai-tool-service.php` (Line 273)

**Function:** `get_tool_statistics()`

**Parameter:** `$tool_slug`

**Context:**
```php
includes/services/class-wp-mcp-ai-tool-service.php:273:	public function get_tool_statistics( $tool_slug, $assistant_id = null  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for assistant-specific logic. {
includes/services/class-wp-mcp-ai-tool-service.php-274-		// This would integrate with usage tracking.
includes/services/class-wp-mcp-ai-tool-service.php-275-		// For now, return placeholder.
includes/services/class-wp-mcp-ai-tool-service.php-276-		return array(
includes/services/class-wp-mcp-ai-tool-service.php-277-			'tool'            => $tool_slug,
includes/services/class-wp-mcp-ai-tool-service.php-278-			'execution_count' => 0,
--
includes/services/class-wp-mcp-ai-efficiency-monitor.php-307-	 * @return array Resource usage metrics.
includes/services/class-wp-mcp-ai-efficiency-monitor.php-308-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 15. `includes/services/class-wp-mcp-ai-efficiency-monitor.php` (Line 309)

**Function:** `calculate_resource_usage_metrics()`

**Parameter:** `$system_load`

**Context:**
```php
includes/services/class-wp-mcp-ai-efficiency-monitor.php:309:	protected function calculate_resource_usage_metrics( $system_load ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for load-aware metrics.
includes/services/class-wp-mcp-ai-efficiency-monitor.php-310-		return array(
includes/services/class-wp-mcp-ai-efficiency-monitor.php-311-			'memory_utilization' => $this->get_memory_usage_percentage(),
includes/services/class-wp-mcp-ai-efficiency-monitor.php-312-			'api_rate_limits'    => $this->get_rate_limit_status(),
includes/services/class-wp-mcp-ai-efficiency-monitor.php-313-			'token_consumption'  => $this->get_token_usage(),
includes/services/class-wp-mcp-ai-efficiency-monitor.php-314-		);
--
includes/services/class-wp-mcp-ai-model-service.php-191-	 * @return array Model list.
includes/services/class-wp-mcp-ai-model-service.php-192-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 16. `includes/services/class-wp-mcp-ai-model-service.php` (Line 495)

**Function:** `get_cloudflare_models()`

**Parameter:** `$requires_multimodal`

**Context:**
```php
includes/services/class-wp-mcp-ai-model-service.php:495:	protected function get_cloudflare_models( $settings, $requires_vision, $requires_multimodal ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for capability filtering.
includes/services/class-wp-mcp-ai-model-service.php-496-		// Check if Cloudflare provider is enabled and configured.
includes/services/class-wp-mcp-ai-model-service.php-497-		if ( empty( $settings['enable_cloudflare'] ) || empty( $settings['cloudflare_api_token'] ) || empty( $settings['cloudflare_account_id'] ) ) {
includes/services/class-wp-mcp-ai-model-service.php-498-			return array();
includes/services/class-wp-mcp-ai-model-service.php-499-		}
includes/services/class-wp-mcp-ai-model-service.php-500-
--
includes/services/class-wp-mcp-ai-reasoning-controller.php-240-	 * @return float Score 0-1.
includes/services/class-wp-mcp-ai-reasoning-controller.php-241-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 17. `includes/services/class-wp-mcp-ai-reasoning-controller.php` (Line 529)

**Function:** `check_completeness()`

**Parameter:** `$task`

**Context:**
```php
includes/services/class-wp-mcp-ai-reasoning-controller.php:529:	protected function check_completeness( $reasoning_output, $task  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for task analysis. {
includes/services/class-wp-mcp-ai-reasoning-controller.php-530-		// Simple heuristic: check if all parts of task appear addressed.
includes/services/class-wp-mcp-ai-reasoning-controller.php-531-		if ( empty( $reasoning_output ) ) {
includes/services/class-wp-mcp-ai-reasoning-controller.php-532-			return 0.3;
includes/services/class-wp-mcp-ai-reasoning-controller.php-533-		}
includes/services/class-wp-mcp-ai-reasoning-controller.php-534-
--
includes/services/class-wp-mcp-ai-file-orchestration-service.php-389-	 * @return true|WP_Error True if valid, WP_Error otherwise.
includes/services/class-wp-mcp-ai-file-orchestration-service.php-390-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 18. `includes/services/class-wp-mcp-ai-file-orchestration-service.php` (Line 391)

**Function:** `validate_upload_inputs()`

**Parameter:** `$options`

**Context:**
```php
includes/services/class-wp-mcp-ai-file-orchestration-service.php:391:	protected function validate_upload_inputs( $file_path, $mime_type, array $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for option-based validation.
includes/services/class-wp-mcp-ai-file-orchestration-service.php-392-		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
includes/services/class-wp-mcp-ai-file-orchestration-service.php-393-			return new WP_Error(
includes/services/class-wp-mcp-ai-file-orchestration-service.php-394-				'wp_mcp_ai_file_not_found',
includes/services/class-wp-mcp-ai-file-orchestration-service.php-395-				__( 'File not found on server.', 'mcp-ai-wpoos' ),
includes/services/class-wp-mcp-ai-file-orchestration-service.php-396-				array( 'status' => 404 )
--
includes/services/class-wp-mcp-ai-file-orchestration-service.php-516-	 * @param array  $options   Options.
includes/services/class-wp-mcp-ai-file-orchestration-service.php-517-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 19. `includes/services/class-wp-mcp-ai-file-orchestration-service.php` (Line 518)

**Function:** `log_upload_start()`

**Parameter:** `$options`

**Context:**
```php
includes/services/class-wp-mcp-ai-file-orchestration-service.php:518:	protected function log_upload_start( $file_path, $mime_type, array $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for option logging.
includes/services/class-wp-mcp-ai-file-orchestration-service.php-519-		WP_MCP_AI_Logger::log_event(
includes/services/class-wp-mcp-ai-file-orchestration-service.php-520-			strtolower( $this->provider_name ) . '_file_upload',
includes/services/class-wp-mcp-ai-file-orchestration-service.php-521-			sprintf( 'Uploading file to %s File API.', $this->provider_name ),
includes/services/class-wp-mcp-ai-file-orchestration-service.php-522-			array(
includes/services/class-wp-mcp-ai-file-orchestration-service.php-523-				'file_name' => basename( $file_path ),
--
includes/services/class-wp-mcp-ai-code-optimizer.php-250-	 * @return array Dependencies.
includes/services/class-wp-mcp-ai-code-optimizer.php-251-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 20. `includes/services/class-wp-mcp-ai-code-optimizer.php` (Line 252)

**Function:** `extract_dependencies()`

**Parameter:** `$task`

**Context:**
```php
includes/services/class-wp-mcp-ai-code-optimizer.php:252:	protected function extract_dependencies( $code, $task ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for task-specific extraction.
includes/services/class-wp-mcp-ai-code-optimizer.php-253-		$dependencies = array();
includes/services/class-wp-mcp-ai-code-optimizer.php-254-
includes/services/class-wp-mcp-ai-code-optimizer.php-255-		// Extract use statements (PHP).
includes/services/class-wp-mcp-ai-code-optimizer.php-256-		preg_match_all( '/use\s+([^;]+);/', $code, $use_matches );
includes/services/class-wp-mcp-ai-code-optimizer.php-257-		if ( ! empty( $use_matches[1] ) ) {
--
includes/services/class-wp-mcp-ai-code-optimizer.php-275-	 * @return array Compressed code and structure.
includes/services/class-wp-mcp-ai-code-optimizer.php-276-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 21. `includes/services/class-wp-mcp-ai-code-optimizer.php` (Line 277)

**Function:** `compress_boilerplate()`

**Parameter:** `$dependencies`

**Context:**
```php
includes/services/class-wp-mcp-ai-code-optimizer.php:277:	protected function compress_boilerplate( $sections, $dependencies ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for dependency optimization.
includes/services/class-wp-mcp-ai-code-optimizer.php-278-		$compressed_code = implode( "\n\n", $sections );
includes/services/class-wp-mcp-ai-code-optimizer.php-279-
includes/services/class-wp-mcp-ai-code-optimizer.php-280-		// Remove excessive whitespace.
includes/services/class-wp-mcp-ai-code-optimizer.php-281-		$compressed_code = preg_replace( '/\n{3,}/', "\n\n", $compressed_code );
includes/services/class-wp-mcp-ai-code-optimizer.php-282-
--
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1090-	 * @return array|null Agent data or null.
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1091-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 22. `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php` (Line 1092)

**Function:** `find_profession_agent_for_role()`

**Parameter:** `$role`

**Context:**
```php
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php:1092:	protected function find_profession_agent_for_role( $role, $task_requirements = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for role filtering.
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1093-		// Query professions with agent_role meta.
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1094-		$args = array(
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1095-			'post_type'      => 'mcp_ai_profession',
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1096-			'post_status'    => 'publish',
includes/services/class-wp-mcp-ai-agent-team-orchestrator.php-1097-			'posts_per_page' => 10,
--
includes/services/class-wp-mcp-ai-tool-load-balancer.php-242-	 * @return array Ranked tool recommendations with confidence scores.
includes/services/class-wp-mcp-ai-tool-load-balancer.php-243-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 23. `includes/services/class-wp-mcp-ai-file-service.php` (Line 251)

**Function:** `handle_file_download()`

**Parameter:** `$attachment_id`

**Context:**
```php
includes/services/class-wp-mcp-ai-file-service.php:251:	public function handle_file_download( $attachment_id, $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for context-aware downloading.
includes/services/class-wp-mcp-ai-file-service.php-252-		$attachment = get_post( $attachment_id );
includes/services/class-wp-mcp-ai-file-service.php-253-
includes/services/class-wp-mcp-ai-file-service.php-254-		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
includes/services/class-wp-mcp-ai-file-service.php-255-			return new WP_Error(
includes/services/class-wp-mcp-ai-file-service.php-256-				'wp_mcp_ai_invalid_attachment',
--
includes/services/class-wp-mcp-ai-tool-profiler.php-302-	 * @return array Recommendations.
includes/services/class-wp-mcp-ai-tool-profiler.php-303-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 24. `includes/services/class-wp-mcp-ai-tool-profiler.php` (Line 304)

**Function:** `generate_recommendations()`

**Parameter:** `$tool_slug`

**Context:**
```php
includes/services/class-wp-mcp-ai-tool-profiler.php:304:	protected function generate_recommendations( $executions, $tool_slug  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for tool-specific profiling. {
includes/services/class-wp-mcp-ai-tool-profiler.php-305-		$performance = $this->analyze_performance( $executions );
includes/services/class-wp-mcp-ai-tool-profiler.php-306-
includes/services/class-wp-mcp-ai-tool-profiler.php-307-		$recommendations = array(
includes/services/class-wp-mcp-ai-tool-profiler.php-308-			'best_practices' => array(),
includes/services/class-wp-mcp-ai-tool-profiler.php-309-			'configuration'  => array(),
--
includes/services/class-wp-mcp-ai-tool-profiler.php-334-	 * @return array Task features.
includes/services/class-wp-mcp-ai-tool-profiler.php-335-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 25. `includes/class-wp-mcp-ai-job-notifier.php` (Line 371)

**Function:** `get_sse_event_name_for_job()`

**Parameter:** `$event_type`

**Context:**
```php
includes/class-wp-mcp-ai-job-notifier.php:371:	protected static function get_sse_event_name_for_job( $job_id, $event_type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for event type routing.
includes/class-wp-mcp-ai-job-notifier.php-372-		// Check if this is a crawl4ai job.
includes/class-wp-mcp-ai-job-notifier.php-373-		if ( strpos( $job_id, 'crawl' ) === 0 || strpos( $job_id, 'crawl4ai' ) === 0 ) {
includes/class-wp-mcp-ai-job-notifier.php-374-			return 'crawl4ai_job_status_update';
includes/class-wp-mcp-ai-job-notifier.php-375-		}
includes/class-wp-mcp-ai-job-notifier.php-376-
--
includes/class-wp-mcp-ai-federation-directory-rest.php-237-	 * @return bool|WP_Error True if user is logged in, WP_Error otherwise.
includes/class-wp-mcp-ai-federation-directory-rest.php-238-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 26. `includes/class-wp-mcp-ai-nefarious-usage-monitor.php` (Line 269)

**Function:** `monitor_chat_request()`

**Parameter:** `$request_data`

**Context:**
```php
includes/class-wp-mcp-ai-nefarious-usage-monitor.php:269:		public function monitor_chat_request( $messages, $request_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for request context analysis.
includes/class-wp-mcp-ai-nefarious-usage-monitor.php-270-			if ( ! $this->enabled ) {
includes/class-wp-mcp-ai-nefarious-usage-monitor.php-271-				return;
includes/class-wp-mcp-ai-nefarious-usage-monitor.php-272-			}
includes/class-wp-mcp-ai-nefarious-usage-monitor.php-273-
includes/class-wp-mcp-ai-nefarious-usage-monitor.php-274-			// Ensure messages is an array to prevent foreach errors.
--
includes/rest/class-wp-mcp-ai-asset-inventory-rest.php-121-	 * @return WP_REST_Response|WP_Error Response object or error.
includes/rest/class-wp-mcp-ai-asset-inventory-rest.php-122-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 27. `includes/class-wp-mcp-ai-job-queue-manager.php` (Line 397)

**Function:** `mark_job_complete()`

**Parameter:** `$result`

**Context:**
```php
includes/class-wp-mcp-ai-job-queue-manager.php:397:	protected static function mark_job_complete( $job_id, $result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for result logging.
includes/class-wp-mcp-ai-job-queue-manager.php-398-		$queue = self::get_queue_state();
includes/class-wp-mcp-ai-job-queue-manager.php-399-
includes/class-wp-mcp-ai-job-queue-manager.php-400-		if ( isset( $queue[ $job_id ] ) ) {
includes/class-wp-mcp-ai-job-queue-manager.php-401-			unset( $queue[ $job_id ] );
includes/class-wp-mcp-ai-job-queue-manager.php-402-		}
--
includes/class-wp-mcp-ai-rest-mcp-methods.php-218-	 * @return array|WP_Error
includes/class-wp-mcp-ai-rest-mcp-methods.php-219-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 28. `includes/class-wp-mcp-ai-openai-client.php` (Line 4341)

**Function:** `count_tokens()`

**Parameter:** `$messages`

**Context:**
```php
includes/class-wp-mcp-ai-openai-client.php:4341:		public function count_tokens( array $messages, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for model-specific token counting.
includes/class-wp-mcp-ai-openai-client.php-4342-			// For OpenAI, we don't have a direct token counting API endpoint,.
includes/class-wp-mcp-ai-openai-client.php-4343-
includes/class-wp-mcp-ai-openai-client.php-4344-			// so we use estimation based on character count.
includes/class-wp-mcp-ai-openai-client.php-4345-			// This is a reasonable heuristic: ~4 characters per token for English text.
includes/class-wp-mcp-ai-openai-client.php-4346-
--
includes/class-wp-mcp-ai-tool-recommendations.php-590-	 * @return string Explanation of recommendation.
includes/class-wp-mcp-ai-tool-recommendations.php-591-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 29. `includes/class-wp-mcp-ai-tool-recommendations.php` (Line 592)

**Function:** `get_recommendation_reason()`

**Parameter:** `$tool_slug`

**Context:**
```php
includes/class-wp-mcp-ai-tool-recommendations.php:592:	protected static function get_recommendation_reason( $category, $tool_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for tool-specific reasons.
includes/class-wp-mcp-ai-tool-recommendations.php-593-		$reasons = array(
includes/class-wp-mcp-ai-tool-recommendations.php-594-			'high_resource'      => __( 'This tool performs complex operations or generates large outputs, requiring higher token limits for optimal performance.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations.php-595-			'medium_resource'    => __( 'This tool handles moderate complexity operations. A balanced token limit provides good performance without excessive resource usage.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations.php-596-			'low_resource'       => __( 'This tool performs simple operations with minimal token requirements. Standard limits are sufficient.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations.php-597-			'image_generation'   => __( 'Image generation tools have specialized requirements. Let the assistant choose the best model for image tasks.', 'mcp-ai-wpoos' ),
--
includes/cli/class-wp-mcp-ai-cli-dlq.php-189-	 * @param array $assoc_args Associative arguments.
includes/cli/class-wp-mcp-ai-cli-dlq.php-190-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 30. `includes/cli/class-wp-mcp-ai-cli-dlq.php` (Line 191)

**Function:** `retry()`

**Parameter:** `$assoc_args`

**Context:**
```php
includes/cli/class-wp-mcp-ai-cli-dlq.php:191:	public function retry( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
includes/cli/class-wp-mcp-ai-cli-dlq.php-192-		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
includes/cli/class-wp-mcp-ai-cli-dlq.php-193-			WP_CLI::error( 'Dead Letter Queue class not found.' );
includes/cli/class-wp-mcp-ai-cli-dlq.php-194-		}
includes/cli/class-wp-mcp-ai-cli-dlq.php-195-
includes/cli/class-wp-mcp-ai-cli-dlq.php-196-		if ( empty( $args[0] ) ) {
--
includes/cli/class-wp-mcp-ai-cli-dlq.php-232-	 * @param array $assoc_args Associative arguments.
includes/cli/class-wp-mcp-ai-cli-dlq.php-233-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 31. `includes/cli/class-wp-mcp-ai-cli-dlq.php` (Line 234)

**Function:** `dismiss()`

**Parameter:** `$assoc_args`

**Context:**
```php
includes/cli/class-wp-mcp-ai-cli-dlq.php:234:	public function dismiss( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
includes/cli/class-wp-mcp-ai-cli-dlq.php-235-		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
includes/cli/class-wp-mcp-ai-cli-dlq.php-236-			WP_CLI::error( 'Dead Letter Queue class not found.' );
includes/cli/class-wp-mcp-ai-cli-dlq.php-237-		}
includes/cli/class-wp-mcp-ai-cli-dlq.php-238-
includes/cli/class-wp-mcp-ai-cli-dlq.php-239-		if ( empty( $args[0] ) ) {
--
includes/cli/class-wp-mcp-ai-cli-sla.php-281-	 * @param array $assoc_args Associative arguments.
includes/cli/class-wp-mcp-ai-cli-sla.php-282-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 32. `includes/cli/class-wp-mcp-ai-cli-sla.php` (Line 283)

**Function:** `enable()`

**Parameter:** `$assoc_args`

**Context:**
```php
includes/cli/class-wp-mcp-ai-cli-sla.php:283:	public function enable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
includes/cli/class-wp-mcp-ai-cli-sla.php-284-		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
includes/cli/class-wp-mcp-ai-cli-sla.php-285-		$settings['sla_prioritization_enabled'] = true;
includes/cli/class-wp-mcp-ai-cli-sla.php-286-		update_option( 'wp_mcp_ai_settings', $settings );
includes/cli/class-wp-mcp-ai-cli-sla.php-287-
includes/cli/class-wp-mcp-ai-cli-sla.php-288-		WP_CLI::success( 'SLA-based prioritization enabled.' );
--
includes/cli/class-wp-mcp-ai-cli-sla.php-300-	 * @param array $assoc_args Associative arguments.
includes/cli/class-wp-mcp-ai-cli-sla.php-301-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 33. `includes/cli/class-wp-mcp-ai-cli-sla.php` (Line 302)

**Function:** `disable()`

**Parameter:** `$assoc_args`

**Context:**
```php
includes/cli/class-wp-mcp-ai-cli-sla.php:302:	public function disable( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for CLI flags.
includes/cli/class-wp-mcp-ai-cli-sla.php-303-		$settings                               = get_option( 'wp_mcp_ai_settings', array() );
includes/cli/class-wp-mcp-ai-cli-sla.php-304-		$settings['sla_prioritization_enabled'] = false;
includes/cli/class-wp-mcp-ai-cli-sla.php-305-		update_option( 'wp_mcp_ai_settings', $settings );
includes/cli/class-wp-mcp-ai-cli-sla.php-306-
includes/cli/class-wp-mcp-ai-cli-sla.php-307-		WP_CLI::success( 'SLA-based prioritization disabled.' );
--
includes/blocks/class-wp-mcp-ai-assistant-builder-blocks.php-91-	 * @return array Modified categories.
includes/blocks/class-wp-mcp-ai-assistant-builder-blocks.php-92-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 34. `includes/class-wp-mcp-ai-tool-recommendations-backup.php` (Line 330)

**Function:** `get_recommendation_reason()`

**Parameter:** `$tool_slug`

**Context:**
```php
includes/class-wp-mcp-ai-tool-recommendations-backup.php:330:	protected static function get_recommendation_reason( $category, $tool_slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for tool-specific reasons.
includes/class-wp-mcp-ai-tool-recommendations-backup.php-331-		$reasons = array(
includes/class-wp-mcp-ai-tool-recommendations-backup.php-332-			'high_resource'     => __( 'This tool performs complex operations or generates large outputs, requiring higher token limits for optimal performance.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations-backup.php-333-			'medium_resource'   => __( 'This tool handles moderate complexity operations. A balanced token limit provides good performance without excessive resource usage.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations-backup.php-334-			'low_resource'      => __( 'This tool performs simple operations with minimal token requirements. Standard limits are sufficient.', 'mcp-ai-wpoos' ),
includes/class-wp-mcp-ai-tool-recommendations-backup.php-335-			'image_generation'  => __( 'Image generation tools have specialized requirements. Let the assistant choose the best model for image tasks.', 'mcp-ai-wpoos' ),
--
includes/class-wp-mcp-ai-security-audit.php-469-	 * @return void
includes/class-wp-mcp-ai-security-audit.php-470-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 35. `includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php` (Line 69)

**Function:** `maybe_enrich_payload()`

**Parameter:** `$request`

**Context:**
```php
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php:69:		public static function maybe_enrich_payload( $payload, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for request context.
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-70-			if ( ! self::is_enabled() || ! is_array( $payload ) ) {
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-71-				return $payload;
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-72-			}
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-73-
includes/integrations/class-wp-mcp-ai-integration-wordpress-gravatar.php-74-			$subject = isset( $payload['sub'] ) ? (string) $payload['sub'] : '';
--
includes/integrations/class-wp-mcp-ai-meta-oauth-handler.php-297-		 * @return string[]
includes/integrations/class-wp-mcp-ai-meta-oauth-handler.php-298-		 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 36. `includes/integrations/class-wp-mcp-ai-integration-auth0-github.php` (Line 69)

**Function:** `maybe_enrich_payload()`

**Parameter:** `$request`

**Context:**
```php
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php:69:		public static function maybe_enrich_payload( $payload, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for request context.
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php-70-			if ( ! self::is_enabled() || ! is_array( $payload ) ) {
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php-71-				return $payload;
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php-72-			}
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php-73-
includes/integrations/class-wp-mcp-ai-integration-auth0-github.php-74-			$subject = isset( $payload['sub'] ) ? (string) $payload['sub'] : '';
--
includes/class-wp-mcp-ai-enhanced-token-tracking.php-578-	 * @return string Inferred Gemini model.
includes/class-wp-mcp-ai-enhanced-token-tracking.php-579-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 37. `includes/class-wp-mcp-ai-enhanced-token-tracking.php` (Line 580)

**Function:** `infer_gemini_model_from_tool()`

**Parameter:** `$old_model`

**Context:**
```php
includes/class-wp-mcp-ai-enhanced-token-tracking.php:580:	private static function infer_gemini_model_from_tool( $tool, $old_model ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for model migration logic.
includes/class-wp-mcp-ai-enhanced-token-tracking.php-581-		// Image-related Gemini tools use the Gemini image model.
includes/class-wp-mcp-ai-enhanced-token-tracking.php-582-		if ( in_array( $tool, array( 'generate_gemini_image', 'edit_gemini_image' ), true ) ) {
includes/class-wp-mcp-ai-enhanced-token-tracking.php-583-			return 'gemini-2.5-flash-image';
includes/class-wp-mcp-ai-enhanced-token-tracking.php-584-		}
includes/class-wp-mcp-ai-enhanced-token-tracking.php-585-
--
includes/class-wp-mcp-ai-jetengine-tool-handlers.php-301-	 * @return bool
includes/class-wp-mcp-ai-jetengine-tool-handlers.php-302-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 38. `includes/class-wp-mcp-ai-pattern-workflow-templates.php` (Line 48)

**Function:** `get_workflow_template()`

**Parameter:** `$pattern_slug`

**Context:**
```php
includes/class-wp-mcp-ai-pattern-workflow-templates.php:48:	public function get_workflow_template( $pattern_slug, $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for context-aware customization.
includes/class-wp-mcp-ai-pattern-workflow-templates.php-49-		$templates = $this->get_all_templates();
includes/class-wp-mcp-ai-pattern-workflow-templates.php-50-		return isset( $templates[ $pattern_slug ] ) ? $templates[ $pattern_slug ] : null;
includes/class-wp-mcp-ai-pattern-workflow-templates.php-51-	}
includes/class-wp-mcp-ai-pattern-workflow-templates.php-52-
includes/class-wp-mcp-ai-pattern-workflow-templates.php-53-	/**
--
includes/class-wp-mcp-ai-queue-manager.php-138-	 * @return string Execution mode constant.
includes/class-wp-mcp-ai-queue-manager.php-139-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 39. `includes/class-wp-mcp-ai-queue-manager.php` (Line 140)

**Function:** `get_execution_mode()`

**Parameter:** `$context`

**Context:**
```php
includes/class-wp-mcp-ai-queue-manager.php:140:	public function get_execution_mode( $tool_name, array $arguments, array $context ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/class-wp-mcp-ai-queue-manager.php-141-		$tool = $this->get_tool_registry()->get_tool( $tool_name );
includes/class-wp-mcp-ai-queue-manager.php-142-
includes/class-wp-mcp-ai-queue-manager.php-143-		if ( null === $tool ) {
includes/class-wp-mcp-ai-queue-manager.php-144-			return self::MODE_SYNC;
includes/class-wp-mcp-ai-queue-manager.php-145-		}
--
includes/class-wp-mcp-ai-queue-manager.php-201-	 * @return int Estimated time in milliseconds.
includes/class-wp-mcp-ai-queue-manager.php-202-	 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 40. `includes/class-wp-mcp-ai-queue-manager.php` (Line 203)

**Function:** `estimate_execution_time()`

**Parameter:** `$arguments`

**Context:**
```php
includes/class-wp-mcp-ai-queue-manager.php:203:	private function estimate_execution_time( $tool_name, array $arguments ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
includes/class-wp-mcp-ai-queue-manager.php-204-		// Check for cached estimates.
includes/class-wp-mcp-ai-queue-manager.php-205-		$cache_key = 'wp_mcp_ai_tool_time_' . md5( $tool_name );
includes/class-wp-mcp-ai-queue-manager.php-206-		$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai' );
includes/class-wp-mcp-ai-queue-manager.php-207-
includes/class-wp-mcp-ai-queue-manager.php-208-		if ( false !== $cached ) {
--
includes/class-wp-mcp-ai-response-attachments.php-43-		 * @param WP_REST_Request $request      REST request instance.
includes/class-wp-mcp-ai-response-attachments.php-44-		 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 41. `includes/class-wp-mcp-ai-response-attachments.php` (Line 45)

**Function:** `handle_chat_response()`

**Parameter:** `$request`

**Context:**
```php
includes/class-wp-mcp-ai-response-attachments.php:45:		public static function handle_chat_response( $assistant_id, $response, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for request context.
includes/class-wp-mcp-ai-response-attachments.php-46-			if ( empty( $response ) || ! is_array( $response ) ) {
includes/class-wp-mcp-ai-response-attachments.php-47-				return;
includes/class-wp-mcp-ai-response-attachments.php-48-			}
includes/class-wp-mcp-ai-response-attachments.php-49-
includes/class-wp-mcp-ai-response-attachments.php-50-			$segments = self::collect_file_segments_from_response( $response );
--
includes/class-wp-mcp-ai-logger.php-668-		 * @return string
includes/class-wp-mcp-ai-logger.php-669-		 */
```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

### 42. `includes/class-wp-mcp-ai-logger.php` (Line 670)

**Function:** `redact_sensitive_value()`

**Parameter:** `$value`

**Context:**
```php
includes/class-wp-mcp-ai-logger.php:670:		protected static function redact_sensitive_value( $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for context-aware redaction.
includes/class-wp-mcp-ai-logger.php-671-			return '[redacted]';
includes/class-wp-mcp-ai-logger.php-672-		}
includes/class-wp-mcp-ai-logger.php-673-
includes/class-wp-mcp-ai-logger.php-674-		/**
includes/class-wp-mcp-ai-logger.php-675-		 * Determine if the entry should be stored for quick access in the admin UI.
___BEGIN___COMMAND_DONE_MARKER___0

```

**Recommendation:** Review this parameter and determine if it should be implemented or removed.

---

