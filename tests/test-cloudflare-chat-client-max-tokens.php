<?php
/**
 * Test Cloudflare chat-client max_tokens behavior.
 *
 * Verifies that max_tokens values from orchestration presets are correctly
 * applied to Cloudflare Worker AI chat-client requests.
 *
 * Issue: Chat responses limited to ~6k tokens when orchestration preset specifies ~16k.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Cloudflare_Chat_Client_Max_Tokens_Test extends WP_UnitTestCase {

	/**
	 * Test that Resource Manager correctly reads orchestration preset values.
	 */
	public function test_resource_manager_reads_orchestration_preset() {
		// Apply Conservative preset which sets high_tier_max_tokens = 16000.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'conservative' );
		}

		// Get the Resource Manager.
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
		
		// Get the workload tier (should be 'high' for most test environments).
		$tier = $resource_mgr->get_workload_tier();
		
		// Get max_tokens - should be 16000 for Conservative preset on high tier.
		$max_tokens = $resource_mgr->get_max_tokens();

		$this->assertContains( $tier, array( 'low', 'medium', 'high' ), 'Tier should be low, medium, or high' );

		// Conservative preset values:
		// - low_tier_max_tokens: 1000
		// - medium_tier_max_tokens: 4000
		// - high_tier_max_tokens: 16000
		$expected_map = array(
			'low'    => 1000,
			'medium' => 4000,
			'high'   => 16000,
		);

		$this->assertEquals(
			$expected_map[ $tier ],
			$max_tokens,
			sprintf(
				'Resource Manager should return %d tokens for %s tier with Conservative preset, got %d',
				$expected_map[ $tier ],
				$tier,
				$max_tokens
			)
		);
	}

	/**
	 * Test that Cloudflare client uses Resource Manager max_tokens.
	 */
	public function test_cloudflare_client_uses_resource_manager_max_tokens() {
		// Apply Conservative preset.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'conservative' );
		}

		// Get expected max_tokens from Resource Manager.
		$resource_mgr      = WP_MCP_AI_Resource_Manager::instance();
		$expected_max_tokens = $resource_mgr->get_max_tokens();

		// Create Cloudflare client.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			$this->markTestSkipped( 'Cloudflare client class not available' );
		}

		$client = new WP_MCP_AI_Cloudflare_Client();

		// Use reflection to test the build_payload method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		// Build payload without explicit max_tokens - should use Resource Manager.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$options = array(
			'model' => '@cf/meta/llama-3.1-8b-instruct',
			// No max_tokens specified - should use Resource Manager default.
		);

		$payload = $method->invoke( $client, $messages, $options );

		$this->assertArrayHasKey( 'max_tokens', $payload, 'Payload should include max_tokens' );
		$this->assertEquals(
			$expected_max_tokens,
			$payload['max_tokens'],
			sprintf(
				'Cloudflare client should use Resource Manager max_tokens (%d), got %d',
				$expected_max_tokens,
				$payload['max_tokens']
			)
		);
	}

	/**
	 * Test explicit max_tokens override.
	 */
	public function test_explicit_max_tokens_overrides_resource_manager() {
		// Apply Conservative preset.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'conservative' );
		}

		// Create Cloudflare client.
		if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			$this->markTestSkipped( 'Cloudflare client class not available' );
		}

		$client = new WP_MCP_AI_Cloudflare_Client();

		// Use reflection to test the build_payload method.
		$reflection = new ReflectionClass( $client );
		$method     = $reflection->getMethod( 'build_payload' );
		$method->setAccessible( true );

		// Build payload WITH explicit max_tokens - should override Resource Manager.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message',
			),
		);

		$explicit_max_tokens = 25000;
		$options = array(
			'model'      => '@cf/meta/llama-3.1-8b-instruct',
			'max_tokens' => $explicit_max_tokens,
		);

		$payload = $method->invoke( $client, $messages, $options );

		$this->assertArrayHasKey( 'max_tokens', $payload, 'Payload should include max_tokens' );
		$this->assertEquals(
			$explicit_max_tokens,
			$payload['max_tokens'],
			sprintf(
				'Cloudflare client should use explicit max_tokens (%d), got %d',
				$explicit_max_tokens,
				$payload['max_tokens']
			)
		);
	}

	/**
	 * Test that different presets result in different max_tokens values.
	 */
	public function test_different_presets_yield_different_max_tokens() {
		$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
		$tier         = $resource_mgr->get_workload_tier();

		// Test Conservative preset.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'conservative' );
		}
		$conservative_max_tokens = $resource_mgr->get_max_tokens();

		// Test Balanced preset.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
		}
		$balanced_max_tokens = $resource_mgr->get_max_tokens();

		// Test Aggressive (Performance) preset.
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'aggressive' );
		}
		$aggressive_max_tokens = $resource_mgr->get_max_tokens();

		// Verify that presets result in different values.
		$this->assertNotEquals(
			$conservative_max_tokens,
			$aggressive_max_tokens,
			'Conservative and Aggressive presets should have different max_tokens'
		);

		// For high tier:
		// Conservative: 16000, Balanced: 32000, Aggressive: 64000
		if ( 'high' === $tier ) {
			$this->assertEquals( 16000, $conservative_max_tokens, 'Conservative high tier should be 16000' );
			$this->assertEquals( 32000, $balanced_max_tokens, 'Balanced high tier should be 32000' );
			$this->assertEquals( 64000, $aggressive_max_tokens, 'Aggressive high tier should be 64000' );
		}
	}

	/**
	 * Cleanup after tests.
	 */
	public function tearDown(): void {
		parent::tearDown();
		
		// Reset to Balanced preset (default).
		if ( class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
			WP_MCP_AI_Orchestration_Preset_Service::apply_preset( 'balanced' );
		}
	}
}
