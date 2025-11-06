<?php
/**
 * Tests for the usage tracker utilities.
 */

class WP_MCP_AI_Usage_Tracker_Test extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

		parent::tearDown();
	}

	public function test_record_chat_usage_updates_totals() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 42;

		$options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response = array(
			'model'    => 'gpt-4o-mini',
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 75,
				'total_tokens'      => 175,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'openai', $totals );
		$this->assertArrayHasKey( 'gpt-4o-mini', $totals['openai'] );

		$model_totals = $totals['openai']['gpt-4o-mini'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 100, $model_totals['prompt_tokens'] );
		$this->assertSame( 75, $model_totals['completion_tokens'] );
		$this->assertSame( 175, $model_totals['total_tokens'] );
		$this->assertNotEmpty( $model_totals['last_used_gmt'] );
		$this->assertArrayHasKey( $assistant_id, $model_totals['assistants'] );

		$assistant_totals = $model_totals['assistants'][ $assistant_id ];
		$this->assertSame( 1, $assistant_totals['requests'] );
		$this->assertSame( 175, $assistant_totals['total_tokens'] );
	}

	public function test_record_chat_usage_accumulates_values() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 7;

		$options = array(
			'provider' => 'openai',
			'model'    => 'gpt-4o-mini',
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );
		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$model_totals = $totals['openai']['gpt-4o-mini'];
		$this->assertSame( 2, $model_totals['requests'] );
		$this->assertSame( 20, $model_totals['prompt_tokens'] );
		$this->assertSame( 10, $model_totals['completion_tokens'] );
		$this->assertSame( 30, $model_totals['total_tokens'] );
	}

	public function test_record_chat_usage_respects_filters() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 13;

		add_filter(
			'wp_mcp_ai_usage_snapshot',
			function ( $usage ) {
				$usage['prompt_tokens']     = 0;
				$usage['completion_tokens'] = 0;
				$usage['total_tokens']      = 0;

				return $usage;
			}
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 10,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, array(), $response );

		remove_all_filters( 'wp_mcp_ai_usage_snapshot' );

		$this->assertSame( array(), WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id ) );
	}

	public function test_record_chat_usage_uses_provider_defaults() {
		$user_id = self::factory()->user->create();

		$options = array(
			'provider' => 'gemini',
		);

		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 50,
				'completion_tokens' => 25,
			),
			'provider' => 'gemini',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, 0, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-1.5-flash', $totals['gemini'] );
	}

	public function test_record_chat_usage_requires_user_id() {
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 5,
				'completion_tokens' => 5,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( 0, 0, array(), $response );

		$this->assertSame( array(), WP_MCP_AI_Usage_Tracker::get_usage_for_user( 0 ) );
	}

	public function test_record_chat_usage_tracks_cached_tokens() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 21;

		$response = array(
			'usage'    => array(
				'cached_tokens' => 25,
			),
			'provider' => 'openai',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, array(), $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'openai', $totals );
		$this->assertNotEmpty( $totals['openai'] );

		$model_totals = reset( $totals['openai'] );

		$this->assertSame( 25, $model_totals['cached_tokens'] );
		$this->assertArrayHasKey( $assistant_id, $model_totals['assistants'] );
		$this->assertSame( 25, $model_totals['assistants'][ $assistant_id ]['cached_tokens'] );
	}

	public function test_gemini_usage_with_total_tokens_is_tracked() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 30;

		$options = array(
			'provider' => 'gemini',
			'model'    => 'gemini-1.5-flash',
		);

		// Simulating Gemini response with all three token fields.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 100,
				'completion_tokens' => 50,
				'total_tokens'      => 150,
			),
			'provider' => 'gemini',
			'model'    => 'gemini-1.5-flash',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-1.5-flash', $totals['gemini'] );

		$model_totals = $totals['gemini']['gemini-1.5-flash'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 100, $model_totals['prompt_tokens'] );
		$this->assertSame( 50, $model_totals['completion_tokens'] );
		$this->assertSame( 150, $model_totals['total_tokens'] );
	}

	public function test_ollama_usage_with_total_tokens_is_tracked() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 31;

		$options = array(
			'provider' => 'ollama',
			'model'    => 'llama3:latest',
		);

		// Simulating Ollama response with all three token fields.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 80,
				'completion_tokens' => 40,
				'total_tokens'      => 120,
			),
			'provider' => 'ollama',
			'model'    => 'llama3:latest',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'ollama', $totals );
		$this->assertArrayHasKey( 'llama3:latest', $totals['ollama'] );

		$model_totals = $totals['ollama']['llama3:latest'];

		$this->assertSame( 1, $model_totals['requests'] );
		$this->assertSame( 80, $model_totals['prompt_tokens'] );
		$this->assertSame( 40, $model_totals['completion_tokens'] );
		$this->assertSame( 120, $model_totals['total_tokens'] );
	}

	public function test_gemini_usage_auto_calculates_total_tokens_if_missing() {
		$user_id      = self::factory()->user->create();
		$assistant_id = 32;

		$options = array(
			'provider' => 'gemini',
			'model'    => 'gemini-2.0-flash',
		);

		// Test fallback: if total_tokens is missing, it should be calculated.
		$response = array(
			'usage'    => array(
				'prompt_tokens'     => 60,
				'completion_tokens' => 30,
			),
			'provider' => 'gemini',
			'model'    => 'gemini-2.0-flash',
		);

		WP_MCP_AI_Usage_Tracker::record_chat_usage( $user_id, $assistant_id, $options, $response );

		$totals = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );

		$this->assertArrayHasKey( 'gemini', $totals );
		$this->assertArrayHasKey( 'gemini-2.0-flash', $totals['gemini'] );

		$model_totals = $totals['gemini']['gemini-2.0-flash'];

		// The usage tracker should auto-calculate total_tokens as sum of prompt + completion.
		$this->assertSame( 90, $model_totals['total_tokens'] );
	}
}
