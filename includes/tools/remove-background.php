<?php
/**
 * Tool for removing image backgrounds using remove.bg API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove background from an image using the remove.bg API.
 *
 * This function takes an image file path, sends it to the remove.bg API,
 * and saves the processed image (with background removed) to the WordPress
 * uploads directory.
 *
 * @param string $image_path Full path to the image file.
 * @return string|WP_Error Path to the new image file on success, WP_Error on failure.
 */
function wp_mcp_ai_remove_image_background( $image_path ) {
	// Validate input.
	if ( empty( $image_path ) ) {
		return new WP_Error(
			'wp_mcp_ai_invalid_image_path',
			__( 'Image path is required.', 'wp-mcp-ai' )
		);
	}

	// Check if file exists.
	if ( ! file_exists( $image_path ) ) {
		return new WP_Error(
			'wp_mcp_ai_image_not_found',
			__( 'Image file not found.', 'wp-mcp-ai' )
		);
	}

	// Get API key from settings.
	if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
		return new WP_Error(
			'wp_mcp_ai_settings_not_available',
			__( 'Plugin settings are not available.', 'wp-mcp-ai' )
		);
	}

	$settings = WP_MCP_AI_Admin_Settings::get_settings();
	$api_key  = isset( $settings['removebg_api_key'] ) ? $settings['removebg_api_key'] : '';

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'wp_mcp_ai_removebg_api_key_missing',
			__( 'remove.bg API key is not configured. Please add it in the plugin settings.', 'wp-mcp-ai' )
		);
	}

	// Prepare the API request.
	$api_url = 'https://api.remove.bg/v1.0/removebg';

	// Validate that the image path is safe to read.
	$realpath = realpath( $image_path );
	if ( false === $realpath ) {
		return new WP_Error(
			'wp_mcp_ai_invalid_image_path',
			__( 'Invalid image path provided.', 'wp-mcp-ai' )
		);
	}

	// Security: Verify the file is within the WordPress uploads directory.
	// This prevents arbitrary file exfiltration by ensuring only files in the
	// uploads directory can be sent to the remove.bg API.
	$upload_dir = wp_upload_dir();
	if ( isset( $upload_dir['error'] ) && false !== $upload_dir['error'] ) {
		return new WP_Error(
			'wp_mcp_ai_upload_dir_error',
			$upload_dir['error']
		);
	}

	$uploads_basedir = isset( $upload_dir['basedir'] ) ? wp_normalize_path( $upload_dir['basedir'] ) : '';
	if ( empty( $uploads_basedir ) ) {
		return new WP_Error(
			'wp_mcp_ai_upload_dir_error',
			__( 'Unable to determine uploads directory.', 'wp-mcp-ai' )
		);
	}

	$normalized_realpath = wp_normalize_path( $realpath );

	// Check if the file is within the uploads directory.
	// Use strpos to check if the real path starts with the uploads base directory.
	if ( 0 !== strpos( $normalized_realpath, $uploads_basedir ) ) {
		return new WP_Error(
			'wp_mcp_ai_invalid_image_path',
			__( 'Access denied. Only files in the WordPress uploads directory can be processed.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	// Read image file.
	$image_data = file_get_contents( $realpath );
	if ( false === $image_data ) {
		return new WP_Error(
			'wp_mcp_ai_image_read_failed',
			__( 'Failed to read the image file.', 'wp-mcp-ai' )
		);
	}

	// Prepare request body.
	$boundary = '----WebKitFormBoundary' . uniqid( '', true );
	$body     = '';

	// Add image file to multipart body.
	$body .= "--{$boundary}\r\n";
	$body .= 'Content-Disposition: form-data; name="image_file"; filename="' . wp_basename( $image_path ) . "\"\r\n";
	$body .= "Content-Type: application/octet-stream\r\n\r\n";
	$body .= $image_data . "\r\n";

	// Add size parameter.
	$body .= "--{$boundary}\r\n";
	$body .= "Content-Disposition: form-data; name=\"size\"\r\n\r\n";
	$body .= "auto\r\n";

	$body .= "--{$boundary}--\r\n";

	// Make API request.
	$response = wp_remote_post(
		$api_url,
		array(
			'headers' => array(
				'X-Api-Key'    => $api_key,
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'    => $body,
			'timeout' => 60,
		)
	);

	// Check for errors.
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'wp_mcp_ai_removebg_request_failed',
			sprintf(
				/* translators: %s: Error message */
				__( 'Failed to connect to remove.bg API: %s', 'wp-mcp-ai' ),
				$response->get_error_message()
			)
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $response_code ) {
		$response_body = wp_remote_retrieve_body( $response );
		$error_message = __( 'Unknown error from remove.bg API.', 'wp-mcp-ai' );

		// Try to parse error message from response.
		$error_data = json_decode( $response_body, true );
		if ( is_array( $error_data ) && isset( $error_data['errors'][0]['title'] ) ) {
			$error_message = $error_data['errors'][0]['title'];
		}

		return new WP_Error(
			'wp_mcp_ai_removebg_api_error',
			sprintf(
				/* translators: 1: HTTP response code, 2: Error message */
				__( 'remove.bg API returned error %1$d: %2$s', 'wp-mcp-ai' ),
				$response_code,
				$error_message
			)
		);
	}

	// Get the processed image data.
	$processed_image = wp_remote_retrieve_body( $response );
	if ( empty( $processed_image ) ) {
		return new WP_Error(
			'wp_mcp_ai_removebg_empty_response',
			__( 'remove.bg API returned an empty response.', 'wp-mcp-ai' )
		);
	}

	// Generate a unique filename for the processed image.
	$upload_dir = wp_upload_dir();
	if ( isset( $upload_dir['error'] ) && false !== $upload_dir['error'] ) {
		return new WP_Error(
			'wp_mcp_ai_upload_dir_error',
			$upload_dir['error']
		);
	}

	$original_filename = wp_basename( $image_path );
	$pathinfo          = pathinfo( $original_filename );
	$filename_base     = isset( $pathinfo['filename'] ) ? $pathinfo['filename'] : 'image';
	$new_filename      = $filename_base . '-no-bg-' . time() . '.png';

	// Save the processed image.
	if ( ! function_exists( 'wp_upload_bits' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	$upload = wp_upload_bits( $new_filename, null, $processed_image );

	if ( ! empty( $upload['error'] ) ) {
		return new WP_Error(
			'wp_mcp_ai_image_save_failed',
			sprintf(
				/* translators: %s: Error message */
				__( 'Failed to save processed image: %s', 'wp-mcp-ai' ),
				$upload['error']
			)
		);
	}

	// Return the path to the new image.
	return isset( $upload['file'] ) ? $upload['file'] : new WP_Error(
		'wp_mcp_ai_image_path_missing',
		__( 'Processed image was saved but path is missing.', 'wp-mcp-ai' )
	);
}
