<?php
/**
 * CRM Tracked Link Resolver (ecosystem port — Wave F2, CRM JobNavigator-adoption batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-link-tracker.php` for the standalone `nvoos-content-graph-pro` addon.
 * Kept byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Front-end resolver for tracked proposal links.
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`;
 *
 * @package NvoosContentGraphPro
 * @subpackage CRM_Toolkit
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracked link resolver.
 *
 * @since 3.2.0
 */
class WP_MCP_AI_CRM_Link_Tracker {

	/**
	 * Registry option key (shared with the create_tracked_link tool).
	 *
	 * @var string
	 */
	const REGISTRY_OPTION = 'wp_mcp_ai_crm_link_registry';

	/**
	 * Register the query var and the front-end handler.
	 *
	 * @return void
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_redirect' ), 5 );
	}

	/**
	 * Register the tracking query var.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = 'nvoos_track';
		return $vars;
	}

	/**
	 * Resolve a tracked link on the front end.
	 *
	 * @return void
	 */
	public static function maybe_handle_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public tracking link: no nonce by design.
		$token = isset( $_GET['nvoos_track'] ) ? sanitize_key( wp_unslash( $_GET['nvoos_track'] ) ) : '';

		$destination = self::resolve( $token );
		if ( ! $destination ) {
			return;
		}

		wp_safe_redirect( $destination, 302 );
		exit;
	}

	/**
	 * Resolve a token: record the open and return the destination URL.
	 *
	 * Pure logic, separated from the redirect/exit wrapper so tests can
	 * exercise the open-recording path without terminating the process.
	 *
	 * @param string $token Sanitized token.
	 * @return string Destination URL or empty string when unknown.
	 */
	public static function resolve( $token ) {
		$token = sanitize_key( (string) $token );

		if ( '' === $token ) {
			return '';
		}

		$registry = get_option( self::REGISTRY_OPTION, array() );
		if ( ! is_array( $registry ) || ! isset( $registry[ $token ] ) ) {
			// Unknown token: fall through silently — reveal nothing.
			return '';
		}

		$entry = $registry[ $token ];

		// Increment the registry counter.
		$entry['opens']          = isset( $entry['opens'] ) ? (int) $entry['opens'] + 1 : 1;
		$entry['last_opened_at'] = gmdate( 'c' );
		$registry[ $token ]      = $entry;
		update_option( self::REGISTRY_OPTION, $registry, false );

		// Mirror onto the owning deal.
		$deal_id = isset( $entry['deal_id'] ) ? absint( $entry['deal_id'] ) : 0;
		if ( $deal_id && 'mcp_ai_deal' === get_post_type( $deal_id ) ) {
			$links = get_post_meta( $deal_id, 'tracked_links', true );
			if ( is_array( $links ) ) {
				foreach ( $links as $index => $link ) {
					if ( isset( $link['token'] ) && $link['token'] === $token ) {
						$links[ $index ]['opens']          = isset( $link['opens'] ) ? (int) $link['opens'] + 1 : 1;
						$links[ $index ]['last_opened_at'] = gmdate( 'c' );
						break;
					}
				}
				update_post_meta( $deal_id, 'tracked_links', $links );
			}

			if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
				WP_MCP_AI_CRM_Audit::record(
					'tracked_link_opened',
					'deal',
					$deal_id,
					array(
						'label'  => isset( $entry['label'] ) ? $entry['label'] : '',
						'action' => 'open',
					)
				);
			}
		}

		// Redirect to the stored destination (http/https only).
		$destination = isset( $entry['url'] ) ? $entry['url'] : '';

		return $destination;
	}
}
