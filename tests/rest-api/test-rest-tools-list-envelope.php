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
	 * Dispatch GET /tools and return the decoded response payload.
	 *
	 * @return array Response data.
	 */
	private function fetch_tools_list(): array {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $mock_client );

		$request  = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
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

		foreach ( array( 'name', 'slug', 'description', 'required_capability', 'inputSchema' ) as $key ) {
			$this->assertArrayHasKey( $key, $first, "Tool entry must include the {$key} field." );
		}

		$this->assertSame( $first['name'], $first['slug'] );
		$this->assertIsArray( $first['inputSchema'] );
	}
}
