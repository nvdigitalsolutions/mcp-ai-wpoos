<?php
/**
 * Tool for Cloud Vision API Object Localization.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';

/**
 * Detects and localizes multiple objects in an image using Google Cloud Vision API.
 */
class WP_MCP_AI_Tool_Cloud_Vision_Object_Localization implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cloud_vision_object_localization';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Cloud Vision Object Localization', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects and localizes multiple objects in an image, providing object names, confidence scores, and bounding box coordinates.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the image to analyze.', 'wp-mcp-ai' ),
				),
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the image to analyze (alternative to image_url).', 'wp-mcp-ai' ),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of objects to detect.', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if user has required capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to use the Cloud Vision Object Localization tool.', 'wp-mcp-ai' )
			);
		}

		// Get credentials from settings.
		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$api_key          = isset( $settings['google_vision_api_key'] ) ? $settings['google_vision_api_key'] : '';
		$credentials_json = isset( $settings['google_vision_credentials_json'] ) ? $settings['google_vision_credentials_json'] : '';

		if ( empty( $api_key ) && empty( $credentials_json ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Google Cloud Vision API credentials are not configured. Please add an API key or service account JSON in Settings → MCP AI → Google Cloud Vision.', 'wp-mcp-ai' )
			);
		}

		// Get max results.
		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 10;

		// Get image content.
		$image_content = $this->get_image_content( $arguments );
		if ( is_wp_error( $image_content ) ) {
			return $image_content;
		}

		// Build the API request.
		$request_body = array(
			'image'    => array(
				'content' => $image_content,
			),
			'features' => array(
				array(
					'type'       => 'OBJECT_LOCALIZATION',
					'maxResults' => min( $max_results, 100 ),
				),
			),
		);

		// Make API request.
		$endpoint = 'https://vision.googleapis.com/v1/images:annotate';
		if ( ! empty( $api_key ) ) {
			$endpoint = add_query_arg( 'key', $api_key, $endpoint );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( array( 'requests' => array( $request_body ) ) ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Cloud Vision API request failed: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				)
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 200 !== $response_code ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Unknown error', 'wp-mcp-ai' );
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %1$d: HTTP status code, %2$s: error message */
					__( 'Cloud Vision API returned error %1$d: %2$s', 'wp-mcp-ai' ),
					$response_code,
					$error_message
				)
			);
		}

		// Parse response.
		$objects = array();
		if ( isset( $data['responses'][0]['localizedObjectAnnotations'] ) ) {
			foreach ( $data['responses'][0]['localizedObjectAnnotations'] as $annotation ) {
				$bounding_poly = isset( $annotation['boundingPoly'] ) ? $annotation['boundingPoly'] : array();
				$vertices      = array();

				if ( isset( $bounding_poly['normalizedVertices'] ) ) {
					foreach ( $bounding_poly['normalizedVertices'] as $vertex ) {
						$vertices[] = array(
							'x' => isset( $vertex['x'] ) ? $vertex['x'] : 0,
							'y' => isset( $vertex['y'] ) ? $vertex['y'] : 0,
						);
					}
				}

				$objects[] = array(
					'name'           => isset( $annotation['name'] ) ? $annotation['name'] : '',
					'mid'            => isset( $annotation['mid'] ) ? $annotation['mid'] : '',
					'score'          => isset( $annotation['score'] ) ? $annotation['score'] : 0,
					'bounding_poly'  => $vertices,
				);
			}
		}

		return array(
			'success'       => true,
			'objects_count' => count( $objects ),
			'objects'       => $objects,
		);
	}

	/**
	 * Get image content as base64 from URL or attachment.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error Base64 encoded image or error.
	 */
	private function get_image_content( $arguments ) {
		// Try attachment ID first.
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'invalid_attachment',
					__( 'Attachment not found or file does not exist.', 'wp-mcp-ai' )
				);
			}

			$image_data = file_get_contents( $file_path );
			if ( false === $image_data ) {
				return new WP_Error(
					'read_error',
					__( 'Failed to read attachment file.', 'wp-mcp-ai' )
				);
			}

			return base64_encode( $image_data );
		}

		// Try image URL.
		if ( ! empty( $arguments['image_url'] ) ) {
			$image_url = esc_url_raw( $arguments['image_url'] );
			$response  = wp_remote_get( $image_url, array( 'timeout' => 15 ) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to download image: %s', 'wp-mcp-ai' ),
						$response->get_error_message()
					)
				);
			}

			$image_data = wp_remote_retrieve_body( $response );
			if ( empty( $image_data ) ) {
				return new WP_Error(
					'empty_image',
					__( 'Downloaded image is empty.', 'wp-mcp-ai' )
				);
			}

			return base64_encode( $image_data );
		}

		return new WP_Error(
			'missing_image',
			__( 'Either image_url or attachment_id must be provided.', 'wp-mcp-ai' )
		);
	}
}
