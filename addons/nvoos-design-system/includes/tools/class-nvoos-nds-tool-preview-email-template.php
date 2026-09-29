<?php
/**
 * NV oOS Design System — Tool: Preview Email Template
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders any email template with sample content and the current design tokens.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Preview_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_preview_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Preview Email Template', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Render an NV oOS Design System email template with sample content and the current design tokens, returning the full HTML. Use this to review a template (including AI-generated drafts) before activating it.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug' => array(
					'type'        => 'string',
					'description' => __( 'Template slug to preview (e.g. "letterhead", "minimal", "transactional", "newsletter", "ecommerce-receipt", or an AI-generated slug). Defaults to the active template.', 'nvoos-design-system' ),
					'maxLength'   => 120,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$slug = isset( $arguments['slug'] ) && '' !== trim( (string) $arguments['slug'] )
			? sanitize_title( $arguments['slug'] )
			: NV_oOS_Design_System_Email_Template_Registry::get_active_slug();

		$template = NV_oOS_Design_System_Email_Template_Registry::get_active();

		if ( $slug !== NV_oOS_Design_System_Email_Template_Registry::get_active_slug() ) {
			$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );
			if ( $post instanceof WP_Post ) {
				$template = array(
					'html'     => $post->post_content,
					'slug'     => $slug,
					'settings' => NV_oOS_Design_System_Email_Template_CPT::get_settings( $post->ID ),
				);
			} else {
				$template = array(
					'html'     => NV_oOS_Design_System_Email_Template_Registry::get_builtin_html( $slug ),
					'slug'     => $slug,
					'settings' => array(),
				);
			}
		}

		if ( empty( $template['html'] ) ) {
			return new WP_Error(
				'tool_error',
				sprintf(
					/* translators: %s: template slug */
					__( 'Email template "%s" was not found.', 'nvoos-design-system' ),
					$slug
				)
			);
		}

		$sample_body = '<h2>' . esc_html__( 'Sample heading', 'nvoos-design-system' ) . '</h2><p>'
			. esc_html__( 'This is a preview rendered with the current design tokens and sample content.', 'nvoos-design-system' )
			. '</p><p>' . esc_html__( 'Best regards,', 'nvoos-design-system' ) . '<br>' . esc_html__( 'Your team', 'nvoos-design-system' ) . '</p>';

		$render_context = array(
			'subject'      => __( 'Sample subject line', 'nvoos-design-system' ),
			'body'         => wpautop( $sample_body ),
			'to_name'      => __( 'Valued Customer', 'nvoos-design-system' ),
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => home_url(),
			'site_domain'  => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'logo_url'     => isset( $template['settings']['logo_url'] ) ? $template['settings']['logo_url'] : '',
			'admin_email'  => (string) get_option( 'admin_email', '' ),
			'sub_brand'    => isset( $template['settings']['sub_brand'] ) ? $template['settings']['sub_brand'] : '',
			'confidential' => isset( $template['settings']['confidential'] ) ? $template['settings']['confidential'] : '',
			'year'         => gmdate( 'Y' ),
		);

		$renderer = new NV_oOS_Design_System_Email_Renderer();
		$html     = $renderer->render( $template['html'], $render_context, $slug );

		return array(
			'success'    => true,
			'slug'       => $slug,
			'html'       => $html,
			'size_bytes' => strlen( $html ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'admin-surface' );
	}
}
