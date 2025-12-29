<?php
/**
 * Generate Simple Jwt Token Tool
 *
 * @package WP_MCP_AI
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
	use SimpleJWTLogin\Services\AuthenticateService;

	class WP_MCP_AI_Generate_Simple_JWT_Token_Tool_Test extends WP_UnitTestCase {
		/**
		 * Set up test environment.
		 */
		protected function setUp(): void {
			parent::setUp();

			AuthenticateService::$next_payload = null;
			AuthenticateService::$last_call    = array();
			JwtKeyFactory::$next_private_key   = 'unit-test-private-key';
			JwtKeyFactory::$last_settings      = null;
			JWT::$next_token                   = 'encoded-token';
			JWT::$last_payload                 = null;
			JWT::$last_key                     = null;
			JWT::$last_algorithm               = null;

			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_payload' );
			remove_all_filters( 'wp_mcp_ai_simple_jwt_login_tool_token' );
			remove_all_actions( 'wp_mcp_ai_simple_jwt_login_tool_token_generated' );
		}

		/**
		 * Tear down test environment.
		 */
		protected function tearDown(): void {
			wp_set_current_user( 0 );

			parent::tearDown();
		}

		public function test_execute_returns_unmodified_token() {
			$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user_id );

			$token_string    = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.Token+/=._~';
			JWT::$next_token = $token_string;
			$tool            = new WP_MCP_AI_Tool_Generate_Simple_JWT_Token();
			$claims          = array( 'custom_scope' => 'manage_options' );
			$context         = array( 'assistant_id' => 42 );

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

			$this->assertSame( 'unit-test-private-key', JWT::$last_key );
			$this->assertSame( 'HS256', JWT::$last_algorithm );

			$this->assertArrayHasKey( 'claims', AuthenticateService::$last_call );
			$this->assertSame( 42, AuthenticateService::$last_call['claims']['assistant_id'] );
			$this->assertSame( 'manage_options', AuthenticateService::$last_call['claims']['custom_scope'] );
		}
	}
}
