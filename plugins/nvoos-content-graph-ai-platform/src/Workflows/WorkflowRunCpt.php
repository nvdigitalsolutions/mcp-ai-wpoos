<?php
/**
 * Workflow Run CPT — durable execution event log (Wave E1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Workflow_Run_CPT`:
 * byte-identical `mcp_ai_workflow_run` CPT args (hidden, `manage_options`
 * capabilities, title + custom-fields supports), the ten run meta keys with
 * the same type schemas, the run lifecycle (create → append events →
 * status transitions with terminal `finished_at` stamping), the event-log
 * read path, the full run-record shape, and the four-dimension budget
 * check with the `wp_mcp_ai_workflow_run_budget_exceeded` action.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Standalone-only hook registration via `Plugin::registerWorkflowCpts()`
 *    (the base loader owns the same `init` registration at priority 13 in
 *    monolith installs).
 *  - The inline meta-key schemas and the terminal-status list are promoted
 *    to named class constants (`META_KEYS`, `TERMINAL_STATUSES` — additive
 *    only, same values).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Workflows
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Workflows;

/**
 * Manages the `mcp_ai_workflow_run` CPT and its durable event log.
 *
 * @since 2.1.0
 */
class WorkflowRunCpt {

	/**
	 * CPT slug constant.
	 */
	const CPT = 'mcp_ai_workflow_run';

	/**
	 * Valid run status values.
	 */
	const STATUSES = array( 'pending', 'running', 'completed', 'failed', 'cancelled' );

	/**
	 * Valid event type values.
	 */
	const EVENT_TYPES = array(
		'step_started',
		'step_finished',
		'step_errored',
		'step_retried',
		'budget_exceeded',
		'checkpoint',
	);

	/**
	 * Meta key map: key => schema type.
	 */
	const META_KEYS = array(
		'_wp_mcp_ai_run_workflow_id' => 'integer',
		'_wp_mcp_ai_run_status'      => 'string',
		'_wp_mcp_ai_run_input'       => 'string',
		'_wp_mcp_ai_run_context'     => 'string',
		'_wp_mcp_ai_run_event_log'   => 'string',
		'_wp_mcp_ai_run_budget'      => 'string',
		'_wp_mcp_ai_run_cost_usd'    => 'number',
		'_wp_mcp_ai_run_tokens_used' => 'integer',
		'_wp_mcp_ai_run_started_at'  => 'integer',
		'_wp_mcp_ai_run_finished_at' => 'integer',
	);

	/**
	 * Terminal statuses (stamp `finished_at`).
	 */
	const TERMINAL_STATUSES = array( 'completed', 'failed', 'cancelled' );

	// ── CPT / meta registration ───────────────────────────────────────────────

