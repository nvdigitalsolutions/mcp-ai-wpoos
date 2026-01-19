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
					$primary_role     = $role_data['primary'];
					$secondary_roles  = isset( $role_data['secondary'] ) ? $role_data['secondary'] : array();
				} else {
					$primary_role     = $role_data;
					$secondary_roles  = array();
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
		if ( $this->has_keywords( $title, array(
			// Quality Assurance & Testing
			'qa engineer', 'quality assurance', 'quality engineer', 'tester', 'test engineer',
			// Editorial & Review
			'editor', 'reviewer', 'proofreader', 'copy editor',
			// Audit & Compliance
			'auditor', 'inspector', 'compliance officer', 'validator',
			// Evaluation & Assessment
			'evaluator', 'assessor', 'judge', 'critic',
		) ) ||
			$this->has_keywords( $expertise_lower, array(
				'quality assurance', 'qa', 'testing', 'test automation',
				'editing', 'reviewing', 'proofreading',
				'validation', 'inspection', 'audit', 'compliance',
				'quality control', 'peer review'
			) ) ) {
			$matched_roles[] = 'critic';
		}

		// 2. Check SPECIALIST capabilities.
		if ( in_array( $category, array( 'legal', 'healthcare', 'financial', 'scientific', 'regulatory' ), true ) ||
			$this->has_keywords( $title, array(
				// Legal
				'attorney', 'lawyer', 'paralegal', 'legal advisor', 'judge',
				// Medical/Healthcare
				'doctor', 'physician', 'surgeon', 'nurse', 'dentist', 'veterinarian', 'pharmacist',
				'therapist', 'psychologist', 'psychiatrist',
				// Financial
				'accountant', 'financial advisor', 'tax advisor', 'bookkeeper',
				// Scientific/Research
				'scientist', 'researcher', 'physicist', 'chemist', 'biologist',
				'geologist', 'meteorologist', 'oceanographer', 'toxicologist',
				// Regulatory/Compliance
				'regulatory affairs', 'compliance specialist', 'drug safety',
			) ) ||
			$this->has_keywords( $expertise_lower, array(
				'legal', 'law', 'litigation',
				'medical', 'healthcare', 'clinical', 'pharmacy',
				'financial', 'accounting', 'taxation',
				'scientific research', 'laboratory', 'clinical trials',
				'regulatory', 'compliance', 'pharmaceutical'
			) ) ) {
			$matched_roles[] = 'specialist';
		}

		// 3. Check PLANNER capabilities.
		if ( $this->has_keywords( $title, array(
			// Project/Product Management
			'project manager', 'product manager', 'program manager', 'scrum master',
			// Strategic Planning
			'planner', 'strategist', 'strategic planner', 'urban planner', 'event planner',
			// Coordination
			'coordinator', 'logistics coordinator', 'research coordinator',
			// Architecture (system design, not implementation)
			'architect', 'cloud architect', 'solutions architect', 'systems architect',
			'enterprise architect', 'landscape architect',
			// Management/Leadership
			'director', 'manager', 'administrator', 'supervisor',
		) ) ||
			$this->has_keywords( $expertise_lower, array(
				'project management', 'product management', 'program management',
				'planning', 'strategy', 'strategic planning',
				'coordination', 'logistics',
				'architecture', 'system design', 'solution design',
				'management', 'administration', 'leadership'
			) ) ||
			'advisory' === $category ) {
			$matched_roles[] = 'planner';
		}

		// 4. Check EXECUTOR capabilities.
		if ( in_array( $category, array( 'technical', 'creative', 'trades', 'operations' ), true ) ||
			$this->has_keywords( $title, array(
				// Software Development
				'developer', 'programmer', 'coder', 'software engineer',
				// Engineering (implementation)
				'engineer', 'drafter', 'technician', 'mechanic',
				// Creative Execution
				'designer', 'artist', 'photographer', 'videographer', 'animator',
				'editor', 'producer', 'cinematographer',
				// Trades/Crafts
				'electrician', 'plumber', 'carpenter', 'welder', 'mason',
				'machinist', 'painter', 'roofer',
				// Operations
				'operator', 'driver', 'pilot', 'captain',
			) ) ||
			$this->has_keywords( $expertise_lower, array(
				'development', 'programming', 'coding', 'software development',
				'engineering', 'technical implementation',
				'design', 'creative', 'multimedia production',
				'construction', 'fabrication', 'installation',
				'operations', 'execution'
			) ) ) {
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
			'data_scientist'     => array(
				'data_analysis' => array(
					'steps'         => array( 'get_dataset', 'analyze_data', 'create_chart', 'interpret_results' ),
					'dependencies'  => array(
						'analyze_data'      => 'get_dataset',
						'create_chart'      => 'analyze_data',
						'interpret_results' => 'create_chart',
					),
					'parallel_safe' => false,
					'tools'         => array( 'get_recent_posts', 'create_chart', 'save_post' ),
				),
			),
			'content_writer'     => array(
				'article_writing' => array(
					'steps'        => array( 'research_topic', 'create_outline', 'write_draft', 'polish' ),
					'dependencies' => array(
						'create_outline' => 'research_topic',
						'write_draft'    => 'create_outline',
						'polish'         => 'write_draft',
					),
					'tools'        => array( 'web_search', 'create_post', 'save_post' ),
				),
			),
			'software_developer' => array(
				'code_development' => array(
					'steps'         => array( 'analyze_requirements', 'design_solution', 'implement_code', 'test' ),
					'dependencies'  => array(
						'design_solution' => 'analyze_requirements',
						'implement_code'  => 'design_solution',
						'test'            => 'implement_code',
					),
					'parallel_safe' => false,
				),
			),
			'project_manager'    => array(
				'project_planning' => array(
					'steps'         => array( 'define_scope', 'break_down_tasks', 'assign_resources', 'create_timeline' ),
					'parallel_safe' => true,
					'tools'         => array( 'create_post', 'save_post' ),
				),
			),
			'technical_editor'   => array(
				'content_review' => array(
					'steps'        => array( 'read_content', 'check_accuracy', 'verify_quality', 'provide_feedback' ),
					'dependencies' => array(
						'check_accuracy'   => 'read_content',
						'verify_quality'   => 'read_content',
						'provide_feedback' => array( 'check_accuracy', 'verify_quality' ),
					),
					'tools'        => array( 'get_post', 'save_post' ),
				),
			),
			'research_analyst'   => array(
				'research_task' => array(
					'steps'         => array( 'gather_sources', 'analyze_data', 'synthesize_findings', 'document_results' ),
					'dependencies'  => array(
						'analyze_data'        => 'gather_sources',
						'synthesize_findings' => 'analyze_data',
						'document_results'    => 'synthesize_findings',
					),
					'parallel_safe' => false,
					'tools'         => array( 'web_search', 'crawl4ai', 'create_chart', 'save_post' ),
				),
			),
		);
	}
}
