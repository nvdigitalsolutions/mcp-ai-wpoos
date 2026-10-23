<?php
/**
 * REST controller for the diagnostics cluster (Proposal 084 Phase 3).
 *
 * The wp-admin diagnostics cluster is five render-only pages (settings
 * dashboard, MCP server, provider connectivity, REST context, Pro dashboard
 * charts). This controller consolidates the data behind them into one
 * read-only overview the Complete Console's Diagnostics page renders:
 *   GET /diagnostics/overview
 *
 * Sections wrap the existing data services — no logic forks:
 *   - health             WP_MCP_AI_REST_Health::health_check() (rest-health)
 *   - rest_context       WP_MCP_AI_REST_API_Context_Fix::get_diagnostics()
 *   - pro_dashboard      WP_MCP_AI_Pro_Dashboard_Diagnostic::run_diagnostics()
 *   - providers          WP_MCP_AI_Admin_Settings::get_available_providers()
 *                        + WP_MCP_AI_Credential_Resolver
 * Live per-provider/MCP-endpoint network tests stay wp-admin AJAX for now
 * (on-demand, network-bound); a POST test route can wrap them later.
 *
 * @package WP_MCP_AI
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostics REST controller.
 */
class WP_MCP_AI_REST_Diagnostics_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/diagnostics';

	/**
	 * Register the diagnostics routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — diagnostics are administrator-only.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /diagnostics/overview — the consolidated diagnostics payload.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'generated_at'       => time(),
					'health'             => $this->collect_health(),
					'system'             => $this->collect_system(),
					'settings_dashboard' => $this->collect_settings_dashboard(),
					'mcp_server'         => $this->collect_mcp_server(),
					'providers'          => $this->collect_providers(),
					'rest_context'       => $this->collect_rest_context(),
					'pro_dashboard'      => $this->collect_pro_dashboard(),
				),
			)
		);
	}

	/**
	 * Subsystem health checks — reuse the rest-health endpoint's checker.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_health() {
		if ( ! class_exists( 'WP_MCP_AI_REST_Health' ) ) {
			$health_file = WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-health.php';
			if ( file_exists( $health_file ) ) {
				require_once $health_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_REST_Health' ) ) {
			return array( 'status' => 'unavailable' );
		}

		$response = WP_MCP_AI_REST_Health::health_check();
		$data     = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : $response;

		return is_array( $data ) ? $data : array( 'status' => 'unavailable' );
	}

	/**
	 * System facts (the dashboard-diagnostic page's system-information card).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_system() {
		return array(
			'wp_version'     => sanitize_text_field( (string) get_bloginfo( 'version' ) ),
			'php_version'    => sanitize_text_field( (string) PHP_VERSION ),
			'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? sanitize_text_field( (string) WP_MCP_AI_VERSION ) : '',
			'memory_limit'   => sanitize_text_field( (string) ini_get( 'memory_limit' ) ),
			'is_multisite'   => is_multisite(),
		);
	}

	/**
	 * Settings-dashboard load-state facts (the dashboard-diagnostic page's
	 * constants/classes/globals/sections cards). Menu-state checks stay
	 * wp-admin-only — $menu/$submenu are not populated over REST.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_settings_dashboard() {
		$constants = array(
			'WP_MCP_AI_PATH'    => defined( 'WP_MCP_AI_PATH' ),
			'WP_MCP_AI_URL'     => defined( 'WP_MCP_AI_URL' ),
			'WP_MCP_AI_VERSION' => defined( 'WP_MCP_AI_VERSION' ),
		);

		$class_slugs = array(
			'WP_MCP_AI_Admin_Settings',
			'WP_MCP_AI_Settings_Dashboard',
			'WP_MCP_AI_Settings_Registry',
			'WP_MCP_AI_Settings_Validator',
			'WP_MCP_AI_Settings_Section',
			'WP_MCP_AI_Section_General',
			'WP_MCP_AI_Section_Providers',
			'WP_MCP_AI_Section_Authentication',
			'WP_MCP_AI_Section_Tools',
			'WP_MCP_AI_Section_Integrations',
			'WP_MCP_AI_Section_Security',
			'WP_MCP_AI_Section_Advanced',
			'WP_MCP_AI_Auth0_Setup',
		);
		$classes     = array();
		foreach ( $class_slugs as $class ) {
			$classes[ $class ] = class_exists( $class );
		}

		$sections_total = 0;
		if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			$sections_total = count( WP_MCP_AI_Settings_Registry::get_sections() );
		}

		return array(
			'constants'              => $constants,
			'classes'                => $classes,
			'dashboard_global_set'   => isset( $GLOBALS['wp_mcp_ai_settings_dashboard'] ),
			'admin_settings_global'  => isset( $GLOBALS['wp_mcp_ai_admin_settings'] ),
			'settings_init_function' => function_exists( 'wp_mcp_ai_init_settings_dashboard' ),
			'sections_total'         => $sections_total,
		);
	}

	/**
	 * MCP-server surface facts (the mcp-server diagnostic page's summary
	 * cards, minus its on-demand AJAX testers).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_mcp_server() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings' ) ? WP_MCP_AI_Admin_Settings::get_settings() : array();
		$settings = is_array( $settings ) ? $settings : array();

		$tool_count = 0;
		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			try {
				$tool_count = count( WP_MCP_AI_Tool_Registry::get_instance()->get_tools() );
			} catch ( Throwable $e ) {
				$tool_count = -1;
			}
		}

		$routes_present = false;
		if ( class_exists( 'WP_REST_Server' ) ) {
			$routes         = rest_get_server()->get_routes();
			$routes_present = isset( $routes['/mcp-ai/v1/mcp'] );
		}

		return array(
			'auth0_configured'             => ! empty( $settings['auth0_domain'] ) && ! empty( $settings['auth0_audience'] ),
			'federation_enabled'           => ! empty( $settings['enable_federation'] ),
			'federation_directory_enabled' => ! empty( $settings['enable_federation_directory'] ),
			'mesh_enabled'                 => ! empty( $settings['enable_mesh'] ),
			'tool_count'                   => $tool_count,
			'mcp_routes_present'           => $routes_present,
		);
	}

	/**
	 * Provider enablement + credential source (no live network tests).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_providers() {
		$providers = array();

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) || ! method_exists( 'WP_MCP_AI_Admin_Settings', 'get_available_providers' ) ) {
			return $providers;
		}

		$available = WP_MCP_AI_Admin_Settings::get_available_providers();
		if ( ! is_array( $available ) ) {
			return $providers;
		}

		$has_resolver = class_exists( 'WP_MCP_AI_Credential_Resolver' );

		foreach ( $available as $slug => $label ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$providers[] = array(
				'slug'            => $slug,
				'label'           => sanitize_text_field( (string) $label ),
				'has_credentials' => $has_resolver ? WP_MCP_AI_Credential_Resolver::has_credentials( $slug ) : false,
				'key_source'      => $has_resolver ? WP_MCP_AI_Credential_Resolver::get_key_source( $slug ) : 'none',
			);
		}

		return $providers;
	}

	/**
	 * REST context facts — wrap the context-fix service's own diagnostics.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_rest_context() {
		if ( ! class_exists( 'WP_MCP_AI_REST_API_Context_Fix' ) ) {
			$fix_file = WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest-api-context-fix.php';
			if ( file_exists( $fix_file ) ) {
				require_once $fix_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_REST_API_Context_Fix' ) || ! method_exists( 'WP_MCP_AI_REST_API_Context_Fix', 'get_diagnostics' ) ) {
			return array();
		}

		$diagnostics = WP_MCP_AI_REST_API_Context_Fix::get_diagnostics();
		return is_array( $diagnostics ) ? $diagnostics : array();
	}

	/**
	 * Pro dashboard charts diagnostics — wrap the page's own runner.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_pro_dashboard() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_Diagnostic' ) ) {
			$pro_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard-diagnostic.php';
			if ( file_exists( $pro_file ) ) {
				require_once $pro_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_Diagnostic' ) || ! method_exists( 'WP_MCP_AI_Pro_Dashboard_Diagnostic', 'run_diagnostics' ) ) {
			return array();
		}

		try {
			$results = WP_MCP_AI_Pro_Dashboard_Diagnostic::run_diagnostics();
		} catch ( Throwable $e ) {
			return array(
				'overall_status' => 'error',
				'message'        => sanitize_text_field( $e->getMessage() ),
			);
		}

		return is_array( $results ) ? $results : array();
	}
}
