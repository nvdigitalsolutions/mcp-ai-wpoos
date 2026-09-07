<?php
/**
 * Characterization tests for the ported toolkit data-store factory (Wave F1).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   factory/store classes; the public data-store contract is asserted.
 * - Standalone matrix (base plugin absent): the ported classes in
 *   `src/class-wp-mcp-ai-toolkit-data-store-factory.php` +
 *   `src/data-stores/` are asserted in full, including the tenant-repository
 *   bridge (`src/class-wp-mcp-ai-tenant-repository.php`).
 *
 * JetEngine is not loaded in CI — availability assertions are
 * probe-conditional (local Docker monolith has JetEngine active).
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Toolkit data-store factory tests.
 */
class Test_Toolkit_Data_Store_Factory extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared wp_mcp_ai_settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * Force the CCT preference off so backend selection is deterministic.
	 *
	 * @return void
	 */
	private function force_cpt_preference(): void {
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings                         = is_array( $settings ) ? $settings : array();
		$settings['enable_jetengine_cct'] = 0;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The factory must always return a data-store implementation.
	 */
	public function test_get_store_returns_data_store_interface(): void {
		$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'ecommerce', 'products' );
		$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Data_Store', $store );
	}

	/**
	 * The is_jetengine_installed probe must mirror jet_engine() availability.
	 */
	public function test_is_jetengine_installed_probe(): void {
		$this->assertSame( function_exists( 'jet_engine' ), WP_MCP_AI_Toolkit_Data_Store_Factory::is_jetengine_installed() );
	}

	/**
	 * With the CCT preference off the factory must choose the CPT backend.
	 */
	public function test_storage_type_is_cpt_when_preference_disabled(): void {
		$this->force_cpt_preference();
		$this->assertSame( 'cpt', WP_MCP_AI_Toolkit_Data_Store_Factory::get_storage_type() );
		$this->assertInstanceOf(
			'WP_MCP_AI_Toolkit_CPT_Store',
			WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'ecommerce', 'products' )
		);
	}

	/**
	 * With the CCT preference on the backend must follow JetEngine's real
	 * availability (probe-conditional — CI has no JetEngine).
	 */
	public function test_storage_type_follows_jetengine_availability(): void {
		$settings                         = get_option( 'wp_mcp_ai_settings', array() );
		$settings                         = is_array( $settings ) ? $settings : array();
		$settings['enable_jetengine_cct'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( ! function_exists( 'jet_engine' ) ) {
			$this->assertSame( 'cpt', WP_MCP_AI_Toolkit_Data_Store_Factory::get_storage_type() );
			return;
		}

		// Real JetEngine always ships the custom-content-types module; the
		// factory only falls back when that module is missing.
		$expected   = 'cct';
		$jet_engine = jet_engine();
		if ( ! isset( $jet_engine->modules ) ) {
			$expected = 'cpt';
		} elseif ( ! isset( $jet_engine->modules->modules_manager ) ) {
			$expected = 'cpt';
		} else {
			$module = $jet_engine->modules->modules_manager->get_module( 'custom-content-types' );
			if ( ! $module || ! isset( $module->instance ) || ! $module->instance ) {
				$expected = 'cpt';
			}
		}
		$this->assertSame( $expected, WP_MCP_AI_Toolkit_Data_Store_Factory::get_storage_type() );
	}

	/**
	 * get_tenant_store must return a data store in both tenant states
	 * (scoped when a tenant resolves, bypass otherwise).
	 */
	public function test_get_tenant_store_returns_store(): void {
		$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'ecommerce', 'products' );
		$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Data_Store', $store );
	}

	/**
	 * CPT store CRUD round-trip: create/get/update/query/delete.
	 */
	public function test_cpt_store_crud_round_trip(): void {
		$store = new WP_MCP_AI_Toolkit_CPT_Store( 'ecommerce', 'products' );
		$store->register_post_type();

		$this->assertTrue( $store->is_available() );
		$this->assertSame( 'cpt', $store->get_storage_type() );
		// 20-char slug cap — 'mcp_ecommerce_products' truncates.
		$this->assertSame( 'mcp_ecommerce_produc', $store->get_content_type_slug() );

		$item_id = $store->create_item(
			array(
				'title' => 'Test Product',
				'sku'   => 'SKU-1',
			)
		);
		$this->assertIsInt( $item_id );
		$this->assertGreaterThan( 0, $item_id );

		$item = $store->get_item( $item_id );
		$this->assertSame( 'Test Product', $item['title'] );
		$this->assertSame( 'SKU-1', $item['sku'] );

		$this->assertTrue( $store->update_item( $item_id, array( 'sku' => 'SKU-2' ) ) );
		$item = $store->get_item( $item_id );
		$this->assertSame( 'SKU-2', $item['sku'] );

		$items = $store->query_items( array( 'per_page' => 5 ) );
		$this->assertCount( 1, $items );

		$this->assertTrue( $store->delete_item( $item_id ) );
		$this->assertInstanceOf( 'WP_Error', $store->get_item( $item_id ) );
	}

	/**
	 * The canonical CRM slug map must survive the port (byte-identical).
	 */
	public function test_cpt_store_uses_canonical_crm_slug(): void {
		$store = new WP_MCP_AI_Toolkit_CPT_Store( 'crm', 'leads' );
		$this->assertSame( 'mcp_ai_lead', $store->get_content_type_slug() );

		$contacts = new WP_MCP_AI_Toolkit_CPT_Store( 'crm', 'contacts' );
		$this->assertSame( 'mcp_crm_contacts', $contacts->get_content_type_slug() );
	}

	/**
	 * Auto-generated slugs must truncate to 20 characters.
	 */
	public function test_cpt_store_truncates_long_slugs(): void {
		$store = new WP_MCP_AI_Toolkit_CPT_Store( 'very_long_toolkit_slug', 'extremely_long_entity_type' );
		$slug  = $store->get_content_type_slug();
		$this->assertLessThanOrEqual( 20, strlen( $slug ) );
		$this->assertStringStartsWith( 'mcp_', $slug );
	}

	/**
	 * The field-schema filter contract must pass toolkit and entity args.
	 */
	public function test_cpt_store_field_schema_filter_contract(): void {
		$captured = array();
		add_filter(
			'wp_mcp_ai_toolkit_cpt_field_schema',
			static function ( $schema, $toolkit, $entity ) use ( &$captured ) {
				$captured = array( $toolkit, $entity );
				return array_merge( $schema, array( 'name' => array( 'type' => 'string' ) ) );
			},
			10,
			3
		);

		$store = new WP_MCP_AI_Toolkit_CPT_Store( 'ecommerce', 'products' );
		$this->assertSame( array( 'ecommerce', 'products' ), $captured );
		$this->assertArrayHasKey( 'name', $store->get_field_schema() );
	}

	/**
	 * The CCT store must report the cct backend and degrade to WP_Error
	 * creation when JetEngine is absent (probe-conditional).
	 */
	public function test_cct_store_degrades_without_jetengine(): void {
		$store = new WP_MCP_AI_Toolkit_CCT_Store( 'ecommerce', 'products' );
		$this->assertSame( 'cct', $store->get_storage_type() );

		if ( function_exists( 'jet_engine' ) ) {
			// JetEngine present — availability is environment-dependent;
			// only the backend-type contract is asserted here.
			$this->assertSame( 'cct', $store->get_storage_type() );
			return;
		}

		$this->assertFalse( $store->is_available() );
		$result = $store->create_item( array( 'title' => 'Uncreatable' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Standalone only: the registry's toolkit_data_store module must boot
	 * once its files exist.
	 */
	public function test_data_store_module_boots_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith loads the factory on demand.' );
		}

		$registry = WP_MCP_AI_Pro_Module_Registry::get_instance();
		$registry->boot();
		$this->assertTrue( $registry->is_loaded( 'toolkit_data_store' ) );
	}
}
