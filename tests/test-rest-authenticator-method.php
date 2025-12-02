<?php
/**
 * Tests for WP_MCP_AI_REST_Authenticator::authenticate() method.
 *
 * Verifies that the authenticate() method is properly implemented and
 * handles different authentication scenarios correctly.
 *
 * @package WP_MCP_AI
 */

/**
 * Test REST authenticator authenticate method.
 */
class Test_REST_Authenticator_Method extends WP_UnitTestCase {
	/**
	 * Authenticator instance.
	 *
	 * @var WP_MCP_AI_REST_Authenticator
	 */
	private $authenticator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure required classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
			require_once dirname( __DIR__ ) . '/includes/rest/class-wp-mcp-ai-rest-authenticator.php';
		}

		$this->authenticator = new WP_MCP_AI_REST_Authenticator();
	}

	/**
	 * Test that authenticate() method exists.
	 */
	public function test_authenticate_method_exists() {
		$this->assertTrue(
			method_exists( $this->authenticator, 'authenticate' ),
			'WP_MCP_AI_REST_Authenticator should have an authenticate() method'
		);
	}

	/**
	 * Test authenticate with no credentials returns auth context.
	 */
	public function test_authenticate_with_no_credentials() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );

		$result = $this->authenticator->authenticate( $request );

		$this->assertIsArray( $result, 'authenticate() should return an array' );
		$this->assertArrayHasKey( 'user_id', $result, 'Result should have user_id key' );
		$this->assertSame( 0, $result['user_id'], 'user_id should be 0 for unauthenticated request' );
	}

	/**
	 * Test authenticate with valid nonce.
	 */
	public function test_authenticate_with_valid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->authenticator->authenticate( $request );

		$this->assertIsArray( $result, 'authenticate() should return an array' );
		$this->assertArrayHasKey( 'user_id', $result, 'Result should have user_id key' );
		$this->assertSame( $user_id, $result['user_id'], 'user_id should match authenticated user' );
	}

	/**
	 * Test authenticate returns error for invalid mesh key.
	 */
	public function test_authenticate_with_invalid_mesh_key() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'invalid_key' );

		$result = $this->authenticator->authenticate( $request );

		// Should return an error since mesh is not configured.
		$this->assertInstanceOf( WP_Error::class, $result, 'authenticate() should return WP_Error for invalid mesh key' );
	}

	/**
	 * Test that auth context is reset on each authenticate call.
	 */
	public function test_authenticate_resets_context() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request1 = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request1->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result1 = $this->authenticator->authenticate( $request1 );
		$this->assertSame( $user_id, $result1['user_id'] );

		// Now authenticate with no credentials.
		wp_set_current_user( 0 );
		$request2 = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );

		$result2 = $this->authenticator->authenticate( $request2 );
		$this->assertSame( 0, $result2['user_id'], 'Auth context should be reset between calls' );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}
}
