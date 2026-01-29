<?php
/**
 * Profession Orchestration Seeder.
 *
 * Seeds orchestration configurations for existing professions based on
 * research-backed heuristics and best practices.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds orchestration configurations for professions.
 *
 * Implements automatic agent role assignment using category and keyword heuristics
 * based on 2026 multi-agent orchestration best practices research.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Profession_Orchestration_Seeder {

	/**
	 * Version of the seeder (for tracking migrations).
	 */
	const SEEDER_VERSION = '1.0.0';

	/**
	 * Option key for tracking seeder version.
	 */
	const VERSION_OPTION = 'wp_mcp_ai_profession_orchestration_version';

	/**
	 * Seed all orchestration configurations.
	 *
	 * @param bool $force Force re-seeding even if already done.
	 * @return array Results with counts.
	 */
	public function seed_all( $force = false ) {
		$current_version = get_option( self::VERSION_OPTION, '0.0.0' );

		if ( ! $force && version_compare( $current_version, self::SEEDER_VERSION, '>=' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Orchestration configurations already seeded. Use --force to re-seed.', 'mcp-ai-wpoos' ),
			);
		}

		$results = array(
			'agent_roles_assigned'  => 0,
			'task_patterns_created' => 0,
			'errors'                => array(),
		);

		// Seed agent roles.
		$role_result                     = $this->seed_agent_roles();
		$results['agent_roles_assigned'] = $role_result['count'];
		$results['errors']               = array_merge( $results['errors'], $role_result['errors'] );

		// Seed task patterns for top professions.
		$pattern_result                   = $this->seed_task_patterns();
		$results['task_patterns_created'] = $pattern_result['count'];
		$results['errors']                = array_merge( $results['errors'], $pattern_result['errors'] );

		// Update version.
		update_option( self::VERSION_OPTION, self::SEEDER_VERSION );

		return array_merge(
			array(
				'success' => true,
				'message' => sprintf(
					/* translators: 1: roles assigned, 2: patterns created */
					__( 'Seeded %1$d agent roles and %2$d task patterns.', 'mcp-ai-wpoos' ),
					$results['agent_roles_assigned'],
					$results['task_patterns_created']
				),
			),
			$results
		);
	}

	/**
	 * Assign default agent roles based on profession characteristics.
	 *
	 * Uses category-based and keyword-based heuristics from research.
	 *
	 * @return array Results with count and errors.
	 */
	public function seed_agent_roles() {
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		$seeded = 0;
		$errors = array();

		foreach ( $professions as $profession ) {
			try {
				$role_data = $this->determine_agent_role( $profession );

				// Handle both single role (backward compatibility) and multi-role return.
				if ( is_array( $role_data ) ) {
					$primary_role    = $role_data['primary'];
					$secondary_roles = isset( $role_data['secondary'] ) ? $role_data['secondary'] : array();
				} else {
					$primary_role    = $role_data;
					$secondary_roles = array();
				}

				update_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_AGENT_ROLE, $primary_role );
				update_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_AGENT_SECONDARY_ROLES, wp_json_encode( $secondary_roles ) );
				++$seeded;

				// Batch processing - flush cache every 50 items.
				if ( 0 === $seeded % 50 ) {
					wp_cache_flush();
				}
			} catch ( Exception $e ) {
				$errors[] = sprintf(
					/* translators: 1: profession title, 2: error message */
					__( 'Failed to assign role to %1$s: %2$s', 'mcp-ai-wpoos' ),
					$profession->post_title,
					$e->getMessage()
				);
			}
		}

		return array(
			'count'  => $seeded,
			'errors' => $errors,
		);
	}

	/**
	 * Determine agent role based on profession attributes.
	 *
	 * Implements industry-standard multi-agent orchestration patterns based on:
	 * - DeepSeek V4 agent role taxonomy
	 * - AutoGen multi-agent framework best practices
	 * - MetaGPT software development agent patterns
	 *
	 * Role Definitions (Industry Standard):
	 * - PLANNER: Strategic planning, coordination, workflow design, resource allocation
	 * - EXECUTOR: Implementation, technical execution, hands-on work, building/creating
	 * - CRITIC: Validation, review, quality assurance, testing, compliance checking
	 * - SPECIALIST: Domain expertise requiring deep knowledge (legal, medical, financial, scientific)
	 * - GENERALIST: Multi-domain tasks, general assistance, broad capabilities
	 *
	 * Multi-Role Support (New):
	 * Some professions naturally combine roles (e.g., QA Engineer = Critic + Planner).
	 * Returns array with 'primary' and 'secondary' roles when applicable.
	 *
	 * @param WP_Post $profession Profession post object.
	 * @return string|array Single role string OR array with 'primary' and 'secondary' keys.
	 */
	protected function determine_agent_role( $profession ) {
		$category  = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
		$expertise = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
		$title     = strtolower( $profession->post_title );

		if ( ! is_array( $expertise ) ) {
			$expertise = array();
		}

		// Convert expertise to lowercase for matching.
		$expertise_lower = array_map( 'strtolower', $expertise );

		// Track potential roles for multi-role professions.
		$matched_roles = array();

		// 1. Check CRITIC capabilities.
		if ( $this->has_keywords(
			$title,
			array(
				// Quality Assurance & Testing
				'qa engineer',
				'quality assurance',
				'quality engineer',
				'tester',
				'test engineer',
				// Editorial & Review
				'editor',
				'reviewer',
				'proofreader',
				'copy editor',
				// Audit & Compliance
				'auditor',
				'inspector',
				'compliance officer',
				'validator',
				// Evaluation & Assessment
				'evaluator',
				'assessor',
				'judge',
				'critic',
			)
		) ||
			$this->has_keywords(
				$expertise_lower,
				array(
					'quality assurance',
					'qa',
					'testing',
					'test automation',
					'editing',
					'reviewing',
					'proofreading',
					'validation',
					'inspection',
					'audit',
					'compliance',
					'quality control',
					'peer review',
				)
			) ) {
			$matched_roles[] = 'critic';
		}

		// 2. Check SPECIALIST capabilities.
		if ( in_array( $category, array( 'legal', 'healthcare', 'financial', 'scientific', 'regulatory' ), true ) ||
			$this->has_keywords(
				$title,
				array(
					// Legal
					'attorney',
					'lawyer',
					'paralegal',
					'legal advisor',
					'judge',
					// Medical/Healthcare
					'doctor',
					'physician',
					'surgeon',
					'nurse',
					'dentist',
					'veterinarian',
					'pharmacist',
					'therapist',
					'psychologist',
					'psychiatrist',
					// Financial
					'accountant',
					'financial advisor',
					'tax advisor',
					'bookkeeper',
					// Scientific/Research
					'scientist',
					'researcher',
					'physicist',
					'chemist',
					'biologist',
					'geologist',
					'meteorologist',
					'oceanographer',
					'toxicologist',
					// Regulatory/Compliance
					'regulatory affairs',
					'compliance specialist',
					'drug safety',
				)
			) ||
			$this->has_keywords(
				$expertise_lower,
				array(
					'legal',
					'law',
					'litigation',
					'medical',
					'healthcare',
					'clinical',
					'pharmacy',
					'financial',
					'accounting',
					'taxation',
					'scientific research',
					'laboratory',
					'clinical trials',
					'regulatory',
					'compliance',
					'pharmaceutical',
				)
			) ) {
			$matched_roles[] = 'specialist';
		}

		// 3. Check PLANNER capabilities.
		if ( $this->has_keywords(
			$title,
			array(
				// Project/Product Management
				'project manager',
				'product manager',
				'program manager',
				'scrum master',
				// Strategic Planning
				'planner',
				'strategist',
				'strategic planner',
				'urban planner',
				'event planner',
				// Coordination
				'coordinator',
				'logistics coordinator',
				'research coordinator',
				// Architecture (system design, not implementation)
				'architect',
				'cloud architect',
				'solutions architect',
				'systems architect',
				'enterprise architect',
				'landscape architect',
				// Management/Leadership
				'director',
				'manager',
				'administrator',
				'supervisor',
			)
		) ||
			$this->has_keywords(
				$expertise_lower,
				array(
					'project management',
					'product management',
					'program management',
					'planning',
					'strategy',
					'strategic planning',
					'coordination',
					'logistics',
					'architecture',
					'system design',
					'solution design',
					'management',
					'administration',
					'leadership',
				)
			) ||
			'advisory' === $category ) {
			$matched_roles[] = 'planner';
		}

		// 4. Check EXECUTOR capabilities.
		if ( in_array( $category, array( 'technical', 'creative', 'trades', 'operations' ), true ) ||
			$this->has_keywords(
				$title,
				array(
					// Software Development
					'developer',
					'programmer',
					'coder',
					'software engineer',
					// Engineering (implementation)
					'engineer',
					'drafter',
					'technician',
					'mechanic',
					// Creative Execution
					'designer',
					'artist',
					'photographer',
					'videographer',
					'animator',
					'editor',
					'producer',
					'cinematographer',
					// Trades/Crafts
					'electrician',
					'plumber',
					'carpenter',
					'welder',
					'mason',
					'machinist',
					'painter',
					'roofer',
					// Operations
					'operator',
					'driver',
					'pilot',
					'captain',
				)
			) ||
			$this->has_keywords(
				$expertise_lower,
				array(
					'development',
					'programming',
					'coding',
					'software development',
					'engineering',
					'technical implementation',
					'design',
					'creative',
					'multimedia production',
					'construction',
					'fabrication',
					'installation',
					'operations',
					'execution',
				)
			) ) {
			$matched_roles[] = 'executor';
		}

		// Determine primary and secondary roles based on matches.
		if ( empty( $matched_roles ) ) {
			return 'generalist';
		} elseif ( count( $matched_roles ) === 1 ) {
			return $matched_roles[0];
		} else {
			// Multi-role profession - apply priority order.
			// Priority: Specialist > Critic > Planner > Executor.
			$priority = array( 'specialist', 'critic', 'planner', 'executor' );
			$sorted   = array();

			foreach ( $priority as $role ) {
				if ( in_array( $role, $matched_roles, true ) ) {
					$sorted[] = $role;
				}
			}

			return array(
				'primary'   => $sorted[0],
				'secondary' => array_slice( $sorted, 1 ),
			);
		}
	}

	/**
	 * Check if text contains any of the specified keywords.
	 *
	 * @param string|array $haystack Text or array to search in.
	 * @param array        $keywords Keywords to search for.
	 * @return bool True if any keyword found.
	 */
	protected function has_keywords( $haystack, $keywords ) {
		if ( is_array( $haystack ) ) {
			$haystack = implode( ' ', $haystack );
		}

		$haystack = strtolower( $haystack );

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $haystack, strtolower( $keyword ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Seed task patterns for top professions.
	 *
	 * Creates workflow templates for commonly used professions.
	 *
	 * @return array Results with count and errors.
	 */
	public function seed_task_patterns() {
		$patterns = $this->get_default_task_patterns();
		$seeded   = 0;
		$errors   = array();

		foreach ( $patterns as $profession_slug => $pattern_config ) {
			$profession = get_page_by_path( $profession_slug, OBJECT, 'mcp_ai_profession' );

			if ( ! $profession ) {
				$errors[] = sprintf(
					/* translators: %s: profession slug */
					__( 'Profession not found: %s', 'mcp-ai-wpoos' ),
					$profession_slug
				);
				continue;
			}

			update_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_TASK_PATTERNS, wp_json_encode( $pattern_config ) );
			++$seeded;
		}

		return array(
			'count'  => $seeded,
			'errors' => $errors,
		);
	}

	/**
	 * Get default task patterns for common professions.
	 *
	 * @return array Profession slug => pattern configuration.
	 */
	protected function get_default_task_patterns() {
		return array(
			// Data & Analytics Professions
			'data_scientist'     => array(
				'data_analysis' => array(
					'steps'         => array( 'get_dataset', 'analyze_data', 'create_chart', 'interpret_results' ),
					'dependencies'  => array(
						'analyze_data'      => 'get_dataset',
						'create_chart'      => 'analyze_data',
						'interpret_results' => 'create_chart',
					),
					'parallel_safe' => false,
					'tools'         => array( 'get_recent_posts', 'create_chart', 'huggingface_dataset_get_rows', 'create_text_embeddings', 'save_post' ),
				),
				'ml_pipeline'   => array(
					'steps'         => array( 'prepare_data', 'train_model', 'validate_model', 'deploy_results' ),
					'dependencies'  => array(
						'train_model'    => 'prepare_data',
						'validate_model' => 'train_model',
						'deploy_results' => 'validate_model',
					),
					'parallel_safe' => false,
					'tools'         => array( 'batch_embed_content', 'create_vector_store', 'openai_usage_analytics' ),
				),
			),
			// Content Creation Professions
			'content_writer'     => array(
				'article_writing'    => array(
					'steps'        => array( 'research_topic', 'create_outline', 'write_draft', 'polish' ),
					'dependencies' => array(
						'create_outline' => 'research_topic',
						'write_draft'    => 'create_outline',
						'polish'         => 'write_draft',
					),
					'tools'        => array( 'web_search', 'deep_research', 'create_post', 'generate_post_excerpt', 'save_post' ),
				),
				'seo_optimization'   => array(
					'steps'         => array( 'analyze_keywords', 'optimize_content', 'check_seo', 'publish' ),
					'dependencies'  => array(
						'optimize_content' => 'analyze_keywords',
						'check_seo'        => 'optimize_content',
						'publish'          => 'check_seo',
					),
					'parallel_safe' => false,
					'tools'         => array( 'get_rankmath_seo', 'seo_meta_optimizer', 'suggest_internal_links', 'save_post' ),
				),
			),
			// Development Professions
			'software_developer' => array(
				'code_development'   => array(
					'steps'         => array( 'analyze_requirements', 'design_solution', 'implement_code', 'test' ),
					'dependencies'  => array(
						'design_solution' => 'analyze_requirements',
						'implement_code'  => 'design_solution',
						'test'            => 'implement_code',
					),
					'parallel_safe' => false,
					'tools'         => array( 'analyze_code_sequence', 'generate_mermaid', 'save_post' ),
				),
				'code_review'        => array(
					'steps'        => array( 'analyze_code', 'check_quality', 'suggest_improvements', 'document' ),
					'dependencies' => array(
						'check_quality'        => 'analyze_code',
						'suggest_improvements' => 'check_quality',
						'document'             => 'suggest_improvements',
					),
					'tools'        => array( 'analyze_code_sequence', 'moderate_content', 'save_post' ),
				),
			),
			// Management Professions
			'project_manager'    => array(
				'project_planning'   => array(
					'steps'         => array( 'define_scope', 'break_down_tasks', 'assign_resources', 'create_timeline' ),
					'parallel_safe' => true,
					'tools'         => array( 'create_agent_team', 'create_task_plan', 'generate_mermaid', 'create_post', 'save_post' ),
				),
				'workflow_execution' => array(
					'steps'         => array( 'initialize_workflow', 'monitor_progress', 'handle_issues', 'complete' ),
					'dependencies'  => array(
						'monitor_progress' => 'initialize_workflow',
						'handle_issues'    => 'monitor_progress',
						'complete'         => 'handle_issues',
					),
					'parallel_safe' => false,
					'tools'         => array( 'execute_workflow', 'check_workflow_health', 'get_session_status', 'aggregate_agent_results' ),
				),
			),
			// Quality Assurance Professions
			'technical_editor'   => array(
				'content_review'    => array(
					'steps'        => array( 'read_content', 'check_accuracy', 'verify_quality', 'provide_feedback' ),
					'dependencies' => array(
						'check_accuracy'   => 'read_content',
						'verify_quality'   => 'read_content',
						'provide_feedback' => array( 'check_accuracy', 'verify_quality' ),
					),
					'tools'        => array( 'get_recent_posts', 'moderate_content', 'content_freshness_checker', 'save_post' ),
				),
				'editorial_workflow' => array(
					'steps'         => array( 'review_submissions', 'categorize_content', 'schedule_publishing', 'notify' ),
					'parallel_safe' => true,
					'tools'         => array( 'get_recent_posts', 'auto_categorize_content', 'create_cron_job', 'send_group_email' ),
				),
			),
			// Research Professions
			'research_analyst'   => array(
				'research_task'        => array(
					'steps'         => array( 'gather_sources', 'analyze_data', 'synthesize_findings', 'document_results' ),
					'dependencies'  => array(
						'analyze_data'        => 'gather_sources',
						'synthesize_findings' => 'analyze_data',
						'document_results'    => 'synthesize_findings',
					),
					'parallel_safe' => false,
					'tools'         => array( 'web_search', 'deep_research', 'run_crawl4ai_job', 'create_chart', 'save_post' ),
				),
				'competitive_analysis' => array(
					'steps'         => array( 'identify_competitors', 'gather_data', 'analyze_trends', 'report_insights' ),
					'dependencies'  => array(
						'gather_data'     => 'identify_competitors',
						'analyze_trends'  => 'gather_data',
						'report_insights' => 'analyze_trends',
					),
					'parallel_safe' => false,
					'tools'         => array( 'web_search', 'scrape_product', 'crawl4ai_price_lookup', 'create_chart', 'save_post' ),
				),
			),
			// E-commerce Professions
			'ecommerce_manager'  => array(
				'product_management' => array(
					'steps'         => array( 'analyze_inventory', 'optimize_listings', 'update_pricing', 'monitor_orders' ),
					'parallel_safe' => true,
					'tools'         => array( 'get_woo_products', 'create_woo_product', 'get_woo_recent_orders', 'generate_image_alt_text' ),
				),
				'customer_service'   => array(
					'steps'         => array( 'review_orders', 'process_requests', 'send_updates', 'gather_feedback' ),
					'dependencies'  => array(
						'process_requests' => 'review_orders',
						'send_updates'     => 'process_requests',
						'gather_feedback'  => 'send_updates',
					),
					'tools'         => array( 'get_woo_recent_orders', 'send_group_email', 'client_analyze_sentiment' ),
				),
			),
			// Media & Design Professions
			'graphic_designer'   => array(
				'image_creation' => array(
					'steps'         => array( 'conceptualize', 'generate_image', 'edit_refine', 'optimize_export' ),
					'dependencies'  => array(
						'generate_image' => 'conceptualize',
						'edit_refine'    => 'generate_image',
						'optimize_export' => 'edit_refine',
					),
					'parallel_safe' => false,
					'tools'         => array( 'generate_gemini_image', 'edit_gemini_image', 'resize_image', 'convert_image_format', 'generate_image_alt_text' ),
				),
				'media_optimization' => array(
					'steps'         => array( 'audit_library', 'optimize_images', 'generate_captions', 'update_metadata' ),
					'parallel_safe' => true,
					'tools'         => array( 'search_attachments', 'image_alt_text_optimizer', 'generate_image_caption', 'media_library_optimizer' ),
				),
			),
			'video_producer'     => array(
				'video_production' => array(
					'steps'         => array( 'script_planning', 'generate_video', 'add_captions', 'publish' ),
					'dependencies'  => array(
						'generate_video' => 'script_planning',
						'add_captions'   => 'generate_video',
						'publish'        => 'add_captions',
					),
					'parallel_safe' => false,
					'tools'         => array( 'generate_sora_video', 'generate_video_caption', 'check_video_status', 'save_post' ),
				),
			),
			// Marketing Professions
			'digital_marketer'   => array(
				'campaign_management' => array(
					'steps'         => array( 'plan_campaign', 'create_content', 'schedule_distribution', 'analyze_results' ),
					'dependencies'  => array(
						'create_content'        => 'plan_campaign',
						'schedule_distribution' => 'create_content',
						'analyze_results'       => 'schedule_distribution',
					),
					'parallel_safe' => false,
					'tools'         => array( 'newsletter_create_email', 'create_post', 'create_cron_job', 'sitekit_analytics', 'create_chart' ),
				),
				'email_marketing'     => array(
					'steps'         => array( 'segment_audience', 'compose_email', 'send_campaign', 'track_engagement' ),
					'dependencies'  => array(
						'compose_email'   => 'segment_audience',
						'send_campaign'   => 'compose_email',
						'track_engagement' => 'send_campaign',
					),
					'tools'         => array( 'newsletter_get_subscribers', 'newsletter_create_email', 'send_group_email', 'newsletter_get_subscriber_stats' ),
				),
			),
			// Security Professions
			'security_specialist' => array(
				'security_audit'   => array(
					'steps'         => array( 'scan_vulnerabilities', 'assess_risks', 'recommend_fixes', 'monitor' ),
					'dependencies'  => array(
						'assess_risks'    => 'scan_vulnerabilities',
						'recommend_fixes' => 'assess_risks',
						'monitor'         => 'recommend_fixes',
					),
					'parallel_safe' => false,
					'tools'         => array( 'check_site_security', 'login_security_monitor', 'user_activity_auditor', 'get_system_logs', 'save_post' ),
				),
				'user_management'  => array(
					'steps'         => array( 'review_accounts', 'setup_2fa', 'audit_activity', 'report' ),
					'parallel_safe' => true,
					'tools'         => array( 'get_user_info', '2fa_setup_assistant', 'user_activity_auditor', 'password_strength_analyzer' ),
				),
			),
			// Translation & Localization
			'translator'         => array(
				'content_translation' => array(
					'steps'         => array( 'analyze_source', 'translate_content', 'review_quality', 'publish' ),
					'dependencies'  => array(
						'translate_content' => 'analyze_source',
						'review_quality'    => 'translate_content',
						'publish'           => 'review_quality',
					),
					'parallel_safe' => false,
					'tools'         => array( 'get_recent_posts', 'client_translate_text', 'moderate_content', 'save_post' ),
				),
			),
			// AI/ML Specialist
			'ai_engineer'        => array(
				'model_optimization' => array(
					'steps'         => array( 'research_models', 'test_performance', 'select_best', 'implement' ),
					'dependencies'  => array(
						'test_performance' => 'research_models',
						'select_best'      => 'test_performance',
						'implement'        => 'select_best',
					),
					'parallel_safe' => false,
					'tools'         => array( 'list_available_models', 'research_model', 'suggest_best_model', 'add_model_config' ),
				),
				'vector_management'  => array(
					'steps'         => array( 'prepare_content', 'create_embeddings', 'build_store', 'query_test' ),
					'dependencies'  => array(
						'create_embeddings' => 'prepare_content',
						'build_store'       => 'create_embeddings',
						'query_test'        => 'build_store',
					),
					'tools'         => array( 'batch_embed_content', 'create_vector_store', 'manage_vector_store_files', 'semantic_content_search' ),
				),
			),
			// Analytics Specialist
			'analytics_specialist' => array(
				'website_analytics' => array(
					'steps'         => array( 'collect_data', 'analyze_metrics', 'visualize_trends', 'report_insights' ),
					'dependencies'  => array(
						'analyze_metrics'  => 'collect_data',
						'visualize_trends' => 'analyze_metrics',
						'report_insights'  => 'visualize_trends',
					),
					'parallel_safe' => false,
					'tools'         => array( 'sitekit_analytics', 'sitekit_search_console', 'sitekit_pagespeed', 'create_chart', 'save_post' ),
				),
			),
			// Disaster Response Specialist
			'disaster_coordinator' => array(
				'emergency_monitoring' => array(
					'steps'         => array( 'monitor_events', 'assess_impact', 'coordinate_response', 'report' ),
					'dependencies'  => array(
						'assess_impact'       => 'monitor_events',
						'coordinate_response' => 'assess_impact',
						'report'              => 'coordinate_response',
					),
					'parallel_safe' => false,
					'tools'         => array( 'get_gdacs_events', 'get_nhc_active_storms', 'reliefweb_reports', 'geocode_address', 'create_chart', 'save_post' ),
				),
			),
			// Customer Support
			'customer_support'   => array(
				'ticket_handling' => array(
					'steps'         => array( 'review_ticket', 'analyze_sentiment', 'resolve_issue', 'follow_up' ),
					'dependencies'  => array(
						'analyze_sentiment' => 'review_ticket',
						'resolve_issue'     => 'analyze_sentiment',
						'follow_up'         => 'resolve_issue',
					),
					'tools'         => array( 'client_analyze_sentiment', 'client_question_answering', 'send_group_email' ),
				),
			),
		);
	}
}
