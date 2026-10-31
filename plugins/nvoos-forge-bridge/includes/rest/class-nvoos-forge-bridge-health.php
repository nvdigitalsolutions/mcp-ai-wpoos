<?php
/**
 * REST — redacted health endpoint.
 *
 * @package NvoosForgeBridge
 */

namespace NvoosForgeBridge\Rest;

use NvoosForgeBridge\Settings;

/**
 * Health route.
 */
class Health extends \WP_REST_Controller {

	/**
	 * Namespace.
	 */
	private const NS = 'nvoos-forge/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_health' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Redacted status — booleans only, never URLs, tokens, or secrets.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_health(): \WP_REST_Response {
		global $wp_version;
		return new \WP_REST_Response(
			array(
				'status'            => 'ok',
				'pluginVersion'     => NVOOS_FORGE_BRIDGE_VERSION,
				'wordpressVersion'  => $wp_version,
				'phpVersion'        => PHP_VERSION,
				'rebuildEnabled'    => Settings::rebuild_enabled(),
				'rebuildConfigured' => Settings::rebuild_configured(),
				'rebuildUrlHost'    => self::redacted_host( Settings::rebuild_url() ),
			)
		);
	}

	/**
	 * The host of a URL, or null — never the full URL.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private static function redacted_host( string $url ) {
		if ( '' === $url ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host ? $host : null;
	}
}
