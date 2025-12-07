<?php
/**
 * Tool for generating Simple JWT Login bearer tokens.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a Simple JWT Login bearer token for the current user.
 */
class WP_MCP_AI_Tool_Generate_Simple_JWT_Token implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether the tool can be registered.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! self::are_simple_jwt_classes_available() ) {
			return false;
		}

		try {
			$wordpress_data = new \SimpleJWTLogin\Modules\WordPressData();
			$settings       = new \SimpleJWTLogin\Modules\SimpleJWTLoginSettings( $wordpress_data );
		} catch ( \Exception $exception ) {
			return false;
		}

		$auth_settings = $settings->getAuthenticationSettings();
		if ( method_exists( $auth_settings, 'isAuthenticationEnabled' ) && ! $auth_settings->isAuthenticationEnabled() ) {
			return false;
		}

		try {
			$key_factory = \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::getFactory( $settings );
		} catch ( \Exception $exception ) {
			return false;
		}

		$private_key = $key_factory->getPrivateKey();
		$algorithm   = $settings->getGeneralSettings()->getJWTDecryptAlgorithm();

		return ! empty( $private_key ) && ! empty( $algorithm );
	}

	/**
	 * Describe why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! self::are_simple_jwt_classes_available() ) {
			return __( 'Install and activate the Simple JWT Login plugin to mint bearer tokens.', 'wp-mcp-ai' );
		}

		try {
			$wordpress_data = new \SimpleJWTLogin\Modules\WordPressData();
			$settings       = new \SimpleJWTLogin\Modules\SimpleJWTLoginSettings( $wordpress_data );
		} catch ( \Exception $exception ) {
			return __( 'Simple JWT Login could not be initialised. Review the plugin configuration and try again.', 'wp-mcp-ai' );
		}

		$auth_settings = $settings->getAuthenticationSettings();
		if ( method_exists( $auth_settings, 'isAuthenticationEnabled' ) && ! $auth_settings->isAuthenticationEnabled() ) {
			return __( 'Enable authentication in the Simple JWT Login settings to mint tokens.', 'wp-mcp-ai' );
		}

		try {
			$key_factory = \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::getFactory( $settings );
		} catch ( \Exception $exception ) {
			return __( 'Simple JWT Login could not access the signing keys. Review the plugin configuration and try again.', 'wp-mcp-ai' );
		}

		$private_key = $key_factory->getPrivateKey();
		$algorithm   = $settings->getGeneralSettings()->getJWTDecryptAlgorithm();

		if ( empty( $private_key ) || empty( $algorithm ) ) {
			return __( 'Configure a signing algorithm and private key in Simple JWT Login before generating tokens.', 'wp-mcp-ai' );
		}

		return '';
	}

	/**
	 * Confirm whether the Simple JWT Login plugin exposes the required classes.
	 *
	 * @return bool
	 */
	protected static function are_simple_jwt_classes_available() {
		return class_exists( '\\SimpleJWTLogin\\Modules\\WordPressData' )
			&& class_exists( '\\SimpleJWTLogin\\Modules\\SimpleJWTLoginSettings' )
			&& class_exists( '\\SimpleJWTLogin\\Services\\AuthenticateService' )
			&& class_exists( '\\SimpleJWTLogin\\Helpers\\Jwt\\JwtKeyFactory' )
			&& class_exists( '\\SimpleJWTLogin\\Libraries\\JWT\\JWT' )
			&& class_exists( '\\SimpleJWTLogin\\Modules\\Settings\\AuthenticationSettings' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_simple_jwt_token';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Simple JWT Token', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a Simple JWT Login bearer credential for the currently authenticated WordPress user.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'claims' => array(
					'type'                 => 'object',
					'description'          => __( 'Optional custom claims to merge into the JWT payload.', 'wp-mcp-ai' ),
					'additionalProperties' => array(
						'type' => array( 'string', 'number', 'integer', 'boolean', 'null' ),
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_simple_jwt_login_requires_auth', __( 'You must be logged in to mint a Simple JWT Login token.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_simple_jwt_login_wrong_site', __( 'The current user does not belong to this site.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		if ( ! self::are_simple_jwt_classes_available() ) {
			return new WP_Error(
				'wp_mcp_ai_simple_jwt_login_missing',
				__( 'The Simple JWT Login plugin is required to generate bearer tokens.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
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

		$auth_settings = $settings->getAuthenticationSettings();
		if ( method_exists( $auth_settings, 'isAuthenticationEnabled' ) && ! $auth_settings->isAuthenticationEnabled() ) {
			return new WP_Error(
				'wp_mcp_ai_simple_jwt_login_disabled',
				__( 'Simple JWT Login authentication is disabled. Enable authentication in the plugin settings to mint tokens.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		$user = get_userdata( $acting_user_id );
		if ( ! $user ) {
			return new WP_Error( 'wp_mcp_ai_simple_jwt_login_user_missing', __( 'Unable to load the current WordPress user.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
		}

		$claims = array();
		if ( isset( $arguments['claims'] ) && is_array( $arguments['claims'] ) ) {
			foreach ( $arguments['claims'] as $claim_key => $claim_value ) {
				if ( ! is_string( $claim_key ) || '' === $claim_key ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_login_invalid_claim',
						__( 'Claim keys must be non-empty strings.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}

				if ( is_array( $claim_value ) || is_object( $claim_value ) ) {
					return new WP_Error(
						'wp_mcp_ai_simple_jwt_login_invalid_claim',
						__( 'Claim values must be scalar or null.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}

				$claims[ $claim_key ] = $claim_value;
			}
		}

		if ( isset( $context['assistant_id'] ) && ! isset( $claims['assistant_id'] ) ) {
			$claims['assistant_id'] = absint( $context['assistant_id'] );
		}

		try {
			$payload = \SimpleJWTLogin\Services\AuthenticateService::generatePayload(
				$claims,
				$wordpress_data,
				$settings,
				$user
			);

			/**
			 * Filter the Simple JWT Login payload minted by the bearer token tool.
			 *
			 * @param array       $payload Generated payload data.
			 * @param WP_User     $user    WordPress user receiving the token.
			 * @param array       $context Tool execution context.
			 * @param array       $claims  Claims passed to the tool.
			 */
			$payload = apply_filters( 'wp_mcp_ai_simple_jwt_login_tool_payload', $payload, $user, $context, $claims );

			$key_factory = \SimpleJWTLogin\Helpers\Jwt\JwtKeyFactory::getFactory( $settings );
			$private_key = $key_factory->getPrivateKey();
			$algorithm   = $settings->getGeneralSettings()->getJWTDecryptAlgorithm();

			if ( empty( $private_key ) || empty( $algorithm ) ) {
				return new WP_Error(
					'wp_mcp_ai_simple_jwt_login_missing_keys',
					__( 'Simple JWT Login is not configured with a private key for signing tokens.', 'wp-mcp-ai' ),
					array( 'status' => 500 )
				);
			}

			$token = \SimpleJWTLogin\Libraries\JWT\JWT::encode( $payload, $private_key, $algorithm );
		} catch ( \Exception $exception ) {
			return new WP_Error(
				'wp_mcp_ai_simple_jwt_login_token_error',
				__( 'The Simple JWT Login token could not be generated.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'details' => array(
						'message' => wp_strip_all_tags( $exception->getMessage() ),
						'code'    => $exception->getCode(),
					),
				)
			);
		}

		/**
		 * Filter the Simple JWT Login token minted by the bearer token tool.
		 *
		 * @param string $token   Encoded JWT.
		 * @param array  $payload JWT payload data.
		 * @param WP_User $user   WordPress user receiving the token.
		 * @param array  $context Tool execution context.
		 */
		$token = apply_filters( 'wp_mcp_ai_simple_jwt_login_tool_token', $token, $payload, $user, $context );

		$expires_at = null;
		if ( isset( $payload[ \SimpleJWTLogin\Modules\Settings\AuthenticationSettings::JWT_PAYLOAD_PARAM_EXP ] ) ) {
			$expires = intval( $payload[ \SimpleJWTLogin\Modules\Settings\AuthenticationSettings::JWT_PAYLOAD_PARAM_EXP ] );
			if ( $expires > 0 ) {
				$expires_at = gmdate( 'c', $expires );
			}
		}

		/**
		 * Fires after a Simple JWT Login token has been generated by the bearer token tool.
		 *
		 * @param string  $token   Encoded JWT string.
		 * @param array   $payload Payload data encoded in the JWT.
		 * @param WP_User $user    WordPress user receiving the token.
		 * @param array   $context Tool execution context.
		 */
		do_action( 'wp_mcp_ai_simple_jwt_login_tool_token_generated', $token, $payload, $user, $context );

		return array(
			'token_type' => 'Bearer',
			'token'      => $token,
			'user_id'    => $user->ID,
			'expires_at' => $expires_at,
			'payload'    => $payload,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-plugin',     // Requires Simple JWT Login plugin.
			'requires-capability', // Requires authentication.
			'local-only',          // No external API calls.
			'non-deterministic',   // Generates unique tokens each time.
		);
	}
}
