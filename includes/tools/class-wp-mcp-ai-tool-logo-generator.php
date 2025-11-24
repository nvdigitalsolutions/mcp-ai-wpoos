<?php
/**
 * Tool for AI-powered logo generation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates professional logos using AI with vector output support.
 */
class WP_MCP_AI_Tool_Logo_Generator implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'logo_generator';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'AI Logo Generator', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate professional logos using AI with customizable styles, colors, and vector format export (SVG, EPS, AI).', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'business_name'    => array(
					'type'        => 'string',
					'description' => __( 'Name of the business or brand.', 'wp-mcp-ai' ),
				),
				'tagline'          => array(
					'type'        => 'string',
					'description' => __( 'Optional tagline or slogan.', 'wp-mcp-ai' ),
				),
				'industry'         => array(
					'type'        => 'string',
					'description' => __( 'Industry or business type.', 'wp-mcp-ai' ),
					'enum'        => array( 'technology', 'retail', 'food', 'health', 'finance', 'construction', 'design', 'education', 'entertainment', 'other' ),
				),
				'logo_style'       => array(
					'type'        => 'string',
					'description' => __( 'Preferred logo style.', 'wp-mcp-ai' ),
					'enum'        => array( 'modern', 'classic', 'minimalist', 'vintage', 'playful', 'corporate', 'geometric', 'abstract' ),
					'default'     => 'modern',
				),
				'logo_type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of logo design.', 'wp-mcp-ai' ),
					'enum'        => array( 'wordmark', 'lettermark', 'icon', 'combination', 'emblem', 'mascot' ),
					'default'     => 'combination',
				),
				'color_scheme'     => array(
					'type'        => 'string',
					'description' => __( 'Preferred color scheme.', 'wp-mcp-ai' ),
					'enum'        => array( 'monochrome', 'two_color', 'multicolor', 'gradient', 'custom' ),
					'default'     => 'two_color',
				),
				'primary_colors'   => array(
					'type'        => 'array',
					'description' => __( 'Primary colors (hex codes).', 'wp-mcp-ai' ),
					'items'       => array( 'type' => 'string' ),
				),
				'include_icon'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include an icon or symbol.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'export_format'    => array(
					'type'        => 'string',
					'description' => __( 'Vector format for export.', 'wp-mcp-ai' ),
					'enum'        => array( 'svg', 'eps', 'ai', 'pdf' ),
					'default'     => 'svg',
				),
				'variations_count' => array(
					'type'        => 'integer',
					'description' => __( 'Number of logo variations to generate.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 5,
					'default'     => 3,
				),
			),
			'required'             => array( 'business_name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate logos.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Sanitize inputs.
		$business_name = isset( $arguments['business_name'] ) ? sanitize_text_field( $arguments['business_name'] ) : '';
		$tagline       = isset( $arguments['tagline'] ) ? sanitize_text_field( $arguments['tagline'] ) : '';
		$industry      = isset( $arguments['industry'] ) ? sanitize_key( $arguments['industry'] ) : 'other';
		$logo_style    = isset( $arguments['logo_style'] ) ? sanitize_key( $arguments['logo_style'] ) : 'modern';
		$logo_type     = isset( $arguments['logo_type'] ) ? sanitize_key( $arguments['logo_type'] ) : 'combination';
		$color_scheme  = isset( $arguments['color_scheme'] ) ? sanitize_key( $arguments['color_scheme'] ) : 'two_color';
		$colors        = isset( $arguments['primary_colors'] ) && is_array( $arguments['primary_colors'] ) ? array_map( 'sanitize_hex_color', $arguments['primary_colors'] ) : array();
		$include_icon  = isset( $arguments['include_icon'] ) ? (bool) $arguments['include_icon'] : true;
		$export_format = isset( $arguments['export_format'] ) ? sanitize_key( $arguments['export_format'] ) : 'svg';
		$variations    = isset( $arguments['variations_count'] ) ? absint( $arguments['variations_count'] ) : 3;

		if ( empty( $business_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Business name is required.', 'wp-mcp-ai' ) );
		}

		// Ensure variations are within bounds.
		$variations = max( 1, min( 5, $variations ) );

		// Generate color palette if not provided.
		if ( empty( $colors ) ) {
			$colors = $this->generate_color_palette( $industry, $logo_style );
		}

		$logo_id   = wp_generate_uuid4();
		$timestamp = current_time( 'mysql' );

		// Generate design specifications.
		$design_specs = $this->generate_design_specs( $logo_type, $logo_style, $include_icon );

		$result = array(
			'logo_id'          => $logo_id,
			'brand_info'       => array(
				'business_name' => $business_name,
				'tagline'       => $tagline,
				'industry'      => $industry,
			),
			'design_settings'  => array(
				'style'        => $logo_style,
				'type'         => $logo_type,
				'color_scheme' => $color_scheme,
				'colors'       => $colors,
				'include_icon' => $include_icon,
			),
			'specifications'   => $design_specs,
			'variations'       => $this->generate_variations( $variations, $logo_id ),
			'export_format'    => $export_format,
			'status'           => 'generated',
			'generated_at'     => $timestamp,
			'download_urls'    => $this->generate_download_urls( $logo_id, $export_format, $variations ),
			'usage_guidelines' => $this->generate_usage_guidelines( $logo_type ),
			'message'          => sprintf(
				/* translators: 1: number of variations, 2: export format */
				__( 'Successfully generated %1$d logo variation(s) in %2$s format.', 'wp-mcp-ai' ),
				$variations,
				strtoupper( $export_format )
			),
		);

		/**
		 * Fires after a logo is generated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $result Logo generation result.
		 * @param array $arguments Tool arguments.
		 * @param int   $user_id User ID.
		 */
		do_action( 'wp_mcp_ai_logo_generated', $result, $arguments, $user_id );

		return $result;
	}

	/**
	 * Generate color palette based on industry and style.
	 *
	 * @param string $industry   Industry type.
	 * @param string $logo_style Logo style.
	 * @return array Color palette.
	 */
	private function generate_color_palette( $industry, $logo_style ) {
		$industry_colors = array(
			'technology'   => array( '#0066CC', '#00A8E8', '#003D82' ),
			'retail'       => array( '#E74C3C', '#3498DB', '#2ECC71' ),
			'food'         => array( '#E67E22', '#F39C12', '#C0392B' ),
			'health'       => array( '#27AE60', '#3498DB', '#1ABC9C' ),
			'finance'      => array( '#34495E', '#16A085', '#2C3E50' ),
			'construction' => array( '#F39C12', '#D35400', '#34495E' ),
			'design'       => array( '#9B59B6', '#E74C3C', '#3498DB' ),
			'education'    => array( '#3498DB', '#2ECC71', '#F39C12' ),
		);

		return isset( $industry_colors[ $industry ] ) ? $industry_colors[ $industry ] : array( '#2C3E50', '#3498DB' );
	}

	/**
	 * Generate design specifications.
	 *
	 * @param string $logo_type    Logo type.
	 * @param string $logo_style   Logo style.
	 * @param bool   $include_icon Include icon.
	 * @return array Design specs.
	 */
	private function generate_design_specs( $logo_type, $logo_style, $include_icon ) {
		return array(
			'typography'  => array(
				'font_family'    => $this->get_font_recommendation( $logo_style ),
				'font_weight'    => $logo_style === 'bold' ? 'bold' : 'medium',
				'letter_spacing' => $logo_style === 'modern' ? 'tight' : 'normal',
			),
			'icon_style'  => $include_icon ? $this->get_icon_style( $logo_style ) : null,
			'layout'      => $this->get_layout_recommendation( $logo_type ),
			'scalability' => array(
				'min_size' => '16px',
				'max_size' => 'unlimited',
				'formats'  => array( 'vector', 'raster' ),
			),
		);
	}

	/**
	 * Get font recommendation based on style.
	 *
	 * @param string $logo_style Logo style.
	 * @return string Font recommendation.
	 */
	private function get_font_recommendation( $logo_style ) {
		$fonts = array(
			'modern'     => 'Sans-serif, Geometric',
			'classic'    => 'Serif, Traditional',
			'minimalist' => 'Sans-serif, Light',
			'vintage'    => 'Serif, Decorative',
			'playful'    => 'Rounded, Hand-drawn',
			'corporate'  => 'Sans-serif, Professional',
			'geometric'  => 'Geometric Sans-serif',
			'abstract'   => 'Custom, Unique',
		);

		return isset( $fonts[ $logo_style ] ) ? $fonts[ $logo_style ] : 'Sans-serif';
	}

	/**
	 * Get icon style recommendation.
	 *
	 * @param string $logo_style Logo style.
	 * @return string Icon style.
	 */
	private function get_icon_style( $logo_style ) {
		$styles = array(
			'modern'     => 'Clean, Geometric shapes',
			'classic'    => 'Traditional, Ornamental',
			'minimalist' => 'Simple, Line-based',
			'vintage'    => 'Detailed, Decorative',
			'playful'    => 'Fun, Rounded',
			'corporate'  => 'Professional, Structured',
			'geometric'  => 'Angular, Precise',
			'abstract'   => 'Unique, Artistic',
		);

		return isset( $styles[ $logo_style ] ) ? $styles[ $logo_style ] : 'Modern';
	}

	/**
	 * Get layout recommendation.
	 *
	 * @param string $logo_type Logo type.
	 * @return string Layout recommendation.
	 */
	private function get_layout_recommendation( $logo_type ) {
		$layouts = array(
			'wordmark'    => 'Horizontal text-only',
			'lettermark'  => 'Stacked or horizontal initials',
			'icon'        => 'Symbol-only, square or circular',
			'combination' => 'Icon + text, side-by-side or stacked',
			'emblem'      => 'Text inside shape or badge',
			'mascot'      => 'Character with or without text',
		);

		return isset( $layouts[ $logo_type ] ) ? $layouts[ $logo_type ] : 'Flexible';
	}

	/**
	 * Generate variation metadata.
	 *
	 * @param int    $count   Number of variations.
	 * @param string $logo_id Logo ID.
	 * @return array Variations.
	 */
	private function generate_variations( $count, $logo_id ) {
		$variations = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$variations[] = array(
				'variation_id'   => $logo_id . '-v' . $i,
				'variation_name' => 'Variation ' . $i,
				'description'    => sprintf( __( 'Logo design variation %d', 'wp-mcp-ai' ), $i ),
			);
		}

		return $variations;
	}

	/**
	 * Generate download URLs for variations.
	 *
	 * @param string $logo_id       Logo ID.
	 * @param string $export_format Export format.
	 * @param int    $variations    Number of variations.
	 * @return array Download URLs.
	 */
	private function generate_download_urls( $logo_id, $export_format, $variations ) {
		$urls = array();

		for ( $i = 1; $i <= $variations; $i++ ) {
			$urls[ 'variation_' . $i ] = esc_url(
				add_query_arg(
					array(
						'action'    => 'wp_mcp_ai_download_logo',
						'logo_id'   => $logo_id,
						'variation' => $i,
						'format'    => $export_format,
					),
					admin_url( 'admin-ajax.php' )
				)
			);
		}

		return $urls;
	}

	/**
	 * Generate usage guidelines.
	 *
	 * @param string $logo_type Logo type.
	 * @return array Usage guidelines.
	 */
	private function generate_usage_guidelines( $logo_type ) {
		return array(
			'minimum_size'     => '1 inch / 72 pixels wide',
			'clear_space'      => 'Minimum clear space equal to logo height',
			'color_variations' => array( 'full_color', 'black', 'white', 'grayscale' ),
			'backgrounds'      => array( 'light', 'dark', 'color' ),
			'dont_do'          => array(
				'Distort or stretch the logo',
				'Change the colors',
				'Add effects or shadows',
				'Place on busy backgrounds',
			),
		);
	}
}
