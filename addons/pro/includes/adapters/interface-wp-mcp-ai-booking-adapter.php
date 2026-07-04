<?php
/**
 * Booking Adapter Interface
 *
 * Contract that every third-party booking system adapter must implement.
 * Enables the Calendar Booking Toolkit to interact with JetAppointment,
 * JetBooking, and future booking plugins through a consistent API surface.
 *
 * @package   WP_MCP_AI_Pro
 * @subpackage Adapters
 * @since     1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface WP_MCP_AI_Booking_Adapter_Interface
 *
 * Every adapter must implement these methods. Tools call the factory,
 * the factory returns adapter instances satisfying this contract.
 *
 * @since 1.5.0
 */
interface WP_MCP_AI_Booking_Adapter_Interface {

	/**
	 * Check whether the external booking system is available.
	 *
	 * Must verify: plugin class exists, required DB tables exist,
	 * configuration is complete, and NV oOS integration toggle is enabled.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function is_available();

	/**
	 * Human-readable reason why the adapter is unavailable.
	 *
	 * @since 1.5.0
	 * @return string
	 */
	public static function get_unavailable_reason();

	/**
	 * Get a unique slug identifying this adapter.
	 *
	 * @since 1.5.0
	 * @return string e.g. 'jetappointment', 'jetbooking'
	 */
	public function get_slug();

	/**
	 * Get a human-readable label for this adapter.
	 *
	 * @since 1.5.0
	 * @return string e.g. 'JetAppointment', 'JetBooking'
	 */
	public function get_label();

	/**
	 * Get bookings/appointments matching the given filters.
	 *
	 * @since 1.5.0
	 * @param array $filters date_from, date_to, status, provider_id, service_id.
	 * @param int   $limit   Max results (default 50).
	 * @param int   $offset  Pagination offset (default 0).
	 * @return array{success:bool,items:array,total:int}|WP_Error
	 */
	public function get_bookings( array $filters = array(), $limit = 50, $offset = 0 );

	/**
	 * Get a single booking/appointment by its ID.
	 *
	 * @since 1.5.0
	 * @param int|string $booking_id External system's booking ID.
	 * @return array{success:bool,booking:array}|WP_Error
	 */
	public function get_booking( $booking_id );

	/**
	 * Create a new booking/appointment.
	 *
	 * @since 1.5.0
	 * @param array $data Booking data (fields vary by adapter).
	 * @return array{success:bool,booking_id:int|string,booking:array}|WP_Error
	 */
	public function create_booking( array $data );

	/**
	 * Update an existing booking/appointment.
	 *
	 * @since 1.5.0
	 * @param int|string $booking_id External system's booking ID.
	 * @param array      $data       Fields to update.
	 * @return array{success:bool,booking:array}|WP_Error
	 */
	public function update_booking( $booking_id, array $data );

	/**
	 * Cancel/delete a booking/appointment.
	 *
	 * @since 1.5.0
	 * @param int|string $booking_id External system's booking ID.
	 * @param string     $reason     Optional cancellation reason.
	 * @return array{success:bool}|WP_Error
	 */
	public function cancel_booking( $booking_id, $reason = '' );

	/**
	 * Check availability for a time range.
	 *
	 * @since 1.5.0
	 * @param string $start_time Start datetime (Y-m-d H:i:s).
	 * @param string $end_time   End datetime (Y-m-d H:i:s).
	 * @param array  $context    Optional: provider_id, service_id, unit_ids.
	 * @return array{success:bool,available:bool,conflicts:array,reasons:array}|WP_Error
	 */
	public function check_availability( $start_time, $end_time, array $context = array() );

	/**
	 * Get available time slots for a date.
	 *
	 * @since 1.5.0
	 * @param string $date             Date (Y-m-d).
	 * @param int    $duration_minutes Required slot duration.
	 * @param array  $context          Optional: provider_id, service_id.
	 * @return array{success:bool,date:string,slots:array,total:int}|WP_Error
	 */
	public function get_available_slots( $date, $duration_minutes = 60, array $context = array() );

	/**
	 * Get providers/staff/resources available in this system.
	 *
	 * @since 1.5.0
	 * @param array $filters Optional filters.
	 * @return array{success:bool,providers:array,total:int}|WP_Error
	 */
	public function get_providers( array $filters = array() );

	/**
	 * Get services offered through this system.
	 *
	 * @since 1.5.0
	 * @param array $filters Optional: provider_id, category.
	 * @return array{success:bool,services:array,total:int}|WP_Error
	 */
	public function get_services( array $filters = array() );

	/**
	 * Run a health check against the external system.
	 *
	 * @since 1.5.0
	 * @return array{success:bool,healthy:bool,checks:array,message:string}
	 */
	public function health_check();
}
