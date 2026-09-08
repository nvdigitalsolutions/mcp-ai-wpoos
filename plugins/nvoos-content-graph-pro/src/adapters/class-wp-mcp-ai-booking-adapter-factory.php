<?php
/**
 * Calendar Booking data layer (ecosystem port — Wave F2, calendar-booking data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/adapters/class-wp-mcp-ai-booking-adapter-factory.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the metabox requires resolve from the
 * addon's `src/metaboxes/` copies (same batch).
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
 * Class WP_MCP_AI_Booking_Adapter_Factory
 *
 * Singleton-like static factory. Scans once for available adapters,
 * caches results, and provides typed accessors.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Booking_Adapter_Factory {

	/**
	 * Adapter instances, keyed by slug.
	 *
	 * @var array<string,WP_MCP_AI_Booking_Adapter_Interface>
	 */
	private static $adapters = array();

	/**
	 * Availability flags per adapter slug.
	 *
	 * @var array<string,bool>
	 */
	private static $availability = array();

	/**
	 * Unavailable reason strings per adapter slug.
	 *
	 * @var array<string,string>
	 */
	private static $availability_reasons = array();

	/**
	 * Whether the scan has already run.
	 *
	 * @var bool
	 */
	private static $scanned = false;

	/**
	 * Global disable flag (kill-switch).
	 *
	 * @var bool|null
	 */
	private static $globally_disabled = null;

	/**
	 * Scan for available adapters (runs once per request).
	 *
	 * @since 1.5.0
	 * @return void
	 */
	private static function scan() {
		if ( self::$scanned ) {
			return;
		}
		self::$scanned = true;

		// Global kill-switch: define('WP_MCP_AI_DISABLE_BOOKING_ADAPTERS', true).
		if ( self::is_globally_disabled() ) {
			self::$availability['jetappointment']         = false;
			self::$availability_reasons['jetappointment'] = __( 'Booking adapters are globally disabled via WP_MCP_AI_DISABLE_BOOKING_ADAPTERS constant.', 'nvoos-content-graph-pro' );
			self::$availability['jetbooking']             = false;
			self::$availability_reasons['jetbooking']     = __( 'Booking adapters are globally disabled via WP_MCP_AI_DISABLE_BOOKING_ADAPTERS constant.', 'nvoos-content-graph-pro' );
			return;
		}

		// JetAppointment detection.
		if ( class_exists( 'WP_MCP_AI_JetAppointment_Adapter' ) ) {
			if ( WP_MCP_AI_JetAppointment_Adapter::is_available() ) {
				self::$availability['jetappointment'] = true;
			} else {
				self::$availability['jetappointment']         = false;
				self::$availability_reasons['jetappointment'] = WP_MCP_AI_JetAppointment_Adapter::get_unavailable_reason();
			}
		} else {
			self::$availability['jetappointment']         = false;
			self::$availability_reasons['jetappointment'] = __( 'JetAppointment adapter class not loaded.', 'nvoos-content-graph-pro' );
		}

		// JetBooking detection.
		if ( class_exists( 'WP_MCP_AI_JetBooking_Adapter' ) ) {
			if ( WP_MCP_AI_JetBooking_Adapter::is_available() ) {
				self::$availability['jetbooking'] = true;
			} else {
				self::$availability['jetbooking']         = false;
				self::$availability_reasons['jetbooking'] = WP_MCP_AI_JetBooking_Adapter::get_unavailable_reason();
			}
		} else {
			self::$availability['jetbooking']         = false;
			self::$availability_reasons['jetbooking'] = __( 'JetBooking adapter class not loaded.', 'nvoos-content-graph-pro' );
		}
	}

	/**
	 * Check if adapters are globally disabled.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	private static function is_globally_disabled() {
		if ( null === self::$globally_disabled ) {
			self::$globally_disabled = defined( 'WP_MCP_AI_DISABLE_BOOKING_ADAPTERS' ) && WP_MCP_AI_DISABLE_BOOKING_ADAPTERS;
		}
		return self::$globally_disabled;
	}

	/**
	 * Check if JetAppointment adapter is available.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function has_jetappointment() {
		self::scan();
		return ! empty( self::$availability['jetappointment'] );
	}

	/**
	 * Check if JetBooking adapter is available.
	 *
	 * @since 1.5.0
	 * @return bool
	 */
	public static function has_jetbooking() {
		self::scan();
		return ! empty( self::$availability['jetbooking'] );
	}

	/**
	 * Get the JetAppointment adapter instance.
	 *
	 * @since 1.5.0
	 * @return WP_MCP_AI_JetAppointment_Adapter|null
	 */
	public static function get_jetappointment() {
		if ( ! self::has_jetappointment() ) {
			return null;
		}
		if ( ! isset( self::$adapters['jetappointment'] ) ) {
			self::$adapters['jetappointment'] = new WP_MCP_AI_JetAppointment_Adapter();
		}
		return self::$adapters['jetappointment'];
	}

	/**
	 * Get the JetBooking adapter instance.
	 *
	 * @since 1.5.0
	 * @return WP_MCP_AI_JetBooking_Adapter|null
	 */
	public static function get_jetbooking() {
		if ( ! self::has_jetbooking() ) {
			return null;
		}
		if ( ! isset( self::$adapters['jetbooking'] ) ) {
			self::$adapters['jetbooking'] = new WP_MCP_AI_JetBooking_Adapter();
		}
		return self::$adapters['jetbooking'];
	}

	/**
	 * Get all currently available adapter instances.
	 *
	 * @since 1.5.0
	 * @return array<string,WP_MCP_AI_Booking_Adapter_Interface>
	 */
	public static function get_all_available() {
		self::scan();
		$available = array();
		if ( self::has_jetappointment() ) {
			$available['jetappointment'] = self::get_jetappointment();
		}
		if ( self::has_jetbooking() ) {
			$available['jetbooking'] = self::get_jetbooking();
		}
		return $available;
	}

	/**
	 * Get status information for all configured adapters.
	 *
	 * Useful for admin health dashboards and WP-CLI status commands.
	 *
	 * @since 1.5.0
	 * @return array<int,array{slug:string,label:string,available:bool,reason:string}>
	 */
	public static function get_statuses() {
		self::scan();
		$statuses = array();

		$slugs = array( 'jetappointment', 'jetbooking' );
		foreach ( $slugs as $slug ) {
			$statuses[] = array(
				'slug'      => $slug,
				'label'     => self::get_label( $slug ),
				'available' => self::$availability[ $slug ] ?? false,
				'reason'    => self::$availability_reasons[ $slug ] ?? __( 'Adapter class not loaded.', 'nvoos-content-graph-pro' ),
			);
		}

		return $statuses;
	}

	/**
	 * Get a human-readable label for an adapter slug.
	 *
	 * @since 1.5.0
	 * @param string $slug Adapter slug.
	 * @return string
	 */
	private static function get_label( $slug ) {
		$labels = array(
			'jetappointment' => __( 'JetAppointment', 'nvoos-content-graph-pro' ),
			'jetbooking'     => __( 'JetBooking', 'nvoos-content-graph-pro' ),
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}
}
