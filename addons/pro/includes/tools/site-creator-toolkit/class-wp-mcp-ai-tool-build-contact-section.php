<?php
/**
 * Build Contact Section Tool
 *
 * Creates contact sections with forms, location info, maps,
 * and social media links.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build Contact Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Build_Contact_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'build_contact_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Build Contact Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates contact sections with forms, location info, maps, and social media links. Supports various layouts and integrations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'          => array(
					'type'        => 'string',
					'description' => __( 'Section title', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Get in Touch',
				),
				'include_form'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include contact form', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_map'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include location map', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_social' => array(
					'type'        => 'boolean',
					'description' => __( 'Include social media links', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'layout'         => array(
					'type'        => 'string',
					'description' => __( 'Section layout', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'split', 'stacked', 'sidebar' ),
					'default'     => 'split',
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
	 * @return array|WP_Error Contact section data or error.
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
		$title          = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Get in Touch';
		$include_form   = isset( $arguments['include_form'] ) ? (bool) $arguments['include_form'] : true;
		$include_map    = isset( $arguments['include_map'] ) ? (bool) $arguments['include_map'] : true;
		$include_social = isset( $arguments['include_social'] ) ? (bool) $arguments['include_social'] : true;
		$layout         = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'split';

		// Generate contact section.
		$contact_section = array(
			'type'    => 'contact',
			'title'   => $title,
			'layout'  => $layout,
			'content' => array(),
		);

		// Add contact info.
		$contact_section['content']['contact_info'] = array(
			'address' => '123 Main Street, City, State 12345',
			'phone'   => '+1 (555) 123-4567',
			'email'   => 'contact@example.com',
		);

		// Add form if requested.
		if ( $include_form ) {
			$contact_section['content']['form'] = $this->generate_contact_form();
		}

		// Add map if requested.
		if ( $include_map ) {
			$contact_section['content']['map'] = array(
				'type'        => 'embed',
				'provider'    => 'google-maps',
				'coordinates' => array(
					'lat' => 0,
					'lng' => 0,
				),
			);
		}

		// Add social links if requested.
		if ( $include_social ) {
			$contact_section['content']['social'] = array(
				array(
					'platform' => 'facebook',
					'url'      => '#',
				),
				array(
					'platform' => 'twitter',
					'url'      => '#',
				),
				array(
					'platform' => 'linkedin',
					'url'      => '#',
				),
				array(
					'platform' => 'instagram',
					'url'      => '#',
				),
			);
		}

		return array(
			'success'         => true,
			'contact_section' => $contact_section,
			/* translators: %s: layout type */
			'summary'         => sprintf( __( 'Generated %s contact section with form and map.', 'mcp-ai-wpoos-pro' ), $layout ),
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate contact form.
	 *
	 * @since 1.2.0
	 *
	 * @return array Contact form.
	 */
	private function generate_contact_form() {
		return array(
			'fields'  => array(
				array(
					'type'        => 'text',
					'name'        => 'name',
					'label'       => 'Your Name',
					'placeholder' => 'John Doe',
					'required'    => true,
				),
				array(
					'type'        => 'email',
					'name'        => 'email',
					'label'       => 'Email Address',
					'placeholder' => 'john@example.com',
					'required'    => true,
				),
				array(
					'type'        => 'tel',
					'name'        => 'phone',
					'label'       => 'Phone Number',
					'placeholder' => '(555) 123-4567',
					'required'    => false,
				),
				array(
					'type'        => 'textarea',
					'name'        => 'message',
					'label'       => 'Message',
					'placeholder' => 'Your message here...',
					'required'    => true,
					'rows'        => 5,
				),
			),
			'submit'  => array(
				'text' => 'Send Message',
			),
			'options' => array(
				'privacy_policy'  => true,
				'spam_protection' => 'recaptcha',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
