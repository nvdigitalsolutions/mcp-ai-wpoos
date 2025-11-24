<?php
/**
 * Tool for material and color recommendations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides AI-powered material and color palette recommendations.
 */
class WP_MCP_AI_Tool_Material_Color_Recommendations implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'material_color_recommendations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Material & Color Recommendations', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Get AI-powered recommendations for materials, color palettes, and design elements based on project preferences and style.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of design project.', 'wp-mcp-ai' ),
					'enum'        => array( 'residential', 'commercial', 'office', 'retail', 'hospitality', 'exterior', 'landscape' ),
					'default'     => 'residential',
				),
				'style_preference' => array(
					'type'        => 'string',
					'description' => __( 'Preferred design style.', 'wp-mcp-ai' ),
					'enum'        => array( 'modern', 'contemporary', 'traditional', 'industrial', 'minimalist', 'rustic', 'scandinavian', 'mediterranean' ),
					'default'     => 'modern',
				),
				'budget_range'     => array(
					'type'        => 'string',
					'description' => __( 'Budget range for materials.', 'wp-mcp-ai' ),
					'enum'        => array( 'economy', 'mid_range', 'premium', 'luxury' ),
					'default'     => 'mid_range',
				),
				'primary_use'      => array(
					'type'        => 'string',
					'description' => __( 'Primary use of the space.', 'wp-mcp-ai' ),
					'enum'        => array( 'living', 'working', 'dining', 'sleeping', 'recreation', 'commercial' ),
				),
				'color_mood'       => array(
					'type'        => 'string',
					'description' => __( 'Desired mood or atmosphere.', 'wp-mcp-ai' ),
					'enum'        => array( 'calm', 'energetic', 'professional', 'warm', 'cool', 'neutral', 'vibrant' ),
					'default'     => 'neutral',
				),
				'sustainability'   => array(
					'type'        => 'boolean',
					'description' => __( 'Prioritize sustainable and eco-friendly materials.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'project_type', 'style_preference' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to access recommendations.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Sanitize inputs.
		$project_type   = isset( $arguments['project_type'] ) ? sanitize_key( $arguments['project_type'] ) : 'residential';
		$style          = isset( $arguments['style_preference'] ) ? sanitize_key( $arguments['style_preference'] ) : 'modern';
		$budget         = isset( $arguments['budget_range'] ) ? sanitize_key( $arguments['budget_range'] ) : 'mid_range';
		$use            = isset( $arguments['primary_use'] ) ? sanitize_key( $arguments['primary_use'] ) : '';
		$mood           = isset( $arguments['color_mood'] ) ? sanitize_key( $arguments['color_mood'] ) : 'neutral';
		$sustainability = isset( $arguments['sustainability'] ) ? (bool) $arguments['sustainability'] : false;

		// Generate recommendations.
		$recommendations = array(
			'project_info'  => array(
				'type'           => $project_type,
				'style'          => $style,
				'budget'         => $budget,
				'mood'           => $mood,
				'sustainability' => $sustainability,
			),
			'color_palette' => $this->generate_color_palette( $style, $mood ),
			'materials'     => $this->recommend_materials( $project_type, $style, $budget, $sustainability ),
			'finishes'      => $this->recommend_finishes( $style, $budget ),
			'accents'       => $this->recommend_accents( $style, $mood ),
			'generated_at'  => current_time( 'mysql' ),
		);

		/**
		 * Filters material and color recommendations before returning.
		 *
		 * @since 1.0.0
		 *
		 * @param array $recommendations Generated recommendations.
		 * @param array $arguments       Tool arguments.
		 * @param int   $user_id         User ID.
		 */
		$recommendations = apply_filters( 'wp_mcp_ai_material_recommendations', $recommendations, $arguments, $user_id );

		return $recommendations;
	}

	/**
	 * Generate color palette based on style and mood.
	 *
	 * @param string $style Design style.
	 * @param string $mood  Color mood.
	 * @return array Color palette.
	 */
	private function generate_color_palette( $style, $mood ) {
		$palettes = array(
			'modern_neutral'       => array(
				'primary'   => '#2C3E50',
				'secondary' => '#ECF0F1',
				'accent'    => '#3498DB',
				'trim'      => '#FFFFFF',
			),
			'modern_warm'          => array(
				'primary'   => '#D35400',
				'secondary' => '#F39C12',
				'accent'    => '#E74C3C',
				'trim'      => '#FDF2E9',
			),
			'contemporary_cool'    => array(
				'primary'   => '#16A085',
				'secondary' => '#1ABC9C',
				'accent'    => '#2ECC71',
				'trim'      => '#E8F8F5',
			),
			'minimalist_neutral'   => array(
				'primary'   => '#95A5A6',
				'secondary' => '#BDC3C7',
				'accent'    => '#34495E',
				'trim'      => '#FFFFFF',
			),
			'industrial_neutral'   => array(
				'primary'   => '#34495E',
				'secondary' => '#7F8C8D',
				'accent'    => '#E67E22',
				'trim'      => '#ECF0F1',
			),
			'scandinavian_neutral' => array(
				'primary'   => '#FDFEFE',
				'secondary' => '#85929E',
				'accent'    => '#5DADE2',
				'trim'      => '#F8F9F9',
			),
		);

		$key = $style . '_' . $mood;
		if ( ! isset( $palettes[ $key ] ) ) {
			$key = 'modern_neutral';
		}

		$palette                = $palettes[ $key ];
		$palette['scheme_name'] = ucwords( str_replace( '_', ' ', $key ) );

		return $palette;
	}

	/**
	 * Recommend materials based on parameters.
	 *
	 * @param string $project_type  Project type.
	 * @param string $style         Design style.
	 * @param string $budget        Budget range.
	 * @param bool   $sustainability Sustainable preference.
	 * @return array Material recommendations.
	 */
	private function recommend_materials( $project_type, $style, $budget, $sustainability ) {
		$base_materials = array(
			'modern'       => array(
				'flooring' => array( 'polished_concrete', 'engineered_wood', 'porcelain_tile' ),
				'walls'    => array( 'smooth_plaster', 'glass', 'metal_panels' ),
				'counters' => array( 'quartz', 'concrete', 'stainless_steel' ),
			),
			'traditional'  => array(
				'flooring' => array( 'hardwood', 'natural_stone', 'ceramic_tile' ),
				'walls'    => array( 'plaster', 'wood_paneling', 'wallpaper' ),
				'counters' => array( 'granite', 'marble', 'wood' ),
			),
			'industrial'   => array(
				'flooring' => array( 'polished_concrete', 'reclaimed_wood', 'metal' ),
				'walls'    => array( 'exposed_brick', 'concrete', 'metal' ),
				'counters' => array( 'concrete', 'reclaimed_wood', 'steel' ),
			),
			'minimalist'   => array(
				'flooring' => array( 'polished_concrete', 'light_wood', 'white_tile' ),
				'walls'    => array( 'smooth_plaster', 'glass', 'white_paint' ),
				'counters' => array( 'white_quartz', 'concrete', 'corian' ),
			),
			'scandinavian' => array(
				'flooring' => array( 'light_wood', 'white_oak', 'bamboo' ),
				'walls'    => array( 'white_plaster', 'light_wood', 'white_paint' ),
				'counters' => array( 'light_wood', 'white_quartz', 'marble' ),
			),
		);

		$materials = isset( $base_materials[ $style ] ) ? $base_materials[ $style ] : $base_materials['modern'];

		// Add sustainability note if requested.
		if ( $sustainability ) {
			$materials['sustainability_notes'] = array(
				'Look for FSC-certified wood',
				'Consider recycled materials',
				'Use low-VOC finishes',
				'Opt for locally-sourced materials',
			);
		}

		// Add budget considerations.
		$materials['budget_notes'] = $this->get_budget_notes( $budget );

		return $materials;
	}

	/**
	 * Recommend finishes based on style and budget.
	 *
	 * @param string $style  Design style.
	 * @param string $budget Budget range.
	 * @return array Finish recommendations.
	 */
	private function recommend_finishes( $style, $budget ) {
		$finishes = array(
			'modern'       => array( 'matte', 'satin', 'high_gloss', 'brushed_metal' ),
			'traditional'  => array( 'satin', 'semi_gloss', 'oil_rubbed', 'polished' ),
			'industrial'   => array( 'raw', 'matte', 'brushed', 'weathered' ),
			'minimalist'   => array( 'matte', 'ultra_matte', 'smooth', 'seamless' ),
			'scandinavian' => array( 'matte', 'natural', 'light_stain', 'whitewash' ),
		);

		return isset( $finishes[ $style ] ) ? $finishes[ $style ] : $finishes['modern'];
	}

	/**
	 * Recommend accent elements.
	 *
	 * @param string $style Design style.
	 * @param string $mood  Color mood.
	 * @return array Accent recommendations.
	 */
	private function recommend_accents( $style, $mood ) {
		return array(
			'hardware'    => $this->get_hardware_recommendations( $style ),
			'lighting'    => $this->get_lighting_recommendations( $style, $mood ),
			'accessories' => $this->get_accessory_recommendations( $style ),
		);
	}

	/**
	 * Get hardware recommendations.
	 *
	 * @param string $style Design style.
	 * @return array Hardware recommendations.
	 */
	private function get_hardware_recommendations( $style ) {
		$hardware = array(
			'modern'       => array( 'brushed_nickel', 'chrome', 'matte_black' ),
			'traditional'  => array( 'oil_rubbed_bronze', 'antique_brass', 'polished_nickel' ),
			'industrial'   => array( 'black_iron', 'raw_steel', 'brushed_steel' ),
			'minimalist'   => array( 'concealed', 'matte_white', 'brushed_nickel' ),
			'scandinavian' => array( 'brushed_nickel', 'light_wood', 'white' ),
		);

		return isset( $hardware[ $style ] ) ? $hardware[ $style ] : $hardware['modern'];
	}

	/**
	 * Get lighting recommendations.
	 *
	 * @param string $style Design style.
	 * @param string $mood  Color mood.
	 * @return array Lighting recommendations.
	 */
	private function get_lighting_recommendations( $style, $mood ) {
		return array(
			'fixture_style' => array( 'pendant', 'recessed', 'track', 'chandelier' ),
			'color_temp'    => $mood === 'warm' ? '2700K-3000K' : '4000K-5000K',
			'type'          => array( 'LED', 'dimmable' ),
		);
	}

	/**
	 * Get accessory recommendations.
	 *
	 * @param string $style Design style.
	 * @return array Accessory recommendations.
	 */
	private function get_accessory_recommendations( $style ) {
		$accessories = array(
			'modern'       => array( 'geometric_art', 'minimal_sculpture', 'glass_vases' ),
			'traditional'  => array( 'framed_art', 'decorative_mirrors', 'ceramic_vases' ),
			'industrial'   => array( 'metal_sculpture', 'vintage_signs', 'exposed_bulbs' ),
			'minimalist'   => array( 'single_statement_piece', 'simple_plants', 'clean_lines' ),
			'scandinavian' => array( 'natural_textiles', 'wood_accents', 'plants' ),
		);

		return isset( $accessories[ $style ] ) ? $accessories[ $style ] : $accessories['modern'];
	}

	/**
	 * Get budget-specific notes.
	 *
	 * @param string $budget Budget range.
	 * @return array Budget notes.
	 */
	private function get_budget_notes( $budget ) {
		$notes = array(
			'economy'   => array(
				'Consider laminate over hardwood',
				'Use paint instead of tile in some areas',
				'Mix high-end and budget materials strategically',
			),
			'mid_range' => array(
				'Balance cost and quality',
				'Invest in high-traffic areas',
				'Consider engineered alternatives',
			),
			'premium'   => array(
				'Focus on quality and durability',
				'Natural materials preferred',
				'Custom fabrication options available',
			),
			'luxury'    => array(
				'Exotic and rare materials',
				'Custom everything',
				'Focus on uniqueness and craftsmanship',
			),
		);

		return isset( $notes[ $budget ] ) ? $notes[ $budget ] : $notes['mid_range'];
	}
}
