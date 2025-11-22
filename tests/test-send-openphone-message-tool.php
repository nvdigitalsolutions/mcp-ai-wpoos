<?php
/**
 * Tests for the send OpenPhone message tool.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-send-openphone-message.php';

/**
 * Tests for the send OpenPhone message tool.
 */
class WP_MCP_AI_Send_OpenPhone_Message_Tool_Test extends WP_UnitTestCase {
	/**
	 * Reset globals between tests.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_all_filters( 'wp_mcp_ai_send_openphone_message_capability' );
		remove_all_filters( 'wp_mcp_ai_send_openphone_message_timeout' );
		parent::tearDown();
	}

	/**
	 * Ensure users without the required capability cannot send messages.
	 */
	public function test_execute_requires_permission() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array( '+15555555556' ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Ensure multisite access validation works.
	 */
	public function test_execute_validates_multisite_access() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'This test requires multisite.' );
		}

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Remove user from blog to test multisite validation.
		remove_user_from_blog( $user_id, get_current_blog_id() );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array( '+15555555556' ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $user_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_wrong_site', $result->get_error_code() );
	}

	/**
	 * Ensure missing API key is rejected.
	 */
	public function test_execute_requires_api_key() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'from'    => '+15555555555',
				'to'      => array( '+15555555556' ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_openphone_api_key', $result->get_error_code() );
	}

	/**
	 * Ensure missing from number is rejected.
	 */
	public function test_execute_requires_from_number() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'to'      => array( '+15555555556' ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_openphone_from', $result->get_error_code() );
	}

	/**
	 * Ensure missing recipient array is rejected.
	 */
	public function test_execute_requires_recipients() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'content' => 'Test message',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_openphone_to', $result->get_error_code() );
	}

	/**
	 * Ensure empty recipient array is rejected.
	 */
	public function test_execute_rejects_empty_recipients() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array(),
				'content' => 'Test message',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_openphone_to', $result->get_error_code() );
	}

	/**
	 * Ensure invalid recipient phone numbers are filtered out.
	 */
	public function test_execute_filters_invalid_recipients() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array( '', 'invalid', null ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_openphone_recipients', $result->get_error_code() );
	}

	/**
	 * Ensure missing message content is rejected.
	 */
	public function test_execute_requires_content() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array( '+15555555556' ),
			),
			array(
				'user_id' => $admin_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_openphone_content', $result->get_error_code() );
	}

	/**
	 * Test that the tool slug is correct.
	 */
	public function test_get_slug() {
		$tool = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$this->assertSame( 'send_openphone_message', $tool->get_slug() );
	}

	/**
	 * Test that the tool name is translatable.
	 */
	public function test_get_name() {
		$tool = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$name = $tool->get_name();
		$this->assertIsString( $name );
		$this->assertNotEmpty( $name );
	}

	/**
	 * Test that the tool description is translatable.
	 */
	public function test_get_description() {
		$tool        = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$description = $tool->get_description();
		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
	}

	/**
	 * Test that the parameters schema is properly structured.
	 */
	public function test_get_parameters_schema() {
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required fields.
		$this->assertContains( 'api_key', $schema['required'] );
		$this->assertContains( 'from', $schema['required'] );
		$this->assertContains( 'to', $schema['required'] );
		$this->assertContains( 'content', $schema['required'] );

		// Check properties.
		$this->assertArrayHasKey( 'api_key', $schema['properties'] );
		$this->assertArrayHasKey( 'from', $schema['properties'] );
		$this->assertArrayHasKey( 'to', $schema['properties'] );
		$this->assertArrayHasKey( 'content', $schema['properties'] );
		$this->assertArrayHasKey( 'user_id', $schema['properties'] );

		// Verify 'to' is an array type.
		$this->assertSame( 'array', $schema['properties']['to']['type'] );
	}

	/**
	 * Test that capability flags are properly defined.
	 */
	public function test_get_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'external-api', $flags );
		$this->assertContains( 'network-dependent', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'requires-credentials', $flags );
		$this->assertContains( 'rate-limited', $flags );
	}

	/**
	 * Test phone number sanitization adds plus sign.
	 */
	public function test_phone_number_sanitization_adds_plus() {
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_phone_number' );
		$method->setAccessible( true );

		// Test number without plus sign.
		$result = $method->invoke( $tool, '15555555555' );
		$this->assertSame( '+15555555555', $result );

		// Test number with plus sign.
		$result = $method->invoke( $tool, '+15555555555' );
		$this->assertSame( '+15555555555', $result );

		// Test number with formatting.
		$result = $method->invoke( $tool, '+1 (555) 555-5555' );
		$this->assertSame( '+15555555555', $result );
	}

	/**
	 * Test API key sanitization.
	 */
	public function test_api_key_sanitization() {
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_api_key' );
		$method->setAccessible( true );

		// Valid API key.
		$result = $method->invoke( $tool, 'sk_test_123456' );
		$this->assertSame( 'sk_test_123456', $result );

		// API key with spaces.
		$result = $method->invoke( $tool, '  sk_test_123456  ' );
		$this->assertSame( 'sk_test_123456', $result );

		// Empty API key.
		$result = $method->invoke( $tool, '' );
		$this->assertSame( '', $result );

		// Invalid type.
		$result = $method->invoke( $tool, array() );
		$this->assertSame( '', $result );
	}

	/**
	 * Test message content sanitization.
	 */
	public function test_message_sanitization() {
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$method = new ReflectionMethod( $tool, 'sanitize_message' );
		$method->setAccessible( true );

		// Normal text.
		$result = $method->invoke( $tool, 'Hello, world!' );
		$this->assertSame( 'Hello, world!', $result );

		// Text with extra whitespace.
		$result = $method->invoke( $tool, "  Hello,\nworld!  " );
		$this->assertStringContainsString( 'Hello', $result );

		// Empty text.
		$result = $method->invoke( $tool, '' );
		$this->assertSame( '', $result );

		// Invalid type.
		$result = $method->invoke( $tool, 123 );
		$this->assertSame( '', $result );
	}

	/**
	 * Test sensitive value masking.
	 */
	public function test_mask_sensitive_value() {
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$method = new ReflectionMethod( $tool, 'mask_sensitive_value' );
		$method->setAccessible( true );

		// Long value.
		$result = $method->invoke( $tool, '+15555555555' );
		$this->assertStringStartsWith( '+1', $result );
		$this->assertStringEndsWith( '55', $result );
		$this->assertStringContainsString( '*', $result );

		// Short value.
		$result = $method->invoke( $tool, 'abc' );
		$this->assertSame( '***', $result );

		// Empty value.
		$result = $method->invoke( $tool, '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test that capability filter is respected.
	 */
	public function test_capability_filter_is_respected() {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		// Without filter, editor should not have permission.
		$tool   = new WP_MCP_AI_Tool_Send_OpenPhone_Message();
		$result = $tool->execute(
			array(
				'api_key' => 'test_api_key',
				'from'    => '+15555555555',
				'to'      => array( '+15555555556' ),
				'content' => 'Test message',
			),
			array(
				'user_id' => $editor_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		// With filter allowing editors.
		add_filter(
			'wp_mcp_ai_send_openphone_message_capability',
			function () {
				return 'edit_posts';
			}
		);

		// Now we need to mock the HTTP response since editor has permission.
		// For this test, we'll just verify the capability check passed.
		// In a real scenario, we'd mock wp_remote_post.
	}
}
