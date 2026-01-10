<?php
/**
 * Tests for Cloudflare system prompt handling
 *
 * @package WP_MCP_AI
 */

/**
 * Test Cloudflare system prompt functionality.
 */
class Test_Cloudflare_System_Prompt extends WP_UnitTestCase {

	/**
	 * Cloudflare client instance for testing.
	 *
	 * @var WP_MCP_AI_Cloudflare_Client
	 */
	private $client;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the Cloudflare client class.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cloudflare-client.php';
		}

		$this->client = new WP_MCP_AI_Cloudflare_Client();
	}

	/**
	 * Test that system_prompt in options is added as a system field in payload.
	 *
	 * Cloudflare Workers AI uses a separate 'system' field for system prompts
	 * rather than system role messages (similar to Ollama).
	 */
	public function test_system_prompt_added_as_system_field() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, what can you do?',
			),
		);

		$options = array(
			'system_prompt' => 'You are a helpful disaster relief assistant for Jamaica.',
			'model'         => '@cf/meta/llama-3.2-3b-instruct',
			'temperature'   => 0.7,
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		// First, we need to simulate what create_chat_completion does:
		// prepend system messages before calling build_payload.
		$system_messages = array();
		if ( ! empty( $options['system_prompt'] ) ) {
			$system_messages[] = array(
				'role'    => 'system',
				'content' => wp_kses_post( (string) $options['system_prompt'] ),
			);
		}

		if ( ! empty( $system_messages ) ) {
			$messages = array_merge( $system_messages, $messages );
		}

		// Now call build_payload with the updated messages.
		$payload = $method->invoke( $this->client, $messages, $options );

		// Verify the payload has a 'system' field with the system_prompt content.
		$this->assertArrayHasKey( 'system', $payload );
		$this->assertStringContainsString( 'disaster relief assistant', $payload['system'] );

		// Verify messages array only contains non-system messages.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertIsArray( $payload['messages'] );
		$this->assertCount( 1, $payload['messages'] );
		$this->assertEquals( 'user', $payload['messages'][0]['role'] );
		$this->assertEquals( 'Hello, what can you do?', $payload['messages'][0]['content'] );

		// Verify no system role messages in the messages array.
		foreach ( $payload['messages'] as $msg ) {
			$this->assertNotEquals( 'system', $msg['role'], 'Messages array should not contain system role messages' );
		}
	}

	/**
	 * Test that system messages are extracted and converted to system field.
	 *
	 * Verifies that system role messages are extracted from the messages array
	 * and placed in the 'system' field of the payload.
	 */
	public function test_system_messages_extracted_to_system_field() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are YAAD-RELIEF, a disaster relief GPT for Jamaica.',
			),
			array(
				'role'    => 'user',
				'content' => 'What should I do during a hurricane?',
			),
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array() );

		// Verify system field is present with system message content.
		$this->assertArrayHasKey( 'system', $payload );
		$this->assertStringContainsString( 'YAAD-RELIEF', $payload['system'] );
		$this->assertStringContainsString( 'disaster relief', $payload['system'] );

		// Verify messages array only contains non-system messages.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 1, $payload['messages'] );
		$this->assertEquals( 'user', $payload['messages'][0]['role'] );
		$this->assertStringContainsString( 'hurricane', $payload['messages'][0]['content'] );
	}

	/**
	 * Test that wp_kses_post doesn't strip meaningful content from system_prompt.
	 *
	 * Verifies that WordPress sanitization doesn't remove the actual instructions.
	 */
	public function test_system_prompt_sanitization_preserves_content() {
		$system_prompt = "# System Instructions\n\nYou are \"YAAD-RELIEF\", a calm, fast, and culturally-aware disaster relief GPT focused on Jamaica during hurricanes and other hazards. Your mission is to keep people safe, informed, and connected—prioritizing life safety, verified guidance, and Jamaica's legal framework.";

		$sanitized = wp_kses_post( $system_prompt );

		// Verify key content is preserved.
		$this->assertStringContainsString( 'YAAD-RELIEF', $sanitized );
		$this->assertStringContainsString( 'disaster relief', $sanitized );
		$this->assertStringContainsString( 'Jamaica', $sanitized );
		$this->assertStringContainsString( 'hurricanes', $sanitized );
		$this->assertGreaterThan( 100, strlen( $sanitized ) );
	}

	/**
	 * Test that empty system_prompt doesn't add a system field.
	 *
	 * Verifies that when system_prompt is empty or not provided,
	 * no system field is added to the payload.
	 */
	public function test_empty_system_prompt_no_system_field() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$options = array(
			'model'       => '@cf/meta/llama-3.2-3b-instruct',
			'temperature' => 0.7,
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		// Simulate what create_chat_completion does with empty system_prompt.
		$system_messages = array();
		if ( ! empty( $options['system_prompt'] ) ) {
			$system_messages[] = array(
				'role'    => 'system',
				'content' => wp_kses_post( (string) $options['system_prompt'] ),
			);
		}

		if ( ! empty( $system_messages ) ) {
			$messages = array_merge( $system_messages, $messages );
		}

		$payload = $method->invoke( $this->client, $messages, $options );

		// Verify the payload does not have a system field.
		$this->assertArrayNotHasKey( 'system', $payload );

		// Verify the payload only contains the user message.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 1, $payload['messages'] );
		$this->assertEquals( 'user', $payload['messages'][0]['role'] );
	}

	/**
	 * Test that multiple system messages are combined into a single system field.
	 *
	 * This handles cases where professional layer prompts are added as additional
	 * system messages, ensuring they're all combined correctly.
	 */
	public function test_multiple_system_messages_combined() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are YAAD-RELIEF, a disaster relief assistant for Jamaica.',
			),
			array(
				'role'    => 'system',
				'content' => 'Professional Role: You have expertise in hurricane preparedness and emergency response.',
			),
			array(
				'role'    => 'user',
				'content' => 'What should I prepare for a hurricane?',
			),
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, array() );

		// Verify system field contains both system messages combined.
		$this->assertArrayHasKey( 'system', $payload );
		$this->assertStringContainsString( 'YAAD-RELIEF', $payload['system'] );
		$this->assertStringContainsString( 'Professional Role', $payload['system'] );
		$this->assertStringContainsString( 'hurricane preparedness', $payload['system'] );

		// Verify messages array only contains the user message.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 1, $payload['messages'] );
		$this->assertEquals( 'user', $payload['messages'][0]['role'] );
	}
