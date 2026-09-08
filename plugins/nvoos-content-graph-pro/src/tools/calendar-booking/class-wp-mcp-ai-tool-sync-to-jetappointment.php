<?php
/**
 * JetAppointment/JetBooking sync tool (ecosystem port — Wave F2, calendar jet-sync batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-tool-sync-to-jetappointment.php` for the standalone
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
 * Sync appointments from NV oOS to JetAppointment.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Tool_Sync_To_JetAppointment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'sync_to_jetappointment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync To JetAppointment', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Push NV oOS appointments to JetAppointment. Skips appointments already synced (tracked via _jetappointment_id meta).', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'appointment_ids' => array(
					'type'        => 'array',
					'description' => __( 'Specific NV oOS appointment IDs to sync. If empty, syncs all unsynced appointments.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum appointments to sync (default: 50, max: 200).', 'nvoos-content-graph-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
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
		return array( 'pro', 'database-write', 'database-read', 'external-api', 'phase-1.5' );
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

		$adapter = WP_MCP_AI_Booking_Adapter_Factory::get_jetappointment();
		$limit   = ! empty( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;

		// Build query for unsynced appointments.
		$meta_query = array(
			array(
				'key'     => '_jetappointment_id',
				'compare' => 'NOT EXISTS',
			),
		);

		// If specific IDs provided, use those instead.
		if ( ! empty( $arguments['appointment_ids'] ) && is_array( $arguments['appointment_ids'] ) ) {
			$post__in = array_map( 'absint', $arguments['appointment_ids'] );
		} else {
			$post__in = array();
		}

		$query_args = array(
			'post_type'      => 'mcp_appointment',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => $meta_query,
		);

		if ( ! empty( $post__in ) ) {
			$query_args['post__in'] = $post__in;
			unset( $query_args['meta_query'] );
		}

		$query  = new WP_Query( $query_args );
		$synced = 0;
		$errors = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id      = get_the_ID();
				$start_time   = get_post_meta( $post_id, '_start_time', true );
				$end_time     = get_post_meta( $post_id, '_end_time', true );
				$client_email = get_post_meta( $post_id, '_client_email', true );
				$status       = get_post_meta( $post_id, '_status', true );

				if ( empty( $start_time ) ) {
					continue;
				}

				$date_obj = strtotime( $start_time );
				$end_obj  = strtotime( $end_time ? $end_time : '+1 hour', $date_obj );

				$ja_data = array(
					'service'            => absint( get_post_meta( $post_id, '_service_id', true ) ),
					'provider'           => absint( get_post_meta( $post_id, '_provider_id', true ) ),
					'date'               => $date_obj ? gmdate( 'd/m/Y', $date_obj ) : '',
					'date_timestamp'     => $date_obj ? $date_obj : 0,
					'slot'               => $date_obj ? gmdate( 'H:i', $date_obj ) : '',
					'slot_end'           => $end_obj ? gmdate( 'H:i', $end_obj ) : '',
					'slot_timestamp'     => $date_obj ? $date_obj : 0,
					'slot_end_timestamp' => $end_obj ? $end_obj : 0,
					'status'             => ! empty( $status ) ? $status : 'pending',
					'user_email'         => ! empty( $client_email ) ? $client_email : '',
				);

				$result = $adapter->create_booking( $ja_data );

				if ( is_wp_error( $result ) ) {
					$errors[] = sprintf(
						/* translators: 1: appointment ID, 2: error message */
						__( 'Appointment #%1$d: %2$s', 'nvoos-content-graph-pro' ),
						$post_id,
						$result->get_error_message()
					);
					continue;
				}

				// Track the JetAppointment ID to prevent duplicate sync.
				if ( ! empty( $result['booking_id'] ) ) {
					update_post_meta( $post_id, '_jetappointment_id', absint( $result['booking_id'] ) );
				}

				++$synced;
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %d: number of synced appointments */
				_n(
					'%d appointment synced to JetAppointment.',
					'%d appointments synced to JetAppointment.',
					$synced,
					'nvoos-content-graph-pro'
				),
				$synced
			),
			'synced'  => $synced,
			'errors'  => $errors,
		);
	}
}
