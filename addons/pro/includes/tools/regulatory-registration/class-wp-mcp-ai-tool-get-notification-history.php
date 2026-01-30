<?php
/**
 * Tool for retrieving notification history and audit trail.
 *
 * Allows AI assistants to view sent notification history
 * for audit and tracking purposes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets notification history.
 */
class WP_MCP_AI_Tool_Get_Notification_History implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_notification_history';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Notification History', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves notification history and audit trail with filtering by type, date range, and registration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'notification_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by notification type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'expiry_alert', 'status_change', 'submission_confirmation', 'approval_notice' ),
				),
				'registration_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by registration ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'start_date'        => array(
					'type'        => 'string',
					'description' => __( 'Start date (format: YYYY-MM-DD, optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'          => array(
					'type'        => 'string',
					'description' => __( 'End date (format: YYYY-MM-DD, optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results (optional, default: 50)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
		);
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view notification history.', 'mcp-ai-wpoos-pro' ) );
		}

		$notification_type = ! empty( $arguments['notification_type'] ) ? sanitize_text_field( $arguments['notification_type'] ) : '';
		$registration_id   = ! empty( $arguments['registration_id'] ) ? absint( $arguments['registration_id'] ) : 0;
		$start_date        = ! empty( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date          = ! empty( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$limit             = ! empty( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		// Get notification history.
		$notification_history = get_option( 'wp_mcp_ai_notification_history', array() );

		// Filter results.
		$filtered_history = array();
		foreach ( $notification_history as $entry ) {
			// Filter by type.
			if ( $notification_type && isset( $entry['type'] ) && $entry['type'] !== $notification_type ) {
				continue;
			}

			// Filter by registration ID.
			if ( $registration_id && isset( $entry['registration_id'] ) && absint( $entry['registration_id'] ) !== $registration_id ) {
				continue;
			}

			// Filter by date range.
			if ( $start_date && isset( $entry['timestamp'] ) ) {
				if ( strtotime( $entry['timestamp'] ) < strtotime( $start_date ) ) {
					continue;
				}
			}

			if ( $end_date && isset( $entry['timestamp'] ) ) {
				if ( strtotime( $entry['timestamp'] ) > strtotime( $end_date . ' 23:59:59' ) ) {
					continue;
				}
			}

			$filtered_history[] = $entry;
		}

		// Sort by timestamp (newest first).
		usort(
			$filtered_history,
			function ( $a, $b ) {
				$time_a = isset( $a['timestamp'] ) ? strtotime( $a['timestamp'] ) : 0;
				$time_b = isset( $b['timestamp'] ) ? strtotime( $b['timestamp'] ) : 0;
				return $time_b - $time_a;
			}
		);

		// Apply limit.
		$filtered_history = array_slice( $filtered_history, 0, $limit );

		// Calculate statistics.
		$stats = array(
			'total_notifications' => count( $notification_history ),
			'filtered_results'    => count( $filtered_history ),
			'by_type'             => array(),
		);

		foreach ( $notification_history as $entry ) {
			if ( isset( $entry['type'] ) ) {
				$type = $entry['type'];
				if ( ! isset( $stats['by_type'][ $type ] ) ) {
					$stats['by_type'][ $type ] = 0;
				}
				$stats['by_type'][ $type ]++;
			}
		}

		return array(
			'success'     => true,
			'total'       => count( $filtered_history ),
			'limit'       => $limit,
			'filters'     => array(
				'notification_type' => $notification_type,
				'registration_id'   => $registration_id,
				'start_date'        => $start_date,
				'end_date'          => $end_date,
			),
			'statistics'  => $stats,
			'history'     => $filtered_history,
			'retrieved_at' => current_time( 'mysql' ),
		);
	}
}
