<?php
/**
 * Download Google Maps Images Tool - Pro add-on tool for importing business photos.
 *
 * Downloads business listing photos from Google Maps Places API (New) and imports
 * them into the WordPress Media Library with proper attribution metadata.
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
 * Tool for downloading Google Maps business listing photos.
 *
 * Provides functionality to:
 * - Retrieve business photos via place_id or text search query
 * - Import photos to the WordPress Media Library with attribution
 * - Optionally bundle images into a ZIP archive for download
 * - Respect rate limits and attribution requirements
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Download_Google_Maps_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Places API base URL for the new Places API.
	 */
	const PLACES_API_BASE = 'https://places.googleapis.com/v1/places';

	/**
	 * Default timeout for API requests in seconds.
	 */
	const DEFAULT_API_TIMEOUT = 15;

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
		return 'download_google_maps_images';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Download Google Maps Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Downloads business listing photos from Google Maps using the Places API (New). Retrieves place photos by place_id or text search query, imports them to the WordPress Media Library with proper attribution metadata. Supports limiting the number of images and optional ZIP bundle export.', 'mcp-ai-wpoos-pro' );
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
				'api_key'      => array(
					'type'        => 'string',
					'description' => __( 'Google Maps Places API key.', 'mcp-ai-wpoos-pro' ),
				),
				'place_id'     => array(
					'type'        => 'string',
					'description' => __( 'Google Maps place_id for the business. If omitted, provide search_query to find the business.', 'mcp-ai-wpoos-pro' ),
				),
				'search_query' => array(
					'type'        => 'string',
					'description' => __( 'Text search query to find the business (e.g. "Acme Corp New York"). Used when place_id is not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'max_images'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of images to download (1-10, default 10).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
				),
				'max_width'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum width in pixels for downloaded images (default 1600).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1600,
				),
				'max_height'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum height in pixels for downloaded images (default 1200).', 'mcp-ai-wpoos-pro' ),
					'default'     => 1200,
				),
				'output_mode'  => array(
					'type'        => 'string',
					'enum'        => array( 'media_library', 'zip', 'both' ),
					'description' => __( 'Where to save images: media_library (WordPress Media Library), zip (ZIP archive in uploads), or both.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'media_library',
				),
			),
			'required'             => array( 'api_key' ),
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
			'requires-credentials',  // Requires Google Maps API key.
			'requires-capability',   // Requires upload_files capability.
			'write',                 // Creates media library attachments.
			'external-api',          // Makes Google Places API calls.
			'rate-limited',          // Subject to Google API rate limits.
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
				'requests_per_hour'   => 60,
				'concurrent_requests' => 2,
			),
			'timeout_constraints' => array(
				'max_execution_time'  => 120,
				'recommended_timeout' => 60,
			),
			'dependencies'        => array(
				'required_settings' => array( 'api_key' => 'Google Maps Places API key' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_download_google_maps_images_capability', 'upload_files', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to download images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 2. Validate parameters.
		$api_key = isset( $arguments['api_key'] ) ? sanitize_text_field( $arguments['api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'A Google Maps Places API key is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$place_id     = isset( $arguments['place_id'] ) ? sanitize_text_field( $arguments['place_id'] ) : '';
		$search_query = isset( $arguments['search_query'] ) ? sanitize_text_field( $arguments['search_query'] ) : '';

		if ( '' === $place_id && '' === $search_query ) {
			return new WP_Error(
				'wp_mcp_ai_missing_params',
				__( 'Either place_id or search_query must be provided.', 'mcp-ai-wpoos-pro' )
			);
		}

		$max_images  = isset( $arguments['max_images'] ) ? absint( $arguments['max_images'] ) : 10;
		$max_images  = max( 1, min( 10, $max_images ) );
		$max_width   = isset( $arguments['max_width'] ) ? absint( $arguments['max_width'] ) : 1600;
		$max_height  = isset( $arguments['max_height'] ) ? absint( $arguments['max_height'] ) : 1200;
		$output_mode = isset( $arguments['output_mode'] ) ? sanitize_text_field( $arguments['output_mode'] ) : 'media_library';

		if ( ! in_array( $output_mode, array( 'media_library', 'zip', 'both' ), true ) ) {
			$output_mode = 'media_library';
		}

		// 3. Resolve place_id via text search if not provided.
		if ( '' === $place_id ) {
			$resolved = $this->resolve_place_id( $api_key, $search_query );

			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}

			$place_id = $resolved;
		}

		// 4. Get place details with photos.
		$place_data = $this->get_place_photos( $api_key, $place_id );

		if ( is_wp_error( $place_data ) ) {
			return $place_data;
		}

		$photos     = $place_data['photos'];
		$place_name = $place_data['place_name'];

		if ( empty( $photos ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_photos',
				__( 'No photos found for this place.', 'mcp-ai-wpoos-pro' )
			);
		}

		$photos = array_slice( $photos, 0, $max_images );

		// 5. Download each photo.
		$downloaded    = array();
		$download_errs = 0;

		foreach ( $photos as $index => $photo ) {
			$image_data = $this->download_photo( $api_key, $photo, $max_width, $max_height );

			if ( is_wp_error( $image_data ) ) {
				++$download_errs;
				continue;
			}

			$attribution = '';
			if ( ! empty( $photo['authorAttributions'] ) && is_array( $photo['authorAttributions'] ) ) {
				$first_author = $photo['authorAttributions'][0];
				$attribution  = isset( $first_author['displayName'] ) ? sanitize_text_field( $first_author['displayName'] ) : '';
			}

			$downloaded[] = array(
				'image_body'   => $image_data['body'],
				'content_type' => $image_data['content_type'],
				'attribution'  => $attribution,
				'index'        => $index,
			);
		}

		if ( empty( $downloaded ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download any photos from Google Maps.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 6. Process output based on mode.
		$attachments = array();
		$zip_url     = '';
		$zip_path    = '';

		$save_to_media = in_array( $output_mode, array( 'media_library', 'both' ), true );
		$save_to_zip   = in_array( $output_mode, array( 'zip', 'both' ), true );

		if ( $save_to_media ) {
			$attachments = $this->import_to_media_library( $downloaded, $place_id, $place_name, $user_id );
		}

		if ( $save_to_zip ) {
			$zip_result = $this->create_zip_archive( $downloaded, $place_id, $place_name );

			if ( ! is_wp_error( $zip_result ) ) {
				$zip_url  = $zip_result['url'];
				$zip_path = $zip_result['path'];
			}
		}

		// 8. Return result.
		$result = array(
			'success'           => true,
			'place_id'          => $place_id,
			'place_name'        => $place_name,
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
	 * Resolve a place_id from a text search query.
	 *
	 * @param string $api_key      Google Maps API key.
	 * @param string $search_query Text search query for the business.
	 * @return string|WP_Error Resolved place_id or error.
	 */
	protected function resolve_place_id( $api_key, $search_query ) {
		$response = wp_remote_post(
			self::PLACES_API_BASE . ':searchText',
			array(
				'timeout' => self::DEFAULT_API_TIMEOUT,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-Goog-Api-Key'   => $api_key,
					'X-Goog-FieldMask' => 'places.id,places.displayName',
				),
				'body'    => wp_json_encode(
					array(
						'textQuery' => $search_query,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to search for the place.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = __( 'Google Places text search API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = sanitize_text_field( $decoded['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array( 'code' => $code )
			);
		}

		if ( empty( $decoded['places'] ) || ! is_array( $decoded['places'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'No places found for the given search query.', 'mcp-ai-wpoos-pro' )
			);
		}

		$first_place = $decoded['places'][0];

		if ( empty( $first_place['id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'The search result did not include a place ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		return sanitize_text_field( $first_place['id'] );
	}

	/**
	 * Get place details including photos from the Places API.
	 *
	 * @param string $api_key  Google Maps API key.
	 * @param string $place_id Google Maps place_id.
	 * @return array|WP_Error Array with 'photos' and 'place_name' keys, or error.
	 */
	protected function get_place_photos( $api_key, $place_id ) {
		$url = self::PLACES_API_BASE . '/' . urlencode( $place_id );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::DEFAULT_API_TIMEOUT,
				'headers' => array(
					'X-Goog-Api-Key'   => $api_key,
					'X-Goog-FieldMask' => 'photos,displayName',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Failed to retrieve place details.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( 200 !== $code ) {
			$message = __( 'Google Places details API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( ! empty( $decoded['error']['message'] ) ) {
				$message = sanitize_text_field( $decoded['error']['message'] );
			}

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array( 'code' => $code )
			);
		}

		$place_name = '';
		if ( ! empty( $decoded['displayName']['text'] ) ) {
			$place_name = sanitize_text_field( $decoded['displayName']['text'] );
		}

		$photos = array();
		if ( ! empty( $decoded['photos'] ) && is_array( $decoded['photos'] ) ) {
			$photos = $decoded['photos'];
		}

		return array(
			'photos'     => $photos,
			'place_name' => $place_name,
		);
	}

	/**
	 * Download a single photo from the Places API.
	 *
	 * @param string $api_key    Google Maps API key.
	 * @param array  $photo      Photo metadata from the Places API response.
	 * @param int    $max_width  Maximum width in pixels.
	 * @param int    $max_height Maximum height in pixels.
	 * @return array|WP_Error Array with 'body' and 'content_type' keys, or error.
	 */
	protected function download_photo( $api_key, $photo, $max_width, $max_height ) {
		if ( empty( $photo['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Photo metadata is missing the resource name.', 'mcp-ai-wpoos-pro' )
			);
		}

		$photo_name = sanitize_text_field( $photo['name'] );

		// Get the photo URI via the media endpoint (skipHttpRedirect returns JSON with photoUri).
		$media_url = add_query_arg(
			array(
				'key'              => $api_key,
				'maxHeightPx'      => $max_height,
				'maxWidthPx'       => $max_width,
				'skipHttpRedirect' => 'true',
			),
			'https://places.googleapis.com/v1/' . $photo_name . '/media'
		);

		$media_response = wp_remote_get(
			esc_url_raw( $media_url ),
			array(
				'timeout' => self::DEFAULT_API_TIMEOUT,
			)
		);

		if ( is_wp_error( $media_response ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to retrieve photo media URI.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $media_response->get_error_message() )
			);
		}

		$media_code    = wp_remote_retrieve_response_code( $media_response );
		$media_body    = wp_remote_retrieve_body( $media_response );
		$media_decoded = json_decode( $media_body, true );

		if ( 200 !== $media_code || empty( $media_decoded['photoUri'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to obtain the photo download URI.', 'mcp-ai-wpoos-pro' )
			);
		}

		$photo_uri = esc_url_raw( $media_decoded['photoUri'] );

		// Download the actual image content.
		$image_response = wp_remote_get(
			$photo_uri,
			array(
				'timeout' => self::DEFAULT_DOWNLOAD_TIMEOUT,
			)
		);

		if ( is_wp_error( $image_response ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download the photo image.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $image_response->get_error_message() )
			);
		}

		$image_code = wp_remote_retrieve_response_code( $image_response );

		if ( 200 !== $image_code ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Photo download returned a non-200 response.', 'mcp-ai-wpoos-pro' ),
				array( 'code' => $image_code )
			);
		}

		$content_type = wp_remote_retrieve_header( $image_response, 'content-type' );
		$image_body   = wp_remote_retrieve_body( $image_response );

		if ( empty( $image_body ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Downloaded photo image is empty.', 'mcp-ai-wpoos-pro' )
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
	 * @param string $place_id   Google Maps place_id.
	 * @param string $place_name Business display name.
	 * @param int    $user_id    WordPress user ID for attachment ownership.
	 * @return array Array of attachment data arrays.
	 */
	protected function import_to_media_library( $downloaded, $place_id, $place_name, $user_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachments = array();

		foreach ( $downloaded as $item ) {
			$extension = $this->get_extension_from_content_type( $item['content_type'] );
			$filename  = sanitize_file_name(
				sanitize_title( $place_name ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
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

			$post_data = array(
				'post_title'  => $place_name . ' - ' . __( 'Photo', 'mcp-ai-wpoos-pro' ) . ' ' . ( $item['index'] + 1 ),
				'post_author' => $user_id,
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
			update_post_meta( $attachment_id, '_wp_mcp_ai_source', 'google_maps' );
			update_post_meta( $attachment_id, '_wp_mcp_ai_place_id', sanitize_text_field( $place_id ) );

			if ( '' !== $item['attribution'] ) {
				update_post_meta( $attachment_id, '_wp_mcp_ai_attribution', sanitize_text_field( $item['attribution'] ) );
			}

			// Set alt text from business display name.
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $place_name ) );

			$attachments[] = array(
				'id'          => $attachment_id,
				'url'         => esc_url_raw( wp_get_attachment_url( $attachment_id ) ),
				'title'       => get_the_title( $attachment_id ),
				'attribution' => $item['attribution'],
			);
		}

		return $attachments;
	}

	/**
	 * Create a ZIP archive of downloaded images.
	 *
	 * @param array  $downloaded Array of downloaded image data.
	 * @param string $place_id   Google Maps place_id.
	 * @param string $place_name Business display name.
	 * @return array|WP_Error Array with 'url' and 'path' keys, or error.
	 */
	protected function create_zip_archive( $downloaded, $place_id, $place_name ) {
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
			sanitize_title( $place_name ) . '-' . substr( md5( $place_id . time() ), 0, 8 ) . '.zip'
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
				sanitize_title( $place_name ) . '-' . ( $item['index'] + 1 ) . '.' . $extension
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
