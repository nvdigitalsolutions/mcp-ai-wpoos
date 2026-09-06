<?php
/**
 * Agent Audit Trail — CoSAI Principle 3 (Transparent & Verifiable)
 *
 * Generates structured, immutable audit trails of agent actions — inputs, plans,
 * decisions, tool calls, and outputs — in a format compatible with enterprise
 * observability (OpenTelemetry concepts). Each trail is a cryptographically
 * verifiable event chain stored as WordPress custom post type `mcp_ai_audit_event`
 * with an options-based fallback.
 *
 * Addresses MCP-T12 (Insufficient Logging/Monitoring) by providing a complete
 * decision audit log that can be ingested by SIEM, OTEL collectors, or the
 * Agent Command Center dashboard.
 *
 * @package    WP_MCP_AI
 * @subpackage NvoosContentGraphAiPlatform/src/Agents
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 *
 * CoSAI Alignment:
 *   - Principle 3 (Transparent & Verifiable): Every agent action is recorded
 *     with timestamp, sequence, and cryptographic chain-of-custody.
 *   - MCP-T12 Mitigation: Structured, queryable event log prevents insufficient
 *     monitoring of AI agent decision paths.
 *   - OTEL-compatible: Event fields map to Span (trail_id = trace_id), Events
 *     (step_type + data), and Resource (assistant_id, model).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Audit Trail class.
 *
 * Provides a static API for creating and querying immutable audit trails of
 * agent sessions. Events are stored as WordPress custom post type
 * `mcp_ai_audit_event` with an options-based fallback for environments where
 * CPT registration has not yet occurred.
 *
 * @since 1.2.0
 */
class AgentAuditTrail {

	/**
	 * Custom post type slug.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const CPT_SLUG = 'mcp_ai_audit_event';

	/**
	 * Options key prefix for fallback storage.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_audit_';

	/**
	 * Options key for the trail index mapping session_id to trail_ids.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const SESSION_INDEX_OPTION = 'wp_mcp_ai_audit_session_index';

	/**
	 * Default retention period in days.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Maximum number of option-based events per trail before
	 * rolling into a second option key to avoid hitting option
	 * size limits.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const MAX_EVENTS_PER_OPTION = 500;

	/**
	 * Valid step types.
	 *
	 * @since 1.2.0
	 * @var   array<string>
	 */
	const VALID_STEP_TYPES = array(
		'session_start',
		'decision',
		'tool_call',
		'output',
		'session_end',
	);

	/**
	 * Valid trail statuses for end_session().
	 *
	 * @since 1.2.0
	 * @var   array<string>
	 */
	const VALID_TRAIL_STATUSES = array(
		'success',
		'failure',
		'timeout',
		'cancelled',
		'error',
	);

	/**
	 * Whether the CPT has been registered yet.
	 *
	 * @since 1.2.0
	 * @var   bool|null
	 */
	private static $cpt_registered = null;

	/**
	 * Cached retention days to avoid repeated filter calls.
	 *
	 * @since 1.2.0
	 * @var   int|null
	 */
	private static $retention_days = null;

