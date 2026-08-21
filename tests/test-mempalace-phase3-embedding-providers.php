<?php
/**
 * Tests for the pluggable embedding provider system (Phase 3).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

/**
 * Test case for Phase 3 embedding providers.
 *
 * @since 1.1.0
 */
class Test_MemPalace_Phase3_Embedding_Providers extends WP_UnitTestCase {

	/**
	 * Original settings snapshot, restored in tearDown.
	 *
	 * @var array
	 */
	private $original_settings = array();

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider();
	}

	/**
	 * Tear down: clear cache + restore settings + reset provider.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_embed_' ) . '%'
			)
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $this->original_settings );
		WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider();
		remove_all_filters( 'wp_mcp_ai_embedding_provider' );
		remove_all_filters( 'wp_mcp_ai_embedding_provider_openai_model' );
		remove_all_filters( 'wp_mcp_ai_embedding_provider_ollama_model' );
		remove_all_filters( 'wp_mcp_ai_embedding_provider_ollama_endpoint' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// --- Interface + provider classes ---

	/**
	 * The interface and both providers should load.
	 */
	public function test_interface_and_providers_loaded() {
		$this->assertTrue( interface_exists( 'WP_MCP_AI_Embedding_Provider_Interface' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedding_Provider_OpenAI' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedding_Provider_Ollama' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Embedding_Provider_Gemini' ) );

		$openai = new WP_MCP_AI_Embedding_Provider_OpenAI();
		$ollama = new WP_MCP_AI_Embedding_Provider_Ollama();
		$gemini = new WP_MCP_AI_Embedding_Provider_Gemini();

		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Interface', $openai );
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Interface', $ollama );
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Interface', $gemini );
		$this->assertSame( 'openai', $openai->get_id() );
		$this->assertSame( 'ollama', $ollama->get_id() );
		$this->assertSame( 'gemini', $gemini->get_id() );
		$this->assertSame( 'gemini-embedding-001', $gemini->get_model() );
	}

	// --- Default resolver ---

	/**
	 * With no settings configured, the resolver should return a WP_Error.
	 */
	public function test_default_resolver_no_provider_returns_wp_error() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_Error', $provider );
		$this->assertSame( 'no_embedding_provider', $provider->get_error_code() );
	}

	/**
	 * Ollama is auto-selected when only an Ollama endpoint is configured.
	 */
	public function test_default_resolver_picks_ollama_when_only_ollama_configured() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url' => 'http://localhost:11434',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Ollama', $provider );
	}

	/**
	 * OpenAI wins when both backends are configured (preserves prior behaviour).
	 */
	public function test_default_resolver_prefers_openai_when_both_configured() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key'      => 'sk-test',
				'ollama_endpoint_url' => 'http://localhost:11434',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_OpenAI', $provider );
	}

	/**
	 * Explicit `embedding_provider` setting overrides the auto-detect.
	 */
	public function test_default_resolver_honours_explicit_preference() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key'      => 'sk-test',
				'ollama_endpoint_url' => 'http://localhost:11434',
				'embedding_provider'  => 'ollama',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Ollama', $provider );
	}

	/**
	 * Gemini is auto-selected when only a Gemini key is configured.
	 */
	public function test_default_resolver_picks_gemini_when_only_gemini_configured() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'gemini_api_key' => 'gemini-test-key' )
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Gemini', $provider );
	}

	/**
	 * Auto-detect prefers Ollama over Gemini (local-first fallback ordering).
	 */
	public function test_default_resolver_prefers_ollama_over_gemini() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'ollama_endpoint_url' => 'http://localhost:11434',
				'gemini_api_key'      => 'gemini-test-key',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Ollama', $provider );
	}

	/**
	 * Auto-detect prefers Gemini over DigitalOcean.
	 */
	public function test_default_resolver_prefers_gemini_over_digitalocean() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'digitalocean_api_key' => 'do-test-key',
				'gemini_api_key'       => 'gemini-test-key',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Gemini', $provider );
	}

	/**
	 * Explicit `embedding_provider: gemini` is honoured when configured.
	 */
	public function test_default_resolver_honours_gemini_preference() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'openai_api_key'     => 'sk-test',
				'gemini_api_key'     => 'gemini-test-key',
				'embedding_provider' => 'gemini',
			)
		);
		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Gemini', $provider );
	}

	// --- Filter override ---

	/**
	 * The `wp_mcp_ai_embedding_provider` filter should win over auto-detect.
	 */
	public function test_filter_can_override_default_provider() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'openai_api_key' => 'sk-test' )
		);
		add_filter(
			'wp_mcp_ai_embedding_provider',
			static function () {
				return new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434' );
			}
		);

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$service->reset_embedding_provider();

		$provider = $service->get_embedding_provider();
		$this->assertInstanceOf( 'WP_MCP_AI_Embedding_Provider_Ollama', $provider );
	}

	// --- Cache key disambiguation ---

	/**
	 * Switching providers must not return a cached vector keyed for the
	 * previous provider.
	 */
	public function test_cache_key_disambiguates_by_provider() {
		// Stub HTTP for both endpoints.
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '11434' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'embedding' => array( 0.1, 0.2, 0.3 ) ) ),
					);
				}
				return $preempt;
			},
			10,
			3
		);

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();

		// First call via Ollama.
		add_filter(
			'wp_mcp_ai_embedding_provider',
			static function () {
				return new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434', 'm1' );
			}
		);
		$service->reset_embedding_provider();
		$ollama_vec = $service->embed_context( 'hello world' );
		$this->assertIsArray( $ollama_vec );
		$this->assertSame( array( 0.1, 0.2, 0.3 ), $ollama_vec );

		// Now swap the filter for a different provider and assert we don't
		// get the cached Ollama vector.
		remove_all_filters( 'wp_mcp_ai_embedding_provider' );
		$called = false;
		add_filter(
			'wp_mcp_ai_embedding_provider',
			function () use ( &$called ) {
				$called = true;
				return new class() implements WP_MCP_AI_Embedding_Provider_Interface {
					/** Provider id. */
					public function get_id() {
						return 'fake';
					}
					/** Model id. */
					public function get_model() {
						return 'fake-model';
					}
					/** Availability flag. */
					public function is_available() {
						return true;
					}
					/**
					 * Generate a stub embedding.
					 *
					 * @param string $text Input text.
					 * @return array
					 */
					public function embed( $text ) {
						return array( 9.0, 9.0, 9.0 );
					}
				};
			}
		);
		$service->reset_embedding_provider();
		$fake_vec = $service->embed_context( 'hello world' );

		$this->assertTrue( $called, 'Provider filter should be re-invoked after reset' );
		$this->assertSame( array( 9.0, 9.0, 9.0 ), $fake_vec, 'Cache must be scoped per-provider' );
	}

	// --- Ollama provider HTTP behaviour ---

	/**
	 * Ollama provider should parse the modern `embedding` key.
	 */
	public function test_ollama_provider_parses_embedding_response() {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/api/embeddings' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'embedding' => array( 0.5, 0.6 ) ) ),
				);
			},
			10,
			3
		);

		$provider = new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434', 'nomic-embed-text' );
		$vec      = $provider->embed( 'hello' );

		$this->assertIsArray( $vec );
		$this->assertSame( array( 0.5, 0.6 ), $vec );
	}

	/**
	 * Ollama provider should also accept the plural `embeddings[0]` form.
	 */
	public function test_ollama_provider_accepts_embeddings_array_form() {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/api/embeddings' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'embeddings' => array( array( 1.0, 2.0 ) ) ) ),
				);
			},
			10,
			3
		);

		$provider = new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434' );
		$vec      = $provider->embed( 'hello' );

		$this->assertIsArray( $vec );
		$this->assertSame( array( 1.0, 2.0 ), $vec );
	}

	/**
	 * Non-2xx responses should surface a structured error.
	 */
	public function test_ollama_provider_handles_http_errors() {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( $url, '/api/embeddings' ) ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 500 ),
					'body'     => 'oops',
				);
			},
			10,
			3
		);

		$provider = new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434' );
		$result   = $provider->embed( 'hello' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'ollama_http_error', $result->get_error_code() );
	}

	/**
	 * Empty input should return a structured error without making a request.
	 */
	public function test_ollama_provider_rejects_empty_text() {
		$provider = new WP_MCP_AI_Embedding_Provider_Ollama( 'http://localhost:11434' );
		$result   = $provider->embed( '' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'empty_text', $result->get_error_code() );
	}

	/**
	 * Is_available should be false when no endpoint is configured.
	 */
	public function test_ollama_provider_unavailable_without_endpoint() {
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$provider = new WP_MCP_AI_Embedding_Provider_Ollama();
		$this->assertFalse( $provider->is_available() );
	}
}
