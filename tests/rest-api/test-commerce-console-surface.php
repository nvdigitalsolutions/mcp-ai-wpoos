<?php
/**
 * Tests the Proposal 089 commerce console surface (2.4.0):
 * feature flags for the four commerce toolkits, the schema-driven toolkit
 * settings sections, the read-only sync-runs REST bridge, and the
 * commerce/content record entities.
 *
 * Covers: WooCommerce gating on the flags, section → settings-schema
 * exposure (tab, enable toggle, guardrail fields), sync-runs permission +
 * error contracts, and the records schema additions. Skipped when the Pro
 * addon classes are not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Commerce_Console_Surface_Test extends WP_UnitTestCase {

	/**
	 * Section IDs this test registered (for registry cleanup).
	 *
	 * @var string[]
	 */
	private $registered_sections = array();

	/**
	 * Set up per test.
	 */
	public function set_up() {
		parent::set_up();
		update_option( 'wp_mcp_ai_settings', array() );
	}

	/**
	 * Restore the settings registry to its boot-time state and drop the
	 * Remote Sites fixtures this suite may have created.
	 */
	public function tear_down() {
		foreach ( $this->registered_sections as $section_id ) {
			WP_MCP_AI_Settings_Registry::unregister_section( $section_id );
		}
		$this->registered_sections = array();
		delete_option( 'wp_mcp_ai_settings' );
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		}
		parent::tear_down();
	}

	/**
	 * Load the four commerce section classes (idempotent).
	 */
	private function load_commerce_sections() {
		if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/abstract-wp-mcp-ai-settings-section.php';
		}

		$p = defined( 'WP_MCP_AI_PRO_PATH' ) ? WP_MCP_AI_PRO_PATH : '';

		if ( ! class_exists( 'WP_MCP_AI_Commerce_Toolkit_Settings_Section' ) ) {
			require_once $p . 'includes/admin/sections/abstract-wp-mcp-ai-commerce-toolkit-section.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Ecommerce_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-ecommerce-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Ezuite_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-ezuite-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_Shopify_Sync_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-shopify-sync-toolkit.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Section_FlowHub_Toolkit' ) ) {
			require_once $p . 'includes/admin/sections/class-wp-mcp-ai-section-flowhub-toolkit.php';
		}
	}

	/**
	 * Register the four commerce sections + tabs, mirroring the Pro module
	 * registry closure.
	 */
	private function register_commerce_sections() {
		$this->load_commerce_sections();

		$tabs = array(
			'ecommerce'    => array(
				'title' => 'E-Commerce',
				'icon'  => 'dashicons-cart',
			),
			'ezuite'       => array(
				'title' => 'EZuite ERP',
				'icon'  => 'dashicons-networking',
			),
			'shopify_sync' => array(
				'title' => 'Shopify Sync',
				'icon'  => 'dashicons-store',
			),
			'flowhub'      => array(
				'title' => 'FlowHub',
				'icon'  => 'dashicons-shield-alt',
			),
		);
		foreach ( $tabs as $tab_id => $tab ) {
			WP_MCP_AI_Settings_Registry::register_tab( $tab_id, $tab );
		}

		$sections = array(
			new WP_MCP_AI_Section_Ecommerce_Toolkit(),
			new WP_MCP_AI_Section_Ezuite_Toolkit(),
			new WP_MCP_AI_Section_Shopify_Sync_Toolkit(),
			new WP_MCP_AI_Section_FlowHub_Toolkit(),
		);
		foreach ( $sections as $section ) {
			WP_MCP_AI_Settings_Registry::register_section( $section );
			$this->registered_sections[] = $section->get_id();
		}
	}

	/**
	 * Commerce flags reflect the toolkit toggles when WooCommerce is
	 * present, and are forced off without it (the toolkit bootstraps gate on
	 * `class_exists( 'WooCommerce' )`, so the nav must mirror that).
	 */
	public function test_commerce_flags_reflect_toggles_and_woocommerce() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_ecommerce_toolkit'    => true,
				'enable_ezuite_toolkit'       => true,
				'enable_shopify_sync_toolkit' => true,
				'enable_flowhub_toolkit'      => true,
			)
		);

		$flags = WP_MCP_AI_Feature_Flags::all();

		if ( class_exists( 'WooCommerce' ) ) {
			// All toggles on → every commerce flag on.
			$this->assertTrue( $flags['ecommerce'] );
			$this->assertTrue( $flags['ezuite'] );
			$this->assertTrue( $flags['shopify_sync'] );
			$this->assertTrue( $flags['flowhub'] );

			// Toggles off → flags off.
			update_option( 'wp_mcp_ai_settings', array() );
			$flags = WP_MCP_AI_Feature_Flags::all();
			$this->assertFalse( $flags['ecommerce'] );
			$this->assertFalse( $flags['ezuite'] );
			$this->assertFalse( $flags['shopify_sync'] );
			$this->assertFalse( $flags['flowhub'] );
			return;
		}

		// Without WooCommerce the flags are off regardless of the toggles.
		$this->assertFalse( $flags['ecommerce'] );
		$this->assertFalse( $flags['ezuite'] );
		$this->assertFalse( $flags['shopify_sync'] );
		$this->assertFalse( $flags['flowhub'] );
	}

	/**
	 * The content-records flag defaults true and is filterable.
	 */
	public function test_content_records_flag_defaults_true() {
		$this->assertTrue( WP_MCP_AI_Feature_Flags::all()['content_records'] );

		add_filter( 'wp_mcp_ai_feature_available_content_records', '__return_false' );
		$this->assertFalse( WP_MCP_AI_Feature_Flags::all()['content_records'] );
		remove_filter( 'wp_mcp_ai_feature_available_content_records', '__return_false' );
	}

	/**
	 * The commerce sections expose their fields through the schema-driven
	 * settings surface with the right tabs and guardrail defaults.
	 */
	public function test_commerce_sections_expose_schema_fields() {
		if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			$this->markTestSkipped( 'Settings registry not loaded.' );
		}
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_REST_Settings_Controller' ) ) {
			$this->markTestSkipped( 'Settings REST controller not loaded.' );
		}

		$this->register_commerce_sections();

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Settings_Controller() )->get_schema();
		$data   = $result->get_data();

		$this->assertArrayHasKey( 'fields', $data['data'] );
		$fields = array();
		foreach ( $data['data']['fields'] as $field ) {
			$fields[ $field['key'] ] = $field;
		}

		$this->assertArrayHasKey( 'ecommerce_safety_buffer', $fields );
		$this->assertSame( 'ecommerce', $fields['ecommerce_safety_buffer']['tab'] );
		$this->assertSame( 2, $fields['ecommerce_safety_buffer']['default'] );
		$this->assertSame( 0.0, $fields['ecommerce_safety_buffer']['min'] );
		$this->assertSame( 10.0, $fields['ecommerce_safety_buffer']['max'] );

		// The enable toggles stay owned by the base Tools section (tab
		// `tools`) — the schema dedupes by key, first registrant wins.
		$this->assertArrayHasKey( 'enable_ecommerce_toolkit', $fields );
		$this->assertSame( 'tools', $fields['enable_ecommerce_toolkit']['tab'] );

		$this->assertArrayHasKey( 'ezuite_safety_buffer', $fields );
		$this->assertSame( 'ezuite', $fields['ezuite_safety_buffer']['tab'] );

		$this->assertArrayHasKey( 'shopify_sync_safety_buffer', $fields );
		$this->assertSame( 'shopify_sync', $fields['shopify_sync_safety_buffer']['tab'] );

		$this->assertArrayHasKey( 'flowhub_safety_buffer', $fields );
		$this->assertSame( 'flowhub', $fields['flowhub_safety_buffer']['tab'] );

		// Tabs are registered so the SPA renders labels.
		$this->assertArrayHasKey( 'ecommerce', $data['data']['tabs'] );
	}

	/**
	 * Non-admins are refused by the sync-runs controller.
	 */
	public function test_sync_runs_non_admin_is_forbidden() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Unknown toolkit slugs get a structured 404.
	 */
	public function test_sync_runs_unknown_toolkit_is_404() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/commerce/sync-runs/mystery/latest' );
		$request->set_param( 'toolkit', 'mystery' );

		$result = ( new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller() )->get_latest_run( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sync_runs_unknown_toolkit', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Listing runs returns the envelope; unavailable logs surface the 501
	 * contract when the Sync Log Manager is absent.
	 */
	public function test_sync_runs_list_envelope() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$controller = new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller();
		$result     = $controller->get_runs( new WP_REST_Request() );

		if ( ! class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) ) {
			$this->assertWPError( $result );
			$this->assertSame( 'sync_runs_unavailable', $result->get_error_code() );
			$this->assertSame( 501, $result->get_error_data()['status'] );
			return;
		}

		$this->assertNotWPError( $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'runs', $data['data'] );
	}

	/**
	 * Phase B — an unknown remote site id gets a structured 404.
	 */
	public function test_sync_runs_unknown_remote_site_is_404() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/commerce/sync-runs' );
		$request->set_param( 'remote_site', 'no-such-site' );

		$result = ( new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller() )->get_runs( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sync_runs_unknown_remote_site', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Phase B — a disabled remote WordPress connection is refused.
	 */
	public function test_sync_runs_disabled_remote_site_is_403() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Remote Site Manager not loaded.' );
		}

		$save = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Disabled fixture store',
				'url'             => 'https://store.example.com',
				'connection_type' => 'wordpress',
				'enabled'         => 0,
			)
		);
		if ( is_wp_error( $save ) ) {
			$this->markTestSkipped( 'Connection fixture rejected: ' . $save->get_error_message() );
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/commerce/sync-runs' );
		$request->set_param( 'remote_site', $save );

		$result = ( new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller() )->get_runs( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'sync_runs_remote_site_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Phase B — the remote proxy passes the run payload through (zero real
	 * HTTP — the pre_http_request filter intercepts the outbound request).
	 */
	public function test_sync_runs_remote_proxy_returns_runs() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$this->markTestSkipped( 'Remote Site Manager not loaded.' );
		}

		$save = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Remote fixture store',
				'url'             => 'https://store.example.com',
				'connection_type' => 'wordpress',
				'auth_type'       => 'application_password',
				'username'        => 'fixture',
				'password'        => 'app-password',
				'enabled'         => 1,
			)
		);
		if ( is_wp_error( $save ) ) {
			$this->markTestSkipped( 'Connection fixture rejected: ' . $save->get_error_message() );
		}

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$http_calls ) {
				$http_calls++;
				$payload = wp_json_encode(
					array(
						'success' => true,
						'data'    => array(
							'runs' => array(
								array(
									'toolkit'       => 'woocommerce',
									'run_id'        => 'remote-run-1',
									'connection_id' => '',
									'dry_run'       => false,
									'started_at'    => '2026-10-25 10:00:00',
									'finished_at'   => '2026-10-25 10:01:00',
									'status'        => 'completed',
									'summary'       => array(
										'items_updated' => 4,
										'items_skipped' => 0,
										'items_errored' => 0,
									),
								),
							),
						),
					)
				);
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $payload,
				);
			},
			10,
			3
		);

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai-pro/v1/commerce/sync-runs' );
		$request->set_param( 'remote_site', $save );
		$request->set_param( 'toolkit', 'woocommerce' );

		$result = ( new WP_MCP_AI_Pro_Commerce_Sync_Runs_REST_Controller() )->get_runs( $request );

		$this->assertNotWPError( $result );
		$data = $result->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertSame( $save, $data['data']['remote_site'] );
		$this->assertCount( 1, $data['data']['runs'] );
		$this->assertSame( 'remote-run-1', $data['data']['runs'][0]['run_id'] );
		$this->assertSame( 1, $http_calls );
	}

	/**
	 * The records schema exposes product (group commerce) and post/page
	 * (group content) when their post types exist.
	 */
	public function test_records_schema_exposes_commerce_content_entities() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'labels'       => array( 'name' => 'Products' ),
					'public'       => false,
					'show_in_rest' => true,
					'supports'     => array( 'title' ),
				)
			);
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		$this->assertArrayHasKey( 'product', $data['entities'] );
		$this->assertSame( 'commerce', $data['entities']['product']['group'] );
		$this->assertSame( 'product', $data['entities']['product']['postType'] );

		$this->assertArrayHasKey( 'post', $data['entities'] );
		$this->assertSame( 'content', $data['entities']['post']['group'] );

		$this->assertArrayHasKey( 'page', $data['entities'] );
		$this->assertSame( 'content', $data['entities']['page']['group'] );
	}

	/**
	 * Meta-in-REST registration never grants custom-fields support to a
	 * post type without a private meta schema — the deterministic pin is a
	 * scratch CPT; core `post` may already carry the support from other
	 * plugins (Elementor grants it in the CI bootstrap), so that half only
	 * asserts the controller did not ADD it.
	 */
	public function test_register_meta_in_rest_skips_platform_post_types() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}

		register_post_type(
			'p089_schema_free',
			array(
				'labels'   => array( 'name' => 'Schema-free probe' ),
				'public'   => false,
				'supports' => array( 'title' ),
			)
		);

		$post_before = post_type_supports( 'post', 'custom-fields' );

		( new WP_MCP_AI_Pro_Records_REST_Controller() )->register_meta_in_rest();

		// Deterministic pin: a schema-free post type must not gain support.
		$this->assertFalse( post_type_supports( 'p089_schema_free', 'custom-fields' ) );

		// Environment-tolerant pin: the controller must not ADD support to
		// core posts when they did not have it (skip when another plugin
		// granted it at boot — e.g. Elementor in the CI bootstrap).
		if ( ! $post_before ) {
			$this->assertFalse( post_type_supports( 'post', 'custom-fields' ) );
			$this->assertFalse( post_type_supports( 'page', 'custom-fields' ) );
		}
	}
}
