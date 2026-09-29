<?php
/**
 * NV oOS Design System — Tool: Audit Email Template
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the compliance audit (structure, security, WCAG-relevant accessibility,
 * dark mode, size) on any email template and returns the scorecard.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Audit_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_audit_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Audit Email Template', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audit an NV oOS Design System email template against the compliance checklist: structural and security BLOCK gates, WCAG 2.2 AA-relevant checks (alt text, contrast, headings, language), dark-mode support, and the 100 KB size budget. Returns a scorecard with per-check results. Use before activating a template.', 'nvoos-design-system' );
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
					'description' => __( 'Template slug to audit. Defaults to the active template.', 'nvoos-design-system' ),
					'maxLength'   => 120,
				),
				'html' => array(
					'type'        => 'string',
					'description' => __( 'Raw template HTML to audit directly (alternative to slug; useful before saving a generated template).', 'nvoos-design-system' ),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$scope = 'full';
		$html  = '';

		if ( isset( $arguments['html'] ) && '' !== trim( (string) $arguments['html'] ) ) {
			$html = (string) $arguments['html'];
		} else {
			$slug = isset( $arguments['slug'] ) && '' !== trim( (string) $arguments['slug'] )
				? sanitize_title( $arguments['slug'] )
				: NV_oOS_Design_System_Email_Template_Registry::get_active_slug();

			$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( $slug );

			if ( $post instanceof WP_Post ) {
				$html  = $post->post_content;
				$scope = NV_oOS_Design_System_Email_Template_CPT::get_scope( $post->ID );
			} else {
				$html = NV_oOS_Design_System_Email_Template_Registry::get_builtin_html( $slug );
			}

			if ( '' === trim( $html ) ) {
				return new WP_Error(
					'tool_error',
					sprintf(
						/* translators: %s: template slug */
						__( 'Email template "%s" was not found.', 'nvoos-design-system' ),
						$slug
					)
				);
			}
		}

		$auditor = NV_oOS_Design_System_Plugin::email_auditor();
		$audit   = $auditor->audit( $html, array(), $scope );

		return array(
			'success'         => true,
			'audit'           => $audit,
			'activation_note' => $audit['passes_required']
				? __( 'Template passes the required gates. Remaining warnings should be reviewed before activation.', 'nvoos-design-system' )
				: __( 'Template fails required gates — do not activate without fixing the errors.', 'nvoos-design-system' ),
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
