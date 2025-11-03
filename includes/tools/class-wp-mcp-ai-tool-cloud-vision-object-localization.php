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
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cloud-vision-base.php';

/**
 * Detects and localizes multiple objects in an image using Google Cloud Vision API.
 */
class WP_MCP_AI_Tool_Cloud_Vision_Object_Localization extends WP_MCP_AI_Tool_Cloud_Vision_Base implements WP_MCP_AI_Tool_Interface {

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

		// Check if credentials are configured.
		if ( ! $this->has_credentials() ) {
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
		$data = $this->make_api_request( $request_body );
		if ( is_wp_error( $data ) ) {
			return $data;
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
}
