<?php
/**
 * Pattern-Based Workflow Templates (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Pattern_Workflow_Templates`:
 * byte-identical template catalog for the eight multi-agent patterns
 * (names, pattern slugs, descriptions, role lists, and step workflows
 * with their type/role/criticality shapes), the
 * `get_workflow_template()` / `get_all_templates()` lookups, the
 * `customize_template()` contract (custom-role merge + the
 * `wp_mcp_ai_customize_workflow_template` filter), and the
 * toolkit-recommendation path.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Pattern slugs resolve from this package's `PatternConstants`
 *    (byte-identical values).
 *  - The toolkit registry resolves per install mode through the
 *    `get_toolkit_registry()` seam: base `WP_MCP_AI_Toolkit_Registry`
 *    monolith (boot-gated probe); standalone resolves null until the
 *    Pro-tier toolkit registry ports — `get_recommended_template_for_toolkit()`
 *    returns null (documented degradation, same envelope as the base's
 *    no-registry/no-pattern paths).
 *  - No standalone hook registration — a pure template library
 *    consumed by the DAG builder (E-UI-2) and the optimizer (E1).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Pattern Workflow Templates class.
 *
 * Defines workflow templates for each of the 8 multi-agent patterns.
 *
 * @since 2.1.0
 */
class PatternWorkflowTemplates {

	/**
	 * Pattern registry instance.
	 *
	 * @var object|null
	 */
	protected $pattern_registry;

	/**
	 * Constructor.
	 *
	 * @param object|null $pattern_registry Pattern registry instance.
	 */
	public function __construct( $pattern_registry = null ) {
		$this->pattern_registry = $pattern_registry;
	}

	/**
	 * Get workflow template for a pattern.
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @param array  $context      Optional context for customization.
	 * @return array|null Workflow template or null if pattern not found.
	 */
	public function get_workflow_template( $pattern_slug, $context = array() ) { // phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Parameter reserved for context-aware customization.
		$templates = $this->get_all_templates();
		return isset( $templates[ $pattern_slug ] ) ? $templates[ $pattern_slug ] : null;
	}

	/**
	 * Get all workflow templates.
	 *
	 * @return array Array of workflow templates keyed by pattern slug.
	 */
	public function get_all_templates() {
		return array(
			PatternConstants::PATTERN_ORCHESTRATOR    => $this->get_orchestrator_template(),
			PatternConstants::PATTERN_SEQUENTIAL      => $this->get_sequential_template(),
			PatternConstants::PATTERN_PEER_TO_PEER    => $this->get_peer_to_peer_template(),
			PatternConstants::PATTERN_SKILL_ROUTER    => $this->get_skill_router_template(),
			PatternConstants::PATTERN_LAYERED_DEFENSE => $this->get_layered_defense_template(),
			PatternConstants::PATTERN_EVENT_DRIVEN    => $this->get_event_driven_template(),
			PatternConstants::PATTERN_HIERARCHICAL    => $this->get_hierarchical_template(),
			PatternConstants::PATTERN_EXPERIMENTATION => $this->get_experimentation_template(),
		);
	}

