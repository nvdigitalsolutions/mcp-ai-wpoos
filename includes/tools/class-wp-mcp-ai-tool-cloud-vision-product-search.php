<?php
/**
 * Tool for Cloud Vision API Product Search.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-cloud-vision-base.php';

/**
 * Searches for similar products using Google Cloud Vision API Product Search.
 */
class WP_MCP_AI_Tool_Cloud_Vision_Product_Search extends WP_MCP_AI_Tool_Cloud_Vision_Base implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cloud_vision_product_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Cloud Vision Product Search', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches for similar products using Google Cloud Vision API Product Search feature. Requires a configured product set in GCP.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'image_url'          => array(
					'type'        => 'string',
					'description' => __( 'URL of the image to search for similar products.', 'wp-mcp-ai' ),
				),
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the image to search (alternative to image_url).', 'wp-mcp-ai' ),
				),
				'project_id'         => array(
					'type'        => 'string',
					'description' => __( 'Google Cloud project ID.', 'wp-mcp-ai' ),
				),
				'location'           => array(
					'type'        => 'string',
					'description' => __( 'Compute region (e.g., us-east1, europe-west1).', 'wp-mcp-ai' ),
					'default'     => 'us-east1',
				),
				'product_set_id'     => array(
					'type'        => 'string',
					'description' => __( 'Product set ID to search within.', 'wp-mcp-ai' ),
				),
				'product_categories' => array(
					'type'        => 'array',
					'description' => __( 'Product categories to filter results (e.g., homegoods-v2, apparel-v2).', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'filter'             => array(
					'type'        => 'string',
					'description' => __( 'Optional filter expression for product labels.', 'wp-mcp-ai' ),
				),
				'max_results'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return.', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'required'   => array( 'project_id', 'location', 'product_set_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if user has required capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to use the Cloud Vision Product Search tool.', 'wp-mcp-ai' )
			);
		}

		// Check if credentials are configured.
		if ( ! $this->has_credentials() ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Google Cloud Vision API credentials are not configured. Please add an API key or service account JSON in Settings → MCP AI → Google Cloud Vision.', 'wp-mcp-ai' )
			);
		}

		// Parse required arguments.
		$project_id     = isset( $arguments['project_id'] ) ? sanitize_text_field( $arguments['project_id'] ) : '';
		$location       = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : 'us-east1';
		$product_set_id = isset( $arguments['product_set_id'] ) ? sanitize_text_field( $arguments['product_set_id'] ) : '';
		$max_results    = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 10;

		if ( empty( $project_id ) || empty( $product_set_id ) ) {
			return new WP_Error(
				'missing_parameters',
				__( 'Required parameters: project_id, product_set_id', 'wp-mcp-ai' )
			);
		}

		// Get image content.
		$image_content = $this->get_image_content( $arguments );
		if ( is_wp_error( $image_content ) ) {
			return $image_content;
		}

		// Build the API request.
		$product_set_path = sprintf(
			'projects/%s/locations/%s/productSets/%s',
			$project_id,
			$location,
			$product_set_id
		);

		$request_body = array(
			'image'   => array(
				'content' => $image_content,
			),
			'features' => array(
				array(
					'type'       => 'PRODUCT_SEARCH',
					'maxResults' => min( $max_results, 100 ),
				),
			),
			'imageContext' => array(
				'productSearchParams' => array(
					'productSet' => $product_set_path,
				),
			),
		);

		// Add product categories if specified.
		if ( ! empty( $arguments['product_categories'] ) && is_array( $arguments['product_categories'] ) ) {
			$request_body['imageContext']['productSearchParams']['productCategories'] = $arguments['product_categories'];
		}

		// Add filter if specified.
		if ( ! empty( $arguments['filter'] ) ) {
			$request_body['imageContext']['productSearchParams']['filter'] = sanitize_text_field( $arguments['filter'] );
		}

		// Make API request.
		$data = $this->make_api_request( $request_body );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Parse response.
		$results = array();
		if ( isset( $data['responses'][0]['productSearchResults']['results'] ) ) {
			foreach ( $data['responses'][0]['productSearchResults']['results'] as $result ) {
				$product = isset( $result['product'] ) ? $result['product'] : array();
				$results[] = array(
					'product_name'        => isset( $product['name'] ) ? $product['name'] : '',
					'product_display_name' => isset( $product['displayName'] ) ? $product['displayName'] : '',
					'product_description' => isset( $product['description'] ) ? $product['description'] : '',
					'product_labels'      => isset( $product['productLabels'] ) ? $product['productLabels'] : array(),
					'score'               => isset( $result['score'] ) ? $result['score'] : 0,
					'image_url'           => isset( $result['image'] ) ? $result['image'] : '',
				);
			}
		}

		return array(
			'success'           => true,
			'product_set'       => $product_set_path,
			'results_count'     => count( $results ),
			'products'          => $results,
			'indexing_time'     => isset( $data['responses'][0]['productSearchResults']['indexTime'] ) ? $data['responses'][0]['productSearchResults']['indexTime'] : null,
		);
	}
}
