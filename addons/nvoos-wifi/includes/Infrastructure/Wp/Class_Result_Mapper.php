<?php
/**
 * Maps domain `Result` failures to WordPress `WP_Error` instances.
 *
 * This class is the WordPress boundary for errors. The domain never returns
 * `WP_Error` (it may not reference a WordPress class), so every WP surface —
 * REST and tools alike — funnels failures through here.
 *
 * The mapping deliberately distinguishes causes: an expired API key and an
 * unreachable controller must not both surface as "controller error"
 * (implementation plan §7, row 7: misleading native-source errors).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Wp;

use NvoosWifi\Domain\Result;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates domain failures into `WP_Error`.
 *
 * @since 0.1.0
 */
final class Class_Result_Mapper {

	/**
	 * HTTP status per domain error code.
	 *
	 * Unknown codes fall through to 500 — never to 200, so a failure cannot be
	 * mistaken for a successful empty response.
	 *
	 * @var array<string, int>
	 */
	const STATUS = array(
		'invalid_venue'            => 400,
		'invalid_window'           => 400,
		'invalid_timezone'         => 400,
		'invalid_residency'        => 400,
		'invalid_device_identity'  => 400,
		'invalid_kpi_value'        => 400,
		'invalid_kpi_time'         => 400,
		'invalid_method'           => 422,
		'invalid_sample'           => 500,
		'mac_not_allowed'          => 400,
		'method_required'          => 422,
		'unknown_kpi_key'          => 500,
		'venue_not_found'          => 404,
		'adapter_shape'            => 502,
		'adapter_auth'             => 502,
		'adapter_rate_limited'     => 503,
		'adapter_unreachable'      => 504,
	);

	/**
	 * Convert a failed `Result` into a `WP_Error`.
	 *
	 * @param Result $result A failed result.
	 * @return WP_Error
	 */
	public static function to_wp_error( Result $result ): WP_Error {
		$code = '' === $result->code() ? 'unknown_error' : $result->code();

		return new WP_Error(
			'nvoos_wifi_' . $code,
			$result->message(),
			array( 'status' => self::status_for( $code ) )
		);
	}

	/**
	 * HTTP status for a domain error code.
	 *
	 * @param string $code Domain error code.
	 * @return int
	 */
	public static function status_for( string $code ): int {
		return self::STATUS[ $code ] ?? 500;
	}
}