	// -------------------------------------------------------------------------
	// WordPress Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Initialise the audit trail system.
	 *
	 * Registers the CPT on 'init', schedules the daily pruning cron,
	 * and wires the pruning callback. Called once from agents-init.php
	 * during the plugin boot sequence.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'schedule_pruning' ) );
		add_action( 'wp_mcp_ai_audit_trail_prune', array( __CLASS__, 'run_pruning' ) );
	}

	// -------------------------------------------------------------------------
	// Public API — Session Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Begin a new audit trail for an agent session.
	 *
	 * Creates the trail record and stores an initial 'session_start' event
	 * with the supplied context metadata.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id Unique session identifier (typically a UUID).
	 * @param array  $context    Context metadata. Expected keys:
	 *                           - assistant_id (int)   — Required.
	 *                           - provider     (string) — AI provider slug.
	 *                           - model        (string) — Model identifier.
	 *                           - user_id      (int)    — Optional. WordPress user ID.
	 *                           - role         (string) — Optional. Agent role type.
	 * @return string|WP_Error Trail ID on success, WP_Error on failure.
	 */
	public static function start_session( $session_id, $context ) {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_session',
				__( 'Session ID is required and must not be empty.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$context = self::sanitize_context( $context );

		if ( empty( $context['assistant_id'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_missing_assistant',
				__( 'Context must include assistant_id.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_id = self::generate_trail_id();

		$event = array(
			'trail_id'     => $trail_id,
			'session_id'   => $session_id,
			'assistant_id' => $context['assistant_id'],
			'provider'     => isset( $context['provider'] ) ? sanitize_key( $context['provider'] ) : '',
			'model'        => isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : '',
			'step_type'    => 'session_start',
			'timestamp'    => self::microtime_float(),
			'sequence'     => 1,
			'data'         => wp_json_encode( $context ),
		);

		// Conditionally include optional fields.
		if ( isset( $context['user_id'] ) ) {
			$event['user_id'] = absint( $context['user_id'] );
		}
		if ( isset( $context['role'] ) ) {
			$event['role'] = sanitize_key( $context['role'] );
		}

		$event['event_hash'] = self::compute_event_hash( $event );

		$stored = self::store_event( $event );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// Update session index.
		self::add_trail_to_session_index( $session_id, $trail_id );

		/**
		 * Fires after an audit trail event is persisted to storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $event    The stored event data.
		 * @param string $trail_id The trail identifier.
		 */
		do_action( 'wp_mcp_ai_audit_trail_event_stored', $event, $trail_id );

		return $trail_id;
	}

	/**
	 * Log a decision point — what the LLM decided at a particular step.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier from start_session().
	 * @param string $step     Human-readable step name (e.g., 'task_decomposition', 'tool_selection').
	 * @param array  $data     Decision data including reasoning, alternatives considered, etc.
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	public static function log_decision( $trail_id, $step, $data ) {
		$trail_id = sanitize_key( $trail_id );
		$step     = sanitize_key( $step );

		if ( empty( $trail_id ) || empty( $step ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_decision',
				__( 'Trail ID and step are required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_meta = self::get_trail_meta( $trail_id );
		if ( is_wp_error( $trail_meta ) ) {
			return $trail_meta;
		}

		$sequence = self::get_next_sequence( $trail_id );

		$event = array(
			'trail_id'     => $trail_id,
			'session_id'   => $trail_meta['session_id'],
			'assistant_id' => $trail_meta['assistant_id'],
			'provider'     => $trail_meta['provider'],
			'model'        => $trail_meta['model'],
			'step_type'    => 'decision',
			'step'         => $step,
			'timestamp'    => self::microtime_float(),
			'sequence'     => $sequence,
			'data'         => wp_json_encode( self::sanitize_decision_data( $data ) ),
		);

		$event['event_hash']      = self::compute_event_hash( $event );
		$event['prev_event_hash'] = self::get_last_event_hash( $trail_id );

		$stored = self::store_event( $event );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		/**
		 * Fires after an audit trail event is persisted to storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $event    The stored event data.
		 * @param string $trail_id The trail identifier.
		 */
		do_action( 'wp_mcp_ai_audit_trail_event_stored', $event, $trail_id );

		return $event;
	}

	/**
	 * Log a tool call execution including arguments, result, and duration.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id    Trail identifier from start_session().
	 * @param string $tool_slug   The unique slug of the executed tool.
	 * @param array  $arguments   The sanitized arguments passed to the tool.
	 * @param mixed  $result      The tool's return value (success array or WP_Error).
	 * @param float  $duration_ms Execution duration in milliseconds.
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	public static function log_tool_call( $trail_id, $tool_slug, $arguments, $result, $duration_ms ) {
		$trail_id  = sanitize_key( $trail_id );
		$tool_slug = sanitize_key( $tool_slug );

		if ( empty( $trail_id ) || empty( $tool_slug ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_tool_call',
				__( 'Trail ID and tool slug are required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_meta = self::get_trail_meta( $trail_id );
		if ( is_wp_error( $trail_meta ) ) {
			return $trail_meta;
		}

		$sequence = self::get_next_sequence( $trail_id );

		// Serialize result for storage. WP_Error objects are converted to
		// a structured representation.
		$result_data = array(
			'is_error' => is_wp_error( $result ),
		);

		if ( is_wp_error( $result ) ) {
			$result_data['error_code']    = $result->get_error_code();
			$result_data['error_message'] = $result->get_error_message();
			$result_data['error_data']    = $result->get_error_data();
		} else {
			$result_data['value'] = $result;
		}

		$event = array(
			'trail_id'     => $trail_id,
			'session_id'   => $trail_meta['session_id'],
			'assistant_id' => $trail_meta['assistant_id'],
			'provider'     => $trail_meta['provider'],
			'model'        => $trail_meta['model'],
			'step_type'    => 'tool_call',
			'tool_slug'    => $tool_slug,
			'timestamp'    => self::microtime_float(),
			'sequence'     => $sequence,
			'duration_ms'  => (float) $duration_ms,
			'data'         => wp_json_encode(
				array(
					'arguments' => $arguments,
					'result'    => $result_data,
				)
			),
		);

		$event['event_hash']      = self::compute_event_hash( $event );
		$event['prev_event_hash'] = self::get_last_event_hash( $trail_id );

		$stored = self::store_event( $event );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		/**
		 * Fires after an audit trail event is persisted to storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $event    The stored event data.
		 * @param string $trail_id The trail identifier.
		 */
		do_action( 'wp_mcp_ai_audit_trail_event_stored', $event, $trail_id );

		return $event;
	}

	/**
	 * Log the final output produced by the agent.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier from start_session().
	 * @param mixed  $output   The final output data (string, array, etc.).
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	public static function log_output( $trail_id, $output ) {
		$trail_id = sanitize_key( $trail_id );

		if ( empty( $trail_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_output',
				__( 'Trail ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_meta = self::get_trail_meta( $trail_id );
		if ( is_wp_error( $trail_meta ) ) {
			return $trail_meta;
		}

		$sequence = self::get_next_sequence( $trail_id );

		$event = array(
			'trail_id'     => $trail_id,
			'session_id'   => $trail_meta['session_id'],
			'assistant_id' => $trail_meta['assistant_id'],
			'provider'     => $trail_meta['provider'],
			'model'        => $trail_meta['model'],
			'step_type'    => 'output',
			'timestamp'    => self::microtime_float(),
			'sequence'     => $sequence,
			'data'         => wp_json_encode( array( 'output' => $output ) ),
		);

		$event['event_hash']      = self::compute_event_hash( $event );
		$event['prev_event_hash'] = self::get_last_event_hash( $trail_id );

		$stored = self::store_event( $event );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		/**
		 * Fires after an audit trail event is persisted to storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $event    The stored event data.
		 * @param string $trail_id The trail identifier.
		 */
		do_action( 'wp_mcp_ai_audit_trail_event_stored', $event, $trail_id );

		return $event;
	}

	/**
	 * Close the audit trail with a final status and metadata.
	 *
	 * Stores a 'session_end' event and marks the trail as closed. Once closed,
	 * no further events can be appended to this trail.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier from start_session().
	 * @param string $status   Trail outcome: 'success', 'failure', 'timeout',
	 *                         'cancelled', or 'error'.
	 * @param array  $metadata Optional. Additional close metadata
	 *                         (e.g., total_tokens, cost, duration_total_ms).
	 * @return array|WP_Error Event array on success, WP_Error on failure
	 *                        (including if trail is already closed).
	 */
	public static function end_session( $trail_id, $status, $metadata = array() ) {
		$trail_id = sanitize_key( $trail_id );
		$status   = sanitize_key( $status );

		if ( empty( $trail_id ) || empty( $status ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_end',
				__( 'Trail ID and status are required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! in_array( $status, self::VALID_TRAIL_STATUSES, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_status',
				sprintf(
					/* translators: %s: comma-separated list of valid statuses */
					__( 'Invalid trail status. Valid statuses: %s', 'nvoos-content-graph-ai-platform' ),
					implode( ', ', self::VALID_TRAIL_STATUSES )
				)
			);
		}

		// Check if trail is already closed.
		if ( self::is_trail_closed( $trail_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_trail_closed',
				__( 'This audit trail is already closed and cannot accept new events.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_meta = self::get_trail_meta( $trail_id );
		if ( is_wp_error( $trail_meta ) ) {
			return $trail_meta;
		}

		$sequence = self::get_next_sequence( $trail_id );

		$metadata = is_array( $metadata ) ? $metadata : array();
		$metadata = array_merge( $metadata, array( 'closed_at' => self::microtime_float() ) );

		$event = array(
			'trail_id'     => $trail_id,
			'session_id'   => $trail_meta['session_id'],
			'assistant_id' => $trail_meta['assistant_id'],
			'provider'     => $trail_meta['provider'],
			'model'        => $trail_meta['model'],
			'step_type'    => 'session_end',
			'status'       => $status,
			'timestamp'    => self::microtime_float(),
			'sequence'     => $sequence,
			'data'         => wp_json_encode( $metadata ),
		);

		$event['event_hash']      = self::compute_event_hash( $event );
		$event['prev_event_hash'] = self::get_last_event_hash( $trail_id );

		$stored = self::store_event( $event );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// Mark the trail as closed.
		self::set_trail_closed( $trail_id );

		/**
		 * Fires after an audit trail event is persisted to storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $event    The stored event data.
		 * @param string $trail_id The trail identifier.
		 */
		do_action( 'wp_mcp_ai_audit_trail_event_stored', $event, $trail_id );

		return $event;
	}

	// -------------------------------------------------------------------------
	// Public API — Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the full event chain for a trail.
	 *
	 * Returns events ordered by sequence number ascending. The 'session_start'
	 * and 'session_end' events bookend the chain.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return array|WP_Error Array of event arrays on success, WP_Error on failure.
	 */
	public static function get_trail( $trail_id ) {
		$trail_id = sanitize_key( $trail_id );

		if ( empty( $trail_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_trail_id',
				__( 'Trail ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$store = self::get_event_store();

		if ( 'cpt' === $store ) {
			return self::get_trail_from_cpt( $trail_id );
		}

		return self::get_trail_from_options( $trail_id );
	}

	/**
	 * Retrieve all trail IDs for a given session.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id Session identifier.
	 * @return array|WP_Error Array of trail arrays (each being the full trail) on success.
	 */
	public static function get_trails_by_session( $session_id ) {
		$session_id = sanitize_key( $session_id );

		if ( empty( $session_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_session',
				__( 'Session ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$trail_ids = self::get_session_trail_ids( $session_id );

		if ( empty( $trail_ids ) ) {
			return array();
		}

		$trails = array();
		foreach ( $trail_ids as $trail_id ) {
			$trail = self::get_trail( $trail_id );
			if ( ! is_wp_error( $trail ) ) {
				$trails[] = $trail;
			}
		}

		return $trails;
	}

	/**
	 * Retrieve paginated trails for an assistant.
	 *
	 * Designed to feed the Agent Command Center dashboard. Returns trails
	 * ordered by start timestamp descending (most recent first).
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum number of trails to return. Default 20.
	 * @param int $offset       Pagination offset. Default 0.
	 * @return array|WP_Error Array with 'trails' and 'total' keys on success.
	 */
	public static function get_trails_by_assistant( $assistant_id, $limit = 20, $offset = 0 ) {
		$assistant_id = absint( $assistant_id );
		$limit        = max( 1, min( absint( $limit ), 100 ) );
		$offset       = max( 0, absint( $offset ) );

		if ( empty( $assistant_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_invalid_assistant',
				__( 'Assistant ID is required.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$store = self::get_event_store();

		if ( 'cpt' === $store ) {
			return self::get_trails_by_assistant_from_cpt( $assistant_id, $limit, $offset );
		}

		return self::get_trails_by_assistant_from_options( $assistant_id, $limit, $offset );
	}

	// -------------------------------------------------------------------------
	// CPT Registration & Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Register the audit event custom post type.
	 *
	 * Called during 'init'. The CPT is non-public and accessible only to users
	 * with 'manage_options' capability to enforce immutability.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register_cpt() {
		if ( post_type_exists( self::CPT_SLUG ) ) {
			self::$cpt_registered = true;
			return;
		}

		$labels = array(
			'name'          => __( 'Audit Events', 'nvoos-content-graph-ai-platform' ),
			'singular_name' => __( 'Audit Event', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'            => $labels,
			'public'            => false,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'show_in_nav_menus' => false,
			'show_in_admin_bar' => false,
			'show_in_rest'      => false,
			'capability_type'   => 'post',
			'capabilities'      => array(
				'create_posts'       => 'manage_options',
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'delete_posts'       => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
			),
			'map_meta_cap'      => false, // false prevents WP 6.1+ delete_post _doing_it_wrong notice (same fix as PR #4822 workflow-cpt).
			'has_archive'       => false,
			'hierarchical'      => false,
			'supports'          => array( 'title' ),
			'rewrite'           => false,
			'query_var'         => false,
			'can_export'        => false,
			'delete_with_user'  => false,
		);

		register_post_type( self::CPT_SLUG, $args );

		self::$cpt_registered = true;
	}

	/**
	 * Schedule the auto-pruning cron event.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function schedule_pruning() {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_audit_trail_prune' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_audit_trail_prune' );
		}
	}

	/**
	 * Clear the scheduled pruning cron event on deactivation.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function unschedule_pruning() {
		wp_clear_scheduled_hook( 'wp_mcp_ai_audit_trail_prune' );
	}

	/**
	 * Run auto-pruning: remove events older than the configured retention period.
	 *
	 * Hooked to 'wp_mcp_ai_audit_trail_prune'. Handles both CPT and options-based
	 * storage.
	 *
	 * @since 1.2.0
	 *
	 * @return int Number of events pruned.
	 */
	public static function run_pruning() {
		$retention_days = self::get_retention_days();
		$cutoff         = time() - ( $retention_days * DAY_IN_SECONDS );
		$pruned         = 0;

		$store = self::get_event_store();

		if ( 'cpt' === $store ) {
			$pruned = self::prune_cpt_events( $cutoff );
		}

		$pruned += self::prune_options_events( $cutoff );

		/**
		 * Fires after audit trail pruning completes.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $pruned         Number of events removed.
		 * @param int   $cutoff         Unix timestamp cutoff.
		 * @param int   $retention_days Retention period in days.
		 */
		do_action( 'wp_mcp_ai_audit_trail_pruned', $pruned, $cutoff, $retention_days );

		return $pruned;
	}

	// -------------------------------------------------------------------------
	// Internal — Storage Abstraction
	// -------------------------------------------------------------------------

	/**
	 * Determine which event store is active.
	 *
	 * @since 1.2.0
	 *
	 * @return string 'cpt' if the custom post type is registered, 'options' otherwise.
	 */
	private static function get_event_store() {
		if ( null === self::$cpt_registered ) {
			self::$cpt_registered = post_type_exists( self::CPT_SLUG );
		}

		return self::$cpt_registered ? 'cpt' : 'options';
	}

	/**
	 * Store a single event in the active store.
	 *
	 * Applies the 'wp_mcp_ai_audit_trail_store_event' filter before persisting,
	 * allowing external systems (SIEM, OTEL collector, custom DB) to intercept
	 * the event. If the filter returns false, the event is NOT stored locally.
	 *
	 * @since 1.2.0
	 *
	 * @param array $event Event data to store.
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	private static function store_event( $event ) {
		/**
		 * Filter to redirect audit trail events to external systems.
		 *
		 * Return false to prevent local storage (e.g., when forwarding to
		 * a SIEM or OpenTelemetry collector). Return the event array (possibly
		 * modified) to continue with local storage.
		 *
		 * @since 1.2.0
		 *
		 * @param array|false $event The event data. Return false to skip local storage.
		 * @param string      $store The active store ('cpt' or 'options').
		 */
		$filtered = apply_filters( 'wp_mcp_ai_audit_trail_store_event', $event, self::get_event_store() );

		if ( false === $filtered ) {
			// External system is handling storage; return the original event.
			return $event;
		}

		if ( is_array( $filtered ) ) {
			$event = $filtered;
		}

		$store = self::get_event_store();

		if ( 'cpt' === $store ) {
			return self::store_event_cpt( $event );
		}

		return self::store_event_options( $event );
	}

	/**
	 * Store an event as a custom post type entry.
	 *
	 * @since 1.2.0
	 *
	 * @param array $event Event data.
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	private static function store_event_cpt( $event ) {
		$title = sprintf(
			'%s | %s | #%d | %s',
			$event['trail_id'],
			$event['step_type'],
			$event['sequence'],
			gmdate( 'Y-m-d H:i:s', (int) $event['timestamp'] )
		);

		$post_data = array(
			'post_type'   => self::CPT_SLUG,
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_author' => 0,
			'meta_input'  => array(
				'_wp_mcp_ai_audit_trail_id'     => $event['trail_id'],
				'_wp_mcp_ai_audit_session_id'   => $event['session_id'],
				'_wp_mcp_ai_audit_assistant_id' => $event['assistant_id'],
				'_wp_mcp_ai_audit_provider'     => $event['provider'],
				'_wp_mcp_ai_audit_model'        => $event['model'],
				'_wp_mcp_ai_audit_step_type'    => $event['step_type'],
				'_wp_mcp_ai_audit_timestamp'    => $event['timestamp'],
				'_wp_mcp_ai_audit_sequence'     => $event['sequence'],
				'_wp_mcp_ai_audit_data'         => $event['data'],
				'_wp_mcp_ai_audit_event_hash'   => $event['event_hash'],
			),
		);

		// Include optional meta fields.
		if ( isset( $event['prev_event_hash'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_prev_hash'] = $event['prev_event_hash'];
		}
		if ( isset( $event['step'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_step'] = $event['step'];
		}
		if ( isset( $event['tool_slug'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_tool_slug'] = $event['tool_slug'];
		}
		if ( isset( $event['duration_ms'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_duration_ms'] = $event['duration_ms'];
		}
		if ( isset( $event['status'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_status'] = $event['status'];
		}
		if ( isset( $event['user_id'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_user_id'] = $event['user_id'];
		}
		if ( isset( $event['role'] ) ) {
			$post_data['meta_input']['_wp_mcp_ai_audit_role'] = $event['role'];
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_store_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to store audit event: %s', 'nvoos-content-graph-ai-platform' ),
					$post_id->get_error_message()
				)
			);
		}

		$event['post_id'] = $post_id;

		return $event;
	}

	/**
	 * Store an event in WordPress options (fallback).
	 *
	 * Events are grouped by trail_id in options, with chunking to avoid
	 * hitting the option value size limit.
	 *
	 * @since 1.2.0
	 *
	 * @param array $event Event data.
	 * @return array|WP_Error Event array on success, WP_Error on failure.
	 */
	private static function store_event_options( $event ) {
		$trail_id   = $event['trail_id'];
		$chunk      = (int) floor( ( $event['sequence'] - 1 ) / self::MAX_EVENTS_PER_OPTION );
		$option_key = self::OPTION_PREFIX . 'trail_' . $trail_id . '_chunk_' . $chunk;

		$events = get_option( $option_key, array() );

		if ( ! is_array( $events ) ) {
			$events = array();
		}

		$events[] = $event;

		$updated = update_option( $option_key, $events, false );

		if ( ! $updated ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_options_store_failed',
				__( 'Failed to store audit event in options.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Update trail metadata option.
		self::update_trail_meta_option( $trail_id, $event, $chunk );

		return $event;
	}

	// -------------------------------------------------------------------------
	// Internal — CPT Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a trail's events from CPT storage.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return array|WP_Error Array of events.
	 */
	private static function get_trail_from_cpt( $trail_id ) {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT_SLUG,
				'post_status'    => 'publish',
				'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded audit-trail retrieval per trail; admin-only.
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_wp_mcp_ai_audit_sequence', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key — Indexed by the query below; acceptable for admin-only retrieval.
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query — Indexed by trail_id meta; acceptable for admin-only retrieval.
					array(
						'key'   => '_wp_mcp_ai_audit_trail_id',
						'value' => $trail_id,
					),
				),
			)
		);

		return self::hydrate_events_from_posts( $posts );
	}

	/**
	 * Retrieve trails by assistant from CPT storage.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum number of trails.
	 * @param int $offset       Pagination offset.
	 * @return array Array with 'trails' and 'total' keys.
	 */
	private static function get_trails_by_assistant_from_cpt( $assistant_id, $limit, $offset ) {
		// First, find unique trail_ids for this assistant by querying
		// session_start events.
		$start_posts = get_posts(
			array(
				'post_type'      => self::CPT_SLUG,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_wp_mcp_ai_audit_assistant_id',
						'value' => $assistant_id,
						'type'  => 'NUMERIC',
					),
					array(
						'key'   => '_wp_mcp_ai_audit_step_type',
						'value' => 'session_start',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$trail_ids = array();
		foreach ( $start_posts as $post_id ) {
			$trail_ids[] = get_post_meta( $post_id, '_wp_mcp_ai_audit_trail_id', true );
		}

		$total  = count( $trail_ids );
		$paged  = array_slice( $trail_ids, $offset, $limit );
		$trails = array();

		foreach ( $paged as $trail_id ) {
			$trail = self::get_trail_from_cpt( $trail_id );
			if ( ! is_wp_error( $trail ) ) {
				$trails[] = $trail;
			}
		}

		return array(
			'trails' => $trails,
			'total'  => $total,
		);
	}

	/**
	 * Hydrate event arrays from WP_Post objects.
	 *
	 * @since 1.2.0
	 *
	 * @param array $posts Array of WP_Post objects.
	 * @return array Array of event arrays.
	 */
	private static function hydrate_events_from_posts( $posts ) {
		$events = array();

		foreach ( $posts as $post ) {
			$event = array(
				'post_id'      => $post->ID,
				'trail_id'     => get_post_meta( $post->ID, '_wp_mcp_ai_audit_trail_id', true ),
				'session_id'   => get_post_meta( $post->ID, '_wp_mcp_ai_audit_session_id', true ),
				'assistant_id' => (int) get_post_meta( $post->ID, '_wp_mcp_ai_audit_assistant_id', true ),
				'provider'     => get_post_meta( $post->ID, '_wp_mcp_ai_audit_provider', true ),
				'model'        => get_post_meta( $post->ID, '_wp_mcp_ai_audit_model', true ),
				'step_type'    => get_post_meta( $post->ID, '_wp_mcp_ai_audit_step_type', true ),
				'timestamp'    => (float) get_post_meta( $post->ID, '_wp_mcp_ai_audit_timestamp', true ),
				'sequence'     => (int) get_post_meta( $post->ID, '_wp_mcp_ai_audit_sequence', true ),
				'data'         => get_post_meta( $post->ID, '_wp_mcp_ai_audit_data', true ),
				'event_hash'   => get_post_meta( $post->ID, '_wp_mcp_ai_audit_event_hash', true ),
			);

			$prev_hash = get_post_meta( $post->ID, '_wp_mcp_ai_audit_prev_hash', true );
			if ( ! empty( $prev_hash ) ) {
				$event['prev_event_hash'] = $prev_hash;
			}

			$step = get_post_meta( $post->ID, '_wp_mcp_ai_audit_step', true );
			if ( ! empty( $step ) ) {
				$event['step'] = $step;
			}

			$tool_slug = get_post_meta( $post->ID, '_wp_mcp_ai_audit_tool_slug', true );
			if ( ! empty( $tool_slug ) ) {
				$event['tool_slug'] = $tool_slug;
			}

			$duration_ms = get_post_meta( $post->ID, '_wp_mcp_ai_audit_duration_ms', true );
			if ( '' !== $duration_ms ) {
				$event['duration_ms'] = (float) $duration_ms;
			}

			$status = get_post_meta( $post->ID, '_wp_mcp_ai_audit_status', true );
			if ( ! empty( $status ) ) {
				$event['status'] = $status;
			}

			$events[] = $event;
		}

		return $events;
	}

	// -------------------------------------------------------------------------
	// Internal — Options Retrieval
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a trail's events from options storage.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return array|WP_Error Array of events.
	 */
	private static function get_trail_from_options( $trail_id ) {
		$events    = array();
		$chunk_num = 0;

		while ( true ) {
			$option_key = self::OPTION_PREFIX . 'trail_' . $trail_id . '_chunk_' . $chunk_num;
			$chunk      = get_option( $option_key, null );

			if ( null === $chunk || ! is_array( $chunk ) ) {
				break;
			}

			$events = array_merge( $events, $chunk );
			++$chunk_num;
		}

		if ( empty( $events ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_trail_not_found',
				sprintf(
					/* translators: %s: trail ID */
					__( 'Audit trail not found: %s', 'nvoos-content-graph-ai-platform' ),
					$trail_id
				)
			);
		}

		return $events;
	}

	/**
	 * Retrieve trails by assistant from options storage.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum number of trails.
	 * @param int $offset       Pagination offset.
	 * @return array Array with 'trails' and 'total' keys.
	 */
	private static function get_trails_by_assistant_from_options( $assistant_id, $limit, $offset ) {
		$all_trail_ids = self::get_all_option_trail_ids();

		$matching = array();
		foreach ( $all_trail_ids as $trail_id ) {
			$trail = self::get_trail_from_options( $trail_id );
			if ( is_wp_error( $trail ) ) {
				continue;
			}

			$first_event = reset( $trail );
			if ( $first_event && isset( $first_event['assistant_id'] ) && (int) $first_event['assistant_id'] === $assistant_id ) {
				$matching[] = $trail;
			}
		}

		$total = count( $matching );
		$paged = array_slice( $matching, $offset, $limit );

		return array(
			'trails' => $paged,
			'total'  => $total,
		);
	}

	// -------------------------------------------------------------------------
	// Internal — Pruning
	// -------------------------------------------------------------------------

	/**
	 * Prune CPT events older than the cutoff timestamp.
	 *
	 * @since 1.2.0
	 *
	 * @param int $cutoff Unix timestamp.
	 * @return int Number of events pruned.
	 */
	private static function prune_cpt_events( $cutoff ) {
		$pruned = 0;

		$posts = get_posts(
			array(
				'post_type'      => self::CPT_SLUG,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_mcp_ai_audit_timestamp',
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $posts as $post_id ) {
			$deleted = wp_delete_post( $post_id, true );
			if ( $deleted ) {
				++$pruned;
			}
		}

		return $pruned;
	}

	/**
	 * Prune options-based events older than the cutoff timestamp.
	 *
	 * @since 1.2.0
	 *
	 * @param int $cutoff Unix timestamp.
	 * @return int Number of events pruned.
	 */
	private static function prune_options_events( $cutoff ) {
		global $wpdb;

		$pruned = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
				self::OPTION_PREFIX . 'trail_%'
			)
		);

		foreach ( $options as $option_name ) {
			$events = get_option( $option_name, array() );
			if ( ! is_array( $events ) ) {
				continue;
			}

			$kept = array();
			foreach ( $events as $event ) {
				if ( isset( $event['timestamp'] ) && (float) $event['timestamp'] < $cutoff ) {
					++$pruned;
				} else {
					$kept[] = $event;
				}
			}

			if ( empty( $kept ) ) {
				delete_option( $option_name );
			} elseif ( count( $kept ) !== count( $events ) ) {
				update_option( $option_name, $kept, false );
			}
		}

		// Also prune orphaned closed-trail markers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$closed_options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
				self::OPTION_PREFIX . 'closed_%'
			)
		);

		foreach ( $closed_options as $option_name ) {
			$trail_id = str_replace( self::OPTION_PREFIX . 'closed_', '', $option_name );
			// Check if any events still exist for this trail.
			$first_chunk = self::OPTION_PREFIX . 'trail_' . $trail_id . '_chunk_0';

			if ( false === get_option( $first_chunk, false ) ) {
				delete_option( $option_name );
			}
		}

		return $pruned;
	}

	// -------------------------------------------------------------------------
	// Internal — Helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a unique trail identifier.
	 *
	 * Uses WordPress's built-in UUID generator when available, falling back
	 * to a prefixed unique ID.
	 *
	 * @since 1.2.0
	 *
	 * @return string Trail ID.
	 */
	private static function generate_trail_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'trail_' . wp_generate_uuid4();
		}

		return 'trail_' . uniqid( '', true ) . '_' . wp_rand( 10000, 99999 );
	}

	/**
	 * Get the current time with microsecond precision.
	 *
	 * @since 1.2.0
	 *
	 * @return float Current Unix timestamp with microseconds.
	 */
	private static function microtime_float() {
		return microtime( true );
	}

	/**
	 * Sanitize context array for start_session().
	 *
	 * @since 1.2.0
	 *
	 * @param array $context Raw context data.
	 * @return array Sanitized context.
	 */
	private static function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$sanitized = array();

		if ( isset( $context['assistant_id'] ) ) {
			$sanitized['assistant_id'] = absint( $context['assistant_id'] );
		}

		if ( isset( $context['provider'] ) ) {
			$sanitized['provider'] = sanitize_key( $context['provider'] );
		}

		if ( isset( $context['model'] ) ) {
			$sanitized['model'] = sanitize_text_field( $context['model'] );
		}

		if ( isset( $context['user_id'] ) ) {
			$sanitized['user_id'] = absint( $context['user_id'] );
		}

		if ( isset( $context['role'] ) ) {
			$sanitized['role'] = sanitize_key( $context['role'] );
		}

		// Pass through any additional context keys with sanitization.
		if ( isset( $context['workflow_id'] ) ) {
			$sanitized['workflow_id'] = sanitize_key( $context['workflow_id'] );
		}

		if ( isset( $context['team_id'] ) ) {
			$sanitized['team_id'] = sanitize_key( $context['team_id'] );
		}

		if ( isset( $context['harness_profile'] ) ) {
			$sanitized['harness_profile'] = sanitize_key( $context['harness_profile'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize decision data for log_decision().
	 *
	 * @since 1.2.0
	 *
	 * @param array $data Raw decision data.
	 * @return array Sanitized decision data.
	 */
	private static function sanitize_decision_data( $data ) {
		if ( ! is_array( $data ) ) {
			return array( 'value' => sanitize_text_field( (string) $data ) );
		}

		$sanitized = array();

		if ( isset( $data['reasoning'] ) ) {
			$sanitized['reasoning'] = sanitize_textarea_field( $data['reasoning'] );
		}

		if ( isset( $data['alternatives'] ) && is_array( $data['alternatives'] ) ) {
			$sanitized['alternatives'] = array_map( 'sanitize_text_field', $data['alternatives'] );
		}

		if ( isset( $data['confidence'] ) ) {
			$sanitized['confidence'] = (float) $data['confidence'];
		}

		if ( isset( $data['criteria'] ) ) {
			$sanitized['criteria'] = is_array( $data['criteria'] )
				? array_map( 'sanitize_text_field', $data['criteria'] )
				: sanitize_text_field( $data['criteria'] );
		}

		// Pass through remaining keys with basic sanitization.
		foreach ( $data as $key => $value ) {
			if ( ! isset( $sanitized[ $key ] ) ) {
				if ( is_string( $value ) ) {
					$sanitized[ $key ] = sanitize_text_field( $value );
				} elseif ( is_numeric( $value ) ) {
					$sanitized[ $key ] = $value;
				} elseif ( is_bool( $value ) ) {
					$sanitized[ $key ] = $value;
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Compute a SHA-256 hash of the event for chain-of-custody verification.
	 *
	 * The hash is computed over: trail_id | sequence | step_type | timestamp | data
	 * This allows downstream verification that no event has been tampered with.
	 *
	 * @since 1.2.0
	 *
	 * @param array $event Event data (without hash fields).
	 * @return string Hex-encoded SHA-256 hash.
	 */
	private static function compute_event_hash( $event ) {
		$payload = sprintf(
			'%s|%d|%s|%s|%s',
			$event['trail_id'],
			$event['sequence'],
			$event['step_type'],
			$event['timestamp'],
			$event['data']
		);

		return hash( 'sha256', $payload . wp_salt() );
	}

	/**
	 * Get the hash of the last event in a trail for chain linking.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return string Event hash, or empty string if this is the first event.
	 */
	private static function get_last_event_hash( $trail_id ) {
		$events = self::get_trail( $trail_id );

		if ( is_wp_error( $events ) || empty( $events ) ) {
			return '';
		}

		$last = end( $events );

		return isset( $last['event_hash'] ) ? $last['event_hash'] : '';
	}

	/**
	 * Get metadata for a trail from its session_start event.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return array|WP_Error Trail metadata array on success, WP_Error on failure.
	 */
	private static function get_trail_meta( $trail_id ) {
		$events = self::get_trail( $trail_id );

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		if ( empty( $events ) ) {
			return new \WP_Error(
				'wp_mcp_ai_audit_empty_trail',
				__( 'Audit trail contains no events.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$first = $events[0];

		return array(
			'session_id'   => isset( $first['session_id'] ) ? $first['session_id'] : '',
			'assistant_id' => isset( $first['assistant_id'] ) ? $first['assistant_id'] : 0,
			'provider'     => isset( $first['provider'] ) ? $first['provider'] : '',
			'model'        => isset( $first['model'] ) ? $first['model'] : '',
		);
	}

	/**
	 * Get the next sequence number for a trail.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return int Next sequence number.
	 */
	private static function get_next_sequence( $trail_id ) {
		$events = self::get_trail( $trail_id );

		if ( is_wp_error( $events ) || empty( $events ) ) {
			return 1;
		}

		return count( $events ) + 1;
	}

	/**
	 * Check if a trail has been closed.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return bool True if the trail is closed.
	 */
	private static function is_trail_closed( $trail_id ) {
		$closed_option = self::OPTION_PREFIX . 'closed_' . $trail_id;
		return (bool) get_option( $closed_option, false );
	}

	/**
	 * Mark a trail as closed to prevent further events.
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @return void
	 */
	private static function set_trail_closed( $trail_id ) {
		$closed_option = self::OPTION_PREFIX . 'closed_' . $trail_id;
		update_option( $closed_option, self::microtime_float(), false );
	}

	/**
	 * Add a trail ID to the session index.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id Session identifier.
	 * @param string $trail_id   Trail identifier.
	 * @return void
	 */
	private static function add_trail_to_session_index( $session_id, $trail_id ) {
		$index = get_option( self::SESSION_INDEX_OPTION, array() );

		if ( ! is_array( $index ) ) {
			$index = array();
		}

		if ( ! isset( $index[ $session_id ] ) ) {
			$index[ $session_id ] = array();
		}

		if ( ! in_array( $trail_id, $index[ $session_id ], true ) ) {
			$index[ $session_id ][] = $trail_id;
			update_option( self::SESSION_INDEX_OPTION, $index, false );
		}
	}

	/**
	 * Get trail IDs for a session.
	 *
	 * @since 1.2.0
	 *
	 * @param string $session_id Session identifier.
	 * @return array Array of trail IDs.
	 */
	private static function get_session_trail_ids( $session_id ) {
		$index = get_option( self::SESSION_INDEX_OPTION, array() );

		if ( ! is_array( $index ) || ! isset( $index[ $session_id ] ) ) {
			return array();
		}

		return $index[ $session_id ];
	}

	/**
	 * Update the trail metadata in options (used by options-based storage).
	 *
	 * @since 1.2.0
	 *
	 * @param string $trail_id Trail identifier.
	 * @param array  $event    The stored event.
	 * @param int    $chunk    The chunk number.
	 * @return void
	 */
	private static function update_trail_meta_option( $trail_id, $event, $chunk ) {
		// Track chunk count for retrieval.
		$meta_key = self::OPTION_PREFIX . 'meta_' . $trail_id;
		$meta     = get_option( $meta_key, array() );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		if ( ! isset( $meta['max_chunk'] ) || $chunk > $meta['max_chunk'] ) {
			$meta['max_chunk'] = $chunk;
		}

		if ( ! isset( $meta['event_count'] ) ) {
			$meta['event_count'] = 0;
		}
		++$meta['event_count'];

		update_option( $meta_key, $meta, false );
	}

	/**
	 * Get all trail IDs stored in options.
	 *
	 * @since 1.2.0
	 *
	 * @return array Array of trail IDs.
	 */
	private static function get_all_option_trail_ids() {
		global $wpdb;

		$trail_ids = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$options = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s",
				self::OPTION_PREFIX . 'trail_%_chunk_0'
			)
		);

		foreach ( $options as $option_name ) {
			// Extract trail_id from option name: wp_mcp_ai_audit_trail_{trail_id}_chunk_0.
			$pattern  = '/^' . preg_quote( self::OPTION_PREFIX . 'trail_', '/' ) . '(.+)_chunk_0$/';
			$trail_id = preg_replace( $pattern, '$1', $option_name );

			if ( $trail_id !== $option_name ) {
				$trail_ids[] = $trail_id;
			}
		}

		return $trail_ids;
	}

	/**
	 * Get the configured retention period in days.
	 *
	 * @since 1.2.0
	 *
	 * @return int Retention days.
	 */
	private static function get_retention_days() {
		if ( null !== self::$retention_days ) {
			return self::$retention_days;
		}

		/**
		 * Filter the audit trail retention period in days.
		 *
		 * Events older than this many days are automatically pruned by the
		 * daily cron job.
		 *
		 * @since 1.2.0
		 *
		 * @param int $days Retention period in days. Default 30.
		 */
		self::$retention_days = (int) apply_filters( 'wp_mcp_ai_audit_trail_retention_days', self::DEFAULT_RETENTION_DAYS );

		if ( self::$retention_days < 1 ) {
			self::$retention_days = self::DEFAULT_RETENTION_DAYS;
		}

		return self::$retention_days;
	}
}
