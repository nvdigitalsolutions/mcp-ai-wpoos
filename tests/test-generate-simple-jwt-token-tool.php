<?php
// phpcs:ignoreFile -- Test file intentionally hosts Simple JWT Login API
// stubs alongside the test class, mirroring third-party class/method names
// and using multiple namespaces so the suite works with and without the
// real plugin loaded.
/**
 * Generate Simple Jwt Token Tool
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace SimpleJWTLogin\Modules\Settings {
	if ( ! class_exists( __NAMESPACE__ . '\\AuthenticationSettings' ) ) {
		class AuthenticationSettings {
			const JWT_PAYLOAD_PARAM_EXP = 'exp';
		}
	}
}

namespace SimpleJWTLogin\Services {
	use SimpleJWTLogin\Modules\Settings\AuthenticationSettings as SettingsAuthenticationSettings;

	if ( ! class_exists( __NAMESPACE__ . '\\AuthenticateService' ) ) {
		class AuthenticateService {
			public static $next_payload = null;
			public static $last_call    = array();

			public static function generatePayload( array $claims, $wordpress_data, $settings, $user ) {
				self::$last_call = array(
					'claims'    => $claims,
					'user_id'   => $user instanceof \WP_User ? $user->ID : null,
					'settings'  => $settings,
					'wordpress' => $wordpress_data,
				);

				if ( null !== self::$next_payload ) {
					$payload            = self::$next_payload;
					self::$next_payload = null;

					return $payload;
				}

				$payload = array(
					'sub' => $user instanceof \WP_User ? 'user-' . $user->ID : 'user-0',
					SettingsAuthenticationSettings::JWT_PAYLOAD_PARAM_EXP => time() + 300,
				);

				foreach ( $claims as $key => $value ) {
					$payload[ $key ] = $value;
				}

				return $payload;
			}
		}
	}
}

namespace SimpleJWTLogin\Helpers\Jwt {
	if ( ! class_exists( __NAMESPACE__ . '\\JwtKeyFactory' ) ) {
		class JwtKeyFactory {
			public static $next_private_key = 'unit-test-private-key';
			public static $last_settings    = null;

			public static function getFactory( $settings ) {
				self::$last_settings = $settings;

				return new class() {
					public function getPrivateKey() {
						return \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::$next_private_key;
					}
				};
			}
		}
	}
}

namespace SimpleJWTLogin\Libraries\JWT {
	if ( ! class_exists( __NAMESPACE__ . '\\JWT' ) ) {
		class JWT {
			public static $next_token     = 'encoded-token';
			public static $last_payload   = null;
			public static $last_key       = null;
			public static $last_algorithm = null;

			public static function encode( $payload, $key, $algorithm ) {
				self::$last_payload   = $payload;
				self::$last_key       = $key;
				self::$last_algorithm = $algorithm;

				return self::$next_token;
			}
		}
	}
}

namespace {
	use SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory;
	use SimpleJWTLogin\Libraries\JWT\JWT;
	use SimpleJWTLogin\Modules\Settings\AuthenticationSettings as SettingsAuthenticationSettings;
	use SimpleJWTLogin\Modules\SimpleJWTLoginSettings;
	use SimpleJWTLogin\Services\AuthenticateService;

	/**
	 * Token generation tool tests.
	 *
	 * When the real Simple JWT Login plugin is loaded by the test bootstrap,
	 * the tool runs against the real plugin classes and the payload/token are
	 * pinned via the tool's own filters. Otherwise the namespace stubs above
	 * record the tool's calls for the assertions.
	 */
	class WP_MCP_AI_Generate_Simple_JWT_Token_Tool_Test extends WP_UnitTestCase {
		const REAL_PLUGIN_SETTINGS_OPTION = 'simple_jwt_login_settings';
		const REAL_PLUGIN_SIGNING_KEY     = 'unit-test-jwt-secret';

		/**
		 * Set up test environment.
		 */
		protected function setUp(): void {
			parent::setUp();

			if ( $this->uses_mock_service() ) {
				AuthenticateService::$next_payload = null;
				AuthenticateService::$last_call    = array();
				JwtKeyFactory::$next_private_key   = 'unit-test-private-key';
				JwtKeyFactory::$last_settings      = null;
				JWT::$next_token                   = 'encoded-token';
				JWT::$last_payload                 = null;
				JWT::$last_key                     = null;
				JWT::$last_algorithm               = null;
			}

			$this->reset_real_plugin_state();

			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_payload' );
			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_token' );
			remove_all_actions( 'wp_mcp_ai_simple_jwt_login_tool_token_generated' );
		}

		/**
		 * Tear down test environment.
		 */
		protected function tearDown(): void {
			wp_set_current_user( 0 );

			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_payload' );
			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_token' );

			$this->reset_real_plugin_state();

			parent::tearDown();
		}

		/**
		 * Ensure the token tool returns the encoded token unchanged.
		 */
		public function test_execute_returns_unmodified_token() {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );

			$token_string = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.Token+/=._~';
			$claims       = array( 'custom_scope' => 'manage_options' );
			$context      = array( 'assistant_id' => 42 );

			if ( $this->uses_mock_service() ) {
				JWT::$next_token = $token_string;
			} else {
				$this->configure_real_plugin();

				// The real plugin encodes a genuine token; pin the payload and
				// the token via the tool's own filters to keep the assertions
				// deterministic without touching production code.
				add_filter(
					'wp_mcp_ai_simple_jwt_login_tool_payload',
					static function () {
						return array(
							SettingsAuthenticationSettings::JWT_PAYLOAD_PARAM_EXP => time() + 300,
							'assistant_id' => 42,
							'custom_scope' => 'manage_options',
						);
					}
				);

				add_filter(
					'wp_mcp_ai_simple_jwt_login_tool_token',
					static function () use ( $token_string ) {
						return $token_string;
					}
				);
			}

			$tool   = new WP_MCP_AI_Tool_Generate_Simple_JWT_Token();
			$result = $tool->execute( array( 'claims' => $claims ), $context );

			$this->assertIsArray( $result );
			$this->assertSame( 'Bearer', $result['token_type'] );
			$this->assertSame( $token_string, $result['token'] );
			$this->assertSame( $user_id, $result['user_id'] );

			$this->assertArrayHasKey( SettingsAuthenticationSettings::JWT_PAYLOAD_PARAM_EXP, $result['payload'] );
			$expected_expiry = gmdate( 'c', $result['payload'][ SettingsAuthenticationSettings::JWT_PAYLOAD_PARAM_EXP ] );
			$this->assertSame( $expected_expiry, $result['expires_at'] );

			$this->assertArrayHasKey( 'assistant_id', $result['payload'] );
			$this->assertSame( 42, $result['payload']['assistant_id'] );
			$this->assertArrayHasKey( 'custom_scope', $result['payload'] );
			$this->assertSame( 'manage_options', $result['payload']['custom_scope'] );

			// The static recording hooks only exist on the stubs.
			if ( $this->uses_mock_service() ) {
				$this->assertSame( 'unit-test-private-key', JWT::$last_key );
				$this->assertSame( 'HS256', JWT::$last_algorithm );

				$this->assertArrayHasKey( 'claims', AuthenticateService::$last_call );
				$this->assertSame( 42, AuthenticateService::$last_call['claims']['assistant_id'] );
				$this->assertSame( 'manage_options', AuthenticateService::$last_call['claims']['custom_scope'] );
			}
		}

		/**
		 * Whether the lightweight service stubs are in effect.
		 *
		 * The test bootstrap loads the real Simple JWT Login plugin whenever
		 * it is installed, which disables the namespace stubs above. In that
		 * case the tool is exercised against the real plugin classes.
		 *
		 * @return bool
		 */
		protected function uses_mock_service() {
			return property_exists( AuthenticateService::class, 'next_payload' );
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
	}
}
