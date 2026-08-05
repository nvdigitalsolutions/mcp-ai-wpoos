<?php
/**
 * Sync From JetBooking Tool
 *
 * Imports JetBooking bookings as mcp_appointment CPT posts.
 * Uses _jetbooking_id meta for idempotent re-sync.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since     1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs JetBooking bookings into the NV oOS calendar.
 *
 * Maps daily bookings to multi-day appointments, uses _jetbooking_id
 * post meta as a foreign key for idempotent re-sync.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Sync_From_JetBooking implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_calendar_booking_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Calendar Booking toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_from_jetbooking';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync From JetBooking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import bookings from JetBooking into the NV oOS calendar. Uses _jetbooking_id meta to prevent duplicate imports.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from' => array(
					'type'        => 'string',
					'description' => __( 'Start date for import range (Y-m-d). Default: 7 days ago.', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'End date for import range (Y-m-d). Default: 90 days from now.', 'mcp-ai-wpoos-pro' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by booking status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'on-hold', 'confirmed', 'cancelled', 'completed', 'all' ),
					'default'     => 'all',
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum bookings to import (default: 100, max: 500).', 'mcp-ai-wpoos-pro' ),
					'default'     => 100,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'database-read', 'phase-1.5' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'toolkit_not_available', self::get_unavailable_reason() );
		}

		if ( ! class_exists( 'WP_MCP_AI_Booking_Adapter_Factory' ) || ! WP_MCP_AI_Booking_Adapter_Factory::has_jetbooking() ) {
			return new WP_Error(
				'jetbooking_unavailable',
				__( 'JetBooking adapter is not available. Ensure JetBooking is installed and configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$adapter   = WP_MCP_AI_Booking_Adapter_Factory::get_jetbooking();
		$date_from = ! empty( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$date_to   = ! empty( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d', strtotime( '+90 days' ) );
		$status    = ! empty( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'all';
		$limit     = ! empty( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 500 ) : 100;

		$filters = array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);

		if ( 'all' !== $status ) {
			$filters['status'] = $status;
		}

		$result = $adapter->get_bookings( $filters, $limit );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$created = 0;
		$updated = 0;
		$errors  = array();

		foreach ( $result['items'] as $item ) {
			$jb_id = absint( $item['id'] );

			$existing = get_posts(
				array(
					'post_type'      => 'mcp_appointment',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_jetbooking_id',
					'meta_value'     => $jb_id,
					'fields'         => 'ids',
				)
			);

			$post_title = sprintf(
				/* translators: 1: unit title, 2: instance ID */
				__( 'Booking: %1$s (Instance #%2$d)', 'mcp-ai-wpoos-pro' ),
				! empty( $item['unit_title'] ) ? $item['unit_title'] : __( 'Unit', 'mcp-ai-wpoos-pro' ),
				absint( $item['instance_id'] )
			);

			$post_data = array(
				'post_type'   => 'mcp_appointment',
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_author' => ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id(),
			);

			if ( ! empty( $existing ) ) {
				$post_data['ID'] = $existing[0];
				$post_id         = wp_update_post( $post_data, true );
				if ( is_wp_error( $post_id ) ) {
					$errors[] = $post_id->get_error_message();
					continue;
				}
				++$updated;
			} else {
				$post_id = wp_insert_post( $post_data, true );
				if ( is_wp_error( $post_id ) ) {
					$errors[] = $post_id->get_error_message();
					continue;
				}
				++$created;
			}

			update_post_meta( $post_id, '_jetbooking_id', $jb_id );
			update_post_meta( $post_id, '_start_time', $item['check_in_date'] . ' 00:00:00' );
			update_post_meta( $post_id, '_end_time', $item['check_out_date'] . ' 23:59:59' );
			update_post_meta( $post_id, '_status', $this->map_status( $item['status'] ) );
			update_post_meta( $post_id, '_client_email', $item['user_email'] );
			update_post_meta( $post_id, '_appointment_type', 'booking' );
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: created, 2: updated */
				__( 'Sync complete: %1$d created, %2$d updated.', 'mcp-ai-wpoos-pro' ),
				$created,
				$updated
			),
			'created' => $created,
			'updated' => $updated,
			'errors'  => $errors,
		);
	}

	/**
	 * Map JetBooking status to NV oOS status.
	 *
	 * @param string $jb_status JetBooking status.
	 * @return string
	 */
	private function map_status( $jb_status ) {
		$map = array(
			'on-hold'   => 'pending',
			'confirmed' => 'confirmed',
			'cancelled' => 'cancelled',
			'completed' => 'completed',
		);
		return isset( $map[ $jb_status ] ) ? $map[ $jb_status ] : 'pending';
	}
}
