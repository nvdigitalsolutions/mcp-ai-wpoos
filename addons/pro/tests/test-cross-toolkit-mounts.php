<?php
/**
 * Test_Cross_Toolkit_Mounts
 *
 * Asserts that mounted-foreign surfaces:
 *   - appear read-only in the consumer's descriptor,
 *   - propagate disable from the source toolkit,
 *   - keep assistant binding with the source toolkit.
 *
 * Canonical fixture: Architectural Design mounts `health-records-consolidate`
 * from the Healthcare toolkit.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Cross_Toolkit_Mounts extends WP_UnitTestCase {

	/** Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architectural_Design_MCP_Server() );

		// Reset configs to defaults.
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'crm' );
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'health' );
		delete_option( WP_MCP_AI_Toolkit_Server_Base::OPTION_PREFIX . 'architectural-design' );
	}

	/** Test architectural design advertises mounted health consolidate.
	 */
	public function test_architectural_design_advertises_mounted_health_consolidate() {
		$server  = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );
		$mounted = $server->effective_mounted_surfaces();

		$this->assertCount( 1, $mounted );
		$this->assertSame( 'health', $mounted[0]['source_toolkit_slug'] );
		$this->assertSame( 'health-records-consolidate', $mounted[0]['page_slug'] );
		$this->assertTrue( $mounted[0]['read_only'] );
	}

	/** Test mounted resources appear under mounted namespace.
	 */
	public function test_mounted_resources_appear_under_mounted_namespace() {
		$server    = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );
		$resources = $server->get_resources();

		$mounted_uris = array_filter(
			wp_list_pluck( $resources, 'uri' ),
			static function ( $u ) {
				return false !== strpos( $u, '/_mounted/' );
			}
		);

		$this->assertNotEmpty( $mounted_uris );
		foreach ( $mounted_uris as $uri ) {
			$this->assertStringContainsString( 'nvoos://architectural-design/_mounted/health/', $uri );
		}
	}

	/** Test mounted prompts carry read only metadata.
	 */
	public function test_mounted_prompts_carry_read_only_metadata() {
		$server  = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );
		$prompts = $server->get_prompts();

		$mounted = array_filter(
			$prompts,
			static function ( $p ) {
				return 0 === strpos( $p['name'], '_mounted/' );
			}
		);

		$this->assertNotEmpty( $mounted );
		foreach ( $mounted as $p ) {
			$this->assertTrue( ! empty( $p['metadata']['read_only'] ) );
			$this->assertSame( 'health', $p['metadata']['source'] );
		}
	}

	/** Test disabling source server hides mount in consumer.
	 */
	public function test_disabling_source_server_hides_mount_in_consumer() {
		$health = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'health' );
		$arch   = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );

		// Sanity: mount visible by default.
		$this->assertCount( 1, $arch->effective_mounted_surfaces() );

		// Disable the SOURCE server entirely.
		$health->update_configuration( array( 'enabled' => false ) );

		// The mount must disappear from the consumer's effective view.
		$this->assertCount( 0, $arch->effective_mounted_surfaces() );

		// Reset.
		$health->update_configuration( array( 'enabled' => true ) );
	}

	/** Test disabling source surface hides mount in consumer.
	 */
	public function test_disabling_source_surface_hides_mount_in_consumer() {
		$health = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'health' );
		$arch   = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );

		// Disable the underlying native surface on the source.
		$health->update_configuration(
			array(
				'enabled'           => true,
				'disabled_surfaces' => array( 'health-records-consolidate' ),
			)
		);

		// Consumer mount must disappear.
		$this->assertCount( 0, $arch->effective_mounted_surfaces() );

		// Reset.
		$health->update_configuration( array( 'enabled' => true ) );
	}

	/** Test consumer can disable its own mount without affecting source.
	 */
	public function test_consumer_can_disable_its_own_mount_without_affecting_source() {
		$health = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'health' );
		$arch   = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );

		$arch->update_configuration(
			array(
				'enabled'         => true,
				'disabled_mounts' => array( 'health::health-records-consolidate' ),
			)
		);

		// Consumer's mount disappears.
		$this->assertCount( 0, $arch->effective_mounted_surfaces() );

		// Source's native surface unaffected.
		$health_native = $health->effective_ingestion_surfaces();
		$this->assertCount( 2, $health_native );

		// Reset.
		$arch->update_configuration( array( 'enabled' => true ) );
	}

	/** Test assistant binding stays with source toolkit.
	 */
	public function test_assistant_binding_stays_with_source_toolkit() {
		$arch = WP_MCP_AI_Toolkit_Server_Registry::get_instance()->get( 'architectural-design' );

		foreach ( $arch->mounted_surfaces() as $surface ) {
			// The mount declaration tags the source — assistant binding belongs there.
			$this->assertArrayHasKey( 'source_toolkit_slug', $surface );
			$this->assertSame( 'health', $surface['source_toolkit_slug'] );
			$this->assertTrue( ! empty( $surface['read_only'] ) );
		}
	}
}
