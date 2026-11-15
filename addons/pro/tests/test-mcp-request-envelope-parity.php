<?php
/**
 * Asserts the WordPress adapter sends the shared MCP request shapes.
 *
 * The counterpart of `lib/core/tests/Unit/Domain/Mcp/McpRequestEnvelopeTest.php`.
 * That file pins the shapes; this one proves the adapter actually uses them —
 * a mis-wired delegation would otherwise only surface as a remote 400.
 *
 * @package WP_MCP_AI_Pro
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

declare(strict_types=1);

/**
 * The request-envelope parity test.
 */
class Test_MCP_Request_Envelope_Parity extends WP_UnitTestCase {

	/**
	 * Load the adapter and its recording test double.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Test_Capture_MCP_Client' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-test-capture-mcp-client.php';
		}
	}

	/**
	 * A fresh recording client.
	 */
	private function client(): WP_MCP_AI_Test_Capture_MCP_Client {
		return new WP_MCP_AI_Test_Capture_MCP_Client();
	}

	/**
	 * The tools/list method must send `{}`, not `[]`, and the payload plus ttl are extracted.
	 */
	public function test_tools_list_sends_an_object_and_extracts_the_ttl(): void {
		$client       = $this->client();
		$client->next = array(
			'tools' => array( array( 'name' => 'a' ) ),
			'ttlMs' => 750,
		);

		$tools = $client->list_tools();

		$this->assertSame( 'tools/list', $client->captured[0]['method'] );
		$this->assertSame( '{}', (string) wp_json_encode( $client->captured[0]['params'] ) );
		$this->assertCount( 1, $tools );
		$this->assertSame( 750, $client->get_last_tools_ttl_ms() );
	}

	/**
	 * An empty result degrades to an empty list and a zero ttl.
	 */
	public function test_an_empty_tools_result_degrades_cleanly(): void {
		$client       = $this->client();
		$client->next = array();

		$this->assertSame( array(), $client->list_tools() );
		$this->assertSame( 0, $client->get_last_tools_ttl_ms() );
	}

	/**
	 * A tools/call without arguments must send `arguments: {}`.
	 */
	public function test_tool_call_without_arguments_sends_an_empty_object(): void {
		$client = $this->client();
		$client->call_tool( 'get_weather' );

		$this->assertSame( 'tools/call', $client->captured[0]['method'] );
		$this->assertSame(
			'{"name":"get_weather","arguments":{}}',
			(string) wp_json_encode( $client->captured[0]['params'] )
		);
	}

	/**
	 * The tool name is sanitised at the WordPress boundary, before it is shaped.
	 */
	public function test_tool_call_sanitises_the_name(): void {
		$client = $this->client();
		$client->call_tool( '  get_weather  ', array( 'city' => 'Kingston' ) );

		$params = $client->captured[0]['params'];

		$this->assertSame( 'get_weather', $params['name'] );
		$this->assertSame( array( 'city' => 'Kingston' ), $params['arguments'] );
	}

	/**
	 * The resources/list method must send `{}` and extract the list.
	 */
	public function test_resources_list_sends_an_object(): void {
		$client       = $this->client();
		$client->next = array( 'resources' => array( array( 'uri' => 'ui://a' ) ) );

		$resources = $client->list_resources();

		$this->assertSame( 'resources/list', $client->captured[0]['method'] );
		$this->assertSame( '{}', (string) wp_json_encode( $client->captured[0]['params'] ) );
		$this->assertCount( 1, $resources );

		$empty       = $this->client();
		$empty->next = array();

		$this->assertSame( array(), $empty->list_resources() );
	}

	/**
	 * A resources/read request carries the sanitised uri.
	 */
	public function test_resource_read_carries_the_uri(): void {
		$client = $this->client();
		$client->read_resource( '  ui://server/card  ' );

		$this->assertSame( 'resources/read', $client->captured[0]['method'] );
		$this->assertSame( array( 'uri' => 'ui://server/card' ), $client->captured[0]['params'] );
	}

	/**
	 * The `_meta` envelope must match the shared core builder exactly.
	 */
	public function test_build_request_meta_delegates_to_the_core_builder(): void {
		$client = $this->client();

		$expected = \Nvoos\Core\Domain\Mcp\McpRequestEnvelope::meta(
			WP_MCP_AI_MCP_App_Client::PROTOCOL_VERSION,
			'NV oOS MCP App Client',
			defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.9.0'
		);

		// json_encode rather than assertEquals: the envelope carries a
		// stdClass for clientCapabilities, and object identity is not the point.
		$this->assertSame( wp_json_encode( $expected ), wp_json_encode( $client->meta() ) );
	}
}
