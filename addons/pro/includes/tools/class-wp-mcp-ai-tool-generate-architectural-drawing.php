<?php
/**
 * Tool that generates architectural drawings using OpenAI DALL-E and Gemini Imagen APIs.
 *
 * Extends the Graphic Editor Suite pattern to architectural drafting workflows.
 * Supports 10 drawing types, 6 presentation styles, and professional architectural output.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-nodejs-subprocess.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Provides a Pro tool for generating architectural drawings using AI.
 */
class WP_MCP_AI_Tool_Generate_Architectural_Drawing implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Rules_Interface {
	use WP_MCP_AI_NodeJS_Subprocess;
	use WP_MCP_AI_Tool_Image_Response;

	const DEFAULT_MODEL         = 'gpt-image-1.5';
	const DEFAULT_PROVIDER      = 'openai';
	const DEFAULT_DRAWING_TYPE  = 'floor_plan';
	const DEFAULT_STYLE         = 'technical';
	const DEFAULT_SIZE          = '1024x1536';
	const DEFAULT_QUALITY       = 'high';
	const DEFAULT_SCALE         = '1/4"=1\'-0"';
	const DEFAULT_OUTPUT_FORMAT = 'png';

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
		return __( 'Generate Architectural Drawing', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates professional architectural drawings (floor plans, elevations, sections, details) using OpenAI DALL-E or Gemini Imagen. Supports 10 drawing types, 6 presentation styles, building codes, dimension specifications, and material lists. Output can be PNG (raster) or SVG (vector).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'prompt' => array(
					'type' => 'string',
					'description' => __( 'Architectural requirements or description (e.g., "residential floor plan with 3 bedrooms, open kitchen, 2000 sq ft").', 'mcp-ai-wpoos-pro' ),
				),
				'drawing_type' => array(
					'type' => 'string',
					'description' => __( 'Type of architectural drawing to generate.', 'mcp-ai-wpoos-pro' ),
					'enum' => $this->get_drawing_types(),
					'default' => self::DEFAULT_DRAWING_TYPE,
				),
				'presentation_style' => array(
					'type' => 'string',
					'description' => __( 'Visual presentation style for the drawing.', 'mcp-ai-wpoos-pro' ),
					'enum' => $this->get_presentation_styles(),
					'default' => self::DEFAULT_STYLE,
				),
				'scale' => array(
					'type' => 'string',
					'description' => __( 'Architectural scale notation (e.g., "1/4\"=1\'-0\"", "1:100", "1:50"). Default: 1/4"=1\'-0"', 'mcp-ai-wpoos-pro' ),
					'default' => self::DEFAULT_SCALE,
				),
				'dimensions' => array(
					'type' => 'object',
					'description' => __( 'Dimensional specifications for the space (width, depth, height in feet or meters).', 'mcp-ai-wpoos-pro' ),
					'properties' => array(
						'width' => array(
							'type' => 'number',
							'description' => __( 'Width of the space.', 'mcp-ai-wpoos-pro' ),
							'minimum' => 1,
						),
						'depth' => array(
							'type' => 'number',
							'description' => __( 'Depth of the space.', 'mcp-ai-wpoos-pro' ),
							'minimum' => 1,
						),
						'height' => array(
							'type' => 'number',
							'description' => __( 'Height of the space (for sections/elevations).', 'mcp-ai-wpoos-pro' ),
							'minimum' => 1,
						),
						'unit' => array(
							'type' => 'string',
							'description' => __( 'Unit of measurement.', 'mcp-ai-wpoos-pro' ),
							'enum' => array( 'feet', 'meters', 'inches', 'centimeters' ),
							'default' => 'feet',
						),
					),
				),
				'materials' => array(
					'type' => 'array',
					'description' => __( 'List of materials/finishes to include (e.g., ["wood flooring", "concrete walls", "glass curtain wall"]).', 'mcp-ai-wpoos-pro' ),
					'items' => array(
						'type' => 'string',
					),
				),
				'building_code' => array(
					'type' => 'string',
					'description' => __( 'Building code standard to reference (IBC, IRC, NBC, Eurocode).', 'mcp-ai-wpoos-pro' ),
					'enum' => array( 'ibc', 'irc', 'nbc', 'eurocode', 'none' ),
					'default' => 'none',
				),
				'annotations' => array(
					'type' => 'boolean',
					'description' => __( 'Include dimension annotations and callouts. Default: true for technical/annotated styles.', 'mcp-ai-wpoos-pro' ),
					'default' => true,
				),
				'provider' => array(
					'type' => 'string',
					'description' => __( 'AI provider to use for generation.', 'mcp-ai-wpoos-pro' ),
					'enum' => array( 'openai', 'gemini' ),
					'default' => self::DEFAULT_PROVIDER,
				),
				'model' => array(
					'type' => 'string',
					'description' => __( 'AI model to use. For OpenAI: gpt-image-1.5, gpt-image-1, dall-e-3. For Gemini: gemini-2.5-flash-image.', 'mcp-ai-wpoos-pro' ),
					'default' => self::DEFAULT_MODEL,
				),
				'size' => array(
					'type' => 'string',
					'description' => __( 'Image size. OpenAI: 1024x1024, 1024x1536, 1536x1024. Gemini uses aspect_ratio instead.', 'mcp-ai-wpoos-pro' ),
					'default' => self::DEFAULT_SIZE,
				),
				'aspect_ratio' => array(
					'type' => 'string',
					'description' => __( 'Aspect ratio for Gemini (1:1, 3:4, 4:3, 9:16, 16:9). Default: 3:4 for portrait drawings.', 'mcp-ai-wpoos-pro' ),
					'enum' => array( '1:1', '3:4', '4:3', '9:16', '16:9' ),
					'default' => '3:4',
				),
				'quality' => array(
					'type' => 'string',
					'description' => __( 'Image quality. OpenAI: low, medium, high. Default: high for architectural drawings.', 'mcp-ai-wpoos-pro' ),
					'enum' => array( 'low', 'medium', 'high', 'auto' ),
					'default' => self::DEFAULT_QUALITY,
				),
				'output_format' => array(
					'type' => 'string',
					'description' => __( 'Output format: png (raster) or svg (vector). SVG is vectorized from raster output.', 'mcp-ai-wpoos-pro' ),
					'enum' => array( 'png', 'svg', 'both' ),
					'default' => self::DEFAULT_OUTPUT_FORMAT,
				),
				'file_name' => array(
					'type' => 'string',
					'description' => __( 'Optional base file name for the saved drawing.', 'mcp-ai-wpoos-pro' ),
				),
				'timeout' => array(
					'type' => 'integer',
					'description' => __( 'Request timeout in seconds (5-300). Default: 90.', 'mcp-ai-wpoos-pro' ),
					'minimum' => 5,
					'maximum' => 300,
					'default' => 90,
				),
			),
			'required' => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label' => __( 'generate_architectural_drawing', 'mcp-ai-wpoos-pro' ),
				'payload' => __( 'generate_architectural_drawing', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'label' => __( 'Floor Plan with dimensions', 'mcp-ai-wpoos-pro' ),
				'payload' => __( 'Use generate_architectural_drawing to create a residential floor plan. Ask for room layout, square footage, and special features, then generate with dimensions and annotations.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'label' => __( 'Building Elevation', 'mcp-ai-wpoos-pro' ),
				'payload' => __( 'Use generate_architectural_drawing to create a building elevation. Ask about building style, materials, and height, then generate with material callouts.', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'label' => __( 'Construction Detail', 'mcp-ai-wpoos-pro' ),
				'payload' => __( 'Use generate_architectural_drawing to create a construction detail. Ask about the specific building assembly (wall section, window detail, etc.) and materials, then generate with annotations and scale.', 'mcp-ai-wpoos-pro' ),
			),
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

		// Authentication check.
		if ( ! $user_id && ! $has_token ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to generate architectural drawings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Capability check.
		if ( $user_id ) {
			if ( ! user_can( $user_id, 'upload_files' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to generate architectural drawings.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Validate and sanitize input parameters.
		$prompt = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'No prompt was supplied for the architectural drawing request.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$drawing_type       = isset( $arguments['drawing_type'] ) ? sanitize_text_field( $arguments['drawing_type'] ) : self::DEFAULT_DRAWING_TYPE;
		$presentation_style = isset( $arguments['presentation_style'] ) ? sanitize_text_field( $arguments['presentation_style'] ) : self::DEFAULT_STYLE;
		$provider           = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : self::DEFAULT_PROVIDER;

		// Validate drawing type and style.
		if ( ! in_array( $drawing_type, $this->get_drawing_types(), true ) ) {
			$drawing_type = self::DEFAULT_DRAWING_TYPE;
		}
		if ( ! in_array( $presentation_style, $this->get_presentation_styles(), true ) ) {
			$presentation_style = self::DEFAULT_STYLE;
		}
		if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) {
			$provider = self::DEFAULT_PROVIDER;
		}

		// Build enhanced architectural prompt.
		$enhanced_prompt = $this->build_architectural_prompt( $prompt, $arguments );

		// Route to appropriate provider.
		if ( 'gemini' === $provider ) {
			$result = $this->generate_with_gemini( $enhanced_prompt, $arguments, $user_id, $context );
		} else {
			$result = $this->generate_with_openai( $enhanced_prompt, $arguments, $user_id, $context );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Add metadata about the architectural drawing.
		$result['drawing_type']       = $drawing_type;
		$result['presentation_style'] = $presentation_style;
		$result['provider']           = $provider;
		$result['original_prompt']    = $prompt;
		$result['enhanced_prompt']    = $enhanced_prompt;

		if ( isset( $arguments['scale'] ) ) {
			$result['scale'] = sanitize_text_field( $arguments['scale'] );
		}

		if ( isset( $arguments['building_code'] ) && 'none' !== $arguments['building_code'] ) {
			$result['building_code'] = strtoupper( sanitize_text_field( $arguments['building_code'] ) );
		}

		/**
		 * Filter the architectural drawing result.
		 *
		 * @param array $result    Result array to be returned.
		 * @param array $arguments Arguments supplied to the tool.
		 * @param array $context   Execution context supplied to the tool.
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_architectural_drawing_result', $result, $arguments, $context );

		// Add rendered image HTML to the response for display in chat UI.
		$result = $this->add_image_html_to_response( $result );

		return $result;
	}

	/**
	 * Build enhanced architectural prompt with professional specifications.
	 *
	 * @param string $base_prompt    User's base prompt.
	 * @param array  $arguments      Tool arguments.
	 * @return string Enhanced prompt.
	 */
	protected function build_architectural_prompt( $base_prompt, $arguments ) {
		$drawing_type       = isset( $arguments['drawing_type'] ) ? sanitize_text_field( $arguments['drawing_type'] ) : self::DEFAULT_DRAWING_TYPE;
		$presentation_style = isset( $arguments['presentation_style'] ) ? sanitize_text_field( $arguments['presentation_style'] ) : self::DEFAULT_STYLE;

		// Start with drawing type specific instruction.
		$type_instructions  = $this->get_drawing_type_instructions( $drawing_type );
		$style_instructions = $this->get_style_instructions( $presentation_style );

		$prompt_parts = array(
			sprintf( 'Create a professional architectural %s drawing.', str_replace( '_', ' ', $drawing_type ) ),
			$base_prompt,
			$type_instructions,
			$style_instructions,
		);

		// Add dimensional information if provided.
		if ( isset( $arguments['dimensions'] ) && is_array( $arguments['dimensions'] ) ) {
			$dims = $arguments['dimensions'];
			$unit = isset( $dims['unit'] ) ? sanitize_text_field( $dims['unit'] ) : 'feet';

			$dim_parts = array();
			if ( isset( $dims['width'] ) ) {
				$dim_parts[] = sprintf( 'width: %.1f %s', floatval( $dims['width'] ), $unit );
			}
			if ( isset( $dims['depth'] ) ) {
				$dim_parts[] = sprintf( 'depth: %.1f %s', floatval( $dims['depth'] ), $unit );
			}
			if ( isset( $dims['height'] ) ) {
				$dim_parts[] = sprintf( 'height: %.1f %s', floatval( $dims['height'] ), $unit );
			}

			if ( ! empty( $dim_parts ) ) {
				$prompt_parts[] = 'Dimensions: ' . implode( ', ', $dim_parts ) . '.';
			}
		}

		// Add scale notation if provided.
		if ( isset( $arguments['scale'] ) ) {
			$scale          = sanitize_text_field( $arguments['scale'] );
			$prompt_parts[] = sprintf( 'Scale: %s.', $scale );
		}

		// Add materials if provided.
		if ( isset( $arguments['materials'] ) && is_array( $arguments['materials'] ) ) {
			$materials = array_map( 'sanitize_text_field', $arguments['materials'] );
			if ( ! empty( $materials ) ) {
				$prompt_parts[] = 'Materials/Finishes: ' . implode( ', ', $materials ) . '.';
			}
		}

		// Add building code reference if provided.
		if ( isset( $arguments['building_code'] ) && 'none' !== $arguments['building_code'] ) {
			$code           = strtoupper( sanitize_text_field( $arguments['building_code'] ) );
			$prompt_parts[] = sprintf( 'Comply with %s building code requirements.', $code );
		}

		// Add annotation instructions if enabled.
		$annotations = isset( $arguments['annotations'] ) ? (bool) $arguments['annotations'] : true;
		if ( $annotations && in_array( $presentation_style, array( 'technical', 'annotated' ), true ) ) {
			$prompt_parts[] = 'Include clear dimension lines, measurement annotations, and material callouts.';
		}

		return implode( ' ', $prompt_parts );
	}

	/**
	 * Get drawing type specific instructions.
	 *
	 * @param string $drawing_type Drawing type.
	 * @return string Instructions.
	 */
	protected function get_drawing_type_instructions( $drawing_type ) {
		$instructions = array(
			'floor_plan' => 'Show the layout from above with walls, doors, windows, and room labels. Include furniture placement and circulation paths.',
			'elevation' => 'Show the exterior view of the building facade with accurate proportions, material indications, and vertical dimensions.',
			'section' => 'Show a vertical cut through the building revealing interior structure, floor levels, ceiling heights, and construction assemblies.',
			'detail' => 'Show an enlarged view of a specific building component with precise construction details, material layers, and connection methods.',
			'site_plan' => 'Show the building placement on the site with property boundaries, landscaping, parking, and site access.',
			'reflected_ceiling_plan' => 'Show the ceiling layout from below with lighting fixtures, HVAC diffusers, and ceiling grid.',
			'roof_plan' => 'Show the roof layout from above with roof slopes, drainage, penetrations, and roofing materials.',
			'3d_axonometric' => 'Show a three-dimensional view with parallel projection showing multiple faces of the building simultaneously.',
			'isometric' => 'Show a three-dimensional view with 30-degree angles showing the building in isometric projection.',
			'construction_detail' => 'Show precise construction assembly details with material layers, fasteners, and installation sequences.',
		);

		return isset( $instructions[ $drawing_type ] ) ? $instructions[ $drawing_type ] : '';
	}

	/**
	 * Get presentation style instructions.
	 *
	 * @param string $style Presentation style.
	 * @return string Instructions.
	 */
	protected function get_style_instructions( $style ) {
		$instructions = array(
			'technical' => 'Use precise line weights, architectural symbols, and professional drafting conventions. Black lines on white background.',
			'sketched' => 'Use hand-drawn sketch style with loose linework and artistic shading. Convey design intent with expressive strokes.',
			'rendered' => 'Use realistic rendering with materials, lighting, shadows, and textures. Show depth and three-dimensional qualities.',
			'line_drawing' => 'Use clean, uniform line work without shading or color. Focus on clarity and readability.',
			'annotated' => 'Use technical style with extensive annotations, dimension lines, notes, and material callouts.',
			'schematic' => 'Use simplified diagrammatic representation focusing on key elements and relationships without detailed ornamentation.',
		);

		return isset( $instructions[ $style ] ) ? $instructions[ $style ] : '';
	}

	/**
	 * Generate architectural drawing using OpenAI.
	 *
	 * @param string $prompt     Enhanced prompt.
	 * @param array  $arguments  Tool arguments.
	 * @param int    $user_id    User ID.
	 * @param array  $context    Execution context.
	 * @return array|WP_Error Generation result or error.
	 */
	protected function generate_with_openai( $prompt, $arguments, $user_id, $context ) {
		$model   = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL;
		$size    = isset( $arguments['size'] ) ? sanitize_text_field( $arguments['size'] ) : self::DEFAULT_SIZE;
		$quality = isset( $arguments['quality'] ) ? sanitize_text_field( $arguments['quality'] ) : self::DEFAULT_QUALITY;
		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 90;

		$options = array(
			'model' => $model,
			'size' => $size,
			'quality' => $quality,
			'response_format' => 'b64_json',
			'timeout' => max( 5, min( 300, $timeout ) ),
		);

		$client = new WP_MCP_AI_OpenAI_Client();
		$image  = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_response',
				__( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Store the image.
		$file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
		$storage   = $this->store_image_attachment( $image, $file_name, $prompt, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Handle SVG conversion if requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'png';
		if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
			$svg_storage = $this->convert_to_svg( $storage, $arguments );
			if ( ! is_wp_error( $svg_storage ) ) {
				if ( 'svg' === $output_format ) {
					$storage = $svg_storage; // Replace with SVG.
				} else {
					$storage['svg_version'] = $svg_storage; // Include both.
				}
			}
		}

		return $storage;
	}

	/**
	 * Generate architectural drawing using Gemini.
	 *
	 * @param string $prompt     Enhanced prompt.
	 * @param array  $arguments  Tool arguments.
	 * @param int    $user_id    User ID.
	 * @param array  $context    Execution context.
	 * @return array|WP_Error Generation result or error.
	 */
	protected function generate_with_gemini( $prompt, $arguments, $user_id, $context ) {
		$model        = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'gemini-2.5-flash-image';
		$aspect_ratio = isset( $arguments['aspect_ratio'] ) ? sanitize_text_field( $arguments['aspect_ratio'] ) : '3:4';
		$timeout      = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 90;

		$options = array(
			'model' => $model,
			'aspect_ratio' => $aspect_ratio,
			'mime_type' => 'image/png',
			'timeout' => max( 5, min( 300, $timeout ) ),
		);

		$client = new WP_MCP_AI_Gemini_Client();
		$image  = $client->generate_image( $prompt, $options );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( empty( $image['image'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_response',
				__( 'Gemini returned an empty image response.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Store the image.
		$file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
		$storage   = $this->store_gemini_image_attachment( $image, $file_name, $prompt, $user_id, $context );

		if ( is_wp_error( $storage ) ) {
			return $storage;
		}

		// Handle SVG conversion if requested.
		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'png';
		if ( in_array( $output_format, array( 'svg', 'both' ), true ) ) {
			$svg_storage = $this->convert_to_svg( $storage, $arguments );
			if ( ! is_wp_error( $svg_storage ) ) {
				if ( 'svg' === $output_format ) {
					$storage = $svg_storage; // Replace with SVG.
				} else {
					$storage['svg_version'] = $svg_storage; // Include both.
				}
			}
		}

		return $storage;
	}

	/**
	 * Store OpenAI generated image as WordPress attachment.
	 *
	 * @param array  $image     Image data from OpenAI.
	 * @param string $file_name Base file name.
	 * @param string $prompt    Prompt used.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Storage result or error.
	 */
	protected function store_image_attachment( $image, $file_name, $prompt, $user_id, $context ) {
		$data   = isset( $image['image'] ) ? $image['image'] : '';
		$format = isset( $image['format'] ) ? $image['format'] : 'png';

		if ( '' === $data ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'Image data is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate filename.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'architectural-drawing-%s.%s', $job_id, $format );
		} else {
			$base      = ! empty( $file_name ) ? sanitize_file_name( $file_name ) : 'architectural-drawing';
			$file_name = sprintf( '%s-%s.%s', $base, gmdate( 'Ymd-His' ), $format );
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save the generated drawing.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $upload['error'] )
			);
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_missing',
				__( 'Failed to write the generated drawing to disk.', 'mcp-ai-wpoos-pro' )
			);
		}

		$mime_type = 'image/png';
		$title     = $this->generate_attachment_title( $prompt );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title' => $title,
			'post_content' => '',
			'post_status' => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store metadata.
		$meta = array(
			'source' => 'architectural_drawing',
			'provider' => 'openai',
			'original_prompt' => sanitize_textarea_field( $prompt ),
		);

		if ( ! empty( $image['model'] ) ) {
			$meta['model'] = sanitize_text_field( $image['model'] );
		}
		if ( ! empty( $image['revised_prompt'] ) ) {
			$meta['revised_prompt'] = sanitize_textarea_field( $image['revised_prompt'] );
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_architectural_drawing_meta', $meta );

		$bytes     = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file' => $file_path,
			'file_name' => wp_basename( $file_path ),
			'url' => $local_url,
			'mime_type' => $mime_type,
			'bytes' => $bytes ? (int) $bytes : 0,
			'title' => $title,
		);
	}

	/**
	 * Store Gemini generated image as WordPress attachment.
	 *
	 * @param array  $image     Image data from Gemini.
	 * @param string $file_name Base file name.
	 * @param string $prompt    Prompt used.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Storage result or error.
	 */
	protected function store_gemini_image_attachment( $image, $file_name, $prompt, $user_id, $context ) {
		$data      = isset( $image['image'] ) ? $image['image'] : '';
		$mime_type = isset( $image['mime_type'] ) ? $image['mime_type'] : 'image/png';

		if ( '' === $data ) {
			return new WP_Error(
				'wp_mcp_ai_empty_data',
				__( 'Image data is empty.', 'mcp-ai-wpoos-pro' )
			);
		}

		$extension = str_replace( 'image/', '', $mime_type );

		// Generate filename.
		$job_id = isset( $context['parent_job_id'] ) ? sanitize_key( $context['parent_job_id'] ) : '';
		if ( ! empty( $job_id ) ) {
			$file_name = sprintf( 'architectural-drawing-%s.%s', $job_id, $extension );
		} else {
			$base      = ! empty( $file_name ) ? sanitize_file_name( $file_name ) : 'architectural-drawing';
			$file_name = sprintf( '%s-%s.%s', $base, gmdate( 'Ymd-His' ), $extension );
		}

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save the generated drawing.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $upload['error'] )
			);
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_missing',
				__( 'Failed to write the generated drawing to disk.', 'mcp-ai-wpoos-pro' )
			);
		}

		$title = $this->generate_attachment_title( $prompt );

		$attachment = array(
			'post_mime_type' => $mime_type,
			'post_title' => $title,
			'post_content' => '',
			'post_status' => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( is_array( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store metadata.
		$meta = array(
			'source' => 'architectural_drawing',
			'provider' => 'gemini',
			'original_prompt' => sanitize_textarea_field( $prompt ),
		);

		if ( ! empty( $image['model'] ) ) {
			$meta['model'] = sanitize_text_field( $image['model'] );
		}

		update_post_meta( $attachment_id, '_wp_mcp_ai_architectural_drawing_meta', $meta );

		$bytes     = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file' => $file_path,
			'file_name' => wp_basename( $file_path ),
			'url' => $local_url,
			'mime_type' => $mime_type,
			'bytes' => $bytes ? (int) $bytes : 0,
			'title' => $title,
		);
	}

	/**
	 * Convert raster image to SVG using vectorization.
	 *
	 * @param array $storage   Stored raster image data.
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error SVG storage data or error.
	 */
	protected function convert_to_svg( array $storage, array $arguments ) {
		// Check if Node.js is available.
		if ( ! $this->is_nodejs_available() ) {
			return new WP_Error(
				'wp_mcp_ai_nodejs_required',
				__( 'Node.js is required for SVG vectorization but was not found on the system.', 'mcp-ai-wpoos-pro' )
			);
		}

		$file_path = isset( $storage['file'] ) ? $storage['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Generated image file not found for SVG conversion.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare SVG output file.
		$temp_output = wp_tempnam( 'arch-drawing-svg-' );
		if ( ! $temp_output ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_error',
				__( 'Failed to create temporary SVG output file.', 'mcp-ai-wpoos-pro' )
			);
		}

		$temp_output_svg = $temp_output . '.svg';
		rename( $temp_output, $temp_output_svg );
		$temp_output = $temp_output_svg;

		// Vectorization options optimized for architectural drawings.
		$presentation_style = isset( $arguments['presentation_style'] ) ? $arguments['presentation_style'] : 'technical';

		$vectorization_options = array(
			'colorMode' => ( 'line_drawing' === $presentation_style || 'technical' === $presentation_style ) ? 'binary' : 'color',
			'colorPrecision' => 6,
			'filterSpeckle' => 4,
			'mode' => 'spline',
			'hierarchical' => 'stacked',
		);

		// Execute vectorization script.
		$script_path = WP_MCP_AI_PATH . 'bin/vectorize-image.js';
		$script_args = array(
			$file_path,
			$temp_output,
			wp_json_encode( $vectorization_options ),
		);

		$vectorize_result = $this->execute_nodejs_script(
			$script_path,
			$script_args,
			array(
				'timeout' => 60,
				'parse_json' => true,
			)
		);

		if ( is_wp_error( $vectorize_result ) ) {
			wp_delete_file( $temp_output );
			return $vectorize_result;
		}

		if ( ! isset( $vectorize_result['success'] ) || ! $vectorize_result['success'] ) {
			wp_delete_file( $temp_output );
			return new WP_Error(
				'wp_mcp_ai_vectorization_failed',
				isset( $vectorize_result['error'] ) ? $vectorize_result['error'] : __( 'SVG vectorization failed.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Read SVG file.
		$svg_data = file_get_contents( $temp_output );
		if ( false === $svg_data || '' === $svg_data ) {
			wp_delete_file( $temp_output );
			return new WP_Error(
				'wp_mcp_ai_read_error',
				__( 'Failed to read vectorized SVG file.', 'mcp-ai-wpoos-pro' )
			);
		}

		wp_delete_file( $temp_output );

		// Save as WordPress attachment.
		$svg_storage = $this->save_svg_as_attachment( $svg_data, $arguments );
		if ( is_wp_error( $svg_storage ) ) {
			return $svg_storage;
		}

		// Add vectorization metadata.
		$svg_storage['vectorized']  = true;
		$svg_storage['svg_size']    = isset( $vectorize_result['output_size'] ) ? $vectorize_result['output_size'] : $svg_storage['bytes'];
		$svg_storage['source_size'] = isset( $vectorize_result['input_size'] ) ? $vectorize_result['input_size'] : $storage['bytes'];
		$svg_storage['duration_ms'] = isset( $vectorize_result['duration_ms'] ) ? $vectorize_result['duration_ms'] : 0;

		return $svg_storage;
	}

	/**
	 * Save SVG data as WordPress attachment.
	 *
	 * @param string $svg_data  SVG file content.
	 * @param array  $arguments Tool arguments for naming.
	 * @return array|WP_Error Attachment data or error.
	 */
	protected function save_svg_as_attachment( $svg_data, array $arguments ) {
		$base_name = isset( $arguments['file_name'] ) ? sanitize_file_name( $arguments['file_name'] ) : 'architectural-drawing';
		if ( empty( $base_name ) ) {
			$base_name = 'architectural-drawing';
		}

		$base_name = preg_replace( '/\.(png|jpg|jpeg|gif|webp)$/i', '', $base_name );
		$file_name = $base_name . '-svg-' . gmdate( 'Ymd-His' ) . '.svg';

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $svg_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to save SVG file.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $upload['error'] )
			);
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_failed',
				__( 'Failed to write SVG file to disk.', 'mcp-ai-wpoos-pro' )
			);
		}

		$attachment = array(
			'post_mime_type' => 'image/svg+xml',
			'post_title' => sanitize_text_field( __( 'Architectural Drawing SVG', 'mcp-ai-wpoos-pro' ) ),
			'post_content' => '',
			'post_status' => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return $attachment_id;
		}

		$bytes     = file_exists( $file_path ) ? filesize( $file_path ) : 0;
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file' => $file_path,
			'file_name' => wp_basename( $file_path ),
			'url' => $local_url,
			'mime_type' => 'image/svg+xml',
			'bytes' => $bytes ? (int) $bytes : 0,
			'title' => get_the_title( $attachment_id ),
		);
	}

	/**
	 * Generate attachment title from prompt.
	 *
	 * @param string $prompt Original prompt.
	 * @return string Attachment title.
	 */
	protected function generate_attachment_title( $prompt ) {
		$prompt = (string) $prompt;
		$prompt = preg_replace( '/\s+/', ' ', $prompt );
		$prompt = trim( $prompt );

		if ( '' === $prompt ) {
			return __( 'Architectural Drawing', 'mcp-ai-wpoos-pro' );
		}

		$excerpt = wp_trim_words( $prompt, 10, '…' );

		/* translators: %s: Short excerpt of the prompt. */
		return sprintf( __( 'Architectural Drawing: %s', 'mcp-ai-wpoos-pro' ), $excerpt );
	}

	/**
	 * Get available drawing types.
	 *
	 * @return array Drawing types.
	 */
	protected function get_drawing_types() {
		return array(
			'floor_plan',
			'elevation',
			'section',
			'detail',
			'site_plan',
			'reflected_ceiling_plan',
			'roof_plan',
			'3d_axonometric',
			'isometric',
			'construction_detail',
		);
	}

	/**
	 * Get available presentation styles.
	 *
	 * @return array Presentation styles.
	 */
	protected function get_presentation_styles() {
		return array(
			'technical',
			'sketched',
			'rendered',
			'line_drawing',
			'annotated',
			'schematic',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Remove base64 data to reduce token usage.
		if ( isset( $result['content'] ) && is_array( $result['content'] ) ) {
			unset( $result['content']['data'] );
			unset( $result['content']['data_url'] );

			if ( empty( $result['content'] ) ) {
				unset( $result['content'] );
			}
		}

		// Keep essential metadata.
		$keep_fields = array(
			'attachment_id',
			'url',
			'file_name',
			'mime_type',
			'bytes',
			'title',
			'drawing_type',
			'presentation_style',
			'provider',
			'model',
			'scale',
			'building_code',
			'enhanced_prompt',
			'svg_version',
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Add image_url for vision models.
		if ( isset( $result['url'] ) && '' !== $result['url'] ) {
			$sanitized['image_url'] = array( 'url' => $result['url'] );
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-credentials',
			'requires-capability',
			'write',
			'async',
			'rate-limited',
			'requires-model',
			'consumes-tokens',
			'model-dependent',
			'pro-tool',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'image-generation',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'model_requirements' => array(
				'providers' => array( 'openai', 'gemini' ),
				'models' => array( 'gpt-image-1.5', 'gpt-image-1', 'dall-e-3', 'gemini-2.5-flash-image' ),
				'required' => true,
			),
			'parameter_constraints' => array(
				'required_fields' => array( 'prompt' ),
				'optional_fields' => array(
					'drawing_type',
					'presentation_style',
					'scale',
					'dimensions',
					'materials',
					'building_code',
					'annotations',
					'provider',
					'model',
					'size',
					'aspect_ratio',
					'quality',
					'output_format',
					'file_name',
					'timeout',
				),
				'max_prompt_length' => 4000,
			),
			'rate_limits' => array(
				'requests_per_minute' => 3,
				'requests_per_hour' => 20,
				'concurrent_requests' => 1,
			),
			'timeout_constraints' => array(
				'recommended_timeout' => 90,
				'max_execution_time' => 300,
			),
			'response_constraints' => array(
				'max_size' => 10485760, // 10MB for high-quality architectural drawings.
				'supports_streaming' => false,
			),
			'dependencies' => array(
				'required_settings' => array(
					'openai_api_key' => 'wp_mcp_ai_openai_api_key',
					'gemini_api_key' => 'wp_mcp_ai_gemini_api_key',
				),
				'optional_extensions' => array( 'nodejs' ), // For SVG vectorization.
			),
			'orchestration_hints' => array(
				'can_run_parallel' => false, // Architectural drawings are resource-intensive.
				'requires_lock' => true,
				'cache_ttl' => 0,
				'retry_strategy' => 'exponential_backoff',
				'max_retries' => 2,
			),
		);
	}
}
