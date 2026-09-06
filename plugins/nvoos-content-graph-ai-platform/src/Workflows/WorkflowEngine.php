<?php
/**
 * Workflow Engine V2 — graph-aware execution layer (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Workflow_Engine_V2`:
 * byte-identical `wp_mcp_ai_workflow_v2_enabled` gate (default true),
 * the `manage_options` + post/CPT guards, the
 * `wp_mcp_ai_workflow_v2_before_execute|after_execute` lifecycle
 * actions, the `wf2-<id>-<hex>` run identifier, the durable run record
 * (create → running → step events → terminal status with failure
 * events), the graph → node-description → `execute_workflow` tool
 * delegation with the byte-identical argument shape (`description`,
 * `task_type`, `parallel`, `context` merged with `run_id`), the
 * no-tool / no-registry degradation branches, and the result envelope
 * (`success`/`run_id`/`cpt_run_id`/`results`/`message`).
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - The workflow/run CPT classes resolve per install mode
 *    (`workflow_cpt_class()` / `run_cpt_class()` seams — base classes
 *    monolith boot-gated / platform `WorkflowCpt` + `WorkflowRunCpt`
 *    standalone), replacing the base's `class_exists` guards (the
 *    platform classes always exist in this package).
 *  - The tool registry resolves per install mode (`tool_registry()`
 *    seam — base `WP_MCP_AI_Tool_Registry` monolith / the AI addon's
 *    `CoreBridge` tools standalone, the established `QueueManager`
 *    pattern); `get_tool`/`get` method_exists-guarded. Standalone
 *    installs without a registered `execute_workflow` tool take the
 *    base's own "no tool registered" degradation branch (identical
 *    envelope).
 *  - Static utility, no hooks of its own — resolved by `Dispatcher`'s
 *    `engine_class()` seam and `WorkflowTriggerCpt`'s engine fallback
 *    standalone.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Graph-aware workflow execution engine (V2).
 *
 * @since 2.1.0
 */
class WorkflowEngine {

	/**
	 * Check whether Engine V2 is enabled.
	 *
	 * Off by default; activate with:
	 *   add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'wp_mcp_ai_workflow_v2_enabled', true );
	}

	/**
	 * Execute a workflow stored as a CPT post.
	 *
	 * Reads the graph from the workflow CPT, delegates to the existing
	 * execute_workflow tool, and fires lifecycle hooks.
	 *
	 * @param int   $workflow_post_id Workflow post ID.
	 * @param array $input            Optional runtime input values.
	 * @param array $context          Optional execution context (assistant_id, etc.).
	 * @return array {
	 *   @type bool   $success  Whether execution succeeded.
	 *   @type string $run_id   Unique run identifier.
	 *   @type array  $results  Step results from the underlying engine.
	 *   @type string $message  Human-readable summary.
	 * }
	 */
	public static function execute( $workflow_post_id, $input = array(), $context = array() ) {
		$workflow_post_id = absint( $workflow_post_id );

		if ( ! static::is_enabled() ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Workflow Engine V2 is disabled.', 'nvoos-content-graph-ai-platform' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) );
		}

		$post = get_post( $workflow_post_id );

		if ( ! $post || static::workflow_cpt_class()::CPT !== $post->post_type ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Workflow post not found.', 'nvoos-content-graph-ai-platform' ) );
		}

		/**
		 * Fires before a V2 workflow executes.
		 *
		 * @param int   $workflow_post_id Workflow post ID.
		 * @param array $input            Runtime input.
		 */
		do_action( 'wp_mcp_ai_workflow_v2_before_execute', $workflow_post_id, $input );

		$run_id     = 'wf2-' . $workflow_post_id . '-' . bin2hex( random_bytes( 6 ) );
		$cpt_run_id = null;
		$budget     = array();

		// Phase 4 — create durable run record.
		$run_cpt_class = static::run_cpt_class();
		if ( null !== $run_cpt_class ) {
			if ( isset( $context['run_budget'] ) && is_array( $context['run_budget'] ) ) {
				$budget = $context['run_budget'];
			}

			$cpt_run_id_or_error = $run_cpt_class::create_run(
				$workflow_post_id,
				$input,
				$budget,
				$context
			);

			if ( ! is_wp_error( $cpt_run_id_or_error ) ) {
				$cpt_run_id = $cpt_run_id_or_error;
				$run_cpt_class::set_status( $cpt_run_id, 'running' );
				$run_cpt_class::append_event(
					$cpt_run_id,
					'step_started',
					'workflow-root',
					'agent',
					array(
						'workflow_id' => $workflow_post_id,
						'run_id'      => $run_id,
					)
				);
			}
		}

		$graph = static::workflow_cpt_class()::get_graph( $workflow_post_id );

		// Build a description from graph nodes for the underlying engine.
		$node_descriptions = array();
		if ( ! empty( $graph['nodes'] ) ) {
			foreach ( $graph['nodes'] as $node ) {
				$node_label = isset( $node['label'] ) ? sanitize_text_field( $node['label'] ) : '';
				$node_type  = isset( $node['type'] ) ? sanitize_text_field( $node['type'] ) : 'tool';
				if ( $node_label ) {
					$node_descriptions[] = '[' . $node_type . '] ' . $node_label;
				}
			}
		}

		$description = $post->post_title;
		if ( ! empty( $node_descriptions ) ) {
			$description .= ': ' . implode( ' -> ', $node_descriptions );
		}

		// Translate graph into arguments for the existing execute_workflow tool.
		$arguments = array(
			'description' => $description,
			'task_type'   => isset( $context['task_type'] ) ? sanitize_text_field( $context['task_type'] ) : 'generic',
			'parallel'    => ! empty( $graph['nodes'] ) && self::has_parallel_nodes( $graph ),
			'context'     => array_merge( $input, array( 'run_id' => $run_id ) ),
		);

		$results = array();
		$success = false;
		$message = '';

		// Delegate to the existing execute_workflow tool registry entry if available.
		$registry = static::tool_registry();
		$tool     = null;

		if ( $registry ) {
			$tool = method_exists( $registry, 'get_tool' )
				? $registry->get_tool( 'execute_workflow' )
				: $registry->get( 'execute_workflow' );

			if ( $tool ) {
				$tool_result = $tool->execute( $arguments, $context );

				if ( is_wp_error( $tool_result ) ) {
					$message = $tool_result->get_error_message();

					// Phase 4 — record failure.
					if ( null !== $run_cpt_class && $cpt_run_id ) {
						$run_cpt_class::set_status( $cpt_run_id, 'failed' );
						$run_cpt_class::append_event(
							$cpt_run_id,
							'step_errored',
							'workflow-root',
							'agent',
							array( 'error' => $message )
						);
					}
				} else {
					$success = isset( $tool_result['success'] ) ? (bool) $tool_result['success'] : true;
					$results = is_array( $tool_result ) ? $tool_result : array();
					$message = isset( $tool_result['message'] ) ? $tool_result['message'] : __( 'Workflow completed.', 'nvoos-content-graph-ai-platform' );

					// Phase 4 — record success.
					if ( null !== $run_cpt_class && $cpt_run_id ) {
						$run_cpt_class::set_status( $cpt_run_id, $success ? 'completed' : 'failed' );
						$run_cpt_class::append_event(
							$cpt_run_id,
							$success ? 'step_finished' : 'step_errored',
							'workflow-root',
							'agent',
							array( 'message' => $message )
						);
					}
				}
			} else {
				$success = true;
				$message = __( 'Graph executed (no execute_workflow tool registered).', 'nvoos-content-graph-ai-platform' );
				$results = array(
					'graph' => $graph,
					'input' => $input,
				);

				// Phase 4 — record success (no tool needed).
				if ( null !== $run_cpt_class && $cpt_run_id ) {
					$run_cpt_class::set_status( $cpt_run_id, 'completed' );
					$run_cpt_class::append_event(
						$cpt_run_id,
						'step_finished',
						'workflow-root',
						'agent',
						array( 'message' => $message )
					);
				}
			}
		} else {
			$success = true;
			$message = __( 'Graph executed (tool registry unavailable).', 'nvoos-content-graph-ai-platform' );
			$results = array(
				'graph' => $graph,
				'input' => $input,
			);

			// Phase 4 — record success (registry unavailable).
			if ( null !== $run_cpt_class && $cpt_run_id ) {
				$run_cpt_class::set_status( $cpt_run_id, 'completed' );
				$run_cpt_class::append_event(
					$cpt_run_id,
					'step_finished',
					'workflow-root',
					'agent',
					array( 'message' => $message )
				);
			}
		}

		$result = array(
			'success'    => $success,
			'run_id'     => $run_id,
			'cpt_run_id' => $cpt_run_id,
			'results'    => $results,
			'message'    => $message,
		);

		/**
		 * Fires after a V2 workflow executes.
		 *
		 * @param int   $workflow_post_id Workflow post ID.
		 * @param array $result           Execution result.
		 */
		do_action( 'wp_mcp_ai_workflow_v2_after_execute', $workflow_post_id, $result );

		return $result;
	}

