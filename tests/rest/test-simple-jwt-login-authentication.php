<?php
// phpcs:ignoreFile -- Test file intentionally hosts Simple JWT Login API
// stubs alongside the test class, mirroring third-party class/method names
// and using multiple namespaces so the suite works with and without the
// real plugin loaded.
/**
 * Simple Jwt Login Authentication
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace SimpleJWTLogin\Modules {
	if ( ! class_exists( __NAMESPACE__ . '\\WordPressData' ) ) {
		class WordPressData {
			public function getUserMeta( $user_id, $key ) {
				return array();
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\AuthenticationSettings' ) ) {
		class AuthenticationSettings {
			public function isAuthenticationEnabled() {
				return true;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\GeneralSettings' ) ) {
		class GeneralSettings {
			public function getRequestKeyHeader() {
				return SimpleJWTLoginSettings::$request_key_header;
			}

			public function getJWTDecryptAlgorithm() {
				return SimpleJWTLoginSettings::$jwt_decrypt_algorithm;
			}
		}
	}

	if ( ! class_exists( __NAMESPACE__ . '\\SimpleJWTLoginSettings' ) ) {
		class SimpleJWTLoginSettings {
			public static $request_key_header    = 'Authorization';
			public static $jwt_decrypt_algorithm = 'HS256';

			/**
			 * Constructor.
			 */
			public function __construct( WordPressData $wordpress_data ) {
			}

			public function getGeneralSettings() {
				return new GeneralSettings();
			}

			public function getAuthenticationSettings() {
				return new AuthenticationSettings();
			}
		}
	}
}

namespace SimpleJWTLogin\Helpers {
	if ( ! class_exists( __NAMESPACE__ . '\\ServerHelper' ) ) {
		class ServerHelper {
			/**
			 * Constructor.
			 */
			public function __construct( array $server ) {
			}
		}
	}
}

namespace SimpleJWTLogin\Services {
	if ( ! class_exists( __NAMESPACE__ . '\\ValidateTokenService' ) ) {
		class ValidateTokenService {
			public static $next_response  = null;
			public static $next_exception = null;

			public function withSettings( $settings ) {
				return $this;
			}

			public function withServerHelper( $helper ) {
				return $this;
			}

			public function withRequestMethod( $method ) {
				return $this;
			}

			public function withRequest( $request ) {
				return $this;
			}

			public function withCookies( $cookies ) {
				return $this;
			}

			public function withSession( $session ) {
				return $this;
			}

			public function makeAction() {
				if ( self::$next_exception instanceof \Exception ) {
					$exception = self::$next_exception;
					self::reset();
					throw $exception;
				}

				$response = self::$next_response;
				self::reset();

				return $response;
			}

			public static function reset() {
				self::$next_response  = null;
				self::$next_exception = null;
			}
		}
	}
}

namespace {
	use SimpleJWTLogin\Modules\SimpleJWTLoginSettings;
	use SimpleJWTLogin\Services\ValidateTokenService;

	/**
	 * REST authentication integration tests for the Simple JWT Login bridge.
	 *
	 * When the real plugin is loaded by the test bootstrap these tests mint
	 * genuine HS256 tokens and validate end-to-end; otherwise the namespace
	 * stubs above drive the same assertions.
	 */
	class WP_MCP_AI_REST_Simple_JWT_Login_Integration_Test extends WP_UnitTestCase {
		const REAL_PLUGIN_SETTINGS_OPTION = 'simple_jwt_login_settings';
		const REAL_PLUGIN_SIGNING_KEY     = 'unit-test-jwt-secret';

		/**
		 * REST controller under test.
		 *
		 * @var WP_MCP_AI_REST
		 */
		protected $rest_controller;

		/**
		 * Integration instance.
		 *
		 * @var WP_MCP_AI_Simple_JWT_Login_Integration
		 */
		protected $integration;

		/**
		 * Whether the Simple JWT Login plugin should appear active.
		 *
		 * @var bool
		 */
		protected $simulate_plugin_active = false;

		/**
		 * Set up test environment.
		 */
		protected function setUp(): void {
			parent::setUp();

			remove_filter( 'pre_option_active_plugins', array( $this, 'filter_active_plugins' ), 10 );
			$this->simulate_plugin_active = false;

			wp_set_current_user( 0 );

			if ( $this->uses_mock_service() ) {
				ValidateTokenService::reset();
			}

			$this->reset_real_plugin_state();

			delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
			remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
			remove_all_filters( 'wp_mcp_ai_map_bearer_to_user_id' );

			if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
				remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
			}

