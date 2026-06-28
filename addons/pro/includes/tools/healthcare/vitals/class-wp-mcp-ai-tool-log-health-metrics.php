<?php
/**
 * Tool for logging and retrieving daily health & wellness metrics.
 *
 * Supports two actions: 'log' (save daily metrics) and 'get_history'
 * (retrieve a date-range of previously logged entries).  Metrics are stored
 * in a WordPress option keyed by member ID so they persist across sessions
 * and devices, matching the pattern used by log_vital_signs.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log and retrieve daily health & wellness metrics (steps, water, sleep, calories, sodium, mood).
 */
class WP_MCP_AI_Tool_Log_Health_Metrics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Performs the operation.
	 *
 * @var string Option key prefix used for server-side storage.
 */
	const OPTION_KEY_PREFIX = 'wp_mcp_ai_health_metrics_';

	/**
	 * Performs the operation.
	 *
 * @var int Maximum number of daily entries stored per member.
 */
	const MAX_ENTRIES = 1000;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'log_health_metrics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Log Health Metrics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Logs or retrieves daily health & wellness metrics (steps, water intake, sleep, calories, sodium, mood) for a member. Use action "log" to save a day\'s data and "get_history" to retrieve historical entries.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'    => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: "log" to save metrics or "get_history" to retrieve past entries (default: "log")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'log', 'get_history' ),
					'default'     => 'log',
				),
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'date'      => array(
					'type'        => 'string',
					'description' => __( 'Date in YYYY-MM-DD format (required for log; defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'steps'     => array(
					'type'        => 'integer',
					'description' => __( 'Step count for the day (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'water'     => array(
					'type'        => 'integer',
					'description' => __( 'Glasses of water consumed (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'sleep'     => array(
					'type'        => 'number',
					'description' => __( 'Hours of sleep (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 24,
				),
				'calories'  => array(
					'type'        => 'integer',
					'description' => __( 'Calories consumed in kcal (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'sodium'    => array(
					'type'        => 'integer',
					'description' => __( 'Sodium intake in mg (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'mood'      => array(
					'type'        => 'integer',
					'description' => __( 'Mood rating 1–5 (1 = very poor, 5 = excellent; 0 means not recorded) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 5,
				),
				'days_back' => array(
					'type'        => 'integer',
					'description' => __( 'Number of days of history to retrieve (for get_history, default: 30, max: 365)', 'mcp-ai-wpoos-pro' ),
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 365,
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_member',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'patient', 'wellness_coach' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access health metrics.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify the member CPT record exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'log';

		switch ( $action ) {
			case 'log':
				return $this->log_metrics( $arguments, $member_id, $current_user_id );

			case 'get_history':
				$days_back = isset( $arguments['days_back'] ) ? absint( $arguments['days_back'] ) : 30;
				if ( $days_back < 1 ) {
					$days_back = 30;
				}
				if ( $days_back > 365 ) {
					$days_back = 365;
				}
				return $this->get_history( $member_id, $days_back );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action. Use "log" or "get_history".', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Save one day's health metrics for a member.
	 *
	 * The entry is keyed by date so that re-submitting the same day overwrites
	 * the existing record (idempotent upsert).
	 *
	 * @param array $arguments       Tool arguments.
	 * @param int   $member_id       Member post ID.
	 * @param int   $current_user_id Logged-in user ID.
	 * @return array Result data.
	 */
	private function log_metrics( array $arguments, $member_id, $current_user_id ) {
		$date = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );

		// Validate date format.
		if ( $date && ! $this->validate_date( $date ) ) {
			$date = current_time( 'Y-m-d' );
		}

		$entry = array(
			'date'      => $date,
			'steps'     => isset( $arguments['steps'] ) ? absint( $arguments['steps'] ) : 0,
			'water'     => isset( $arguments['water'] ) ? absint( $arguments['water'] ) : 0,
			'sleep'     => isset( $arguments['sleep'] ) ? round( floatval( $arguments['sleep'] ), 1 ) : 0,
			'calories'  => isset( $arguments['calories'] ) ? absint( $arguments['calories'] ) : 0,
			'sodium'    => isset( $arguments['sodium'] ) ? absint( $arguments['sodium'] ) : 0,
			'mood'      => isset( $arguments['mood'] ) ? min( 5, max( 0, absint( $arguments['mood'] ) ) ) : 0,
			'logged_by' => $current_user_id,
			'logged_at' => current_time( 'mysql' ),
		);

		$option_key = self::OPTION_KEY_PREFIX . $member_id;
		$all_data   = get_option( $option_key, array() );

		if ( ! is_array( $all_data ) ) {
			$all_data = array();
		}

		// Upsert keyed by date.
		$all_data[ $date ] = $entry;

		// Trim to the most recent MAX_ENTRIES days (sort descending, keep newest).
		if ( count( $all_data ) > self::MAX_ENTRIES ) {
			krsort( $all_data );
			$all_data = array_slice( $all_data, 0, self::MAX_ENTRIES, true );
		}

		update_option( $option_key, $all_data );

		return array(
			'success'   => true,
			'message'   => __( 'Health metrics logged successfully.', 'mcp-ai-wpoos-pro' ),
			'member_id' => $member_id,
			'entry'     => $entry,
		);
	}

	/**
	 * Retrieve daily health metrics for the requested date range.
	 *
	 * Returns entries sorted oldest-first so the caller can iterate
	 * chronologically (matching log_vital_signs behaviour).
	 *
	 * @param int $member_id Member post ID.
	 * @param int $days_back How many days back to include.
	 * @return array Result data with 'history' array.
	 */
	private function get_history( $member_id, $days_back ) {
		$option_key = self::OPTION_KEY_PREFIX . $member_id;
		$all_data   = get_option( $option_key, array() );

		if ( ! is_array( $all_data ) || empty( $all_data ) ) {
			return array(
				'success'   => true,
				'member_id' => $member_id,
				'days_back' => $days_back,
				'count'     => 0,
				'history'   => array(),
			);
		}

		$cutoff = gmdate( 'Y-m-d', time() - ( $days_back * DAY_IN_SECONDS ) );

		$filtered = array();
		foreach ( $all_data as $date => $entry ) {
			if ( $date >= $cutoff ) {
				$filtered[ $date ] = $entry;
			}
		}

		// Sort oldest-first.
		ksort( $filtered );

		return array(
			'success'   => true,
			'member_id' => $member_id,
			'days_back' => $days_back,
			'count'     => count( $filtered ),
			'history'   => array_values( $filtered ),
		);
	}

	/**
	 * Validate YYYY-MM-DD date string.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
