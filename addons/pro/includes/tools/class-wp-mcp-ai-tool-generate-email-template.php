<?php
/**
 * Tool for generating responsive email templates using MJML.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate responsive email templates using MJML.
 *
 * This tool leverages MJML to provide:
 * - Mobile-first responsive email design
 * - Cross-client compatibility (Gmail, Outlook, Apple Mail, etc.)
 * - Component-based email layout
 * - Professional marketing email templates
 * - Transactional email generation
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Email Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate responsive email templates using MJML. Create professional, mobile-friendly emails with cross-client compatibility. Perfect for newsletters, marketing campaigns, transactional emails, and notifications.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'            =>  'object',
			'properties'      =>  array(
				'template_type'   =>  array(
					'type'            =>  'string',
					'enum'            =>  array( 'newsletter', 'marketing', 'transactional', 'notification', 'custom' ),
					'description'     =>  __( 'Type of email template to generate', 'mcp-ai-wpoos-pro' ),
					'default'         =>  'newsletter',
				),
				'subject'         =>  array(
					'type'            =>  'string',
					'description'     =>  __( 'Email subject line', 'mcp-ai-wpoos-pro' ),
				),
				'preview_text'    =>  array(
					'type'            =>  'string',
					'description'     =>  __( 'Preview text shown in email clients', 'mcp-ai-wpoos-pro' ),
				),
				'components'      =>  array(
					'type'            =>  'array',
					'description'     =>  __( 'Array of email components (header, text, button, image, etc.)', 'mcp-ai-wpoos-pro' ),
					'items'           =>  array(
						'type'            =>  'object',
						'properties'      =>  array(
							'type'            =>  array( 'type' => 'string' ),
							'content'         =>  array( 'type' => 'string' ),
							'attributes'      =>  array( 'type' => 'object' ),
						),
					),
				),
				'branding'        =>  array(
					'type'            =>  'object',
					'description'     =>  __( 'Branding options (logo, colors, fonts)', 'mcp-ai-wpoos-pro' ),
					'properties'      =>  array(
						'logo_url'        =>  array( 'type' => 'string' ),
						'primary_color'   =>  array( 'type' => 'string' ),
						'secondary_color' =>  array( 'type' => 'string' ),
						'font_family'     =>  array( 'type' => 'string' ),
						'company_name'    =>  array( 'type' => 'string' ),
						'company_address' =>  array( 'type' => 'string' ),
					),
				),
				'footer_links'    =>  array(
					'type'            =>  'array',
					'description'     =>  __( 'Footer links (unsubscribe, privacy policy, etc.)', 'mcp-ai-wpoos-pro' ),
					'items'           =>  array(
						'type'            =>  'object',
						'properties'      =>  array(
							'text'            =>  array( 'type' => 'string' ),
							'url'             =>  array( 'type' => 'string' ),
						),
					),
				),
				'container_width' =>  array(
					'type'            =>  'string',
					'description'     =>  __( 'Email container width (default: 600px)', 'mcp-ai-wpoos-pro' ),
					'default'         =>  '600px',
				),
				'output_format'   =>  array(
					'type'            =>  'string',
					'enum'            =>  array( 'html', 'mjml', 'both' ),
					'description'     =>  __( 'Output format: html (compiled), mjml (source), or both', 'mcp-ai-wpoos-pro' ),
					'default'         =>  'html',
				),
				'minify'          =>  array(
					'type'            =>  'boolean',
					'description'     =>  __( 'Minify HTML output for smaller file size', 'mcp-ai-wpoos-pro' ),
					'default'         =>  false,
				),
			),
			'required'        =>  array( 'components' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation.
			'requires-capability',  // Requires edit_posts capability.
			'external-dependency',  // Requires MJML (Node.js).
			'idempotent',           // Same input produces same output.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate components.
		if ( empty( $arguments['components'] ) || ! is_array( $arguments['components'] ) ) {
			return array(
				'success' =>  false,
				'error'   =>  __( 'Email components are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if MJML service is available.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-mjml-service.php';
		$mjml_service = new WP_MCP_AI_MJML_Service();

		if ( ! $mjml_service->is_available() ) {
			return array(
				'success' =>  false,
				'error'   =>  __( 'MJML is not available. Please ensure Node.js and MJML package are installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Build template options.
		$template_options = array(
			'title'            =>  isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '',
			'preview_text'     =>  isset( $arguments['preview_text'] ) ? sanitize_text_field( $arguments['preview_text'] ) : '',
			'container_width'  =>  isset( $arguments['container_width'] ) ? sanitize_text_field( $arguments['container_width'] ) : '600px',
			'font_family'      =>  'Arial, Helvetica, sans-serif',
			'background_color' =>  '#f4f4f4',
		);

		// Add branding if provided.
		if ( isset( $arguments['branding'] ) && is_array( $arguments['branding'] ) ) {
			$branding = $arguments['branding'];
			if ( isset( $branding['font_family'] ) ) {
				$template_options['font_family'] = sanitize_text_field( $branding['font_family'] );
			}
		}

		// Generate MJML template.
		$mjml = $mjml_service->generate_template( $arguments['components'], $template_options );

		if ( is_wp_error( $mjml ) ) {
			return array(
				'success' =>  false,
				'error'   =>  sprintf(
					/* translators: %s: error message */
					__( 'MJML generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$mjml->get_error_message()
				),
			);
		}

		// Add branding header if provided.
		if ( isset( $arguments['branding'] ) && is_array( $arguments['branding'] ) ) {
			$mjml = $this->add_branding_to_mjml( $mjml, $arguments['branding'] );
		}

		// Add footer if footer_links provided.
		if ( isset( $arguments['footer_links'] ) && is_array( $arguments['footer_links'] ) ) {
			$mjml = $this->add_footer_to_mjml( $mjml, $arguments['footer_links'], $arguments['branding'] ?? array() );
		}

		$output_format = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'html';

		// Return MJML source if requested.
		if ( 'mjml' === $output_format ) {
			return array(
				'success'       =>  true,
				'message'       =>  __( 'Email template generated successfully.', 'mcp-ai-wpoos-pro' ),
				'template_type' =>  isset( $arguments['template_type'] ) ? $arguments['template_type'] : 'custom',
				'mjml'          =>  $mjml,
			);
		}

		// Compile MJML to HTML.
		$compile_options = array(
			'minify'          =>  isset( $arguments['minify'] ) ? (bool) $arguments['minify'] : false,
			'beautify'        =>  ! ( isset( $arguments['minify'] ) && $arguments['minify'] ),
			'validationLevel' =>  'soft',
		);

		$html = $mjml_service->compile( $mjml, $compile_options );

		if ( is_wp_error( $html ) ) {
			return array(
				'success' =>  false,
				'error'   =>  sprintf(
					/* translators: %s: error message */
					__( 'MJML compilation failed: %s', 'mcp-ai-wpoos-pro' ),
					$html->get_error_message()
				),
				'mjml'    =>  $mjml, // Return MJML for debugging.
			);
		}

		$result = array(
			'success'       =>  true,
			'message'       =>  __( 'Email template generated successfully.', 'mcp-ai-wpoos-pro' ),
			'template_type' =>  isset( $arguments['template_type'] ) ? $arguments['template_type'] : 'custom',
			'html'          =>  $html,
			'subject'       =>  isset( $arguments['subject'] ) ? $arguments['subject'] : null,
			'preview_text'  =>  isset( $arguments['preview_text'] ) ? $arguments['preview_text'] : null,
		);

		// Include MJML source if both formats requested.
		if ( 'both' === $output_format ) {
			$result['mjml'] = $mjml;
		}

		// Add email preview HTML to response.
		return $this->add_email_html_to_response( $result );
	}

	/**
	 * Add branding header to MJML template
	 *
	 * @param string $mjml     MJML markup.
	 * @param array  $branding Branding options.
	 * @return string Modified MJML markup.
	 */
	private function add_branding_to_mjml( $mjml, $branding ) {
		// Insert logo header before first section.
		if ( isset( $branding['logo_url'] ) && ! empty( $branding['logo_url'] ) ) {
			$logo_section  = '<mj-section background-color="#ffffff" padding="20px 0">';
			$logo_section .= '<mj-column>';
			$logo_section .= '<mj-image src="' . esc_url( $branding['logo_url'] ) . '" alt="' . esc_attr( isset( $branding['company_name'] ) ? $branding['company_name'] : 'Logo' ) . '" width="150px" align="center" />';
			$logo_section .= '</mj-column>';
			$logo_section .= '</mj-section>';

			$mjml = str_replace( '<mj-body>', '<mj-body>' . $logo_section, $mjml );
		}

		return $mjml;
	}

	/**
	 * Add footer to MJML template
	 *
	 * @param string $mjml         MJML markup.
	 * @param array  $footer_links Footer links.
	 * @param array  $branding     Branding options.
	 * @return string Modified MJML markup.
	 */
	private function add_footer_to_mjml( $mjml, $footer_links, $branding ) {
		$footer  = '<mj-section background-color="#333333" padding="20px">';
		$footer .= '<mj-column>';

		// Company info.
		if ( isset( $branding['company_name'] ) ) {
			$footer .= '<mj-text color="#ffffff" align="center" font-size="12px">';
			$footer .= '<strong>' . esc_html( $branding['company_name'] ) . '</strong>';
			if ( isset( $branding['company_address'] ) ) {
				$footer .= '<br />' . esc_html( $branding['company_address'] );
			}
			$footer .= '</mj-text>';
		}

		// Footer links.
		if ( ! empty( $footer_links ) ) {
			$footer    .= '<mj-text color="#ffffff" align="center" font-size="12px">';
			$link_texts = array();
			foreach ( $footer_links as $link ) {
				if ( isset( $link['text'] ) && isset( $link['url'] ) ) {
					$link_texts[] = '<a href="' . esc_url( $link['url'] ) . '" style="color: #ffffff;">' . esc_html( $link['text'] ) . '</a>';
				}
			}
			$footer .= implode( ' | ', $link_texts );
			$footer .= '</mj-text>';
		}

		$footer .= '</mj-column>';
		$footer .= '</mj-section>';

		// Insert footer before closing body tag.
		$mjml = str_replace( '</mj-body>', $footer . '</mj-body>', $mjml );

		return $mjml;
	}
}
