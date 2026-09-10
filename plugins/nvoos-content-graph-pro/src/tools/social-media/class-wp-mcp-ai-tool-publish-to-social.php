<?php
/**
 * Social media tool (ecosystem port — Wave F2, social tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/social-media/class-wp-mcp-ai-tool-publish-to-social.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps where the
 * base references path constants (capture base require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes content immediately to social media platforms.
 *
 * Supports dry_run mode for previewing what would be published.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Publish_To_Social implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'publish_to_social';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Publish to Social', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Publishes content immediately to social media platforms. Supports dry_run mode.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'platform'  => array(
					'type'        => 'string',
					'description' => __( 'Social media platform to publish to.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok' ),
				),
				'content'   => array(
					'type'        => 'string',
					'description' => __( 'Content to publish.', 'nvoos-content-graph-pro' ),
					'minLength'   => 1,
				),
				'image_url' => array(
					'type'        => 'string',
					'description' => __( 'Optional image URL to attach to the post.', 'nvoos-content-graph-pro' ),
				),
				'link_url'  => array(
					'type'        => 'string',
					'description' => __( 'Optional link URL to include in the post.', 'nvoos-content-graph-pro' ),
				),
				'dry_run'   => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the publish without actually posting.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'platform', 'content' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'publish_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'social_media',
			'post_type'             => 'mcp_social_post',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'social_media_manager', 'content_manager', 'marketer' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the Social Media Toolkit to be enabled in plugin settings.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'The social media toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Publish to Social tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'publish_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to publish social media posts.', 'nvoos-content-graph-pro' )
			);
		}

		// Parse and sanitize arguments.
		$platform  = isset( $arguments['platform'] ) ? sanitize_text_field( $arguments['platform'] ) : '';
		$content   = isset( $arguments['content'] ) ? sanitize_textarea_field( $arguments['content'] ) : '';
		$image_url = isset( $arguments['image_url'] ) ? esc_url_raw( $arguments['image_url'] ) : '';
		$link_url  = isset( $arguments['link_url'] ) ? esc_url_raw( $arguments['link_url'] ) : '';
		$dry_run   = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Validate required fields.
		if ( empty( $platform ) ) {
			return new WP_Error(
				'missing_platform',
				__( 'Platform is required for publishing.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $content ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Content is required for publishing.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate platform.
		$valid_platforms = array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok' );
		if ( ! in_array( $platform, $valid_platforms, true ) ) {
			return new WP_Error(
				'invalid_platform',
				sprintf(
					/* translators: %s: invalid platform name */
					__( 'Invalid platform "%s".', 'nvoos-content-graph-pro' ),
					$platform
				)
			);
		}

		// Platform-specific character limit checks.
		$platform_limits = array(
			'twitter'   => 280,
			'linkedin'  => 3000,
			'instagram' => 2200,
			'facebook'  => 63206,
			'tiktok'    => 2200,
		);

		if ( isset( $platform_limits[ $platform ] ) && mb_strlen( $content ) > $platform_limits[ $platform ] ) {
			return new WP_Error(
				'content_too_long',
				sprintf(
					/* translators: 1: platform name, 2: character limit */
					__( 'Content exceeds %1$s character limit of %2$d characters.', 'nvoos-content-graph-pro' ),
					ucfirst( $platform ),
					$platform_limits[ $platform ]
				)
			);
		}

		// Build publish payload.
		$payload = array(
			'platform'  => $platform,
			'content'   => $content,
			'image_url' => $image_url,
			'link_url'  => $link_url,
			'timestamp' => gmdate( 'c' ),
		);

		if ( $dry_run ) {
			return array(
				'success' => true,
				'dry_run' => true,
				'message' => sprintf(
					/* translators: %s: platform name */
					__( 'Dry run: Content would be published to %s.', 'nvoos-content-graph-pro' ),
					ucfirst( $platform )
				),
				'payload' => $payload,
			);
		}

		// Create the published social post.
		$post_data = array(
			'post_type'    => 'mcp_social_post',
			'post_status'  => 'publish',
			'post_content' => $content,
			'post_title'   => sprintf(
				/* translators: %1$s: platform, %2$s: date */
				__( 'Published - %1$s - %2$s', 'nvoos-content-graph-pro' ),
				ucfirst( $platform ),
				gmdate( 'Y-m-d H:i' )
			),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_mcp_social_platform', $platform );
		update_post_meta( $post_id, '_mcp_social_image_url', $image_url );
		update_post_meta( $post_id, '_mcp_social_link_url', $link_url );
		update_post_meta( $post_id, '_mcp_social_published_at', gmdate( 'c' ) );

		return array(
			'success' => true,
			'dry_run' => false,
			'message' => sprintf(
				/* translators: %s: platform name */
				__( 'Content published to %s successfully.', 'nvoos-content-graph-pro' ),
				ucfirst( $platform )
			),
			'post_id' => $post_id,
			'payload' => $payload,
		);
	}
}
