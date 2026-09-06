<?php
/**
 * Orchestration Presets Service
 *
 * Pre-configured orchestration modes for common scenarios.
 * Part of Phase 4.3: Orchestration Presets.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration Presets Service Class
 *
 * Manages and applies pre-configured orchestration settings for
 * common use cases like research, code generation, and multi-agent collaboration.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Orchestration_Presets {

	/**
	 * Configuration key for active preset
	 */
	const ACTIVE_PRESET_OPTION = 'wp_mcp_ai_active_preset';

	/**
	 * Configuration key for custom presets
	 */
	const CUSTOM_PRESETS_OPTION = 'wp_mcp_ai_custom_presets';

	/**
	 * Get all available presets
	 *
	 * @return array Available presets with metadata.
	 */
	public function get_presets() {
		$built_in_presets = array(
			'research'            => array(
				'name'        => __( 'Research & Analysis', 'mcp-ai-wpoos' ),
				'description' => __( 'Optimized for multi-source research and synthesis. Uses agent teams and reasoning mode.', 'mcp-ai-wpoos' ),
				'category'    => 'research',
				'settings'    => array(
					'enable_agent_teams'   => true,
					'primary_agent_role'   => 'researcher',
					'reasoning_mode'       => 'enabled',
					'load_balancing'       => 'quality-optimized',
					'enable_caching'       => true,
					'enable_prediction'    => true,
					'tool_preferences'     => array( 'web_search', 'crawl4ai', 'analyze_content', 'semantic_search' ),
					'max_delegation_depth' => 3,
				),
			),
			'code_generation'     => array(
				'name'        => __( 'Code Generation', 'mcp-ai-wpoos' ),
				'description' => __( 'Enhanced for coding tasks with validation and security scanning. Activates reasoning mode automatically.', 'mcp-ai-wpoos' ),
				'category'    => 'development',
				'settings'    => array(
					'reasoning_mode'       => 'enabled',
					'code_validation'      => 'enabled',
					'security_scanning'    => 'enabled',
					'context_optimization' => 'code-aware',
					'load_balancing'       => 'quality-optimized',
					'tool_preferences'     => array( 'analyze_code_sequence', 'validate_reasoning_chain', 'enable_reasoning_mode' ),
					'temperature'          => 0.3,
				),
			),
			'multi_agent'         => array(
				'name'        => __( 'Multi-Agent Collaboration', 'mcp-ai-wpoos' ),
				'description' => __( 'Team-based approach for complex tasks requiring multiple perspectives and validation.', 'mcp-ai-wpoos' ),
				'category'    => 'collaboration',
				'settings'    => array(
					'enable_agent_teams' => true,
					'team_composition'   => 'auto',
					'delegation_enabled' => true,
					'result_aggregation' => 'consensus',
					'enable_critic_role' => true,
					'tool_preferences'   => array( 'create_agent_team', 'delegate_to_agent', 'aggregate_agent_results', 'execute_workflow' ),
					'max_team_size'      => 5,
				),
			),
			'speed_optimized'     => array(
				'name'        => __( 'Speed Optimized', 'mcp-ai-wpoos' ),
				'description' => __( 'Fastest response time with standard quality. Maximizes caching and parallel execution.', 'mcp-ai-wpoos' ),
				'category'    => 'performance',
				'settings'    => array(
					'load_balancing'     => 'speed-optimized',
					'enable_caching'     => true,
					'cache_aggressive'   => true,
					'enable_prediction'  => true,
					'parallel_execution' => 'aggressive',
					'skip_validation'    => true,
					'temperature'        => 0.7,
				),
			),
			'quality_optimized'   => array(
				'name'        => __( 'Quality Optimized', 'mcp-ai-wpoos' ),
				'description' => __( 'Highest quality output with reasoning, validation, and multi-step verification.', 'mcp-ai-wpoos' ),
				'category'    => 'quality',
				'settings'    => array(
					'load_balancing'      => 'quality-optimized',
					'reasoning_mode'      => 'enabled',
					'enable_verification' => true,
					'enable_critic_role'  => true,
					'tool_preferences'    => array( 'validate_reasoning_chain', 'enable_reasoning_mode' ),
					'temperature'         => 0.2,
					'require_confidence'  => 0.8,
				),
			),
			'autonomous_workflow' => array(
				'name'        => __( 'Autonomous Workflow', 'mcp-ai-wpoos' ),
				'description' => __( 'Self-managing workflow with task planning, health monitoring, exit detection, and session management for continuous AI operations.', 'mcp-ai-wpoos' ),
				'category'    => 'orchestration',
				'settings'    => array(
					'enable_agent_teams'        => true,
					'delegation_enabled'        => true,
					'reasoning_mode'            => 'enabled',
					'enable_task_planning'      => true,
					'enable_health_monitoring'  => true,
					'enable_exit_detection'     => true,
					'enable_session_management' => true,
					'tool_preferences'          => array( 'create_task_plan', 'manage_autonomous_session', 'check_exit_conditions', 'analyze_loop_health', 'detect_completion_indicators' ),
					'max_delegation_depth'      => 5,
					'max_loop_iterations'       => 50,
				),
			),
			'supervisor_pattern'  => array(
				'name'        => __( 'Supervisor Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Central supervisor agent assigns, tracks, and aggregates sub-agent work. Best for quality control with single coordination point.', 'mcp-ai-wpoos' ),
				'category'    => 'orchestration',
				'settings'    => array(
					'enable_agent_teams'   => true,
					'primary_agent_role'   => 'supervisor',
					'delegation_enabled'   => true,
					'result_aggregation'   => 'supervisor-reviewed',
					'enable_critic_role'   => true,
					'enable_verification'  => true,
					'tool_preferences'     => array( 'create_agent_team', 'delegate_to_agent', 'aggregate_agent_results', 'validate_workflow', 'verify_information' ),
					'max_team_size'        => 5,
					'max_delegation_depth' => 3,
				),
			),
			'pipeline_pattern'    => array(
				'name'        => __( 'Pipeline Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Sequential agent assembly line where each step refines output. Best for structured multi-step processing with clear stage boundaries.', 'mcp-ai-wpoos' ),
				'category'    => 'orchestration',
				'settings'    => array(
					'enable_agent_teams'   => true,
					'pipeline_mode'        => true,
					'sequential_execution' => true,
					'stage_validation'     => true,
					'enable_caching'       => true,
					'tool_preferences'     => array( 'execute_workflow', 'create_task_plan', 'detect_completion_indicators', 'extract_structured_data' ),
					'max_pipeline_stages'  => 10,
				),
			),
			'swarm_pattern'       => array(
				'name'        => __( 'Swarm Pattern', 'mcp-ai-wpoos' ),
				'description' => __( 'Autonomous parallel agents work independently for search, analysis, and data collection. Results are aggregated at completion.', 'mcp-ai-wpoos' ),
				'category'    => 'orchestration',
				'settings'    => array(
					'enable_agent_teams'   => true,
					'parallel_execution'   => 'aggressive',
					'delegation_enabled'   => true,
					'result_aggregation'   => 'parallel-merge',
					'enable_caching'       => true,
					'tool_preferences'     => array( 'create_agent_team', 'delegate_to_agent', 'aggregate_agent_results', 'web_search', 'deep_research', 'aggregate_research_data' ),
					'max_team_size'        => 10,
					'max_delegation_depth' => 2,
				),
			),
			'pso_adaptive'        => array(
				'name'        => __( 'Adaptive Optimization (PSO)', 'mcp-ai-wpoos' ),
				'description' => __( 'Self-tuning orchestration that learns from each conversation. Uses Particle Swarm Optimization to adaptively improve model selection, iteration limits, tool routing, and cost-quality balance over time.', 'mcp-ai-wpoos' ),
				'category'    => 'optimization',
				'settings'    => array(
					'enable_pso_optimizer'       => true,
					'pso_inertia_start'          => 0.9,
					'pso_inertia_end'            => 0.4,
					'pso_learning_rate_personal' => 1.5,
					'pso_learning_rate_social'   => 2.0,
					'pso_update_frequency'       => 10,
					'enable_caching'             => true,
					'enable_prediction'          => true,
					'load_balancing'             => 'adaptive',
				),
			),
			'dspark_speculative'  => array(
				'name'        => __( 'DSpark Speculative', 'mcp-ai-wpoos' ),
				'description' => __( 'Confidence-scheduled speculative execution with tiered model routing. Drafts tool chains ahead of time, verifies batch-style, and auto-selects cheap vs capable models per step. Inspired by DeepSeek V4 DSpark decoding.', 'mcp-ai-wpoos' ),
				'category'    => 'optimization',
				'settings'    => array(
					'enable_speculative_execution'     => true,
					'enable_depth_scheduling'          => true,
					'enable_tiered_model_routing'      => true,
					'enable_hybrid_planning'           => true,
					'enable_chain_acceptance_tracking' => true,
					'load_balancing'                   => 'adaptive',
					'parallel_execution'               => 'speculative',
					'model_selection_strategy'         => 'tiered',
					'verification_strategy'            => 'depth-scheduled',
					'speculative_block_size'           => 4,
					'speculative_min_confidence'       => 0.6,
					'enable_verification'              => true,
					'enable_caching'                   => true,
					'enable_prediction'                => true,
					'tool_preferences'                 => array(
						'calculate_orchestration_capacity',
						'analyze_loop_health',
						'get_loop_metrics',
						'detect_completion_indicators',
					),
				),
			),
		);

		// Merge with custom presets.
		$custom_presets = get_option( self::CUSTOM_PRESETS_OPTION, array() );
		if ( is_array( $custom_presets ) ) {
			foreach ( $custom_presets as $key => $preset ) {
				$preset['custom']         = true;
				$built_in_presets[ $key ] = $preset;
			}
		}

		return apply_filters( 'wp_mcp_ai_orchestration_presets', $built_in_presets );
	}

	/**
	 * Get single preset
	 *
	 * @param string $preset_name Preset name/key.
	 * @return array|null Preset data or null if not found.
	 */
	public function get_preset( $preset_name ) {
		$presets = $this->get_presets();
		return $presets[ $preset_name ] ?? null;
	}

	/**
	 * Apply preset to orchestration
	 *
	 * @param string $preset_name Preset name/key.
	 * @param array  $override_settings Settings to override.
	 * @return array|WP_Error Applied configuration or error.
	 */
	public function apply_preset( $preset_name, $override_settings = array() ) {
		$preset = $this->get_preset( $preset_name );

		if ( ! $preset ) {
			return new WP_Error(
				'preset_not_found',
				sprintf(
					/* translators: %s: preset name */
					__( 'Preset "%s" not found.', 'mcp-ai-wpoos' ),
					$preset_name
				)
			);
		}

		// Merge settings with overrides.
		$settings = array_merge( $preset['settings'], $override_settings );

		// Apply settings to orchestration layer.
		$this->apply_settings( $settings );

		// Store active preset.
		update_option( self::ACTIVE_PRESET_OPTION, $preset_name );

		return array(
			'preset'   => $preset_name,
			'settings' => $settings,
			'applied'  => true,
			'message'  => sprintf(
				/* translators: %s: preset name */
				__( 'Orchestration preset "%s" applied successfully.', 'mcp-ai-wpoos' ),
				$preset['name']
			),
		);
	}

	/**
	 * Apply settings to orchestration components
	 *
	 * @param array $settings Settings to apply.
	 */
	protected function apply_settings( $settings ) {
		// Store settings for runtime access.
		update_option( 'wp_mcp_ai_orchestration_settings', $settings, false );

		/**
		 * Hook for other components to react to preset application.
		 *
		 * @param array $settings Applied settings.
		 */
		do_action( 'wp_mcp_ai_preset_applied', $settings );
	}

	/**
	 * Get current active preset
	 *
	 * @return string|null Active preset name or null.
	 */
	public function get_active_preset() {
		return get_option( self::ACTIVE_PRESET_OPTION, null );
	}

	/**
	 * Get current orchestration settings
	 *
	 * @return array Current settings.
	 */
	public function get_current_settings() {
		return get_option( 'wp_mcp_ai_orchestration_settings', array() );
	}

	/**
	 * Create custom preset
	 *
	 * @param string $preset_key Unique preset key.
	 * @param array  $preset_data Preset data (name, description, settings).
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function create_custom_preset( $preset_key, $preset_data ) {
		// Validate preset key.
		if ( empty( $preset_key ) || ! preg_match( '/^[a-z0-9_]+$/', $preset_key ) ) {
			return new WP_Error(
				'invalid_preset_key',
				__( 'Preset key must contain only lowercase letters, numbers, and underscores.', 'mcp-ai-wpoos' )
			);
		}

		// Check for conflicts with built-in presets.
		$built_in = array_keys( $this->get_presets() );
		if ( in_array( $preset_key, $built_in, true ) ) {
			return new WP_Error(
				'preset_exists',
				__( 'Cannot override built-in preset.', 'mcp-ai-wpoos' )
			);
		}

		// Validate preset data.
		if ( empty( $preset_data['name'] ) || empty( $preset_data['settings'] ) ) {
			return new WP_Error(
				'invalid_preset_data',
				__( 'Preset must have name and settings.', 'mcp-ai-wpoos' )
			);
		}

		// Store custom preset.
		$custom_presets = get_option( self::CUSTOM_PRESETS_OPTION, array() );
		if ( ! is_array( $custom_presets ) ) {
			$custom_presets = array();
		}

		$custom_presets[ $preset_key ] = array(
			'name'        => sanitize_text_field( $preset_data['name'] ),
			'description' => sanitize_textarea_field( $preset_data['description'] ?? '' ),
			'category'    => sanitize_text_field( $preset_data['category'] ?? 'custom' ),
			'settings'    => $preset_data['settings'],
			'created_at'  => current_time( 'mysql' ),
		);

		update_option( self::CUSTOM_PRESETS_OPTION, $custom_presets );

		return true;
	}

	/**
	 * Delete custom preset
	 *
	 * @param string $preset_key Preset key to delete.
	 * @return bool True on success, false if not found or built-in.
	 */
	public function delete_custom_preset( $preset_key ) {
		$custom_presets = get_option( self::CUSTOM_PRESETS_OPTION, array() );

		if ( ! is_array( $custom_presets ) || ! isset( $custom_presets[ $preset_key ] ) ) {
			return false;
		}

		unset( $custom_presets[ $preset_key ] );
		update_option( self::CUSTOM_PRESETS_OPTION, $custom_presets );

		// If this was the active preset, clear it.
		if ( $this->get_active_preset() === $preset_key ) {
			delete_option( self::ACTIVE_PRESET_OPTION );
		}

		return true;
	}

	/**
	 * Reset to default (no preset)
	 *
	 * @return array Reset confirmation.
	 */
	public function reset_to_default() {
		delete_option( self::ACTIVE_PRESET_OPTION );
		delete_option( 'wp_mcp_ai_orchestration_settings' );

		return array(
			'reset'   => true,
			'message' => __( 'Orchestration settings reset to defaults.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get preset recommendations for task
	 *
	 * @param string $task_description Task description.
	 * @return array Recommended presets with confidence scores.
	 */
	public function recommend_preset_for_task( $task_description ) {
		$task_lower      = strtolower( $task_description );
		$recommendations = array();

		// Simple keyword-based recommendations.
		if ( preg_match( '/\b(research|analyze|study|investigate)\b/', $task_lower ) ) {
			$recommendations['research'] = array(
				'confidence' => 0.8,
				'reason'     => __( 'Task involves research and analysis.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(code|program|script|function|class)\b/', $task_lower ) ) {
			$recommendations['code_generation'] = array(
				'confidence' => 0.85,
				'reason'     => __( 'Task involves code generation.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(complex|multiple|team|collaborate)\b/', $task_lower ) ) {
			$recommendations['multi_agent'] = array(
				'confidence' => 0.75,
				'reason'     => __( 'Task may benefit from multi-agent collaboration.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(quick|fast|urgent|asap)\b/', $task_lower ) ) {
			$recommendations['speed_optimized'] = array(
				'confidence' => 0.7,
				'reason'     => __( 'Task prioritizes speed.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(quality|accurate|precise|thorough)\b/', $task_lower ) ) {
			$recommendations['quality_optimized'] = array(
				'confidence' => 0.8,
				'reason'     => __( 'Task prioritizes quality.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(autonomous|continuous|loop|self.?managing|unattended)\b/', $task_lower ) ) {
			$recommendations['autonomous_workflow'] = array(
				'confidence' => 0.85,
				'reason'     => __( 'Task requires autonomous continuous operation.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(supervis|oversee|manage|coordinat|assign|delegate)\b/', $task_lower ) ) {
			$recommendations['supervisor_pattern'] = array(
				'confidence' => 0.8,
				'reason'     => __( 'Task benefits from centralized supervision and delegation.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(pipeline|sequential|stage|step.?by.?step|refine|transform)\b/', $task_lower ) ) {
			$recommendations['pipeline_pattern'] = array(
				'confidence' => 0.8,
				'reason'     => __( 'Task involves sequential multi-step processing.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(parallel|swarm|simultaneous|bulk|mass|gather|scrape)\b/', $task_lower ) ) {
			$recommendations['swarm_pattern'] = array(
				'confidence' => 0.75,
				'reason'     => __( 'Task benefits from parallel autonomous agents.', 'mcp-ai-wpoos' ),
			);
		}

		if ( preg_match( '/\b(optimi[zs]e|adaptive|self[\.\-]?tun(?:ing)?|learn(?:ing)?|improv(?:e|ement|ing)?|evolv(?:e|ing)?|tune|pso)\b/', $task_lower ) ) {
			$recommendations['pso_adaptive'] = array(
				'confidence' => 0.8,
				'reason'     => __( 'Task benefits from adaptive self-tuning optimization.', 'mcp-ai-wpoos' ),
			);
		}

		// Sort by confidence.
		uasort(
			$recommendations,
			function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		return array(
			'task'            => $task_description,
			'recommendations' => $recommendations,
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Get presets by category
	 *
	 * @param string $category Category name.
	 * @return array Presets in category.
	 */
	public function get_presets_by_category( $category ) {
		$all_presets = $this->get_presets();
		$filtered    = array();

		foreach ( $all_presets as $key => $preset ) {
			if ( ( $preset['category'] ?? '' ) === $category ) {
				$filtered[ $key ] = $preset;
			}
		}

		return $filtered;
	}

	/**
	 * Export preset configuration
	 *
	 * @param string $preset_name Preset name.
	 * @return array|WP_Error Exportable preset data or error.
	 */
	public function export_preset( $preset_name ) {
		$preset = $this->get_preset( $preset_name );

		if ( ! $preset ) {
			return new WP_Error(
				'preset_not_found',
				sprintf(
					/* translators: %s: preset name */
					__( 'Preset "%s" not found.', 'mcp-ai-wpoos' ),
					$preset_name
				)
			);
		}

		return array(
			'version'     => '1.0',
			'preset_key'  => $preset_name,
			'preset_data' => $preset,
			'exported_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Import preset configuration
	 *
	 * @param array $import_data Exported preset data.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function import_preset( $import_data ) {
		if ( empty( $import_data['preset_key'] ) || empty( $import_data['preset_data'] ) ) {
			return new WP_Error(
				'invalid_import_data',
				__( 'Invalid import data format.', 'mcp-ai-wpoos' )
			);
		}

		return $this->create_custom_preset(
			$import_data['preset_key'],
			$import_data['preset_data']
		);
	}
}
