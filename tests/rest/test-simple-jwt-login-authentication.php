<?php
/**
 * tests/rest/test-simple-jwt-login-authentication.php
 *
 * @package WP_MCP_AI
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
	class ServerHelper {
		public function __construct( array $server ) {
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
	use SimpleJWTLogin\Services\ValidateTokenService;

	class WP_MCP_AI_REST_Simple_JWT_Login_Integration_Test extends WP_UnitTestCase {
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

		protected function setUp(): void {
			parent::setUp();

			remove_filter( 'pre_option_active_plugins', array( $this, 'filter_active_plugins' ), 10 );
			$this->simulate_plugin_active = false;

			wp_set_current_user( 0 );
			ValidateTokenService::reset();

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

		protected function tearDown(): void {
			remove_filter( 'pre_option_active_plugins', array( $this, 'filter_active_plugins' ), 10 );
			$this->simulate_plugin_active = false;

			wp_set_current_user( 0 );

			remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
			remove_all_filters( 'wp_mcp_ai_map_bearer_to_user_id' );

			delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

			ValidateTokenService::reset();

			parent::tearDown();
		}

		/**
		 * Ensure Simple JWT Login tokens grant REST access when the integration is enabled.
		 */
		public function test_permissions_check_accepts_valid_simple_jwt_login_token() {
			$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );

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

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer valid-simple-jwt' );

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
			ValidateTokenService::$next_exception = new \Exception( 'Token expired', 401 );

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer expired-simple-jwt' );

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
			ValidateTokenService::$next_response = 'malformed-response';

			$this->enable_simple_jwt_login_integration();

			$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
			$request->set_header( 'Authorization', 'Bearer malformed-simple-jwt' );

			$result = $this->rest_controller->permissions_check( $request );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'wp_mcp_ai_simple_jwt_login_unexpected_response', $result->get_error_code() );
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
	}
}
