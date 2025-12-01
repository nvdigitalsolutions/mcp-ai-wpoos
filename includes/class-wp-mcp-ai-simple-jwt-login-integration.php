<?php
/**
 * Simple JWT Login integration helpers for WP oOS.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Simple_JWT_Login_Integration' ) ) {

	/**
	 * Bootstraps the Simple JWT Login integration when available.
	 */
	class WP_MCP_AI_Simple_JWT_Login_Integration {
		const PLUGIN_FILE = 'simple-jwt-login/simple-jwt-login.php';

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Simple_JWT_Login_Integration|null
		 */
		protected static $instance = null;

		/**
		 * Cached user identifier from the last validated token.
		 *
		 * @var int|null
		 */
		protected $last_validated_user_id = null;

		/**
		 * Cached payload from the last validated token.
		 *
		 * @var array|null
		 */
		protected $last_payload = null;

		/**
		 * Initialise the integration singleton.
		 */
		public static function init() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register the plugins_loaded hook.
		 */
		private function __construct() {
			add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ), 40 );
		}

		/**
		 * Register filters when the dependency and setting are available.
		 */
		public function maybe_bootstrap() {
			if ( ! $this->is_enabled() ) {
				return;
			}

			if ( ! class_exists( '\SimpleJWTLogin\Services\ValidateTokenService' ) ) {
				return;
			}

			add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $this, 'pre_validate_bearer_token' ), 10, 3 );
			add_filter( 'wp_mcp_ai_map_bearer_to_user_id', array( $this, 'map_bearer_to_user_id' ), 10, 3 );
		}

		/**
		 * Determine whether the integration should be active.
		 *
		 * @return bool
		 */
		protected function is_enabled() {
			if ( ! $this->is_dependency_active() ) {
				return false;
			}

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			return ! empty( $settings['enable_simple_jwt_login'] );
		}

		/**
		 * Check whether the Simple JWT Login plugin is active.
		 *
		 * @return bool
		 */
		protected function is_dependency_active() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE ) ) {
				return true;
			}

			if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::PLUGIN_FILE ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Attempt to validate the bearer token via Simple JWT Login.
		 *
		 * @param null|bool|WP_Error $pre     Existing validation result.
		 * @param string             $token   Raw bearer token.
		 * @param WP_REST_Request    $request Current REST request.
		 * @return true|WP_Error|null
		 */
		public function pre_validate_bearer_token( $pre, $token, $request ) {
			if ( null !== $pre ) {
				return $pre;
			}

			if ( ! $this->is_enabled() ) {
				return $pre;
			}

			$this->last_validated_user_id = null;
			$this->last_payload           = null;

			if ( empty( $token ) ) {
				return $pre;
			}

			$result = $this->validate_simple_jwt_login_token( $token );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( true === $result ) {
				return true;
			}

			return $pre;
		}

		/**
		 * Map the previously validated token to a WordPress user.
		 *
		 * @param int|null        $mapped   Currently mapped user identifier.
		 * @param array|null      $payload  Token payload when available.
		 * @param WP_REST_Request $request  Current REST request.
		 * @return int|null|WP_Error
		 */
		public function map_bearer_to_user_id( $mapped, $payload, $request ) {
			if ( $mapped instanceof WP_Error ) {
				return $mapped;
			}

			if ( null !== $mapped ) {
				return $mapped;
			}

			if ( ! $this->is_enabled() ) {
				return $mapped;
			}

			if ( null !== $this->last_validated_user_id ) {
				return $this->last_validated_user_id;
			}

			return $mapped;
		}

		/**
		 * Validate the token using Simple JWT Login services.
		 *
		 * @param string $token Raw bearer token.
		 * @return true|WP_Error|null
		 */
		protected function validate_simple_jwt_login_token( $token ) {
			if ( ! class_exists( '\SimpleJWTLogin\Modules\SimpleJWTLoginSettings' ) || ! class_exists( '\SimpleJWTLogin\Modules\WordPressData' ) ) {
				return null;
			}

			try {
				$wordpress_data = new \SimpleJWTLogin\Modules\WordPressData();
				$settings       = new \SimpleJWTLogin\Modules\SimpleJWTLoginSettings( $wordpress_data );
			} catch ( \Exception $exception ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_login_configuration',
					__( 'Simple JWT Login could not be initialised. Check the plugin configuration and try again.', 'wp-mcp-ai' ),
					array(
						'status'  => 500,
						'details' => array(
							'message' => wp_strip_all_tags( $exception->getMessage() ),
							'code'    => $exception->getCode(),
						),
					)
				);
			}

			$server                = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();
			$header_name           = $settings->getGeneralSettings()->getRequestKeyHeader();
			$server_key            = $this->normalise_header_key( $header_name );
			$server[ $server_key ] = 'Bearer ' . $token;

			if ( empty( $server['REQUEST_METHOD'] ) ) {
				$server['REQUEST_METHOD'] = 'POST';
			}

			$service = new \SimpleJWTLogin\Services\ValidateTokenService();

			try {
				$response = $service
					->withSettings( $settings )
					->withServerHelper( new \SimpleJWTLogin\Helpers\ServerHelper( $server ) )
					->withRequestMethod( $server['REQUEST_METHOD'] )
					->withRequest( array() )
					->withCookies( isset( $_COOKIE ) ? (array) $_COOKIE : array() )
					->withSession( isset( $_SESSION ) ? (array) $_SESSION : array() )
					->makeAction();
			} catch ( \Exception $exception ) {
				$fallback = $this->fallback_validate_token( $token, $settings, $wordpress_data, $exception );

				if ( true === $fallback || $fallback instanceof WP_Error ) {
					return $fallback;
				}

				return new WP_Error(
					'wp_mcp_ai_simple_jwt_login_invalid_token',
					__( 'The Simple JWT Login token could not be validated.', 'wp-mcp-ai' ),
					array(
						'status'  => 401,
						'details' => array(
							'message' => wp_strip_all_tags( $exception->getMessage() ),
							'code'    => $exception->getCode(),
						),
					)
				);
			}

			if ( $response instanceof WP_REST_Response ) {
				$data = $response->get_data();
			} else {
				$data = $response;
			}

			if ( ! is_array( $data ) || empty( $data['data']['user']['ID'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_login_unexpected_response',
					__( 'Simple JWT Login returned an unexpected response.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			$this->last_validated_user_id = absint( $data['data']['user']['ID'] );

			if ( isset( $data['data']['jwt'][0]['payload'] ) && is_array( $data['data']['jwt'][0]['payload'] ) ) {
				$this->last_payload = $data['data']['jwt'][0]['payload'];
			} else {
				$this->last_payload = null;
			}

			/**
			 * Fires after a Simple JWT Login token has been validated.
			 *
			 * @param array|null      $payload Validated token payload when available.
			 * @param int             $user_id WordPress user identifier mapped from the token.
			 */
			do_action( 'wp_mcp_ai_simple_jwt_login_validated', $this->last_payload, $this->last_validated_user_id );

			return true;
		}

		/**
		 * Normalise the header key used by Simple JWT Login.
		 *
		 * @param string $header Raw header name from plugin settings.
		 * @return string
		 */
		protected function normalise_header_key( $header ) {
			if ( ! is_string( $header ) || '' === $header ) {
				return 'HTTP_AUTHORIZATION';
			}

			$server_key = strtoupper( str_replace( '-', '_', $header ) );
			if ( 0 !== strpos( $server_key, 'HTTP_' ) ) {
				$server_key = 'HTTP_' . $server_key;
			}

			return $server_key;
		}

		/**
		 * Attempt to validate the token by manually decoding the payload.
		 *
		 * Provides a fallback path when Simple JWT Login's ValidateTokenService
		 * cannot determine the user identifier even though the JWT is otherwise
		 * valid. Mirrors the legacy integration logic so that blank
		 * `jwt_login_by_parameter` settings continue to honour the configured
		 * login strategy.
		 *
		 * @param string                                         $token      Raw bearer token.
		 * @param \SimpleJWTLogin\Modules\SimpleJWTLoginSettings $settings   Simple JWT Login settings instance.
		 * @param \SimpleJWTLogin\Modules\WordPressData          $wordpress_data WordPress data helper.
		 * @param \Exception                                     $exception Original validation exception.
		 * @return true|WP_Error|null
		 */
		protected function fallback_validate_token( $token, $settings, $wordpress_data, $exception ) {
			try {
				$auth_settings = $settings->getAuthenticationSettings();

				if ( method_exists( $auth_settings, 'isAuthenticationEnabled' ) && ! $auth_settings->isAuthenticationEnabled() ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_login_disabled',
						__( 'Simple JWT Login authentication is disabled. Enable authentication in the plugin settings to mint tokens.', 'wp-mcp-ai' ),
						array( 'status' => 403 )
					);
				}

				$allowed_ips = '';
				if ( method_exists( $auth_settings, 'getAllowedIps' ) ) {
					$allowed_ips = trim( (string) $auth_settings->getAllowedIps() );
				}

				if ( $allowed_ips ) {
					$server_helper = new \SimpleJWTLogin\Helpers\ServerHelper( isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array() );

					if ( ! $server_helper->isClientIpInList( $allowed_ips ) ) {
						return new WP_Error(
							'wp_mcp_ai_simple_jwt_disallowed_ip',
							__( 'This IP address is not permitted to authenticate with Simple JWT Login.', 'wp-mcp-ai' ),
							array( 'status' => 403 )
						);
					}
				}

				$general_settings = $settings->getGeneralSettings();
				$algorithm        = $general_settings->getJWTDecryptAlgorithm();
				$key_factory      = \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::getFactory( $settings );
				$public_key       = $key_factory->getPublicKey();

				if ( empty( $public_key ) || empty( $algorithm ) ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_missing_keys',
						__( 'Simple JWT Login is not configured with a public key for token verification.', 'wp-mcp-ai' ),
						array( 'status' => 500 )
					);
				}

				\SimpleJWTLogin\Libraries\JWT\JWT::$leeway = \SimpleJWTLogin\Services\BaseService::JWT_LEEVAY;
				$decoded                                   = \SimpleJWTLogin\Libraries\JWT\JWT::decode( $token, $public_key, array( $algorithm ) );
				$payload                                   = json_decode( wp_json_encode( $decoded ), true );

				if ( ! is_array( $payload ) ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_invalid_payload',
						__( 'Simple JWT Login returned an unexpected payload while validating the token.', 'wp-mcp-ai' ),
						array( 'status' => 401 )
					);
				}

				$user = $this->resolve_user_from_payload( $payload, $settings, $wordpress_data );
				if ( $user instanceof WP_Error ) {
					return $user;
				}

				if ( $user instanceof WP_User ) {
					$revoked_tokens = (array) $wordpress_data->getUserMeta( $user->ID, \SimpleJWTLogin\Modules\SimpleJWTLoginSettings::REVOKE_TOKEN_KEY );
					foreach ( $revoked_tokens as $revoked ) {
						if ( hash_equals( (string) $revoked, (string) $token ) ) {
							return new WP_Error(
								'wp_mcp_ai_simple_jwt_revoked',
								__( 'The bearer token has been revoked by Simple JWT Login.', 'wp-mcp-ai' ),
								array( 'status' => 401 )
							);
						}
					}

					$this->last_validated_user_id = absint( $user->ID );
				} else {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_user_not_found',
						__( 'The user referenced by the Simple JWT Login token could not be found.', 'wp-mcp-ai' ),
						array( 'status' => 401 )
					);
				}

				$this->last_payload = $payload;

				return true;
			} catch ( \Throwable $fallback_exception ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_login_invalid_token',
					__( 'The Simple JWT Login token could not be validated.', 'wp-mcp-ai' ),
					array(
						'status'  => 401,
						'details' => array(
							'message' => wp_strip_all_tags( $fallback_exception->getMessage() ?: $exception->getMessage() ),
							'code'    => $fallback_exception->getCode() ?: $exception->getCode(),
						),
					)
				);
			}
		}

		/**
		 * Resolve the WordPress user referenced by the JWT payload.
		 *
		 * Mirrors the legacy integration logic, allowing Simple JWT Login's
		 * "Login by" configuration to dictate which claim is used when the
		 * plugin does not specify a custom payload key.
		 *
		 * @param array                                          $payload         Decoded JWT payload.
		 * @param \SimpleJWTLogin\Modules\SimpleJWTLoginSettings $settings        Simple JWT Login settings instance.
		 * @param \SimpleJWTLogin\Modules\WordPressData          $wordpress_data WordPress data helper.
		 * @return WP_User|WP_Error
		 */
		protected function resolve_user_from_payload( array $payload, $settings, $wordpress_data ) {
			$login_settings = $settings->getLoginSettings();
			$parameter      = trim( (string) $login_settings->getJwtLoginByParameter() );

			if ( '' === $parameter ) {
				switch ( (int) $login_settings->getJWTLoginBy() ) {
					case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_EMAIL:
						$parameter = 'email';
						break;
					case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_USER_LOGIN:
						$parameter = 'username';
						break;
					case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_WORDPRESS_USER_ID:
					default:
						$parameter = 'id';
						break;
				}
			}

			$identifier = $this->extract_payload_value( $payload, $parameter );
			if ( $identifier instanceof WP_Error ) {
				return $identifier;
			}

			if ( null === $identifier ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_missing_claim',
					sprintf(
						/* translators: %s claim name */
						__( 'The Simple JWT Login token is missing the "%s" claim.', 'wp-mcp-ai' ),
						$parameter
					),
					array( 'status' => 401 )
				);
			}

			switch ( (int) $login_settings->getJWTLoginBy() ) {
				case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_EMAIL:
					$user = $wordpress_data->getUserDetailsByEmail( (string) $identifier );
					break;
				case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_USER_LOGIN:
					$user = $wordpress_data->getUserByUserLogin( (string) $identifier );
					break;
				case \SimpleJWTLogin\Modules\Settings\LoginSettings::JWT_LOGIN_BY_WORDPRESS_USER_ID:
				default:
					$user = $wordpress_data->getUserDetailsById( (int) $identifier );
					break;
			}

			if ( ! $wordpress_data->isInstanceOfuser( $user ) ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_user_not_found',
					__( 'The user referenced by the Simple JWT Login token could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			return $user;
		}

		/**
		 * Retrieve a claim from the payload using dotted notation.
		 *
		 * @param array  $payload   Decoded JWT payload.
		 * @param string $parameter Claim key (supports dotted notation).
		 * @return mixed|WP_Error|null
		 */
		protected function extract_payload_value( array $payload, $parameter ) {
			if ( '' === $parameter ) {
				return null;
			}

			$value    = $payload;
			$segments = explode( '.', $parameter );

			foreach ( $segments as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_missing_claim',
						sprintf(
							/* translators: %s claim name */
							__( 'The Simple JWT Login token is missing the "%s" claim.', 'wp-mcp-ai' ),
							$parameter
						),
						array( 'status' => 401 )
					);
				}

				$value = $value[ $segment ];
			}

			return $value;
		}
	}
}
