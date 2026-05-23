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

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Toolkit_Server_Contract extends WP_UnitTestCase {

	/**
	 * Provider for all servers (Phase 1 pilots + Phase 2 Tier-1 + Phase 6 Tier-2 promotions).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function pilot_servers() {
		return array(
			// Phase 1 pilots.
			'crm'                     => array( 'WP_MCP_AI_CRM_MCP_Server', 'crm' ),
			'health'                  => array( 'WP_MCP_AI_Healthcare_MCP_Server', 'health' ),
			'architectural-design'    => array( 'WP_MCP_AI_Architectural_Design_MCP_Server', 'architectural-design' ),
			// Phase 2 Tier-1 promotions (alphabetical).
			'ai-tool-builder'         => array( 'WP_MCP_AI_AI_Tool_Builder_MCP_Server', 'ai-tool-builder' ),
			'calendar-booking'        => array( 'WP_MCP_AI_Calendar_Booking_MCP_Server', 'calendar-booking' ),
			'cre-debt'                => array( 'WP_MCP_AI_CRE_Debt_MCP_Server', 'cre-debt' ),
			'dj-management'           => array( 'WP_MCP_AI_DJ_Management_MCP_Server', 'dj-management' ),
			'document-generation'     => array( 'WP_MCP_AI_Document_Generation_MCP_Server', 'document-generation' ),
			'eca'                     => array( 'WP_MCP_AI_ECA_Management_MCP_Server', 'eca' ),
			'ecommerce'               => array( 'WP_MCP_AI_Ecommerce_MCP_Server', 'ecommerce' ),
			'financial-planner'       => array( 'WP_MCP_AI_Financial_Planner_MCP_Server', 'financial-planner' ),
			'image-production'        => array( 'WP_MCP_AI_Image_Production_MCP_Server', 'image-production' ),
			'law-firm'                => array( 'WP_MCP_AI_Law_Firm_MCP_Server', 'law-firm' ),
			'media'                   => array( 'WP_MCP_AI_Media_Toolkit_MCP_Server', 'media' ),
			'multilingual'            => array( 'WP_MCP_AI_Multilingual_MCP_Server', 'multilingual' ),
			'project-management'      => array( 'WP_MCP_AI_Project_Management_MCP_Server', 'project-management' ),
			'regulatory-registration' => array( 'WP_MCP_AI_Regulatory_Registration_MCP_Server', 'regulatory-registration' ),
			'social-media'            => array( 'WP_MCP_AI_Social_Media_MCP_Server', 'social-media' ),
			'video-production'        => array( 'WP_MCP_AI_Video_Production_MCP_Server', 'video-production' ),
			// Phase 6 Tier-2 promotions (alphabetical).
			'analytics'               => array( 'WP_MCP_AI_Analytics_MCP_Server', 'analytics' ),
			'architect-agent'         => array( 'WP_MCP_AI_Architect_Agent_MCP_Server', 'architect-agent' ),
			'chat-channels'           => array( 'WP_MCP_AI_Chat_Channels_MCP_Server', 'chat-channels' ),
			'extended-cognition'      => array( 'WP_MCP_AI_Extended_Cognition_MCP_Server', 'extended-cognition' ),
			'healthcare-imaging'      => array( 'WP_MCP_AI_Healthcare_Imaging_MCP_Server', 'healthcare-imaging' ),
			'healthcare-wellness'     => array( 'WP_MCP_AI_Healthcare_Wellness_MCP_Server', 'healthcare-wellness' ),
			'site-creator'            => array( 'WP_MCP_AI_Site_Creator_MCP_Server', 'site-creator' ),
		);
	}

	/** Test classes exist.
	 */
	public function test_classes_exist() {
		foreach ( $this->pilot_servers() as $row ) {
			$this->assertTrue( class_exists( $row[0] ), $row[0] . ' must exist.' );
		}
	}

	/** Test each server implements interface.
	 */
	public function test_each_server_implements_interface() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertInstanceOf( 'WP_MCP_AI_Toolkit_Server_Interface', $server );
		}
	}

	/** Test slug matches expected.
	 */
	public function test_slug_matches_expected() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertSame( $row[1], $server->get_slug() );
		}
	}

	/** Test descriptor is well formed.
	 */
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

	/** Test each native surface has required keys.
	 */
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

	/** Test default enabled state is true.
	 */
	public function test_default_enabled_state_is_true() {
		foreach ( $this->pilot_servers() as $row ) {
			$server = new $row[0]();
			$this->assertTrue( $server->is_enabled(), $row[1] . ' must default to enabled.' );
		}
	}

	/** Test disabling propagates to descriptor.
	 */
	public function test_disabling_propagates_to_descriptor() {
		$server = new WP_MCP_AI_CRM_MCP_Server();
		$server->update_configuration( array( 'enabled' => false ) );

		$this->assertFalse( $server->is_enabled() );
		$descriptor = $server->get_descriptor();
		$this->assertFalse( $descriptor['enabled'] );

		// Cleanup.
		$server->update_configuration( array( 'enabled' => true ) );
	}

	/** Test tools allowlist filters effective tools.
	 */
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
		$server->update_configuration(
			array(
				'enabled'         => true,
				'tools_allowlist' => array(),
			)
		);
		$this->assertSame( $candidates, $server->effective_tool_slugs() );
	}

	/** Test registry holds pilot servers after bootstrap.
	 */
	public function test_registry_holds_pilot_servers_after_bootstrap() {
		WP_MCP_AI_Toolkit_Server_Registry::reset_instance();
		$registry = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		// Manually register all Tier-1 servers, since `init` may have already.
		// fired in this test process.
		foreach ( $this->pilot_servers() as $row ) {
			$registry->register( new $row[0]() );
		}

		foreach ( $this->pilot_servers() as $row ) {
			$this->assertNotNull( $registry->get( $row[1] ), 'Registry missing: ' . $row[1] );
		}
	}

	/** Test every server has at least one candidate tool or surface.
	 */
	public function test_every_server_has_at_least_one_candidate_tool_or_surface() {
		// A Tier-1 server must justify its existence — either by exposing a tool.
		// candidate or by owning at least one ingestion surface.
		foreach ( $this->pilot_servers() as $row ) {
			$server      = new $row[0]();
			$has_tool    = ! empty( $server->candidate_tool_slugs() );
			$has_surface = ! empty( $server->ingestion_surfaces() );
			$this->assertTrue(
				$has_tool || $has_surface,
				$row[1] . ' must declare at least one candidate tool or ingestion surface.'
			);
		}
	}
}