	/**
	 * Register the `mcp_ai_workflow_run` CPT.
	 *
	 * Hooked to `init` at priority 13 (mirroring the base loader).
	 *
	 * @return void
	 */
	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'label'              => __( 'Workflow Runs', 'nvoos-content-graph-ai-platform' ),
				'labels'             => array(
					'name'          => __( 'Workflow Runs', 'nvoos-content-graph-ai-platform' ),
					'singular_name' => __( 'Workflow Run', 'nvoos-content-graph-ai-platform' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'capabilities'       => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
				),
				'map_meta_cap'       => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Register post meta keys for the run CPT.
	 *
	 * Hooked to `init` at priority 13 (mirroring the base loader).
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( self::META_KEYS as $key => $type ) {
			register_post_meta(
				self::CPT,
				$key,
				array(
					'type'         => $type,
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}
	}

	// ── Write helpers ─────────────────────────────────────────────────────────

	/**
	 * Create a new workflow run record.
	 *
	 * @param int   $workflow_id Workflow CPT post ID.
	 * @param array $input       Runtime input key/value pairs.
	 * @param array $budget      Budget constraints:
	 *                           {max_cost_usd, max_tokens, max_wall_seconds, max_steps}.
	 * @param array $context     Execution context (assistant_id, etc.).
	 * @return int|\WP_Error New run post ID on success, WP_Error on failure.
	 */
	public static function create_run( $workflow_id, $input = array(), $budget = array(), $context = array() ) {
		$workflow_id = absint( $workflow_id );
		$timestamp   = time();

		$title = sprintf(
			/* translators: 1: workflow ID, 2: human timestamp */
			__( 'Run of workflow #%1$d at %2$s', 'nvoos-content-graph-ai-platform' ),
			$workflow_id,
			gmdate( 'Y-m-d H:i:s', $timestamp )
		);

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => self::CPT,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Update title to include the real post ID.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => sprintf(
					/* translators: 1: run post ID, 2: workflow ID, 3: human timestamp */
					__( 'Run #%1$d of workflow #%2$d at %3$s', 'nvoos-content-graph-ai-platform' ),
					$post_id,
					$workflow_id,
					gmdate( 'Y-m-d H:i:s', $timestamp )
				),
			)
		);

		update_post_meta( $post_id, '_wp_mcp_ai_run_workflow_id', $workflow_id );
		update_post_meta( $post_id, '_wp_mcp_ai_run_status', 'pending' );
		update_post_meta( $post_id, '_wp_mcp_ai_run_input', wp_json_encode( $input ) );
		update_post_meta( $post_id, '_wp_mcp_ai_run_context', wp_json_encode( $context ) );
		update_post_meta( $post_id, '_wp_mcp_ai_run_event_log', wp_json_encode( array() ) );
		update_post_meta( $post_id, '_wp_mcp_ai_run_budget', wp_json_encode( $budget ) );
		update_post_meta( $post_id, '_wp_mcp_ai_run_cost_usd', 0.0 );
		update_post_meta( $post_id, '_wp_mcp_ai_run_tokens_used', 0 );
		update_post_meta( $post_id, '_wp_mcp_ai_run_started_at', $timestamp );
		update_post_meta( $post_id, '_wp_mcp_ai_run_finished_at', 0 );

		return $post_id;
	}

	/**
	 * Append a single event entry to the run's event log.
	 *
	 * @param int    $run_id    Run post ID.
	 * @param string $type      Event type (see self::EVENT_TYPES).
	 * @param string $node_id   Graph node ID.
	 * @param string $node_type Node type: tool|agent|condition.
	 * @param array  $data      Arbitrary event payload.
	 * @return bool True on success, false if the run does not exist.
	 */
	public static function append_event( $run_id, $type, $node_id, $node_type, $data = array() ) {
		$run_id = absint( $run_id );
		$post   = get_post( $run_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}

		$type      = sanitize_key( $type );
		$node_id   = sanitize_text_field( $node_id );
		$node_type = sanitize_text_field( $node_type );

		$existing = self::get_event_log( $run_id );

		$entry = array(
			'seq'       => count( $existing ) + 1,
			'type'      => $type,
			'node_id'   => $node_id,
			'node_type' => $node_type,
			'timestamp' => time(),
			'data'      => is_array( $data ) ? $data : array(),
		);

		$existing[] = $entry;

		return (bool) update_post_meta( $run_id, '_wp_mcp_ai_run_event_log', wp_json_encode( $existing ) );
	}

	/**
	 * Set the run's status.
	 *
	 * Also sets `_wp_mcp_ai_run_finished_at` when transitioning to a terminal
	 * state (completed|failed|cancelled).
	 *
	 * @param int    $run_id Run post ID.
	 * @param string $status One of self::STATUSES.
	 * @return bool True on success, false for invalid status or missing run.
	 */
	public static function set_status( $run_id, $status ) {
		$run_id = absint( $run_id );
		$status = sanitize_key( $status );

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$post = get_post( $run_id );
		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}

		update_post_meta( $run_id, '_wp_mcp_ai_run_status', $status );

		if ( in_array( $status, self::TERMINAL_STATUSES, true ) ) {
			update_post_meta( $run_id, '_wp_mcp_ai_run_finished_at', time() );
		}

