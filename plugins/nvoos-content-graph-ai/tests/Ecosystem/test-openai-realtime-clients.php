<?php
/**
 * OpenAI Realtime clients port tests (Wave D2c).
 *
 * Characterization suite for the three ported realtime providers:
 * `OpenAiRealtimeClient`, `OpenAiRealtimeTranslateClient`, and
 * `OpenAiRealtimeWhisperClient`. Assertions mirror the base plugin's
 * realtime client tests: slugs/names/transport modes, availability
 * gating, settings-derived defaults, session payload shape, ephemeral
 * token minting + caching, unified SDP relay, and byte-identical error
 * codes.
 *
 * Matrix note: test subclasses pin `get_api_key()` and `get_settings()`
 * so behaviour is deterministic in both matrices; the real store-reading
 * paths are characterized separately in the mode-specific tests.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Provider\OpenAiRealtimeClient;
use NvoosContentGraphAi\Provider\OpenAiRealtimeTranslateClient;
use NvoosContentGraphAi\Provider\OpenAiRealtimeWhisperClient;

/**
 * Test double pinning key + settings for the realtime client.
 */
class Testable_Realtime_Client extends OpenAiRealtimeClient {

	/** @var array */
	public static $settings = array();

	public static function set_settings( array $settings ): void {
		self::$settings = $settings;
	}

	public function get_api_key() {
		return 'sk-realtime-test';
	}

	protected static function get_settings() {
		return self::$settings;
	}

	public function expose_session_payload( $assistant_id, $options = array() ) {
		return $this->build_session_payload( $assistant_id, $options );
	}

	public function expose_vad_config() {
		return $this->get_vad_config();
	}
}

/**
 * Test double pinning key + settings for the translate client.
 */
class Testable_Translate_Client extends OpenAiRealtimeTranslateClient {

	/** @var array */
	public static $settings = array();

	public static function set_settings( array $settings ): void {
		self::$settings = $settings;
	}

	public function get_api_key() {
		return 'sk-translate-test';
	}

	protected static function get_settings() {
		return self::$settings;
	}
}

/**
 * Test double pinning key + settings for the whisper client.
 */
class Testable_Whisper_Client extends OpenAiRealtimeWhisperClient {

	/** @var array */
	public static $settings = array();

	public static function set_settings( array $settings ): void {
		self::$settings = $settings;
	}

	public function get_api_key() {
		return 'sk-whisper-test';
	}

	protected static function get_settings() {
		return self::$settings;
	}
}

/**
 * @group provider
 */
