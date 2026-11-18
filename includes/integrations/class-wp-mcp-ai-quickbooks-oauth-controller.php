<?php
/**
 * QuickBooks OAuth REST callback.
 *
 * Intuit requires registered redirect URIs to carry no query parameters, so
 * the callback is exposed as a clean REST path instead of the legacy
 * `admin-post.php?action=…` URL.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_QuickBooks_OAuth_Controller' ) ) {
	/**
	 * Routes the Intuit OAuth redirect back into the handler.
	 */
	class WP_MCP_AI_QuickBooks_OAuth_Controller {
		/**
		 * Register the callback route on `rest_api_init`.
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		}

		/**
		 * Register the clean-path callback.
		 */
		public static function register_routes() {
			register_rest_route(
				WP_MCP_AI_QuickBooks_OAuth_Handler::QUICKBOOKS_REST_NAMESPACE,
				WP_MCP_AI_QuickBooks_OAuth_Handler::QUICKBOOKS_OAUTH_CALLBACK_ROUTE,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( __CLASS__, 'handle_callback' ),
						'permission_callback' => array( __CLASS__, 'can_manage_options' ),
						'args'                => array(
							// None are required: Intuit redirects back with only
							// `?error=…&error_description=…` when the operator
							// declines consent, and with no code at all.
							'state'             => array( 'sanitize_callback' => 'sanitize_text_field' ),
							'code'              => array( 'sanitize_callback' => 'sanitize_text_field' ),
							'realmId'           => array( 'sanitize_callback' => 'sanitize_text_field' ),
							'error'             => array( 'sanitize_callback' => 'sanitize_text_field' ),
							'error_description' => array( 'sanitize_callback' => 'sanitize_text_field' ),
						),
					),
				)
			);
		}

		/**
		 * Only an administrator may complete the grant.
		 *
		 * The browser arrives with the admin session cookie; Rest cookie
		 * authentication does not require a nonce for GET requests.
		 *
		 * @return bool
		 */
		public static function can_manage_options() {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Complete the OAuth flow and return the operator to where they started.
		 *
		 * @param WP_REST_Request $request Callback request.
		 */
		public static function handle_callback( WP_REST_Request $request ) {
			$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();

			$result = $handler->complete_authorization(
				(string) $request->get_param( 'state' ),
				(string) $request->get_param( 'code' ),
				(string) $request->get_param( 'realmId' ),
				(string) $request->get_param( 'error' ),
				(string) $request->get_param( 'error_description' )
			);

			if ( is_wp_error( $result ) ) {
				$handler->set_notice( $result->get_error_code(), $result->get_error_message(), 'error' );
				$return_url = $handler->get_error_return_url( $result );
			} else {
				$handler->set_notice(
					'quickbooks_connected',
					sprintf(
						/* translators: %s: Company ID. */
						__( 'Successfully connected to QuickBooks! Company ID: %s', 'mcp-ai-wpoos' ),
						$result['company_id']
					),
					'success'
				);
				$return_url = $handler->get_safe_return_url( $result['return_url'] );
			}

			wp_safe_redirect( $return_url );
			exit;
		}
	}
}
