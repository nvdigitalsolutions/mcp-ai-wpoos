<?php
/**
 * Create Hero Section Tool
 *
 * Generates hero sections with various styles, including headline, subheadline,
 * CTAs, images/videos, and animations.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Hero Section Tool
 *
 * Creates hero sections with:
 * - Compelling headlines and subheadlines
 * - Primary and secondary CTAs
 * - Background images or videos
 * - Multiple layout variations
 * - Responsive design
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Hero_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_hero_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Hero Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates hero sections with compelling headlines, CTAs, and media. Supports multiple layout styles including centered, split, full-width, and minimal designs optimized for conversion.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'headline'             => array(
					'type'        => 'string',
					'description' => __( 'Main headline text', 'mcp-ai-wpoos-pro' ),
				),
				'subheadline'          => array(
					'type'        => 'string',
					'description' => __( 'Supporting subheadline text', 'mcp-ai-wpoos-pro' ),
				),
				'cta_primary'          => array(
					'type'        => 'string',
					'description' => __( 'Primary CTA button text', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Get Started',
				),
				'cta_secondary'        => array(
					'type'        => 'string',
					'description' => __( 'Secondary CTA button text (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'layout'               => array(
					'type'        => 'string',
					'description' => __( 'Hero layout style', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'centered', 'split', 'full-width', 'minimal', 'video-background' ),
					'default'     => 'centered',
				),
				'media_type'           => array(
					'type'        => 'string',
					'description' => __( 'Type of media to include', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'image', 'video', 'illustration', 'none' ),
					'default'     => 'image',
				),
				'color_scheme'         => array(
					'type'        => 'string',
					'description' => __( 'Color scheme preference', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'light', 'dark', 'gradient', 'brand' ),
					'default'     => 'light',
				),
				'include_trust_badges' => array(
					'type'        => 'boolean',
					'description' => __( 'Include trust badges or social proof', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'headline' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Hero section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create hero sections.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate and sanitize arguments.
		$headline      = isset( $arguments['headline'] ) ? sanitize_text_field( $arguments['headline'] ) : '';
		$subheadline   = isset( $arguments['subheadline'] ) ? sanitize_textarea_field( $arguments['subheadline'] ) : '';
		$cta_primary   = isset( $arguments['cta_primary'] ) ? sanitize_text_field( $arguments['cta_primary'] ) : 'Get Started';
		$cta_secondary = isset( $arguments['cta_secondary'] ) ? sanitize_text_field( $arguments['cta_secondary'] ) : '';
		$layout        = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'centered';
		$media_type    = isset( $arguments['media_type'] ) ? sanitize_text_field( $arguments['media_type'] ) : 'image';
		$color_scheme  = isset( $arguments['color_scheme'] ) ? sanitize_text_field( $arguments['color_scheme'] ) : 'light';
		$include_trust = isset( $arguments['include_trust_badges'] ) ? (bool) $arguments['include_trust_badges'] : false;

		if ( empty( $headline ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_required',
				__( 'Headline is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate hero section structure.
		$hero_section = array(
			'type'         => 'hero',
			'layout'       => $layout,
			'color_scheme' => $color_scheme,
			'content'      => array(
				'headline'    => $headline,
				'subheadline' => ! empty( $subheadline ) ? $subheadline : $this->generate_default_subheadline( $headline ),
				'cta_primary' => array(
					'text'  => $cta_primary,
					'style' => 'primary',
				),
			),
		);

		// Add secondary CTA if provided.
		if ( ! empty( $cta_secondary ) ) {
			$hero_section['content']['cta_secondary'] = array(
				'text'  => $cta_secondary,
				'style' => 'secondary',
			);
		}

		// Add media element.
		if ( 'none' !== $media_type ) {
			$hero_section['content']['media'] = $this->generate_media_element( $media_type, $layout );
		}

		// Add trust badges if requested.
		if ( $include_trust ) {
			$hero_section['content']['trust_badges'] = $this->generate_trust_badges();
		}

		// Add layout-specific styling.
		$hero_section['styling'] = $this->generate_styling( $layout, $color_scheme );

		// Log generation activity.
		$this->log_generation_activity( $user_id, $layout );

		return array(
			'success'      => true,
			'hero_section' => $hero_section,
			'summary'      => $this->generate_summary( $hero_section ),
			'html_snippet' => $this->generate_html_snippet( $hero_section ),
			'timestamp'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate default subheadline.
	 *
	 * @since 1.2.0
	 *
	 * @param string $headline Main headline.
	 * @return string Subheadline.
	 */
	private function generate_default_subheadline( $headline ) {
		return 'Discover how we can help you succeed';
	}

	/**
	 * Generate media element.
	 *
	 * @since 1.2.0
	 *
	 * @param string $media_type Media type.
	 * @param string $layout     Layout style.
	 * @return array Media element.
	 */
	private function generate_media_element( $media_type, $layout ) {
		$media = array(
			'type' => $media_type,
		);

		switch ( $media_type ) {
			case 'image':
				$media['properties'] = array(
					'placeholder'  => true,
					'alt'          => 'Hero image',
					'aspect_ratio' => 'split' === $layout ? '16:9' : '21:9',
					'optimization' => 'lazy-load',
				);
				break;

			case 'video':
				$media['properties'] = array(
					'autoplay' => true,
					'muted'    => true,
					'loop'     => true,
					'controls' => false,
				);
				break;

			case 'illustration':
				$media['properties'] = array(
					'style'     => 'modern',
					'animated'  => true,
					'svg_based' => true,
				);
				break;
		}

		return $media;
	}

	/**
	 * Generate trust badges.
	 *
	 * @since 1.2.0
	 *
	 * @return array Trust badges.
	 */
	private function generate_trust_badges() {
		return array(
			array(
				'type' => 'rating',
				'text' => '4.9/5 stars',
				'icon' => 'star',
			),
			array(
				'type' => 'social-proof',
				'text' => '10,000+ customers',
				'icon' => 'users',
			),
			array(
				'type' => 'guarantee',
				'text' => '30-day money back',
				'icon' => 'shield',
			),
		);
	}

	/**
	 * Generate styling rules.
	 *
	 * @since 1.2.0
	 *
	 * @param string $layout       Layout style.
	 * @param string $color_scheme Color scheme.
	 * @return array Styling rules.
	 */
	private function generate_styling( $layout, $color_scheme ) {
		$base_styles = array(
			'padding'   => array(
				'top'    => '100px',
				'bottom' => '100px',
			),
			'alignment' => 'center',
		);

		// Layout-specific adjustments.
		switch ( $layout ) {
			case 'centered':
				$base_styles['max_width']  = '800px';
				$base_styles['text_align'] = 'center';
				break;

			case 'split':
				$base_styles['layout']     = 'two-column';
				$base_styles['text_align'] = 'left';
				break;

			case 'full-width':
				$base_styles['max_width']  = '100%';
				$base_styles['text_align'] = 'center';
				break;

			case 'minimal':
				$base_styles['padding'] = array(
					'top'    => '60px',
					'bottom' => '60px',
				);
				break;
		}

		// Color scheme.
		$base_styles['colors'] = $this->get_color_scheme( $color_scheme );

		return $base_styles;
	}

	/**
	 * Get color scheme.
	 *
	 * @since 1.2.0
	 *
	 * @param string $scheme Scheme name.
	 * @return array Colors.
	 */
	private function get_color_scheme( $scheme ) {
		$schemes = array(
			'light'    => array(
				'background' => '#ffffff',
				'text'       => '#333333',
				'cta'        => '#0066cc',
			),
			'dark'     => array(
				'background' => '#1a1a1a',
				'text'       => '#ffffff',
				'cta'        => '#4d9fff',
			),
			'gradient' => array(
				'background' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
				'text'       => '#ffffff',
				'cta'        => '#ffffff',
			),
			'brand'    => array(
				'background' => '#0066cc',
				'text'       => '#ffffff',
				'cta'        => '#ffffff',
			),
		);

		return isset( $schemes[ $scheme ] ) ? $schemes[ $scheme ] : $schemes['light'];
	}

	/**
	 * Generate HTML snippet.
	 *
	 * @since 1.2.0
	 *
	 * @param array $hero_section Hero section data.
	 * @return string HTML.
	 */
	private function generate_html_snippet( $hero_section ) {
		$layout = isset( $hero_section['layout'] ) ? $hero_section['layout'] : 'centered';
		return '<!-- Hero Section: ' . esc_attr( $layout ) . ' -->' . "\n" .
				'<section class="hero-section hero-' . esc_attr( $layout ) . '">' . "\n" .
				'  <!-- Hero content here -->' . "\n" .
				'</section>';
	}

	/**
	 * Generate summary.
	 *
	 * @since 1.2.0
	 *
	 * @param array $hero_section Hero section data.
	 * @return string Summary.
	 */
	private function generate_summary( $hero_section ) {
		return sprintf(
			/* translators: 1: hero section layout, 2: media type */
			__( 'Generated %1$s hero section with headline, CTAs, and %2$s media.', 'mcp-ai-wpoos-pro' ),
			$hero_section['layout'],
			isset( $hero_section['content']['media']['type'] ) ? $hero_section['content']['media']['type'] : 'no'
		);
	}

	/**
	 * Log generation activity.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $layout  Layout style.
	 */
	private function log_generation_activity( $user_id, $layout ) {
		if ( ! function_exists( 'wp_mcp_ai_log_activity' ) ) {
			return;
		}

		wp_mcp_ai_log_activity(
			sprintf( 'Site Creator: Generated hero section - Layout: %s (User: %d)', $layout, $user_id ),
			'info'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'requires-capability',
			'consumes-tokens',
			'non-deterministic',
		);
	}
}
