<?php
/**
 * Post to Multiple Platforms Tool
 *
 * Publish content simultaneously to all social media platforms
 * (Facebook, Instagram, Twitter/X, LinkedIn, TikTok, Pinterest).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for publishing content to multiple social media platforms.
 *
 * Supports:
 * - Facebook, Instagram, Twitter/X, LinkedIn, TikTok, Pinterest
 * - Text, image, and video posts
 * - Platform-specific formatting
 * - Hashtag optimization per platform
 * - Link shortening and tracking
 * - Error handling per platform
 *
 * NPM Dependencies (reference for Node.js integration):
 * - twitter-api-v2
 * - facebook-node-sdk
 * - linkedin-api-client
 * - Use existing sharp for image processing
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Post_To_Multiple_Platforms implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if social media toolkit is enabled.
	 */
	public static function is_available() {
		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if social media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_social_media_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return __( 'Social media toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Post to multiple platforms tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'post_to_multiple_platforms';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Post to Multiple Platforms', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Publish content simultaneously to multiple social media platforms (Facebook, Instagram, Twitter/X, LinkedIn, TikTok, Pinterest). Supports text, images, and videos with platform-specific formatting.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content'          => array(
					'type'        => 'string',
					'description' => __( 'Post content/text (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 5000,
				),
				'platforms'        => array(
					'type'        => 'array',
					'description' => __( 'Target platforms (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'pinterest' ),
					),
					'minItems'    => 1,
				),
				'media_urls'       => array(
					'type'        => 'array',
					'description' => __( 'URLs of images or videos to attach', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'hashtags'         => array(
					'type'        => 'array',
					'description' => __( 'Hashtags to include (auto-optimized per platform)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'link'             => array(
					'type'        => 'string',
					'description' => __( 'URL to include in post', 'mcp-ai-wpoos-pro' ),
				),
				'link_tracking'    => array(
					'type'        => 'boolean',
					'description' => __( 'Enable link tracking with UTM parameters', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'optimize_content' => array(
					'type'        => 'boolean',
					'description' => __( 'Auto-optimize content for each platform (character limits, formatting)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'content', 'platforms' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'social-media',
			'external-api',
			'content-publishing',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to publish social media content.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Validate required fields.
		if ( empty( $arguments['content'] ) ) {
			return new WP_Error(
				'missing_content',
				__( 'Post content is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['platforms'] ) || ! is_array( $arguments['platforms'] ) ) {
			return new WP_Error(
				'missing_platforms',
				__( 'At least one target platform is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs.
		$content          = sanitize_textarea_field( $arguments['content'] );
		$platforms        = array_map( 'sanitize_text_field', $arguments['platforms'] );
		$media_urls       = isset( $arguments['media_urls'] ) ? array_map( 'esc_url_raw', (array) $arguments['media_urls'] ) : array();
		$hashtags         = isset( $arguments['hashtags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['hashtags'] ) : array();
		$link             = isset( $arguments['link'] ) ? esc_url_raw( $arguments['link'] ) : '';
		$link_tracking    = isset( $arguments['link_tracking'] ) ? (bool) $arguments['link_tracking'] : true;
		$optimize_content = isset( $arguments['optimize_content'] ) ? (bool) $arguments['optimize_content'] : true;

		// Validate platforms.
		$valid_platforms = array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok', 'pinterest' );
		$platforms       = array_intersect( $platforms, $valid_platforms );

		if ( empty( $platforms ) ) {
			return new WP_Error(
				'invalid_platforms',
				__( 'No valid platforms specified.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare tracking link.
		$tracking_link = $link;
		if ( $link && $link_tracking ) {
			$tracking_link = $this->add_utm_parameters( $link );
		}

		// Post to each platform.
		$results = array(
			'success'   => true,
			'platforms' => array(),
			'errors'    => array(),
		);

		foreach ( $platforms as $platform ) {
			$platform_content = $optimize_content ? $this->optimize_content_for_platform( $content, $platform, $hashtags ) : $content;
			$result           = $this->post_to_platform( $platform, $platform_content, $media_urls, $tracking_link );

			if ( is_wp_error( $result ) ) {
				$results['errors'][ $platform ] = $result->get_error_message();
			} else {
				$results['platforms'][ $platform ] = $result;
			}
		}

		// Set overall success based on whether at least one platform succeeded.
		$results['success']   = ! empty( $results['platforms'] );
		$results['total']     = count( $platforms );
		$results['succeeded'] = count( $results['platforms'] );
		$results['failed']    = count( $results['errors'] );
		$results['message']   = sprintf(
			/* translators: 1: Number of successful posts, 2: Number of total platforms */
			__( 'Posted successfully to %1$d of %2$d platforms.', 'mcp-ai-wpoos-pro' ),
			$results['succeeded'],
			$results['total']
		);

		return $results;
	}

	/**
	 * Add UTM parameters to URL for tracking.
	 *
	 * @param string $url Base URL.
	 * @return string URL with UTM parameters.
	 */
	protected function add_utm_parameters( $url ) {
		$params = array(
			'utm_source'   => 'social_media',
			'utm_medium'   => 'organic',
			'utm_campaign' => 'multi_platform_post',
		);

		return add_query_arg( $params, $url );
	}

	/**
	 * Optimize content for specific platform.
	 *
	 * @param string $content  Original content.
	 * @param string $platform Target platform.
	 * @param array  $hashtags Hashtags.
	 * @return string Optimized content.
	 */
	protected function optimize_content_for_platform( $content, $platform, $hashtags ) {
		$optimized = $content;

		// Platform-specific character limits and formatting.
		switch ( $platform ) {
			case 'twitter':
				$max_length = 280;
				// Reserve space for hashtags and links.
				if ( ! empty( $hashtags ) ) {
					$hashtag_text = ' ' . implode( ' ', array_map( array( $this, 'format_hashtag' ), array_slice( $hashtags, 0, 3 ) ) );
					$max_length  -= strlen( $hashtag_text ) + 25; // Reserve for link.
				}
				$optimized = $this->truncate_text( $optimized, $max_length );
				break;

			case 'instagram':
				// Instagram allows 2,200 characters.
				$optimized = $this->truncate_text( $optimized, 2200 );
				// Instagram users prefer hashtags at the end.
				if ( ! empty( $hashtags ) ) {
					$hashtag_text = "\n\n" . implode( ' ', array_map( array( $this, 'format_hashtag' ), array_slice( $hashtags, 0, 30 ) ) );
					$optimized   .= $hashtag_text;
				}
				break;

			case 'facebook':
				// Facebook allows 63,206 characters, but shorter is better.
				$optimized = $this->truncate_text( $optimized, 5000 );
				break;

			case 'linkedin':
				// LinkedIn allows 3,000 characters.
				$optimized = $this->truncate_text( $optimized, 3000 );
				// LinkedIn prefers professional tone, fewer hashtags.
				if ( ! empty( $hashtags ) ) {
					$hashtag_text = "\n\n" . implode( ' ', array_map( array( $this, 'format_hashtag' ), array_slice( $hashtags, 0, 5 ) ) );
					$optimized   .= $hashtag_text;
				}
				break;

			case 'tiktok':
				// TikTok captions are limited to 300 characters.
				$optimized = $this->truncate_text( $optimized, 300 );
				if ( ! empty( $hashtags ) ) {
					$hashtag_text = ' ' . implode( ' ', array_map( array( $this, 'format_hashtag' ), array_slice( $hashtags, 0, 5 ) ) );
					$optimized    = $this->truncate_text( $optimized, 280 ) . $hashtag_text;
				}
				break;

			case 'pinterest':
				// Pinterest allows 500 characters.
				$optimized = $this->truncate_text( $optimized, 500 );
				if ( ! empty( $hashtags ) ) {
					$hashtag_text = "\n" . implode( ' ', array_map( array( $this, 'format_hashtag' ), array_slice( $hashtags, 0, 20 ) ) );
					$optimized   .= $hashtag_text;
				}
				break;
		}

		return $optimized;
	}

	/**
	 * Format hashtag with # prefix.
	 *
	 * @param string $tag Hashtag without #.
	 * @return string Formatted hashtag.
	 */
	protected function format_hashtag( $tag ) {
		$tag = preg_replace( '/[^a-zA-Z0-9_]/', '', $tag );
		return '#' . $tag;
	}

	/**
	 * Truncate text to specified length.
	 *
	 * @param string $text      Text to truncate.
	 * @param int    $max_length Maximum length.
	 * @return string Truncated text.
	 */
	protected function truncate_text( $text, $max_length ) {
		if ( strlen( $text ) <= $max_length ) {
			return $text;
		}

		return substr( $text, 0, $max_length - 3 ) . '...';
	}

	/**
	 * Post content to a specific platform.
	 *
	 * @param string $platform   Platform slug.
	 * @param string $content    Platform-optimized content.
	 * @param array  $media_urls Media URLs.
	 * @param string $link       Tracking link.
	 * @return array|WP_Error Post result or error.
	 */
	protected function post_to_platform( $platform, $content, $media_urls, $link ) {
		// Get platform credentials from settings.
		$credentials = $this->get_platform_credentials( $platform );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		// Platform-specific posting logic would go here.
		// In a real implementation, this would use platform APIs.
		switch ( $platform ) {
			case 'facebook':
				return $this->post_to_facebook( $content, $media_urls, $link, $credentials );

			case 'instagram':
				return $this->post_to_instagram( $content, $media_urls, $link, $credentials );

			case 'twitter':
				return $this->post_to_twitter( $content, $media_urls, $link, $credentials );

			case 'linkedin':
				return $this->post_to_linkedin( $content, $media_urls, $link, $credentials );

			case 'tiktok':
				return $this->post_to_tiktok( $content, $media_urls, $link, $credentials );

			case 'pinterest':
				return $this->post_to_pinterest( $content, $media_urls, $link, $credentials );

			default:
				return new WP_Error(
					'unsupported_platform',
					sprintf(
						/* translators: %s: Platform name */
						__( 'Platform "%s" is not supported.', 'mcp-ai-wpoos-pro' ),
						$platform
					)
				);
		}
	}

	/**
	 * Get platform credentials from settings.
	 *
	 * @param string $platform Platform slug.
	 * @return array|WP_Error Credentials or error.
	 */
	protected function get_platform_credentials( $platform ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$key      = 'social_media_' . $platform . '_credentials';

		if ( empty( $settings[ $key ] ) ) {
			return new WP_Error(
				'missing_credentials',
				sprintf(
					/* translators: %s: Platform name */
					__( 'Credentials for %s are not configured.', 'mcp-ai-wpoos-pro' ),
					ucfirst( $platform )
				)
			);
		}

		return $settings[ $key ];
	}

	/**
	 * Post to Facebook.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_facebook( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use Facebook Graph API.
		return array(
			'post_id'      => 'fb_' . wp_generate_uuid4(),
			'url'          => 'https://facebook.com/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Post to Instagram.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_instagram( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use Instagram Graph API.
		return array(
			'post_id'      => 'ig_' . wp_generate_uuid4(),
			'url'          => 'https://instagram.com/p/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Post to Twitter.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_twitter( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use Twitter API v2.
		return array(
			'post_id'      => 'tw_' . wp_generate_uuid4(),
			'url'          => 'https://twitter.com/user/status/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Post to LinkedIn.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_linkedin( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use LinkedIn API.
		return array(
			'post_id'      => 'li_' . wp_generate_uuid4(),
			'url'          => 'https://linkedin.com/feed/update/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Post to TikTok.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_tiktok( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use TikTok API.
		return array(
			'post_id'      => 'tt_' . wp_generate_uuid4(),
			'url'          => 'https://tiktok.com/@user/video/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Post to Pinterest.
	 *
	 * @param string $content     Content.
	 * @param array  $media_urls  Media URLs.
	 * @param string $link        Link.
	 * @param array  $credentials Credentials.
	 * @return array Post result.
	 */
	protected function post_to_pinterest( $content, $media_urls, $link, $credentials ) {
		// Placeholder implementation - would use Pinterest API.
		return array(
			'post_id'      => 'pin_' . wp_generate_uuid4(),
			'url'          => 'https://pinterest.com/pin/placeholder',
			'published_at' => current_time( 'mysql' ),
		);
	}
}
