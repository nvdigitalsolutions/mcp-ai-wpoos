<?php
/**
 * Tool that creates or updates WPCode snippets.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the trait for restricting from chat-client.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Restrict_From_Chat_Client' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-restrict-from-chat-client.php';
}

/**
 * Creates or updates WPCode snippets using the WPCode plugin APIs.
 *
 * This tool is restricted from chat-client by default for security reasons
 * as it allows code execution.
 */
class WP_MCP_AI_Tool_Create_WPCode_Snippet implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Context_Restrictions_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * Allowed code types that map to WPCode executor types.
	 *
	 * @var string[]
	 */
	protected $allowed_code_types = array( 'html', 'text', 'css', 'scss', 'js', 'php', 'universal', 'blocks' );

	/**
	 * Determine whether the tool can be registered.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'WPCode' ) && class_exists( 'WPCode_Snippet' );
	}

	/**
	 * Describe why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! function_exists( 'WPCode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
			return __( 'Install and activate the WPCode plugin to manage code snippets.', 'wp-mcp-ai' );
		}

		return '';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_wpcode_snippet';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create WPCode Snippet', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WPCode snippet or updates an existing one with the supplied configuration.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'snippet_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Existing WPCode snippet ID to update. Leave empty to create a new snippet.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Snippet title displayed in the WPCode list.', 'wp-mcp-ai' ),
				),
				'code'             => array(
					'type'        => 'string',
					'description' => __( 'The snippet code to store. Accepts PHP, HTML, CSS, JS and other formats supported by WPCode.', 'wp-mcp-ai' ),
				),
				'code_type'        => array(
					'type'        => 'string',
					'enum'        => $this->allowed_code_types,
					'description' => __( 'WPCode code type slug that controls execution (php, html, css, js, universal, etc).', 'wp-mcp-ai' ),
				),
				'auto_insert'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the snippet should auto-insert in a location (true) or only be available via shortcode (false).', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'location'         => array(
					'type'        => 'string',
					'description' => __( 'Auto-insert location slug, such as site_wide_header, before_post_content or everywhere.', 'wp-mcp-ai' ),
				),
				'activate'         => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to activate (publish) the snippet after saving.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'tags'             => array(
					'type'        => 'array',
					'description' => __( 'Optional array of tag slugs to assign to the snippet.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'note'             => array(
					'type'        => 'string',
					'description' => __( 'Internal note stored with the snippet.', 'wp-mcp-ai' ),
				),
				'priority'         => array(
					'type'        => 'integer',
					'description' => __( 'Execution priority for the snippet. Lower numbers run earlier.', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'insert_number'    => array(
					'type'        => 'integer',
					'description' => __( 'Paragraph, word or loop index for locations that support numbered insertion.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'custom_shortcode' => array(
					'type'        => 'string',
					'description' => __( 'Optional custom shortcode tag to register when auto insert is disabled.', 'wp-mcp-ai' ),
				),
				'device_type'      => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'desktop', 'mobile' ),
					'description' => __( 'Restrict snippet execution to a specific device type.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'code', 'code_type' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// phpcs:disable WordPressVIPMinimum.Security.UserCan
		if ( ! $user_id || ! user_can( $user_id, 'wpcode_edit_snippets' ) ) { // phpcs:ignore WordPressVIPMinimum.Security.UserCan.Unknown
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage WPCode snippets.', 'wp-mcp-ai' ) );
		}
		// phpcs:enable WordPressVIPMinimum.Security.UserCan

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! function_exists( 'WPCode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'wp-mcp-ai' ) );
		}

		$code      = isset( $arguments['code'] ) ? (string) $arguments['code'] : '';
		$code      = wp_check_invalid_utf8( $code );
		$code_type = isset( $arguments['code_type'] ) ? sanitize_key( $arguments['code_type'] ) : '';

		if ( '' === $code ) {
			return new WP_Error( 'wp_mcp_ai_missing_code', __( 'Snippet code is required.', 'wp-mcp-ai' ) );
		}

		if ( '' === $code_type || ! in_array( $code_type, $this->allowed_code_types, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_code_type', __( 'Please provide a supported WPCode code type.', 'wp-mcp-ai' ) );
		}

		$snippet_id = isset( $arguments['snippet_id'] ) ? absint( $arguments['snippet_id'] ) : 0;
		$existing   = null;

		if ( 0 < $snippet_id ) {
				$existing = new WPCode_Snippet( $snippet_id );

			if ( empty( $existing->post_data ) || 'wpcode' !== $existing->post_type ) {
						return new WP_Error( 'wp_mcp_ai_invalid_snippet', __( 'The requested WPCode snippet could not be found.', 'wp-mcp-ai' ) );
			}
		}

		$auto_insert_enabled = array_key_exists( 'auto_insert', $arguments ) ? (bool) $arguments['auto_insert'] : true;
		$location            = isset( $arguments['location'] ) ? sanitize_key( $arguments['location'] ) : '';

		if ( $auto_insert_enabled && '' === $location ) {
			return new WP_Error( 'wp_mcp_ai_missing_location', __( 'An auto-insert location is required when auto_insert is true.', 'wp-mcp-ai' ) );
		}

		if ( $auto_insert_enabled && '' !== $location ) {
			$location_validation = $this->validate_location( $location, $code_type );
			if ( is_wp_error( $location_validation ) ) {
				return $location_validation;
			}
		}

		$snippet_data = array(
			'code'        => $code,
			'code_type'   => $code_type,
			'auto_insert' => $auto_insert_enabled ? 1 : 0,
		);

		if ( 0 < $snippet_id ) {
				$snippet_data['id'] = $snippet_id;
		}

		if ( isset( $arguments['title'] ) ) {
			$snippet_data['title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( $auto_insert_enabled && '' !== $location ) {
			$snippet_data['location'] = $location;
		}

		if ( array_key_exists( 'activate', $arguments ) ) {
			$snippet_data['active'] = (bool) $arguments['activate'];
		}

		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$tags = array();
			foreach ( $arguments['tags'] as $tag ) {
				$tag = sanitize_text_field( $tag );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
			if ( ! empty( $tags ) ) {
				$snippet_data['tags'] = $tags;
			}
		}

		if ( isset( $arguments['note'] ) ) {
			$snippet_data['note'] = wp_kses_post( $arguments['note'] );
		}

		if ( isset( $arguments['priority'] ) ) {
			$snippet_data['priority'] = max( 0, absint( $arguments['priority'] ) );
		}

		if ( isset( $arguments['insert_number'] ) ) {
			$snippet_data['insert_number'] = max( 1, absint( $arguments['insert_number'] ) );
		}

		if ( isset( $arguments['custom_shortcode'] ) ) {
			$snippet_data['custom_shortcode'] = sanitize_key( $arguments['custom_shortcode'] );
		}

		if ( isset( $arguments['device_type'] ) ) {
			$device_type = sanitize_key( $arguments['device_type'] );
			if ( in_array( $device_type, array( 'any', 'desktop', 'mobile' ), true ) ) {
				$snippet_data['device_type'] = $device_type;
			}
		}

		$snippet = new WPCode_Snippet( $snippet_data );
		$result  = $snippet->save();

		if ( false === $result ) {
			$message = '';
			if ( function_exists( 'wpcode' ) && method_exists( wpcode()->error, 'get_last_error_message' ) ) {
				$message = wpcode()->error->get_last_error_message();
			}

			if ( ! $message ) {
				$message = __( 'The WPCode snippet could not be saved.', 'wp-mcp-ai' );
			}

			return new WP_Error( 'wp_mcp_ai_wpcode_save_failed', $message );
		}

		$saved_snippet = new WPCode_Snippet( $result );
		$post          = get_post( $result );

		$response = array(
			'id'            => $result,
			'title'         => $saved_snippet->get_title(),
			'status'        => $post ? $post->post_status : '',
			'code_type'     => $saved_snippet->get_code_type(),
			'auto_insert'   => (bool) $saved_snippet->get_auto_insert(),
			'location'      => $saved_snippet->get_location(),
			'location_name' => $this->get_location_label( $saved_snippet->get_location() ),
			'activated'     => ( $post && 'publish' === $post->post_status ),
			'shortcode'     => $this->build_shortcode( $saved_snippet ),
		);

		if ( ! empty( $snippet_data['tags'] ) ) {
			$response['tags'] = $snippet_data['tags'];
		} else {
			$response['tags'] = $saved_snippet->get_tags();
		}

		return $response;
	}

	/**
	 * Validate a requested auto-insert location against the active WPCode configuration.
	 *
	 * @param string $location  Location slug.
	 * @param string $code_type Code type slug requested for the snippet.
	 *
	 * @return true|WP_Error
	 */
	protected function validate_location( $location, $code_type ) {
		if ( ! function_exists( 'wpcode' ) || ! isset( wpcode()->auto_insert ) ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_unavailable', __( 'WPCode auto-insert locations are unavailable.', 'wp-mcp-ai' ) );
		}

		$types = wpcode()->auto_insert->get_types();
		foreach ( $types as $type ) {
			$locations = $type->get_locations();
			if ( isset( $locations[ $location ] ) ) {
				if ( isset( $type->code_type ) && 'all' !== $type->code_type ) {
					$allowed_type = $type->code_type;
					if ( 'php' === $allowed_type && 'universal' === $code_type ) {
						return true;
					}
					if ( $allowed_type !== $code_type ) {
						return new WP_Error( 'wp_mcp_ai_location_not_supported', __( 'The requested location is not available for this code type.', 'wp-mcp-ai' ) );
					}
				}

				return true;
			}
		}

		return new WP_Error( 'wp_mcp_ai_unknown_location', __( 'The provided auto-insert location is not recognised by WPCode.', 'wp-mcp-ai' ) );
	}

	/**
	 * Build the shortcode string for a snippet.
	 *
	 * @param WPCode_Snippet $snippet Snippet instance.
	 *
	 * @return string
	 */
	protected function build_shortcode( WPCode_Snippet $snippet ) {
		$custom = $snippet->get_custom_shortcode();
		if ( ! empty( $custom ) ) {
			return '[' . sanitize_key( $custom ) . ']';
		}

		return sprintf( '[wpcode id="%d"]', $snippet->get_id() );
	}

	/**
	 * Retrieve the human readable label for a location if available.
	 *
	 * @param string $location Location slug.
	 *
	 * @return string
	 */
	protected function get_location_label( $location ) {
		if ( '' === $location || ! function_exists( 'wpcode' ) || ! isset( wpcode()->auto_insert ) ) {
			return '';
		}

		return wpcode()->auto_insert->get_location_label( $location );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
