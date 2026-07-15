<?php
/**
 * Toolkit MCP Servers — Bootstrap
 *
 * Loads framework classes, primes the registry on init, and registers all
 * toolkit servers (Phases 1-3 + 6 + 8 + DietPi = 33 servers).
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
require_once __DIR__ . '/class-wp-mcp-ai-toolkit-mcp-audit-log.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-toolkit-mcp-observability-card.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-toolkit-server-token.php';

// Phase 8 — shared trait for Action Scheduler-backed sync servers.
require_once __DIR__ . '/trait-wp-mcp-ai-scheduled-toolkit-server.php';

// Phase 1 pilot servers.
require_once __DIR__ . '/servers/class-wp-mcp-ai-crm-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-healthcare-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-architectural-design-mcp-server.php';

// Phase 2 Tier-1 promotions (16 servers, alphabetical).
require_once __DIR__ . '/servers/class-wp-mcp-ai-ai-tool-builder-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-calendar-booking-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-cre-debt-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-dj-management-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-document-generation-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-eca-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-ecommerce-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-financial-planner-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-image-production-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-law-firm-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-media-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-multilingual-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-project-management-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-regulatory-registration-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-social-media-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-video-production-mcp-server.php';

// Phase 6 Tier-2 promotions (9 servers, alphabetical).
require_once __DIR__ . '/servers/class-wp-mcp-ai-analytics-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-architect-agent-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-chat-channels-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-cloudways-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-comic-creation-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-extended-cognition-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-healthcare-imaging-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-healthcare-wellness-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-site-creator-mcp-server.php';

// DietPi Pro Toolkit (Phase 1).
require_once __DIR__ . '/servers/class-wp-mcp-ai-dietpi-mcp-server.php';

// Phase 8 — Pro Scheduler + Inventory Sync MCP Servers (4 servers).
require_once __DIR__ . '/servers/class-wp-mcp-ai-pro-scheduler-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-flowhub-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-shopify-sync-mcp-server.php';
require_once __DIR__ . '/servers/class-wp-mcp-ai-ezuite-mcp-server.php';

// Phase 6 — /.well-known/mcp discovery endpoint.
require_once __DIR__ . '/class-wp-mcp-ai-pro-well-known-mcp.php';

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
 * Register all Tier-1 servers when the registration action fires.
 */
add_action(
	'wp_mcp_ai_register_toolkit_servers',
	static function ( $registry ) {
		if ( ! ( $registry instanceof WP_MCP_AI_Toolkit_Server_Registry ) ) {
			return;
		}
		// Phase 1 pilots.
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architectural_Design_MCP_Server() );

		// Phase 2 Tier-1 promotions (alphabetical).
		$registry->register( new WP_MCP_AI_AI_Tool_Builder_MCP_Server() );
		$registry->register( new WP_MCP_AI_Calendar_Booking_MCP_Server() );
		$registry->register( new WP_MCP_AI_CRE_Debt_MCP_Server() );
		$registry->register( new WP_MCP_AI_DJ_Management_MCP_Server() );
		$registry->register( new WP_MCP_AI_Document_Generation_MCP_Server() );
		$registry->register( new WP_MCP_AI_ECA_Management_MCP_Server() );
		$registry->register( new WP_MCP_AI_Ecommerce_MCP_Server() );
		$registry->register( new WP_MCP_AI_Financial_Planner_MCP_Server() );
		$registry->register( new WP_MCP_AI_Image_Production_MCP_Server() );
		$registry->register( new WP_MCP_AI_Law_Firm_MCP_Server() );
		$registry->register( new WP_MCP_AI_Media_Toolkit_MCP_Server() );
		$registry->register( new WP_MCP_AI_Multilingual_MCP_Server() );
		$registry->register( new WP_MCP_AI_Project_Management_MCP_Server() );
		$registry->register( new WP_MCP_AI_Regulatory_Registration_MCP_Server() );
		$registry->register( new WP_MCP_AI_Social_Media_MCP_Server() );
		$registry->register( new WP_MCP_AI_Video_Production_MCP_Server() );

		// DietPi Pro Toolkit.
		$registry->register( new WP_MCP_AI_DietPi_MCP_Server() );

		// Phase 6 Tier-2 promotions (alphabetical).
		$registry->register( new WP_MCP_AI_Analytics_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architect_Agent_MCP_Server() );
		$registry->register( new WP_MCP_AI_Chat_Channels_MCP_Server() );
		$registry->register( new WP_MCP_AI_Cloudways_MCP_Server() );
		$registry->register( new WP_MCP_AI_Comic_Creation_MCP_Server() );
		$registry->register( new WP_MCP_AI_Extended_Cognition_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_Imaging_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_Wellness_MCP_Server() );
		$registry->register( new WP_MCP_AI_Site_Creator_MCP_Server() );

		// Phase 8 — Pro Scheduler + Inventory Sync servers (alphabetical).
		$registry->register( new WP_MCP_AI_EZuite_MCP_Server() );
		$registry->register( new WP_MCP_AI_FlowHub_MCP_Server() );
		$registry->register( new WP_MCP_AI_Pro_Scheduler_MCP_Server() );
		$registry->register( new WP_MCP_AI_Shopify_Sync_MCP_Server() );
	}
);

/**
 * Initialize the REST controller.
 */
WP_MCP_AI_Toolkit_MCP_REST_Controller::get_instance()->init();

/**
 * Initialize the cross-mount audit log.
 */
WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance()->init();

/**
 * Register the observability card for the performance/orchestration admin section.
 */
if ( is_admin() ) {
	new WP_MCP_AI_Pro_Toolkit_MCP_Observability_Card();
}

/**
 * Phase 6 — register the /.well-known/mcp discovery endpoint.
 */
new WP_MCP_AI_Pro_Well_Known_MCP();

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
			'enabled'             => ! empty( $_POST['enabled'] ),
			'tools_allowlist'     => isset( $_POST['tools_allowlist'] ) && is_array( $_POST['tools_allowlist'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['tools_allowlist'] ) )
				: array(),
			'disabled_surfaces'   => isset( $_POST['disabled_surfaces'] ) && is_array( $_POST['disabled_surfaces'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['disabled_surfaces'] ) )
				: array(),
			'disabled_mounts'     => isset( $_POST['disabled_mounts'] ) && is_array( $_POST['disabled_mounts'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['disabled_mounts'] ) )
				: array(),
			'requests_per_minute' => isset( $_POST['requests_per_minute'] ) ? max( 0, (int) wp_unslash( $_POST['requests_per_minute'] ) ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
			'max_payload_bytes'   => isset( $_POST['max_payload_bytes'] ) ? max( 0, (int) wp_unslash( $_POST['max_payload_bytes'] ) ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
			'max_iterations'      => isset( $_POST['max_iterations'] ) ? max( 0, (int) wp_unslash( $_POST['max_iterations'] ) ) : 0, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cast to int.
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
