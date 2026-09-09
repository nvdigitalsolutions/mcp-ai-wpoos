<?php
/**
 * Remove-background helper (ecosystem port - Wave F2, image-production data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in monolith
 * installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root; upstream mixed base-domain strings swap to `nvoos-content-graph-pro`.
 *
 * Tool for removing image backgrounds using remove.bg API.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


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
			__( 'Image path is required.', 'nvoos-content-graph-pro' )
		);
	}

	// Security: verify the file is within the WordPress uploads directory
	// BEFORE any other handling. This prevents arbitrary file exfiltration
	// (including via symlinks and traversal) regardless of API key state.
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
			__( 'Unable to determine uploads directory.', 'nvoos-content-graph-pro' )
		);
	}

	// Resolve the real path. realpath() also resolves symlinks for existing
	// files; when the file itself does not exist (e.g. a traversal attempt)
	// resolve the parent directory so the containment check still applies.
	$realpath = realpath( $image_path );
	if ( false === $realpath ) {
		$dirname = realpath( dirname( $image_path ) );
		if ( false === $dirname ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_image_path',
				__( 'Access denied. Only files in the WordPress uploads directory can be processed.', 'nvoos-content-graph-pro' ),
				array( 'status' => 403 )
			);
		}
		$realpath = wp_normalize_path( $dirname . '/' . wp_basename( $image_path ) );
	} else {
		$realpath = wp_normalize_path( $realpath );
	}

	// Normalize paths by removing trailing slashes for consistent comparison.
	$uploads_basedir = rtrim( $uploads_basedir, '/' );
	$realpath        = rtrim( $realpath, '/' );

	// Check if the file is within the uploads directory.
	// Add trailing slash to uploads_basedir to prevent bypass via paths like /uploads_malicious/.
	// The file path must start with uploads directory followed by a slash or be exactly the uploads directory.
	if ( 0 !== strpos( $realpath . '/', $uploads_basedir . '/' ) &&
		$realpath !== $uploads_basedir ) {
		return new WP_Error(
			'wp_mcp_ai_invalid_image_path',
			__( 'Access denied. Only files in the WordPress uploads directory can be processed.', 'nvoos-content-graph-pro' ),
			array( 'status' => 403 )
		);
	}

	// Check if file exists.
	if ( ! file_exists( $image_path ) ) {
		return new WP_Error(
			'wp_mcp_ai_image_not_found',
			__( 'Image file not found.', 'nvoos-content-graph-pro' )
		);
	}

	// Get API key from settings.
	if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
		return new WP_Error(
			'wp_mcp_ai_settings_not_available',
			__( 'Plugin settings are not available.', 'nvoos-content-graph-pro' )
		);
	}

	$settings = WP_MCP_AI_Admin_Settings::get_settings();
	$api_key  = isset( $settings['removebg_api_key'] ) ? $settings['removebg_api_key'] : '';

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'wp_mcp_ai_removebg_api_key_missing',
			__( 'remove.bg API key is not configured. Please add it in the plugin settings.', 'nvoos-content-graph-pro' )
		);
	}

	// Prepare the API request.
	$api_url = 'https://api.remove.bg/v1.0/removebg';

	// Read image file.
	$image_data = file_get_contents( $realpath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
	if ( false === $image_data ) {
		return new WP_Error(
			'wp_mcp_ai_image_read_failed',
			__( 'Failed to read the image file.', 'nvoos-content-graph-pro' )
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
				__( 'Failed to connect to remove.bg API: %s', 'nvoos-content-graph-pro' ),
				$response->get_error_message()
			)
		);
	}

	$response_code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $response_code ) {
		$response_body = wp_remote_retrieve_body( $response );
		$error_message = __( 'Unknown error from remove.bg API.', 'nvoos-content-graph-pro' );

		// Try to parse error message from response.
		$error_data = json_decode( $response_body, true );
		if ( is_array( $error_data ) && isset( $error_data['errors'][0]['title'] ) ) {
			$error_message = $error_data['errors'][0]['title'];
		}

		return new WP_Error(
			'wp_mcp_ai_removebg_api_error',
			sprintf(
				/* translators: 1: HTTP response code, 2: Error message */
				__( 'remove.bg API returned error %1$d: %2$s', 'nvoos-content-graph-pro' ),
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
			__( 'remove.bg API returned an empty response.', 'nvoos-content-graph-pro' )
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
				__( 'Failed to save processed image: %s', 'nvoos-content-graph-pro' ),
				$upload['error']
			)
		);
	}

	// Return the path to the new image.
	return isset( $upload['file'] ) ? $upload['file'] : new WP_Error(
		'wp_mcp_ai_image_path_missing',
		__( 'Processed image was saved but path is missing.', 'nvoos-content-graph-pro' )
	);
}
