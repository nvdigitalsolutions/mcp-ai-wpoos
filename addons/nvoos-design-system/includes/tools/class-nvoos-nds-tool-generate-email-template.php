<?php
/**
 * NV oOS Design System — Tool: Generate Email Template
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates a new email template from a prompt via the configured AI provider.
 *
 * Output is saved as a draft and must pass the structural/security gates;
 * activation remains a separate, human (or assistant-reviewed) step.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Generate_Email_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_generate_email_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Email Template (AI)', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a new production-ready email template from a natural-language description using the configured AI provider (OpenAI or Gemini; auto-detected from the NV oOS API keys). The template is built on accessibility-safe, table-based email HTML, uses the nds_email design tokens, and is saved as a DRAFT with an audit report — activate it separately with nds_set_active_email_template after review. Use when the user wants a new branded email layout, a welcome/newsletter/receipt template, or a variant of an existing template.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt'        => array(
					'type'        => 'string',
					'description' => __( 'Natural-language description of the desired template (e.g. "a warm welcome email for new subscribers with a single call-to-action button").', 'nvoos-design-system' ),
					'minLength'   => 1,
					'maxLength'   => 4000,
				),
				'base_template' => array(
					'type'        => 'string',
					'description' => __( 'Optional built-in template to use as a structural reference: "letterhead", "minimal", "transactional", "newsletter", or "ecommerce-receipt".', 'nvoos-design-system' ),
					'enum'        => array( '', 'letterhead', 'minimal', 'transactional', 'newsletter', 'ecommerce-receipt' ),
					'default'     => '',
				),
				'subject_hint'  => array(
					'type'        => 'string',
					'description' => __( 'Suggested subject line used for the <title> element.', 'nvoos-design-system' ),
					'maxLength'   => 190,
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$prompt        = isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '';
		$base_template = isset( $arguments['base_template'] ) ? sanitize_title( $arguments['base_template'] ) : '';
		$subject_hint  = isset( $arguments['subject_hint'] ) ? sanitize_text_field( $arguments['subject_hint'] ) : '';

		$result = NV_oOS_Design_System_Plugin::email_generator()->generate(
			$prompt,
			$base_template,
			$subject_hint,
			'use_current_tokens'
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'tool_error', $result->get_error_message() );
		}

		return $result;
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
		return array( 'pro', 'admin-surface', 'external-api', 'network-dependent', 'async', 'consumes-tokens', 'non-deterministic' );
	}
}
