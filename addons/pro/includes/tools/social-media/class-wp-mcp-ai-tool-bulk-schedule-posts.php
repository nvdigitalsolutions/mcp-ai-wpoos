<?php
/**
 * Bulk Schedule Posts Tool
 *
 * Upload and schedule multiple posts from CSV with
 * platform targeting and bulk management capabilities.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for bulk scheduling social media posts from CSV.
 *
 * Supports:
 * - CSV file upload with post data
 * - Multiple posts in single operation
 * - Platform-specific targeting per post
 * - Validation and preview before scheduling
 * - Error reporting for invalid entries
 * - Batch processing for large files
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Bulk_Schedule_Posts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

		return __( 'Bulk schedule posts tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'bulk_schedule_posts';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Bulk Schedule Posts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Upload and schedule multiple social media posts from CSV file. Supports platform targeting, validation, preview, and batch processing for large files.', 'mcp-ai-wpoos-pro' );
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
				'csv_file'       => array(
					'type'        => 'string',
					'description' => __( 'Path to CSV file or CSV content as string (required)', 'mcp-ai-wpoos-pro' ),
				),
				'csv_content'    => array(
					'type'        => 'string',
					'description' => __( 'CSV content as string (alternative to csv_file)', 'mcp-ai-wpoos-pro' ),
				),
				'column_mapping' => array(
					'type'        => 'object',
					'description' => __( 'Map CSV columns to post fields', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'content'        => array(
							'type'        => 'string',
							'description' => 'Column name for post content',
							'default'     => 'content',
						),
						'platforms'      => array(
							'type'        => 'string',
							'description' => 'Column name for platforms (comma-separated)',
							'default'     => 'platforms',
						),
						'scheduled_time' => array(
							'type'        => 'string',
							'description' => 'Column name for scheduled time',
							'default'     => 'scheduled_time',
						),
						'media_urls'     => array(
							'type'        => 'string',
							'description' => 'Column name for media URLs (comma-separated)',
							'default'     => 'media_urls',
						),
						'hashtags'       => array(
							'type'        => 'string',
							'description' => 'Column name for hashtags (comma-separated)',
							'default'     => 'hashtags',
						),
						'link'           => array(
							'type'        => 'string',
							'description' => 'Column name for link URL',
							'default'     => 'link',
						),
					),
				),
				'timezone'       => array(
					'type'        => 'string',
					'description' => __( 'Timezone for scheduled times (default: WordPress timezone)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'UTC',
				),
				'preview_only'   => array(
					'type'        => 'boolean',
					'description' => __( 'Preview posts without scheduling them', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'skip_errors'    => array(
					'type'        => 'boolean',
					'description' => __( 'Continue processing if individual posts have errors', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'batch_size'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of posts to process in each batch', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
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
			'database-write',
			'file-upload',
			'bulk-operation',
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
				__( 'You do not have permission to bulk schedule social media posts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if toolkit is enabled.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'toolkit_not_enabled',
				self::get_unavailable_reason()
			);
		}

		// Get CSV data.
		$csv_data = $this->get_csv_data( $arguments );

		if ( is_wp_error( $csv_data ) ) {
			return $csv_data;
		}

		// Parse CSV.
		$posts = $this->parse_csv_data( $csv_data, $arguments );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		if ( empty( $posts ) ) {
			return new WP_Error(
				'no_posts_found',
				__( 'No valid posts found in CSV file.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get parameters.
		$timezone     = isset( $arguments['timezone'] ) ? sanitize_text_field( $arguments['timezone'] ) : wp_timezone_string();
		$preview_only = isset( $arguments['preview_only'] ) ? (bool) $arguments['preview_only'] : false;
		$skip_errors  = isset( $arguments['skip_errors'] ) ? (bool) $arguments['skip_errors'] : true;
		$batch_size   = isset( $arguments['batch_size'] ) ? absint( $arguments['batch_size'] ) : 50;

		// Validate posts.
		$validated_posts = $this->validate_posts( $posts, $timezone );

		// If preview only, return validation results.
		if ( $preview_only ) {
			return array(
				'success' => true,
				'preview' => true,
				'total'   => count( $posts ),
				'valid'   => count( $validated_posts['valid'] ),
				'invalid' => count( $validated_posts['invalid'] ),
				'posts'   => $validated_posts['valid'],
				'errors'  => $validated_posts['invalid'],
				'message' => sprintf(
					/* translators: 1: Number of valid posts, 2: Number of total posts */
					__( '%1$d of %2$d posts are valid and ready to schedule.', 'mcp-ai-wpoos-pro' ),
					count( $validated_posts['valid'] ),
					count( $posts )
				),
			);
		}

		// Schedule posts in batches.
		$results = array(
			'success'   => true,
			'total'     => count( $posts ),
			'scheduled' => 0,
			'failed'    => 0,
			'posts'     => array(),
			'errors'    => array(),
		);

		foreach ( $validated_posts['valid'] as $post_data ) {
			$result = $this->schedule_single_post( $post_data, $current_user_id );

			if ( is_wp_error( $result ) ) {
				++$results['failed'];
				$results['errors'][] = array(
					'row'     => $post_data['row_number'],
					'content' => substr( $post_data['content'], 0, 50 ) . '...',
					'error'   => $result->get_error_message(),
				);

				if ( ! $skip_errors ) {
					break;
				}
			} else {
				++$results['scheduled'];
				$results['posts'][] = $result;
			}
		}

		// Include validation errors.
		foreach ( $validated_posts['invalid'] as $invalid_post ) {
			++$results['failed'];
			$results['errors'][] = $invalid_post;
		}

		$results['message'] = sprintf(
			/* translators: 1: Number of scheduled posts, 2: Number of total posts */
			__( 'Successfully scheduled %1$d of %2$d posts.', 'mcp-ai-wpoos-pro' ),
			$results['scheduled'],
			$results['total']
		);

		return $results;
	}

	/**
	 * Get CSV data from file or content.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error CSV data or error.
	 */
	protected function get_csv_data( $arguments ) {
		if ( ! empty( $arguments['csv_content'] ) ) {
			return $arguments['csv_content'];
		}

		if ( ! empty( $arguments['csv_file'] ) ) {
			$file_path = sanitize_text_field( $arguments['csv_file'] );

			// Security: Resolve canonical path to prevent directory traversal attacks.
			$resolved = realpath( $file_path );
			if ( false === $resolved ) {
				return new WP_Error(
					'file_not_found',
					__( 'CSV file not found or not accessible.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Security: Restrict file access to the WordPress uploads directory.
			$upload_dir   = wp_upload_dir();
			$uploads_base = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
			if ( 0 !== strpos( wp_normalize_path( $resolved ), $uploads_base ) ) {
				return new WP_Error(
					'invalid_file_path',
					__( 'CSV file must be located in the WordPress uploads directory.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check file extension on the resolved path.
			$file_extension = strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) );
			if ( 'csv' !== $file_extension ) {
				return new WP_Error(
					'invalid_file_type',
					__( 'File must be a CSV file.', 'mcp-ai-wpoos-pro' )
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local file reading.
			$csv_data = file_get_contents( $resolved );

			if ( false === $csv_data ) {
				return new WP_Error(
					'file_read_error',
					__( 'Failed to read CSV file.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $csv_data;
		}

		return new WP_Error(
			'missing_csv',
			__( 'Either csv_file or csv_content is required.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Parse CSV data into post array.
	 *
	 * @param string $csv_data  CSV data.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error Array of posts or error.
	 */
	protected function parse_csv_data( $csv_data, $arguments ) {
		$column_mapping = isset( $arguments['column_mapping'] ) ? $arguments['column_mapping'] : array();

		// Default column mapping.
		$defaults = array(
			'content'        => 'content',
			'platforms'      => 'platforms',
			'scheduled_time' => 'scheduled_time',
			'media_urls'     => 'media_urls',
			'hashtags'       => 'hashtags',
			'link'           => 'link',
		);

		$mapping = wp_parse_args( $column_mapping, $defaults );

		// Parse CSV.
		$lines = str_getcsv( $csv_data, "\n" );
		if ( empty( $lines ) ) {
			return new WP_Error(
				'empty_csv',
				__( 'CSV file is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get headers.
		$headers = str_getcsv( array_shift( $lines ) );
		$headers = array_map( 'trim', $headers );

		// Parse rows.
		$posts = array();
		foreach ( $lines as $index => $line ) {
			$row = str_getcsv( $line );

			if ( count( $row ) !== count( $headers ) ) {
				continue; // Skip malformed rows.
			}

			$row_data = array_combine( $headers, $row );
			$post     = $this->map_row_to_post( $row_data, $mapping, $index + 2 ); // +2 for header row and 1-based index.

			if ( ! empty( $post ) ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Map CSV row to post data.
	 *
	 * @param array $row     CSV row data.
	 * @param array $mapping Column mapping.
	 * @param int   $row_num Row number.
	 * @return array Post data.
	 */
	protected function map_row_to_post( $row, $mapping, $row_num ) {
		$post = array(
			'row_number' => $row_num,
		);

		// Map each field.
		if ( ! empty( $row[ $mapping['content'] ] ) ) {
			$post['content'] = sanitize_textarea_field( $row[ $mapping['content'] ] );
		}

		if ( ! empty( $row[ $mapping['platforms'] ] ) ) {
			$post['platforms'] = array_map( 'trim', explode( ',', $row[ $mapping['platforms'] ] ) );
		}

		if ( ! empty( $row[ $mapping['scheduled_time'] ] ) ) {
			$post['scheduled_time'] = sanitize_text_field( $row[ $mapping['scheduled_time'] ] );
		}

		if ( ! empty( $row[ $mapping['media_urls'] ] ) ) {
			$post['media_urls'] = array_map( 'trim', explode( ',', $row[ $mapping['media_urls'] ] ) );
		}

		if ( ! empty( $row[ $mapping['hashtags'] ] ) ) {
			$post['hashtags'] = array_map( 'trim', explode( ',', $row[ $mapping['hashtags'] ] ) );
		}

		if ( ! empty( $row[ $mapping['link'] ] ) ) {
			$post['link'] = esc_url_raw( $row[ $mapping['link'] ] );
		}

		return $post;
	}

	/**
	 * Validate parsed posts.
	 *
	 * @param array  $posts    Array of posts.
	 * @param string $timezone Timezone.
	 * @return array Valid and invalid posts.
	 */
	protected function validate_posts( $posts, $timezone ) {
		$valid   = array();
		$invalid = array();

		foreach ( $posts as $post ) {
			$errors = array();

			// Validate content.
			if ( empty( $post['content'] ) ) {
				$errors[] = __( 'Content is required', 'mcp-ai-wpoos-pro' );
			}

			// Validate platforms.
			if ( empty( $post['platforms'] ) ) {
				$errors[] = __( 'Platforms are required', 'mcp-ai-wpoos-pro' );
			}

			// Validate scheduled time.
			if ( ! empty( $post['scheduled_time'] ) ) {
				try {
					$datetime = new DateTime( $post['scheduled_time'], new DateTimeZone( $timezone ) );
					if ( $datetime->getTimestamp() <= current_time( 'timestamp' ) ) {
						$errors[] = __( 'Scheduled time must be in the future', 'mcp-ai-wpoos-pro' );
					}
				} catch ( Exception $e ) {
					$errors[] = sprintf(
						/* translators: %s: Error message */
						__( 'Invalid scheduled time: %s', 'mcp-ai-wpoos-pro' ),
						$e->getMessage()
					);
				}
			}

			if ( empty( $errors ) ) {
				$valid[] = $post;
			} else {
				$invalid[] = array(
					'row'    => $post['row_number'],
					'errors' => $errors,
				);
			}
		}

		return array(
			'valid'   => $valid,
			'invalid' => $invalid,
		);
	}

	/**
	 * Schedule a single post.
	 *
	 * @param array $post_data Post data.
	 * @param int   $user_id   User ID.
	 * @return array|WP_Error Schedule result or error.
	 */
	protected function schedule_single_post( $post_data, $user_id ) {
		// Create post.
		$post_id = wp_insert_post(
			array(
				'post_title'   => sprintf(
					/* translators: %s: Scheduled time */
					__( 'Bulk Scheduled Post - %s', 'mcp-ai-wpoos-pro' ),
					$post_data['scheduled_time']
				),
				'post_content' => $post_data['content'],
				'post_status'  => 'future',
				'post_type'    => 'social_scheduled_post',
				'post_author'  => $user_id,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store metadata.
		update_post_meta( $post_id, '_social_platforms', $post_data['platforms'] );
		update_post_meta( $post_id, '_social_scheduled_time', $post_data['scheduled_time'] );
		update_post_meta( $post_id, '_social_media_urls', isset( $post_data['media_urls'] ) ? $post_data['media_urls'] : array() );
		update_post_meta( $post_id, '_social_hashtags', isset( $post_data['hashtags'] ) ? $post_data['hashtags'] : array() );
		update_post_meta( $post_id, '_social_link', isset( $post_data['link'] ) ? $post_data['link'] : '' );
		update_post_meta( $post_id, '_social_status', 'scheduled' );

		return array(
			'post_id'      => $post_id,
			'row_number'   => $post_data['row_number'],
			'scheduled_at' => $post_data['scheduled_time'],
			'platforms'    => $post_data['platforms'],
		);
	}
}
