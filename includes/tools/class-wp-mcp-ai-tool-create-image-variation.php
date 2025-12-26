<?php
/**
 * Tool that creates variations of existing images using OpenAI's Image Variations API (DALL-E).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for creating image variations via OpenAI's DALL-E API.
 */
class WP_MCP_AI_Tool_Create_Image_Variation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_image_variation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Image Variation', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates variations of an existing image using OpenAI\'s DALL-E API. Useful for generating alternative versions of an image.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'image_id'        => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the source image.', 'wp-mcp-ai' ),
				),
				'model'           => array(
					'type'        => 'string',
					'description' => __( 'OpenAI model to use for generating variations.', 'wp-mcp-ai' ),
					'enum'        => array( 'dall-e-2' ),
					'default'     => 'dall-e-2',
				),
				'n'               => array(
					'type'        => 'integer',
					'description' => __( 'Number of variations to generate.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 1,
				),
				'size'            => array(
					'type'        => 'string',
					'description' => __( 'Size of the variation images.', 'wp-mcp-ai' ),
					'enum'        => array( '256x256', '512x512', '1024x1024' ),
					'default'     => '1024x1024',
				),
				'response_format' => array(
					'type'        => 'string',
					'description' => __( 'Format for the response.', 'wp-mcp-ai' ),
					'enum'        => array( 'url', 'b64_json' ),
					'default'     => 'b64_json',
				),
			),
			'required'   => array( 'image_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate image_id.
		if ( empty( $arguments['image_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The image_id parameter is required.', 'wp-mcp-ai' ),
			);
		}

		$image_id = absint( $arguments['image_id'] );
		if ( ! wp_attachment_is_image( $image_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The specified image_id is not a valid image attachment.', 'wp-mcp-ai' ),
			);
		}

		// Get image file path.
		$image_path = get_attached_file( $image_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The image file could not be found.', 'wp-mcp-ai' ),
			);
		}

		// Prepare options.
		$options = array();

		if ( ! empty( $arguments['model'] ) ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		if ( ! empty( $arguments['n'] ) ) {
			$options['n'] = absint( $arguments['n'] );
		}

		if ( ! empty( $arguments['size'] ) ) {
			$options['size'] = sanitize_text_field( $arguments['size'] );
		}

		if ( ! empty( $arguments['response_format'] ) ) {
			$options['response_format'] = sanitize_key( $arguments['response_format'] );
		}

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_image_variation( $image_path, $options );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		// Process and save variation images.
		$saved_images = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $index => $image_data ) {
				$saved = $this->save_variation_image( $image_data, $image_id, $index );
				if ( ! is_wp_error( $saved ) ) {
					$saved_images[] = $saved;
				}
			}
		}

		if ( empty( $saved_images ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to save variation images.', 'wp-mcp-ai' ),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'images'         => $saved_images,
				'count'          => count( $saved_images ),
				'original_image' => $image_id,
			),
		);
	}

	/**
	 * Save a variation image as a WordPress attachment.
	 *
	 * @param array $image_data Image data from OpenAI API.
	 * @param int   $original_id Original image attachment ID.
	 * @param int   $index Image index.
	 * @return array|WP_Error
	 */
	private function save_variation_image( $image_data, $original_id, $index = 0 ) {
		// Get image content.
		$image_content = '';
		if ( isset( $image_data['b64_json'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$image_content = base64_decode( $image_data['b64_json'] );
		} elseif ( isset( $image_data['url'] ) ) {
			$response = wp_remote_get( $image_data['url'], array( 'timeout' => 30 ) );
			if ( ! is_wp_error( $response ) ) {
				$image_content = wp_remote_retrieve_body( $response );
			}
		}

		if ( empty( $image_content ) ) {
			return new WP_Error( 'no_image_content', __( 'No image content received.', 'wp-mcp-ai' ) );
		}

		// Generate filename.
		$original_file = get_attached_file( $original_id );
		$original_name = basename( $original_file, '.' . pathinfo( $original_file, PATHINFO_EXTENSION ) );
		$filename      = $original_name . '-variation-' . time();
		if ( $index > 0 ) {
			$filename .= '-' . $index;
		}
		$filename .= '.png';

		// Upload to WordPress.
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $file_path, $image_content ) ) {
			return new WP_Error( 'save_failed', __( 'Failed to save image file.', 'wp-mcp-ai' ) );
		}

		// Get original image title for variations.
		$original_title  = get_the_title( $original_id );
		$variation_title = $original_title ? sprintf( __( '%s - Variation', 'wp-mcp-ai' ), $original_title ) : __( 'Image Variation', 'wp-mcp-ai' );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/png',
			'post_title'     => sanitize_text_field( $variation_title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Add relationship to original image.
		update_post_meta( $attachment_id, '_wp_mcp_ai_variation_of', $original_id );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'file'          => basename( $file_path ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'requires-capability',
			'modifies-state',
		);
	}
}
