<?php
/**
 * Social media tool (ecosystem port — Wave F2, social tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/social-media/class-wp-mcp-ai-tool-schedule-social-posts.php` for the standalone
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
 * Schedules social media posts for publication.
 *
 * Accepts an array of post data objects and creates scheduled social
 * media post entries. Supports dry_run mode for preview.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Schedule_Social_Posts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'schedule_social_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Schedule Social Posts', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Schedules social media posts for publication. Accepts post data and scheduling parameters.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'posts'   => array(
					'type'        => 'array',
					'description' => __( 'Array of social media posts to schedule.', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'platform'       => array(
								'type'        => 'string',
								'description' => __( 'Target platform.', 'nvoos-content-graph-pro' ),
								'enum'        => array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok' ),
							),
							'content'        => array(
								'type'        => 'string',
								'description' => __( 'Post content.', 'nvoos-content-graph-pro' ),
							),
							'scheduled_time' => array(
								'type'        => 'string',
								'description' => __( 'Scheduled publish time (ISO 8601 format).', 'nvoos-content-graph-pro' ),
							),
							'image_url'      => array(
								'type'        => 'string',
								'description' => __( 'Optional image URL to attach.', 'nvoos-content-graph-pro' ),
							),
						),
						'required'   => array( 'platform', 'content', 'scheduled_time' ),
					),
					'minItems'    => 1,
				),
				'dry_run' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, preview the scheduling without actually creating posts.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'posts' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'risk_level'            => 'low',
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
			'scheduling',
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

		return __( 'Schedule Social Posts tool is not available.', 'nvoos-content-graph-pro' );
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
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to schedule social media posts.', 'nvoos-content-graph-pro' )
			);
		}

		// Parse arguments.
		$posts   = isset( $arguments['posts'] ) ? $arguments['posts'] : array();
		$dry_run = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return new WP_Error(
				'missing_posts',
				__( 'At least one post must be provided for scheduling.', 'nvoos-content-graph-pro' )
			);
		}

		$valid_platforms = array( 'facebook', 'instagram', 'twitter', 'linkedin', 'tiktok' );
		$scheduled       = array();
		$errors          = array();

		foreach ( $posts as $index => $post_data ) {
			// Validate required fields.
			if ( empty( $post_data['platform'] ) || empty( $post_data['content'] ) || empty( $post_data['scheduled_time'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Missing required fields: platform, content, or scheduled_time.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$platform       = sanitize_text_field( $post_data['platform'] );
			$content        = sanitize_textarea_field( $post_data['content'] );
			$scheduled_time = sanitize_text_field( $post_data['scheduled_time'] );
			$image_url      = isset( $post_data['image_url'] ) ? esc_url_raw( $post_data['image_url'] ) : '';

			if ( ! in_array( $platform, $valid_platforms, true ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => sprintf(
						/* translators: %s: invalid platform name */
						__( 'Invalid platform "%s".', 'nvoos-content-graph-pro' ),
						$platform
					),
				);
				continue;
			}

			// Validate scheduled time.
			$scheduled_timestamp = strtotime( $scheduled_time );
			if ( false === $scheduled_timestamp ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Invalid scheduled_time format. Use ISO 8601.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			if ( $scheduled_timestamp <= current_time( 'timestamp' ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Scheduled time must be in the future.', 'nvoos-content-graph-pro' ),
				);
				continue;
			}

			$entry = array(
				'platform'       => $platform,
				'content'        => $content,
				'scheduled_time' => gmdate( 'c', $scheduled_timestamp ),
				'image_url'      => $image_url,
			);

			if ( ! $dry_run ) {
				$post_id = $this->create_social_post( $platform, $content, $scheduled_timestamp, $image_url );
				if ( is_wp_error( $post_id ) ) {
					$errors[] = array(
						'index'   => $index,
						'message' => $post_id->get_error_message(),
					);
					continue;
				}
				$entry['post_id'] = $post_id;
				$entry['status']  = 'scheduled';
			} else {
				$entry['status'] = 'dry_run';
			}

			$scheduled[] = $entry;
		}

		return array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'message'    => $dry_run
				? __( 'Dry run complete. No posts were actually scheduled.', 'nvoos-content-graph-pro' )
				: sprintf(
					/* translators: %d: number of posts scheduled */
					__( '%d social media posts scheduled successfully.', 'nvoos-content-graph-pro' ),
					count( $scheduled )
				),
			'scheduled'  => $scheduled,
			'errors'     => $errors,
			'total'      => count( $posts ),
			'successful' => count( $scheduled ),
			'failed'     => count( $errors ),
		);
	}

	/**
	 * Create a scheduled social media post entry.
	 *
	 * @since 2.8.0
	 * @param string $platform          Target platform.
	 * @param string $content           Post content.
	 * @param int    $scheduled_timestamp Scheduled publish timestamp.
	 * @param string $image_url         Optional image URL.
	 * @return int|WP_Error Post ID or error.
	 */
	private function create_social_post( $platform, $content, $scheduled_timestamp, $image_url = '' ) {
		$post_data = array(
			'post_type'    => 'mcp_social_post',
			'post_status'  => 'future',
			'post_content' => $content,
			'post_title'   => sprintf(
				/* translators: %1$s: platform, %2$s: date */
				__( 'Social Post - %1$s - %2$s', 'nvoos-content-graph-pro' ),
				ucfirst( $platform ),
				gmdate( 'Y-m-d H:i', $scheduled_timestamp )
			),
			'post_date'    => gmdate( 'Y-m-d H:i:s', $scheduled_timestamp ),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_mcp_social_platform', $platform );
		update_post_meta( $post_id, '_mcp_social_scheduled_time', gmdate( 'c', $scheduled_timestamp ) );

		if ( ! empty( $image_url ) ) {
			update_post_meta( $post_id, '_mcp_social_image_url', $image_url );
		}

		return $post_id;
	}
}
