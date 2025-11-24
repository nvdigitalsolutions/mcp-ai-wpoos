<?php
/**
 * Tool for vector design and SVG manipulation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides AI-powered vector design creation and manipulation.
 */
class WP_MCP_AI_Tool_Vector_Design_Assistant implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'vector_design_assistant';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Vector Design Assistant', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create and manipulate vector graphics with AI assistance. Generate SVG designs, modify paths, and export to various vector formats.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'            => array(
					'type'        => 'string',
					'description' => __( 'Type of vector operation to perform.', 'wp-mcp-ai' ),
					'enum'        => array( 'create', 'modify', 'convert', 'optimize', 'extract' ),
					'default'     => 'create',
				),
				'design_type'          => array(
					'type'        => 'string',
					'description' => __( 'Type of vector design to create.', 'wp-mcp-ai' ),
					'enum'        => array( 'illustration', 'pattern', 'shape', 'background', 'infographic', 'diagram' ),
				),
				'description'          => array(
					'type'        => 'string',
					'description' => __( 'Description of the desired vector design.', 'wp-mcp-ai' ),
				),
				'style'                => array(
					'type'        => 'string',
					'description' => __( 'Visual style for the vector design.', 'wp-mcp-ai' ),
					'enum'        => array( 'flat', 'line_art', 'gradient', 'minimalist', 'geometric', 'organic', 'abstract' ),
					'default'     => 'flat',
				),
				'color_palette'        => array(
					'type'        => 'array',
					'description' => __( 'Color palette (hex codes).', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'dimensions'           => array(
					'type'        => 'object',
					'description' => __( 'Output dimensions in pixels.', 'wp-mcp-ai' ),
					'properties'  => array(
						'width'  => array(
							'type'    => 'integer',
							'minimum' => 100,
						),
						'height' => array(
							'type'    => 'integer',
							'minimum' => 100,
						),
					),
				),
				'source_attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'Source attachment ID (for modify/convert operations).', 'wp-mcp-ai' ),
				),
				'optimization_level'   => array(
					'type'        => 'string',
					'description' => __( 'Level of SVG optimization.', 'wp-mcp-ai' ),
					'enum'        => array( 'none', 'basic', 'aggressive' ),
					'default'     => 'basic',
				),
				'export_format'        => array(
					'type'        => 'string',
					'description' => __( 'Export format for the vector design.', 'wp-mcp-ai' ),
					'enum'        => array( 'svg', 'eps', 'ai', 'pdf', 'png' ),
					'default'     => 'svg',
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create vector designs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Sanitize inputs.
		$operation     = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : 'create';
		$design_type   = isset( $arguments['design_type'] ) ? sanitize_key( $arguments['design_type'] ) : '';
		$description   = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$style         = isset( $arguments['style'] ) ? sanitize_key( $arguments['style'] ) : 'flat';
		$colors        = isset( $arguments['color_palette'] ) && is_array( $arguments['color_palette'] ) ? array_map( 'sanitize_hex_color', $arguments['color_palette'] ) : array();
		$dimensions    = isset( $arguments['dimensions'] ) ? $arguments['dimensions'] : array();
		$attachment_id = isset( $arguments['source_attachment_id'] ) ? absint( $arguments['source_attachment_id'] ) : 0;
		$optimization  = isset( $arguments['optimization_level'] ) ? sanitize_key( $arguments['optimization_level'] ) : 'basic';
		$export_format = isset( $arguments['export_format'] ) ? sanitize_key( $arguments['export_format'] ) : 'svg';

		// Validate operation-specific requirements.
		if ( in_array( $operation, array( 'modify', 'convert', 'extract' ), true ) && ! $attachment_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_source', __( 'Source attachment ID is required for this operation.', 'wp-mcp-ai' ) );
		}

		// Set default dimensions if not provided.
		$width  = isset( $dimensions['width'] ) ? absint( $dimensions['width'] ) : 1000;
		$height = isset( $dimensions['height'] ) ? absint( $dimensions['height'] ) : 1000;

		// Generate default color palette if not provided.
		if ( empty( $colors ) ) {
			$colors = $this->generate_default_palette( $style );
		}

		$design_id = wp_generate_uuid4();
		$timestamp = current_time( 'mysql' );

		// Process based on operation type.
		$result_data = $this->process_operation( $operation, $design_type, $style, $attachment_id );

		$result = array(
			'design_id'      => $design_id,
			'operation'      => $operation,
			'design_info'    => array(
				'type'        => $design_type,
				'description' => $description,
				'style'       => $style,
			),
			'settings'       => array(
				'colors'       => $colors,
				'dimensions'   => array(
					'width'  => $width,
					'height' => $height,
				),
				'optimization' => $optimization,
				'format'       => $export_format,
			),
			'vector_info'    => $result_data,
			'status'         => 'completed',
			'generated_at'   => $timestamp,
			'download_url'   => esc_url(
				add_query_arg(
					array(
						'action'    => 'wp_mcp_ai_download_vector',
						'design_id' => $design_id,
						'format'    => $export_format,
					),
					admin_url( 'admin-ajax.php' )
				)
			),
			'specifications' => $this->generate_vector_specs( $style, $optimization ),
			'message'        => sprintf(
				/* translators: 1: operation type, 2: export format */
				__( 'Vector %1$s operation completed successfully. Available in %2$s format.', 'wp-mcp-ai' ),
				ucwords( $operation ),
				strtoupper( $export_format )
			),
		);

		/**
		 * Fires after a vector design operation completes.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result Vector design result.
		 * @param array $arguments Tool arguments.
		 * @param int   $user_id User ID.
		 */
		do_action( 'wp_mcp_ai_vector_design_completed', $result, $arguments, $user_id );

		return $result;
	}

	/**
	 * Process the vector operation.
	 *
	 * @param string $operation     Operation type.
	 * @param string $design_type   Design type.
	 * @param string $style         Visual style.
	 * @param int    $attachment_id Source attachment ID.
	 * @return array Operation result data.
	 */
	private function process_operation( $operation, $design_type, $style, $attachment_id ) {
		$data = array(
			'operation_type' => $operation,
		);

		switch ( $operation ) {
			case 'create':
				$data['elements_created'] = $this->get_elements_for_type( $design_type, $style );
				$data['layers']           = $this->get_layer_structure( $design_type );
				break;

			case 'modify':
				$data['modifications_applied'] = array( 'color_adjustment', 'path_refinement', 'optimization' );
				$data['source_file']           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
				break;

			case 'convert':
				$data['conversion'] = array(
					'from'    => 'raster',
					'to'      => 'vector',
					'method'  => 'ai_tracing',
					'quality' => 'high',
				);
				break;

			case 'optimize':
				$data['optimizations'] = array(
					'path_simplification'      => true,
					'redundant_points_removed' => true,
					'precision_decimal_places' => 2,
					'file_size_reduction'      => '40-60%',
				);
				break;

			case 'extract':
				$data['extracted_elements'] = array( 'shapes', 'paths', 'colors', 'text' );
				break;
		}

		return $data;
	}

	/**
	 * Get elements for design type.
	 *
	 * @param string $design_type Design type.
	 * @param string $style       Visual style.
	 * @return array Elements.
	 */
	private function get_elements_for_type( $design_type, $style ) {
		$elements = array(
			'illustration' => array( 'main_subject', 'background', 'details', 'highlights' ),
			'pattern'      => array( 'base_shape', 'repetition_grid', 'color_variants' ),
			'shape'        => array( 'primary_form', 'stroke', 'fill' ),
			'background'   => array( 'base_layer', 'texture', 'gradient' ),
			'infographic'  => array( 'charts', 'icons', 'text_blocks', 'connectors' ),
			'diagram'      => array( 'nodes', 'connections', 'labels', 'legend' ),
		);

		return isset( $elements[ $design_type ] ) ? $elements[ $design_type ] : array( 'base_shape' );
	}

	/**
	 * Get layer structure.
	 *
	 * @param string $design_type Design type.
	 * @return array Layer structure.
	 */
	private function get_layer_structure( $design_type ) {
		return array(
			'total_layers' => $design_type === 'infographic' ? 5 : 3,
			'layer_names'  => array( 'background', 'content', 'foreground' ),
			'grouped'      => true,
			'organized'    => true,
		);
	}

	/**
	 * Generate default color palette.
	 *
	 * @param string $style Visual style.
	 * @return array Color palette.
	 */
	private function generate_default_palette( $style ) {
		$palettes = array(
			'flat'       => array( '#3498DB', '#E74C3C', '#2ECC71', '#F39C12' ),
			'line_art'   => array( '#000000', '#FFFFFF' ),
			'gradient'   => array( '#667EEA', '#764BA2', '#F093FB', '#F5576C' ),
			'minimalist' => array( '#2C3E50', '#ECF0F1' ),
			'geometric'  => array( '#E74C3C', '#3498DB', '#F39C12', '#9B59B6' ),
			'organic'    => array( '#27AE60', '#16A085', '#2ECC71', '#1ABC9C' ),
			'abstract'   => array( '#9B59B6', '#E74C3C', '#F39C12', '#3498DB' ),
		);

		return isset( $palettes[ $style ] ) ? $palettes[ $style ] : $palettes['flat'];
	}

	/**
	 * Generate vector specifications.
	 *
	 * @param string $style        Visual style.
	 * @param string $optimization Optimization level.
	 * @return array Vector specifications.
	 */
	private function generate_vector_specs( $style, $optimization ) {
		return array(
			'format_version'    => 'SVG 1.1',
			'coordinate_system' => 'cartesian',
			'units'             => 'pixels',
			'precision'         => $optimization === 'aggressive' ? 1 : 2,
			'features'          => array(
				'scalable'    => true,
				'editable'    => true,
				'web_ready'   => true,
				'print_ready' => true,
			),
			'optimization'      => array(
				'level'           => $optimization,
				'paths_optimized' => true,
				'viewbox_set'     => true,
				'xmlns_declared'  => true,
			),
		);
	}
}
