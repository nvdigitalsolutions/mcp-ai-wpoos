<?php
/**
 * NV oOS Media Studio — REST API Controller
 *
 * @package NV_oOS_Media_Studio
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Media Studio addon.
 *
 * @since 0.1.0
 * @since 0.3.0  Added upload endpoint for saving edited images to WP Media Library.
 */
class NV_oOS_Media_Studio_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-media-studio/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => array( __CLASS__, 'upload_permission' ),
				'args'                => array(
					'image'    => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => 'Base64-encoded PNG image data URL (data:image/png;base64,…).',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'filename' => array(
						'required'          => false,
						'type'              => 'string',
						'description'       => 'Optional filename for the uploaded image.',
						'sanitize_callback' => 'sanitize_file_name',
						'default'           => 'media-studio-export.png',
					),
				),
			)
		);
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You do not have permission to access this endpoint.', 'nvoos-media-studio' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Upload_files gate. Allows any logged-in user with upload_files capability.
	 *
	 * @since 0.3.0
	 * @return bool|WP_Error
	 */
	public static function upload_permission() {
		if ( is_user_logged_in() && current_user_can( 'upload_files' ) ) {
			return true;
		}
		return new WP_Error(
			'forbidden',
			__( 'You do not have permission to upload files.', 'nvoos-media-studio' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ? NVOOS_MEDIA_STUDIO_VERSION : 'unknown',
			)
		);
	}

	/**
	 * Upload a base64-encoded PNG image to the WordPress Media Library.
	 *
	 * @since 0.3.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( $request ) {
		$data_url = $request->get_param( 'image' );
		$filename = $request->get_param( 'filename' );

		// Validate that this is a PNG data URL.
		if ( 0 !== strpos( $data_url, 'data:image/png;base64,' ) ) {
			return new WP_Error(
				'invalid_image',
				__( 'Image must be a base64-encoded PNG data URL.', 'nvoos-media-studio' ),
				array( 'status' => 400 )
			);
		}

		// Extract the base64 data after the comma.
		$base64 = substr( $data_url, strpos( $data_url, ',' ) + 1 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $base64 );

		if ( false === $decoded ) {
			return new WP_Error(
				'invalid_image',
				__( 'Failed to decode image data.', 'nvoos-media-studio' ),
				array( 'status' => 400 )
			);
		}

		// Ensure PNG extension.
		$filename = sanitize_file_name( $filename );
		if ( ! preg_match( '/\.png$/i', $filename ) ) {
			$filename .= '.png';
		}

		// Generate a unique filename.
		$filename = wp_unique_filename( wp_upload_dir()['path'], $filename );

		// Write the file using wp_upload_bits.
		$upload = wp_upload_bits( $filename, null, $decoded );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'upload_error',
				$upload['error'],
				array( 'status' => 500 )
			);
		}

		// Insert as WordPress attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate attachment metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return rest_ensure_response(
			array(
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
				'filename' => $filename,
			)
		);
	}
}
