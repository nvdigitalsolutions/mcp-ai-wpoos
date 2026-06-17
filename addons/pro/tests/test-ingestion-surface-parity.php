<?php
/**
 * Test_Ingestion_Surface_Parity
 *
 * Asserts the four ingestion-surface shapes — R&A only, C&A only, dual-surface,
 * and multi-page R&A — each produce a valid descriptor.
 *
 * Fixtures:
 *   - CRM      → multi-page R&A (4 pages, 0 C&A)
 *   - Health   → R&A + C&A on shared CPT
 *   - Arch     → multi-page R&A (3 pages) + foreign mount
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

/** Summary.
 *
 * @group toolkit-mcp-servers
 */
class Test_Ingestion_Surface_Parity extends WP_UnitTestCase {

	/** Test crm has four research pages and no consolidate.
	 */
	public function test_crm_has_four_research_pages_and_no_consolidate() {
		$server   = new WP_MCP_AI_CRM_MCP_Server();
		$surfaces = $server->ingestion_surfaces();

		$this->assertCount( 4, $surfaces );
		foreach ( $surfaces as $s ) {
			$this->assertSame( 'research_add', $s['type'] );
		}

		$page_slugs = wp_list_pluck( $surfaces, 'page_slug' );
		$this->assertEqualSets(
			array( 'company-research', 'post-research', 'page-research', 'place-research' ),
			$page_slugs
		);
	}

	/** Test healthcare has research and consolidate on same cpt.
	 */
	public function test_healthcare_has_research_and_consolidate_on_same_cpt() {
		$server   = new WP_MCP_AI_Healthcare_MCP_Server();
		$surfaces = $server->ingestion_surfaces();

		$this->assertCount( 2, $surfaces );

		$by_type = array();
		foreach ( $surfaces as $s ) {
			$by_type[ $s['type'] ] = $s;
		}

		$this->assertArrayHasKey( 'research_add', $by_type );
		$this->assertArrayHasKey( 'consolidate_add', $by_type );
		$this->assertSame( 'mcp_ai_member', $by_type['research_add']['entity_type'] );
		$this->assertSame( 'mcp_ai_member', $by_type['consolidate_add']['entity_type'] );
	}

	/** Test architectural design has three research pages.
	 */
	public function test_architectural_design_has_three_research_pages() {
		$server   = new WP_MCP_AI_Architectural_Design_MCP_Server();
		$surfaces = $server->ingestion_surfaces();

		$this->assertCount( 3, $surfaces );
		foreach ( $surfaces as $s ) {
			$this->assertSame( 'research_add', $s['type'] );
		}
	}

	/** Test prompts namespace per surface for crm.
	 */
	public function test_prompts_namespace_per_surface_for_crm() {
		// Reset config so allowlists don't filter anything out.
		$server = new WP_MCP_AI_CRM_MCP_Server();
		$server->update_configuration( array( 'enabled' => true ) );

		$prompts = $server->get_prompts();
		$names   = wp_list_pluck( $prompts, 'name' );

		// Four R&A prompts in 'crm.research_add.*' namespace.
		$this->assertCount( 4, $names );
		foreach ( $names as $n ) {
			$this->assertStringStartsWith( 'crm.research_add.', $n );
		}
	}

	/** Test prompts for healthcare distinguish research and consolidate.
	 */
	public function test_prompts_for_healthcare_distinguish_research_and_consolidate() {
		$server = new WP_MCP_AI_Healthcare_MCP_Server();
		$server->update_configuration( array( 'enabled' => true ) );

		$names = wp_list_pluck( $server->get_prompts(), 'name' );
		$this->assertContains( 'health.research_add.member', $names );
		$this->assertContains( 'health.consolidate_add.member', $names );
	}

	/** Test disabling a native surface hides only that prompt.
	 */
	public function test_disabling_a_native_surface_hides_only_that_prompt() {
		$server = new WP_MCP_AI_CRM_MCP_Server();
		$server->update_configuration(
			array(
				'enabled'           => true,
				'disabled_surfaces' => array( 'place-research' ),
			)
		);

		$prompt_names = wp_list_pluck( $server->get_prompts(), 'name' );
		$this->assertNotContains( 'crm.research_add.place', $prompt_names );
		$this->assertContains( 'crm.research_add.company', $prompt_names );

		// Reset.
		$server->update_configuration( array( 'enabled' => true ) );
	}

	/** Test resources use nvoos uri scheme.
	 */
	public function test_resources_use_nvoos_uri_scheme() {
		$server = new WP_MCP_AI_CRM_MCP_Server();
		$server->update_configuration( array( 'enabled' => true ) );
		$resources = $server->get_resources();

		$this->assertNotEmpty( $resources );
		foreach ( $resources as $r ) {
			$this->assertStringStartsWith( 'nvoos://crm/', $r['uri'] );
		}
	}
}
