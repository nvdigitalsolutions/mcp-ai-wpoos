<?php
/**
 * Composition root for the NV oOS WiFi addon.
 *
 * This is the only place in the addon that decides *which* implementations the
 * ports receive. Everything downstream — services, domain — receives injected
 * interfaces and knows nothing about WordPress, options or adapters.
 *
 * That single fact is what makes the Laravel host possible: the Laravel service
 * provider binds the same interfaces to different implementations, and the
 * services run unchanged.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi;

use NvoosWifi\Infrastructure\Providers\Class_Mock_Controller_Adapter;
use NvoosWifi\Infrastructure\Wp\Class_Random_Id_Generator;
use NvoosWifi\Infrastructure\Wp\Class_System_Clock;
use NvoosWifi\Infrastructure\Wp\Class_Wp_Kpi_Sink;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use NvoosWifi\Interfaces\Interface_WiFi_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Id_Generator;
use NvoosWifi\REST\Class_Kpi_Controller;
use NvoosWifi\Services\Class_Kpi_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires hooks and builds the port implementations.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * Settings option name.
	 */
	const OPTION_SETTINGS = 'nvoos_wifi_settings';

	/**
	 * Cached KPI service. Reset in tests via {@see self::reset()}.
	 *
	 * @var Class_Kpi_Service|null
	 */
	private static ?Class_Kpi_Service $kpi_service = null;

	/**
	 * Cached clock. Reset in tests via {@see self::reset()}.
	 *
	 * @var Interface_WiFi_Clock|null
	 */
	private static ?Interface_WiFi_Clock $clock = null;

	/**
	 * Register hooks. Called on `plugins_loaded`.
	 *
	 * The `wp_mcp_ai_default_tools` filter is **not** registered here: it is added
	 * at file scope in `nvoos-wifi.php`, because the tool registry may consume
	 * that filter before a late `plugins_loaded` callback runs. Registering it
	 * early is the pattern `includes/tools-init.php` already uses for its own
	 * side-loaded tools.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Clear cached singletons.
	 *
	 * Exists so tests can start from a known state instead of inheriting a
	 * service built with a previous adapter — the static-registry-leak failure
	 * class.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$kpi_service = null;
		self::$clock       = null;
	}

	/**
	 * The controller adapter for this site.
	 *
	 * Phase 0 always returns the mock. Phase 2 selects a real adapter from
	 * settings and falls back to the mock when nothing is configured, so a fresh
	 * install is never broken.
	 *
	 * @return Interface_WiFi_Controller_Adapter
	 */
	public static function adapter(): Interface_WiFi_Controller_Adapter {
		$settings = self::settings();
		$seed     = isset( $settings['mock_seed'] ) && is_string( $settings['mock_seed'] )
			? $settings['mock_seed']
			: 'nvoos-wifi-mock';

		return new Class_Mock_Controller_Adapter( $seed );
	}

	/**
	 * The clock.
	 *
	 * Filterable so a host (or a test) can freeze time. The dual-runtime parity
	 * test needs a deterministic `generated_at`, and there is no other seam for
	 * that without making the composition root itself injectable.
	 *
	 * @return Interface_WiFi_Clock
	 */
	public static function clock(): Interface_WiFi_Clock {
		if ( null === self::$clock ) {
			$candidate = apply_filters( 'nvoos_wifi_clock', new Class_System_Clock() );

			self::$clock = $candidate instanceof Interface_WiFi_Clock
				? $candidate
				: new Class_System_Clock();
		}

		return self::$clock;
	}

	/**
	 * The identifier generator.
	 *
	 * @return Interface_WiFi_Id_Generator
	 */
	public static function id_generator(): Interface_WiFi_Id_Generator {
		return new Class_Random_Id_Generator();
	}

	/**
	 * The KPI service, built from its ports.
	 *
	 * @return Class_Kpi_Service
	 */
	public static function kpi_service(): Class_Kpi_Service {
		if ( null === self::$kpi_service ) {
			self::$kpi_service = new Class_Kpi_Service(
				self::adapter(),
				new Class_Wp_Kpi_Sink(),
				self::clock()
			);
		}

		return self::$kpi_service;
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		( new Class_Kpi_Controller() )->register_routes();
	}

	/**
	 * Add the addon's tools to the base tool map.
	 *
	 * Uses `wp_mcp_ai_default_tools` (a Base filter) rather than a Pro registry,
	 * so the WiFi tools work without a Pro licence — Proposal 150 decision D-6.
	 *
	 * @param mixed $tools Existing `class_name => absolute_path` map.
	 * @return mixed
	 */
	public static function register_tools( $tools ) {
		if ( ! is_array( $tools ) ) {
			return $tools;
		}

		$tools['WP_MCP_AI_Tool_WiFi_Health_Check']
			= NVOOS_WIFI_PATH . 'includes/Tools/class-wp-mcp-ai-tool-wifi-health-check.php';

		return $tools;
	}

	/**
	 * Plugin settings, with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings(): array {
		$settings = get_option( self::OPTION_SETTINGS, array() );

		return is_array( $settings ) ? $settings : array();
	}
}
