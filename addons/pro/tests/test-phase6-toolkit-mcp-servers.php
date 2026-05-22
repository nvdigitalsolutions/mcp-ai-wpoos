<?php
/**
 * Test_Phase6_Toolkit_MCP_Servers
 *
 * Phase 6 — Tier-2 server classes + /.well-known/mcp discovery endpoint.
 *
 * Covers:
 *   1. All 7 Tier-2 server classes exist.
 *   2. Each implements WP_MCP_AI_Toolkit_Server_Interface.
 *   3. Each returns the expected slug.
 *   4. Each returns a non-empty name and description.
 *   5. Each returns a non-empty candidate_tool_slugs array.
 *   6. Each returns an empty ingestion_surfaces array (tools-only).
 *   7. Well-known controller: build_discovery_document() returns correct structure.
 *   8. Well-known controller: disabled servers are excluded from the document.
 *   9. Well-known controller: wp_mcp_ai_well_known_mcp_document filter is applied.
 *  10. Well-known controller: constructor registers expected hooks.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Phase6_Toolkit_MCP_Servers extends WP_UnitTestCase {

	/**
	 * Data provider for all 7 Tier-2 servers.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function tier2_servers() {
		return array(
			'analytics'           => array( 'WP_MCP_AI_Analytics_MCP_Server', 'analytics' ),
			'architect-agent'     => array( 'WP_MCP_AI_Architect_Agent_MCP_Server', 'architect-agent' ),
			'chat-channels'       => array( 'WP_MCP_AI_Chat_Channels_MCP_Server', 'chat-channels' ),
			'extended-cognition'  => array( 'WP_MCP_AI_Extended_Cognition_MCP_Server', 'extended-cognition' ),
			'healthcare-imaging'  => array( 'WP_MCP_AI_Healthcare_Imaging_MCP_Server', 'healthcare-imaging' ),
			'healthcare-wellness' => array( 'WP_MCP_AI_Healthcare_Wellness_MCP_Server', 'healthcare-wellness' ),
			'site-creator'        => array( 'WP_MCP_AI_Site_Creator_MCP_Server', 'site-creator' ),
		);
	}

	/**
	 * Tear down — reset registry singleton.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1. Classes exist.
	// -----------------------------------------------------------------------

	/**
	 * Test that all Phase 6 Tier-2 server classes are loadable.
	 */
	public function test_tier2_classes_exist() {
		foreach ( $this->tier2_servers() as $key => $row ) {
			$this->assertTrue(
				class_exists( $row[0] ),
				$row[0] . ' must exist.'
			);
		}
	}

	// -----------------------------------------------------------------------
	// 2. Interface compliance.
	// -----------------------------------------------------------------------

	/**
	 * Test that all Tier-2 servers implement WP_MCP_AI_Toolkit_Server_Interface.
	 */
	public function test_tier2_implements_interface() {
		foreach ( $this->tier2_servers() as $row ) {
			$server = new $row[0]();
			$this->assertInstanceOf(
				'WP_MCP_AI_Toolkit_Server_Interface',
				$server,
				$row[0] . ' must implement WP_MCP_AI_Toolkit_Server_Interface'
			);
		}
	}

	// -----------------------------------------------------------------------
	// 3. Slugs.
	// -----------------------------------------------------------------------

	/**
	 * Test that get_slug() returns the expected kebab-case slug.
	 */
	public function test_tier2_slug_matches_expected() {
		foreach ( $this->tier2_servers() as $row ) {
			$server = new $row[0]();
			$this->assertSame( $row[1], $server->get_slug() );
		}
	}

	// -----------------------------------------------------------------------
	// 4. Non-empty name and description.
	// -----------------------------------------------------------------------

	/**
	 * Test that name and description are non-empty strings.
	 */
	public function test_tier2_name_and_description_are_non_empty() {
		foreach ( $this->tier2_servers() as $row ) {
			$server = new $row[0]();
			$this->assertNotEmpty( $server->get_name(), $row[0] . '::get_name() must not be empty' );
			$this->assertNotEmpty( $server->get_description(), $row[0] . '::get_description() must not be empty' );
		}
	}

	// -----------------------------------------------------------------------
	// 5. Non-empty candidate tool slugs.
	// -----------------------------------------------------------------------

	/**
	 * Test that candidate_tool_slugs() returns a non-empty array of strings.
	 */
	public function test_tier2_candidate_tool_slugs_non_empty() {
		foreach ( $this->tier2_servers() as $row ) {
			$server = new $row[0]();
			$slugs  = $server->candidate_tool_slugs();
			$this->assertIsArray( $slugs, $row[0] . '::candidate_tool_slugs() must return an array' );
			$this->assertNotEmpty( $slugs, $row[0] . '::candidate_tool_slugs() must not be empty' );
			foreach ( $slugs as $slug ) {
				$this->assertIsString( $slug, $row[0] . ' tool slug must be a string' );
			}
		}
	}

	// -----------------------------------------------------------------------
	// 6. Tools-only — empty ingestion surfaces.
	// -----------------------------------------------------------------------

	/**
	 * Test that ingestion_surfaces() returns an empty array (Tier-2 are tools-only).
	 */
	public function test_tier2_ingestion_surfaces_are_empty() {
		foreach ( $this->tier2_servers() as $row ) {
			$server = new $row[0]();
			$this->assertSame(
				array(),
				$server->ingestion_surfaces(),
				$row[0] . '::ingestion_surfaces() must return [] (tools-only server)'
			);
		}
	}

	// -----------------------------------------------------------------------
	// 7. Well-known discovery document structure.
	// -----------------------------------------------------------------------

	/**
	 * Test that build_discovery_document() returns the expected shape when
	 * at least one server is registered.
	 */
	public function test_well_known_discovery_document_structure() {
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$server   = new WP_MCP_AI_Analytics_MCP_Server();
		$registry->register( $server );

		$controller = new WP_MCP_AI_Pro_Well_Known_MCP();
		$document   = $controller->build_discovery_document();

		$this->assertIsArray( $document );
		$this->assertArrayHasKey( 'mcpServers', $document );
		$this->assertIsArray( $document['mcpServers'] );
		$this->assertCount( 1, $document['mcpServers'] );

		$entry = $document['mcpServers'][0];
		$this->assertArrayHasKey( 'slug', $entry );
		$this->assertArrayHasKey( 'name', $entry );
		$this->assertArrayHasKey( 'description', $entry );
		$this->assertArrayHasKey( 'version', $entry );
		$this->assertArrayHasKey( 'endpoint', $entry );

		$this->assertSame( 'analytics', $entry['slug'] );
		$this->assertStringContainsString( 'mcp-ai-pro/v1/mcp/analytics', $entry['endpoint'] );
	}

	// -----------------------------------------------------------------------
	// 8. Disabled servers excluded from discovery document.
	// -----------------------------------------------------------------------

	/**
	 * Test that disabled servers (is_enabled() === false) are not listed.
	 */
	public function test_well_known_excludes_disabled_servers() {
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$server   = new WP_MCP_AI_Analytics_MCP_Server();

		// Disable the server via its config option.
		update_option( 'wp_mcp_ai_toolkit_mcp_server_analytics', array( 'enabled' => false ) );
		$registry->register( $server );

		$controller = new WP_MCP_AI_Pro_Well_Known_MCP();
		$document   = $controller->build_discovery_document();

		$this->assertSame( array(), $document['mcpServers'], 'Disabled server must not appear in discovery document' );

		delete_option( 'wp_mcp_ai_toolkit_mcp_server_analytics' );
	}

	// -----------------------------------------------------------------------
	// 9. wp_mcp_ai_well_known_mcp_document filter.
	// -----------------------------------------------------------------------

	/**
	 * Test that the wp_mcp_ai_well_known_mcp_document filter is applied.
	 */
	public function test_well_known_document_filter_applied() {
		$controller = new WP_MCP_AI_Pro_Well_Known_MCP();

		add_filter(
			'wp_mcp_ai_well_known_mcp_document',
			static function ( $doc ) {
				$doc['_test_flag'] = true;
				return $doc;
			}
		);

		$document = $controller->build_discovery_document();
		$this->assertTrue( $document['_test_flag'], 'wp_mcp_ai_well_known_mcp_document filter must be applied' );

		// Cleanup.
		remove_all_filters( 'wp_mcp_ai_well_known_mcp_document' );
	}

	// -----------------------------------------------------------------------
	// 10. Constructor registers expected hooks.
	// -----------------------------------------------------------------------

	/**
	 * Test that the constructor registers init, query_vars, and template_redirect hooks.
	 */
	public function test_well_known_constructor_registers_hooks() {
		$controller = new WP_MCP_AI_Pro_Well_Known_MCP();

		$this->assertGreaterThan(
			0,
			has_action( 'init', array( $controller, 'add_rewrite_rules' ) ),
			'init hook for add_rewrite_rules must be registered'
		);
		$this->assertGreaterThan(
			0,
			has_filter( 'query_vars', array( $controller, 'add_query_vars' ) ),
			'query_vars filter for add_query_vars must be registered'
		);
		$this->assertGreaterThan(
			0,
			has_action( 'template_redirect', array( $controller, 'handle_request' ) ),
			'template_redirect hook for handle_request must be registered'
		);
	}
}
