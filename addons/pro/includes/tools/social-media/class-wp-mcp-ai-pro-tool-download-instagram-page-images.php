<?php
/**
 * Download Instagram Page Images Tool - Pro add-on tool for importing business media.
 *
 * Downloads business profile media from Instagram Graph API and imports
 * them into the WordPress Media Library with metadata.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for downloading Instagram Business or Creator account media.
 *
 * Provides functionality to:
 * - Retrieve media via the Instagram Graph API
 * - Filter by media type (image, carousel_album, video, all)
 * - Expand carousel albums to download individual child images
 * - Import images to the WordPress Media Library with captions and timestamps
 * - Support cursor-based pagination for large media collections
 * - Optionally bundle images into a ZIP archive for download
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Download_Instagram_Page_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Instagram Graph API version.
	 */
	const GRAPH_API_VERSION = 'v21.0';

	/**
	 * Graph API base URL.
	 */
	const GRAPH_API_BASE = 'https://graph.facebook.com';

	/**
	 * Default timeout for API requests in seconds.
	 */
	const DEFAULT_API_TIMEOUT = 20;

	/**
	 * Default timeout for image download requests in seconds.
	 */
	const DEFAULT_DOWNLOAD_TIMEOUT = 30;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no server-side dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty string - tool is always available.
	 */
	public static function get_unavailable_reason() {
		return '';
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'download_instagram_page_images';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Download Instagram Page Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Downloads media images from an Instagram Business or Creator account using the Instagram Graph API. Retrieves posts, carousel items, and story images with metadata. Imports to the WordPress Media Library with captions and timestamps. Note: Instagram media URLs are temporary and must be downloaded promptly. Supports optional ZIP bundle export.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'access_token'      => array(
					'type'        => 'string',
					'description' => __( 'Instagram Graph API access token with instagram_basic permission.', 'mcp-ai-wpoos-pro' ),
				),
				'ig_user_id'        => array(
					'type'        => 'string',
					'description' => __( 'Instagram Business or Creator account user ID.', 'mcp-ai-wpoos-pro' ),
				),
				'media_type_filter' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'image', 'carousel_album', 'video' ),
					'description' => __( 'Filter by media type. Use "image" for photos only, "carousel_album" to include carousel posts, "video" to include video thumbnails, or "all" for everything (default: image).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'image',
				),
				'max_images'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of images to download (1-50, default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
				'output_mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'media_library', 'zip', 'both' ),
					'description' => __( 'Where to save images: media_library, zip, or both.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'media_library',
				),
			),
			'required'             => array( 'access_token', 'ig_user_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags() {
		return array(
			'pro',                   // Pro tier tool.
			'requires-credentials',  // Requires Instagram Graph API access token.
			'requires-capability',   // Requires upload_files capability.
			'write',                 // Creates media library attachments.
			'external-api',          // Makes Instagram Graph API calls.
			'rate-limited',          // Subject to Instagram API rate limits.
			'async',                 // May take significant time to complete.
		);
	}

	/**
	 * Get tool-specific execution rules.
	 *
	 * @return array Associative array of tool-specific rules.
	 */
	public function get_tool_rules() {
		return array(
			'rate_limits'         => array(
				'requests_per_minute' => 10,
				'requests_per_hour'   => 100,
				'concurrent_requests' => 2,
			),
			'timeout_constraints' => array(
				'max_execution_time'  => 180,
				'recommended_timeout' => 90,
			),
			'dependencies'        => array(
				'required_settings' => array( 'access_token' => 'Instagram Graph API access token' ),
			),
			'orchestration_hints' => array(
				'can_run_parallel' => false,
				'retry_strategy'   => 'exponential_backoff',
				'max_retries'      => 3,
			),
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
		// 1. Authentication check.
		$user_id             = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$required_capability = apply_filters( 'wp_mcp_ai_download_instagram_page_images_capability', 'upload_files', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to download images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 2. Validate parameters.
		$access_token = isset( $arguments['access_token'] ) ? sanitize_text_field( $arguments['access_token'] ) : '';
		$ig_user_id   = isset( $arguments['ig_user_id'] ) ? sanitize_text_field( $arguments['ig_user_id'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'An Instagram Graph API access token is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $ig_user_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'An Instagram Business or Creator account user ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$media_type_filter = isset( $arguments['media_type_filter'] ) ? sanitize_text_field( $arguments['media_type_filter'] ) : 'image';
		$max_images        = isset( $arguments['max_images'] ) ? absint( $arguments['max_images'] ) : 25;
		$max_images        = max( 1, min( 50, $max_images ) );
		$output_mode       = isset( $arguments['output_mode'] ) ? sanitize_text_field( $arguments['output_mode'] ) : 'media_library';

		if ( ! in_array( $media_type_filter, array( 'all', 'image', 'carousel_album', 'video' ), true ) ) {
			$media_type_filter = 'image';
		}

		if ( ! in_array( $output_mode, array( 'media_library', 'zip', 'both' ), true ) ) {
			$output_mode = 'media_library';
		}

		// 3. Fetch media from Instagram Graph API.
		$media_items = $this->fetch_media( $access_token, $ig_user_id, $media_type_filter, $max_images );

		if ( is_wp_error( $media_items ) ) {
			return $media_items;
		}

		if ( empty( $media_items ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_media',
				__( 'No media found for this Instagram account.', 'mcp-ai-wpoos-pro' )
			);
		}

		$media_items = array_slice( $media_items, 0, $max_images );

		// 4. Download each image.
		$downloaded    = array();
		$download_errs = 0;

		foreach ( $media_items as $index => $item ) {
			$image_url = isset( $item['image_url'] ) ? $item['image_url'] : '';

			if ( '' === $image_url ) {
				++$download_errs;
				continue;
			}

			$image_data = $this->download_image( $image_url );

			if ( is_wp_error( $image_data ) ) {
				++$download_errs;
				continue;
			}

			$downloaded[] = array(
				'image_body'   => $image_data['body'],
				'content_type' => $image_data['content_type'],
				'caption'      => isset( $item['caption'] ) ? $item['caption'] : '',
				'timestamp'    => isset( $item['timestamp'] ) ? $item['timestamp'] : '',
				'permalink'    => isset( $item['permalink'] ) ? $item['permalink'] : '',
				'media_id'     => isset( $item['media_id'] ) ? $item['media_id'] : '',
				'media_type'   => isset( $item['media_type'] ) ? $item['media_type'] : '',
				'index'        => $index,
			);
		}

		if ( empty( $downloaded ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download any images from Instagram.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 5. Process output based on mode.
		$attachments = array();
		$zip_url     = '';
		$zip_path    = '';

		$save_to_media = in_array( $output_mode, array( 'media_library', 'both' ), true );
		$save_to_zip   = in_array( $output_mode, array( 'zip', 'both' ), true );

		if ( $save_to_media ) {
			$attachments = $this->import_to_media_library( $downloaded, $ig_user_id, $user_id );
		}

		if ( $save_to_zip ) {
			$zip_result = $this->create_zip_archive( $downloaded, $ig_user_id );

			if ( ! is_wp_error( $zip_result ) ) {
				$zip_url  = $zip_result['url'];
				$zip_path = $zip_result['path'];
			}
		}

		// 6. Return result.
		$result = array(
			'success'           => true,
			'ig_user_id'        => $ig_user_id,
			'media_type_filter' => $media_type_filter,
			'images_downloaded' => count( $downloaded ),
			'attachments'       => $attachments,
		);

		if ( '' !== $zip_url ) {
			$result['zip_url']  = $zip_url;
			$result['zip_path'] = $zip_path;
		}

		return $result;
	}

	/**
	 * Fetch media from the Instagram Graph API with cursor-based pagination.
	 *
	 * Retrieves media items from the user's feed, filtering by type and
	 * expanding carousel albums into individual images when applicable.
	 *
	 * @param string $access_token      Instagram Graph API access token.
	 * @param string $ig_user_id        Instagram Business or Creator account user ID.
	 * @param string $media_type_filter Media type filter (all, image, carousel_album, video).
	 * @param int    $max_images        Maximum number of images to collect.
	 * @return array|WP_Error Array of media item data or error.
	 */
	protected function fetch_media( $access_token, $ig_user_id, $media_type_filter, $max_images ) {
		$collected = array();
		$after     = '';

		do {
			$url = sprintf(
				'%s/%s/%s/media',
				self::GRAPH_API_BASE,
				self::GRAPH_API_VERSION,
				rawurlencode( $ig_user_id )
			);

			$query_args = array(
				'fields'       => 'id,caption,media_type,media_url,thumbnail_url,timestamp,permalink',
				'limit'        => 50,
				'access_token' => $access_token,
			);

			if ( '' !== $after ) {
				$query_args['after'] = $after;
			}

			$url = add_query_arg( $query_args, $url );

			$response = wp_remote_get(
				esc_url_raw( $url ),
				array(
					'timeout' => self::DEFAULT_API_TIMEOUT,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_api_error',
					__( 'Failed to connect to the Instagram Graph API.', 'mcp-ai-wpoos-pro' ),
					array( 'error' => $response->get_error_message() )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( 200 !== $code ) {
				$message = __( 'Instagram Graph API returned an error.', 'mcp-ai-wpoos-pro' );

				if ( ! empty( $decoded['error']['message'] ) ) {
					$message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$message,
					array( 'code' => $code )
				);
			}

			if ( ! empty( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
				foreach ( $decoded['data'] as $media ) {
					$items = $this->process_media_item( $media, $access_token, $media_type_filter );

					if ( is_wp_error( $items ) ) {
						continue;
					}

					foreach ( $items as $item ) {
						$collected[] = $item;

						if ( count( $collected ) >= $max_images ) {
							break 2;
						}
					}
				}
			}

			// Advance cursor for next page.
			$after = '';
			if ( ! empty( $decoded['paging']['cursors']['after'] ) ) {
				$after = sanitize_text_field( $decoded['paging']['cursors']['after'] );
			}

			// Stop if we have enough images or there are no more pages.
			$has_next = ! empty( $decoded['paging']['next'] );

			$collected_count = count( $collected );
		} while ( $collected_count < $max_images && $has_next && '' !== $after );

		return $collected;
	}

	/**
	 * Process a single media item based on the media type filter.
	 *
	 * Handles filtering by media type and expanding carousel albums into
	 * individual child images.
	 *
	 * @param array  $media             Single media item from the API response.
	 * @param string $access_token      Instagram Graph API access token.
	 * @param string $media_type_filter Active media type filter.
	 * @return array|WP_Error Array of processed media items or error.
	 */
	protected function process_media_item( $media, $access_token, $media_type_filter ) {
		$media_type = isset( $media['media_type'] ) ? $media['media_type'] : '';
		$caption    = isset( $media['caption'] ) ? sanitize_text_field( $media['caption'] ) : '';
		$timestamp  = isset( $media['timestamp'] ) ? sanitize_text_field( $media['timestamp'] ) : '';
		$permalink  = isset( $media['permalink'] ) ? esc_url_raw( $media['permalink'] ) : '';
		$media_id   = isset( $media['id'] ) ? sanitize_text_field( $media['id'] ) : '';
		$items      = array();

		switch ( $media_type ) {
			case 'IMAGE':
				if ( in_array( $media_type_filter, array( 'all', 'image' ), true ) ) {
					$media_url = isset( $media['media_url'] ) ? esc_url_raw( $media['media_url'] ) : '';

					if ( '' !== $media_url ) {
						$items[] = array(
							'image_url'  => $media_url,
							'caption'    => $caption,
							'timestamp'  => $timestamp,
							'permalink'  => $permalink,
							'media_id'   => $media_id,
							'media_type' => 'IMAGE',
						);
					}
				}
				break;

			case 'CAROUSEL_ALBUM':
				if ( in_array( $media_type_filter, array( 'all', 'carousel_album' ), true ) ) {
					$children = $this->fetch_carousel_children( $media_id, $access_token );

					if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
						foreach ( $children as $child ) {
							$child_type = isset( $child['media_type'] ) ? $child['media_type'] : '';

							if ( 'IMAGE' !== $child_type ) {
								continue;
							}

							$child_url = isset( $child['media_url'] ) ? esc_url_raw( $child['media_url'] ) : '';
							$child_id  = isset( $child['id'] ) ? sanitize_text_field( $child['id'] ) : '';

							if ( '' !== $child_url ) {
								$child_timestamp = isset( $child['timestamp'] ) ? sanitize_text_field( $child['timestamp'] ) : $timestamp;

								$items[] = array(
									'image_url'  => $child_url,
									'caption'    => $caption,
									'timestamp'  => $child_timestamp,
									'permalink'  => $permalink,
									'media_id'   => $child_id,
									'media_type' => 'IMAGE',
								);
							}
						}
					}
				}
				break;

			case 'VIDEO':
				if ( in_array( $media_type_filter, array( 'all', 'video' ), true ) ) {
					$thumbnail_url = isset( $media['thumbnail_url'] ) ? esc_url_raw( $media['thumbnail_url'] ) : '';

					if ( '' !== $thumbnail_url ) {
						$items[] = array(
							'image_url'  => $thumbnail_url,
							'caption'    => $caption,
							'timestamp'  => $timestamp,
							'permalink'  => $permalink,
							'media_id'   => $media_id,
							'media_type' => 'VIDEO',
						);
					}
				}
				break;
		}

		return $items;
	}

	/**
	 * Fetch children of a carousel album media item.
	 *
	 * @param string $media_id     The carousel album media ID.
	 * @param string $access_token Instagram Graph API access token.
	 * @return array|WP_Error Array of child media data or error.
	 */
	protected function fetch_carousel_children( $media_id, $access_token ) {
		$url = sprintf(
			'%s/%s/%s/children',
			self::GRAPH_API_BASE,
			self::GRAPH_API_VERSION,
			rawurlencode( $media_id )
		);

		$url = add_query_arg(
			array(
				'fields'       => 'id,media_type,media_url,timestamp',
				'access_token' => $access_token,
			),
			$url
		);

		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'timeout' => self::DEFAULT_API_TIMEOUT,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to fetch carousel children from the Instagram Graph API.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = __( 'Instagram Graph API returned an error while fetching carousel children.', 'mcp-ai-wpoos-pro' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = sanitize_text_field( $decoded['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array( 'code' => $code )
			);
		}

		if ( ! empty( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			return $decoded['data'];
		}

		return array();
	}

	/**
	 * Download a single image from a URL.
	 *
	 * Instagram media URLs are temporary and expire quickly, so images
	 * must be downloaded immediately after retrieval from the API.
	 *
	 * @param string $image_url URL of the image to download.
	 * @return array|WP_Error Array with 'body' and 'content_type' keys, or error.
	 */
	protected function download_image( $image_url ) {
		$response = wp_remote_get(
			esc_url_raw( $image_url ),
			array(
				'timeout' => self::DEFAULT_DOWNLOAD_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download the image.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Image download returned a non-200 response.', 'mcp-ai-wpoos-pro' ),
				array( 'code' => $code )
			);
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$image_body   = wp_remote_retrieve_body( $response );

		if ( empty( $image_body ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Downloaded image is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify the response is an image type.
		$mime_parts = explode( ';', sanitize_text_field( $content_type ) );
		$mime       = strtolower( trim( $mime_parts[0] ) );

		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'The downloaded content is not a valid image type.', 'mcp-ai-wpoos-pro' ),
				array( 'content_type' => $mime )
			);
		}

		return array(
			'body'         => $image_body,
			'content_type' => sanitize_text_field( $content_type ),
		);
	}

	/**
	 * Import downloaded images into the WordPress Media Library.
	 *
	 * @param array  $downloaded  Array of downloaded image data.
	 * @param string $ig_user_id  Instagram user ID.
	 * @param int    $user_id     WordPress user ID for attachment ownership.
	 * @return array Array of attachment data arrays.
	 */
	protected function import_to_media_library( $downloaded, $ig_user_id, $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachments = array();

		foreach ( $downloaded as $item ) {
			$extension = $this->get_extension_from_content_type( $item['content_type'] );
			$filename  = sanitize_file_name(
				'instagram-' . sanitize_title( $ig_user_id ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
			);

			// Write image to a temporary file for sideloading.
			$temp_file = wp_tempnam( $filename );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing downloaded image data to temp file for sideloading.
			$written = file_put_contents( $temp_file, $item['image_body'] );

			if ( false === $written ) {
				if ( file_exists( $temp_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing temp file after failed write.
					unlink( $temp_file );
				}
				continue;
			}

			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $temp_file,
			);

			$post_title = 'Instagram - ' . __( 'Photo', 'mcp-ai-wpoos-pro' ) . ' ' . ( $item['index'] + 1 );
			if ( '' !== $item['caption'] ) {
				$post_title = $item['caption'];
			}

			$post_data = array(
				'post_title'   => $post_title,
				'post_author'  => $user_id,
				'post_excerpt' => $item['caption'],
			);

			$attachment_id = media_handle_sideload( $file_array, 0, null, $post_data );

			// Clean up temp file if sideload failed.
			if ( is_wp_error( $attachment_id ) ) {
				if ( file_exists( $temp_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing temp file after failed sideload.
					unlink( $temp_file );
				}
				continue;
			}

			// Add metadata.
			update_post_meta( $attachment_id, '_wp_mcp_ai_source', 'instagram' );
			update_post_meta( $attachment_id, '_wp_mcp_ai_ig_user_id', sanitize_text_field( $ig_user_id ) );
			update_post_meta( $attachment_id, '_wp_mcp_ai_ig_media_id', sanitize_text_field( $item['media_id'] ) );

			if ( '' !== $item['permalink'] ) {
				update_post_meta( $attachment_id, '_wp_mcp_ai_ig_permalink', esc_url_raw( $item['permalink'] ) );
			}

			if ( '' !== $item['caption'] ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $item['caption'] ) );
			}

			if ( '' !== $item['timestamp'] ) {
				update_post_meta( $attachment_id, '_wp_mcp_ai_ig_timestamp', sanitize_text_field( $item['timestamp'] ) );
			}

			$attachments[] = array(
				'id'         => $attachment_id,
				'url'        => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
				'title'      => get_the_title( $attachment_id ),
				'caption'    => $item['caption'],
				'timestamp'  => $item['timestamp'],
				'permalink'  => $item['permalink'],
				'media_type' => $item['media_type'],
			);
		}

		return $attachments;
	}

	/**
	 * Create a ZIP archive of downloaded images.
	 *
	 * @param array  $downloaded  Array of downloaded image data.
	 * @param string $ig_user_id  Instagram user ID.
	 * @return array|WP_Error Array with 'url' and 'path' keys, or error.
	 */
	protected function create_zip_archive( $downloaded, $ig_user_id ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'ZipArchive PHP extension is not available on this server.', 'mcp-ai-wpoos-pro' )
			);
		}

		$upload_dir = wp_upload_dir();
		$zip_dir    = trailingslashit( $upload_dir['basedir'] ) . 'mcp-ai-downloads';

		wp_mkdir_p( $zip_dir );

		$zip_filename = sanitize_file_name(
			'instagram-' . sanitize_title( $ig_user_id ) . '-' . substr( md5( $ig_user_id . time() ), 0, 8 ) . '.zip'
		);
		$zip_filepath = trailingslashit( $zip_dir ) . $zip_filename;

		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to create ZIP archive.', 'mcp-ai-wpoos-pro' )
			);
		}

		foreach ( $downloaded as $item ) {
			$extension  = $this->get_extension_from_content_type( $item['content_type'] );
			$entry_name = sanitize_file_name(
				'instagram-' . sanitize_title( $ig_user_id ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
			);

			$zip->addFromString( $entry_name, $item['image_body'] );
		}

		$zip->close();

		$zip_url = trailingslashit( $upload_dir['baseurl'] ) . 'mcp-ai-downloads/' . $zip_filename;

		return array(
			'url'  => esc_url_raw( $zip_url ),
			'path' => $zip_filepath,
		);
	}

	/**
	 * Determine file extension from a content-type header value.
	 *
	 * @param string $content_type MIME content type.
	 * @return string File extension without a leading dot.
	 */
	protected function get_extension_from_content_type( $content_type ) {
		if ( empty( $content_type ) ) {
			return 'jpg';
		}

		$parts = explode( ';', $content_type );
		$type  = strtolower( trim( $parts[0] ) );

		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'jpg';
	}
}
