<?php
/**
 * Tool: replay_workflow_run — replay a previous workflow run from its event log.
 *
 * Allows an operator to inspect (dry-run) or re-execute a past workflow run
 * using its stored input and context. Requires `manage_options`. When
 * `dry_run = false` the re-execution is considered destructive and therefore
 * advertises `requires-approval = true` via capability flags.
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replays a previous workflow run from its durable event log.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Tool_Replay_Workflow_Run implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'replay_workflow_run';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Replay Workflow Run', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Replay a previous workflow run from its durable event log. Use dry_run=true (default) to inspect what would happen without side effects; set dry_run=false to re-execute the workflow with the original input.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'run_id'  => array(
					'type'        => 'integer',
					'description' => __( 'The post ID of the workflow run to replay.', 'mcp-ai-wpoos' ),
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'description' => __( 'When true (default), simulate re-execution without side effects. When false, actually re-run the workflow.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'   => array( 'run_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'requires-approval',
			'state-changing',
			'write',
		);
	}

	/**
	 * Execute the replay tool.
	 *
	 * @param array $arguments Tool arguments: run_id (int), dry_run (bool).
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( $arguments, $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos' ) );
		}

		$run_id  = isset( $arguments['run_id'] ) ? absint( $arguments['run_id'] ) : 0;
		$dry_run = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( ! $run_id ) {
			return new WP_Error( 'invalid_run_id', __( 'A valid run_id is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Workflow_Run_CPT' ) ) {
			return new WP_Error(
				'run_cpt_unavailable',
				__( 'Workflow Run CPT is not available.', 'mcp-ai-wpoos' )
			);
		}

		$run = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );

		if ( ! $run ) {
			return new WP_Error(
				'run_not_found',
				sprintf(
					/* translators: %d: run ID */
					__( 'Workflow run #%d not found.', 'mcp-ai-wpoos' ),
					$run_id
				)
			);
		}

		$events         = $run['event_log'];
		$events_replayed = count( $events );
		$original_input  = isset( $run['input'] ) && is_array( $run['input'] ) ? $run['input'] : array();
		$original_context = isset( $run['context'] ) && is_array( $run['context'] ) ? $run['context'] : array();
		$workflow_id     = (int) $run['workflow_id'];

		if ( $dry_run ) {
			// Simulate — describe what would be replayed without executing.
			$summary = array();
			foreach ( $events as $event ) {
				$summary[] = sprintf(
					'[%s] seq=%d node=%s (%s)',
					isset( $event['type'] ) ? sanitize_text_field( $event['type'] ) : '',
					isset( $event['seq'] ) ? (int) $event['seq'] : 0,
					isset( $event['node_id'] ) ? sanitize_text_field( $event['node_id'] ) : '',
					isset( $event['node_type'] ) ? sanitize_text_field( $event['node_type'] ) : ''
				);
			}

			return array(
				'success'          => true,
				'run_id'           => null,
				'original_run_id'  => $run_id,
				'events_replayed'  => $events_replayed,
				'dry_run'          => true,
				'message'          => sprintf(
					/* translators: 1: event count, 2: original run ID */
					__( 'Dry run: %1$d event(s) would be replayed from run #%2$d.', 'mcp-ai-wpoos' ),
					$events_replayed,
					$run_id
				),
				'event_summary'    => $summary,
			);
		}

		// Live replay — requires `requires-approval` to be honoured upstream.
		if ( ! class_exists( 'WP_MCP_AI_Workflow_Engine_V2' ) || ! WP_MCP_AI_Workflow_Engine_V2::is_enabled() ) {
			return new WP_Error(
				'engine_v2_unavailable',
				__( 'Workflow Engine V2 is not available or not enabled.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! $workflow_id ) {
			return new WP_Error(
				'invalid_workflow_id',
				__( 'Original run has no associated workflow ID.', 'mcp-ai-wpoos' )
			);
		}

		// Carry over original budget if present.
		$replay_context = array_merge(
			$original_context,
			array(
				'replay_of_run_id' => $run_id,
			)
		);

		if ( ! empty( $run['budget'] ) ) {
			$replay_context['run_budget'] = $run['budget'];
		}

		$result = WP_MCP_AI_Workflow_Engine_V2::execute(
			$workflow_id,
			$original_input,
			$replay_context
		);

		$new_run_id = isset( $result['run_id'] ) ? $result['run_id'] : null;

		return array(
			'success'         => isset( $result['success'] ) ? (bool) $result['success'] : false,
			'run_id'          => $new_run_id,
			'original_run_id' => $run_id,
			'events_replayed' => $events_replayed,
			'dry_run'         => false,
			'message'         => isset( $result['message'] ) ? $result['message'] : __( 'Replay initiated.', 'mcp-ai-wpoos' ),
		);
	}
}
