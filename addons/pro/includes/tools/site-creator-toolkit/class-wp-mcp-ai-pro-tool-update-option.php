<?php
/**
 * Tool for updating WordPress options.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update Option Tool
 *
 * Updates or creates WordPress options in the wp_options table.
 */
class WP_MCP_AI_Pro_Tool_Update_Option implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Option names that are safe for AI tools to modify.
	 *
	 * Critical WordPress options (siteurl, home, active_plugins, cron,
	 * wp_user_roles, template, stylesheet, users_can_register, etc.) are
	 * deliberately excluded to prevent site takeover and privilege escalation.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const ALLOWED_OPTIONS = array(
		'blogname',
		'blogdescription',
		'admin_email',
		'timezone_string',
		'date_format',
		'time_format',
		'start_of_week',
		'posts_per_page',
		'posts_per_rss',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'default_category',
		'default_post_format',
		'comment_moderation',
		'comments_notify',
		'moderation_notify',
		'comment_registration',
		'thread_comments',
		'thread_comments_depth',
		'default_ping_status',
		'default_comment_status',
		'require_name_email',
		'blog_public',
		'wp_mcp_ai_settings',
	);

	/**
	 * Per-option type schema for value coercion.
	 *
	 * @since 1.0.0
	 * @var array<string,string>
	 */
	const OPTION_TYPES = array(
		'blogname'               => 'string',
		'blogdescription'        => 'string',
		'admin_email'            => 'email',
		'timezone_string'        => 'string',
		'date_format'            => 'string',
		'time_format'            => 'string',
		'start_of_week'          => 'int',
		'posts_per_page'         => 'int',
		'posts_per_rss'          => 'int',
		'show_on_front'          => 'string',
		'page_on_front'          => 'int',
		'page_for_posts'         => 'int',
		'default_category'       => 'int',
		'default_post_format'    => 'string',
		'comment_moderation'     => 'int',
		'comments_notify'        => 'int',
		'moderation_notify'      => 'int',
		'comment_registration'   => 'int',
		'thread_comments'        => 'int',
		'thread_comments_depth'  => 'int',
		'default_ping_status'    => 'string',
		'default_comment_status' => 'string',
		'require_name_email'     => 'int',
		'blog_public'            => 'int',
	);

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_option';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Option', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates a WordPress option value. Can also be used to create a new option.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'option_name'  => array(
					'type'        => 'string',
					'description' => __( 'The name of the option to update (e.g., "blogname").', 'mcp-ai-wpoos-pro' ),
				),
				'option_value' => array(
					'description' => __( 'The new value for the option.', 'mcp-ai-wpoos-pro' ),
					'anyOf'       => array(
						array( 'type' => 'string' ),
						array( 'type' => 'number' ),
						array( 'type' => 'boolean' ),
						array( 'type' => 'object' ),
						array(
							'type'  => 'array',
							'items' => array(),
						),
						array( 'type' => 'null' ),
					),
				),
			),
			'required'             => array( 'option_name', 'option_value' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator features are enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) || empty( $settings['site_creator_allow_option_updates'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The update_option tool is disabled. Enable it in NV oOS → Tools & Features → Site Creator settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage options.', 'mcp-ai-wpoos-pro' )
			);
		}

		$option_name = isset( $arguments['option_name'] ) ? sanitize_text_field( $arguments['option_name'] ) : '';

		if ( empty( $option_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_option_name',
				__( 'Option name not provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce option-name allowlist to prevent modification of critical
		// WordPress options (active_plugins, siteurl, wp_user_roles, etc.)
		// that could lead to site takeover or privilege escalation.
		$is_allowed = in_array( $option_name, self::ALLOWED_OPTIONS, true )
			|| strpos( $option_name, 'wp_mcp_ai_' ) === 0
			|| strpos( $option_name, 'theme_mods_' ) === 0;

		if ( ! $is_allowed ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden_option',
				sprintf(
					/* translators: %s: option name */
					__( 'The option "%s" cannot be modified via AI tools for security reasons.', 'mcp-ai-wpoos-pro' ),
					$option_name
				)
			);
		}

		$option_value = isset( $arguments['option_value'] ) ? $arguments['option_value'] : '';

		// Coerce value to the expected type for the option.
		if ( isset( self::OPTION_TYPES[ $option_name ] ) ) {
			switch ( self::OPTION_TYPES[ $option_name ] ) {
				case 'string':
					$option_value = sanitize_text_field( (string) $option_value );
					break;
				case 'int':
					$option_value = absint( $option_value );
					break;
				case 'email':
					$option_value = sanitize_email( (string) $option_value );
					break;
			}
		}

		// Use update_option which handles both create and update.
		$updated = update_option( $option_name, $option_value );

		// update_option returns false if the value is the same, which isn't an error.
		// Do NOT return the option_value in the response — avoids leaking
		// sensitive values to AI model context.
		return array(
			'success'     => true,
			'option_name' => $option_name,
			'message'     => $updated
				? sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" updated successfully.', 'mcp-ai-wpoos-pro' ),
					$option_name
				)
				: sprintf(
					/* translators: %s: option name */
					__( 'Option "%s" was not updated (the new value may be the same as the old value).', 'mcp-ai-wpoos-pro' ),
					$option_name
				),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Modifies data.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires manage_options capability.
			'state-changing',       // Modifies database state.
			'idempotent',           // Safe to call multiple times.
		);
	}
}
