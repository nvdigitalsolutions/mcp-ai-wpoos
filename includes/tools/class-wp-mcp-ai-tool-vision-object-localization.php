<?php
/**
 * Tool for Google Cloud Vision API Object Localization.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-cloud-vision-client.php';

/**
 * Provides an assistant tool that detects and localizes objects using Vision API.
 *
 * Requires a Google Cloud API key with the Cloud Vision API enabled. The plugin
 * reuses the configured Gemini API key, which can be overridden via the
 * `wp_mcp_ai_vision_api_key` filter.
 */
class WP_MCP_AI_Tool_Vision_Object_Localization implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';
	const VISION_API_ENDPOINT         = 'https://vision.googleapis.com/v1/images:annotate';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vision_object_localization';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vision Object Localization', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Detects and localizes multiple objects in an image using Google Cloud Vision API. Note: Requires proper Google Cloud authentication to succeed.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Detecting and locating multiple objects in an image with bounding information via the Google Cloud Vision API.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Finding similar products; use vision_product_search for product matching, or analyze_image for general image analysis.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'vision_product_search', 'analyze_image' ),
			'notes'           => __( 'Requires a Google Cloud API key (reuses the Gemini key); accepts image_url or base64 image_content, max 100 results.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_url'     => array(
					'type'        => 'string',
					'description' => __( 'URL of the image to analyze for object localization.', 'mcp-ai-wpoos' ),
				),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content as an alternative to image_url.', 'mcp-ai-wpoos' ),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => __( 'Maximum number of objects to detect (1-100).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'wp_mcp_ai_vision_object_localization_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_forbidden',
				__( 'You do not have permission to use Vision Object Localization.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Validate that either image_url or image_content is provided.
		if ( empty( $arguments['image_url'] ) && empty( $arguments['image_content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vision_missing_image',
				__( 'Either image_url or image_content must be provided.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Build the image source object.
		$client = new WP_MCP_AI_Cloud_Vision_Client();
		$image  = $client->build_image_source(
			isset( $arguments['image_url'] ) ? $arguments['image_url'] : '',
			isset( $arguments['image_content'] ) ? $arguments['image_content'] : ''
		);

		$max_results = isset( $arguments['max_results'] ) ? min( 100, max( 1, absint( $arguments['max_results'] ) ) ) : 10;

		// Delegate the request, error mapping, and response decoding to the
		// shared Cloud Vision client (same endpoint, key, and timeout filters).
		return $client->annotate(
			array(
				array(
					'type'       => 'OBJECT_LOCALIZATION',
					'maxResults' => $max_results,
				),
			),
			$image,
			$context,
			$arguments,
			$this
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'media_processing',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'data_scientist', 'researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'external-api',         // Calls Google Cloud Vision API.
		);
	}
}
