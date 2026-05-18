<?php
/**
 * Tool for batch processing images with multiple operations.
 *
 * Applies a series of operations to multiple images at once.
 * Supports chaining operations like resize, compress, convert, etc.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.8
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Batch process multiple images with operations.
 */
class WP_MCP_AI_Tool_Batch_Process_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'batch_process_images';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Batch Process Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Apply multiple operations to a batch of images at once. Supports chaining operations like resize, compress, and convert.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'images'     => array(
					'type'        => 'array',
					'description' => __( 'Array of image IDs or URLs to process.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'oneOf' => array(
							array( 'type' => 'integer' ),
							array( 'type' => 'string' ),
						),
					),
				),
				'operations' => array(
					'type'        => 'array',
					'description' => __( 'Ordered list of operations to apply to each image.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'type'       => array(
								'type' => 'string',
								'enum' => array( 'resize', 'compress', 'convert', 'enhance', 'remove_background' ),
							),
							'parameters' => array(
								'type'        => 'object',
								'description' => __( 'Operation-specific parameters.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'type' ),
					),
				),
			),
			'required'             => array( 'images', 'operations' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'performance-impact',
			'cpu-intensive',
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
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to batch process images.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate inputs.
		$images = isset( $arguments['images'] ) ? (array) $arguments['images'] : array();
		if ( empty( $images ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_images',
				__( 'At least one image is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$operations = isset( $arguments['operations'] ) ? (array) $arguments['operations'] : array();
		if ( empty( $operations ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_operations',
				__( 'At least one operation is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Process each image.
		$results = array();
		$errors  = array();

		foreach ( $images as $image_ref ) {
			$result = $this->process_single_image( $image_ref, $operations, $context );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'image' => $image_ref,
					'error' => $result->get_error_message(),
				);
			} else {
				$results[] = $result;
			}
		}

		return array(
			'success'   => true,
			'processed' => count( $results ),
			'failed'    => count( $errors ),
			'results'   => $results,
			'errors'    => $errors,
		);
	}

	/**
	 * Process a single image with operations.
	 *
	 * @param int|string $image_ref  Image ID or URL.
	 * @param array      $operations Operations to apply.
	 * @param array      $context    Execution context.
	 * @return array|WP_Error Processing result or error.
	 */
	protected function process_single_image( $image_ref, $operations, $context ) {
		$current_ref = $image_ref;

		foreach ( $operations as $operation ) {
			$type   = isset( $operation['type'] ) ? sanitize_text_field( $operation['type'] ) : '';
			$params = isset( $operation['parameters'] ) ? (array) $operation['parameters'] : array();

			// Map operation type to tool.
			$tool_slug = $this->get_tool_for_operation( $type );
			if ( ! $tool_slug ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_operation',
					sprintf(
						/* translators: %s: operation type */
						__( 'Invalid operation type: %s', 'mcp-ai-wpoos-pro' ),
						$type
					)
				);
			}

			// Get tool instance.
			$tool = wp_mcp_ai_get_tool_instance( $tool_slug );
			if ( ! $tool ) {
				return new WP_Error(
					'wp_mcp_ai_tool_not_found',
					sprintf(
						/* translators: %s: tool slug */
						__( 'Tool not found: %s', 'mcp-ai-wpoos-pro' ),
						$tool_slug
					)
				);
			}

			// Prepare arguments.
			$args = $params;
			if ( is_int( $current_ref ) ) {
				$args['attachment_id'] = $current_ref;
			} else {
				$args['url'] = $current_ref;
			}

			// Execute operation.
			$result = $tool->execute( $args, $context );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update reference for next operation.
			if ( isset( $result['attachment_id'] ) ) {
				$current_ref = $result['attachment_id'];
			}
		}

		return array(
			'original'      => $image_ref,
			'attachment_id' => $current_ref,
		);
	}

	/**
	 * Get tool slug for operation type.
	 *
	 * @param string $type Operation type.
	 * @return string|null Tool slug or null if not found.
	 */
	protected function get_tool_for_operation( $type ) {
		$map = array(
			'resize'            => 'resize_image_smart',
			'compress'          => 'compress_image',
			'convert'           => 'convert_image_format',
			'enhance'           => 'enhance_image_quality',
			'remove_background' => 'remove_image_background',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : null;
	}

	/**
	 * Sanitize the tool result for LLM consumption.
	 *
	 * @param array|WP_Error $result The result to sanitize.
	 * @return array Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				),
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}
}
