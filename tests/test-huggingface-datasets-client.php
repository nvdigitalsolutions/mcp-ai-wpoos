<?php
/**
 * Tests for WP_MCP_AI_Huggingface_Datasets_Client class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for huggingface datasets client tests.
 *
 * @group huggingface-datasets-client
 */
class WP_MCP_AI_Huggingface_Datasets_Client_Tests extends WP_UnitTestCase {

	/**
	 * Hugging Face Datasets client instance.
	 *
	 * @var WP_MCP_AI_Huggingface_Datasets_Client
	 */
	protected $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client = new WP_MCP_AI_Huggingface_Datasets_Client();

		// Clear settings.
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		// Enable HuggingFace Datasets for tests.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_huggingface_datasets' => true,
			)
		);
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test get_dataset_name_suggestions with known renamed dataset.
	 */
	public function test_get_dataset_name_suggestions_for_imdb() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'imdb' );

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );
		$this->assertContains( 'stanfordnlp/imdb', $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions with unknown dataset.
	 */
	public function test_get_dataset_name_suggestions_for_unknown_dataset() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'nonexistent_dataset_12345' );

		$this->assertIsArray( $suggestions );
		$this->assertEmpty( $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions with case insensitivity.
	 */
	public function test_get_dataset_name_suggestions_case_insensitive() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions_lower = $method->invoke( $this->client, 'imdb' );
		$suggestions_upper = $method->invoke( $this->client, 'IMDB' );
		$suggestions_mixed = $method->invoke( $this->client, 'ImDb' );

		$this->assertEquals( $suggestions_lower, $suggestions_upper );
		$this->assertEquals( $suggestions_lower, $suggestions_mixed );
	}

	/**
	 * Test get_dataset_name_suggestions for squad dataset.
	 */
	public function test_get_dataset_name_suggestions_for_squad() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'squad' );

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );
		$this->assertContains( 'rajpurkar/squad', $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions for common_voice dataset.
	 */
	public function test_get_dataset_name_suggestions_for_common_voice() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'common_voice' );

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );
		$this->assertContains( 'mozilla-foundation/common_voice_17_0', $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions for bookcorpus dataset.
	 */
	public function test_get_dataset_name_suggestions_for_bookcorpus() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'bookcorpus' );

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );
		$this->assertContains( 'bookcorpus', $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions for wmt14 dataset.
	 */
	public function test_get_dataset_name_suggestions_for_wmt14() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions = $method->invoke( $this->client, 'wmt14' );

		$this->assertIsArray( $suggestions );
		$this->assertNotEmpty( $suggestions );
		$this->assertContains( 'wmt/wmt14', $suggestions );
	}

	/**
	 * Test get_dataset_name_suggestions for super_glue dataset variations.
	 */
	public function test_get_dataset_name_suggestions_for_super_glue() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'get_dataset_name_suggestions' );
		$method->setAccessible( true );

		$suggestions_underscore    = $method->invoke( $this->client, 'super_glue' );
		$suggestions_no_underscore = $method->invoke( $this->client, 'superglue' );

		$this->assertIsArray( $suggestions_underscore );
		$this->assertNotEmpty( $suggestions_underscore );
		$this->assertContains( 'super_glue', $suggestions_underscore );

		$this->assertIsArray( $suggestions_no_underscore );
		$this->assertNotEmpty( $suggestions_no_underscore );
		$this->assertContains( 'super_glue', $suggestions_no_underscore );
	}

	/**
	 * Test that 404 errors include helpful suggestions.
	 *
	 * Note: This test mocks the API response to avoid making real API calls.
	 */
	public function test_handle_response_404_with_suggestions() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_response' );
		$method->setAccessible( true );

		// Mock a 404 response.
		$mock_response = array(
			'response' => array(
				'code' => 404,
			),
			'body'     => '{"error":"Not found"}',
		);

		$params = array(
			'dataset' => 'imdb',
		);

		$result = $method->invoke( $this->client, $mock_response, '/search', $params );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_hf_datasets_not_found', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'imdb', $message );
		$this->assertStringContainsString( 'not found', strtolower( $message ) );
		$this->assertStringContainsString( 'stanfordnlp/imdb', $message );
	}

	/**
	 * Test that 404 errors for unknown datasets include generic help message.
	 */
	public function test_handle_response_404_without_suggestions() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_response' );
		$method->setAccessible( true );

		// Mock a 404 response.
		$mock_response = array(
			'response' => array(
				'code' => 404,
			),
			'body'     => '{"error":"Not found"}',
		);

		$params = array(
			'dataset' => 'completely_unknown_dataset_xyz',
		);

		$result = $method->invoke( $this->client, $mock_response, '/search', $params );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_hf_datasets_not_found', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'completely_unknown_dataset_xyz', $message );
		$this->assertStringContainsString( 'not found', strtolower( $message ) );
		$this->assertStringContainsString( 'https://huggingface.co/datasets', $message );
	}

	/**
	 * Test that non-404 errors still work.
	 */
	public function test_handle_response_non_404_error() {
		// Use reflection to access protected method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'handle_response' );
		$method->setAccessible( true );

		// Mock a 500 response.
		$mock_response = array(
			'response' => array(
				'code' => 500,
			),
			'body'     => '{"error":"Internal server error"}',
		);

		$params = array(
			'dataset' => 'imdb',
		);

		$result = $method->invoke( $this->client, $mock_response, '/search', $params );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_hf_datasets_api_error', $result->get_error_code() );

		$message = $result->get_error_message();
		$this->assertStringContainsString( '500', $message );
	}
}
