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
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-svg-vectorizer.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Provides a tool for creating image variations via OpenAI's DALL-E API.
 */
class WP_MCP_AI_Tool_Create_Image_Variation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;
	use WP_MCP_AI_SVG_Vectorizer;
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Image_Response;

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
		return __( 'Create Image Variation', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates variations of an existing image using OpenAI\'s DALL-E API. Useful for generating alternative versions of an image.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'image_id'        => array(
						'type'        => 'integer',
						'description' => __( 'WordPress attachment ID of the source image.', 'mcp-ai-wpoos' ),
					),
					'model'           => array(
						'type'        => 'string',
						'description' => __( 'OpenAI model to use for generating variations.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'dall-e-2' ),
						'default'     => 'dall-e-2',
					),
					'n'               => array(
						'type'        => 'integer',
						'description' => __( 'Number of variations to generate.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 10,
						'default'     => 1,
					),
					'size'            => array(
						'type'        => 'string',
						'description' => __( 'Size of the variation images.', 'mcp-ai-wpoos' ),
						'enum'        => array( '256x256', '512x512', '1024x1024' ),
						'default'     => '1024x1024',
					),
					'response_format' => array(
						'type'        => 'string',
						'description' => __( 'Format for the response.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'url', 'b64_json' ),
						'default'     => 'b64_json',
					),
				),
				$this->get_output_format_parameter_schema()
			),
			'required'   => array( 'image_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments The tool arguments.
	 * @param array $context   The tool context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate image_id.
		if ( empty( $arguments['image_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The image_id parameter is required.', 'mcp-ai-wpoos' ),
			);
		}

		$image_id = absint( $arguments['image_id'] );
		if ( ! wp_attachment_is_image( $image_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The specified image_id is not a valid image attachment.', 'mcp-ai-wpoos' ),
			);
		}

		// Get image file path.
		$image_path = get_attached_file( $image_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The image file could not be found.', 'mcp-ai-wpoos' ),
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

		// Check if SVG output is requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'default';

		// Process and save variation images.
		$saved_images = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $index => $image_data ) {
				$saved = $this->save_variation_image( $image_data, $image_id, $index );
				if ( ! is_wp_error( $saved ) ) {
					// Convert to SVG if requested.
					if ( 'svg' === $output_format ) {
						$svg_saved = $this->convert_to_svg( $saved, $arguments );
						if ( ! is_wp_error( $svg_saved ) ) {
							$saved = $svg_saved;
						} else {
							// Log error but keep raster version.
							WP_MCP_AI_Logger::log_error(
								'variation_svg_conversion_failed',
								'Failed to convert image variation to SVG',
								array(
									'error'         => $svg_saved->get_error_message(),
									'attachment_id' => $saved['attachment_id'],
								)
							);
						}
					}
					$saved_images[] = $saved;
				}
			}
		}

		if ( empty( $saved_images ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to save variation images.', 'mcp-ai-wpoos' ),
			);
		}

		// Build descriptive text message.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: count of variations, 2: original image ID */
			__( 'Successfully created %1$d variation(s) from image ID: %2$d.', 'mcp-ai-wpoos' ),
			count( $saved_images ),
			$image_id
		);

		if ( 'svg' === $output_format ) {
			$text_parts[] = __( 'Variations converted to SVG format.', 'mcp-ai-wpoos' );
		}

		$text = implode( ' ', $text_parts );

		$result = array(
			'success' => true,
			'data'    => array(
				'images'         => $saved_images,
				'count'          => count( $saved_images ),
				'original_image' => $image_id,
				'output_format'  => $output_format,
				'text'           => $text,
				'message'        => $text,
			),
		);

		// Add rendered image HTML for each variation.
		$result = $this->add_multiple_images_html_to_response( $result );

		return $result;
	}

	/**
	 * Save a variation image as a WordPress attachment.
	 *
	 * @param array $image_data Image data from OpenAI API.
	 * @param int   $original_id Original image attachment ID.
	 * @param int   $index Image index.
	 * @return array|WP_Error Array with file, attachment_id, url, file_name, bytes, mime_type.
	 */
	private function save_variation_image( $image_data, $original_id, $index = 0 ) {
		// Get image content.
		$image_content = '';
		if ( isset( $image_data['b64_json'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- base64_decode used to decode binary image/file data received from the API, not for code obfuscation.
			$image_content = base64_decode( $image_data['b64_json'] );
		} elseif ( isset( $image_data['url'] ) ) {
			$response = wp_remote_get( $image_data['url'], array( 'timeout' => 30 ) );
			if ( ! is_wp_error( $response ) ) {
				$image_content = wp_remote_retrieve_body( $response );
			}
		}

		if ( empty( $image_content ) ) {
			return new WP_Error( 'no_image_content', __( 'No image content received.', 'mcp-ai-wpoos' ) );
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

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing to WordPress uploads directory (wp_upload_dir() path); never to plugin directory. WP_Filesystem is not available in this REST/cron/tool execution context.
		if ( false === file_put_contents( $file_path, $image_content ) ) {
			return new WP_Error( 'save_failed', __( 'Failed to save image file.', 'mcp-ai-wpoos' ) );
		}

		// Get original image title for variations.
		$original_title = get_the_title( $original_id );
		/* translators: %s: Original image title */
		$variation_title = $original_title ? sprintf( __( '%s - Variation', 'mcp-ai-wpoos' ), $original_title ) : __( 'Image Variation', 'mcp-ai-wpoos' );

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
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Add relationship to original image.
		update_post_meta( $attachment_id, '_wp_mcp_ai_variation_of', $original_id );

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
			'file'          => $file_path,
			'file_name'     => basename( $file_path ),
			'bytes'         => $bytes,
			'mime_type'     => 'image/png',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
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

			'profession_tags'       => array( 'graphic_designer', 'photographer' ),

			'risk_level'            => 'standard',

		);
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
