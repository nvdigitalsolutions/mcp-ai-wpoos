<?php
/**
 * Tool for generating automated cover letters for regulatory submissions.
 *
 * Allows AI assistants to generate professional cover letters with
 * registration details and submission context.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates automated cover letters for submissions.
 */
class WP_MCP_AI_Tool_Generate_Cover_Letter implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_cover_letter';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Cover Letter', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a professional cover letter for regulatory submission with customizable content, recipient details, and submission type.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID for cover letter (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'submission_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of submission (optional, default: "new")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation', 'withdrawal' ),
					'default'     => 'new',
				),
				'recipient_name'  => array(
					'type'        => 'string',
					'description' => __( 'Recipient name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'recipient_title' => array(
					'type'        => 'string',
					'description' => __( 'Recipient title (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'custom_content'  => array(
					'type'        => 'string',
					'description' => __( 'Additional custom content to include (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'format'          => array(
					'type'        => 'string',
					'description' => __( 'Output format (optional, default: "pdf")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pdf', 'docx', 'html' ),
					'default'     => 'pdf',
				),
			),
			'required'             => array( 'registration_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate cover letters.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$submission_type = ! empty( $arguments['submission_type'] ) ? sanitize_text_field( $arguments['submission_type'] ) : 'new';
		$recipient_name  = ! empty( $arguments['recipient_name'] ) ? sanitize_text_field( $arguments['recipient_name'] ) : '';
		$recipient_title = ! empty( $arguments['recipient_title'] ) ? sanitize_text_field( $arguments['recipient_title'] ) : '';
		$custom_content  = ! empty( $arguments['custom_content'] ) ? wp_kses_post( $arguments['custom_content'] ) : '';
		$format          = ! empty( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'pdf';

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$product_id = absint( get_post_meta( $registration_id, 'product_id', true ) );
		$country    = get_post_meta( $registration_id, 'country', true );
		$authority  = get_post_meta( $registration_id, 'authority', true );
		$cos_number = get_post_meta( $registration_id, 'cos_number', true );

		// Get product details.
		$product_name = '';
		if ( $product_id ) {
			$product = get_post( $product_id );
			if ( $product ) {
				$product_name = $product->post_title;
			}
		}

		// Generate cover letter content.
		$date = gmdate( 'F j, Y' );

		$letter_content  = "{$date}\n\n";
		$letter_content .= $recipient_name ? "{$recipient_name}\n" : "To Whom It May Concern\n";
		$letter_content .= $recipient_title ? "{$recipient_title}\n" : '';
		$letter_content .= "{$authority}\n\n";
		$letter_content .= "Subject: {$submission_type} Registration Application for {$product_name}\n\n";
		$letter_content .= "Dear Sir/Madam,\n\n";
		$letter_content .= "We hereby submit our {$submission_type} registration application for the following product:\n\n";
		$letter_content .= "Product Name: {$product_name}\n";
		$letter_content .= "Country: {$country}\n";
		if ( $cos_number ) {
			$letter_content .= "COS Number: {$cos_number}\n";
		}
		$letter_content .= "\n";

		if ( $custom_content ) {
			$letter_content .= $custom_content . "\n\n";
		}

		$letter_content .= "Please find the complete dossier attached for your review and approval.\n\n";
		$letter_content .= "Sincerely,\n";
		$letter_content .= get_bloginfo( 'name' ) . "\n";

		// Save cover letter.
		$upload_dir = wp_upload_dir();
		$letter_dir = $upload_dir['basedir'] . '/cover-letters';
		$filename   = sprintf( 'cover-letter-%d-%s.%s', $registration_id, gmdate( 'YmdHis' ), $format === 'html' ? 'html' : 'txt' );
		$file_path  = $letter_dir . '/' . $filename;
		$file_url   = $upload_dir['baseurl'] . '/cover-letters/' . $filename;

		if ( ! file_exists( $letter_dir ) ) {
			wp_mkdir_p( $letter_dir );
		}

		file_put_contents( $file_path, $letter_content );

		return array(
			'success'         => true,
			'file_path'       => $file_path,
			'file_url'        => $file_url,
			'filename'        => $filename,
			'registration_id' => $registration_id,
			'submission_type' => $submission_type,
			'product_name'    => $product_name,
			'authority'       => $authority,
			'format'          => $format,
			'generated_at'    => current_time( 'mysql' ),
			'content_preview' => substr( $letter_content, 0, 200 ) . '...',
		);
	}
}