			$registry    = WP_MCP_AI_Tool_Registry::get_instance();
			$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
				->disableOriginalConstructor()
				->getMock();

			$this->rest_controller = new WP_MCP_AI_REST( $registry, $mock_client );

			$this->reset_integration_singleton();
			$this->integration = WP_MCP_AI_Simple_JWT_Login_Integration::init();
		}

		/**
		 * Tear down test environment.
		 */
		protected function tearDown(): void {
			remove_filter( 'pre_option_active_plugins', array( $this, 'filter_active_plugins' ), 10 );
			$this->simulate_plugin_active = false;

			wp_set_current_user( 0 );

			remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
			remove_all_filters( 'wp_mcp_ai_map_bearer_to_user_id' );

			delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

			$this->reset_real_plugin_state();

			parent::tearDown();
		}

		/**
		 * Ensure Simple JWT Login tokens grant REST access when the integration is enabled.
		 */
		public function test_permissions_check_accepts_valid_simple_jwt_login_token() {
			$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

			if ( $this->uses_mock_service() ) {
				ValidateTokenService::$next_response = array(
					'data' => array(
						'user' => array( 'ID' => $user_id ),
						'jwt'  => array(
							array(
								'payload' => array(
									'sub' => 'user-' . $user_id,
									'exp' => time() + 300,
								),
							),
						),
					),
				);

				$token = 'valid-simple-jwt';
			} else {
				$this->configure_real_plugin();
				$token = $this->mint_real_token( $user_id, time() + 300 );
			}

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer ' . $token );

			$result = $this->rest_controller->permissions_check( $request );

			$this->assertTrue( $result );

			$context = $this->get_auth_context();

			$this->assertTrue( $context['token_authenticated'] );
			$this->assertSame( 'bearer', $context['token_type'] );
			$this->assertSame( $user_id, $context['user_id'] );
			$this->assertSame( $user_id, get_current_user_id() );
		}

		/**
		 * Ensure expired Simple JWT Login tokens surface actionable REST errors.
		 */
		public function test_permissions_check_rejects_expired_simple_jwt_login_token() {
			if ( $this->uses_mock_service() ) {
				ValidateTokenService::$next_exception = new \Exception( 'Token expired', 401 );

				$token = 'expired-simple-jwt';
			} else {
				$this->configure_real_plugin();

				$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
				$token   = $this->mint_real_token( $user_id, time() - 3600 );
			}

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer ' . $token );

			$result = $this->rest_controller->permissions_check( $request );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wp_mcp_ai_simple_jwt_login_invalid_token', $result->get_error_code() );

			$context = $this->get_auth_context();
			$this->assertFalse( $context['token_authenticated'] );
			$this->assertSame( 0, $context['user_id'] );
		}

		/**
		 * Ensure malformed Simple JWT Login responses do not grant access.
		 */
		public function test_permissions_check_rejects_malformed_simple_jwt_login_response() {
			if ( $this->uses_mock_service() ) {
				ValidateTokenService::$next_response = 'malformed-response';
			} else {
				$this->configure_real_plugin();
			}

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer malformed-simple-jwt' );

			$result = $this->rest_controller->permissions_check( $request );

			$this->assertInstanceOf( WP_Error::class, $result );

			// The stub returns a malformed response body; the real plugin
			// throws while parsing the garbage token. Both must be denied.
			if ( $this->uses_mock_service() ) {
				$this->assertSame( 'wp_mcp_ai_simple_jwt_login_unexpected_response', $result->get_error_code() );
			} else {
				$this->assertSame( 'wp_mcp_ai_simple_jwt_login_invalid_token', $result->get_error_code() );
			}
		}

		/**
		 * When the integration is disabled, bearer token validation falls back to Auth0 logic.
		 */
		public function test_permissions_check_requires_standard_bearer_when_integration_disabled() {
			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer not-a-valid-jwt' );

			$result = $this->rest_controller->permissions_check( $request );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wp_mcp_ai_invalid_bearer_token', $result->get_error_code() );
		}

