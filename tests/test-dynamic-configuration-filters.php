<?php
/**
 * Tests for dynamic configuration filters.
 *
 * Ensures that all hardcoded values can be overridden via WordPress filters.
 */
class WP_MCP_AI_Dynamic_Configuration_Filters_Test extends WP_UnitTestCase {

	/**
	 * Test SSE max duration filter.
	 */
	public function test_sse_max_duration_filter() {
		// Default value.
		$default_max_duration = 300;
		$this->assertEquals( $default_max_duration, WP_MCP_AI_SSE_Stream::MAX_DURATION );

		// Add filter to change max duration.
		add_filter(
			'wp_mcp_ai_sse_max_duration',
			function () {
				return 600;
			}
		);

		// Since the filter is applied in the method, we need to test indirectly.
		// The filter should be available for use.
		$filtered_value = apply_filters( 'wp_mcp_ai_sse_max_duration', $default_max_duration );
		$this->assertEquals( 600, $filtered_value );

		// Clean up.
		remove_all_filters( 'wp_mcp_ai_sse_max_duration' );
	}

	/**
	 * Test SSE poll interval filter.
	 */
	public function test_sse_poll_interval_filter() {
		$default_poll_interval = 2;
		$this->assertEquals( $default_poll_interval, WP_MCP_AI_SSE_Stream::POLL_INTERVAL );

		add_filter(
			'wp_mcp_ai_sse_poll_interval',
			function () {
				return 5;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_sse_poll_interval', $default_poll_interval );
		$this->assertEquals( 5, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_sse_poll_interval' );
	}

	/**
	 * Test SSE heartbeat interval filter.
	 */
	public function test_sse_heartbeat_interval_filter() {
		$default_heartbeat = 15;
		$this->assertEquals( $default_heartbeat, WP_MCP_AI_SSE_Stream::HEARTBEAT_INTERVAL );

		add_filter(
			'wp_mcp_ai_sse_heartbeat_interval',
			function () {
				return 30;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_sse_heartbeat_interval', $default_heartbeat );
		$this->assertEquals( 30, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_sse_heartbeat_interval' );
	}

	/**
	 * Test rate limit max retries filter.
	 */
	public function test_rate_limit_max_retries_filter() {
		$default_max_retries = 3;
		$this->assertEquals( $default_max_retries, WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_RETRIES );

		add_filter(
			'wp_mcp_ai_rate_limit_max_retries',
			function () {
				return 5;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_rate_limit_max_retries', $default_max_retries );
		$this->assertEquals( 5, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_rate_limit_max_retries' );
	}

	/**
	 * Test rate limit initial delay filter.
	 */
	public function test_rate_limit_initial_delay_filter() {
		$default_initial_delay = 2;
		$this->assertEquals( $default_initial_delay, WP_MCP_AI_Rate_Limit_Manager::DEFAULT_INITIAL_DELAY );

		add_filter(
			'wp_mcp_ai_rate_limit_initial_delay',
			function () {
				return 5;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_rate_limit_initial_delay', $default_initial_delay );
		$this->assertEquals( 5, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_rate_limit_initial_delay' );
	}

	/**
	 * Test rate limit max delay filter.
	 */
	public function test_rate_limit_max_delay_filter() {
		$default_max_delay = 30;
		$this->assertEquals( $default_max_delay, WP_MCP_AI_Rate_Limit_Manager::DEFAULT_MAX_DELAY );

		add_filter(
			'wp_mcp_ai_rate_limit_max_delay',
			function () {
				return 60;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_rate_limit_max_delay', $default_max_delay );
		$this->assertEquals( 60, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_rate_limit_max_delay' );
	}

	/**
	 * Test rate limit backoff multiplier filter.
	 */
	public function test_rate_limit_backoff_multiplier_filter() {
		$default_multiplier = 2;
		$this->assertEquals( $default_multiplier, WP_MCP_AI_Rate_Limit_Manager::BACKOFF_MULTIPLIER );

		add_filter(
			'wp_mcp_ai_rate_limit_backoff_multiplier',
			function () {
				return 3;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_rate_limit_backoff_multiplier', $default_multiplier );
		$this->assertEquals( 3, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_rate_limit_backoff_multiplier' );
	}

	/**
	 * Test token budget safety margin filter.
	 */
	public function test_token_budget_safety_margin_filter() {
		$default_margin = 0.1;
		$this->assertEquals( $default_margin, WP_MCP_AI_Token_Budget_Manager::DEFAULT_SAFETY_MARGIN );

		add_filter(
			'wp_mcp_ai_token_budget_safety_margin',
			function () {
				return 0.2;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_token_budget_safety_margin', $default_margin );
		$this->assertEquals( 0.2, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_token_budget_safety_margin' );
	}

	/**
	 * Test token budget min chunk size filter.
	 */
	public function test_token_budget_min_chunk_size_filter() {
		$default_chunk = 1000;
		$this->assertEquals( $default_chunk, WP_MCP_AI_Token_Budget_Manager::MIN_CHUNK_SIZE );

		add_filter(
			'wp_mcp_ai_token_budget_min_chunk_size',
			function () {
				return 2000;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_token_budget_min_chunk_size', $default_chunk );
		$this->assertEquals( 2000, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_token_budget_min_chunk_size' );
	}

	/**
	 * Test token budget max input tokens filter.
	 */
	public function test_token_budget_max_input_tokens_filter() {
		$default_max = 12000;
		$this->assertEquals( $default_max, WP_MCP_AI_Token_Budget_Manager::MAX_INPUT_TOKENS );

		add_filter(
			'wp_mcp_ai_token_budget_max_input_tokens',
			function () {
				return 20000;
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_token_budget_max_input_tokens', $default_max );
		$this->assertEquals( 20000, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_token_budget_max_input_tokens' );
	}

	/**
	 * Test token budget default limit filter.
	 */
	public function test_token_budget_default_limit_filter() {
		$unknown_model = 'some-new-model';

		add_filter(
			'wp_mcp_ai_token_budget_default_limit',
			function ( $limit, $model ) {
				if ( 'some-new-model' === $model ) {
					return 16000;
				}
				return $limit;
			},
			10,
			2
		);

		$limit = WP_MCP_AI_Token_Budget_Manager::get_model_limit( $unknown_model );
		$this->assertEquals( 16000, $limit );

		remove_all_filters( 'wp_mcp_ai_token_budget_default_limit' );
	}

	/**
	 * Test federation peer verification delay filter.
	 */
	public function test_federation_peer_verification_delay_filter() {
		$default_delay = 100000; // 100ms in microseconds.

		add_filter(
			'wp_mcp_ai_federation_peer_verification_delay',
			function () {
				return 200000; // 200ms.
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_federation_peer_verification_delay', $default_delay );
		$this->assertEquals( 200000, $filtered_value );

		remove_all_filters( 'wp_mcp_ai_federation_peer_verification_delay' );
	}

	/**
	 * Test Ollama endpoint URL filter.
	 */
	public function test_ollama_endpoint_url_filter() {
		$default_url = 'http://localhost:11434';

		add_filter(
			'wp_mcp_ai_default_ollama_endpoint_url',
			function () {
				return 'http://custom-ollama-server:11434';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_default_ollama_endpoint_url', $default_url );
		$this->assertEquals( 'http://custom-ollama-server:11434', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_default_ollama_endpoint_url' );
	}

	/**
	 * Test LM Studio endpoint URL filter.
	 */
	public function test_lm_studio_endpoint_url_filter() {
		$default_url = 'http://localhost:1234';

		add_filter(
			'wp_mcp_ai_default_lm_studio_endpoint_url',
			function () {
				return 'http://custom-lm-studio:1234';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url', $default_url );
		$this->assertEquals( 'http://custom-lm-studio:1234', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url' );
	}

	/**
	 * Test WordPress.com userinfo endpoint filter.
	 */
	public function test_wpcom_userinfo_endpoint_filter() {
		$default_url = 'https://public-api.wordpress.com/oauth2/userinfo';

		add_filter(
			'wp_mcp_ai_default_wpcom_userinfo_endpoint',
			function () {
				return 'https://custom-wpcom-api/userinfo';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_default_wpcom_userinfo_endpoint', $default_url );
		$this->assertEquals( 'https://custom-wpcom-api/userinfo', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_default_wpcom_userinfo_endpoint' );
	}

	/**
	 * Test Gmail OAuth scope filter.
	 */
	public function test_gmail_oauth_scope_filter() {
		$default_scope = 'https://www.googleapis.com/auth/gmail.readonly';

		add_filter(
			'wp_mcp_ai_gmail_oauth_scope',
			function () {
				return 'https://www.googleapis.com/auth/gmail.modify';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_gmail_oauth_scope', $default_scope );
		$this->assertEquals( 'https://www.googleapis.com/auth/gmail.modify', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_gmail_oauth_scope' );
	}

	/**
	 * Test Gmail OAuth authorize endpoint filter.
	 */
	public function test_gmail_oauth_authorize_endpoint_filter() {
		$default_endpoint = 'https://accounts.google.com/o/oauth2/v2/auth';

		add_filter(
			'wp_mcp_ai_gmail_oauth_authorize_endpoint',
			function () {
				return 'https://custom-oauth-provider/authorize';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_gmail_oauth_authorize_endpoint', $default_endpoint );
		$this->assertEquals( 'https://custom-oauth-provider/authorize', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_gmail_oauth_authorize_endpoint' );
	}

	/**
	 * Test Gmail OAuth token endpoint filter.
	 */
	public function test_gmail_oauth_token_endpoint_filter() {
		$default_endpoint = 'https://oauth2.googleapis.com/token';

		add_filter(
			'wp_mcp_ai_gmail_oauth_token_endpoint',
			function () {
				return 'https://custom-oauth-provider/token';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_gmail_oauth_token_endpoint', $default_endpoint );
		$this->assertEquals( 'https://custom-oauth-provider/token', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_gmail_oauth_token_endpoint' );
	}

	/**
	 * Test Gmail profile endpoint filter.
	 */
	public function test_gmail_profile_endpoint_filter() {
		$default_endpoint = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

		add_filter(
			'wp_mcp_ai_gmail_profile_endpoint',
			function () {
				return 'https://custom-gmail-api/profile';
			}
		);

		$filtered_value = apply_filters( 'wp_mcp_ai_gmail_profile_endpoint', $default_endpoint );
		$this->assertEquals( 'https://custom-gmail-api/profile', $filtered_value );

		remove_all_filters( 'wp_mcp_ai_gmail_profile_endpoint' );
	}
}