	/**
	 * Get orchestrator pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_orchestrator_template() {
		return array(
			'name'        => __( 'Orchestrator Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_ORCHESTRATOR,
			'description' => __( 'Centralized coordinator manages worker agents', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'coordinator', 'worker_1', 'worker_2', 'worker_3' ),
			'workflow'    => array(
				array(
					'name'        => 'plan',
					'type'        => 'coordinate',
					'role'        => 'coordinator',
					'description' => __( 'Coordinator plans and delegates tasks', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'execute_parallel',
					'type'        => 'parallel',
					'roles'       => array( 'worker_1', 'worker_2', 'worker_3' ),
					'description' => __( 'Workers execute tasks in parallel', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'aggregate',
					'type'        => 'coordinate',
					'role'        => 'coordinator',
					'description' => __( 'Coordinator aggregates results', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get sequential pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_sequential_template() {
		return array(
			'name'        => __( 'Sequential Pipeline Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_SEQUENTIAL,
			'description' => __( 'Linear chain of agents processing sequentially', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'stage_1', 'stage_2', 'stage_3', 'stage_4' ),
			'workflow'    => array(
				array(
					'name'        => 'stage_1_process',
					'type'        => 'delegate',
					'role'        => 'stage_1',
					'description' => __( 'First stage processing', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_2_process',
					'type'        => 'delegate',
					'role'        => 'stage_2',
					'description' => __( 'Second stage processing', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_3_process',
					'type'        => 'delegate',
					'role'        => 'stage_3',
					'description' => __( 'Third stage processing', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'stage_4_finalize',
					'type'        => 'delegate',
					'role'        => 'stage_4',
					'description' => __( 'Final stage processing', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get peer-to-peer pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_peer_to_peer_template() {
		return array(
			'name'        => __( 'Peer-to-Peer Collaboration Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_PEER_TO_PEER,
			'description' => __( 'Agents collaborate as equals to reach consensus', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
			'workflow'    => array(
				array(
					'name'        => 'initial_proposals',
					'type'        => 'parallel',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Each peer generates initial proposal', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'peer_review',
					'type'        => 'collaborate',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Peers review and discuss proposals', 'nvoos-content-graph-ai-platform' ),
					'critical'    => false,
				),
				array(
					'name'        => 'consensus',
					'type'        => 'vote',
					'roles'       => array( 'peer_1', 'peer_2', 'peer_3', 'peer_4' ),
					'description' => __( 'Reach consensus on final approach', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get skill router pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_skill_router_template() {
		return array(
			'name'        => __( 'Skill Router Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_SKILL_ROUTER,
			'description' => __( 'Router directs tasks to specialized agents', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'router', 'specialist_1', 'specialist_2', 'specialist_3' ),
			'workflow'    => array(
				array(
					'name'        => 'analyze_requirements',
					'type'        => 'route',
					'role'        => 'router',
					'description' => __( 'Router analyzes task requirements', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'route_to_specialist',
					'type'        => 'route',
					'role'        => 'router',
					'description' => __( 'Router selects appropriate specialist', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'specialist_execution',
					'type'        => 'delegate_dynamic',
					'roles'       => array( 'specialist_1', 'specialist_2', 'specialist_3' ),
					'description' => __( 'Selected specialist executes task', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get layered defense pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_layered_defense_template() {
		return array(
			'name'        => __( 'Layered Defense Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_LAYERED_DEFENSE,
			'description' => __( 'Multiple validation layers for security', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'validator_1', 'validator_2', 'validator_3' ),
			'workflow'    => array(
				array(
					'name'        => 'layer_1_validation',
					'type'        => 'validate',
					'role'        => 'validator_1',
					'description' => __( 'First validation layer', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'layer_2_validation',
					'type'        => 'validate',
					'role'        => 'validator_2',
					'description' => __( 'Second validation layer', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'layer_3_validation',
					'type'        => 'validate',
					'role'        => 'validator_3',
					'description' => __( 'Final validation layer', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get event-driven pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_event_driven_template() {
		return array(
			'name'        => __( 'Event-Driven Response Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_EVENT_DRIVEN,
			'description' => __( 'Agents respond to events and triggers', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'monitor', 'responder_1', 'responder_2' ),
			'workflow'    => array(
				array(
					'name'        => 'event_detection',
					'type'        => 'monitor',
					'role'        => 'monitor',
					'description' => __( 'Monitor detects events', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'event_response',
					'type'        => 'respond',
					'roles'       => array( 'responder_1', 'responder_2' ),
					'description' => __( 'Responders handle events', 'nvoos-content-graph-ai-platform' ),
					'critical'    => false,
				),
			),
		);
	}

	/**
	 * Get hierarchical pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_hierarchical_template() {
		return array(
			'name'        => __( 'Hierarchical Orchestrator Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_HIERARCHICAL,
			'description' => __( 'Multi-level management hierarchy', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'director', 'manager_1', 'manager_2', 'worker_1', 'worker_2', 'worker_3', 'worker_4' ),
			'workflow'    => array(
				array(
					'name'        => 'strategic_planning',
					'type'        => 'coordinate',
					'role'        => 'director',
					'description' => __( 'Director defines strategy', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'tactical_planning',
					'type'        => 'parallel',
					'roles'       => array( 'manager_1', 'manager_2' ),
					'description' => __( 'Managers create tactical plans', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'execution',
					'type'        => 'parallel',
					'roles'       => array( 'worker_1', 'worker_2', 'worker_3', 'worker_4' ),
					'description' => __( 'Workers execute tasks', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
				array(
					'name'        => 'consolidation',
					'type'        => 'coordinate',
					'role'        => 'director',
					'description' => __( 'Director consolidates results', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get experimentation pattern template.
	 *
	 * @return array Workflow template.
	 */
	protected function get_experimentation_template() {
		return array(
			'name'        => __( 'Experimentation Pipeline Workflow', 'nvoos-content-graph-ai-platform' ),
			'pattern'     => PatternConstants::PATTERN_EXPERIMENTATION,
			'description' => __( 'Multiple approaches tested and best selected', 'nvoos-content-graph-ai-platform' ),
			'roles'       => array( 'experimenter_1', 'experimenter_2', 'experimenter_3', 'evaluator' ),
			'workflow'    => array(
				array(
					'name'        => 'parallel_experiments',
					'type'        => 'parallel',
					'roles'       => array( 'experimenter_1', 'experimenter_2', 'experimenter_3' ),
					'description' => __( 'Multiple agents try different approaches', 'nvoos-content-graph-ai-platform' ),
					'critical'    => false,
				),
				array(
					'name'        => 'evaluate_results',
					'type'        => 'evaluate',
					'role'        => 'evaluator',
					'description' => __( 'Evaluator compares and selects best result', 'nvoos-content-graph-ai-platform' ),
					'critical'    => true,
				),
			),
		);
	}

