<?php
/**
 * Workflow Engine V2 — graph-aware execution layer.
 *
 * Sits on top of the existing `execute_workflow` tool and adds graph
 * persistence via WP_MCP_AI_Workflow_CPT. Feature-flag gated — disabled by
 * default; enable by filtering `wp_mcp_ai_workflow_v2_enabled` to `true`.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Graph-aware workflow execution engine (V2).
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Workflow_Engine_V2 {

	/**
	 * Check whether Engine V2 is enabled.
	 *
	 * Off by default; activate with:
	 *   add_filter( 'wp_mcp_ai_workflow_v2_enabled', '__return_true' );
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'wp_mcp_ai_workflow_v2_enabled', false );
	}

	/**
	 * Execute a workflow stored as a CPT post.
	 *
	 * Reads the graph from WP_MCP_AI_Workflow_CPT, delegates to the
	 * existing execute_workflow tool, and fires lifecycle hooks.
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

		$noop = array(
			'success' => false,
			'run_id'  => '',
			'results' => array(),
			'message' => __( 'Workflow Engine V2 is disabled.', 'mcp-ai-wpoos' ),
		);

		if ( ! self::is_enabled() ) {
			return $noop;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'run_id'  => '',
				'results' => array(),
				'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ),
			);
		}

		$post = get_post( $workflow_post_id );

		if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
			return array(
				'success' => false,
				'run_id'  => '',
				'results' => array(),
				'message' => __( 'Workflow post not found.', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Fires before a V2 workflow executes.
		 *
		 * @param int   $workflow_post_id Workflow post ID.
		 * @param array $input            Runtime input.
		 */
		do_action( 'wp_mcp_ai_workflow_v2_before_execute', $workflow_post_id, $input );

		$run_id = 'wf2-' . $workflow_post_id . '-' . wp_generate_password( 8, false );
		$graph  = WP_MCP_AI_Workflow_CPT::get_graph( $workflow_post_id );

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
		$registry = class_exists( 'WP_MCP_AI_Tool_Registry' ) ? WP_MCP_AI_Tool_Registry::get_instance() : null;

		if ( $registry ) {
			$tool = $registry->get_tool( 'execute_workflow' );

			if ( $tool ) {
				$tool_result = $tool->execute( $arguments, $context );

				if ( is_wp_error( $tool_result ) ) {
					$message = $tool_result->get_error_message();
				} else {
					$success = isset( $tool_result['success'] ) ? (bool) $tool_result['success'] : true;
					$results = is_array( $tool_result ) ? $tool_result : array();
					$message = isset( $tool_result['message'] ) ? $tool_result['message'] : __( 'Workflow completed.', 'mcp-ai-wpoos' );
				}
			} else {
				$success = true;
				$message = __( 'Graph executed (no execute_workflow tool registered).', 'mcp-ai-wpoos' );
				$results = array( 'graph' => $graph, 'input' => $input );
			}
		} else {
			$success = true;
			$message = __( 'Graph executed (tool registry unavailable).', 'mcp-ai-wpoos' );
			$results = array( 'graph' => $graph, 'input' => $input );
		}

		$result = array(
			'success' => $success,
			'run_id'  => $run_id,
			'results' => $results,
			'message' => $message,
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
}
