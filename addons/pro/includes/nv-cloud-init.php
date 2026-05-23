<?php
/**
 * NV oOS Cloud — Pro subsystem bootstrap.
 *
 * Loads the service, billing observer, REST controller and admin settings
 * page, registers the router filter that handles the `nv_hosted` provider
 * id, and schedules a daily balance refresh.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-nv-cloud-service.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/providers/class-wp-mcp-ai-nv-cloud-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/providers/class-wp-mcp-ai-nv-cloud-provider-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-nv-cloud-billing-observer.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-nv-cloud-rest-controller.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-nv-cloud-settings-page.php';

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_route_to_provider' ) ) {

	/**
	 * Router filter — handles the `nv_hosted` provider id.
	 *
	 * @param array|WP_Error|null $pre      Pre-routed result.
	 * @param string              $provider Provider key.
	 * @param array               $messages Messages.
	 * @param array               $options  Options.
	 * @return array|WP_Error|null
	 */
	function wp_mcp_ai_nv_cloud_route_to_provider( $pre, $provider, $messages, $options ) {
		if ( null !== $pre ) {
			return $pre;
		}
		if ( WP_MCP_AI_NV_Cloud_Client::PROVIDER_SLUG !== $provider ) {
			return $pre;
		}

		$client = new WP_MCP_AI_NV_Cloud_Client();
		return $client->create_chat_completion( is_array( $messages ) ? $messages : array(), is_array( $options ) ? $options : array() );
	}
}

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_default_provider' ) ) {

	/**
	 * Promote `nv_hosted` to the front of the provider priority list when
	 * the customer has flagged "Use NV oOS Cloud as default" and they're
	 * connected. Hooks `wp_mcp_ai_admin_settings_priority_list` if the base
	 * defines it; otherwise falls back to a generic settings filter.
	 *
	 * @param array $settings Settings array.
	 * @return array
	 */
	function wp_mcp_ai_nv_cloud_default_provider( $settings ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}
		$service = WP_MCP_AI_NV_Cloud_Service::get_instance();
		if ( ! $service->is_default_provider() ) {
			return $settings;
		}

		$priority = isset( $settings['provider_priority_list'] ) && is_array( $settings['provider_priority_list'] )
			? $settings['provider_priority_list']
			: array();

		// Drop any existing nv_hosted entry then put it at the front.
		$priority = array_values(
			array_filter(
				$priority,
				static function ( $item ) {
					return WP_MCP_AI_NV_Cloud_Client::PROVIDER_SLUG !== $item;
				}
			)
		);
		array_unshift( $priority, WP_MCP_AI_NV_Cloud_Client::PROVIDER_SLUG );

		$settings['provider_priority_list'] = $priority;
		return $settings;
	}
}

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_register_rest' ) ) {

	/**
	 * Register the REST controller.
	 */
	function wp_mcp_ai_nv_cloud_register_rest() {
		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller();
		$controller->register_routes();
	}
}

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_balance_refresh_tick' ) ) {

	/**
	 * Daily cron — refresh the balance from the SaaS so the dashboard stays
	 * accurate even when the customer doesn't open the settings page.
	 */
	function wp_mcp_ai_nv_cloud_balance_refresh_tick() {
		$service = WP_MCP_AI_NV_Cloud_Service::get_instance();
		if ( ! $service->is_connected() ) {
			return;
		}
		$controller = new WP_MCP_AI_REST_NV_Cloud_Controller( $service );
		$controller->refresh_balance_now();
	}
}

// Wire everything up.
add_filter( 'wp_mcp_ai_route_to_provider', 'wp_mcp_ai_nv_cloud_route_to_provider', 10, 4 );
add_filter( 'wp_mcp_ai_admin_settings', 'wp_mcp_ai_nv_cloud_default_provider', 20 );
add_filter( 'option_wp_mcp_ai_settings', 'wp_mcp_ai_nv_cloud_default_provider', 20 );
add_action( 'rest_api_init', 'wp_mcp_ai_nv_cloud_register_rest' );

if ( is_admin() ) {
	$wp_mcp_ai_nv_cloud_admin_page = new WP_MCP_AI_NV_Cloud_Settings_Page();
	$wp_mcp_ai_nv_cloud_admin_page->register();
}

// Boot the billing observer so per-request markup ledger entries are written.
WP_MCP_AI_NV_Cloud_Billing_Observer::init();

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_maybe_schedule_cron' ) ) {

	/**
	 * Schedule the daily balance-refresh event lazily on `init` so it runs at
	 * most once per process and not on every PHP request. Using a transient
	 * guard keeps the database hit cheap even under high concurrency.
	 */
	function wp_mcp_ai_nv_cloud_maybe_schedule_cron() {
		if ( wp_next_scheduled( 'wp_mcp_ai_nv_cloud_balance_refresh' ) ) {
			return;
		}
		if ( get_transient( 'wp_mcp_ai_nv_cloud_cron_check' ) ) {
			return;
		}
		set_transient( 'wp_mcp_ai_nv_cloud_cron_check', 1, HOUR_IN_SECONDS );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_mcp_ai_nv_cloud_balance_refresh' );
	}
}

if ( ! function_exists( 'wp_mcp_ai_nv_cloud_unschedule_cron' ) ) {

	/**
	 * Tear down the daily cron when the Pro module is being deactivated.
	 *
	 * Hooked from the Pro plugin's deactivation routine (see
	 * `addons/pro/mcp-ai-wpoos-pro.php`) — guarding here as a function so it
	 * can be reused if the module ever ships a dedicated deactivation hook.
	 */
	function wp_mcp_ai_nv_cloud_unschedule_cron() {
		$ts = wp_next_scheduled( 'wp_mcp_ai_nv_cloud_balance_refresh' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'wp_mcp_ai_nv_cloud_balance_refresh' );
		}
		delete_transient( 'wp_mcp_ai_nv_cloud_cron_check' );
	}
}

add_action( 'init', 'wp_mcp_ai_nv_cloud_maybe_schedule_cron', 30 );
add_action( 'wp_mcp_ai_nv_cloud_balance_refresh', 'wp_mcp_ai_nv_cloud_balance_refresh_tick' );
add_action( 'wp_mcp_ai_pro_deactivated', 'wp_mcp_ai_nv_cloud_unschedule_cron' );
