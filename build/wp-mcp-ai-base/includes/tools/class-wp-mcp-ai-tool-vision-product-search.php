<?php
/**
 * Tool for Google Cloud Vision API Product Search.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Provides an assistant tool that searches for similar products using Vision API.
 *
 * Note: This tool intentionally does NOT include authentication credentials,
 * demonstrating what happens when Vision API calls are made without proper auth.
 */
class WP_MCP_AI_Tool_Vision_Product_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';
	const VISION_API_ENDPOINT         = 'https://vision.googleapis.com/v1/images:annotate';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vision_product_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vision Product Search', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches for similar products using Google Cloud Vision API Product Search feature. Note: Requires proper Google Cloud authentication to succeed.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_url'        => array(
					'type'        => 'string',
					'description' => __( 'URL of the product image to search for.', 'wp-mcp-ai' ),
				),
				'image_content'    => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative to image_url.', 'wp-mcp-ai' ),
				),
				'product_set'      => array(
					'type'        => 'string',
					'description' => __( 'Optional product set resource name to search within.', 'wp-mcp-ai' ),
				),
				'product_category' => array(
					'type'        => 'string',
					'description' => __( 'Optional product category to filter results.', 'wp-mcp-ai' ),
				),
				'filter'           => array(
					'type'        => 'string',
					'description' => __( 'Optional filter expression for product search.', 'wp-mcp-ai' ),
				),
				'max_results'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Maximum number of similar products to return (1-100).', 'wp-mcp-ai' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters(
			'wp_mcp_ai_vision_product_search_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_forbidden',
				__( 'You do not have permission to use Vision Product Search.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Validate that either image_url or image_content is provided.
		if ( empty( $arguments['image_url'] ) && empty( $arguments['image_content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_missing_image',
				__( 'Either image_url or image_content must be provided.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		// Build the image source object.
		$image = array();
		if ( ! empty( $arguments['image_url'] ) ) {
			$image['source'] = array(
				'imageUri' => esc_url_raw( $arguments['image_url'] ),
			);
		} elseif ( ! empty( $arguments['image_content'] ) ) {
			$image['content'] = sanitize_text_field( $arguments['image_content'] );
		}

		// Build product search parameters.
		$product_search_params = array();
		if ( ! empty( $arguments['product_set'] ) ) {
			$product_search_params['productSet'] = sanitize_text_field( $arguments['product_set'] );
		}
		if ( ! empty( $arguments['product_category'] ) ) {
			$product_search_params['productCategories'] = array( sanitize_text_field( $arguments['product_category'] ) );
		}
		if ( ! empty( $arguments['filter'] ) ) {
			$product_search_params['filter'] = sanitize_text_field( $arguments['filter'] );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 10;
		$max_results = min( 100, max( 1, $max_results ) );

		// Build the request body.
		$request_body = array(
			'requests' => array(
				array(
					'image'    => $image,
					'features' => array(
						array(
							'type'       => 'PRODUCT_SEARCH',
							'maxResults' => $max_results,
						),
					),
				),
			),
		);

		if ( ! empty( $product_search_params ) ) {
			$request_body['requests'][0]['imageContext'] = array(
				'productSearchParams' => $product_search_params,
			);
		}

		$timeout = apply_filters( 'wp_mcp_ai_vision_request_timeout', 30, $context, $arguments, $this );

		// Intentionally make the request WITHOUT authentication.
		// This demonstrates the behavior when authentication is missing.
		$response = wp_remote_post(
			self::VISION_API_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => max( 5, absint( $timeout ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Vision API request failed: %s', 'wp-mcp-ai' ),
					$response->get_error_message()
				),
				array( 'status' => 500 )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		// Handle API errors (expected when authentication is missing).
		if ( $status_code >= 400 ) {
			$error_message = __( 'Vision API returned an error.', 'wp-mcp-ai' );
			if ( is_array( $decoded ) && isset( $decoded['error']['message'] ) ) {
				$error_message = $decoded['error']['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_vision_api_error',
				$error_message,
				array(
					'status'       => $status_code,
					'api_response' => $decoded,
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_invalid_response',
				__( 'Vision API returned an invalid response.', 'wp-mcp-ai' ),
				array( 'status' => 500 )
			);
		}

		return $decoded;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
