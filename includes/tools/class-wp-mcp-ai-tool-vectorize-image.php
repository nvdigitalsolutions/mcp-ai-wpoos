<?php
/**
 * Tool for vectorizing raster images to SVG format.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-image-base.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';

/**
 * Convert raster images to SVG vector format using @neplex/vectorizer.
 */
class WP_MCP_AI_Tool_Vectorize_Image extends WP_MCP_AI_Tool_Image_Base implements WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vectorize_image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vectorize Image', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert a raster image (PNG, JPEG, WebP, GIF) to SVG vector format with configurable quality settings.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->get_source_parameters_schema(),
				array(
					'color_mode'      => array(
						'type'        => 'string',
						'description' => __( 'Color mode for vectorization.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'color', 'binary' ),
						'default'     => 'color',
					),
					'color_precision' => array(
						'type'        => 'integer',
						'description' => __( 'Color quantization precision (1-8). Higher values preserve more colors but create larger files.', 'mcp-ai-wpoos' ),
						'minimum'     => 1,
						'maximum'     => 8,
						'default'     => 6,
					),
					'filter_speckle'  => array(
						'type'        => 'integer',
						'description' => __( 'Filter out speckles of this size in pixels. Higher values remove more noise.', 'mcp-ai-wpoos' ),
						'minimum'     => 0,
						'maximum'     => 100,
						'default'     => 4,
					),
					'mode'            => array(
						'type'        => 'string',
						'description' => __( 'Path simplification mode. Spline creates smooth curves, polygon creates straight lines.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'spline', 'polygon', 'none' ),
						'default'     => 'spline',
					),
					'hierarchical'    => array(
						'type'        => 'string',
						'description' => __( 'Layer stacking mode. Stacked layers overlap, cutout layers have holes.', 'mcp-ai-wpoos' ),
						'enum'        => array( 'stacked', 'cutout' ),
						'default'     => 'stacked',
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
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

			'profession_tags'       => array( 'graphic_designer' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires upload_files capability.
			'write',                // Creates new media files.
			'local-only',           // Works locally without external APIs.
			'idempotent',           // Can be called multiple times safely with same result.
			'performance-impact',   // Large images may temporarily affect performance.
			'requires-nodejs',      // Requires Node.js to be installed.
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
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to vectorize images.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id && ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to edit images.', 'mcp-ai-wpoos' ) );
		}

		if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check if vectorizer library is installed.
		if ( ! WP_MCP_AI_Optional_Components::is_vectorizer_installed() ) {
			return new WP_Error(
				'wp_mcp_ai_vectorizer_not_installed',
				__( 'Vectorizer library is not installed. It will be automatically downloaded in the background. Please try again in a few minutes, or contact your administrator to manually install it.', 'mcp-ai-wpoos' )
			);
		}

		// Check if Node.js is available.
		if ( ! $this->is_nodejs_available() ) {
			return new WP_Error(
				'wp_mcp_ai_nodejs_required',
				__( 'Node.js is required for image vectorization but was not found on the system.', 'mcp-ai-wpoos' )
			);
		}

		// Enrich arguments with metadata from context messages if available.
		$arguments = $this->enrich_arguments_from_messages( $arguments, $context );

		// Load source image.
		$image_editor = $this->load_source_image( $arguments, $user_id );
		if ( is_wp_error( $image_editor ) ) {
			return $image_editor;
		}

		// Save the image to a temporary file for processing.
		$temp_input = $this->save_to_temp_file( $image_editor );
		if ( is_wp_error( $temp_input ) ) {
			return $temp_input;
		}

		// Prepare output file.
		$temp_output = wp_tempnam( 'vectorized-' );
		if ( ! $temp_output ) {
			$this->cleanup_temp_file( $temp_input );
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary output file.', 'mcp-ai-wpoos' ) );
		}

		// Add .svg extension to output file.
		$temp_output_svg = $temp_output . '.svg';
		rename( $temp_output, $temp_output_svg );
		$temp_output = $temp_output_svg;

		// Prepare vectorization options.
		$options = array(
			'colorMode'      => isset( $arguments['color_mode'] ) ? sanitize_text_field( $arguments['color_mode'] ) : 'color',
			'colorPrecision' => isset( $arguments['color_precision'] ) ? absint( $arguments['color_precision'] ) : 6,
			'filterSpeckle'  => isset( $arguments['filter_speckle'] ) ? absint( $arguments['filter_speckle'] ) : 4,
			'mode'           => isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'spline',
			'hierarchical'   => isset( $arguments['hierarchical'] ) ? sanitize_text_field( $arguments['hierarchical'] ) : 'stacked',
		);

		// Execute vectorization script.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
		$script_args = array(
			$temp_input,
			$temp_output,
			wp_json_encode( $options ),
		);

		$result = $this->execute_nodejs_script(
			$script_path,
			$script_args,
			array(
				'timeout'    => 60,
				'parse_json' => true,
			)
		);

		// Cleanup temporary input file.
		$this->cleanup_temp_file( $temp_input );

		if ( is_wp_error( $result ) ) {
			$this->cleanup_temp_file( $temp_output );
			return $result;
		}

		if ( ! isset( $result['success'] ) || ! $result['success'] ) {
			$this->cleanup_temp_file( $temp_output );
			return new WP_Error(
				'wp_mcp_ai_vectorization_failed',
				isset( $result['error'] ) ? $result['error'] : __( 'Vectorization failed.', 'mcp-ai-wpoos' )
			);
		}

		// Read SVG file.
		$svg_data = file_get_contents( $temp_output );
		if ( false === $svg_data || '' === $svg_data ) {
			$this->cleanup_temp_file( $temp_output );
			return new WP_Error( 'wp_mcp_ai_read_error', __( 'Failed to read vectorized SVG file.', 'mcp-ai-wpoos' ) );
		}

		// Cleanup temporary output file.
		$this->cleanup_temp_file( $temp_output );

		// Save as WordPress attachment.
		$storage = $this->save_svg_as_attachment( $svg_data, $arguments, $user_id );
		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Build response.
		$message = sprintf(
			/* translators: 1: attachment ID, 2: file name */
			__( 'Successfully vectorized image to SVG format. Attachment ID: %1$d, File: %2$s', 'mcp-ai-wpoos' ),
			$storage['attachment_id'],
			$storage['file_name']
		);

		$response = array(
			'attachment_id' => $storage['attachment_id'],
			'url'           => $storage['url'],
			'file_name'     => $storage['file_name'],
			'mime_type'     => 'image/svg+xml',
			'bytes'         => $storage['bytes'],
			'title'         => $storage['title'],
			'source_format' => $image_editor->get_mime_type(),
			'source_size'   => isset( $result['input_size'] ) ? $result['input_size'] : 0,
			'svg_size'      => isset( $result['output_size'] ) ? $result['output_size'] : $storage['bytes'],
			'size_ratio'    => isset( $result['size_ratio'] ) ? $result['size_ratio'] : '0',
			'duration_ms'   => isset( $result['duration_ms'] ) ? $result['duration_ms'] : 0,
			'options'       => $options,
			'text'          => $message,
			'message'       => $message,
		);

		// Add image_url structure for agentic workflow and chat client display.
		// This allows the chat client to display the vectorized SVG and enables
		// vision models to "see" the generated image in subsequent iterations.
		if ( ! empty( $storage['url'] ) ) {
			$response['image_url'] = array(
				'url' => $storage['url'],
			);
		}

		return $response;
	}

	/**
	 * Save image editor content to a temporary file.
	 *
	 * @param WP_Image_Editor $image_editor Image editor instance.
	 * @return string|WP_Error Temporary file path or WP_Error on failure.
	 */
	protected function save_to_temp_file( $image_editor ) {
		$temp_file = wp_tempnam( 'vectorize-input-' );
		if ( ! $temp_file ) {
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to create temporary file.', 'mcp-ai-wpoos' ) );
		}

		$result = $image_editor->save( $temp_file );
		if ( is_wp_error( $result ) ) {
			$this->cleanup_temp_file( $temp_file );
			return $result;
		}

		// WordPress's image editor may append an extension to the filename.
		// Use the actual saved path from the result array.
		$saved_path = isset( $result['path'] ) ? $result['path'] : $temp_file;

		// Verify the file was actually saved.
		if ( ! file_exists( $saved_path ) ) {
			$this->cleanup_temp_file( $temp_file );
			return new WP_Error( 'wp_mcp_ai_temp_file_error', __( 'Failed to save temporary file.', 'mcp-ai-wpoos' ) );
		}

		return $saved_path;
	}

	/**
	 * Save SVG data as WordPress attachment.
	 *
	 * @param string $svg_data  SVG file content.
	 * @param array  $arguments Original tool arguments.
	 * @param int    $user_id   User ID.
	 * @return array|WP_Error Attachment data or WP_Error on failure.
	 */
	protected function save_svg_as_attachment( $svg_data, array $arguments, $user_id ) {
		// Generate file name.
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'vectorized-image';
		if ( empty( $base_name ) ) {
			$base_name = 'vectorized-image';
		}

		// Remove extension if present.
		$base_name = preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-' . gmdate( 'Ymd-His' ) . '.svg';

		// Upload SVG file.
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to save SVG file.', 'mcp-ai-wpoos' ), array( 'error' => $upload['error'] ) );
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_upload_failed', __( 'Failed to write SVG file to disk.', 'mcp-ai-wpoos' ) );
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sanitize_text_field( __( 'Vectorized Image', 'mcp-ai-wpoos' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return new WP_Error( 'wp_mcp_ai_attachment_error', __( 'Failed to register SVG as an attachment.', 'mcp-ai-wpoos' ), array( 'error' => $attachment_id ) );
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get attachment URL and ensure it's valid.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( false === $attachment_url ) {
			WP_MCP_AI_Logger::log_error(
				'vectorize_attachment_url_failed',
				'Failed to generate URL for vectorized SVG attachment',
				array(
					'attachment_id' => $attachment_id,
					'file_path'     => $file_path,
				)
			);
			// Use a fallback URL if possible, otherwise return an error.
			$upload_dir     = wp_upload_dir();
			$attachment_url = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $attachment_url,
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => get_the_title( $attachment_id ),
		);
	}

	/**
	 * Cleanup temporary file.
	 *
	 * @param string $file_path Temporary file path.
	 */
	protected function cleanup_temp_file( $file_path ) {
		if ( ! empty( $file_path ) && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	/**
	 * Sanitize vectorized image result for LLM consumption.
	 *
	 * SVG vectorization returns metadata about the conversion process that's useful
	 * for the chat client but not needed by the LLM. This method strips unnecessary
	 * data while preserving essential information and adds the image_url structure
	 * for agentic loops with vision models.
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with only metadata and image_url for vision.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'attachment_id',
			'url',
			'file_name',
			'mime_type',
			'bytes',
			'title',
			'source_format',
			'source_size',
			'svg_size',
			'size_ratio',
			'text',  // Descriptive message about the vectorization.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url structure for the agentic loop.
		// This allows vision models to "see" the vectorized SVG in subsequent iterations.
		if ( isset( $result['url'] ) && '' !== $result['url'] ) {
			$sanitized['image_url'] = array(
				'url' => $result['url'],
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}
}