		/**
		 * Activate the integration and register its filters.
		 */
		protected function enable_simple_jwt_login_integration() {
			$this->simulate_plugin_active = true;
			add_filter( 'pre_option_active_plugins', array( $this, 'filter_active_plugins' ), 10 );

			update_option(
				WP_MCP_AI_Admin_Settings::OPTION_NAME,
				array( 'enable_simple_jwt_login' => 1 )
			);

			$this->integration->maybe_bootstrap();
		}

		/**
		 * Mock Simple JWT Login as an active plugin.
		 *
		 * @return array
		 */
		public function filter_active_plugins() {
			if ( ! $this->simulate_plugin_active ) {
				return array();
			}

			return array( WP_MCP_AI_Simple_JWT_Login_Integration::PLUGIN_FILE );
		}

		/**
		 * Reset the integration singleton between tests.
		 */
		protected function reset_integration_singleton() {
			$reflection = new \ReflectionClass( WP_MCP_AI_Simple_JWT_Login_Integration::class );

			if ( $reflection->hasProperty( 'instance' ) ) {
				$property = $reflection->getProperty( 'instance' );
				$property->setAccessible( true );
				$property->setValue( null, null );
			}
		}

		/**
		 * Retrieve the protected authentication context for assertions.
		 *
		 * @return array
		 */
		protected function get_auth_context() {
			$method = new \ReflectionMethod( WP_MCP_AI_REST::class, 'get_auth_context' );
			$method->setAccessible( true );

			return $method->invoke( $this->rest_controller );
		}

		/**
		 * Whether the lightweight ValidateTokenService stub is in effect.
		 *
		 * The test bootstrap loads the real Simple JWT Login plugin whenever
		 * it is installed. In that case the namespace stubs at the top of this
		 * file are skipped and the real plugin classes take over; the tests
		 * then exercise the real plugin end-to-end.
		 *
		 * @return bool
		 */
		protected function uses_mock_service() {
			return property_exists( ValidateTokenService::class, 'next_response' );
		}

		/**
		 * Configure the real Simple JWT Login plugin with a HS256 signing key.
		 */
		protected function configure_real_plugin() {
			update_option(
				self::REAL_PLUGIN_SETTINGS_OPTION,
				wp_json_encode(
					array(
						'allow_authentication'   => 1,
						'jwt_payload'            => array( 'email', 'id', 'username', 'iat', 'exp' ),
						'jwt_auth_ttl'           => 60,
						'jwt_auth_refresh_ttl'   => 20160,
						'jwt_algorithm'          => 'HS256',
						'decryption_source'      => '0',
						'decryption_key'         => self::REAL_PLUGIN_SIGNING_KEY,
						'request_jwt_header'     => 1,
						'request_jwt_url'        => 0,
						'request_jwt_cookie'     => 0,
						'request_jwt_session'    => 0,
						'request_keys'           => array( 'header' => 'Authorization' ),
						'jwt_login_by'           => 1,
						'jwt_login_by_parameter' => 'id',
					)
				)
			);
		}

		/**
		 * Remove real-plugin settings state between tests.
		 */
		protected function reset_real_plugin_state() {
			delete_option( self::REAL_PLUGIN_SETTINGS_OPTION );

			if ( ! class_exists( SimpleJWTLoginSettings::class ) ) {
				return;
			}

			// The plugin caches its settings parsers in a private static array
			// keyed by type; clear it so each test reads fresh settings.
			$reflection = new \ReflectionClass( SimpleJWTLoginSettings::class );
			if ( $reflection->hasProperty( 'settingsInstances' ) ) {
				$property = $reflection->getProperty( 'settingsInstances' );
				$property->setAccessible( true );
				$property->setValue( null, array() );
			}
		}

		/**
		 * Mint a real HS256 token using the plugin's own JWT library.
		 *
		 * @param int $user_id WordPress user identifier to embed.
		 * @param int $expires Token expiry timestamp.
		 * @return string
		 */
		protected function mint_real_token( $user_id, $expires ) {
			return \SimpleJWTLogin\Libraries\JWT\JWT::encode(
				array(
					'id'  => absint( $user_id ),
					'iat' => time(),
					'exp' => $expires,
				),
				self::REAL_PLUGIN_SIGNING_KEY,
				'HS256'
			);
		}
	}
}
