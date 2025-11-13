<?php
/**
 * Files Controller for REST API
 *
 * Handles file-related endpoints including file downloads.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Files Controller Class
 *
 * Manages file-related REST API endpoints:
 * - GET /files/{id}/download - File download endpoint
 */
class WP_MCP_AI_REST_Files_Controller extends WP_MCP_AI_REST_Controller_Base {
	/**
	 * Reference to the main REST controller for shared functionality.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $main_controller;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST                    $main_controller Main REST controller.
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator   Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator       Request validator (optional, for DI).
	 */
	public function __construct( $main_controller = null, $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
		$this->main_controller = $main_controller;
	}

	/**
	 * Register files routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/files/(?P<file_id>[^/]+)/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'download_file_permissions_check' ),
					'callback'            => array( $this, 'handle_file_download' ),
					'args'                => array(
						'assistant_id'  => array(
							'description'       => __( 'ID of the assistant context for file access.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'file_id'       => array(
							'description'       => __( 'ID or identifier of the file to download.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'download_name' => array(
							'description'       => __( 'Optional custom filename for the download.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_file_name',
						),
						'disposition'   => array(
							'description'       => __( 'Content-Disposition header value (attachment or inline).', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => false,
							'default'           => 'attachment',
							'enum'              => array( 'attachment', 'inline' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check for file downloads.
	 *
	 * Handles nonce in header or query parameter.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|WP_Error
	 */
	public function download_file_permissions_check( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			$nonce_param = $request->get_param( '_wpnonce' );

			if ( is_string( $nonce_param ) && '' !== $nonce_param ) {
				$request->set_header( 'X-WP-Nonce', $nonce_param );
			}
		}

		return $this->permissions_check( $request );
	}

	/**
	 * Handle GET /files/{id}/download - File download.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_file_download( WP_REST_Request $request ) {
		$assistant_id = $this->main_controller->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$scoped_id    = $this->main_controller->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		if ( $scoped_id ) {
			$assistant_id = $scoped_id;
		}

		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );

		if ( '' === $file_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$local_attachment = $this->main_controller->resolve_local_attachment_for_openai_file( $file_id );

		if ( is_wp_error( $local_attachment ) ) {
			return $local_attachment;
		}

		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
		}

		$client = $this->main_controller->get_openai_client();
		$result = $client->download_file( $file_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$body = isset( $result['body'] ) ? (string) $result['body'] : '';

		if ( '' === $body ) {
			return new WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'wp-mcp-ai' ) );
		}

		$content_type = isset( $result['content_type'] ) && '' !== $result['content_type'] ? $result['content_type'] : 'application/octet-stream';

		if ( 'application/octet-stream' === $content_type && ! empty( $local_attachment['metadata']['mime_type'] ) ) {
			if ( function_exists( 'sanitize_mime_type' ) ) {
				$content_type = sanitize_mime_type( $local_attachment['metadata']['mime_type'] );
			} else {
				$content_type = sanitize_text_field( $local_attachment['metadata']['mime_type'] );
			}
		}

		$requested_name = $request->get_param( 'download_name' );
		$download_name  = '';

		if ( is_string( $requested_name ) && '' !== $requested_name ) {
			$download_name = sanitize_file_name( $requested_name );
		}

		$filename = '';

		if ( isset( $result['filename'] ) && '' !== $result['filename'] ) {
			$filename = sanitize_file_name( $result['filename'] );
		} elseif ( ! empty( $local_attachment['metadata']['filename'] ) ) {
			$filename = sanitize_file_name( $local_attachment['metadata']['filename'] );
		}

		if ( '' === $filename && '' !== $download_name ) {
			$filename = $download_name;
		}

		if ( '' === $filename ) {
			$fallback_name = sanitize_file_name( 'openai-file-' . $file_id );
			$filename      = '' !== $fallback_name ? $fallback_name : 'openai-file';
		}

		$disposition = $request->get_param( 'disposition' );
		$disposition = is_string( $disposition ) ? strtolower( $disposition ) : '';

		if ( ! in_array( $disposition, array( 'inline', 'attachment' ), true ) ) {
			$disposition = 'attachment';
		}

		$content_length = strlen( $body );

		$headers = array(
			'Content-Type'           => $content_type,
			'Content-Length'         => (string) $content_length,
			'Content-Disposition'    => sprintf( '%s; filename="%s"', $disposition, $filename ),
			'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
			'Pragma'                 => 'no-cache',
			'X-Content-Type-Options' => 'nosniff',
			'X-Robots-Tag'           => 'noindex',
		);

		$headers = apply_filters( 'wp_mcp_ai_file_download_headers', $headers, $file_id, $result, $request );

		add_filter(
			'rest_pre_serve_request',
			function ( $served, $response, $request_obj, $server ) use ( $headers, $body ) {
				if ( $served ) {
					return $served;
				}

				foreach ( $headers as $key => $value ) {
					if ( '' === $key || null === $value ) {
						continue;
					}

					$server->send_header( $key, $value );
				}

				echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

				return true;
			},
			10,
			4
		);

		return new WP_REST_Response( null, 200 );
	}
}
