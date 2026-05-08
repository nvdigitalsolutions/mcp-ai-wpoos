<?php
/**
 * Toolkit MCP Servers — Bootstrap
 *
 * Loads framework classes, primes the registry on init, and registers the three
 * Phase 1 pilot servers (CRM, Healthcare, Architectural Design).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-toolkit-server.php';
require_once __DIR__ . '/class-wp-mcp-ai-toolkit-server-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-toolkit-server-registry.php';
require_once __DIR__ . '/class-wp-mcp-ai-toolkit-mcp-rest-controller.php';

require_once __DIR__ . '/servers/class-wp-mcp-ai-crm-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-healthcare-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-architectural-design-mcp-server.php';

/**
 * Wire the registry to fire its registration action at init priority 12 — after
 * toolkit initialization completes (which happens at priority 10/11 across the
 * Pro addon).
 */
add_action(
	'init',
	static function () {
		WP_MCP_AI_Toolkit_Server_Registry::get_instance()->bootstrap();
	},
	12
);

/**
 * Register the Phase 1 pilot servers when the registration action fires.
 */
add_action(
	'wp_mcp_ai_register_toolkit_servers',
	static function ( $registry ) {
		if ( ! ( $registry instanceof WP_MCP_AI_Toolkit_Server_Registry ) ) {
			return;
		}
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architectural_Design_MCP_Server() );
	}
);

/**
 * Initialize the REST controller.
 */
WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance()->init();

/**
 * Admin-post handler — persists per-toolkit MCP server configuration.
 *
 * Triggered by the form on the "MCP Server" tab of every toolkit settings page.
 */
add_action(
	'admin_post_wp_mcp_ai_save_toolkit_mcp_server',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ), 403 );
		}

		$slug = isset( $_POST['server_slug'] ) ? sanitize_key( wp_unslash( $_POST['server_slug'] ) ) : '';
		check_admin_referer( 'wp_mcp_ai_save_toolkit_mcp_server_' . $slug );

		$server = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( $slug );
		if ( null === $server ) {
			wp_die( esc_html__( 'Unknown MCP server.', 'mcp-ai-wpoos-pro' ), 404 );
		}

		$config = array(
			'enabled'           => ! empty( $_POST['enabled'] ),
			'tools_allowlist'   => isset( $_POST['tools_allowlist'] ) && is_array( $_POST['tools_allowlist'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['tools_allowlist'] ) )
				: array(),
			'disabled_surfaces' => isset( $_POST['disabled_surfaces'] ) && is_array( $_POST['disabled_surfaces'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['disabled_surfaces'] ) )
				: array(),
			'disabled_mounts'   => isset( $_POST['disabled_mounts'] ) && is_array( $_POST['disabled_mounts'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['disabled_mounts'] ) )
				: array(),
		);

		if ( $server instanceof WP_MCP_AI_Toolkit_Server_Base ) {
			$server->update_configuration( $config );
		}

		$redirect_page = isset( $_POST['redirect_page'] ) ? sanitize_key( wp_unslash( $_POST['redirect_page'] ) ) : '';
		$redirect_url  = $redirect_page
			? add_query_arg(
				array(
					'page'      => $redirect_page,
					'tab'       => 'mcp_server',
					'mcp_saved' => '1',
				),
				admin_url( 'admin.php' )
			)
			: admin_url();
		wp_safe_redirect( $redirect_url );
		exit;
	}
);
