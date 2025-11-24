<?php
/**
 * Tests for WP_MCP_AI_Token_Usage_Service with mixed provider scenarios.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for token usage service with mixed providers.
 */
class WP_MCP_AI_Token_Usage_Service_Mixed_Providers_Test extends WP_UnitTestCase {
	/**
	 * Test user IDs.
	 *
	 * @var array
	 */
	private $test_users = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test users.
		$this->test_users[] = self::factory()->user->create();
		$this->test_users[] = self::factory()->user->create();

		// Initialize settings.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up test users.
		foreach ( $this->test_users as $user_id ) {
			delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );
		}

		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		parent::tearDown();
	}

	/**
	 * Test that the same model name from different providers is tracked separately.
	 */
	public function test_same_model_different_providers_tracked_separately() {
		$user_id      = $this->test_users[0];
		$assistant_id = 42;

		// Record usage for gpt-4o from OpenAI.
		$openai_options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o',
		);

		$openai_response = array(
			'model'    => 'gpt-4o',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $openai_options, $openai_response );

		// Record usage for the same model name but from a different provider (Ollama).
		$ollama_options = array(
			'provider' => 'ollama',
			'model'    => 'gpt-4o',
		);

		$ollama_response = array(
			'model'    => 'gpt-4o',
			'usage'    => array(
				'prompt_tokens'     => 200,
				'completion_tokens' => 100,
				'total_tokens'      => 300,
			),
			'provider' => 'ollama',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $ollama_options, $ollama_response );

		// Get site-wide statistics.
		$stats = WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();

		// Verify that we have two separate entries in top_models.
		$this->assertNotEmpty( $stats['top_models'] );
		$this->assertCount( 2, $stats['top_models'] );

		// Verify that both entries have the same model name but different providers.
		$model_names = array_map(
			function ( $model ) {
				return $model['model'];
			},
			$stats['top_models']
		);
		$this->assertContains( 'gpt-4o', $model_names );

		// Find the entries.
		$openai_entry = null;
		$ollama_entry = null;

		foreach ( $stats['top_models'] as $model_data ) {
			if ( 'gpt-4o' === $model_data['model'] ) {
				if ( 'openai' === $model_data['provider'] ) {
					$openai_entry = $model_data;
				} elseif ( 'ollama' === $model_data['provider'] ) {
					$ollama_entry = $model_data;
				}
			}
		}

		// Verify both entries exist.
		$this->assertNotNull( $openai_entry, 'OpenAI entry should exist' );
		$this->assertNotNull( $ollama_entry, 'Ollama entry should exist' );

		// Verify each has the correct token counts.
		$this->assertSame( 150, $openai_entry['total_tokens'] );
		$this->assertSame( 300, $ollama_entry['total_tokens'] );
		$this->assertSame( 1, $openai_entry['requests'] );
		$this->assertSame( 1, $ollama_entry['requests'] );
	}

	/**
	 * Test that different models from the same provider are tracked separately.
	 */
	public function test_different_models_same_provider_tracked_separately() {
		$user_id      = $this->test_users[0];
		$assistant_id = 42;

		// Record usage for gpt-4o-mini.
		$options1 = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response1 = array(
			'model'    => 'gpt-4o-mini',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options1, $response1 );

		// Record usage for gpt-5-nano.
		$options2 = array(
			'provider' => 'openai',
			'model'    => 'gpt-5-nano-2025-08-07',
		);

		$response2 = array(
			'model'    => 'gpt-5-nano-2025-08-07',
			'usage'    => array(
				'prompt_tokens'     => 200,
				'completion_tokens' => 100,
				'total_tokens'      => 300,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options2, $response2 );

		// Get site-wide statistics.
		$stats = WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();

		// Verify that we have two separate entries.
		$this->assertNotEmpty( $stats['top_models'] );
		$this->assertCount( 2, $stats['top_models'] );

		// Verify both are from OpenAI but different models.
		foreach ( $stats['top_models'] as $model_data ) {
			$this->assertSame( 'openai', $model_data['provider'] );
		}
	}

	/**
	 * Test site-wide statistics aggregation with multiple users and mixed providers.
	 */
	public function test_site_wide_stats_with_mixed_providers_multiple_users() {
		$user1        = $this->test_users[0];
		$user2        = $this->test_users[1];
		$assistant_id = 42;

		// User 1: OpenAI gpt-4o-mini.
		$options1 = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini-2024-07-18',
		);

		$response1 = array(
			'model'    => 'gpt-4o-mini-2024-07-18',
			'usage'    => array(
				'prompt_tokens'     => 261,
				'completion_tokens' => 3050645,
				'total_tokens'      => 3050906,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user1, $assistant_id, $options1, $response1 );

		// User 2: OpenAI gpt-5-2025-08-07.
		$options2 = array(
			'provider' => 'openai',
			'model'    => 'gpt-5-2025-08-07',
		);

		$response2 = array(
			'model'    => 'gpt-5-2025-08-07',
			'usage'    => array(
				'prompt_tokens'     => 248,
				'completion_tokens' => 6196026,
				'total_tokens'      => 6196274,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user2, $assistant_id, $options2, $response2 );

		// User 2 also: OpenAI gpt-5-nano-2025-08-07.
		$options3 = array(
			'provider' => 'openai',
			'model'    => 'gpt-5-nano-2025-08-07',
		);

		$response3 = array(
			'model'    => 'gpt-5-nano-2025-08-07',
			'usage'    => array(
				'prompt_tokens'     => 273,
				'completion_tokens' => 3454952,
				'total_tokens'      => 3455225,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user2, $assistant_id, $options3, $response3 );

		// Get site-wide statistics.
		$stats = WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();

		// Verify we have entries for all three models.
		$this->assertNotEmpty( $stats['top_models'] );
		$this->assertCount( 3, $stats['top_models'] );

		// Verify all are from OpenAI.
		foreach ( $stats['top_models'] as $model_data ) {
			$this->assertSame( 'openai', $model_data['provider'] );
		}

		// Verify the model with highest token count is first (gpt-5-2025-08-07).
		$this->assertSame( 'gpt-5-2025-08-07', $stats['top_models'][0]['model'] );
		$this->assertSame( 6196274, $stats['top_models'][0]['total_tokens'] );

		// Verify total statistics.
		$this->assertSame( 2, $stats['total_users'] );
		$this->assertSame( 3, $stats['total_requests'] );

		// Verify provider stats.
		$this->assertArrayHasKey( 'openai', $stats['by_provider'] );
		$this->assertSame( 3, $stats['by_provider']['openai']['requests'] );
	}

	/**
	 * Test that top_tools is included in site-wide statistics.
	 */
	public function test_top_tools_included_in_site_wide_stats() {
		// Get site-wide statistics.
		$stats = WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();

		// Verify that top_tools key exists in the response.
		$this->assertArrayHasKey( 'top_tools', $stats );
		$this->assertIsArray( $stats['top_tools'] );
	}

	/**
	 * Test that top_tools contains expected data structure when tools are used.
	 */
	public function test_top_tools_data_structure() {
		// Skip if Tool Token Limits class is not available.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Token_Limits class not available' );
		}

		$user_id = $this->test_users[0];

		// Simulate tool usage for a user.
		$tool_usage = array(
			'test_tool' => array(
				'requests'     => 5,
				'total_tokens' => 1000,
			),
		);

		update_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY, $tool_usage );

		// Get site-wide statistics.
		$stats = WP_MCP_AI_Token_Usage_Service::get_site_wide_statistics();

		// Verify top_tools contains data.
		$this->assertArrayHasKey( 'top_tools', $stats );

		if ( ! empty( $stats['top_tools'] ) ) {
			$first_tool = reset( $stats['top_tools'] );

			// Verify data structure.
			$this->assertArrayHasKey( 'tool_slug', $first_tool );
			$this->assertArrayHasKey( 'tool_name', $first_tool );
			$this->assertArrayHasKey( 'total_users', $first_tool );
			$this->assertArrayHasKey( 'requests', $first_tool );
			$this->assertArrayHasKey( 'total_tokens', $first_tool );
			$this->assertArrayHasKey( 'total_cost', $first_tool );
		}

		// Clean up.
		delete_user_meta( $user_id, WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY );
	}
}
