<?php
/**
 * NV oOS Design System — Tool: Set Active Email Template
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The NV oOS tool contracts (interface, capability-flags interface, and the
// default-capability trait) live in the base plugin. Guard so the addon stays
// loadable — and its autoloader stays safe — when it runs standalone without
// the base plugin active.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) || ! trait_exists( 'WP_MCP_AI_Tool_Default_Capability' ) ) {
	return;
}

/**
 * Activates an email template, gated by publish status and the audit.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Set_Active_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_set_active_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Set Active Email Template', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Activate an NV oOS Design System email template so all outgoing WordPress emails are wrapped in it. Draft templates and templates failing the required audit gates are refused unless force=true is passed (the override is returned in the audit log). Use after reviewing a template with nds_audit_email_template and nds_preview_email_template.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'slug'  => array(
					'type'        => 'string',
					'description' => __( 'Template slug to activate (e.g. "letterhead", "minimal", or an AI-generated slug).', 'nvoos-design-system' ),
					'minLength'   => 1,
					'maxLength'   => 120,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => __( 'Bypass the draft/audit gates. Only use when the warnings are understood and accepted.', 'nvoos-design-system' ),
					'default'     => false,
				),
			),
			'required'             => array( 'slug' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$slug  = isset( $arguments['slug'] ) ? sanitize_title( $arguments['slug'] ) : '';
		$force = ! empty( $arguments['force'] );

		if ( '' === $slug ) {
			return new WP_Error( 'tool_error', __( 'A template slug is required.', 'nvoos-design-system' ) );
		}

		$result = NV_oOS_Design_System_Email_Template_Registry::set_active( $slug, $force );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'tool_error', $result->get_error_message() );
		}

		return array(
			'success' => true,
			'slug'    => $slug,
			'forced'  => $force,
			'message' => __( 'Email template activated. All outgoing WordPress emails will now use this template.', 'nvoos-design-system' ),
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
		return array( 'pro', 'admin-surface', 'destructive' );
	}
}