		return true;
	}

	// ── Read helpers ──────────────────────────────────────────────────────────

	/**
	 * Retrieve the event log for a run.
	 *
	 * @param int $run_id Run post ID.
	 * @return array Ordered array of event entries (empty if none).
	 */
	public static function get_event_log( $run_id ) {
		$raw = get_post_meta( absint( $run_id ), '_wp_mcp_ai_run_event_log', true );
		if ( ! $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Retrieve a full run record as an associative array.
	 *
	 * @param int $run_id Run post ID.
	 * @return array|false Associative array of run data, or false if not found.
	 */
	public static function get_run( $run_id ) {
		$run_id = absint( $run_id );
		$post   = get_post( $run_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}

		$input   = get_post_meta( $run_id, '_wp_mcp_ai_run_input', true );
		$context = get_post_meta( $run_id, '_wp_mcp_ai_run_context', true );
		$budget  = get_post_meta( $run_id, '_wp_mcp_ai_run_budget', true );

		return array(
			'id'          => $run_id,
			'workflow_id' => (int) get_post_meta( $run_id, '_wp_mcp_ai_run_workflow_id', true ),
			'status'      => get_post_meta( $run_id, '_wp_mcp_ai_run_status', true ),
			'input'       => $input ? json_decode( $input, true ) : array(),
			'context'     => $context ? json_decode( $context, true ) : array(),
			'event_log'   => self::get_event_log( $run_id ),
			'budget'      => $budget ? json_decode( $budget, true ) : array(),
			'cost_usd'    => (float) get_post_meta( $run_id, '_wp_mcp_ai_run_cost_usd', true ),
			'tokens_used' => (int) get_post_meta( $run_id, '_wp_mcp_ai_run_tokens_used', true ),
			'started_at'  => (int) get_post_meta( $run_id, '_wp_mcp_ai_run_started_at', true ),
			'finished_at' => (int) get_post_meta( $run_id, '_wp_mcp_ai_run_finished_at', true ),
			'title'       => $post->post_title,
		);
	}

	/**
	 * Check whether the run is still within its declared budget.
	 *
	 * Fires `wp_mcp_ai_workflow_run_budget_exceeded` (with run ID and
	 * violation details) the first time a budget dimension is breached.
	 *
	 * @param int $run_id Run post ID.
	 * @return bool True if within budget, false if any limit is exceeded.
	 */
	public static function check_budget( $run_id ) {
		$run_id = absint( $run_id );
		$run    = self::get_run( $run_id );

		if ( ! $run ) {
			return false;
		}

		$budget = isset( $run['budget'] ) && is_array( $run['budget'] ) ? $run['budget'] : array();

		if ( empty( $budget ) ) {
			return true;
		}

		$violations = array();

		// Cost ceiling.
		if ( isset( $budget['max_cost_usd'] ) && $budget['max_cost_usd'] > 0 ) {
			if ( $run['cost_usd'] > (float) $budget['max_cost_usd'] ) {
				$violations['cost_usd'] = array(
					'limit'   => (float) $budget['max_cost_usd'],
					'current' => $run['cost_usd'],
				);
			}
		}

		// Token ceiling.
		if ( isset( $budget['max_tokens'] ) && $budget['max_tokens'] > 0 ) {
			if ( $run['tokens_used'] > (int) $budget['max_tokens'] ) {
				$violations['tokens_used'] = array(
					'limit'   => (int) $budget['max_tokens'],
					'current' => $run['tokens_used'],
				);
			}
		}

		// Wall-clock ceiling.
		if ( isset( $budget['max_wall_seconds'] ) && $budget['max_wall_seconds'] > 0 ) {
			$elapsed = time() - $run['started_at'];
			if ( $elapsed > (int) $budget['max_wall_seconds'] ) {
				$violations['wall_seconds'] = array(
					'limit'   => (int) $budget['max_wall_seconds'],
					'current' => $elapsed,
				);
			}
		}

		// Step ceiling.
		if ( isset( $budget['max_steps'] ) && $budget['max_steps'] > 0 ) {
			$step_count = count( $run['event_log'] );
			if ( $step_count > (int) $budget['max_steps'] ) {
				$violations['steps'] = array(
					'limit'   => (int) $budget['max_steps'],
					'current' => $step_count,
				);
			}
		}

		if ( ! empty( $violations ) ) {
			/**
			 * Fires when a workflow run exceeds one or more budget limits.
			 *
			 * @param int   $run_id     Run post ID.
			 * @param array $violations Map of violated dimension => {limit, current}.
			 */
			do_action( 'wp_mcp_ai_workflow_run_budget_exceeded', $run_id, $violations );
			return false;
		}

		return true;
	}
}
