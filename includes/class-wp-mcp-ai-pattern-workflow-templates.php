<?php
/**
 * Pattern-Based Workflow Templates
 *
 * Provides workflow templates for each multi-agent pattern.
 * Integrates with the agent team orchestrator.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern Workflow Templates class
 *
 * Defines workflow templates for each of the 8 multi-agent patterns.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pattern_Workflow_Templates {

	/**
	 * Pattern registry instance
	 *
	 * @var WP_MCP_AI_Pattern_Registry
	 */
	protected $pattern_registry;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Pattern_Registry|null $pattern_registry Pattern registry instance.
	 */
	public function __construct( $pattern_registry = null ) {
		$this->pattern_registry = $pattern_registry;
	}

	/**
	 * Get workflow template for a pattern
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @param array  $context      Optional context for customization.
	 * @return array|null Workflow template or null if pattern not found.
	 */
	public function get_workflow_template( $pattern_slug, $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for context-aware customization.
		$templates = $this->get_all_templates();
		return isset( $templates[ $pattern_slug ] ) ? $templates[ $pattern_slug ] : null;
	}

	/**
	 * Get all workflow templates
	 *
	 * @return array Array of workflow templates keyed by pattern slug.
	 */
	public function get_all_templates() {
		return array(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR        => $this->get_orchestrator_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL         => $this->get_sequential_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER       => $this->get_peer_to_peer_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER       => $this->get_skill_router_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE    => $this->get_layered_defense_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN       => $this->get_event_driven_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL       => $this->get_hierarchical_template(),
			WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION    => $this->get_experimentation_template(),
		);
	}

	/**
	 * Get orchestrator pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_orchestrator_template() {
		return array(
			'name'        => __( 'Orchestrator Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR,
			'description' => __( 'Centralized coordinator manages worker agents', 'mcp-ai-wpoos' ),
			'roles'       => array( 'coordinator', 'worker_1', 'worker_2', 'worker_3' ),
			'workflow'    => array(
				array(
					'name'        => 'plan',
					'type'        => 'coordinate',
					'role'        => 'coordinator',
					'description' => __( 'Coordinator plans and delegates tasks', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'execute_parallel',
					'type'        => 'parallel',
					'roles'       => array( 'worker_1', 'worker_2', 'worker_3' ),
					'description' => __( 'Workers execute tasks in parallel', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'aggregate',
					'type'        => 'coordinate',
					'role'        => 'coordinator',
					'description' => __( 'Coordinator aggregates results', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get sequential pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_sequential_template() {
		return array(
			'name'        => __( 'Sequential Pipeline Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL,
			'description' => __( 'Linear chain of agents processing sequentially', 'mcp-ai-wpoos' ),
			'roles'       => array( 'stage_1', 'stage_2', 'stage_3', 'stage_4' ),
			'workflow'    => array(
				array(
					'name'        => 'stage_1_process',
					'type'        => 'delegate',
					'role'        => 'stage_1',
					'description' => __( 'First stage processing', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_2_process',
					'type'        => 'delegate',
					'role'        => 'stage_2',
					'description' => __( 'Second stage processing', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_3_process',
					'type'        => 'delegate',
					'role'        => 'stage_3',
					'description' => __( 'Third stage processing', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_4_finalize',
					'type'        => 'delegate',
					'role'        => 'stage_4',
					'description' => __( 'Final stage processing', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get peer-to-peer pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_peer_to_peer_template() {
		return array(
			'name'        => __( 'Peer-to-Peer Collaboration Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER,
			'description' => __( 'Agents collaborate as equals to reach consensus', 'mcp-ai-wpoos' ),
			'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
			'workflow'    => array(
				array(
					'name'        => 'initial_proposals',
					'type'        => 'parallel',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Each peer generates initial proposal', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'peer_review',
					'type'        => 'collaborate',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Peers review and discuss proposals', 'mcp-ai-wpoos' ),
					'critical'    => false,
				),
				array(
					'name'        => 'consensus',
					'type'        => 'vote',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Reach consensus on final approach', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get skill router pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_skill_router_template() {
		return array(
			'name'        => __( 'Skill Router Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER,
			'description' => __( 'Router directs tasks to specialized agents', 'mcp-ai-wpoos' ),
			'roles'       => array( 'router', 'specialist_1', 'specialist_2', 'specialist_3' ),
			'workflow'    => array(
				array(
					'name'        => 'analyze_requirements',
					'type'        => 'route',
					'role'        => 'router',
					'description' => __( 'Router analyzes task requirements', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'route_to_specialist',
					'type'        => 'route',
					'role'        => 'router',
					'description' => __( 'Router selects appropriate specialist', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'specialist_execution',
					'type'        => 'delegate_dynamic',
					'roles'       => array( 'specialist_1', 'specialist_2', 'specialist_3' ),
					'description' => __( 'Selected specialist executes task', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get layered defense pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_layered_defense_template() {
		return array(
			'name'        => __( 'Layered Defense Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE,
			'description' => __( 'Multiple validation layers for security', 'mcp-ai-wpoos' ),
			'roles'       => array( 'validator_1', 'validator_2', 'validator_3' ),
			'workflow'    => array(
				array(
					'name'        => 'layer_1_validation',
					'type'        => 'validate',
					'role'        => 'validator_1',
					'description' => __( 'First validation layer', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'layer_2_validation',
					'type'        => 'validate',
					'role'        => 'validator_2',
					'description' => __( 'Second validation layer', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'layer_3_validation',
					'type'        => 'validate',
					'role'        => 'validator_3',
					'description' => __( 'Final validation layer', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get event-driven pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_event_driven_template() {
		return array(
			'name'        => __( 'Event-Driven Response Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN,
			'description' => __( 'Agents respond to events and triggers', 'mcp-ai-wpoos' ),
			'roles'       => array( 'monitor', 'responder_1', 'responder_2' ),
			'workflow'    => array(
				array(
					'name'        => 'event_detection',
					'type'        => 'monitor',
					'role'        => 'monitor',
					'description' => __( 'Monitor detects events', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'event_response',
					'type'        => 'respond',
					'roles'       => array( 'responder_1', 'responder_2' ),
					'description' => __( 'Responders handle events', 'mcp-ai-wpoos' ),
					'critical'    => false,
				),
			),
		);
	}

	/**
	 * Get hierarchical pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_hierarchical_template() {
		return array(
			'name'        => __( 'Hierarchical Orchestrator Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL,
			'description' => __( 'Multi-level management hierarchy', 'mcp-ai-wpoos' ),
			'roles'       => array( 'director', 'manager_1', 'manager_2', 'worker_1', 'worker_2', 'worker_3', 'worker_4' ),
			'workflow'    => array(
				array(
					'name'        => 'strategic_planning',
					'type'        => 'coordinate',
					'role'        => 'director',
					'description' => __( 'Director defines strategy', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'tactical_planning',
					'type'        => 'parallel',
					'roles'       => array( 'manager_1', 'manager_2' ),
					'description' => __( 'Managers create tactical plans', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'execution',
					'type'        => 'parallel',
					'roles'       => array( 'worker_1', 'worker_2', 'worker_3', 'worker_4' ),
					'description' => __( 'Workers execute tasks', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
				array(
					'name'        => 'consolidation',
					'type'        => 'coordinate',
					'role'        => 'director',
					'description' => __( 'Director consolidates results', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get experimentation pattern template
	 *
	 * @return array Workflow template.
	 */
	protected function get_experimentation_template() {
		return array(
			'name'        => __( 'Experimentation Pipeline Workflow', 'mcp-ai-wpoos' ),
			'pattern'     => WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION,
			'description' => __( 'Multiple approaches tested and best selected', 'mcp-ai-wpoos' ),
			'roles'       => array( 'experimenter_1', 'experimenter_2', 'experimenter_3', 'evaluator' ),
			'workflow'    => array(
				array(
					'name'        => 'parallel_experiments',
					'type'        => 'parallel',
					'roles'       => array( 'experimenter_1', 'experimenter_2', 'experimenter_3' ),
					'description' => __( 'Multiple agents try different approaches', 'mcp-ai-wpoos' ),
					'critical'    => false,
				),
				array(
					'name'        => 'evaluate_results',
					'type'        => 'evaluate',
					'role'        => 'evaluator',
					'description' => __( 'Evaluator compares and selects best result', 'mcp-ai-wpoos' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get recommended template for a toolkit
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array|null Recommended workflow template or null.
	 */
	public function get_recommended_template_for_toolkit( $toolkit_slug ) {
		if ( ! $this->pattern_registry ) {
			return null;
		}

		// Get toolkit info to find primary pattern.
		$toolkit_registry = new WP_MCP_AI_Toolkit_Registry();
		$toolkit_info     = $toolkit_registry->get_toolkit( $toolkit_slug );

		if ( ! $toolkit_info || ! isset( $toolkit_info['primary_pattern'] ) ) {
			return null;
		}

		$pattern_slug = $toolkit_info['primary_pattern'];
		return $this->get_workflow_template( $pattern_slug );
	}

	/**
	 * Customize template for specific task
	 *
	 * @param array $template Base template.
	 * @param array $context  Task context for customization.
	 * @return array Customized template.
	 */
	public function customize_template( $template, $context ) {
		// Clone template to avoid modifying original.
		$customized = $template;

		// Adjust team size if specified.
		if ( isset( $context['team_size'] ) ) {
			$team_size = absint( $context['team_size'] );
			// Logic to adjust roles based on team size would go here.
		}

		// Add custom roles if specified.
		if ( isset( $context['custom_roles'] ) && is_array( $context['custom_roles'] ) ) {
			$customized['roles'] = array_merge( $customized['roles'], $context['custom_roles'] );
		}

		return apply_filters( 'wp_mcp_ai_customize_workflow_template', $customized, $template, $context );
	}
}
