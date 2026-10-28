<?php
/**
 * Tests that the Food & Beverage toolkit settings page registers correctly
 * under the NV oOS admin menu, and that the toolkit init gate loads it when
 * `enable_fnb_toolkit` is on.
 *
 * Regression for the "F&B side admin menu not showing up when enabled" bug:
 * the toolkit shipped tools + presets but no admin page, and its init.php was
 * never loaded (the Pro module registry had no fnb module entry).
 *
 * Mirrors test-pro-schedule-toolkit-settings-registration.php.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

/**
 * Class Test_Fnb_Settings_Page_Registration
 */
class Test_Fnb_Settings_Page_Registration extends WP_UnitTestCase {

	/**
	 * Set up admin context.
	 */
	public function setUp(): void {
		parent::setUp();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		set_current_screen( 'dashboard' );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_redirect' );
		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_fnb_settings' );
		delete_option( 'wp_mcp_ai_fnb_table_map_proposals' );
		unset( $_POST['action'], $_POST['_wpnonce'], $_POST['proposals'], $_POST['overwrite'], $_POST['discard'], $_REQUEST['_wpnonce'] );
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/**
	 * Helper: ensure the settings page class is loaded.
	 *
	 * @return string|null Class name when available, null otherwise.
	 */
	protected function load_page_class() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return null;
		}
		$file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-fnb-settings-page.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		require_once $file;

