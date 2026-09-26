<?php
/**
 * Tool: update_site_settings — Updates allowlisted WordPress site settings.
 *
 * Port of mcp-wordpress wp_update_site_settings tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a curated allowlist of core WordPress general settings.
 *
 * Only a fixed set of safe, well-typed options can be changed (title,
 * tagline, timezone, date/time formats, start of week, admin email, posts
 * per page, registration, default comment status). Arbitrary option writes
 * remain out of scope — unlike a generic update_option tool, this class
 * validates every value against its option type before persisting.
 */
class WP_MCP_AI_Tool_Update_Site_Settings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_site_settings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Site Settings', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates allowlisted core WordPress settings: site title, tagline, timezone, date/time formats, start of week, admin email, posts per page, user registration, and default comment status. Each value is validated before saving.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Changing core general settings that this tool explicitly lists.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Writing arbitrary options or third-party plugin settings; this tool intentionally supports only a safe allowlist.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_site_settings', 'get_environment_status' ),
			'notes'           => __( 'Requires manage_options. Only provided fields are updated; invalid values are rejected with a WP_Error before anything is saved.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'                  => array(
					'type'        => 'string',
					'description' => __( 'The site title (blogname).', 'mcp-ai-wpoos' ),
					'maxLength'   => 200,
				),
				'description'            => array(
					'type'        => 'string',
					'description' => __( 'The site tagline or description (blogdescription).', 'mcp-ai-wpoos' ),
					'maxLength'   => 500,
				),
				'timezone'               => array(
					'type'        => 'string',
					'description' => __( 'A valid PHP timezone identifier, e.g. "America/New_York" or "Europe/London".', 'mcp-ai-wpoos' ),
				),
				'date_format'            => array(
					'type'        => 'string',
					'description' => __( 'The site date format (e.g. "F j, Y").', 'mcp-ai-wpoos' ),
					'maxLength'   => 100,
				),
				'time_format'            => array(
					'type'        => 'string',
					'description' => __( 'The site time format (e.g. "g:i a").', 'mcp-ai-wpoos' ),
					'maxLength'   => 100,
				),
				'start_of_week'          => array(
					'type'        => 'integer',
					'description' => __( 'First day of the week, 0 (Sunday) through 6 (Saturday).', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 6,
				),
				'admin_email'            => array(
					'type'        => 'string',
					'description' => __( 'The site administration email address.', 'mcp-ai-wpoos' ),
				),
				'posts_per_page'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of posts shown per page on the blog.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'users_can_register'     => array(
					'type'        => 'boolean',
					'description' => __( 'Whether anyone can register an account on this site.', 'mcp-ai-wpoos' ),
				),
				'default_comment_status' => array(
					'type'        => 'string',
					'description' => __( 'Default comment status for new posts.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'open', 'closed' ),
				),
			),
			'required'             => array(),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'local-only',
			'requires-capability',
			'state-changing',
			'reversible',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'developer_technical',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'systems_administrator', 'web_developer' ),
			'risk_level'            => 'standard',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update site settings.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update site settings.', 'mcp-ai-wpoos' ) );
		}

		// Build the validated update map BEFORE touching any option so a
		// rejected value never leaves a partially applied update.
		$updates = array();

		if ( isset( $arguments['title'] ) ) {
			$title = sanitize_text_field( $arguments['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'wp_mcp_ai_invalid_title', __( 'Site title cannot be empty.', 'mcp-ai-wpoos' ) );
			}
			$updates['blogname'] = $title;
		}

		if ( isset( $arguments['description'] ) ) {
			$updates['blogdescription'] = sanitize_text_field( $arguments['description'] );
		}

		if ( isset( $arguments['timezone'] ) ) {
			$timezone = sanitize_text_field( $arguments['timezone'] );
			if ( '' === $timezone || ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_timezone', __( 'The provided timezone is not a valid PHP timezone identifier.', 'mcp-ai-wpoos' ) );
			}
			$updates['timezone_string'] = $timezone;
			$updates['gmt_offset']      = 0; // timezone_string takes precedence over the numeric offset.
		}

		if ( isset( $arguments['date_format'] ) ) {
			$date_format = sanitize_text_field( $arguments['date_format'] );
			if ( '' === $date_format ) {
				return new WP_Error( 'wp_mcp_ai_invalid_date_format', __( 'Date format cannot be empty.', 'mcp-ai-wpoos' ) );
			}
			$updates['date_format'] = $date_format;
		}

		if ( isset( $arguments['time_format'] ) ) {
			$time_format = sanitize_text_field( $arguments['time_format'] );
			if ( '' === $time_format ) {
				return new WP_Error( 'wp_mcp_ai_invalid_time_format', __( 'Time format cannot be empty.', 'mcp-ai-wpoos' ) );
			}
			$updates['time_format'] = $time_format;
		}

		if ( isset( $arguments['start_of_week'] ) ) {
			$start_of_week = absint( $arguments['start_of_week'] );
			if ( $start_of_week > 6 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_start_of_week', __( 'Start of week must be between 0 (Sunday) and 6 (Saturday).', 'mcp-ai-wpoos' ) );
			}
			$updates['start_of_week'] = $start_of_week;
		}

		if ( isset( $arguments['admin_email'] ) ) {
			$admin_email = sanitize_email( $arguments['admin_email'] );
			if ( ! is_email( $admin_email ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_admin_email', __( 'The provided admin email address is invalid.', 'mcp-ai-wpoos' ) );
			}
			$updates['admin_email'] = $admin_email;
		}

		if ( isset( $arguments['posts_per_page'] ) ) {
			$posts_per_page = absint( $arguments['posts_per_page'] );
			if ( $posts_per_page < 1 || $posts_per_page > 500 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_posts_per_page', __( 'Posts per page must be between 1 and 500.', 'mcp-ai-wpoos' ) );
			}
			$updates['posts_per_page'] = $posts_per_page;
		}

		if ( isset( $arguments['users_can_register'] ) ) {
			$updates['users_can_register'] = filter_var( $arguments['users_can_register'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
		}

		if ( isset( $arguments['default_comment_status'] ) ) {
			$status = sanitize_key( $arguments['default_comment_status'] );
			if ( ! in_array( $status, array( 'open', 'closed' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_comment_status', __( 'Default comment status must be "open" or "closed".', 'mcp-ai-wpoos' ) );
			}
			$updates['default_comment_status'] = $status;
		}

		if ( empty( $updates ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No supported settings were provided to update.', 'mcp-ai-wpoos' ) );
		}

		$changed = array();

		foreach ( $updates as $option => $value ) {
			$previous = get_option( $option, null );

			if ( null !== $previous && (string) $previous === (string) $value ) {
				continue;
			}

			$result = update_option( $option, $value );
			if ( ! $result ) {
				return new WP_Error( 'wp_mcp_ai_settings_update_failed', sprintf( /* translators: %s: option name */ __( 'Failed to update the "%s" setting.', 'mcp-ai-wpoos' ), $option ) );
			}

			$changed[] = $option;
		}

		if ( empty( $changed ) ) {
			return array(
				'message' => __( 'All provided settings already match their current values; nothing was changed.', 'mcp-ai-wpoos' ),
				'updated' => array(),
			);
		}

		return array(
			'message' => sprintf(
				/* translators: %d: number of changed settings */
				_n( '%d setting updated.', '%d settings updated.', count( $changed ), 'mcp-ai-wpoos' ),
				count( $changed )
			),
			'updated' => $changed,
		);
	}
}
