<?php
/**
 * Court Deadline Tracker Tool
 *
 * Tracks court deadlines for matters including filing dates, hearing dates, and discovery cutoffs.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages court deadlines on legal matters.
 */
class WP_MCP_AI_Tool_LF_Court_Deadline_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_court_deadline_tracker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Court Deadline Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Adds, lists, completes, and retrieves upcoming court deadlines on a matter. Supports FRCP, state, and local rule types with priority levels.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Deadline action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'mark_complete', 'get_upcoming' ),
				),
				'matter_id'            => array(
					'type'        => 'integer',
					'description' => __( 'The matter ID to manage deadlines for.', 'mcp-ai-wpoos-pro' ),
				),
				'deadline_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the deadline.', 'mcp-ai-wpoos-pro' ),
				),
				'deadline_date'        => array(
					'type'        => 'string',
					'description' => __( 'Deadline date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'rule_type'            => array(
					'type'        => 'string',
					'description' => __( 'Rule type governing this deadline.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'frcp', 'state', 'local' ),
				),
				'priority'             => array(
					'type'        => 'string',
					'description' => __( 'Deadline priority level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'deadline_id'          => array(
					'type'        => 'string',
					'description' => __( 'Deadline ID or description to identify a specific deadline (for mark_complete action).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( empty( $action ) || ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Action and matter ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$deadlines = get_post_meta( $matter_id, '_lf_deadlines', true );
		if ( ! is_array( $deadlines ) ) {
			$deadlines = array();
		}

		switch ( $action ) {
			case 'add':
				$description = isset( $arguments['deadline_description'] ) ? sanitize_text_field( $arguments['deadline_description'] ) : '';
				$date        = isset( $arguments['deadline_date'] ) ? sanitize_text_field( $arguments['deadline_date'] ) : '';
				$rule_type   = isset( $arguments['rule_type'] ) ? sanitize_text_field( $arguments['rule_type'] ) : 'frcp';
				$priority    = isset( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'medium';

				if ( empty( $description ) || empty( $date ) ) {
					return new WP_Error( 'missing_required', __( 'Deadline description and date are required.', 'mcp-ai-wpoos-pro' ) );
				}

				$deadline = array(
					'id'          => wp_generate_uuid4(),
					'description' => $description,
					'date'        => $date,
					'rule_type'   => $rule_type,
					'priority'    => $priority,
					'completed'   => false,
					'created_by'  => $uid,
					'created_at'  => current_time( 'Y-m-d H:i:s' ),
				);

				$deadlines[] = $deadline;
				update_post_meta( $matter_id, '_lf_deadlines', $deadlines );

				return array(
					'success'    => true,
					'message'    => __( 'Deadline added successfully. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'deadline_id' => $deadline['id'],
						'matter_id'   => $matter_id,
						'description' => $description,
						'date'        => $date,
						'priority'    => $priority,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'list':
				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of deadlines */
						__( '%d deadlines found. ', 'mcp-ai-wpoos-pro' ),
						count( $deadlines )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'deadlines' => $deadlines,
						'total'     => count( $deadlines ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'mark_complete':
				$deadline_id = '';
				if ( isset( $arguments['deadline_id'] ) ) {
					$deadline_id = sanitize_text_field( $arguments['deadline_id'] );
				} elseif ( isset( $arguments['deadline_description'] ) ) {
					$deadline_id = sanitize_text_field( $arguments['deadline_description'] );
				}
				$found = false;

				foreach ( $deadlines as &$dl ) {
					if ( $dl['id'] === $deadline_id || $dl['description'] === $deadline_id ) {
						$dl['completed']    = true;
						$dl['completed_at'] = current_time( 'Y-m-d H:i:s' );
						$dl['completed_by'] = $uid;
						$found              = true;
						break;
					}
				}
				unset( $dl );

				if ( ! $found ) {
					return new WP_Error( 'not_found', __( 'Deadline not found.', 'mcp-ai-wpoos-pro' ) );
				}

				update_post_meta( $matter_id, '_lf_deadlines', $deadlines );

				return array(
					'success'    => true,
					'message'    => __( 'Deadline marked as complete. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'completed' => true,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'get_upcoming':
				$today    = current_time( 'Y-m-d' );
				$upcoming = array();
				foreach ( $deadlines as $dl ) {
					if ( empty( $dl['completed'] ) && $dl['date'] >= $today ) {
						$upcoming[] = $dl;
					}
				}

				// Sort by date ascending.
				usort(
					$upcoming,
					function ( $a, $b ) {
						return strcmp( $a['date'], $b['date'] );
					}
				);

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %d: number of upcoming deadlines */
						__( '%d upcoming deadlines. ', 'mcp-ai-wpoos-pro' ),
						count( $upcoming )
					) . self::DISCLAIMER,
					'data'       => array(
						'matter_id' => $matter_id,
						'upcoming'  => $upcoming,
						'total'     => count( $upcoming ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid deadline action.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}
