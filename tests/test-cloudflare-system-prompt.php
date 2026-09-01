<?php
/**
 * Tests for Cloudflare system prompt handling
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Test that system_prompt in options is prepended as a system role message.
	 *
	 * Cloudflare Workers AI follows the OpenAI chat completions format: the
	 * system prompt is a system-role entry in the messages array, not a
	 * separate "system" field (that is the Ollama format).
	 */
	public function test_system_prompt_added_as_system_message() {
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

		// No separate "system" field in the OpenAI-compatible format.
		$this->assertArrayNotHasKey( 'system', $payload );

		// The system prompt is the first message, with role system.
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertIsArray( $payload['messages'] );
		$this->assertCount( 2, $payload['messages'] );
		$this->assertEquals( 'system', $payload['messages'][0]['role'] );
		$this->assertStringContainsString( 'disaster relief assistant', $payload['messages'][0]['content'] );
		$this->assertEquals( 'user', $payload['messages'][1]['role'] );
		$this->assertEquals( 'Hello, what can you do?', $payload['messages'][1]['content'] );
	}

	/**
	 * Test that system role messages are preserved in the messages array.
	 *
	 * Verifies system messages stay as system-role entries rather than being
	 * extracted into a separate field.
	 */
	public function test_system_messages_preserved_in_messages_array() {
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

		// No separate system field — the system message stays in the array.
		$this->assertArrayNotHasKey( 'system', $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 2, $payload['messages'] );
		$this->assertEquals( 'system', $payload['messages'][0]['role'] );
		$this->assertStringContainsString( 'YAAD-RELIEF', $payload['messages'][0]['content'] );
		$this->assertEquals( 'user', $payload['messages'][1]['role'] );
		$this->assertStringContainsString( 'hurricane', $payload['messages'][1]['content'] );
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
	 * Test that multiple system messages are preserved in order.
	 *
	 * This handles cases where professional layer prompts are added as
	 * additional system messages, ensuring they all remain in the messages
	 * array ahead of the user turn.
	 */
	public function test_multiple_system_messages_preserved() {
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

		// Both system messages stay in the array, in order, before the user.
		$this->assertArrayNotHasKey( 'system', $payload );
		$this->assertArrayHasKey( 'messages', $payload );
		$this->assertCount( 3, $payload['messages'] );
		$this->assertEquals( 'system', $payload['messages'][0]['role'] );
		$this->assertStringContainsString( 'YAAD-RELIEF', $payload['messages'][0]['content'] );
		$this->assertEquals( 'system', $payload['messages'][1]['role'] );
		$this->assertStringContainsString( 'Professional Role', $payload['messages'][1]['content'] );
		$this->assertStringContainsString( 'hurricane preparedness', $payload['messages'][1]['content'] );
		$this->assertEquals( 'user', $payload['messages'][2]['role'] );
	}

	/**
	 * Test that payload leads with messages and system entries come first.
	 *
	 * Cloudflare Workers AI follows the OpenAI format, so the messages field
	 * is the first payload key and system-role messages lead the array.
	 */
	public function test_payload_field_ordering() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are YAAD-RELIEF, a disaster relief assistant for Jamaica.',
			),
			array(
				'role'    => 'user',
				'content' => 'What can you help me with?',
			),
		);

		$tools = array(
			array(
				'type'     => 'function',
				'function' => array(
					'name'        => 'get_weather',
					'description' => 'Get weather information',
					'parameters'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
		);

		$options = array(
			'tools'       => $tools,
			'temperature' => 0.7,
		);

		// Use reflection to access the protected build_payload method.
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		$payload = $method->invoke( $this->client, $messages, $options );

		// Get the keys in the order they appear in the payload.
		$keys = array_keys( $payload );

		// The messages field must be the first payload key.
		$this->assertEquals( 'messages', $keys[0], 'First field should be messages' );

		// The system message must lead the messages array.
		$this->assertEquals( 'system', $payload['messages'][0]['role'] );
		$this->assertEquals( 'user', $payload['messages'][1]['role'] );

		// If tools are present, verify they come after messages.
		if ( isset( $payload['tools'] ) ) {
			$tools_index = array_search( 'tools', $keys, true );
			$this->assertGreaterThan( 0, $tools_index, 'Tools field should come after messages' );
		}
	}
}