	/**
	 * Get recommended template for a toolkit.
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array|null Recommended workflow template or null.
	 */
	public function get_recommended_template_for_toolkit( $toolkit_slug ) {
		if ( ! $this->pattern_registry ) {
			return null;
		}

		// Get toolkit info to find primary pattern.
		$toolkit_registry = $this->get_toolkit_registry();
		if ( null === $toolkit_registry ) {
			return null;
		}
		$toolkit_info = $toolkit_registry->get_toolkit( $toolkit_slug );

		if ( ! $toolkit_info || ! isset( $toolkit_info['primary_pattern'] ) ) {
			return null;
		}

		$pattern_slug = $toolkit_info['primary_pattern'];
		return $this->get_workflow_template( $pattern_slug );
	}

	/**
	 * Customize template for specific task.
	 *
	 * @param array $template Base template.
	 * @param array $context  Task context for customization.
	 * @return array Customized template.
	 */
	public function customize_template( $template, $context ) {
		// Clone template to avoid modifying original.
		$customized = $template;

		// Add custom roles if specified.
		if ( isset( $context['custom_roles'] ) && is_array( $context['custom_roles'] ) ) {
			$customized['roles'] = array_merge( $customized['roles'], $context['custom_roles'] );
		}

		return apply_filters( 'wp_mcp_ai_customize_workflow_template', $customized, $template, $context );
	}

	// ── Per-mode collaborator seams ───────────────────────────────────────────

	/**
	 * Resolve the toolkit registry per install mode.
	 *
	 * The base registry owns toolkit metadata in monolith installs;
	 * standalone resolves null until the Pro-tier toolkit registry ports
	 * (documented degradation — the recommendation path returns null).
	 * The base's own templates class instantiates the registry directly,
	 * which fatals against its protected constructor — this port uses the
	 * registry's `get_instance()` singleton instead (documented
	 * hardening; behavior for the working path is identical).
	 *
	 * @return object|null Registry object, or null when unavailable.
	 */
	protected function get_toolkit_registry() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Toolkit_Registry' ) ) {
			return \WP_MCP_AI_Toolkit_Registry::get_instance();
		}

		return null;
	}
}
