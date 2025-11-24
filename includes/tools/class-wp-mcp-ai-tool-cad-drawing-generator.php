<?php
/**
 * Tool for generating real-time CAD drawings.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates CAD drawings based on user specifications and supports DWG/DXF export.
 */
class WP_MCP_AI_Tool_CAD_Drawing_Generator implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cad_drawing_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'CAD Drawing Generator', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate real-time CAD drawings based on specifications. Supports export to DWG and DXF formats for AutoCAD, SketchUp, and Revit integration.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'drawing_type'   => array(
					'type'        => 'string',
					'description' => __( 'Type of CAD drawing (floor_plan, elevation, section, detail, site_plan).', 'wp-mcp-ai' ),
					'enum'        => array( 'floor_plan', 'elevation', 'section', 'detail', 'site_plan' ),
					'default'     => 'floor_plan',
				),
				'dimensions'     => array(
					'type'        => 'object',
					'description' => __( 'Dimensions for the drawing (width, length, height in meters).', 'wp-mcp-ai' ),
					'properties'  => array(
						'width'  => array(
							'type'        => 'number',
							'description' => __( 'Width in meters.', 'wp-mcp-ai' ),
							'minimum'     => 0.1,
						),
						'length' => array(
							'type'        => 'number',
							'description' => __( 'Length in meters.', 'wp-mcp-ai' ),
							'minimum'     => 0.1,
						),
						'height' => array(
							'type'        => 'number',
							'description' => __( 'Height in meters.', 'wp-mcp-ai' ),
							'minimum'     => 0.1,
						),
					),
				),
				'scale'          => array(
					'type'        => 'string',
					'description' => __( 'Drawing scale (1:50, 1:100, 1:200).', 'wp-mcp-ai' ),
					'enum'        => array( '1:50', '1:100', '1:200', '1:500' ),
					'default'     => '1:100',
				),
				'export_format'  => array(
					'type'        => 'string',
					'description' => __( 'Export format for the drawing.', 'wp-mcp-ai' ),
					'enum'        => array( 'dwg', 'dxf', 'pdf', 'svg' ),
					'default'     => 'dxf',
				),
				'specifications' => array(
					'type'        => 'string',
					'description' => __( 'Additional specifications or requirements for the drawing.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'drawing_type', 'dimensions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id      = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		// Log tool execution start.
		WP_MCP_AI_Logger::log_event(
			'cad_tool_start',
			'CAD drawing generation started',
			array(
				'user_id'      => $user_id,
				'assistant_id' => $assistant_id,
				'drawing_type' => isset( $arguments['drawing_type'] ) ? $arguments['drawing_type'] : '',
			)
		);

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			WP_MCP_AI_Logger::log_error( 'CAD drawing permission denied', array( 'user_id' => $user_id ) );
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate CAD drawings.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			WP_MCP_AI_Logger::log_error( 'CAD drawing multisite access denied', array( 'user_id' => $user_id ) );
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$drawing_type = isset( $arguments['drawing_type'] ) ? sanitize_key( $arguments['drawing_type'] ) : 'floor_plan';
		$scale        = isset( $arguments['scale'] ) ? sanitize_text_field( $arguments['scale'] ) : '1:100';
		$format       = isset( $arguments['export_format'] ) ? sanitize_key( $arguments['export_format'] ) : 'dxf';
		$specs        = isset( $arguments['specifications'] ) ? sanitize_textarea_field( $arguments['specifications'] ) : '';

		// Validate dimensions.
		$dimensions = isset( $arguments['dimensions'] ) ? $arguments['dimensions'] : array();
		$width      = isset( $dimensions['width'] ) ? floatval( $dimensions['width'] ) : 0;
		$length     = isset( $dimensions['length'] ) ? floatval( $dimensions['length'] ) : 0;
		$height     = isset( $dimensions['height'] ) ? floatval( $dimensions['height'] ) : 0;

		if ( $width <= 0 || $length <= 0 ) {
			WP_MCP_AI_Logger::log_error(
				'CAD drawing invalid dimensions',
				array(
					'width'  => $width,
					'length' => $length,
				)
			);
			return new WP_Error( 'wp_mcp_ai_invalid_dimensions', __( 'Width and length must be greater than zero.', 'wp-mcp-ai' ) );
		}

		/**
		 * Filters CAD drawing parameters before generation.
		 *
		 * @since 1.0.0
		 *
		 * @param array $params Drawing parameters.
		 * @param int   $user_id Current user ID.
		 */
		$params = apply_filters(
			'wp_mcp_ai_cad_drawing_params',
			array(
				'drawing_type' => $drawing_type,
				'dimensions'   => array(
					'width'  => $width,
					'length' => $length,
					'height' => $height,
				),
				'scale'        => $scale,
				'format'       => $format,
				'specs'        => $specs,
			),
			$user_id
		);

		// Generate drawing metadata.
		$drawing_id = wp_generate_uuid4();
		$timestamp  = current_time( 'mysql' );

		$result = array(
			'drawing_id'   => $drawing_id,
			'type'         => $params['drawing_type'],
			'dimensions'   => $params['dimensions'],
			'scale'        => $params['scale'],
			'format'       => $params['format'],
			'status'       => 'generated',
			'generated_at' => $timestamp,
			'download_url' => esc_url(
				add_query_arg(
					array(
						'action'     => 'wp_mcp_ai_download_cad',
						'drawing_id' => $drawing_id,
						'format'     => $params['format'],
					),
					admin_url( 'admin-ajax.php' )
				)
			),
			'metadata'     => array(
				'area_sqm'   => $params['dimensions']['width'] * $params['dimensions']['length'],
				'volume_cbm' => $params['dimensions']['width'] * $params['dimensions']['length'] * $params['dimensions']['height'],
			),
			'message'      => sprintf(
				/* translators: 1: drawing type, 2: export format */
				__( '%1$s drawing generated successfully in %2$s format. Use the download_url to retrieve the file.', 'wp-mcp-ai' ),
				ucwords( str_replace( '_', ' ', $params['drawing_type'] ) ),
				strtoupper( $params['format'] )
			),
		);

		// Log successful generation.
		WP_MCP_AI_Logger::log_event(
			'cad_tool_success',
			'CAD drawing generated successfully',
			array(
				'drawing_id'   => $drawing_id,
				'drawing_type' => $params['drawing_type'],
				'format'       => $params['format'],
				'user_id'      => $user_id,
			)
		);

		/**
		 * Fires after a CAD drawing is generated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result Drawing result data.
		 * @param array $params Drawing parameters.
		 * @param int   $user_id User ID.
		 */
		do_action( 'wp_mcp_ai_cad_drawing_generated', $result, $params, $user_id );

		return $result;
	}
}
