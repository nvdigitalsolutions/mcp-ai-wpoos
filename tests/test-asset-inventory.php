<?php
/**
 * Tests for Asset Inventory System
 *
 * @package WP_MCP_AI
 */

/**
 * Test Asset Inventory functionality.
 */
class Test_Asset_Inventory extends WP_UnitTestCase {
	/**
	 * Asset inventory instance.
	 *
	 * @var WP_MCP_AI_Asset_Inventory
	 */
	protected $inventory;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->inventory = WP_MCP_AI_Asset_Inventory::get_instance();
	}

	/**
	 * Test singleton instance.
	 */
	public function test_get_instance() {
		$this->assertInstanceOf( WP_MCP_AI_Asset_Inventory::class, $this->inventory );

		// Test singleton.
		$instance2 = WP_MCP_AI_Asset_Inventory::get_instance();
		$this->assertSame( $this->inventory, $instance2 );
	}

	/**
	 * Test asset discovery.
	 */
	public function test_discover_assets() {
		$assets = $this->inventory->discover_assets();

		// Should return an array.
		$this->assertIsArray( $assets );

		// Should have discovered some assets.
		$this->assertGreaterThan( 0, count( $assets ) );

		// Each asset should have required fields.
		foreach ( $assets as $asset ) {
			$this->assertArrayHasKey( 'id', $asset );
			$this->assertArrayHasKey( 'name', $asset );
			$this->assertArrayHasKey( 'type', $asset );
			$this->assertArrayHasKey( 'classification', $asset );
			$this->assertArrayHasKey( 'location', $asset );
			$this->assertArrayHasKey( 'owner', $asset );
			$this->assertArrayHasKey( 'description', $asset );
			$this->assertArrayHasKey( 'last_modified', $asset );
		}
	}

	/**
	 * Test asset inventory storage.
	 */
	public function test_get_asset_inventory() {
		// Run discovery first.
		$this->inventory->discover_assets();

		// Get stored inventory.
		$stored = $this->inventory->get_asset_inventory();

		// Should have inventory data.
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'assets', $stored );
		$this->assertArrayHasKey( 'generated_at', $stored );
		$this->assertArrayHasKey( 'total_count', $stored );
	}

	/**
	 * Test get assets by classification.
	 */
	public function test_get_assets_by_classification() {
		// Run discovery first.
		$this->inventory->discover_assets();

		// Get restricted assets.
		$restricted = $this->inventory->get_assets_by_classification( 'restricted' );
		$this->assertIsArray( $restricted );

		// All returned assets should be restricted.
		foreach ( $restricted as $asset ) {
			$this->assertEquals( 'restricted', $asset['classification'] );
		}

		// Get confidential assets.
		$confidential = $this->inventory->get_assets_by_classification( 'confidential' );
		$this->assertIsArray( $confidential );

		// All returned assets should be confidential.
		foreach ( $confidential as $asset ) {
			$this->assertEquals( 'confidential', $asset['classification'] );
		}
	}

	/**
	 * Test get assets by type.
	 */
	public function test_get_assets_by_type() {
		// Run discovery first.
		$this->inventory->discover_assets();

		// Get code assets.
		$code = $this->inventory->get_assets_by_type( 'code' );
		$this->assertIsArray( $code );

		// All returned assets should be code type.
		foreach ( $code as $asset ) {
			$this->assertEquals( 'code', $asset['type'] );
		}

		// Get configuration assets.
		$config = $this->inventory->get_assets_by_type( 'configuration' );
		$this->assertIsArray( $config );

		// All returned assets should be configuration type.
		foreach ( $config as $asset ) {
			$this->assertEquals( 'configuration', $asset['type'] );
		}
	}

	/**
	 * Test asset statistics.
	 */
	public function test_get_asset_statistics() {
		// Run discovery first.
		$this->inventory->discover_assets();

		// Get statistics.
		$stats = $this->inventory->get_asset_statistics();

		// Should have required fields.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total', $stats );
		$this->assertArrayHasKey( 'by_type', $stats );
		$this->assertArrayHasKey( 'by_classification', $stats );
		$this->assertArrayHasKey( 'generated_at', $stats );

		// Total should match asset count.
		$this->assertGreaterThan( 0, $stats['total'] );

		// Should have counts by type.
		$this->assertIsArray( $stats['by_type'] );
		$this->assertNotEmpty( $stats['by_type'] );

		// Should have counts by classification.
		$this->assertIsArray( $stats['by_classification'] );
		$this->assertNotEmpty( $stats['by_classification'] );
	}

	/**
	 * Test classification levels constant.
	 */
	public function test_classification_levels() {
		$levels = WP_MCP_AI_Asset_Inventory::CLASSIFICATION_LEVELS;

		$this->assertIsArray( $levels );
		$this->assertArrayHasKey( 'public', $levels );
		$this->assertArrayHasKey( 'internal', $levels );
		$this->assertArrayHasKey( 'confidential', $levels );
		$this->assertArrayHasKey( 'restricted', $levels );
	}

	/**
	 * Test asset types constant.
	 */
	public function test_asset_types() {
		$types = WP_MCP_AI_Asset_Inventory::ASSET_TYPES;

		$this->assertIsArray( $types );
		$this->assertArrayHasKey( 'api_key', $types );
		$this->assertArrayHasKey( 'user_data', $types );
		$this->assertArrayHasKey( 'chat_transcript', $types );
		$this->assertArrayHasKey( 'code', $types );
		$this->assertArrayHasKey( 'configuration', $types );
		$this->assertArrayHasKey( 'database', $types );
		$this->assertArrayHasKey( 'third_party', $types );
		$this->assertArrayHasKey( 'documentation', $types );
	}

	/**
	 * Test that code assets are discovered.
	 */
	public function test_code_assets_discovered() {
		$this->inventory->discover_assets();
		$code_assets = $this->inventory->get_assets_by_type( 'code' );

		// Should have at least one code asset.
		$this->assertGreaterThan( 0, count( $code_assets ) );

		// Should find includes directory.
		$found_includes = false;
		foreach ( $code_assets as $asset ) {
			if ( strpos( $asset['id'], 'code_includes' ) !== false ) {
				$found_includes = true;
				break;
			}
		}
		$this->assertTrue( $found_includes, 'Should discover includes directory as code asset' );
	}

	/**
	 * Test that third-party integrations are discovered.
	 */
	public function test_third_party_integrations_discovered() {
		$this->inventory->discover_assets();
		$integrations = $this->inventory->get_assets_by_type( 'third_party' );

		// Should have discovered integrations.
		$this->assertGreaterThan( 0, count( $integrations ) );

		// Should include OpenAI.
		$found_openai = false;
		foreach ( $integrations as $asset ) {
			if ( strpos( $asset['id'], 'integration_openai' ) !== false ) {
				$found_openai = true;
				break;
			}
		}
		$this->assertTrue( $found_openai, 'Should discover OpenAI integration' );
	}

	/**
	 * Test that documentation assets are discovered.
	 */
	public function test_documentation_assets_discovered() {
		$this->inventory->discover_assets();
		$docs = $this->inventory->get_assets_by_type( 'documentation' );

		// Should have discovered some documentation.
		$this->assertGreaterThan( 0, count( $docs ) );
	}

	/**
	 * Clean up after tests.
	 */
	public function tearDown(): void {
		// Clean up stored inventory.
		delete_option( 'wp_mcp_ai_asset_inventory' );

		parent::tearDown();
	}
}
