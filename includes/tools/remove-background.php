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
 * @param int|string $image_reference Attachment ID or media library URL.
 * @return string|WP_Error Path to the new image file on success, WP_Error on failure.
 */
function wp_mcp_ai_remove_image_background( $image_reference ) {
        // Validate input.
        if ( empty( $image_reference ) && 0 !== $image_reference && '0' !== $image_reference ) {
                return new WP_Error(
                        'wp_mcp_ai_invalid_attachment_reference',
                        __( 'Image reference is required.', 'wp-mcp-ai' ),
                        array( 'status' => 400 )
                );
        }

        if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
                require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';
        }

        $attachment_id = 0;

        if ( is_numeric( $image_reference ) ) {
                $attachment_id = absint( $image_reference );
        } elseif ( is_string( $image_reference ) ) {
                $image_reference = trim( $image_reference );

                if ( '' === $image_reference ) {
                        return new WP_Error(
                                'wp_mcp_ai_invalid_attachment_reference',
                                __( 'Image reference is required.', 'wp-mcp-ai' ),
                                array( 'status' => 400 )
                        );
                }

                if ( filter_var( $image_reference, FILTER_VALIDATE_URL ) ) {
                        $attachment_id = attachment_url_to_postid( $image_reference );

                        if ( ! $attachment_id ) {
                                return new WP_Error(
                                        'wp_mcp_ai_invalid_attachment_reference',
                                        __( 'The provided media URL could not be resolved to an attachment.', 'wp-mcp-ai' ),
                                        array( 'status' => 400 )
                                );
                        }
                } else {
                        return new WP_Error(
                                'wp_mcp_ai_invalid_attachment_reference',
                                __( 'Only attachment IDs or media library URLs may be processed.', 'wp-mcp-ai' ),
                                array( 'status' => 400 )
                        );
                }
        } else {
                return new WP_Error(
                        'wp_mcp_ai_invalid_attachment_reference',
                        __( 'Only attachment IDs or media library URLs may be processed.', 'wp-mcp-ai' ),
                        array( 'status' => 400 )
                );
        }

        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
                return new WP_Error(
                        'wp_mcp_ai_invalid_attachment_reference',
                        __( 'A valid media attachment is required.', 'wp-mcp-ai' ),
                        array( 'status' => 400 )
                );
        }

        $can_access = WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id );
        $can_access = apply_filters( 'wp_mcp_ai_remove_background_can_access_attachment', $can_access, $attachment_id );

        if ( ! $can_access ) {
                return new WP_Error(
                        'wp_mcp_ai_attachment_forbidden',
                        __( 'You do not have permission to access this attachment.', 'wp-mcp-ai' ),
                        array( 'status' => 403 )
                );
        }

        $file_path = get_attached_file( $attachment_id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
                return new WP_Error(
                        'wp_mcp_ai_image_not_found',
                        __( 'Image file not found.', 'wp-mcp-ai' )
                );
        }

        $realpath = realpath( $file_path );
        if ( false === $realpath ) {
                return new WP_Error(
                        'wp_mcp_ai_invalid_image_path',
                        __( 'Invalid image path provided.', 'wp-mcp-ai' )
                );
        }

        $uploads = wp_get_upload_dir();

        if ( empty( $uploads['basedir'] ) ) {
                return new WP_Error(
                        'wp_mcp_ai_upload_dir_unavailable',
                        __( 'The uploads directory could not be determined.', 'wp-mcp-ai' )
                );
        }

        $normalized_path   = wp_normalize_path( $realpath );
        $normalized_upload = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );

        if ( 0 !== strpos( $normalized_path, $normalized_upload ) ) {
                return new WP_Error(
                        'wp_mcp_ai_attachment_outside_uploads',
                        __( 'Only attachments stored in the uploads directory can be processed.', 'wp-mcp-ai' ),
                        array( 'status' => 400 )
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
	$api_key  = isset( $settings['wp_mcp_ai_removebg_api_key'] ) ? $settings['wp_mcp_ai_removebg_api_key'] : '';

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'wp_mcp_ai_removebg_api_key_missing',
			__( 'remove.bg API key is not configured. Please add it in the plugin settings.', 'wp-mcp-ai' )
		);
	}

	// Prepare the API request.
	$api_url = 'https://api.remove.bg/v1.0/removebg';

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
        $body .= 'Content-Disposition: form-data; name="image_file"; filename="' . wp_basename( $realpath ) . "\"\r\n";
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

        $original_filename = wp_basename( $realpath );
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
