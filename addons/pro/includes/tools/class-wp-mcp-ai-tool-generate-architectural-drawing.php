<?php
/**
 * AI-Enhanced Tool for generating advanced architectural vector drawings.
 *
 * This Pro tool uses AI to generate architectural drawings including floor plans,
 * elevations, sections, and construction details. Supports both raster and SVG output.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-tool-svg-vectorizer.php';

/**
 * Generates AI-enhanced architectural drawings and vector graphics.
 */
class WP_MCP_AI_Tool_Generate_Architectural_Drawing implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {
	use WP_MCP_AI_Tool_SVG_Vectorizer;

	const DEFAULT_MODEL    = 'gpt-image-1.5';
	const DEFAULT_SIZE     = '1792x1024'; // Wide format suitable for architectural drawings.
	const DEFAULT_QUALITY  = 'high';
	const DEFAULT_STYLE    = 'natural'; // More suitable for technical drawings.

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_architectural_drawing';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Architectural Drawing', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-enhanced tool for generating advanced architectural vector drawings including floor plans, elevations, sections, construction details, and technical diagrams. Supports multiple output formats including SVG for scalable vector graphics.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'drawing_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of architectural drawing to generate', 'wp-mcp-ai' ),
					'enum'        => array(
						'floor_plan',
						'site_plan',
						'elevation',
						'section',
						'detail',
						'reflected_ceiling_plan',
						'roof_plan',
						'3d_axonometric',
						'isometric',
						'construction_detail',
					),
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of the architectural drawing to generate. Include dimensions, materials, scale, and specific requirements.', 'wp-mcp-ai' ),
				),
				'building_type'       => array(
					'type'        => 'string',
					'description' => __( 'Type of building or structure (e.g., residential, commercial, mixed-use, industrial)', 'wp-mcp-ai' ),
					'enum'        => array( 'residential', 'commercial', 'industrial', 'institutional', 'mixed-use', 'retail', 'healthcare', 'educational' ),
				),
				'style'               => array(
					'type'        => 'string',
					'description' => __( 'Drawing style and presentation approach', 'wp-mcp-ai' ),
					'enum'        => array(
						'technical',      // Clean technical drawing style.
						'sketched',       // Hand-drawn sketch style.
						'rendered',       // Fully rendered with materials.
						'line_drawing',   // Pure line work, no fills.
						'annotated',      // With dimensions and notes.
						'schematic',      // Simplified schematic style.
					),
					'default'     => 'technical',
				),
				'scale'               => array(
					'type'        => 'string',
					'description' => __( 'Architectural scale for the drawing (e.g., 1/4"=1\'-0", 1:100)', 'wp-mcp-ai' ),
				),
				'dimensions'          => array(
					'type'        => 'object',
					'description' => __( 'Building or element dimensions', 'wp-mcp-ai' ),
					'properties'  => array(
						'width'  => array(
							'type'        => 'string',
							'description' => __( 'Width dimension (e.g., "50 feet", "15 meters")', 'wp-mcp-ai' ),
						),
						'length' => array(
							'type'        => 'string',
							'description' => __( 'Length dimension', 'wp-mcp-ai' ),
						),
						'height' => array(
							'type'        => 'string',
							'description' => __( 'Height dimension', 'wp-mcp-ai' ),
						),
					),
				),
				'materials'           => array(
					'type'        => 'array',
					'description' => __( 'Materials to be shown in the drawing', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'code_requirements'   => array(
					'type'        => 'string',
					'description' => __( 'Building code to follow (IBC, IRC, local codes)', 'wp-mcp-ai' ),
					'enum'        => array( 'IBC', 'IRC', 'NBC', 'Eurocode', 'local' ),
				),
				'annotations'         => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include dimensions, notes, and annotations', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'output_format'       => array(
					'type'        => 'string',
					'description' => __( 'Output format for the drawing', 'wp-mcp-ai' ),
					'enum'        => array( 'png', 'svg', 'both' ),
					'default'     => 'png',
				),
				'image_size'          => array(
					'type'        => 'string',
					'description' => __( 'Size of the generated image for raster formats', 'wp-mcp-ai' ),
					'enum'        => array( '1024x1024', '1792x1024', '1024x1792' ),
					'default'     => '1792x1024',
				),
				'quality'             => array(
					'type'        => 'string',
					'description' => __( 'Image quality setting', 'wp-mcp-ai' ),
					'enum'        => array( 'standard', 'high', 'ultra' ),
					'default'     => 'high',
				),
				'model'               => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for generation', 'wp-mcp-ai' ),
					'default'     => self::DEFAULT_MODEL,
				),
				'save_as_attachment'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the drawing as a media attachment', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'file_name'           => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved attachment', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'drawing_type', 'description' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'generate_architectural_drawing', 'wp-mcp-ai' ),
				'payload' => __( 'generate_architectural_drawing', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Create floor plan', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_architectural_drawing` tool to create a floor plan. Ask for building type, dimensions, room layout, and any specific requirements.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Create building elevation', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_architectural_drawing` tool to create a building elevation. Ask for building type, height, materials, and architectural style.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Create construction detail', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `generate_architectural_drawing` tool to create a construction detail. Ask for the specific detail type, materials, connections, and scale.', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate architectural drawings.', 'wp-mcp-ai' )
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['drawing_type'] ) || empty( $arguments['description'] ) ) {
			return new WP_Error(
				'missing_parameters',
				__( 'Both drawing_type and description are required.', 'wp-mcp-ai' )
			);
		}

		// Sanitize inputs.
		$drawing_type   = sanitize_text_field( $arguments['drawing_type'] );
		$description    = sanitize_textarea_field( $arguments['description'] );
		$building_type  = isset( $arguments['building_type'] ) ? sanitize_text_field( $arguments['building_type'] ) : '';
		$style          = isset( $arguments['style'] ) ? sanitize_text_field( $arguments['style'] ) : 'technical';
		$scale          = isset( $arguments['scale'] ) ? sanitize_text_field( $arguments['scale'] ) : '';
		$output_format  = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'png';
		$image_size     = isset( $arguments['image_size'] ) ? sanitize_text_field( $arguments['image_size'] ) : self::DEFAULT_SIZE;
		$quality        = isset( $arguments['quality'] ) ? sanitize_text_field( $arguments['quality'] ) : self::DEFAULT_QUALITY;
		$model          = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL;
		$save_as_attachment = isset( $arguments['save_as_attachment'] ) ? (bool) $arguments['save_as_attachment'] : true;
		$file_name      = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : '';

		// Build the AI prompt for generating the architectural drawing.
		$ai_prompt = $this->build_architectural_prompt( $arguments );

		if ( is_wp_error( $ai_prompt ) ) {
			return $ai_prompt;
		}

		// Generate the image using OpenAI or Gemini.
		$image_result = $this->generate_drawing_image( $ai_prompt, $model, $image_size, $quality, $style );

		if ( is_wp_error( $image_result ) ) {
			return $image_result;
		}

		// Save as attachment if requested.
		$attachment_id = null;
		if ( $save_as_attachment ) {
			$attachment_id = $this->save_as_media_attachment(
				$image_result['image_data'],
				$image_result['mime_type'],
				$file_name,
				$drawing_type,
				$user_id
			);

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		// Optionally convert to SVG if requested.
		$svg_result = null;
		$svg_attachment_id = null;
		if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
			$svg_result = $this->convert_to_svg( $image_result['image_data'], $drawing_type );
			
			if ( ! is_wp_error( $svg_result ) && $save_as_attachment ) {
				// Save SVG as attachment.
				$svg_file_name = $file_name ? $file_name . '-vector' : '';
				$svg_attachment_id = $this->save_svg_as_attachment(
					$svg_result['svg_data'],
					$svg_file_name,
					$drawing_type,
					$user_id
				);
			}
		}

		// Prepare the response.
		$response = array(
			'summary'       => sprintf(
				/* translators: %s: drawing type */
				__( 'Generated %s architectural drawing', 'wp-mcp-ai' ),
				$drawing_type
			),
			'success'       => true,
			'drawing_type'  => $drawing_type,
			'building_type' => $building_type,
			'style'         => $style,
			'scale'         => $scale,
			'output_format' => $output_format,
			'prompt_used'   => $ai_prompt,
		);

		if ( $attachment_id ) {
			$response['attachment_id']  = $attachment_id;
			$response['attachment_url'] = wp_get_attachment_url( $attachment_id );
		}

		if ( $svg_result && ! is_wp_error( $svg_result ) ) {
			$response['svg_generated'] = true;
			$response['svg_size'] = $svg_result['svg_size'];
			
			if ( $svg_attachment_id && ! is_wp_error( $svg_attachment_id ) ) {
				$response['svg_attachment_id']  = $svg_attachment_id;
				$response['svg_attachment_url'] = wp_get_attachment_url( $svg_attachment_id );
			}
			
			// Include SVG data if not saved as attachment.
			if ( ! $svg_attachment_id ) {
				$response['svg_data'] = $svg_result['svg_data'];
			}
		} elseif ( is_wp_error( $svg_result ) ) {
			$response['svg_error'] = $svg_result->get_error_message();
		}

		return $response;
	}

	/**
	 * Build the AI prompt for architectural drawing generation.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string|WP_Error The prompt or error.
	 */
	protected function build_architectural_prompt( $arguments ) {
		$drawing_type = $arguments['drawing_type'];
		$description  = $arguments['description'];
		$style        = isset( $arguments['style'] ) ? $arguments['style'] : 'technical';

		// Base prompt.
		$prompt_parts = array();

		// Add drawing type specific guidance.
		switch ( $drawing_type ) {
			case 'floor_plan':
				$prompt_parts[] = 'Create a professional architectural floor plan drawing.';
				$prompt_parts[] = 'Show walls, doors, windows, and room layouts.';
				$prompt_parts[] = 'Use standard architectural symbols and conventions.';
				break;

			case 'elevation':
				$prompt_parts[] = 'Create a professional building elevation drawing.';
				$prompt_parts[] = 'Show exterior facade with materials, windows, and architectural features.';
				break;

			case 'section':
				$prompt_parts[] = 'Create a professional building section drawing.';
				$prompt_parts[] = 'Show floor-to-floor heights, roof structure, and interior spaces.';
				break;

			case 'detail':
			case 'construction_detail':
				$prompt_parts[] = 'Create a detailed construction detail drawing.';
				$prompt_parts[] = 'Show precise connections, materials, and assembly methods.';
				break;

			case 'site_plan':
				$prompt_parts[] = 'Create a professional site plan drawing.';
				$prompt_parts[] = 'Show building location, property lines, parking, and landscaping.';
				break;

			case 'reflected_ceiling_plan':
				$prompt_parts[] = 'Create a reflected ceiling plan (RCP).';
				$prompt_parts[] = 'Show ceiling grid, light fixtures, and MEP elements.';
				break;

			case 'roof_plan':
				$prompt_parts[] = 'Create a professional roof plan drawing.';
				$prompt_parts[] = 'Show roof geometry, slopes, drainage, and equipment.';
				break;

			case '3d_axonometric':
			case 'isometric':
				$prompt_parts[] = 'Create a 3D axonometric/isometric architectural drawing.';
				$prompt_parts[] = 'Show spatial relationships and three-dimensional form.';
				break;
		}

		// Add style guidance.
		switch ( $style ) {
			case 'technical':
				$prompt_parts[] = 'Use clean, precise technical drawing style with clear lines.';
				$prompt_parts[] = 'Black lines on white background, professional CAD style.';
				break;

			case 'sketched':
				$prompt_parts[] = 'Use hand-drawn architectural sketch style.';
				$prompt_parts[] = 'Loose, expressive linework while maintaining technical accuracy.';
				break;

			case 'rendered':
				$prompt_parts[] = 'Include material textures, shadows, and rendering.';
				$prompt_parts[] = 'Show realistic representation of materials and finishes.';
				break;

			case 'line_drawing':
				$prompt_parts[] = 'Pure line work drawing, no fills or hatching.';
				$prompt_parts[] = 'Clean linework only, emphasizing form and structure.';
				break;

			case 'annotated':
				$prompt_parts[] = 'Include dimensions, notes, and construction annotations.';
				$prompt_parts[] = 'Show key measurements and material callouts.';
				break;

			case 'schematic':
				$prompt_parts[] = 'Simplified schematic style, focus on spatial relationships.';
				$prompt_parts[] = 'Clear diagram showing essential elements only.';
				break;
		}

		// Add user description.
		$prompt_parts[] = 'Specific requirements: ' . $description;

		// Add building type if specified.
		if ( ! empty( $arguments['building_type'] ) ) {
			$prompt_parts[] = 'Building type: ' . $arguments['building_type'] . '.';
		}

		// Add dimensions if specified.
		if ( ! empty( $arguments['dimensions'] ) ) {
			$dims = $arguments['dimensions'];
			if ( ! empty( $dims['width'] ) || ! empty( $dims['length'] ) || ! empty( $dims['height'] ) ) {
				$dim_text = 'Dimensions: ';
				if ( ! empty( $dims['width'] ) ) {
					$dim_text .= 'Width: ' . sanitize_text_field( $dims['width'] ) . ', ';
				}
				if ( ! empty( $dims['length'] ) ) {
					$dim_text .= 'Length: ' . sanitize_text_field( $dims['length'] ) . ', ';
				}
				if ( ! empty( $dims['height'] ) ) {
					$dim_text .= 'Height: ' . sanitize_text_field( $dims['height'] );
				}
				$prompt_parts[] = rtrim( $dim_text, ', ' ) . '.';
			}
		}

		// Add scale if specified.
		if ( ! empty( $arguments['scale'] ) ) {
			$prompt_parts[] = 'Scale: ' . sanitize_text_field( $arguments['scale'] ) . '.';
		}

		// Add materials if specified.
		if ( ! empty( $arguments['materials'] ) && is_array( $arguments['materials'] ) ) {
			$materials = array_map( 'sanitize_text_field', $arguments['materials'] );
			$prompt_parts[] = 'Materials: ' . implode( ', ', $materials ) . '.';
		}

		// Add code requirements if specified.
		if ( ! empty( $arguments['code_requirements'] ) ) {
			$prompt_parts[] = 'Follow ' . sanitize_text_field( $arguments['code_requirements'] ) . ' building code standards.';
		}

		// Add annotations instruction.
		if ( ! empty( $arguments['annotations'] ) ) {
			$prompt_parts[] = 'Include dimension lines, labels, and technical annotations.';
		}

		return implode( ' ', $prompt_parts );
	}

	/**
	 * Generate the drawing image using AI.
	 *
	 * @param string $prompt The AI prompt.
	 * @param string $model  The model to use.
	 * @param string $size   The image size.
	 * @param string $quality The quality setting.
	 * @param string $style  The style setting.
	 * @return array|WP_Error Array with image_data and mime_type, or error.
	 */
	protected function generate_drawing_image( $prompt, $model, $size, $quality, $style ) {
		// Load OpenAI client.
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
		}

		$client = new WP_MCP_AI_OpenAI_Client();

		// Prepare generation options.
		$options = array(
			'size'            => $size,
			'quality'         => $quality === 'ultra' ? 'high' : $quality,
			'response_format' => 'b64_json',
			'model'           => $model,
		);

		// Add style for DALL-E 3 models.
		if ( strpos( $model, 'dall-e-3' ) !== false ) {
			$options['style'] = $style === 'technical' ? 'natural' : $style;
		}

		// Generate the image.
		$result = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['b64_json'] ) ) {
			return new WP_Error(
				'image_generation_failed',
				__( 'Failed to generate architectural drawing image.', 'wp-mcp-ai' )
			);
		}

		return array(
			'image_data' => $result['b64_json'],
			'mime_type'  => 'image/png',
		);
	}

	/**
	 * Save image as media attachment.
	 *
	 * @param string $base64_data Base64 encoded image data.
	 * @param string $mime_type   MIME type.
	 * @param string $file_name   Optional file name.
	 * @param string $drawing_type Drawing type for title.
	 * @param int    $user_id     User ID.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function save_as_media_attachment( $base64_data, $mime_type, $file_name, $drawing_type, $user_id ) {
		$image_data = base64_decode( $base64_data );

		if ( false === $image_data ) {
			return new WP_Error(
				'invalid_image_data',
				__( 'Failed to decode image data.', 'wp-mcp-ai' )
			);
		}

		// Generate file name.
		if ( empty( $file_name ) ) {
			$file_name = 'architectural-' . $drawing_type . '-' . time();
		}

		$file_name = sanitize_file_name( $file_name );

		// Determine extension from MIME type.
		$extension = 'png';
		if ( 'image/jpeg' === $mime_type ) {
			$extension = 'jpg';
		} elseif ( 'image/svg+xml' === $mime_type ) {
			$extension = 'svg';
		}

		$file_name .= '.' . $extension;

		// Upload to WordPress.
		$upload = wp_upload_bits( $file_name, null, $image_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'upload_failed',
				$upload['error']
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title'     => sprintf(
				/* translators: %s: drawing type */
				__( 'Architectural Drawing - %s', 'wp-mcp-ai' ),
				ucwords( str_replace( '_', ' ', $drawing_type ) )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * Save SVG as media attachment.
	 *
	 * @param string $svg_data    SVG content.
	 * @param string $file_name   Optional file name.
	 * @param string $drawing_type Drawing type for title.
	 * @param int    $user_id     User ID.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function save_svg_as_attachment( $svg_data, $file_name, $drawing_type, $user_id ) {
		// Generate file name.
		if ( empty( $file_name ) ) {
			$file_name = 'architectural-' . $drawing_type . '-vector-' . time();
		}

		$file_name = sanitize_file_name( $file_name ) . '.svg';

		// Upload to WordPress.
		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'svg_upload_failed',
				$upload['error']
			);
		}

		// Create attachment.
		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => sprintf(
				/* translators: %s: drawing type */
				__( 'Architectural Drawing - %s (SVG)', 'wp-mcp-ai' ),
				ucwords( str_replace( '_', ' ', $drawing_type ) )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// SVG files don't need metadata generation like raster images.
		// Just update basic metadata.
		$metadata = array(
			'file'      => $upload['file'],
			'filesize'  => filesize( $upload['file'] ),
		);
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * Convert raster image to SVG using Node.js vectorizer.
	 *
	 * Uses @neplex/vectorizer npm package for AI-powered raster-to-vector conversion.
	 * Optimized for architectural drawings with configurable precision settings.
	 *
	 * @param string $base64_data Base64 encoded image data.
	 * @param string $drawing_type Drawing type.
	 * @return array|WP_Error Array with SVG data and attachment ID, or error.
	 */
	protected function convert_to_svg( $base64_data, $drawing_type ) {
		// Decode base64 image data.
		$image_data = base64_decode( $base64_data );
		if ( false === $image_data ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Failed to decode base64 image data for SVG conversion.', 'wp-mcp-ai' )
			);
		}

		// Check if Node.js is available.
		$node_path = $this->find_node_binary();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		// Check if vectorizer script exists.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize.js';
		if ( ! file_exists( $script_path ) ) {
			return new WP_Error(
				'vectorizer_script_missing',
				__( 'SVG vectorizer script not found. Please run npm install.', 'wp-mcp-ai' )
			);
		}

		// Check if @neplex/vectorizer is installed.
		$node_modules_check = WP_MCP_AI_PATH . 'node_modules/@neplex/vectorizer';
		if ( ! is_dir( $node_modules_check ) ) {
			return new WP_Error(
				'vectorizer_not_installed',
				__( 'SVG vectorizer package not installed. Please run: npm install @neplex/vectorizer', 'wp-mcp-ai' )
			);
		}

		// Create temporary files for input and output.
		$temp_dir    = sys_get_temp_dir();
		$temp_input  = tempnam( $temp_dir, 'arch_draw_' ) . '.png';
		$temp_output = tempnam( $temp_dir, 'arch_draw_svg_' ) . '.svg';

		// Write image data to temp file.
		if ( false === file_put_contents( $temp_input, $image_data ) ) {
			return new WP_Error(
				'temp_file_write_failed',
				__( 'Failed to write temporary image file for vectorization.', 'wp-mcp-ai' )
			);
		}

		// Configure vectorization options based on drawing type.
		$options = $this->get_vectorizer_options( $drawing_type );

		// Build command to run Node.js vectorizer.
		$command = sprintf(
			'%s %s %s %s %s 2>&1',
			escapeshellcmd( $node_path ),
			escapeshellarg( $script_path ),
			escapeshellarg( $temp_input ),
			escapeshellarg( $temp_output ),
			escapeshellarg( wp_json_encode( $options ) )
		);

		// Execute vectorization.
		$output      = array();
		$return_code = 0;
		exec( $command, $output, $return_code );

		// Clean up input file.
		if ( file_exists( $temp_input ) ) {
			unlink( $temp_input );
		}

		// Parse output JSON.
		$output_json = implode( "\n", $output );
		$result      = json_decode( $output_json, true );

		if ( 0 !== $return_code || ! $result || empty( $result['success'] ) ) {
			// Clean up output file if exists.
			if ( file_exists( $temp_output ) ) {
				unlink( $temp_output );
			}

			$error_msg = isset( $result['error'] ) ? $result['error'] : __( 'Vectorization failed with unknown error.', 'wp-mcp-ai' );
			return new WP_Error(
				'vectorization_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'SVG vectorization failed: %s', 'wp-mcp-ai' ),
					$error_msg
				)
			);
		}

		// Read SVG content.
		if ( ! file_exists( $temp_output ) ) {
			return new WP_Error(
				'svg_output_missing',
				__( 'SVG output file was not created.', 'wp-mcp-ai' )
			);
		}

		$svg_content = file_get_contents( $temp_output );
		unlink( $temp_output );

		if ( false === $svg_content || empty( $svg_content ) ) {
			return new WP_Error(
				'svg_read_failed',
				__( 'Failed to read SVG output file.', 'wp-mcp-ai' )
			);
		}

		return array(
			'svg_data' => $svg_content,
			'svg_size' => strlen( $svg_content ),
			'message'  => __( 'Successfully converted to SVG format.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Get vectorizer options based on drawing type.
	 *
	 * @param string $drawing_type Drawing type.
	 * @return array Vectorizer options.
	 */
	protected function get_vectorizer_options( $drawing_type ) {
		// Default options for technical architectural drawings.
		$base_options = array(
			'colorMode'        => 'color',
			'colorPrecision'   => 6,
			'filterSpeckle'    => 4,
			'cornerThreshold'  => 60,
			'lengthThreshold'  => 4.0,
			'maxIterations'    => 10,
			'spliceThreshold'  => 45,
			'pathPrecision'    => 8,
			'mode'             => 'stacked',
		);

		// Adjust options based on drawing type.
		switch ( $drawing_type ) {
			case 'floor_plan':
			case 'site_plan':
			case 'reflected_ceiling_plan':
			case 'roof_plan':
				// Plans need high precision and sharp corners.
				$base_options['cornerThreshold']  = 45;
				$base_options['lengthThreshold']  = 3.0;
				$base_options['pathPrecision']    = 10;
				break;

			case 'elevation':
			case 'section':
				// Elevations and sections need good detail preservation.
				$base_options['colorPrecision']   = 7;
				$base_options['cornerThreshold']  = 50;
				break;

			case 'detail':
			case 'construction_detail':
				// Details need maximum precision.
				$base_options['colorPrecision']   = 8;
				$base_options['cornerThreshold']  = 40;
				$base_options['lengthThreshold']  = 2.0;
				$base_options['pathPrecision']    = 12;
				$base_options['filterSpeckle']    = 2;
				break;

			case '3d_axonometric':
			case 'isometric':
				// 3D views can have smoother curves.
				$base_options['cornerThreshold']  = 70;
				$base_options['lengthThreshold']  = 5.0;
				break;
		}

		/**
		 * Filter vectorizer options.
		 *
		 * @param array  $base_options Default options.
		 * @param string $drawing_type Drawing type.
		 */
		return apply_filters( 'wp_mcp_ai_architectural_drawing_vectorizer_options', $base_options, $drawing_type );
	}

	/**
	 * Find Node.js binary path.
	 *
	 * @return string|WP_Error Node.js path or error.
	 */
	protected function find_node_binary() {
		// Common Node.js binary locations.
		$possible_paths = array(
			'/usr/bin/node',
			'/usr/local/bin/node',
			'/opt/homebrew/bin/node',
		);

		// Check NODE_PATH environment variable.
		$env_node = getenv( 'NODE_PATH' );
		if ( $env_node && file_exists( $env_node ) && is_executable( $env_node ) ) {
			return $env_node;
		}

		// Try 'which node' command.
		$which_output = shell_exec( 'which node 2>/dev/null' );
		if ( $which_output ) {
			$which_path = trim( $which_output );
			if ( file_exists( $which_path ) && is_executable( $which_path ) ) {
				return $which_path;
			}
		}

		// Check common paths.
		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}

		return new WP_Error(
			'node_not_found',
			__( 'Node.js not found. Please install Node.js to enable SVG conversion.', 'wp-mcp-ai' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                      // Pro feature.
			'write',                    // Creates media attachments.
			'external-api',             // Calls OpenAI/Gemini API.
			'requires-api-key',         // Requires OpenAI API key.
			'requires-capability',      // Requires 'upload_files' capability.
			'rate-limited',             // Subject to API rate limits.
			'costs-money',              // Incurs API costs.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'providers' => array( 'openai', 'gemini' ),
			'models'    => array(
				'openai:dall-e-3',
				'openai:gpt-image-1',
				'openai:gpt-image-1.5',
				'gemini:imagen-3.0-generate-001',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_validation_rules() {
		return array(
			'parameter_constraints' => array(
				'required_fields' => array( 'drawing_type', 'description' ),
			),
			'dependencies'          => array(
				'required_settings' => array(
					'openai_api_key' => 'wp_mcp_ai_openai_api_key',
				),
			),
		);
	}
}
