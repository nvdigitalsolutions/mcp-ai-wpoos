<?php
/**
 * F&B Multi-Connection Source Routing Tests.
 *
 * Pins the per-table source catalog (Proposal 093): each spec table can be
 * mapped to a Remote Sites Drive connection + folder, tools and metrics then
 * resolve the right connection deterministically instead of asking the model
 * to pass connection IDs, and cross-connection calculations (dish margin:
 * Menu from one connection, Recipes/Supplier prices from another) work in a
 * single run.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );

/**
 * Test case: F&B multi-connection source routing.
 */
class Test_Fnb_Multi_Connection_Routing extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Path to the Pro admin classes.
	 *
	 * @var string
	 */
	private $admin_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';
		$this->admin_dir   = dirname( __DIR__, 4 ) . '/addons/pro/includes/admin';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-settings.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-data-source.php';
		}
		if ( ! interface_exists( 'WP_MCP_AI_Fnb_Table_Reader' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-table-reader.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-universal-reader.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Read_Table' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-tool-fnb-read-table.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Search_Table' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-tool-fnb-search-table.php';
		}

		delete_option( 'wp_mcp_ai_fnb_settings' );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		delete_option( 'wp_mcp_ai_fnb_table_map_proposals' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Configure a table_map via the settings option.
	 *
	 * @param array $map table_map value.
	 */
	private function set_table_map( array $map ) {
		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'table_map'      => $map,
				'connection_id'  => 'conn_global',
				'data_folder_id' => 'folder_global',
			)
		);
	}

	/**
	 * Skip when the toolkit is unavailable.
	 */
	private function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}
	}

	/**
	 * The table_map whitelist accepts connection_id/folder_id and drops junk.
	 */
	public function test_table_map_accepts_connection_and_folder_keys() {
		$this->maybe_skip();

		$this->set_table_map(
			array(
				'1.0' => array(
					'connection_id' => 'conn_sales',
					'folder_id'     => 'folder_sales',
					'file'          => 'menu.xlsx',
					'bogus_key'     => 'drop-me',
				),
			)
		);

		$entry = WP_MCP_AI_Fnb_Settings::source_map( '1.0' );

		$this->assertSame( 'conn_sales', $entry['connection_id'] );
		$this->assertSame( 'folder_sales', $entry['folder_id'] );
		$this->assertSame( 'menu.xlsx', $entry['file'] );
		$this->assertArrayNotHasKey( 'bogus_key', $entry );
	}

	/**
	 * An explicit tool-argument override beats the operator mapping.
	 */
	public function test_resolve_source_prefers_explicit_override() {
		$this->maybe_skip();

		$this->set_table_map(
			array(
				'1.0' => array(
					'connection_id' => 'conn_sales',
					'folder_id'     => 'folder_sales',
				),
			)
		);

		$source = WP_MCP_AI_Fnb_Data_Source::resolve_source( '1.0', 'conn_override' );

		$this->assertSame( 'conn_override', $source['connection_id'] );
		// The override changes the connection only — the mapped folder still applies.
		$this->assertSame( 'folder_sales', $source['folder_id'] );
	}

	/**
	 * The operator mapping beats the toolkit-global connection/folder.
	 */
	public function test_resolve_source_uses_table_map_over_global_settings() {
		$this->maybe_skip();

		$this->set_table_map(
			array(
				'1.0' => array(
					'connection_id' => 'conn_sales',
					'folder_id'     => 'folder_sales',
				),
			)
		);

		$source = WP_MCP_AI_Fnb_Data_Source::resolve_source( '1.0' );

		$this->assertSame( 'conn_sales', $source['connection_id'] );
		$this->assertSame( 'folder_sales', $source['folder_id'] );
	}

	/**
	 * With no operator mapping, the toolkit-global settings apply; the
	 * assumptions table falls back to the setup folder.
	 */
	public function test_resolve_source_falls_back_to_global_settings() {
		$this->maybe_skip();

		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'connection_id'   => 'conn_global',
				'data_folder_id'  => 'folder_data',
				'setup_folder_id' => 'folder_setup',
			)
		);

		$source      = WP_MCP_AI_Fnb_Data_Source::resolve_source( '1.0' );
		$assumptions = WP_MCP_AI_Fnb_Data_Source::resolve_source( 'assumptions' );

		$this->assertSame( 'conn_global', $source['connection_id'] );
		$this->assertSame( 'folder_data', $source['folder_id'] );
		$this->assertSame( 'folder_setup', $assumptions['folder_id'] );
	}

	/**
	 * With nothing configured, the source is empty and the Drive client's own
	 * auto-fallback decides (single connection resolves, several error loudly).
	 */
	public function test_resolve_source_empty_when_unconfigured() {
		$this->maybe_skip();

		$source = WP_MCP_AI_Fnb_Data_Source::resolve_source( '1.0' );

		$this->assertSame( '', $source['connection_id'] );
		$this->assertSame( '', $source['folder_id'] );
		$this->assertSame( '', $source['connection_name'] );
	}

	/**
	 * The get_table() path threads the connection override into the reader and
	 * the per-source cache key, so two connections never share cached rows.
	 */
	public function test_get_table_threads_connection_override_into_cache_key() {
		$this->maybe_skip();

		$first  = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0', null, 'conn_sales' );
		$second = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0', null, 'conn_kitchen' );

		$this->assertNotWPError( $first );
		$this->assertNotWPError( $second );
		$this->assertSame( 'conn_sales', $first['connection_id'] );
		$this->assertSame( 'conn_kitchen', $second['connection_id'] );
		$this->assertNotEmpty( $first['rows'], 'Menu fixture rows must resolve.' );

		// Distinct cache entries per source.
		$this->assertNotSame(
			get_transient( 'fnb_table_' . md5( '1.0:conn_sales||' ) ),
			get_transient( 'fnb_table_' . md5( '1.0:conn_kitchen||' ) ),
			'Each connection must cache its own rows.'
		);
	}

	/**
	 * The fnb_read_table tool accepts a connection_id override and reports the
	 * resolved connection back for observability.
	 */
	public function test_read_table_tool_accepts_connection_override() {
		$this->maybe_skip();

		$tool   = new WP_MCP_AI_Tool_Fnb_Read_Table();
		$result = $tool->execute(
			array(
				'table_id'      => '1.0',
				'connection_id' => 'conn_sales',
				'limit'         => 5,
			),
			array( 'user_id' => get_current_user_id() )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'conn_sales', $result['connection_id'] );
		$this->assertArrayHasKey( 'connection_name', $result );
		$this->assertCount( 5, $result['rows'] );
	}

	/**
	 * The fnb_search_table tool accepts a connection_id override and reports
	 * the resolved connection back.
	 */
	public function test_search_table_tool_accepts_connection_override() {
		$this->maybe_skip();

		$tool   = new WP_MCP_AI_Tool_Fnb_Search_Table();
		$result = $tool->execute(
			array(
				'table_id'      => '1.0',
				'connection_id' => 'conn_kitchen',
				'limit'         => 10,
			),
			array( 'user_id' => get_current_user_id() )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'conn_kitchen', $result['connection_id'] );
	}

	/**
	 * The settings-page sanitizer merges the Table Sources grid into table_map,
	 * keeps only known tables and keys, and preserves everything else.
	 */
	public function test_settings_sanitize_merges_table_map() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) || ! file_exists( $this->admin_dir . '/class-wp-mcp-ai-fnb-settings-page.php' ) ) {
			$this->markTestSkipped( 'F&B settings page not available in this matrix' );
		}

		require_once $this->admin_dir . '/class-wp-mcp-ai-fnb-settings-page.php';

		update_option(
			'wp_mcp_ai_fnb_settings',
			array( 'venue_name' => 'The Test Venue' )
		);

		$clean = WP_MCP_AI_Fnb_Settings_Page::sanitize_settings(
			array(
				'table_map' => array(
					'1.0'  => array(
						'connection_id' => 'conn_sales',
						'folder_id'     => 'folder_sales',
						'file'          => 'menu.xlsx',
						'bogus'         => 'x',
					),
					'99.0' => array( 'connection_id' => 'conn_bogus' ),
					'2.0'  => array(
						'connection_id' => '',
						'folder_id'     => '',
						'file'          => '',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'table_map', $clean );
		$this->assertSame( 'conn_sales', $clean['table_map']['1.0']['connection_id'] );
		$this->assertArrayNotHasKey( 'bogus', $clean['table_map']['1.0'] );
		$this->assertArrayNotHasKey( '99.0', $clean['table_map'], 'Unknown table IDs must be dropped.' );
		$this->assertArrayNotHasKey( '2.0', $clean['table_map'], 'Fully empty rows must be dropped.' );
		$this->assertSame( 'The Test Venue', $clean['venue_name'], 'Unrelated settings must survive the merge.' );
	}
}
