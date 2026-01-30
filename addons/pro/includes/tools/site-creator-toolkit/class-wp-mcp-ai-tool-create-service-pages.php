<?php
/**
 * Create Service Pages Tool
 *
 * Generates service/product pages with descriptions, benefits, pricing tables,
 * FAQs, and strategic CTAs optimized for conversion.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Service Pages Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Service_Pages implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'create_service_pages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Service Pages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates service or product pages with descriptions, benefits, pricing tables, FAQs, and strategic CTAs. Creates conversion-optimized pages for showcasing offerings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'service_name' => array(
					'type'        => 'string',
					'description' => __( 'Service or product name', 'mcp-ai-wpoos-pro' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Service description', 'mcp-ai-wpoos-pro' ),
				),
				'benefits'     => array(
					'type'        => 'array',
					'description' => __( 'Key benefits (max 6)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 6,
				),
				'pricing'      => array(
					'type'        => 'object',
					'description' => __( 'Pricing information', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'price'    => array( 'type' => 'string' ),
						'currency' => array( 'type' => 'string' ),
						'period'   => array( 'type' => 'string' ),
					),
				),
				'include_faq'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include FAQ section', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'cta_text'     => array(
					'type'        => 'string',
					'description' => __( 'Call-to-action button text', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Get Started',
				),
			),
			'required'             => array( 'service_name', 'description' ),
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
	 * @return array|WP_Error Service page data or error.
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
		$service_name = isset( $arguments['service_name'] ) ? sanitize_text_field( $arguments['service_name'] ) : '';
		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$benefits     = isset( $arguments['benefits'] ) && is_array( $arguments['benefits'] ) ?
			array_slice( array_map( 'sanitize_text_field', $arguments['benefits'] ), 0, 6 ) : array();
		$pricing      = isset( $arguments['pricing'] ) && is_array( $arguments['pricing'] ) ? $arguments['pricing'] : array();
		$include_faq  = isset( $arguments['include_faq'] ) ? (bool) $arguments['include_faq'] : true;
		$cta_text     = isset( $arguments['cta_text'] ) ? sanitize_text_field( $arguments['cta_text'] ) : 'Get Started';

		if ( empty( $service_name ) || empty( $description ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Service name and description are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate service page structure.
		$service_page = array(
			'title'    => $service_name,
			'sections' => array(
				$this->generate_hero_section( $service_name, $description, $cta_text ),
				$this->generate_overview_section( $service_name, $description ),
			),
		);

		if ( ! empty( $benefits ) ) {
			$service_page['sections'][] = $this->generate_benefits_section( $benefits );
		}

		if ( ! empty( $pricing ) ) {
			$service_page['sections'][] = $this->generate_pricing_section( $pricing );
		}

		$service_page['sections'][] = $this->generate_process_section( $service_name );

		if ( $include_faq ) {
			$service_page['sections'][] = $this->generate_faq_section( $service_name );
		}

		$service_page['sections'][] = $this->generate_cta_section( $service_name, $cta_text );

		return array(
			'success'      => true,
			'service_page' => $service_page,
			/* translators: 1: service name, 2: number of sections */
			'summary'      => sprintf( __( 'Generated service page for %1$s with %2$d sections.', 'mcp-ai-wpoos-pro' ), $service_name, count( $service_page['sections'] ) ),
			'timestamp'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate hero section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $service_name Service name.
	 * @param string $description  Description.
	 * @param string $cta_text     CTA text.
	 * @return array Hero section.
	 */
	private function generate_hero_section( $service_name, $description, $cta_text ) {
		return array(
			'type'    => 'hero',
			'content' => array(
				'headline'    => $service_name,
				'description' => $description,
				'cta'         => array( 'text' => $cta_text ),
			),
		);
	}

	/**
	 * Generate overview section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $service_name Service name.
	 * @param string $description  Description.
	 * @return array Overview section.
	 */
	private function generate_overview_section( $service_name, $description ) {
		return array(
			'type'    => 'overview',
			'content' => array(
				'title'   => 'About ' . $service_name,
				'content' => $description,
			),
		);
	}

	/**
	 * Generate benefits section.
	 *
	 * @since 1.2.0
	 *
	 * @param array $benefits Benefits list.
	 * @return array Benefits section.
	 */
	private function generate_benefits_section( $benefits ) {
		return array(
			'type'    => 'benefits',
			'content' => array(
				'title'    => 'Key Benefits',
				'benefits' => array_map(
					function ( $benefit ) {
						return array(
							'title'       => $benefit,
							'description' => 'Experience the advantage of ' . strtolower( $benefit ),
						);
					},
					$benefits
				),
			),
		);
	}

	/**
	 * Generate pricing section.
	 *
	 * @since 1.2.0
	 *
	 * @param array $pricing Pricing info.
	 * @return array Pricing section.
	 */
	private function generate_pricing_section( $pricing ) {
		return array(
			'type'    => 'pricing',
			'content' => array(
				'title'    => 'Pricing',
				'price'    => isset( $pricing['price'] ) ? sanitize_text_field( $pricing['price'] ) : '',
				'currency' => isset( $pricing['currency'] ) ? sanitize_text_field( $pricing['currency'] ) : 'USD',
				'period'   => isset( $pricing['period'] ) ? sanitize_text_field( $pricing['period'] ) : 'month',
			),
		);
	}

	/**
	 * Generate process section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $service_name Service name.
	 * @return array Process section.
	 */
	private function generate_process_section( $service_name ) {
		return array(
			'type'    => 'process',
			'content' => array(
				'title' => 'How It Works',
				'steps' => array(
					array(
						'title'       => 'Step 1: Consultation',
						'description' => 'We understand your needs',
					),
					array(
						'title'       => 'Step 2: Planning',
						'description' => 'We create a tailored strategy',
					),
					array(
						'title'       => 'Step 3: Execution',
						'description' => 'We deliver results',
					),
				),
			),
		);
	}

	/**
	 * Generate FAQ section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $service_name Service name.
	 * @return array FAQ section.
	 */
	private function generate_faq_section( $service_name ) {
		return array(
			'type'    => 'faq',
			'content' => array(
				'title' => 'Frequently Asked Questions',
				'faqs'  => array(
					array(
						'question' => "What is included in {$service_name}?",
						'answer'   => 'Our service includes comprehensive support and all essential features.',
					),
					array(
						'question' => 'How long does it take?',
						'answer'   => 'Typical delivery time is 2-4 weeks depending on scope.',
					),
					array(
						'question' => 'Do you offer support?',
						'answer'   => 'Yes, we provide ongoing support and maintenance.',
					),
				),
			),
		);
	}

	/**
	 * Generate CTA section.
	 *
	 * @since 1.2.0
	 *
	 * @param string $service_name Service name.
	 * @param string $cta_text     CTA text.
	 * @return array CTA section.
	 */
	private function generate_cta_section( $service_name, $cta_text ) {
		return array(
			'type'    => 'cta',
			'content' => array(
				'title'  => "Ready to Get Started with {$service_name}?",
				'button' => array( 'text' => $cta_text ),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'consumes-tokens', 'non-deterministic' );
	}
}
