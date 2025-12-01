<?php
/**
 * Tests for tool result sanitization before passing to LLM.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test tool result sanitization for LLM context.
 */
class WP_MCP_AI_Tool_LLM_Sanitization_Test extends WP_UnitTestCase {

	/**
	 * Test that tools with custom sanitization use their own rules.
	 */
	public function test_custom_sanitization_is_used_when_available() {
		$assistant_id = $this->create_assistant();
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Mock the crawl4ai tool response with large raw data.
		$mock_result = array(
			'status'   => 'completed',
			'task_id'  => 'task_123',
			'results'  => array(
				array(
					'url'      => 'https://example.com',
					'markdown' => '# Test Content (already truncated)',
					'text'     => 'Test content',
					'html'     => '<html>Test</html>',
					'metadata' => array(
						'headers'      => array( 'Content-Type' => 'text/html' ),
						'retrieved_at' => '2024-01-01 00:00:00',
					),
				),
			),
			'raw'      => array(
				'results' => array(
					array(
						'url'      => 'https://example.com',
						'markdown' => '# FULL UNTRUNCATED CONTENT THAT IS HUGE AND SHOULD BE REMOVED...',
						'text'     => 'Full untruncated content...',
					),
				),
			),
			'metadata' => array(
				'user_agent' => 'Test Agent',
			),
		);

		$tool      = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$sanitized = $tool->sanitize_for_llm( $mock_result );

		// 'raw' field should be stripped.
		$this->assertArrayNotHasKey( 'raw', $sanitized );

		// Actual content should be preserved (LLM needs it).
		$this->assertArrayHasKey( 'results', $sanitized );
		$this->assertArrayHasKey( 'markdown', $sanitized['results'][0] );
		$this->assertEquals( '# Test Content (already truncated)', $sanitized['results'][0]['markdown'] );
		$this->assertArrayHasKey( 'text', $sanitized['results'][0] );

		// HTML field should be stripped (redundant and large).
		$this->assertArrayNotHasKey( 'html', $sanitized['results'][0] );

		// Verbose metadata should be stripped.
		$this->assertArrayNotHasKey( 'headers', $sanitized['results'][0]['metadata'] );
		$this->assertArrayNotHasKey( 'retrieved_at', $sanitized['results'][0]['metadata'] );
		$this->assertArrayNotHasKey( 'user_agent', $sanitized['metadata'] );

		// Essential metadata should be kept.
		$this->assertEquals( 'completed', $sanitized['status'] );
		$this->assertEquals( 'task_123', $sanitized['task_id'] );
	}

	/**
	 * Test image generation tool strips base64 data but keeps metadata.
	 */
	public function test_image_tool_strips_base64_keeps_metadata() {
		$mock_result = array(
			'attachment_id' => 123,
			'url'           => 'https://example.com/image.png',
			'file_name'     => 'generated-image.png',
			'mime_type'     => 'image/png',
			'bytes'         => 50000,
			'size'          => '1024x1024',
			'quality'       => 'hd',
			'format'        => 'png',
			'prompt'        => 'A beautiful landscape',
			'content'       => array(
				'encoding'  => 'base64',
				'data'      => str_repeat( 'A', 100000 ), // 100KB of base64 data.
				'data_url'  => 'data:image/png;base64,' . str_repeat( 'A', 100000 ),
				'mime_type' => 'image/png',
			),
		);

		$tool      = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
		$sanitized = $tool->sanitize_for_llm( $mock_result );

		// Base64 content should be stripped.
		$this->assertArrayNotHasKey( 'content', $sanitized );

		// Essential metadata should be kept.
		$this->assertEquals( 123, $sanitized['attachment_id'] );
		$this->assertEquals( 'https://example.com/image.png', $sanitized['url'] );
		$this->assertEquals( 'generated-image.png', $sanitized['file_name'] );
		$this->assertEquals( 50000, $sanitized['bytes'] );
		$this->assertEquals( 'A beautiful landscape', $sanitized['prompt'] );
	}

