<?php
/**
 * Tests the Pro Records REST bridge (2.4.0, Proposal 084 Phase 4, W11):
 * GET /mcp-ai-pro/v1/records/schema + meta-in-REST registration for the
 * CRM command center's generic Records page.
 *
 * Covers: manage_options gating, the schema envelope, and the
 * register_post_meta exposure of the company schema keys. Skipped when the
 * Pro addon classes are not loaded.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Records_REST_Controller_Test extends WP_UnitTestCase {

	/**
	 * Slugs of the scratch CPTs this test may register (for cleanup).
	 *
	 * @var string[]
	 */
	private $scratch_cpts = array();

	/**
	 * Skip when the Pro addon classes are absent.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_REST_Controller' ) ) {
			$this->markTestSkipped( 'Pro addon not loaded.' );
		}
	}

	/**
	 * Remove the scratch CPTs this test created.
	 */
	public function tearDown(): void {
		foreach ( $this->scratch_cpts as $slug ) {
			if ( post_type_exists( $slug ) ) {
				unregister_post_type( $slug );
			}
		}
		$this->scratch_cpts = array();
		parent::tearDown();
	}

	/**
	 * Register a scratch CPT when the toolkit did not (the CRM CPTs are
	 * settings-gated — enable_crm_toolkit).
	 *
	 * @param string $slug Post type slug.
	 */
	private function ensure_cpt( $slug ) {
		if ( post_type_exists( $slug ) ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- The scratch CPT slug is a parameter; each call site passes a literal.
		register_post_type(
			$slug,
			array(
				'labels'       => array( 'name' => ucfirst( $slug ) ),
				'public'       => false,
				'show_in_rest' => true,
				'supports'     => array( 'title' ),
			)
		);
		$this->scratch_cpts[] = $slug;
	}

	/**
	 * Non-admins are refused.
	 */
	public function test_non_admin_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Admins get the entities envelope.
	 */
	public function test_schema_returns_entities_envelope() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->get_schema( new WP_REST_Request() );

		$this->assertNotWPError( $result );
		$data = $result->get_data();
		$this->assertArrayHasKey( 'entities', $data );
		$this->assertIsArray( $data['entities'] );
	}

	/**
	 * With the entity CPTs present, each carries its meta columns and group.
	 */
	public function test_schema_includes_all_record_entities() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		foreach ( array( 'mcp_ai_company', 'mcp_ai_customer', 'mcp_ai_deal', 'mcp_ai_lead', 'mcp_ai_ticket', 'mcp_ai_project', 'mcp_ai_task', 'mcp_ai_event', 'mcp_ai_profession' ) as $slug ) {
			$this->ensure_cpt( $slug );
		}

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		if ( ! isset( $data['entities']['company'] ) ) {
			$this->markTestSkipped( 'Record meta schemas unavailable in this bootstrap.' );
		}

		$expected_keys = array( 'company', 'customer', 'deal', 'lead', 'ticket', 'project', 'task', 'event', 'profession' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $data['entities'], 'Entity should be exposed: ' . $key );
			$this->assertNotEmpty( $data['entities'][ $key ]['columns'], 'Entity should carry columns: ' . $key );
		}

		$this->assertSame( 'mcp_ai_company', $data['entities']['company']['postType'] );

		// Groups partition the Records page per command center.
		$this->assertSame( 'crm', $data['entities']['company']['group'] );
		$this->assertSame( 'pm', $data['entities']['project']['group'] );
		$this->assertSame( 'professions', $data['entities']['profession']['group'] );
	}

	/**
	 * The schema meta keys are exposed through the core wp/v2 REST.
	 */
	public function test_company_meta_registered_in_rest() {
		$this->ensure_cpt( 'mcp_ai_company' );
		$this->ensure_cpt( 'mcp_ai_deal' );

		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			$this->markTestSkipped( 'CPT meta schema registry unavailable in this bootstrap.' );
		}

		( new WP_MCP_AI_Pro_Records_REST_Controller() )->register_meta_in_rest();

		$has_any = false;
		foreach ( WP_MCP_AI_Pro_CPT_Meta_Schema::get( 'mcp_ai_company' ) as $field ) {
			if ( ! empty( $field['meta_key'] ) ) {
				$has_any = true;
				$this->assertTrue(
					registered_meta_key_exists( 'post', $field['meta_key'], 'mcp_ai_company' ),
					'Meta key should be REST-exposed: ' . $field['meta_key']
				);
			}
		}
		$this->assertTrue( $has_any, 'The company schema should declare at least one meta field.' );

		// One spot-check from the W11b entities (deal).
		$this->assertTrue(
			registered_meta_key_exists( 'post', '_deal_stage', 'mcp_ai_deal' ),
			'Deal meta should be REST-exposed.'
		);

		// The profession CPT owns its meta registration (W12 flipped it to
		// REST exposure) — the records registrar must not re-register it.
		if ( post_type_exists( 'mcp_ai_profession' ) ) {
			$this->assertTrue(
				registered_meta_key_exists( 'post', '_wp_mcp_ai_profession_category', 'mcp_ai_profession' ),
				'Profession meta should be REST-exposed.'
			);
		}
	}

	/**
	 * The REST routes are registered under the Pro namespace.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );

		if ( ! isset( $routes['/mcp-ai-pro/v1/records/schema'] ) ) {
			// In some matrices rest_api_init fired before the Pro bootstrap
			// hooked this controller — register on the next fire (fresh server).
			add_action(
				'rest_api_init',
				function () {
					( new WP_MCP_AI_Pro_Records_REST_Controller() )->register_routes();
				}
			);
			$GLOBALS['wp_rest_server'] = null;
			rest_get_server();
			$routes = rest_get_server()->get_routes( 'mcp-ai-pro/v1' );
		}

		$this->assertArrayHasKey( '/mcp-ai-pro/v1/records/schema', $routes );
		$this->assertArrayHasKey( '/mcp-ai-pro/v1/records/(?P<entity>[a-z0-9_-]+)/research', $routes );
	}

	/**
	 * Unknown entities are rejected as readable 400s.
	 */
	public function test_research_unknown_entity_is_400() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->research(
			$this->research_request( 'mystery', 'acme' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'records_unknown_entity', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Empty queries are rejected.
	 */
	public function test_research_missing_query_is_400() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->research(
			$this->research_request( 'company', '   ' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'records_missing_query', $result->get_error_code() );
	}

	/**
	 * Entities whose CPT is absent are refused with 501.
	 */
	public function test_research_unavailable_entity_is_501() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// No scratch CPT — the ticket post type is not registered.
		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->research(
			$this->research_request( 'ticket', 'billing question' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'records_entity_unavailable', $result->get_error_code() );
		$this->assertSame( 501, $result->get_error_data()['status'] );
	}

	/**
	 * The full pipeline surfaces provider errors as readable 400s: a stub
	 * research tool returns a canned report, then the extractor fails at
	 * the provider-setup gate (no API keys in CI) — zero network.
	 */
	public function test_research_surfaces_extraction_errors() {
		$this->ensure_cpt( 'mcp_ai_company' );

		// Deterministic provider gate: no keys configured anywhere.
		update_option( 'wp_mcp_ai_settings', array() );
		delete_option( 'wp_mcp_ai_credentials' );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$original = $registry->get_tool( 'deep_research' );
		if ( ! class_exists( 'WP_MCP_AI_Test_Records_Deep_Research_Stub' ) ) {
			require_once dirname( __DIR__ ) . '/helpers/class-wp-mcp-ai-test-records-deep-research-stub.php';
		}
		$stub = new WP_MCP_AI_Test_Records_Deep_Research_Stub();
		$registry->register_tool( $stub );

		try {
			$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->research(
				$this->research_request( 'company', 'acme ltd' )
			);
		} finally {
			// Restore the shared registry instance untouched.
			if ( $original ) {
				$registry->register_tool( $original );
			} else {
				$registry->unregister_tool( 'deep_research' );
			}
		}

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * Build a research request with the given params.
	 *
	 * @param string $entity Entity key.
	 * @param string $query  Research query.
	 * @return WP_REST_Request
	 */
	private function research_request( $entity, $query ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai-pro/v1/records/' . $entity . '/research' );
		$request->set_param( 'entity', $entity );
		$request->set_param( 'query', $query );
		return $request;
	}

	/**
	 * The extractor's JSON parser keeps only allowed keys.
	 */
	public function test_parse_fields_json() {
		$extractor_file = WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-records-research-extractor.php';
		if ( file_exists( $extractor_file ) ) {
			require_once $extractor_file;
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_Records_Research_Extractor' ) ) {
			$this->markTestSkipped( 'Research extractor unavailable in this bootstrap.' );
		}

		$extractor = new WP_MCP_AI_Pro_Records_Research_Extractor();
		$allowed   = array( 'title', '_company_industry', '_company_status' );

		// Fenced JSON.
		$fields = $extractor->parse_fields_json( "```json\n{\"title\":\"Acme\",\"_company_industry\":\"manufacturing\",\"_evil\":\"x\"}\n```", $allowed );
		$this->assertSame( 'Acme', $fields['title'] );
		$this->assertSame( 'manufacturing', $fields['_company_industry'] );
		$this->assertArrayNotHasKey( '_evil', $fields );

		// JSON embedded in prose.
		$fields = $extractor->parse_fields_json( 'Here you go: {"title":"Beta"} thanks!', $allowed );
		$this->assertSame( 'Beta', $fields['title'] );

		// Garbage → readable error.
		$result = $extractor->parse_fields_json( 'no json here', $allowed );
		$this->assertWPError( $result );
		$this->assertSame( 'records_invalid_extraction', $result->get_error_code() );
	}
}