class Test_OpenAi_Realtime_Clients extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
		\NvoosContentGraphAi\Adapter\CredentialResolver::clearCache();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		Testable_Realtime_Client::set_settings( array() );
		Testable_Translate_Client::set_settings( array() );
		Testable_Whisper_Client::set_settings( array() );

		\wp_set_current_user( 0 );
	}

	public function tearDown(): void {
		\remove_all_filters( 'pre_http_request' );
		\remove_all_filters( 'wp_mcp_ai_openai_realtime_available' );
		\remove_all_filters( 'wp_mcp_ai_openai_translate_available' );
		\remove_all_filters( 'wp_mcp_ai_openai_whisper_available' );
		\delete_transient( 'wp_mcp_ai_realtime_ephemeral_1_0' );

		\delete_option( 'nvoos_content_graph_settings' );
		\delete_option( 'wp_mcp_ai_settings' );
		\NvoosContentGraphAi\Adapter\CredentialResolver::clearCache();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			\WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		parent::tearDown();
	}

	/**
	 * Intercept the next HTTP request with a fixed response.
	 *
	 * @param mixed $response Response array or WP_Error to return.
	 */
	private function intercept_http( $response ): void {
		\add_filter(
			'pre_http_request',
			static function () use ( $response ) {
				return $response;
			},
			10
		);
	}

	// ─── Shared contract ───────────────────────────────────────────

	public function test_shared_contract_slugs_names_transports(): void {
		$realtime = new Testable_Realtime_Client();
		$translate = new Testable_Translate_Client();
		$whisper = new Testable_Whisper_Client();

		$this->assertSame( 'openai_realtime', $realtime->get_slug() );
		$this->assertSame( 'openai_realtime_translate', $translate->get_slug() );
		$this->assertSame( 'openai_realtime_whisper', $whisper->get_slug() );

		foreach ( array( $realtime, $translate, $whisper ) as $client ) {
			$this->assertSame( 'realtime', $client->get_transport_mode() );
			$this->assertNotEmpty( $client->get_name() );
		}
	}

	public function test_real_time_constants_byte_identical(): void {
		$this->assertSame( 'https://api.openai.com/v1/realtime/client_secrets', OpenAiRealtimeClient::CLIENT_SECRETS_ENDPOINT );
		$this->assertSame( 'https://api.openai.com/v1/realtime/calls', OpenAiRealtimeClient::CALLS_ENDPOINT );
		$this->assertSame( 'wss://api.openai.com/v1/realtime', OpenAiRealtimeClient::WEBSOCKET_BASE );
		$this->assertSame( 'gpt-realtime-2', OpenAiRealtimeClient::DEFAULT_MODEL );
		$this->assertSame( 50, OpenAiRealtimeClient::CACHE_TTL );
		$this->assertArrayHasKey( 'marin', OpenAiRealtimeClient::AVAILABLE_VOICES );
		$this->assertSame( 'low', OpenAiRealtimeClient::DEFAULT_REASONING_EFFORT );
		$this->assertArrayHasKey( 'xhigh', OpenAiRealtimeClient::REASONING_EFFORTS );
		$this->assertSame( 'https://api.openai.com/v1/realtime/translations', OpenAiRealtimeTranslateClient::TRANSLATION_ENDPOINT );
		$this->assertSame( 'gpt-realtime-translate', OpenAiRealtimeTranslateClient::DEFAULT_MODEL );
		$this->assertSame( 'gpt-realtime-whisper', OpenAiRealtimeWhisperClient::DEFAULT_MODEL );
		$this->assertSame( 1.0, OpenAiRealtimeWhisperClient::DEFAULT_LATENCY_DELAY );
	}

	public function test_availability_requires_key_and_provider_selection(): void {
		$realtime = new Testable_Realtime_Client();

		// Key present but provider not selected.
		$this->assertFalse( $realtime->is_available() );

		Testable_Realtime_Client::set_settings(
			array(
				'voice_mode'              => 'realtime',
				'voice_realtime_provider' => 'openai',
			)
		);
		$this->assertTrue( $realtime->is_available() );

		// Availability filter can force-disable.
		\add_filter( 'wp_mcp_ai_openai_realtime_available', '__return_false' );
		$this->assertFalse( $realtime->is_available() );
	}

	public function test_translate_and_whisper_availability_gating(): void {
		$translate = new Testable_Translate_Client();
		$whisper   = new Testable_Whisper_Client();

		$this->assertFalse( $translate->is_available() );
		$this->assertFalse( $whisper->is_available() );

		Testable_Translate_Client::set_settings(
			array(
				'voice_mode'              => 'realtime',
				'voice_realtime_provider' => 'openai_translate',
			)
		);
		Testable_Whisper_Client::set_settings(
			array(
				'voice_mode'              => 'realtime',
				'voice_realtime_provider' => 'openai_whisper',
			)
		);

		$this->assertTrue( $translate->is_available() );
		$this->assertTrue( $whisper->is_available() );
	}

	// ─── Realtime client ────────────────────────────────────────────

	public function test_realtime_settings_derived_defaults(): void {
		Testable_Realtime_Client::set_settings(
			array(
				'openai_realtime_model'  => 'gpt-realtime-1.5',
				'openai_realtime_voice'  => 'ash',
				'realtime_reasoning_effort' => 'high',
			)
		);

		$client = new Testable_Realtime_Client();

		$this->assertSame( 'gpt-realtime-1.5', $client->get_default_model() );
		$this->assertSame( 'ash', $client->get_default_voice() );
		$this->assertSame( 'high', $client->get_default_reasoning_effort() );
	}

	public function test_realtime_factory_defaults_without_settings(): void {
		$client = new Testable_Realtime_Client();

		$this->assertSame( 'gpt-realtime-2', $client->get_default_model() );
		$this->assertSame( 'marin', $client->get_default_voice() );
		$this->assertSame( 'low', $client->get_default_reasoning_effort() );
	}

	public function test_session_payload_shape(): void {
		$client = new Testable_Realtime_Client();

		$payload = $client->expose_session_payload(
			1,
			array(
				'model'           => 'gpt-realtime-2',
				'voice'           => 'coral',
				'reasoning_effort' => 'medium',
			)
		);

		$session = $payload['session'];

		$this->assertSame( 'realtime', $session['type'] );
		$this->assertSame( 'gpt-realtime-2', $session['model'] );
		$this->assertSame( array( 'audio', 'text' ), $session['output_modalities'] );
		$this->assertSame( 'coral', $session['audio']['output']['voice'] );
		$this->assertSame( 'audio/pcm', $session['audio']['input']['format']['type'] );
		$this->assertSame( 24000, $session['audio']['input']['format']['rate'] );
		$this->assertSame( 'medium', $session['reasoning']['effort'] );
		$this->assertSame( 'auto', $session['tool_choice'] );
		$this->assertTrue( $session['parallel_tool_calls'] );
		// Monolith installs resolve assistant tools from the base tool
		// registry (byte-identical); standalone installs return an empty
		// list until the assistant/tools surface is ported.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertIsArray( $session['tools'] );
		} else {
			$this->assertSame( array(), $session['tools'] );
		}
	}

	public function test_vad_config_from_settings_and_defaults(): void {
		$client = new Testable_Realtime_Client();

		// Defaults.
		$vad = $client->expose_vad_config();
		$this->assertSame( 'semantic_vad', $vad['type'] );
		$this->assertSame( 0.5, $vad['threshold'] );
		$this->assertSame( 700, $vad['silence_duration_ms'] );
		$this->assertSame( 300, $vad['prefix_padding_ms'] );

		// Disabled → type only.
		Testable_Realtime_Client::set_settings( array( 'enable_voice_activity_detection' => false ) );
		$vad = $client->expose_vad_config();
		$this->assertSame( array( 'type' => 'semantic_vad' ), $vad );
	}

	public function test_missing_key_errors_byte_identical(): void {
		// Real clients with no key configured anywhere.
		$realtime  = new OpenAiRealtimeClient();
		$translate = new OpenAiRealtimeTranslateClient();
		$whisper   = new OpenAiRealtimeWhisperClient();

		$result = $realtime->create_session( 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_realtime_no_key', $result->get_error_code() );

		$result = $translate->create_session( 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_translate_no_key', $result->get_error_code() );

		$result = $whisper->create_session( 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_whisper_no_key', $result->get_error_code() );

		$result = $realtime->create_ephemeral_token( 0 );
		// Key check fires first; when an environment supplies a key the
		// invalid-assistant guard is the next expected outcome.
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertContains(
			$result->get_error_code(),
			array( 'wp_mcp_ai_realtime_no_key', 'wp_mcp_ai_realtime_invalid_assistant' )
		);
	}

	public function test_invalid_assistant_guard(): void {
		$client = new Testable_Realtime_Client();

		$result = $client->create_ephemeral_token( 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_realtime_invalid_assistant', $result->get_error_code() );

		$result = $client->create_session( 0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_realtime_invalid_assistant', $result->get_error_code() );
	}

	public function test_ephemeral_token_mints_and_caches(): void {
		$calls = 0;
		\add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => \wp_json_encode(
						array(
							'id'            => 'sess_1',
							'expires_at'    => 1900000000,
							'client_secret' => array( 'value' => 'ek_abc' ),
						)
					),
				);
			},
			10
		);

		$client = new Testable_Realtime_Client();

		$token = $client->create_ephemeral_token( 1, array( 'voice' => 'sage' ) );

		$this->assertIsArray( $token );
		$this->assertSame( 'openai_realtime', $token['type'] );
		$this->assertSame( 'webrtc_ephemeral', $token['connection_method'] );
		$this->assertSame( 'sage', $token['voice'] );
		$this->assertSame( 'sess_1', $token['session_id'] );
		$this->assertSame( 'ek_abc', $token['client_secret']['value'] );
		$this->assertSame( 1, $calls );

		// Second call is served from the transient cache.
		$cached = $client->create_ephemeral_token( 1, array( 'voice' => 'sage' ) );
		$this->assertSame( 'ek_abc', $cached['client_secret']['value'] );
		$this->assertSame( 1, $calls );
	}

	public function test_ephemeral_token_http_error(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => \wp_json_encode( array( 'error' => array( 'message' => 'Invalid key' ) ) ),
			)
		);

		$client = new Testable_Realtime_Client();
		$result = $client->create_ephemeral_token( 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_realtime_token_failed', $result->get_error_code() );
		$this->assertSame( 'Invalid key', $result->get_error_message() );
	}

	public function test_create_session_defaults_to_ephemeral(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => \wp_json_encode(
					array(
						'id'            => 'sess_2',
						'client_secret' => array( 'value' => 'ek_def' ),
					)
				),
			)
		);

		$client = new Testable_Realtime_Client();
		$result = $client->create_session( 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 'webrtc_ephemeral', $result['connection_method'] );
	}

	public function test_create_session_webrtc_unified_relay(): void {
		$this->intercept_http(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'v=0\r\nsdp-answer-body',
			)
		);

		$client = new Testable_Realtime_Client();
		$result = $client->create_session(
			1,
			array(
				'connection_method' => 'webrtc_unified',
				'sdp_offer'         => 'v=0\r\noffer',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'webrtc_unified', $result['connection_method'] );
		$this->assertSame( 'v=0\r\nsdp-answer-body', $result['sdp_answer'] );
	}

	public function test_unified_session_multipart_body(): void {
		$captured = array();
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = array(
					'url'     => $url,
					'headers' => $args['headers'] ?? array(),
					'body'    => $args['body'] ?? '',
				);

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'sdp-answer',
				);
			},
			10,
			3
		);

		$client = new Testable_Realtime_Client();
		$client->create_unified_session( 'v=0 offer-body', 1 );

		$this->assertStringEndsWith( '/v1/realtime/calls', $captured['url'] );
		$this->assertStringStartsWith( 'multipart/form-data; boundary=', $captured['headers']['Content-Type'] );
		$this->assertStringContainsString( 'name="sdp"', $captured['body'] );
		$this->assertStringContainsString( 'Content-Type: application/sdp', $captured['body'] );
		$this->assertStringContainsString( 'v=0 offer-body', $captured['body'] );
		$this->assertStringContainsString( 'name="session"', $captured['body'] );
		$this->assertStringContainsString( 'Content-Type: application/json', $captured['body'] );
	}

	// ─── Translate client ───────────────────────────────────────────

	public function test_translate_language_tables(): void {
		$client = new Testable_Translate_Client();

		$this->assertArrayHasKey( 'en', $client->get_input_languages() );
		$this->assertArrayHasKey( 'zh-TW', $client->get_input_languages() );
		$this->assertCount( 13, $client->get_output_languages() );
		$this->assertSame( 'en', $client->get_default_input_language() );
		$this->assertSame( 'es', $client->get_default_output_language() );
		$this->assertSame( array(), $client->get_available_voices() );
		$this->assertSame( '', $client->get_default_voice() );
	}

	public function test_translate_create_session_shape(): void {
		Testable_Translate_Client::set_settings(
			array(
				'realtime_translate_input_lang'  => 'de',
				'realtime_translate_output_lang' => 'fr',
			)
		);

		$client = new Testable_Translate_Client();

		$session = $client->create_session( 1 );

		$this->assertSame( 'openai_translate', $session['type'] );
		$this->assertSame( 'realtime', $session['transport_mode'] );
		$this->assertSame( 'gpt-realtime-translate', $session['model'] );
		$this->assertSame( 'de', $session['input_language'] );
		$this->assertSame( 'fr', $session['output_language'] );
		$this->assertArrayHasKey( 'endpoint', $session );

		// Per-request overrides win over settings defaults.
		$session = $client->create_session(
			1,
			array(
				'input_language'  => 'it',
				'output_language' => 'ja',
			)
		);
		$this->assertSame( 'it', $session['input_language'] );
		$this->assertSame( 'ja', $session['output_language'] );
	}

	// ─── Whisper client ─────────────────────────────────────────────

	public function test_whisper_create_session_shape(): void {
		Testable_Whisper_Client::set_settings( array( 'realtime_whisper_latency_delay' => '0.4' ) );

		$client = new Testable_Whisper_Client();

		$this->assertSame( 0.4, $client->get_default_latency_delay() );

		$session = $client->create_session( 1 );
		$this->assertSame( 'openai_whisper', $session['type'] );
		$this->assertSame( 'realtime', $session['transport_mode'] );
		$this->assertSame( 'gpt-realtime-whisper', $session['model'] );
		$this->assertSame( 0.4, $session['latency_delay'] );

		// Default latency when no setting.
		Testable_Whisper_Client::set_settings( array() );
		$this->assertSame( 1.0, $client->get_default_latency_delay() );

		// Per-request override.
		$session = $client->create_session( 1, array( 'latency_delay' => '2.5' ) );
		$this->assertSame( 2.5, $session['latency_delay'] );

		$this->assertSame( array(), $client->get_available_voices() );
		$this->assertSame( '', $client->get_default_voice() );
	}
}
