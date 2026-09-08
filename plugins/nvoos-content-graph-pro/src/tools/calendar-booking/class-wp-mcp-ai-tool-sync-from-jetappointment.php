<?php
/**
 * JetAppointment/JetBooking sync tool (ecosystem port — Wave F2, calendar jet-sync batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-from-jetappointment.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync appointments from JetAppointment into NV oOS mcp_appointment CPT.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Sync_From_JetAppointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Calendar Booking toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_from_jetappointment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync From JetAppointment', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import appointments from JetAppointment into the NV oOS calendar. Uses _jetappointment_id meta to prevent duplicate imports on re-sync.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Start date for import range (Y-m-d). Default: 7 days ago.', 'nvoos-content-graph-pro' ),
				),
				'date_to'   => array(
					'type'        => 'string',
					'description' => __( 'End date for import range (Y-m-d). Default: 30 days from now.', 'nvoos-content-graph-pro' ),
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by appointment status.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'pending', 'confirmed', 'cancelled', 'all' ),
					'default'     => 'all',
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum appointments to import (default: 100, max: 500).', 'nvoos-content-graph-pro' ),
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

		if ( ! class_exists( 'WP_MCP_AI_Booking_Adapter_Factory' ) || ! WP_MCP_AI_Booking_Adapter_Factory::has_jetappointment() ) {
			return new WP_Error(
				'jetappointment_unavailable',
				__( 'JetAppointment adapter is not available. Ensure JetAppointment is installed and configured.', 'nvoos-content-graph-pro' )
			);
		}

		$adapter   = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
		$date_from = ! empty( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$date_to   = ! empty( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : gmdate( 'Y-m-d', strtotime( '+30 days' ) );
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
		$skipped = 0;
		$errors  = array();

		foreach ( $result['items'] as $item ) {
			$ja_id = absint( $item['id'] );

			// Check if already synced.
			$existing = get_posts(
				array(
					'post_type'      => 'mcp_appointment',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => '_jetappointment_id',
					'meta_value'     => $ja_id,
					'fields'         => 'ids',
				)
			);

			$post_data = array(
				'post_type'   => 'mcp_appointment',
				'post_title'  => sprintf(
					/* translators: %d: JetAppointment ID */
					__( 'Appointment #%d', 'nvoos-content-graph-pro' ),
					$ja_id
				),
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

			// Save meta fields.
			update_post_meta( $post_id, '_jetappointment_id', $ja_id );
			update_post_meta( $post_id, '_start_time', $item['start_time'] );
			update_post_meta( $post_id, '_end_time', $item['end_time'] );
			update_post_meta( $post_id, '_status', $item['status'] );
			update_post_meta( $post_id, '_client_email', $item['user_email'] );

			if ( ! empty( $item['provider_id'] ) ) {
				update_post_meta( $post_id, '_provider_id', absint( $item['provider_id'] ) );
			}
			if ( ! empty( $item['service_id'] ) ) {
				update_post_meta( $post_id, '_service_id', absint( $item['service_id'] ) );
			}
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: created count, 2: updated count, 3: skipped count */
				__( 'Sync complete: %1$d created, %2$d updated, %3$d skipped.', 'nvoos-content-graph-pro' ),
				$created,
				$updated,
				$skipped
			),
			'created' => $created,
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}
}