		return class_exists( 'WP_MCP_AI_Fnb_Settings_Page' )
			? 'WP_MCP_AI_Fnb_Settings_Page'
			: null;
	}

	/**
	 * Redirect target captured by the blocked wp_redirect filter.
	 *
	 * @var string
	 */
	private $redirect_target = '';

	/**
	 * Capture and block redirects so handlers abort via WPDieException
	 * instead of exit-ing the PHPUnit process.
	 *
	 * @param string $location Redirect target.
	 * @return false
	 */
	public function capture_redirect( $location ) {
		$this->redirect_target = $location;

		return false;
	}

	/**
	 * Seed a proposal set and run the apply handler, catching the blocked
	 * redirect's WPDieException.
	 *
	 * @param string $page_class Settings page class name.
	 * @param array  $proposals  Proposals option value.
	 * @param array  $post       POST fields (proposals selection, overwrite, discard).
	 * @return void
	 */
	private function run_apply_handler( $page_class, array $proposals, array $post ) {
		update_option( 'wp_mcp_ai_fnb_table_map_proposals', $proposals );

		$nonce                = wp_create_nonce( 'wp_mcp_ai_fnb_automap_apply' );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
		$_POST['action']      = 'wp_mcp_ai_fnb_automap_apply';
		$_POST['proposals']   = isset( $post['proposals'] ) ? $post['proposals'] : array();
		$_POST['overwrite']   = isset( $post['overwrite'] ) ? $post['overwrite'] : '';
		$_POST['discard']     = isset( $post['discard'] ) ? $post['discard'] : '';

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 2 );

		try {
			call_user_func( array( $page_class, 'handle_automap_apply' ) );
			$this->fail( 'handle_automap_apply must abort via WPDieException after the blocked redirect.' );
		} catch ( WPDieException $e ) {
			unset( $e );
		}
	}

	/**
	 * The settings page is registered as a submenu of the NV oOS Pro menu.
	 *
	 * The first build attached it to the base "NV oOS" menu
	 * (`wp-mcp-ai-dashboard`); the toolkit belongs in the Pro section, so the
	 * parent is `nvoos-pro-dashboard`.
	 */
	public function test_submenu_registered_under_nvoos_pro_menu() {
		global $submenu;

		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		// Register a stub parent menu so the submenu has somewhere to attach.
		$submenu = array();
		add_menu_page( 'NV oOS Pro', 'NV oOS Pro', 'manage_options', 'nvoos-pro-dashboard', '__return_null' );

		call_user_func( array( $class, 'add_menu' ) );

		$this->assertArrayHasKey( 'nvoos-pro-dashboard', $submenu, 'NV oOS Pro submenu group exists' );

		$slugs = wp_list_pluck( $submenu['nvoos-pro-dashboard'], 2 );
		$this->assertContains( 'wp-mcp-ai-fnb', $slugs, 'F&B settings page registered under NV oOS Pro' );

		// Regression: the base NV oOS menu must NOT carry the F&B page.
		$this->assertArrayNotHasKey( 'wp-mcp-ai-dashboard', $submenu, 'F&B settings page must not attach to the base NV oOS menu' );
	}

	/**
	 * The F&B settings option is registered with the Settings API.
	 */
	public function test_settings_are_registered() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		call_user_func( array( $class, 'register_settings' ) );

		$registered = get_registered_settings();
		$this->assertArrayHasKey( 'wp_mcp_ai_fnb_settings', $registered, 'F&B settings option registered' );
	}

	/**
	 * The sanitizer whitelists the adapter and validates the as-of date.
	 */
	public function test_sanitize_settings() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$ref = new ReflectionMethod( $class, 'sanitize_settings' );
		$ref->setAccessible( true );

		$out = $ref->invoke(
			null,
			array(
				'adapter'                   => 'csv',
				'connection_id'             => 'conn-1',
				'data_folder_id'            => 'folder-data',
				'setup_folder_id'           => 'folder-setup',
				'data_workbook_id'          => 'workbook-1',
				'data_workbook_name'        => 'Surf Club',
				'drafts_folder_id'          => 'folder-drafts',
				'csv_data_dir'              => '/srv/fnb',
				'as_of_date'                => '2026-10-08',
				'demo_mode'                 => '1',
				'enable_fnb_native_storage' => '1',
			)
		);

		$this->assertSame( 'csv', $out['adapter'] );
		$this->assertSame( 'conn-1', $out['connection_id'] );
		$this->assertSame( 'folder-data', $out['data_folder_id'] );
		$this->assertSame( 'folder-setup', $out['setup_folder_id'] );
		$this->assertSame( 'workbook-1', $out['data_workbook_id'] );
		$this->assertSame( 'Surf Club', $out['data_workbook_name'] );
		$this->assertSame( 'folder-drafts', $out['drafts_folder_id'] );
		$this->assertSame( '/srv/fnb', $out['csv_data_dir'] );
		$this->assertSame( '2026-10-08', $out['as_of_date'] );
		$this->assertTrue( $out['demo_mode'] );
		$this->assertTrue( $out['enable_fnb_native_storage'] );

		// Invalid adapter falls back to drive; invalid date is dropped.
		$out_invalid = $ref->invoke(
			null,
			array(
				'adapter'    => 'ftp',
				'as_of_date' => 'yesterday',
			)
		);
		$this->assertSame( 'drive', $out_invalid['adapter'] );
		$this->assertSame( '', $out_invalid['as_of_date'] );
		$this->assertFalse( $out_invalid['enable_fnb_native_storage'], 'Native storage must reset when the checkbox is omitted.' );
	}

	/**
	 * The proposal review panel renders BEFORE the main settings form opens,
	 * so its Apply/Discard form is a sibling of the options.php form, never
	 * nested inside it.
	 *
	 * Regression for the stuck-proposals bug: the panel used to be a section
	 * callback inside the settings form, so the HTML parser dropped the nested
	 * <form> tag and the Apply/Discard buttons submitted options.php instead
	 * of the admin-post handler — proposals could never be cleared.
	 */
	public function test_review_panel_renders_outside_settings_form() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		update_option(
			'wp_mcp_ai_fnb_table_map_proposals',
			array(
				array(
					'table_id'       => '6.0',
					'connection_id'  => 'conn_sales',
					'folder_id'      => 'folder_sales',
					'file'           => 'Sales',
					'confidence'     => 0.75,
					'sample_columns' => array( 'Date', 'Month', 'Item ID' ),
				),
			)
		);

		// The Table Sources grid renders the table registry and settings map.
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-settings.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/class-wp-mcp-ai-fnb-data-source.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		call_user_func( array( $class, 'register_settings' ) );

		ob_start();
		call_user_func( array( $class, 'render_page' ) );
		$html = ob_get_clean();

		$main_form_pos = strpos( $html, '<form method="post" action="options.php">' );
		$apply_pos     = strpos( $html, 'wp_mcp_ai_fnb_automap_apply' );

		$this->assertNotFalse( $main_form_pos, 'The main settings form must render.' );
		$this->assertNotFalse( $apply_pos, 'The auto-map apply form must render.' );
		$this->assertLessThan(
			$main_form_pos,
			$apply_pos,
			'The review panel must render before the main settings form opens.'
		);
		$this->assertSame(
			1,
			substr_count( $html, '<form method="post" action="options.php">' ),
			'The main settings form must open exactly once.'
		);

		// Every action button before the settings form must be type="button" —
		// a submit-type button there would let Enter or a click submit the
		// wrong form (the original bug's mechanism).
		$before_main = substr( $html, 0, $main_form_pos );
		$this->assertStringNotContainsString( 'type="submit"', $before_main );

		// High-confidence rows render with their checkboxes pre-checked. The
		// table ID travels as the checkbox VALUE so dotted IDs like "6.0"
		// survive the POST round-trip unchanged (PHP form parsing mangles
		// dotted key segments, and sanitize_key() would strip the dot).
		$this->assertStringContainsString( 'name="proposals[]" value="6.0" checked', $html );
	}

	/**
	 * The Table Sources section intro lives INSIDE the settings form, so it
	 * must never emit its own <form> element.
	 */
	public function test_section_intro_never_renders_nested_form() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		update_option(
			'wp_mcp_ai_fnb_table_map_proposals',
			array(
				array(
					'table_id'       => '6.0',
					'connection_id'  => 'conn_sales',
					'folder_id'      => 'folder_sales',
					'file'           => 'Sales',
					'confidence'     => 0.75,
					'sample_columns' => array( 'Date' ),
				),
			)
		);

		ob_start();
		call_user_func( array( $class, 'render_table_sources_intro' ) );
		$intro = ob_get_clean();

		$this->assertStringNotContainsString( '<form', $intro );
		$this->assertStringContainsString( 'propose mappings', $intro );
		$this->assertStringContainsString( 'review panel above this form', $intro );
	}

	/**
	 * Applying the accepted proposals writes the checked tables into the
	 * operator-authored table_map, using the raw dotted table IDs as keys,
	 * and clears the pending proposals.
	 *
	 * Regression: the checkbox keys used to be the table IDs themselves
	 * (name="proposals[6.0]") and the handler re-sanitised them with
	 * sanitize_key(), which strips dots — "6.0" became "60" and never
	 * matched the stored proposal, so Apply reported "0 applied, N skipped".
	 */
	public function test_automap_apply_writes_selected_proposals_to_table_map() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$this->run_apply_handler(
			$class,
			array(
				array(
					'table_id'       => '6.0',
					'connection_id'  => 'conn_sales',
					'folder_id'      => 'folder_sales',
					'file'           => 'Sales',
					'confidence'     => 0.75,
					'sample_columns' => array( 'Date' ),
				),
				array(
					'table_id'       => '2.0',
					'connection_id'  => 'conn_kitchen',
					'folder_id'      => 'folder_kitchen',
					'file'           => 'Kitchen and bar',
					'confidence'     => 0.8,
					'sample_columns' => array( 'Item ID' ),
				),
				array(
					'table_id'       => '14.0',
					'connection_id'  => 'conn_finance',
					'folder_id'      => 'folder_finance',
					'file'           => 'Finance',
					'confidence'     => 0.91,
					'sample_columns' => array( 'Entry ID' ),
				),
			),
			array(
				'proposals' => array( '6.0', '2.0' ),
			)
		);

		$settings = get_option( 'wp_mcp_ai_fnb_settings', array() );

		$this->assertArrayHasKey( 'table_map', $settings );
		$this->assertSame( 'conn_sales', $settings['table_map']['6.0']['connection_id'] );
		$this->assertSame( 'folder_sales', $settings['table_map']['6.0']['folder_id'] );
		$this->assertSame( 'Sales', $settings['table_map']['6.0']['file'] );
		$this->assertSame( 'conn_kitchen', $settings['table_map']['2.0']['connection_id'] );
		$this->assertArrayNotHasKey( '14.0', $settings['table_map'], 'Unchecked proposals must not be applied.' );
		$this->assertFalse( get_option( 'wp_mcp_ai_fnb_table_map_proposals' ), 'Applied proposals must be cleared.' );
		$this->assertStringContainsString( 'wp-mcp-ai-fnb', $this->redirect_target, 'The handler must redirect back to the F&B settings page.' );
	}

	/**
	 * Operator-authored mappings win unless the overwrite box is checked.
	 */
	public function test_automap_apply_preserves_operator_mappings_without_overwrite() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		$proposal = array(
			array(
				'table_id'       => '6.0',
				'connection_id'  => 'conn_sales',
				'folder_id'      => 'folder_sales',
				'file'           => 'Sales',
				'confidence'     => 0.75,
				'sample_columns' => array( 'Date' ),
			),
		);

		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'table_map'  => array(
					'6.0' => array(
						'connection_id' => 'conn_operator',
						'folder_id'     => 'folder_operator',
						'file'          => 'Operator map',
					),
				),
				'venue_name' => 'Keep Me',
			)
		);

		// Without overwrite: the operator-authored row stays untouched.
		$this->run_apply_handler( $class, $proposal, array( 'proposals' => array( '6.0' ) ) );
		$settings = get_option( 'wp_mcp_ai_fnb_settings', array() );
		$this->assertSame( 'conn_operator', $settings['table_map']['6.0']['connection_id'], 'Operator mappings win without overwrite.' );
		$this->assertSame( 'Keep Me', $settings['venue_name'], 'Unrelated settings must survive.' );
		$this->assertFalse( get_option( 'wp_mcp_ai_fnb_table_map_proposals' ) );

		// With overwrite: the proposal replaces the operator row.
		$this->run_apply_handler(
			$class,
			$proposal,
			array(
				'proposals' => array( '6.0' ),
				'overwrite' => '1',
			)
		);
		$settings = get_option( 'wp_mcp_ai_fnb_settings', array() );
		$this->assertSame( 'conn_sales', $settings['table_map']['6.0']['connection_id'], 'Overwrite replaces the operator mapping.' );
		$this->assertSame( 'folder_sales', $settings['table_map']['6.0']['folder_id'] );
		$this->assertFalse( get_option( 'wp_mcp_ai_fnb_table_map_proposals' ) );
	}

	/**
	 * Discarding clears the pending proposals without touching the settings.
	 */
	public function test_automap_discard_clears_proposals() {
		$class = $this->load_page_class();
		if ( null === $class ) {
			$this->markTestSkipped( 'Pro addon not active or settings file missing.' );
		}

		update_option(
			'wp_mcp_ai_fnb_settings',
			array( 'venue_name' => 'Keep Me' )
		);

		$this->run_apply_handler(
			$class,
			array(
				array(
					'table_id'       => '6.0',
					'connection_id'  => 'conn_sales',
					'folder_id'      => 'folder_sales',
					'file'           => 'Sales',
					'confidence'     => 0.75,
					'sample_columns' => array( 'Date' ),
				),
			),
			array( 'discard' => '1' )
		);

		$this->assertFalse( get_option( 'wp_mcp_ai_fnb_table_map_proposals' ), 'Discarded proposals must be cleared.' );

		$settings = get_option( 'wp_mcp_ai_fnb_settings', array() );
		$this->assertSame( 'Keep Me', $settings['venue_name'], 'Discard must not touch the settings option.' );
		$this->assertArrayNotHasKey( 'table_map', $settings, 'Discard must not write a table_map.' );
	}

	/**
	 * With the toolkit toggle on, the init gate loads the settings page and
	 * registers the admin menu hook.
	 */
	public function test_init_gate_loads_settings_page_when_enabled() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active.' );
		}

		update_option( 'wp_mcp_ai_settings', array( 'enable_fnb_toolkit' => true ) );

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/food-beverage/init.php';

		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Settings_Page' ), 'F&B settings page must load in admin context.' );
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'WP_MCP_AI_Fnb_Settings_Page', 'add_menu' ) ),
			'F&B admin menu hook must be registered.'
		);

		// Phase 9: the F&B Management top-level menu must load too.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Admin_Menu' ), 'F&B admin menu class must load in admin context.' );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Fnb_Dashboard_Page' ), 'F&B dashboard page class must load in admin context.' );
		$this->assertNotFalse(
			has_action( 'admin_menu', array( 'WP_MCP_AI_Fnb_Admin_Menu', 'register_parent_menu' ) ),
			'F&B top-level menu hook must be registered.'
		);
	}
}
