<?php
/**
 * Tests the GET /mcp-ai/v1/tools response envelope.
 *
 * The Pro SPA tools page consumes this route directly. The envelope is a
 * top-level `{ tools: [...], total }` object where every entry carries the
 * MCP-facing fields (name/description/inputSchema) plus the catalogue fields
 * (slug/required_capability) the page renders.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_REST_Tools_List_Envelope_Test extends WP_UnitTestCase {

	/**
	 * Set up an administrator so assistant-access validation passes, and
	 * clear the tools REST cache so scope tests never read stale entries.
	 */
	public function setUp(): void {
		parent::setUp();
		WP_MCP_AI_REST_Cache::clear_all_caches();

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Dispatch GET /tools and return the decoded response payload.
	 *
	 * @param array $params Query params to set on the request.
	 * @return array Response data.
	 */
	private function fetch_tools_list( array $params = array() ): array {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $rest->handle_tools_list( $request );

		$this->assertNotWPError( $response );

		return $response->get_data();
	}

	/**
	 * The tools response is a top-level envelope with a matching total.
	 */
	public function test_tools_list_envelope_is_top_level_with_total() {
		$data = $this->fetch_tools_list();

		$this->assertArrayHasKey( 'tools', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertNotEmpty( $data['tools'], 'The tools list should not be empty.' );
		$this->assertSame( count( $data['tools'] ), $data['total'] );
	}

	/**
	 * Every entry carries the MCP fields plus the SPA catalogue fields.
	 */
	public function test_tools_list_entries_carry_catalogue_fields() {
		$data  = $this->fetch_tools_list();
		$first = $data['tools'][0];

		foreach ( array( 'name', 'slug', 'description', 'required_capability', 'flags', 'inputSchema' ) as $key ) {
			$this->assertArrayHasKey( $key, $first, "Tool entry must include the {$key} field." );
		}

		$this->assertSame( $first['name'], $first['slug'] );
		$this->assertIsArray( $first['inputSchema'] );
		$this->assertIsArray( $first['flags'] );
	}

	/**
	 * Without an explicit assistant_id, the listing is scoped to the site
	 * default assistant (the MCP tools/list contract); `scope=all` opts out
	 * and returns the full registry — the SPA tool picker relies on this.
	 */
	public function test_scope_all_returns_full_registry_despite_default_assistant() {
		// Configure a default assistant whose tool set is a one-tool subset.
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Scoped Default Assistant',
			)
		);
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOOLS, array( 'get_site_health' ) );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array_merge(
				get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() ),
				array( 'default_assistant' => $assistant_id )
			)
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		// Default behaviour: the unscoped request lists only the default
		// assistant's tools (documents the MCP contract we must not break).
		$scoped = $this->fetch_tools_list();
		$slugs  = wp_list_pluck( $scoped['tools'], 'slug' );
		$this->assertContains( 'get_site_health', $slugs, 'The default assistant scope must still list its own tools.' );
		$this->assertNotContains( 'delete_post', $slugs, 'The unscoped listing must not leak tools outside the default assistant.' );

		// scope=all: the full registry is served regardless of the default.
		$all       = $this->fetch_tools_list( array( 'scope' => 'all' ) );
		$all_slugs = wp_list_pluck( $all['tools'], 'slug' );
		$this->assertContains( 'get_site_health', $all_slugs );
		$this->assertContains( 'delete_post', $all_slugs, 'scope=all must expose tools the default assistant lacks.' );
	}
}