	/**
	 * Test generic sanitization for tools without custom rules.
	 */
	public function test_generic_sanitization_strips_common_verbose_fields() {
		$mock_result = array(
			'id'       => 456,
			'title'    => 'Test Item',
			'url'      => 'https://example.com/item',
			'status'   => 'published',
			'raw'      => array( 'huge' => 'api response' ),
			'metadata' => array(
				'headers'      => array( 'X-Custom' => 'value' ),
				'retrieved_at' => '2024-01-01',
			),
		);

		// Use reflection to access protected generic_sanitize_for_llm method.
		$rest_controller = $this->get_rest_controller();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'generic_sanitize_for_llm' );
		$method->setAccessible( true );

		$sanitized = $method->invoke( $rest_controller, $mock_result );

		// 'raw' should be stripped.
		$this->assertArrayNotHasKey( 'raw', $sanitized );

		// Verbose metadata should be stripped.
		$this->assertArrayNotHasKey( 'headers', $sanitized['metadata'] );
		$this->assertArrayNotHasKey( 'retrieved_at', $sanitized['metadata'] );

		// Essential fields should be kept.
		$this->assertEquals( 456, $sanitized['id'] );
		$this->assertEquals( 'Test Item', $sanitized['title'] );
		$this->assertEquals( 'https://example.com/item', $sanitized['url'] );
	}

	/**
	 * Test that REST controller uses custom sanitization when tool implements interface.
	 */
	public function test_rest_controller_delegates_to_tool_sanitization() {
		$assistant_id = $this->create_assistant();
		$user_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create a mock tool that implements the sanitizer interface.
		$mock_tool = $this->getMockBuilder( WP_MCP_AI_Tool_Interface::class )
			->getMock();

		$mock_tool->method( 'get_slug' )->willReturn( 'test_tool' );

		// Make the mock also implement the sanitizer interface.
		$mock_tool_with_sanitizer = new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
			public function get_slug() {
				return 'test_tool';
			}
			public function get_name() {
				return 'Test Tool';
			}
			public function get_description() {
				return 'Test';
			}
			public function get_parameters_schema() {
				return array();
			}
			public function execute( array $arguments = array(), array $context = array() ) {
				return array(
					'test'        => 'data',
					'large_field' => 'should be removed',
				);
			}
			public function sanitize_for_llm( $result ) {
				// Custom sanitization: only keep 'test' field.
				return array( 'test' => $result['test'] );
			}
		};

		// Register the mock tool.
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$reflection = new ReflectionClass( $registry );
		$property   = $reflection->getProperty( 'tools' );
		$property->setAccessible( true );
		$tools              = $property->getValue( $registry );
		$tools['test_tool'] = $mock_tool_with_sanitizer;
		$property->setValue( $registry, $tools );

		// Update assistant config to allow this tool.
		update_post_meta( $assistant_id, 'tools', array( 'test_tool' ) );

		// Get REST controller and test sanitization.
		$rest_controller = $this->get_rest_controller();
		$reflection      = new ReflectionClass( $rest_controller );
		$method          = $reflection->getMethod( 'sanitize_tool_result_for_llm' );
		$method->setAccessible( true );

		$result           = array(
			'test'        => 'data',
			'large_field' => 'should be removed',
		);
		$assistant_config = array( 'tools' => array( 'test_tool' ) );

		$sanitized = $method->invoke( $rest_controller, $result, 'test_tool', $assistant_config );

		// Custom sanitization should have been applied.
		$this->assertEquals( array( 'test' => 'data' ), $sanitized );
		$this->assertArrayNotHasKey( 'large_field', $sanitized );
	}

	/**
	 * Helper to create an assistant post.
	 *
	 * @return int Assistant post ID.
	 */
	protected function create_assistant() {
		$assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $assistant_id );
		$this->assertNotEmpty( $assistant_id );

		update_post_meta( $assistant_id, 'model', 'gpt-4' );
		update_post_meta( $assistant_id, 'tools', array( 'run_crawl4ai_job', 'generate_openai_image' ) );

		return $assistant_id;
	}

	/**
	 * Helper to get REST controller instance.
	 *
	 * @return WP_MCP_AI_REST REST controller.
	 */
	protected function get_rest_controller() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$client   = new WP_MCP_AI_Language_Model_Router();
		return new WP_MCP_AI_REST( $registry, $client );
	}
}
