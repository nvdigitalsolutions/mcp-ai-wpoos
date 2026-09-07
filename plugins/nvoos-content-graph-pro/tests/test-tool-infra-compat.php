<?php
/**
 * Characterization tests for the D8-compat tool infrastructure slice
 * (Wave F2): the base-owned WP tool interfaces and response traits ported
 * into the standalone addon so ported Pro tools can
 * `implements WP_MCP_AI_Tool_Interface` / `use WP_MCP_AI_Tool_Chat_Response`
 * without the base plugin present.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base plugin owns every symbol;
 *   the public surface is asserted and the serving source must be the base
 *   plugin's files.
 * - Standalone matrix (base plugin absent): the addon's ported copies must
 *   serve the traits (the root composer classmap excludes
 *   `includes/tools/`, so no other source can resolve them). The grouped
 *   interfaces may resolve from the root composer classmap — a test-env
 *   artifact of the monorepo matrix — so only their existence is asserted.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers/class-test-tool-response-fixture.php';

use NvoosContentGraphPro\Tests\Test_Tool_Response_Fixture;

/**
 * D8-compat tool infrastructure tests.
 */
class Test_Tool_Infra_Compat extends WP_UnitTestCase {

	/**
	 * Every tool symbol the F2 toolkits depend on must be resolvable in both
	 * matrices.
	 */
	public function test_tool_symbols_are_loadable(): void {
		$this->assertTrue( interface_exists( 'WP_MCP_AI_Tool_Interface' ) );
		$this->assertTrue( interface_exists( 'WP_MCP_AI_Tool_Capability_Flags_Interface' ) );
		$this->assertTrue( interface_exists( 'WP_MCP_AI_Tool_Safety_Profile_Interface' ) );
		$this->assertTrue( trait_exists( 'WP_MCP_AI_Tool_Envelope' ) );
		$this->assertTrue( trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) );
	}

	/**
	 * The serving source must follow the ownership boundary:
	 * base plugin files in the monolith matrix, addon ported copies in the
	 * standalone matrix.
	 */
	public function test_trait_serving_source(): void {
		$envelope = new ReflectionClass( 'WP_MCP_AI_Tool_Envelope' );
		$file     = str_replace( '\\', '/', (string) $envelope->getFileName() );

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			// Monolith: the base plugin owns the trait.
			$this->assertStringContainsString( 'includes/tools/trait-wp-mcp-ai-tool-envelope.php', $file );
		} else {
			// Standalone: only the addon's ported copy can resolve it (the
			// root classmap excludes includes/tools/).
			$this->assertStringContainsString( 'nvoos-content-graph-pro/src/trait-wp-mcp-ai-tool-envelope.php', $file );
		}
	}

	/**
	 * The canonical success envelope keeps the byte-identical shape: only
	 * `success: true` arrays are produced; failures stay WP_Error.
	 */
	public function test_canonical_success_envelope_shape(): void {
		$fixture = new Test_Tool_Response_Fixture();

		// Scalar payload lands under the data key.
		$scalar = $fixture->expose_success_response( 'All good', 'payload' );
		$this->assertSame(
			array(
				'success' => true,
				'message' => 'All good',
				'data'    => 'payload',
			),
			$scalar
		);

		// Associative payload merges at the top level.
		$merged = $fixture->expose_success_response(
			'Done',
			array(
				'id'     => 42,
				'status' => 'created',
			)
		);
		$this->assertSame( true, $merged['success'] );
		$this->assertSame( 'Done', $merged['message'] );
		$this->assertSame( 42, $merged['id'] );
		$this->assertSame( 'created', $merged['status'] );

		// Null payload is omitted entirely.
		$bare = $fixture->expose_success_response( 'No data' );
		$this->assertSame(
			array(
				'success' => true,
				'message' => 'No data',
			),
			$bare
		);

		// No `success => false` array shape may exist anywhere in the result.
		$this->assertArrayNotHasKey( 'error', $bare );
		$this->assertTrue( $bare['success'] );
	}

	/**
	 * The chat-response formatter keeps the message-guarantee contract that
	 * the chat client depends on.
	 */
	public function test_chat_response_message_guarantee(): void {
		$fixture = new Test_Tool_Response_Fixture();

		// Explicit message + scalar data under the data key.
		$response = $fixture->expose_chat_response( 'raw text', 'Here you go' );
		$this->assertSame( 'Here you go', $response['message'] );
		$this->assertSame( 'raw text', $response['data'] );

		// Empty message auto-generates from the data (array with count).
		$auto = $fixture->expose_chat_response( array( 'count' => 2 ) );
		$this->assertNotSame( '', $auto['message'] );

		// Empty result responses carry the results/count contract.
		$empty = $fixture->expose_empty_result_response( 'Nothing here' );
		$this->assertSame(
			array(
				'message' => 'Nothing here',
				'results' => array(),
				'count'   => 0,
			),
			$empty
		);

		// Collection responses carry the items/count contract.
		$collection = $fixture->expose_collection_response( array( 'a', 'b' ), 'Two items' );
		$this->assertSame( 'Two items', $collection['message'] );
		$this->assertSame( array( 'a', 'b' ), $collection['items'] );
		$this->assertSame( 2, $collection['count'] );
	}

	/**
	 * ensure_response_message standardizes existing message-like keys onto
	 * the `message` field and guarantees a fallback.
	 */
	public function test_ensure_response_message_standardizes(): void {
		$fixture = new Test_Tool_Response_Fixture();

		$with_summary = $fixture->expose_ensure_response_message( array( 'summary' => 'All fine' ) );
		$this->assertSame( 'All fine', $with_summary['message'] );

		$with_fallback = $fixture->expose_ensure_response_message( array( 'id' => 7 ), 'Fallback text' );
		$this->assertSame( 'Fallback text', $with_fallback['message'] );
	}
}
