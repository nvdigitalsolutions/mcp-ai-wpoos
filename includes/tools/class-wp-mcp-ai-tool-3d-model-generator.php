<?php
/**
 * Tool for converting 2D designs to 3D models.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts 2D inputs into interactive 3D models with OBJ and FBX export support.
 */
class WP_MCP_AI_Tool_3D_Model_Generator implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return '3d_model_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( '3D Model Generator', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert 2D designs and floor plans into interactive 3D models. Supports OBJ, FBX, and other industry-standard formats for SketchUp and Revit.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the 2D source (floor plan, elevation, etc).', 'wp-mcp-ai' ),
				),
				'model_type'           => array(
					'type'        => 'string',
					'description' => __( 'Type of 3D model to generate.', 'wp-mcp-ai' ),
					'enum'        => array( 'architectural', 'interior', 'furniture', 'landscape', 'product' ),
					'default'     => 'architectural',
				),
				'detail_level'         => array(
					'type'        => 'string',
					'description' => __( 'Level of detail for the model.', 'wp-mcp-ai' ),
					'enum'        => array( 'low', 'medium', 'high', 'ultra' ),
					'default'     => 'medium',
				),
				'wall_height'          => array(
					'type'        => 'number',
					'description' => __( 'Default wall height in meters (for architectural models).', 'wp-mcp-ai' ),
					'minimum'     => 2.0,
					'maximum'     => 10.0,
					'default'     => 3.0,
				),
				'export_format'        => array(
					'type'        => 'string',
					'description' => __( 'Export format for the 3D model.', 'wp-mcp-ai' ),
					'enum'        => array( 'obj', 'fbx', 'dae', 'stl', 'glb' ),
					'default'     => 'obj',
				),
				'include_textures'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include texture maps with the model.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'apply_materials'      => array(
					'type'        => 'boolean',
					'description' => __( 'Apply suggested materials to the model.', 'wp-mcp-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'source_attachment_id' ),
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
			'3d_model_tool_start',
			'3D model generation started',
			array(
				'user_id'      => $user_id,
				'assistant_id' => $assistant_id,
			)
		);



		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate 3D models.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate attachment.
		$attachment_id = isset( $arguments['source_attachment_id'] ) ? absint( $arguments['source_attachment_id'] ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_attachment', __( 'Invalid source attachment ID.', 'wp-mcp-ai' ) );
		}

		// Sanitize parameters.
		$model_type    = isset( $arguments['model_type'] ) ? sanitize_key( $arguments['model_type'] ) : 'architectural';
		$detail_level  = isset( $arguments['detail_level'] ) ? sanitize_key( $arguments['detail_level'] ) : 'medium';
		$wall_height   = isset( $arguments['wall_height'] ) ? floatval( $arguments['wall_height'] ) : 3.0;
		$export_format = isset( $arguments['export_format'] ) ? sanitize_key( $arguments['export_format'] ) : 'obj';
		$textures      = isset( $arguments['include_textures'] ) ? (bool) $arguments['include_textures'] : true;
		$materials     = isset( $arguments['apply_materials'] ) ? (bool) $arguments['apply_materials'] : true;

		// Validate wall height.
		$wall_height = max( 2.0, min( 10.0, $wall_height ) );

		$model_id  = wp_generate_uuid4();
		$timestamp = current_time( 'mysql' );

		// Calculate complexity and estimated processing time.
		$complexity = $this->calculate_complexity( $detail_level, $textures, $materials );

		$result = array(
			'model_id'          => $model_id,
			'source_attachment' => array(
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
			),
			'settings'          => array(
				'model_type'       => $model_type,
				'detail_level'     => $detail_level,
				'wall_height'      => $wall_height,
				'export_format'    => $export_format,
				'include_textures' => $textures,
				'apply_materials'  => $materials,
			),
			'complexity'        => $complexity,
			'status'            => 'processing',
			'generated_at'      => $timestamp,
			'estimated_time'    => $this->estimate_processing_time( $detail_level ),
			'file_formats'      => $this->get_export_info( $export_format ),
			'download_url'      => esc_url(
				add_query_arg(
					array(
						'action'   => 'wp_mcp_ai_download_3d_model',
						'model_id' => $model_id,
						'format'   => $export_format,
					),
					admin_url( 'admin-ajax.php' )
				)
			),
			'preview_url'       => esc_url(
				add_query_arg(
					array(
						'action'   => 'wp_mcp_ai_preview_3d_model',
						'model_id' => $model_id,
					),
					admin_url( 'admin-ajax.php' )
				)
			),
			'message'           => sprintf(
				/* translators: 1: model type, 2: export format */
				__( '3D %1$s model generation started. Will be available in %2$s format.', 'wp-mcp-ai' ),
				ucwords( $model_type ),
				strtoupper( $export_format )
			),
		);

		/**
		 * Fires after a 3D model generation is queued.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result 3D model result data.
		 * @param int   $attachment_id Source attachment ID.
		 * @param int   $user_id User ID.
		 */
		do_action( 'wp_mcp_ai_3d_model_queued', $result, $attachment_id, $user_id );

		return $result;
	}

	/**
	 * Calculate model complexity score.
	 *
	 * @param string $detail_level Detail level.
	 * @param bool   $textures     Include textures.
	 * @param bool   $materials    Apply materials.
	 * @return array Complexity information.
	 */
	private function calculate_complexity( $detail_level, $textures, $materials ) {
		$base_scores = array(
			'low'    => 10,
			'medium' => 30,
			'high'   => 60,
			'ultra'  => 100,
		);

		$score = isset( $base_scores[ $detail_level ] ) ? $base_scores[ $detail_level ] : 30;

		if ( $textures ) {
			$score += 15;
		}

		if ( $materials ) {
			$score += 10;
		}

		$level = 'simple';
		if ( $score > 70 ) {
			$level = 'very_complex';
		} elseif ( $score > 45 ) {
			$level = 'complex';
		} elseif ( $score > 25 ) {
			$level = 'moderate';
		}

		return array(
			'score' => $score,
			'level' => $level,
		);
	}

	/**
	 * Estimate processing time based on detail level.
	 *
	 * @param string $detail_level Detail level.
	 * @return string Estimated time.
	 */
	private function estimate_processing_time( $detail_level ) {
		$times = array(
			'low'    => '1-2 minutes',
			'medium' => '3-5 minutes',
			'high'   => '8-12 minutes',
			'ultra'  => '15-25 minutes',
		);

		return isset( $times[ $detail_level ] ) ? $times[ $detail_level ] : '3-5 minutes';
	}

	/**
	 * Get export format information.
	 *
	 * @param string $format Export format.
	 * @return array Format information.
	 */
	private function get_export_info( $format ) {
		$formats = array(
			'obj' => array(
				'name'           => 'Wavefront OBJ',
				'compatibility'  => array( 'SketchUp', 'Blender', '3ds Max', 'Maya', 'Cinema 4D' ),
				'includes'       => array( 'geometry', 'materials', 'texture_coordinates' ),
				'file_extension' => '.obj',
			),
			'fbx' => array(
				'name'           => 'Autodesk FBX',
				'compatibility'  => array( 'Revit', 'AutoCAD', '3ds Max', 'Maya', 'Unity', 'Unreal' ),
				'includes'       => array( 'geometry', 'materials', 'textures', 'animations' ),
				'file_extension' => '.fbx',
			),
			'dae' => array(
				'name'           => 'COLLADA',
				'compatibility'  => array( 'SketchUp', 'Blender', 'Cinema 4D' ),
				'includes'       => array( 'geometry', 'materials', 'textures' ),
				'file_extension' => '.dae',
			),
			'stl' => array(
				'name'           => 'Stereolithography',
				'compatibility'  => array( '3D_Printing', 'CAD_Software' ),
				'includes'       => array( 'geometry_only' ),
				'file_extension' => '.stl',
			),
			'glb' => array(
				'name'           => 'GL Transmission Format Binary',
				'compatibility'  => array( 'Web_Browsers', 'AR_VR', 'Three.js' ),
				'includes'       => array( 'geometry', 'materials', 'textures', 'animations' ),
				'file_extension' => '.glb',
			),
		);

		return isset( $formats[ $format ] ) ? $formats[ $format ] : $formats['obj'];
	}
}
