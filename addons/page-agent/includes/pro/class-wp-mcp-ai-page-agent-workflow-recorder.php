<?php
/**
 * Workflow Recorder (Pro Feature — Phase 2)
 *
 * Records Page Agent sessions as workflow runs and enables
 * replay, scheduling, and analytics.
 *
 * Gated by: defined( 'WP_MCP_AI_PRO_ACTIVE' ) && WP_MCP_AI_PRO_ACTIVE
 *
 * @package NV_oOS_Page_Agent
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records and manages Page Agent workflow sessions.
 *
 * @since 0.2.0
 */
class WP_MCP_AI_Page_Agent_Workflow_Recorder {

	/**
	 * Option key for stored workflows.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_page_agent_workflows';

	/**
	 * Maximum number of workflow recordings to store.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	const MAX_WORKFLOWS = 50;

	/**
	 * Constructor — registers WordPress hooks.
	 *
	 * @since 0.2.0
	 */
	public function __construct() {
		add_action( 'wp_mcp_ai_page_agent_workflow_recorded', array( $this, 'prune_old_workflows' ) );
	}

	/**
	 * Record a Page Agent session.
	 *
	 * @since 0.2.0
	 *
	 * @param array $session Session data including instruction, steps, and result.
	 * @return string|WP_Error Workflow ID on success, WP_Error on failure.
	 */
	public function record_session( $session ) {
		$defaults = array(
			'instruction'   => '',
			'steps'         => array(),
			'result'        => null,
			'started_at'    => current_time( 'c' ),
			'completed_at'  => current_time( 'c' ),
			'duration_ms'   => 0,
			'url'           => '',
			'user_id'       => get_current_user_id(),
			'model'         => '',
			'status'        => 'completed',
		);

		$session = wp_parse_args( $session, $defaults );
		$session['id'] = wp_generate_uuid4();

		$workflows   = get_option( self::OPTION_KEY, array() );
		$workflows[] = $session;

		// Trim to max.
		if ( count( $workflows ) > self::MAX_WORKFLOWS ) {
			$workflows = array_slice( $workflows, -self::MAX_WORKFLOWS );
		}

		$updated = update_option( self::OPTION_KEY, $workflows, false );

		if ( ! $updated ) {
			return new WP_Error(
				'workflow_record_failed',
				__( 'Failed to record workflow session.', 'nvoos-page-agent' )
			);
		}

		/**
		 * Fires after a Page Agent workflow session is recorded.
		 *
		 * @since 0.2.0
		 *
		 * @param array $session The recorded session data.
		 */
		do_action( 'wp_mcp_ai_page_agent_workflow_recorded', $session );

		return $session['id'];
	}

	/**
	 * Retrieve a recorded workflow by ID.
	 *
	 * @since 0.2.0
	 *
	 * @param string $workflow_id The workflow ID.
	 * @return array|null The workflow data, or null if not found.
	 */
	public function get_workflow( $workflow_id ) {
		$workflows = get_option( self::OPTION_KEY, array() );

		foreach ( $workflows as $workflow ) {
			if ( isset( $workflow['id'] ) && $workflow['id'] === $workflow_id ) {
				return $workflow;
			}
		}

		return null;
	}

	/**
	 * List recorded workflows with optional filters.
	 *
	 * @since 0.2.0
	 *
	 * @param array $args Query arguments (status, user_id, limit, offset).
	 * @return array
	 */
	public function list_workflows( $args = array() ) {
		$defaults = array(
			'status'  => '',
			'user_id' => 0,
			'limit'   => 20,
			'offset'  => 0,
		);

		$args      = wp_parse_args( $args, $defaults );
		$workflows = get_option( self::OPTION_KEY, array() );

		// Reverse so newest first.
		$workflows = array_reverse( $workflows );

		// Filter by status.
		if ( ! empty( $args['status'] ) ) {
			$workflows = array_filter(
				$workflows,
				function ( $w ) use ( $args ) {
					return isset( $w['status'] ) && $w['status'] === $args['status'];
				}
			);
		}

		// Filter by user.
		if ( ! empty( $args['user_id'] ) ) {
			$workflows = array_filter(
				$workflows,
				function ( $w ) use ( $args ) {
					return isset( $w['user_id'] ) && absint( $w['user_id'] ) === absint( $args['user_id'] );
				}
			);
		}

		// Paginate.
		$total = count( $workflows );
		$workflows = array_slice( $workflows, $args['offset'], $args['limit'] );

		return array(
			'items' => array_values( $workflows ),
			'total' => $total,
		);
	}

	/**
	 * Delete a recorded workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param string $workflow_id The workflow ID to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete_workflow( $workflow_id ) {
		$workflows = get_option( self::OPTION_KEY, array() );

		$workflows = array_filter(
			$workflows,
			function ( $w ) use ( $workflow_id ) {
				return ! isset( $w['id'] ) || $w['id'] !== $workflow_id;
			}
		);

		return update_option( self::OPTION_KEY, array_values( $workflows ), false );
	}

	/**
	 * Prune old workflows when the count exceeds the maximum.
	 *
	 * Hooked to 'wp_mcp_ai_page_agent_workflow_recorded'.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function prune_old_workflows() {
		$workflows = get_option( self::OPTION_KEY, array() );

		if ( count( $workflows ) <= self::MAX_WORKFLOWS ) {
			return;
		}

		// Keep the most recent.
		$workflows = array_slice( $workflows, -self::MAX_WORKFLOWS );
		update_option( self::OPTION_KEY, $workflows, false );
	}

	/**
	 * Get workflow statistics.
	 *
	 * @since 0.2.0
	 *
	 * @return array
	 */
	public function get_statistics() {
		$workflows = get_option( self::OPTION_KEY, array() );

		$total       = count( $workflows );
		$completed   = 0;
		$failed      = 0;
		$total_steps = 0;
		$total_ms    = 0;

		foreach ( $workflows as $w ) {
			if ( isset( $w['status'] ) && 'completed' === $w['status'] ) {
				++$completed;
			} elseif ( isset( $w['status'] ) && 'failed' === $w['status'] ) {
				++$failed;
			}
			$total_steps += isset( $w['steps'] ) ? count( $w['steps'] ) : 0;
			$total_ms    += isset( $w['duration_ms'] ) ? absint( $w['duration_ms'] ) : 0;
		}

		return array(
			'total_workflows'     => $total,
			'completed'           => $completed,
			'failed'              => $failed,
			'total_steps'         => $total_steps,
			'avg_steps'           => $total > 0 ? round( $total_steps / $total, 1 ) : 0,
			'avg_duration_ms'     => $total > 0 ? round( $total_ms / $total ) : 0,
		);
	}
}
