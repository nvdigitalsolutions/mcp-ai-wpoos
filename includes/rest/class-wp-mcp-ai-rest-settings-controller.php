<?php
/**
 * REST controller for the SPA plugin-settings surface.
 *
 * Serves GET/POST /mcp-ai/v1/settings for the Pro SPA Settings page. The
 * route is manage_options-only, never returns secrets (sensitive fields
 * come back masked with the admin placeholder so an unchanged form
 * round-trips without exposing the stored keys), and writes reuse the
 * admin settings sanitizers — including encryption at rest.
 *
 * Field mapping (SPA form -> stored settings key):
 *   openai_api_key   -> openai_api_key
 *   google_api_key   -> gemini_api_key
 *   anthropic_api_key-> anthropic_api_key
 *   ollama_endpoint  -> ollama_endpoint_url
 *   default_model / default_provider / enable_logging / max_tokens /
 *   temperature     -> same key.
 *
 * @package NV_oOS_Pro_Spa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings REST controller.
 */
class WP_MCP_AI_REST_Settings_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/settings';

	/**
	 * SPA form fields accepted by the write path, mapped to stored keys.
	 *
	 * @var array<string,string>
	 */
	const FIELD_MAP = array(
		'openai_api_key'    => 'openai_api_key',
		'google_api_key'    => 'gemini_api_key',
		'anthropic_api_key' => 'anthropic_api_key',
		'ollama_endpoint'   => 'ollama_endpoint_url',
		'default_model'     => 'default_model',
		'default_provider'  => 'default_provider',
		'max_tokens'        => 'max_tokens',
		'temperature'       => 'temperature',
		'enable_logging'    => 'enable_logging',
	);

	/**
	 * Sensitive stored keys masked in the read payload.
	 *
	 * @var string[]
	 */
	const SENSITIVE_KEYS = array( 'openai_api_key', 'gemini_api_key', 'anthropic_api_key' );

	/**
	 * Register the settings routes.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — settings editing is administrator-only.
	 *
	 * @since 2.1.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Administrator access required.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /settings — the SPA-shaped settings payload, secrets masked.
	 *
	 * @since 2.1.0
	 * @return WP_REST_Response
	 */
	public function get_settings_item() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $this->build_spa_payload( WP_MCP_AI_Admin_Settings::get_settings() ),
			)
		);
	}

	/**
	 * POST /settings — sanitize the SPA form fields into the settings option.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_settings_item( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$current = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		if ( ! is_array( $current ) ) {
			$current = array();
		}

		if ( is_array( $body ) ) {
			$changes = array();

			foreach ( self::FIELD_MAP as $spa_key => $stored_key ) {
				if ( ! array_key_exists( $spa_key, $body ) ) {
					continue;
				}

				$changes[ $stored_key ] = $this->sanitize_field( $spa_key, $body[ $spa_key ], $current );
			}

			if ( ! empty( $changes ) ) {
				update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array_merge( $current, $changes ) );
				WP_MCP_AI_Admin_Settings::reset_settings_cache();
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $this->build_spa_payload( WP_MCP_AI_Admin_Settings::get_settings() ),
			)
		);
	}

	/**
	 * Sanitize a single SPA form field into its stored representation.
	 *
	 * @since 2.1.0
	 *
	 * @param string $spa_key SPA form key.
	 * @param mixed  $value   Submitted value.
	 * @param array  $current Current stored settings.
	 * @return mixed Sanitized value for the stored key.
	 */
	private function sanitize_field( $spa_key, $value, array $current ) {
		$stored_key = self::FIELD_MAP[ $spa_key ];

		if ( in_array( $stored_key, self::SENSITIVE_KEYS, true ) ) {
			$current_value = isset( $current[ $stored_key ] ) ? $current[ $stored_key ] : '';
			return WP_MCP_AI_Admin_Settings_Base::sanitize_sensitive_setting_value( $value, $current_value );
		}

		switch ( $spa_key ) {
			case 'ollama_endpoint':
				return esc_url_raw( (string) $value );
			case 'default_model':
				return sanitize_text_field( (string) $value );
			case 'default_provider':
				return sanitize_key( (string) $value );
			case 'enable_logging':
				return rest_sanitize_boolean( $value );
			case 'max_tokens':
				return max( 1, min( 32000, absint( $value ) ) );
			case 'temperature':
				return round( max( 0.0, min( 2.0, (float) $value ) ), 2 );
		}

		return '';
	}

	/**
	 * Map stored settings onto the SPA payload, masking sensitive fields.
	 *
	 * @since 2.1.0
	 *
	 * @param array $settings Merged settings array.
	 * @return array SPA-shaped payload.
	 */
	private function build_spa_payload( array $settings ) {
		$payload = array();

		foreach ( self::FIELD_MAP as $spa_key => $stored_key ) {
			$stored = isset( $settings[ $stored_key ] ) ? $settings[ $stored_key ] : '';

			if ( in_array( $stored_key, self::SENSITIVE_KEYS, true ) ) {
				$payload[ $spa_key ] = WP_MCP_AI_Admin_Settings_Base::mask_sensitive_setting_value( $stored );
				continue;
			}

			switch ( $spa_key ) {
				case 'enable_logging':
					$payload[ $spa_key ] = (bool) $stored;
					break;
				case 'max_tokens':
					$payload[ $spa_key ] = absint( $stored );
					break;
				case 'temperature':
					$payload[ $spa_key ] = (float) $stored;
					break;
				default:
					$payload[ $spa_key ] = is_string( $stored ) ? $stored : '';
			}
		}

		return $payload;
	}
}
