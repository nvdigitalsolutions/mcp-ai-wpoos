<?php
/**
 * Build Testimonial Section Tool
 *
 * Creates customer testimonial sections with quotes, ratings, author info,
 * and various display layouts (slider, grid, masonry).
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build Testimonial Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Build_Testimonial_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'build_testimonial_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Build Testimonial Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates customer testimonial sections with quotes, ratings, and author info. Supports slider, grid, and masonry layouts for social proof.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Section title', 'mcp-ai-wpoos-pro' ),
					'default'     => 'What Our Customers Say',
				),
				'testimonial_count' => array(
					'type'        => 'integer',
					'description' => __( 'Number of testimonials to generate (2-6)', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
					'minimum'     => 2,
					'maximum'     => 6,
				),
				'layout'           => array(
					'type'        => 'string',
					'description' => __( 'Layout style', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'slider', 'grid', 'masonry' ),
					'default'     => 'slider',
				),
				'show_ratings'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include star ratings', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
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
	 * @return array|WP_Error Testimonial section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'What Our Customers Say';
		$count      = isset( $arguments['testimonial_count'] ) ? min( 6, max( 2, absint( $arguments['testimonial_count'] ) ) ) : 3;
		$layout     = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'slider';
		$show_rating = isset( $arguments['show_ratings'] ) ? (bool) $arguments['show_ratings'] : true;

		// Generate testimonials.
		$testimonials = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$testimonial = array(
				'quote'  => 'Outstanding service and exceptional results. Highly recommended!',
				'author' => 'Customer Name ' . $i,
				'role'   => 'Position Title',
			);

			if ( $show_rating ) {
				$testimonial['rating'] = 5;
			}

			$testimonials[] = $testimonial;
		}

		$testimonial_section = array(
			'type'         => 'testimonials',
			'title'        => $title,
			'layout'       => $layout,
			'show_ratings' => $show_rating,
			'testimonials' => $testimonials,
		);

		return array(
			'success'             => true,
			'testimonial_section' => $testimonial_section,
			'summary'             => sprintf( __( 'Generated testimonial section with %d testimonials in %s layout.', 'mcp-ai-wpoos-pro' ), $count, $layout ),
			'timestamp'           => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
