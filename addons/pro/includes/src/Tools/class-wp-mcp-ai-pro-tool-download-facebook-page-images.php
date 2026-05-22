<?php
/**
 * Download Facebook Page Images Tool - Pro add-on tool for importing page photos.
 *
 * Downloads business page photos from Facebook Graph API and imports
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
 * Tool for downloading Facebook Business Page photos.
 *
 * Provides functionality to:
 * - Retrieve page photos via the Facebook Graph API
 * - Select the highest available resolution for each photo
 * - Import photos to the WordPress Media Library with metadata
 * - Support cursor-based pagination for large albums
 * - Optionally bundle images into a ZIP archive for download
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Download_Facebook_Page_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Facebook Graph API version.
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
		return 'download_facebook_page_images';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Download Facebook Page Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Downloads photos from a Facebook Business Page using the Graph API. Retrieves page photos with highest available resolution, imports them to the WordPress Media Library with metadata. Supports cursor-based pagination and optional ZIP bundle export.', 'mcp-ai-wpoos-pro' );
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
				'access_token' => array(
					'type'        => 'string',
					'description' => __( 'Facebook Page access token with pages_read_engagement permission.', 'mcp-ai-wpoos-pro' ),
				),
				'page_id'      => array(
					'type'        => 'string',
					'description' => __( 'Facebook Page ID or username.', 'mcp-ai-wpoos-pro' ),
				),
				'album'        => array(
					'type'        => 'string',
					'enum'        => array( 'uploaded', 'profile', 'cover', 'timeline', 'all' ),
					'description' => __( 'Which photo album to download from (default: uploaded).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'uploaded',
				),
				'max_images'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of images to download (1-50, default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
				'output_mode'  => array(
					'type'        => 'string',
					'enum'        => array( 'media_library', 'zip', 'both' ),
					'description' => __( 'Where to save images: media_library, zip, or both.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'media_library',
				),
			),
			'required'             => array( 'access_token', 'page_id' ),
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
			'requires-credentials',  // Requires Facebook Page access token.
			'requires-capability',   // Requires upload_files capability.
			'write',                 // Creates media library attachments.
			'external-api',          // Makes Facebook Graph API calls.
			'rate-limited',          // Subject to Facebook API rate limits.
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
				'required_settings' => array( 'access_token' => 'Facebook Page access token' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_download_facebook_page_images_capability', 'upload_files', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to download images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 2. Validate parameters.
		$access_token = isset( $arguments['access_token'] ) ? sanitize_text_field( $arguments['access_token'] ) : '';
		$page_id      = isset( $arguments['page_id'] ) ? sanitize_text_field( $arguments['page_id'] ) : '';

		if ( '' === $access_token ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'A Facebook Page access token is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( '' === $page_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'A Facebook Page ID or username is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$album       = isset( $arguments['album'] ) ? sanitize_text_field( $arguments['album'] ) : 'uploaded';
		$max_images  = isset( $arguments['max_images'] ) ? absint( $arguments['max_images'] ) : 25;
		$max_images  = max( 1, min( 50, $max_images ) );
		$output_mode = isset( $arguments['output_mode'] ) ? sanitize_text_field( $arguments['output_mode'] ) : 'media_library';

		if ( ! in_array( $album, array( 'uploaded', 'profile', 'cover', 'timeline', 'all' ), true ) ) {
			$album = 'uploaded';
		}

		if ( ! in_array( $output_mode, array( 'media_library', 'zip', 'both' ), true ) ) {
			$output_mode = 'media_library';
		}

		// 3. Fetch photos from Graph API.
		if ( 'cover' === $album ) {
			$photos = $this->fetch_cover_photo( $access_token, $page_id );
		} else {
			$photos = $this->fetch_album_photos( $access_token, $page_id, $album, $max_images );
		}

		if ( is_wp_error( $photos ) ) {
			return $photos;
		}

		if ( empty( $photos ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_photos',
				__( 'No photos found for this Facebook Page.', 'mcp-ai-wpoos-pro' )
			);
		}

		$photos = array_slice( $photos, 0, $max_images );

		// 4. Select highest resolution and download each photo.
		$downloaded    = array();
		$download_errs = 0;

		foreach ( $photos as $index => $photo ) {
			$image_url = $this->select_highest_resolution( $photo );

			if ( '' === $image_url ) {
				++$download_errs;
				continue;
			}

			$image_data = $this->download_image( $image_url );

			if ( is_wp_error( $image_data ) ) {
				++$download_errs;
				continue;
			}

			$photo_name   = isset( $photo['name'] ) ? sanitize_text_field( $photo['name'] ) : '';
			$created_time = isset( $photo['created_time'] ) ? sanitize_text_field( $photo['created_time'] ) : '';

			$downloaded[] = array(
				'image_body'   => $image_data['body'],
				'content_type' => $image_data['content_type'],
				'name'         => $photo_name,
				'created_time' => $created_time,
				'index'        => $index,
			);
		}

		if ( empty( $downloaded ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download any photos from Facebook.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 5. Process output based on mode.
		$attachments = array();
		$zip_url     = '';
		$zip_path    = '';

		$save_to_media = in_array( $output_mode, array( 'media_library', 'both' ), true );
		$save_to_zip   = in_array( $output_mode, array( 'zip', 'both' ), true );

		if ( $save_to_media ) {
			$attachments = $this->import_to_media_library( $downloaded, $page_id, $user_id );
		}

		if ( $save_to_zip ) {
			$zip_result = $this->create_zip_archive( $downloaded, $page_id );

			if ( ! is_wp_error( $zip_result ) ) {
				$zip_url  = $zip_result['url'];
				$zip_path = $zip_result['path'];
			}
		}

		// 6. Return result.
		$result = array(
			'success'           => true,
			'page_id'           => $page_id,
			'album'             => $album,
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
	 * Fetch photos from a page album using the Graph API with cursor-based pagination.
	 *
	 * @param string $access_token Facebook Page access token.
	 * @param string $page_id      Facebook Page ID or username.
	 * @param string $album        Album type (uploaded, profile, timeline, all).
	 * @param int    $max_images   Maximum number of images to retrieve.
	 * @return array|WP_Error Array of photo data or error.
	 */
	protected function fetch_album_photos( $access_token, $page_id, $album, $max_images ) {
		$photos   = array();
		$after    = '';
		$per_page = min( $max_images, 25 );

		do {
			$url = sprintf(
				'%s/%s/%s/photos',
				self::GRAPH_API_BASE,
				self::GRAPH_API_VERSION,
				rawurlencode( $page_id )
			);

			$query_args = array(
				'fields'       => 'images,name,created_time',
				'limit'        => $per_page,
				'access_token' => $access_token,
			);

			// Add type filter unless 'all' is requested (omitting type returns all photos).
			if ( 'all' !== $album ) {
				$query_args['type'] = $album;
			}

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
					__( 'Failed to connect to the Facebook Graph API.', 'mcp-ai-wpoos-pro' ),
					array( 'error' => $response->get_error_message() )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( 200 !== $code ) {
				$message = __( 'Facebook Graph API returned an error.', 'mcp-ai-wpoos-pro' );

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
				foreach ( $decoded['data'] as $photo ) {
					$photos[] = $photo;

					if ( count( $photos ) >= $max_images ) {
						break;
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

			$photos_count = count( $photos );
		} while ( $photos_count < $max_images && $has_next && '' !== $after );

		return $photos;
	}

	/**
	 * Fetch the cover photo for a Facebook Page.
	 *
	 * @param string $access_token Facebook Page access token.
	 * @param string $page_id      Facebook Page ID or username.
	 * @return array|WP_Error Array containing cover photo data or error.
	 */
	protected function fetch_cover_photo( $access_token, $page_id ) {
		$url = sprintf(
			'%s/%s/%s',
			self::GRAPH_API_BASE,
			self::GRAPH_API_VERSION,
			rawurlencode( $page_id )
		);

		$url = add_query_arg(
			array(
				'fields'       => 'cover',
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
				__( 'Failed to connect to the Facebook Graph API.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = __( 'Facebook Graph API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = sanitize_text_field( $decoded['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array( 'code' => $code )
			);
		}

		if ( empty( $decoded['cover']['source'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_photos',
				__( 'No cover photo found for this Facebook Page.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Return cover photo in a format consistent with album photos.
		return array(
			array(
				'images'       => array(
					array(
						'source' => $decoded['cover']['source'],
					),
				),
				'name'         => __( 'Cover Photo', 'mcp-ai-wpoos-pro' ),
				'created_time' => '',
			),
		);
	}

	/**
	 * Select the highest resolution image URL from a photo's images array.
	 *
	 * Each photo from the Graph API includes an 'images' array with multiple
	 * resolutions. This method sorts by pixel area (width * height) descending
	 * and returns the URL of the largest available version.
	 *
	 * @param array $photo Photo data from the Graph API response.
	 * @return string Highest resolution image URL, or empty string on failure.
	 */
	protected function select_highest_resolution( $photo ) {
		if ( empty( $photo['images'] ) || ! is_array( $photo['images'] ) ) {
			return '';
		}

		$images = $photo['images'];

		usort(
			$images,
			function ( $a, $b ) {
				$area_a = ( isset( $a['width'] ) ? absint( $a['width'] ) : 0 ) * ( isset( $a['height'] ) ? absint( $a['height'] ) : 0 );
				$area_b = ( isset( $b['width'] ) ? absint( $b['width'] ) : 0 ) * ( isset( $b['height'] ) ? absint( $b['height'] ) : 0 );
				return $area_b - $area_a;
			}
		);

		$best = $images[0];

		if ( ! empty( $best['source'] ) ) {
			return esc_url_raw( $best['source'] );
		}

		return '';
	}

	/**
	 * Download a single image from a URL.
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
				__( 'Failed to download the photo image.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Photo download returned a non-200 response.', 'mcp-ai-wpoos-pro' ),
				array( 'code' => $code )
			);
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$image_body   = wp_remote_retrieve_body( $response );

		if ( empty( $image_body ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Downloaded photo image is empty.', 'mcp-ai-wpoos-pro' )
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
	 * @param array  $downloaded Array of downloaded image data.
	 * @param string $page_id    Facebook Page ID.
	 * @param int    $user_id    WordPress user ID for attachment ownership.
	 * @return array Array of attachment data arrays.
	 */
	protected function import_to_media_library( $downloaded, $page_id, $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachments = array();

		foreach ( $downloaded as $item ) {
			$extension = $this->get_extension_from_content_type( $item['content_type'] );
			$filename  = sanitize_file_name(
				'facebook-' . sanitize_title( $page_id ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
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

			$post_title = 'Facebook - ' . __( 'Photo', 'mcp-ai-wpoos-pro' ) . ' ' . ( $item['index'] + 1 );
			if ( '' !== $item['name'] ) {
				$post_title = $item['name'];
			}

			$post_data = array(
				'post_title'   => $post_title,
				'post_author'  => $user_id,
				'post_excerpt' => $item['name'],
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
			update_post_meta( $attachment_id, '_wp_mcp_ai_source', 'facebook_page' );
			update_post_meta( $attachment_id, '_wp_mcp_ai_page_id', sanitize_text_field( $page_id ) );

			if ( '' !== $item['name'] ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $item['name'] ) );
			}

			if ( '' !== $item['created_time'] ) {
				update_post_meta( $attachment_id, '_wp_mcp_ai_created_time', sanitize_text_field( $item['created_time'] ) );
			}

			$attachments[] = array(
				'id'           => $attachment_id,
				'url'          => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
				'title'        => get_the_title( $attachment_id ),
				'created_time' => $item['created_time'],
			);
		}

		return $attachments;
	}

	/**
	 * Create a ZIP archive of downloaded images.
	 *
	 * @param array  $downloaded Array of downloaded image data.
	 * @param string $page_id    Facebook Page ID.
	 * @return array|WP_Error Array with 'url' and 'path' keys, or error.
	 */
	protected function create_zip_archive( $downloaded, $page_id ) {
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
			'facebook-' . sanitize_title( $page_id ) . '-' . substr( md5( $page_id . time() ), 0, 8 ) . '.zip'
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
				'facebook-' . sanitize_title( $page_id ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
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
