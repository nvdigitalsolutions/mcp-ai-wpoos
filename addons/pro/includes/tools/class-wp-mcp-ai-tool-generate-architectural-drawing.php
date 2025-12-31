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

/**
 * Generates AI-enhanced architectural drawings and vector graphics.
 */
class WP_MCP_AI_Tool_Generate_Architectural_Drawing implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {

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
		if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
			$svg_result = $this->convert_to_svg( $image_result['image_data'], $drawing_type );
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
			$response['svg_data'] = $svg_result;
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
	 * Convert raster image to SVG (placeholder - requires additional processing).
	 *
	 * @param string $base64_data Base64 encoded image data.
	 * @param string $drawing_type Drawing type.
	 * @return string|WP_Error SVG data or error.
	 */
	protected function convert_to_svg( $base64_data, $drawing_type ) {
		// Note: True raster-to-vector conversion requires external services or libraries.
		// This is a placeholder that returns an error for now.
		return new WP_Error(
			'svg_conversion_not_available',
			__( 'SVG conversion requires additional processing libraries. PNG output is available.', 'wp-mcp-ai' )
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
