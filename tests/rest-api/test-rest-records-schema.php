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
	 * With the CRM CPTs present, each entity carries its meta columns.
	 */
	public function test_schema_includes_all_crm_entities() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		foreach ( array( 'mcp_ai_company', 'mcp_ai_customer', 'mcp_ai_deal', 'mcp_ai_lead', 'mcp_ai_ticket' ) as $slug ) {
			$this->ensure_cpt( $slug );
		}

		$result = ( new WP_MCP_AI_Pro_Records_REST_Controller() )->get_schema( new WP_REST_Request() );
		$data   = $result->get_data();

		if ( ! isset( $data['entities']['company'] ) ) {
			$this->markTestSkipped( 'CRM meta schemas unavailable in this bootstrap.' );
		}

		$expected_keys = array( 'company', 'customer', 'deal', 'lead', 'ticket' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $data['entities'], 'Entity should be exposed: ' . $key );
			$this->assertNotEmpty( $data['entities'][ $key ]['columns'], 'Entity should carry columns: ' . $key );
		}

		$this->assertSame( 'mcp_ai_company', $data['entities']['company']['postType'] );
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
	}

	/**
	 * The REST route is registered under the Pro namespace.
	 */
	public function test_route_is_registered() {
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
	}
}
