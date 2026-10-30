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
	 * The masked placeholder emitted for sensitive values.
	 *
	 * @var string
	 */
	const MASKED_PLACEHOLDER = '**************';

	/**
	 * Field types the schema-driven SPA form renders (Proposal 084 Phase 2).
	 * Structural/admin-only types (html, heading, slider, custom, multiselect,
	 * specialist/generalist) stay wp-admin-only for now.
	 *
	 * @var string[]
	 */
	const SUPPORTED_FIELD_TYPES = array(
		'checkbox',
		'number',
		'select',
		'text',
		'password',
		'url',
		'email',
		'textarea',
		'hidden',
	);

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

		// Schema-driven settings surface (2.4.0, Proposal 084 Phase 2): the
		// SPA renders any section/field registered in the settings registry.
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/schema',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_schema' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/values',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_values' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_values' ),
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

	/* ── Schema-driven surface (2.4.0, Proposal 084 Phase 2) ─────────── */

	/**
	 * Flatten every SPA-supported field across the registered settings
	 * sections into one schema key => definition map.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string,array<string,mixed>> Keyed by setting key.
	 */
	private function build_schema_fields() {
		$fields = array();

		if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return $fields;
		}

		foreach ( WP_MCP_AI_Settings_Registry::get_sections() as $section ) {
			if ( ! $section instanceof WP_MCP_AI_Settings_Section ) {
				continue;
			}

			foreach ( $section->get_fields() as $key => $field ) {
				if ( ! is_array( $field ) || isset( $fields[ $key ] ) ) {
					continue;
				}

				$type = isset( $field['type'] ) ? sanitize_key( (string) $field['type'] ) : 'text';
				if ( ! in_array( $type, self::SUPPORTED_FIELD_TYPES, true ) ) {
					continue;
				}

				// Sections rendered inside another section (Custom Filters,
				// Pro Performance) declare the '__hidden__' sentinel tab —
				// they own no tab, so their fields must not surface in the
				// schema-driven UI (the SPA would render a literal
				// '__hidden__' tab button).
				if ( '__hidden__' === sanitize_key( (string) $section->get_tab() ) ) {
					continue;
				}

				$fields[ $key ] = array(
					'key'         => sanitize_key( (string) $key ),
					'type'        => $type,
					'section_id'  => sanitize_key( (string) $section->get_id() ),
					'section'     => wp_strip_all_tags( (string) $section->get_title() ),
					'tab'         => sanitize_key( (string) $section->get_tab() ),
					'label'       => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : $key,
					'description' => isset( $field['description'] ) ? wp_strip_all_tags( (string) $field['description'] ) : '',
					'sensitive'   => 'password' === $type,
				);

				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
					$fields[ $key ]['options'] = array_map( 'wp_strip_all_tags', $field['options'] );

					// The stored value may have left the option list (provider
					// model catalogs churn). Without it the SPA select silently
					// displays the FIRST option — a lie about the live config.
					// Append the stored value as an extra option so the UI always
					// shows (and can keep) what is really configured. The key
					// must stay RAW: sanitize_key() would mangle keys with
					// slashes/dashes (model slugs like meta/llama-3.1-8b-instruct).
					if ( 'select' === $type ) {
						$stored_select = WP_MCP_AI_Settings_Registry::get_setting( $key, null );
						if ( is_scalar( $stored_select ) && '' !== (string) $stored_select ) {
							$stored_select_key = (string) $stored_select;
							if ( ! array_key_exists( $stored_select_key, $fields[ $key ]['options'] ) ) {
								/* translators: %s: the currently stored setting value. */
								$fields[ $key ]['options'][ $stored_select_key ] = sprintf( __( '%s (stored)', 'mcp-ai-wpoos' ), $stored_select_key );
							}
						}
					}
				}
				if ( array_key_exists( 'default', $field ) ) {
					$fields[ $key ]['default'] = $field['default'];
				}
				if ( isset( $field['min'] ) ) {
					$fields[ $key ]['min'] = (float) $field['min'];
				}
				if ( isset( $field['max'] ) ) {
					$fields[ $key ]['max'] = (float) $field['max'];
				}
				if ( isset( $field['step'] ) ) {
					$fields[ $key ]['step'] = (float) $field['step'];
				}
			}
		}

		return $fields;
	}

	/**
	 * Read every schema key, masking sensitive values.
	 *
	 * @since 2.4.0
	 *
	 * @param array<string,array<string,mixed>> $schema_fields Schema key => definition.
	 * @return array<string,mixed> Key => value (secrets masked).
	 */
	private function build_values_payload( array $schema_fields ) {
		$values = array();

		if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return $values;
		}

		foreach ( $schema_fields as $key => $field ) {
			$default = array_key_exists( 'default', $field ) ? $field['default'] : '';
			$stored  = WP_MCP_AI_Settings_Registry::get_setting( $key, $default );

			if ( ! empty( $field['sensitive'] ) ) {
				$values[ $key ] = WP_MCP_AI_Admin_Settings_Base::mask_sensitive_setting_value( $stored );
				continue;
			}

			switch ( $field['type'] ) {
				case 'checkbox':
					$values[ $key ] = (bool) $stored;
					break;
				case 'number':
					$values[ $key ] = is_numeric( $stored ) ? (float) $stored : (float) $default;
					break;
				default:
					$values[ $key ] = is_scalar( $stored ) ? $stored : ( is_scalar( $default ) ? $default : '' );
			}
		}

		return $values;
	}

	/**
	 * GET /settings/schema — the SPA-renderable settings catalogue.
	 *
	 * @since 2.4.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_schema() {
		$fields = $this->build_schema_fields();
		$tabs   = array();

		if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			foreach ( WP_MCP_AI_Settings_Registry::get_tabs() as $tab_id => $tab ) {
				$tabs[ sanitize_key( (string) $tab_id ) ] = is_array( $tab ) && isset( $tab['title'] )
					? wp_strip_all_tags( (string) $tab['title'] )
					: sanitize_key( (string) $tab_id );
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'tabs'   => $tabs,
					'fields' => array_values( $fields ),
				),
			)
		);
	}

	/**
	 * GET /settings/values — every schema key, secrets masked.
	 *
	 * @since 2.4.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_values() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'values' => $this->build_values_payload( $this->build_schema_fields() ),
				),
			)
		);
	}

	/**
	 * POST /settings/values — sanitize a partial update against the schema.
	 *
	 * Unknown keys are ignored; sensitive keys submitted as the masked
	 * placeholder keep their stored value.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_values( WP_REST_Request $request ) {
		$schema_fields = $this->build_schema_fields();
		$body          = $request->get_json_params();
		$current       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$changes = array();
		if ( is_array( $body ) ) {
			foreach ( $schema_fields as $key => $field ) {
				if ( ! array_key_exists( $key, $body ) ) {
					continue;
				}

				$current_value   = isset( $current[ $key ] ) ? $current[ $key ] : '';
				$changes[ $key ] = $this->sanitize_schema_value( $field, $body[ $key ], $current_value );
			}
		}

		if ( ! empty( $changes ) ) {
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array_merge( $current, $changes ) );
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'values' => $this->build_values_payload( $schema_fields ),
				),
			)
		);
	}

	/**
	 * Sanitize one submitted value against its schema field definition.
	 *
	 * @since 2.4.0
	 *
	 * @param array<string,mixed> $field         Schema field definition.
	 * @param mixed               $value         Submitted value.
	 * @param mixed               $current_value Currently stored value.
	 * @return mixed Sanitized value.
	 */
	private function sanitize_schema_value( array $field, $value, $current_value ) {
		$type = $field['type'];

		if ( ! empty( $field['sensitive'] ) || 'password' === $type ) {
			return WP_MCP_AI_Admin_Settings_Base::sanitize_sensitive_setting_value( $value, $current_value );
		}

		switch ( $type ) {
			case 'checkbox':
				return rest_sanitize_boolean( $value );

			case 'number':
				$number = (float) $value;
				if ( isset( $field['min'] ) && $number < (float) $field['min'] ) {
					$number = (float) $field['min'];
				}
				if ( isset( $field['max'] ) && $number > (float) $field['max'] ) {
					$number = (float) $field['max'];
				}
				return $number;

			case 'select':
				// Match against the RAW option keys. sanitize_key() would mangle
				// keys containing slashes/dashes (provider model slugs like
				// meta/llama-3.1-8b-instruct), silently rejecting every SPA save
				// of such selects. Membership in the literal option list is the
				// sanitizer — anything else falls back to the stored value.
				$select_value = is_scalar( $value ) ? (string) $value : '';
				if ( ! empty( $field['options'] ) && is_array( $field['options'] ) && ! array_key_exists( $select_value, $field['options'] ) ) {
					return $current_value;
				}
				// Numeric-option selects (e.g. default_assistant) store int
				// values; coerce so REST writes can't leave string values
				// behind that the admin UI and readers would mishandle.
				if ( $this->is_numeric_select( $field ) ) {
					return absint( $select_value );
				}
				return $select_value;

			case 'url':
				return esc_url_raw( (string) $value );

			case 'email':
				return sanitize_email( (string) $value );

			case 'textarea':
				return sanitize_textarea_field( (string) $value );

			case 'hidden':
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Whether a select field's option keys are all numeric.
	 *
	 * @since 2.4.0
	 *
	 * @param array<string,mixed> $field Schema field definition.
	 * @return bool True when every option key is an int or numeric string.
	 */
	private function is_numeric_select( array $field ) {
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return false;
		}
		foreach ( array_keys( $field['options'] ) as $option_key ) {
			if ( ! is_numeric( $option_key ) ) {
				return false;
			}
		}
		return true;
	}
}