	/**
	 * Detect whether any graph nodes are marked for parallel execution.
	 *
	 * @param array $graph Graph array with `nodes` key.
	 * @return bool
	 */
	private static function has_parallel_nodes( $graph ) {
		if ( empty( $graph['nodes'] ) || ! is_array( $graph['nodes'] ) ) {
			return false;
		}

		foreach ( $graph['nodes'] as $node ) {
			$type = isset( $node['type'] ) ? $node['type'] : '';
			if ( 'parallel' === $type ) {
				return true;
			}
		}

		return false;
	}

	// ── Per-mode collaborator seams ───────────────────────────────────────────

	/**
	 * Resolve the workflow CPT class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function workflow_cpt_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Workflow_CPT' ) ) {
			return 'WP_MCP_AI_Workflow_CPT';
		}

		if ( class_exists( __NAMESPACE__ . '\WorkflowCpt' ) ) {
			return __NAMESPACE__ . '\WorkflowCpt';
		}

		return null;
	}

	/**
	 * Resolve the workflow run CPT class per install mode.
	 *
	 * @return string|null Fully-qualified class name, or null when unavailable.
	 */
	protected static function run_cpt_class(): ?string {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Workflow_Run_CPT' ) ) {
			return 'WP_MCP_AI_Workflow_Run_CPT';
		}

		if ( class_exists( __NAMESPACE__ . '\WorkflowRunCpt' ) ) {
			return __NAMESPACE__ . '\WorkflowRunCpt';
		}

		return null;
	}

	/**
	 * Resolve the tool registry per install mode.
	 *
	 * The base plugin's registry owns the `execute_workflow` tool
	 * monolith; standalone resolves the AI addon's `CoreBridge` tool
	 * collection (the established `QueueManager` seam).
	 *
	 * @return object|null Registry object, or null when unavailable.
	 */
	protected static function tool_registry() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return \WP_MCP_AI_Tool_Registry::get_instance();
		}

		if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			return \NvoosContentGraphAi\CoreBridge::instance()->tools;
		}

		return null;
	}
}
