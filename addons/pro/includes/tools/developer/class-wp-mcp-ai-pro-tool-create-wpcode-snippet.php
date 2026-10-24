<?php
/**
 * Tool that creates or updates WPCode snippets.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-wpcode-snippet-repository.php';

/**
 * Creates or updates WPCode snippets using the WPCode plugin APIs.
 *
 * This tool is restricted from chat-client by default for security reasons
 * as it allows code execution.
 */
class WP_MCP_AI_Pro_Tool_Create_WPCode_Snippet implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Context_Restrictions_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * Allowed code types that map to WPCode executor types.
	 *
	 * @var string[]
	 */
	private $allowed_code_types = array( 'php', 'html', 'css', 'js', 'universal', 'text' );

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		return WP_MCP_AI_Pro_WPCode_Snippet_Repository::is_available();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! function_exists( 'wpcode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
			return __( 'Install and activate the WPCode plugin to manage code snippets.', 'mcp-ai-wpoos-pro' );
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
		return __( 'Create WPCode Snippet', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WPCode snippet or updates an existing one with the supplied configuration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Creating or updating WPCode snippets for PHP, JS, CSS, HTML, and other supported code types.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Formatting code without saving a snippet; use format_code_prettier.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'format_code_prettier', 'list_wpcode_snippets' ),
			'notes'           => __( 'Requires the WPCode plugin; auto_insert=true needs a location valid for the chosen code_type.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'snippet_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Existing WPCode snippet ID to update. Leave empty to create a new snippet.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
				),
				'title'             => array(
					'type'        => 'string',
					'description' => __( 'Snippet title displayed in the WPCode list.', 'mcp-ai-wpoos-pro' ),
				),
				'code'              => array(
					'type'        => 'string',
					'description' => __( 'The snippet code to store. Accepts PHP, HTML, CSS, JS and other formats supported by WPCode.', 'mcp-ai-wpoos-pro' ),
				),
				'code_type'         => array(
					'type'        => 'string',
					'enum'        => $this->allowed_code_types,
					'description' => __( 'WPCode code type slug that controls execution (php, html, css, js, universal, etc).', 'mcp-ai-wpoos-pro' ),
				),
				'auto_insert'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the snippet should auto-insert in a location (true) or only be available via shortcode (false).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'location'          => array(
					'type'        => 'string',
					'description' => __( 'Auto-insert location slug, such as site_wide_header, before_post_content or everywhere.', 'mcp-ai-wpoos-pro' ),
				),
				'activate'          => array(
					'type'        => 'boolean',
					'description' => __( 'Set to true to activate (publish) the snippet after saving.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'tags'              => array(
					'type'        => 'array',
					'description' => __( 'Optional array of tag slugs to assign to the snippet.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'note'              => array(
					'type'        => 'string',
					'description' => __( 'Internal note stored with the snippet.', 'mcp-ai-wpoos-pro' ),
				),
				'priority'          => array(
					'type'        => 'integer',
					'description' => __( 'Execution priority for the snippet. Lower numbers run earlier.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'insert_number'     => array(
					'type'        => 'integer',
					'description' => __( 'Paragraph, word or loop index for locations that support numbered insertion.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'custom_shortcode'  => array(
					'type'        => 'string',
					'description' => __( 'Optional custom shortcode tag to register when auto insert is disabled.', 'mcp-ai-wpoos-pro' ),
				),
				'device_type'       => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'desktop', 'mobile' ),
					'description' => __( 'Restrict snippet execution to a specific device type.', 'mcp-ai-wpoos-pro' ),
				),
				'conditional_logic' => array(
					'type'        => 'array',
					'description' => __( 'Optional WPCode Smart Conditional Logic groups. Each group is a list of rules with rule_type, operator and value keys; groups are OR-ed, rules within a group are AND-ed.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'rule_type' => array( 'type' => 'string' ),
								'operator'  => array( 'type' => 'string' ),
								'value'     => array( 'type' => array( 'string', 'integer' ) ),
							),
						),
					),
				),
			),
			'required'             => array( 'code', 'code_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => 'snippet_id',
			'consumes' => array( 'snippet_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// phpcs:disable WordPressVIPMinimum.Security.UserCan
		if ( ! $user_id || ! user_can( $user_id, 'wpcode_edit_snippets' ) ) { // phpcs:ignore WordPressVIPMinimum.Security.UserCan.Unknown
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage WPCode snippets.', 'mcp-ai-wpoos-pro' ) );
		}
		// phpcs:enable WordPressVIPMinimum.Security.UserCan

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$repository = new WP_MCP_AI_Pro_WPCode_Snippet_Repository();
		if ( ! $repository->is_available() ) {
			return new WP_Error( 'wp_mcp_ai_wpcode_missing', __( 'WPCode must be installed and active to manage snippets.', 'mcp-ai-wpoos-pro' ) );
		}

		$code      = isset( $arguments['code'] ) ? (string) $arguments['code'] : '';
		$code      = wp_check_invalid_utf8( $code );
		$code_type = isset( $arguments['code_type'] ) ? sanitize_key( $arguments['code_type'] ) : '';

		if ( '' === $code ) {
			return new WP_Error( 'wp_mcp_ai_missing_code', __( 'Snippet code is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $code_type || ! in_array( $code_type, $this->allowed_code_types, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_code_type', __( 'Please provide a supported WPCode code type.', 'mcp-ai-wpoos-pro' ) );
		}

		$snippet_id = isset( $arguments['snippet_id'] ) ? absint( $arguments['snippet_id'] ) : 0;
		if ( 0 < $snippet_id ) {
			$existing = $repository->get( $snippet_id );
			if ( is_wp_error( $existing ) ) {
				return $existing;
			}
		}

		$auto_insert_enabled = array_key_exists( 'auto_insert', $arguments ) ? (bool) $arguments['auto_insert'] : true;
		$location            = isset( $arguments['location'] ) ? sanitize_key( $arguments['location'] ) : '';

		if ( $auto_insert_enabled && '' === $location ) {
			return new WP_Error( 'wp_mcp_ai_missing_location', __( 'An auto-insert location is required when auto_insert is true.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $auto_insert_enabled && '' !== $location ) {
			$location_validation = $repository->validate_location( $location, $code_type );
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

		if ( isset( $arguments['conditional_logic'] ) && is_array( $arguments['conditional_logic'] ) ) {
			$groups = array();
			foreach ( $arguments['conditional_logic'] as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				$rules = array();
				foreach ( $group as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}
					$rule_type = isset( $rule['rule_type'] ) ? sanitize_key( $rule['rule_type'] ) : '';
					$operator  = isset( $rule['operator'] ) ? sanitize_text_field( $rule['operator'] ) : '';
					if ( '' === $rule_type ) {
						continue;
					}
					$rules[] = array(
						'rule_type' => $rule_type,
						'operator'  => $operator,
						'value'     => isset( $rule['value'] ) ? $rule['value'] : '',
					);
				}
				if ( ! empty( $rules ) ) {
					$groups[] = $rules;
				}
			}
			if ( ! empty( $groups ) ) {
				$snippet_data['conditional_logic'] = $groups;
			}
		}

		$result = $repository->save( $snippet_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$saved_snippet = new WPCode_Snippet( $result );
		$post          = get_post( $result );

		$response = array(
			'id'            => $result,
			'snippet_id'    => $result,
			'title'         => $saved_snippet->get_title(),
			'status'        => $post ? $post->post_status : '',
			'code_type'     => $saved_snippet->get_code_type(),
			'auto_insert'   => (bool) $saved_snippet->get_auto_insert(),
			'location'      => $saved_snippet->get_location(),
			'location_name' => $this->get_location_label( $saved_snippet->get_location() ),
			'activated'     => ( $post && 'publish' === $post->post_status ),
			'shortcode'     => $repository->build_shortcode( $saved_snippet ),
		);

		if ( ! empty( $snippet_data['tags'] ) ) {
			$response['tags'] = $snippet_data['tags'];
		} else {
			$response['tags'] = $saved_snippet->get_tags();
		}

		if ( isset( $snippet_data['conditional_logic'] ) ) {
			$response['conditional_logic'] = $repository->get_conditional_logic( $result );
		}

		return $response;
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
			'pro',                  // Tool is part of the Pro tier.
			'write',                // Creates or modifies data.
			'state-changing',       // Modifies database or site state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
