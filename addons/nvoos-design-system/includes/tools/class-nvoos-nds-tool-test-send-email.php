<?php
/**
 * NV oOS Design System — Tool: Test Send Email
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
 * Sends a test email through the active template.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Tool_Test_Send_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'nds_test_send_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Test Send Email', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Send a test email wrapped in the active NV oOS Design System template. The default recipient is the current admin user. Use this to verify the wrapper renders correctly after changing templates or tokens.', 'nvoos-design-system' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'to'      => array(
					'type'        => 'string',
					'description' => __( 'Recipient email address. Defaults to the current admin user email.', 'nvoos-design-system' ),
					'maxLength'   => 190,
				),
				'subject' => array(
					'type'        => 'string',
					'description' => __( 'Custom subject line. Defaults to a standard test subject.', 'nvoos-design-system' ),
					'maxLength'   => 190,
				),
				'message' => array(
					'type'        => 'string',
					'description' => __( 'Custom message body. Defaults to a standard test message.', 'nvoos-design-system' ),
					'maxLength'   => 5000,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user = wp_get_current_user();

		$to      = isset( $arguments['to'] ) ? sanitize_email( $arguments['to'] ) : '';
		$subject = isset( $arguments['subject'] ) ? sanitize_text_field( $arguments['subject'] ) : '';
		$message = isset( $arguments['message'] ) ? wp_kses_post( $arguments['message'] ) : '';

		if ( '' === $to ) {
			$to = $current_user && ! empty( $current_user->user_email ) ? $current_user->user_email : (string) get_option( 'admin_email', '' );
		}

		if ( empty( $to ) || ! is_email( $to ) ) {
			return new WP_Error( 'tool_error', __( 'A valid recipient email address is required.', 'nvoos-design-system' ) );
		}

		if ( '' === $subject ) {
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Email template test', 'nvoos-design-system' ), get_bloginfo( 'name' ) );
		}

		if ( '' === $message ) {
			$message = __( 'This is a test message sent through the active NV oOS Design System email template. If you can read this, the template wrapper is working.', 'nvoos-design-system' );
		}

		$sent = wp_mail( $to, $subject, $message );

		if ( ! $sent ) {
			return new WP_Error(
				'tool_error',
				__( 'wp_mail() reported a failure. Check the site mail/SMTP configuration and retry.', 'nvoos-design-system' )
			);
		}

		return array(
			'success'  => true,
			'to'       => $to,
			'subject'  => $subject,
			'template' => NV_oOS_Design_System_Email_Template_Registry::get_active_slug(),
			'message'  => __( 'Test email sent through the active template.', 'nvoos-design-system' ),
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
		return array( 'pro', 'admin-surface', 'external-api', 'network-dependent' );
	}
}
