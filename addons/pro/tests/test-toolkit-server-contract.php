<?php
/**
 * Test_Toolkit_Server_Contract
 *
 * Generic contract assertions every Tier-1 toolkit MCP server must satisfy.
 * Runs against the three Phase 1 pilot servers.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/**
 * @group toolkit-mcp-servers
 */
class Test_Toolkit_Server_Contract extends WP_UnitTestCase {

	/**
	 * Provider for all pilot servers.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function pilot_servers() {
		return array(
			'crm'                  => array( 'WP_MCP_AI_CRM_MCP_Server', 'crm' ),
			'health'               => array( 'WP_MCP_AI_Healthcare_MCP_Server', 'health' ),
			'architectural-design' => array( 'WP_MCP_AI_Architectural_Design_MCP_Server', 'architectural-design' ),
		);
	}

	public function test_classes_exist() {
		foreach ( $this->pilot_servers() as $row ) {
			$this->assertTrue( class_exists( $row[0] ), $row[0] . ' must exist.' );
		}
	}

	public function test_each_server_implements_interface() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Server_Interface', $server );
		}
	}

	public function test_slug_matches_expected() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertSame( $row[1], $server->get_slug() );
		}
	}

	public function test_descriptor_is_well_formed() {
		foreach ( $this->pilot_servers() as $row ) {
			$server     = new $row[0]();
			$descriptor = $server->get_descriptor();

			$this->assertIsArray( $descriptor );
			foreach ( array( 'slug', 'name', 'description', 'version', 'enabled', 'protocolVersion', 'capabilities', 'native_surfaces', 'mounted_surfaces', 'tool_count', 'endpoints' ) as $key ) {
				$this->assertArrayHasKey( $key, $descriptor, $row[1] . ' descriptor missing key: ' . $key );
			}

			$this->assertSame( $row[1], $descriptor['slug'] );
			$this->assertIsArray( $descriptor['native_surfaces'] );
			$this->assertIsArray( $descriptor['mounted_surfaces'] );
			$this->assertSame( '2025-06-18', $descriptor['protocolVersion'] );
		}
	}

	public function test_each_native_surface_has_required_keys() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			foreach ( $server->ingestion_surfaces() as $surface ) {
				$this->assertIsArray( $surface );
				foreach ( array( 'type', 'page_slug', 'entity_type', 'class_ref', 'label' ) as $key ) {
					$this->assertArrayHasKey( $key, $surface, $row[1] . ' surface missing: ' . $key );
				}
				$this->assertContains( $surface['type'], array( 'research_add', 'consolidate_add' ) );
			}
		}
	}

	public function test_default_enabled_state_is_true() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertTrue( $server->is_enabled(), $row[1] . ' must default to enabled.' );
		}
	}

	public function test_disabling_propagates_to_descriptor() {
		$server = new WP_MCP_AI_CRM_MCP_Server();
		$server->update_configuration( array( 'enabled' => false ) );

		$this->assertFalse( $server->is_enabled() );
		$descriptor = $server->get_descriptor();
		$this->assertFalse( $descriptor['enabled'] );

		// Cleanup.
		$server->update_configuration( array( 'enabled' => true ) );
	}

	public function test_tools_allowlist_filters_effective_tools() {
		$server     = new WP_MCP_AI_CRM_MCP_Server();
		$candidates = $server->candidate_tool_slugs();
		$this->assertNotEmpty( $candidates );

		// Restrict to first slug only.
		$server->update_configuration(
			array(
				'enabled'         => true,
				'tools_allowlist' => array( $candidates[0] ),
			)
		);

		$effective = $server->effective_tool_slugs();
		$this->assertSame( array( $candidates[0] ), $effective );

		// Reset.
		$server->update_configuration( array( 'enabled' => true, 'tools_allowlist' => array() ) );
		$this->assertSame( $candidates, $server->effective_tool_slugs() );
	}

	public function test_registry_holds_pilot_servers_after_bootstrap() {
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		// Manually register, since `init` may have already fired in this test process.
		$registry->register( new WP_MCP_AI_CRM_MCP_Server() );
		$registry->register( new WP_MCP_AI_Healthcare_MCP_Server() );
		$registry->register( new WP_MCP_AI_Architectural_Design_MCP_Server() );

		foreach ( array( 'crm', 'health', 'architectural-design' ) as $slug ) {
			$this->assertNotNull( $registry->get( $slug ), 'Registry missing: ' . $slug );
		}
	}
}
