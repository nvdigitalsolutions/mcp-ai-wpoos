<?php
/**
 * Simple JWT Login integration for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Integration_Simple_JWT' ) ) {

	/**
	 * Bridges Simple JWT Login bearer tokens into NV oOS authentication.
	 */
	class WP_MCP_AI_Integration_Simple_JWT {
		const PLUGIN_BASENAME = 'simple-jwt-login/simple-jwt-login.php';

		/**
		 * Register hooks to bootstrap the integration once plugins are loaded.
		 */
		public static function load() {
			add_action( 'plugins_loaded', array( __CLASS__, 'init' ) );
		}

		/**
		 * Bootstrap the integration when the Simple JWT Login plugin is active.
		 */
		public static function init() {
			if ( ! self::is_simple_jwt_active() ) {
				return;
			}

			add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( __CLASS__, 'pre_validate_bearer_token' ), 10, 3 );
		}

		/**
		 * Determine whether Simple JWT Login is currently active.
		 *
		 * @return bool
		 */
		protected static function is_simple_jwt_active() {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
			}

			if ( ! function_exists( 'is_plugin_active' ) ) {
				return false;
			}

			if ( ! is_plugin_active( self::PLUGIN_BASENAME ) ) {
				return false;
			}

			return class_exists( '\\SimpleJWTLogin\\Modules\\SimpleJWTLoginSettings' )
				&& class_exists( '\\SimpleJWTLogin\\Modules\\WordPressData' )
				&& class_exists( '\\SimpleJWTLogin\\Helpers\\Jwt\\JwtKeyFactory' )
				&& class_exists( '\\SimpleJWTLogin\\Libraries\\JWT\\JWT' );
		}

		/**
		 * Attempt to validate a bearer token using Simple JWT Login.
		 *
		 * Returning an array signals the REST controller to treat the payload as
		 * pre-validated. Returning null allows other validators to run.
		 *
		 * @param null|bool|WP_Error $pre     Existing pre-validation result.
		 * @param string             $token   Raw bearer token supplied by the REST controller.
		 * @param WP_REST_Request    $request Current REST request.
		 * @return array|WP_Error|null
		 */
		public static function pre_validate_bearer_token( $pre, $token, $request ) {
			if ( null !== $pre ) {
				return $pre;
			}

			if ( ! self::is_simple_jwt_active() ) {
				return null;
			}

			$header_token = self::extract_bearer_from_request( $request );
			if ( $header_token instanceof WP_Error ) {
				return $header_token;
			}

			if ( empty( $token ) ) {
				$token = $header_token;
			} elseif ( $header_token && $token !== $header_token ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_token_mismatch',
					__( 'The Authorization header does not match the supplied bearer token.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( empty( $token ) ) {
				return null;
			}

			$validated = self::validate_token( $token );
			if ( $validated instanceof WP_Error || null === $validated ) {
				return $validated;
			}

			return $validated;
		}

		/**
		 * Extract the bearer token from the Authorization header when available.
		 *
		 * @param WP_REST_Request $request Current REST request.
		 * @return string|WP_Error Empty string when no header is present.
		 */
		protected static function extract_bearer_from_request( $request ) {
			$header = '';

			if ( $request instanceof WP_REST_Request ) {
				$header = (string) $request->get_header( 'Authorization' );
			}

			if ( ! $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}

			if ( '' === $header ) {
				return '';
			}

			$matches = array();
			$matched = preg_match( '/^(?:(\\w+)\\s+)?([\\w\\-.]+)/mi', $header, $matches );
			if ( ! $matched || empty( $matches[2] ) ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_invalid_header',
					__( 'The Authorization header is not a valid bearer token.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			if ( ! empty( $matches[1] ) && 'bearer' !== strtolower( $matches[1] ) ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_unsupported_scheme',
					__( 'Only Bearer tokens are supported for Simple JWT Login authentication.', 'wp-mcp-ai' ),
					array( 'status' => 401 )
				);
			}

			return $matches[2];
		}

		/**
		 * Validate the supplied token via Simple JWT Login.
		 *
		 * @param string $token Raw JWT string.
		 * @return array|WP_Error|null
		 */
		protected static function validate_token( $token ) {
			try {
				$wordpress_data = new \SimpleJWTLogin\Modules\WordPressData();
				$settings       = new \SimpleJWTLogin\Modules\SimpleJWTLoginSettings( $wordpress_data );
				$auth_settings  = $settings->getAuthenticationSettings();

				if ( ! $auth_settings->isAuthenticationEnabled() ) {
					return null;
				}

				$allowed_ips = trim( (string) $auth_settings->getAllowedIps() );
				if ( $allowed_ips ) {
					$server_helper = new \SimpleJWTLogin\Helpers\ServerHelper( $_SERVER );
					if ( ! $server_helper->isClientIpInList( $allowed_ips ) ) {
						return new WP_Error(
							'wp_mcp_ai_simple_jwt_disallowed_ip',
							__( 'This IP address is not permitted to authenticate with Simple JWT Login.', 'wp-mcp-ai' ),
							array( 'status' => 403 )
						);
					}
				}

				$algorithm   = $settings->getGeneralSettings()->getJWTDecryptAlgorithm();
				$key_factory = \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::getFactory( $settings );
				$public_key  = $key_factory->getPublicKey();

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

				$user = self::resolve_user_from_payload( $payload, $settings, $wordpress_data );
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
				}

				$context = self::build_context_from_payload( $payload, $user );

				return array(
					'payload' => $payload,
					'context' => $context,
				);
			} catch ( \Throwable $exception ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_validation_failed',
					__( 'Simple JWT Login could not validate the supplied bearer token.', 'wp-mcp-ai' ),
					array(
						'status'        => 401,
						'error_code'    => $exception->getCode(),
						'error_message' => $exception->getMessage(),
					)
				);
			}
		}

		/**
		 * Resolve the WordPress user referenced by the JWT payload.
		 *
		 * @param array                                          $payload  Decoded JWT payload.
		 * @param \SimpleJWTLogin\Modules\SimpleJWTLoginSettings $settings Plugin settings instance.
		 * @param \SimpleJWTLogin\Modules\WordPressData          $wordpress_data WordPress data helper.
		 * @return WP_User|WP_Error|null
		 */
		protected static function resolve_user_from_payload( array $payload, $settings, $wordpress_data ) {
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

			$identifier = self::extract_payload_value( $payload, $parameter );
			if ( $identifier instanceof WP_Error || null === $identifier ) {
				return $identifier instanceof WP_Error ? $identifier : null;
			}

			$user = null;
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
		 * Retrieve a claim from the payload, supporting dotted notation for nested arrays.
		 *
		 * @param array  $payload Decoded JWT payload.
		 * @param string $parameter Claim key (supports dot notation).
		 * @return mixed|WP_Error|null
		 */
		protected static function extract_payload_value( array $payload, $parameter ) {
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

		/**
		 * Build the authentication context passed to NV oOS.
		 *
		 * @param array        $payload Decoded JWT payload.
		 * @param WP_User|null $user    WordPress user resolved from the payload.
		 * @return array
		 */
		protected static function build_context_from_payload( array $payload, $user ) {
			$context = array(
				'provider'     => 'simple-jwt-login',
				'prevalidated' => true,
			);

			if ( $user instanceof WP_User ) {
				$context['user_id'] = (int) $user->ID;
			}

			$assistant_id = self::extract_assistant_id( $payload );
			if ( $assistant_id ) {
				$context['assistant_id'] = $assistant_id;
			}

			$scopes = self::extract_scopes( $payload );
			if ( ! empty( $scopes ) ) {
				$context['scopes'] = $scopes;
			}

			return $context;
		}

		/**
		 * Attempt to locate an assistant identifier within the payload.
		 *
		 * @param array $payload JWT payload.
		 * @return int
		 */
		protected static function extract_assistant_id( array $payload ) {
			$candidates = array(
				'assistant_id',
				'assistantId',
				'assistant.id',
			);

			foreach ( $candidates as $claim ) {
				$value = self::extract_payload_value( $payload, $claim );
				if ( $value instanceof WP_Error || null === $value ) {
					continue;
				}

				$assistant_id = absint( $value );
				if ( $assistant_id > 0 ) {
					return $assistant_id;
				}
			}

			return 0;
		}

		/**
		 * Normalise scope claims to an array of strings.
		 *
		 * @param array $payload JWT payload.
		 * @return array
		 */
		protected static function extract_scopes( array $payload ) {
			if ( isset( $payload['scopes'] ) && is_array( $payload['scopes'] ) ) {
				return array_values( array_filter( array_map( 'sanitize_text_field', $payload['scopes'] ) ) );
			}

			if ( isset( $payload['scope'] ) ) {
				$scope = $payload['scope'];
				if ( is_string( $scope ) ) {
					$parts = preg_split( '/[\s,]+/', $scope );
					return array_values( array_filter( array_map( 'sanitize_text_field', (array) $parts ) ) );
				}

				if ( is_array( $scope ) ) {
					return array_values( array_filter( array_map( 'sanitize_text_field', $scope ) ) );
				}
			}

			if ( isset( $payload['permissions'] ) && is_array( $payload['permissions'] ) ) {
				return array_values( array_filter( array_map( 'sanitize_text_field', $payload['permissions'] ) ) );
			}

			return array();
		}
	}
}
