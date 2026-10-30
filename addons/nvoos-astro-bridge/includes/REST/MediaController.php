<?php
/**
 * Media REST controller — image uploads for the Astro CMS SPA.
 *
 * The admin SPA's standalone /manage surface (Proposal 092) cannot use the
 * wp-admin media modal, so image fields upload through this endpoint
 * instead. Multipart POST with a `file` part; the caller must hold the
 * manage capability and a cookie session (the standard wp_rest nonce check
 * applies to cookie-authenticated state-changing requests automatically).
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Single-file image upload surface: POST /media.
 */
class MediaController extends BaseController {

	/**
	 * Controller namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'media';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_image' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_media' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /media/{id} — attachment record for image previews.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_media( $request ) {
		$attachment_id = (int) $request->get_param( 'id' );
		$url           = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return new WP_Error(
				'nvoos_media_not_found',
				'Attachment not found.',
				array( 'status' => 404 )
			);
		}

		$path = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		return rest_ensure_response(
			array(
				'id'   => $attachment_id,
				'url'  => $url,
				'path' => $path,
			)
		);
	}

	/**
	 * POST /media — accept one image upload and create an attachment.
	 *
	 * Validation and MIME checks are delegated to wp_handle_upload (which
	 * uses wp_check_filetype_and_ext against the allowed mime list); the
	 * capability gate is the only caller-side guard needed.
	 *
	 * The handler action is filterable as a test seam: `wp_handle_upload`
	 * (default) verifies the file came through PHP's upload mechanism — a
	 * real security property for a REST surface whose body could otherwise
	 * name arbitrary server paths. CLI test environments cannot produce
	 * such files, so the suite filters the action to `wp_handle_sideload`
	 * (is_readable gate instead).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_image( $request ) {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'nvoos_media_missing_file',
				'A file is required.',
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];

		if ( ! isset( $file['tmp_name'] ) || ! is_string( $file['tmp_name'] ) ) {
			return new WP_Error(
				'nvoos_media_invalid_file',
				'The uploaded file is invalid.',
				array( 'status' => 400 )
			);
		}

		// These functions are only available in the admin context; load them
		// for front-end origin uploads (the storefront /manage surface).
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$action = (string) apply_filters( 'funiq_bridge_media_upload_action', 'wp_handle_upload' );

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'test_type' => true,
				'action'    => $action,
			)
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error(
				'nvoos_media_upload_failed',
				(string) $upload['error'],
				array( 'status' => 400 )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( wp_basename( $upload['file'] ) ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'nvoos_media_attach_failed',
				'Upload succeeded but the attachment could not be created: ' . $attachment_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		$url = wp_get_attachment_url( $attachment_id );
		$dir = wp_get_upload_dir();

		// Relative upload path (e.g. 2026/10/image.jpg) so same-origin
		// consumers can serve the file through their media proxy (/media/*).
		$path = '';
		if ( is_array( $dir ) && isset( $dir['basedir'] ) && is_string( $dir['basedir'] ) ) {
			$path = ltrim( str_replace( $dir['basedir'], '', (string) $upload['file'] ), '/\\' );
		}

		return rest_ensure_response(
			array(
				'id'   => $attachment_id,
				'url'  => is_string( $url ) ? $url : '',
				'path' => $path,
			)
		);
	}
}
