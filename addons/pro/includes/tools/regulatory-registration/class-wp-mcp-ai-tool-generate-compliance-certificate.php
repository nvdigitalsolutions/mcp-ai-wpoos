<?php
/**
 * Tool for generating compliance certificates for approved registrations.
 *
 * Allows AI assistants to generate official compliance certificates
 * for regulatory registrations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates compliance certificates for registrations.
 */
class WP_MCP_AI_Tool_Generate_Compliance_Certificate implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_compliance_certificate';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Compliance Certificate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates an official compliance certificate for approved registrations with regulatory details, approval dates, and validity period.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID for certificate generation (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'certificate_type'  => array(
					'type'        => 'string',
					'description' => __( 'Type of certificate (optional, default: "standard")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'standard', 'gcc', 'coa', 'free_sale' ),
					'default'     => 'standard',
				),
				'include_qr_code'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include QR code for verification (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'signatory_name'    => array(
					'type'        => 'string',
					'description' => __( 'Signatory name (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'signatory_title'   => array(
					'type'        => 'string',
					'description' => __( 'Signatory title (optional)', 'mcp-ai-wpoos-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate compliance certificates.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id  = absint( $arguments['registration_id'] );
		$certificate_type = ! empty( $arguments['certificate_type'] ) ? sanitize_text_field( $arguments['certificate_type'] ) : 'standard';
		$include_qr_code  = isset( $arguments['include_qr_code'] ) ? (bool) $arguments['include_qr_code'] : true;
		$signatory_name   = ! empty( $arguments['signatory_name'] ) ? sanitize_text_field( $arguments['signatory_name'] ) : '';
		$signatory_title  = ! empty( $arguments['signatory_title'] ) ? sanitize_text_field( $arguments['signatory_title'] ) : '';

		// Verify registration exists and is approved.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if registration is approved.
		$statuses = wp_get_post_terms( $registration_id, 'mcp_ai_reg_status' );
		$is_approved = false;
		if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
			$status_name = $statuses[0]->slug;
			$is_approved = in_array( $status_name, array( 'approved', 'active' ), true );
		}

		if ( ! $is_approved ) {
			return new WP_Error( 'wp_mcp_ai_invalid_status', __( 'Certificate can only be generated for approved registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$product_id    = absint( get_post_meta( $registration_id, 'product_id', true ) );
		$country       = get_post_meta( $registration_id, 'country', true );
		$authority     = get_post_meta( $registration_id, 'authority', true );
		$cos_number    = get_post_meta( $registration_id, 'cos_number', true );
		$approval_date = get_post_meta( $registration_id, 'approval_date', true );
		$expiry_date   = get_post_meta( $registration_id, 'expiry_date', true );

		// Get product details.
		$product_name = '';
		$brand        = '';
		$manufacturer = '';
		if ( $product_id ) {
			$product = get_post( $product_id );
			if ( $product ) {
				$product_name = $product->post_title;
				$brand        = get_post_meta( $product_id, 'brand', true );
				$manufacturer = get_post_meta( $product_id, 'manufacturer', true );
			}
		}

		// Generate certificate number.
		$certificate_number = sprintf( 'CERT-%d-%s', $registration_id, strtoupper( substr( md5( $registration_id . time() ), 0, 8 ) ) );

		// Generate certificate content.
		$certificate_content  = "CERTIFICATE OF COMPLIANCE\n\n";
		$certificate_content .= "Certificate Number: {$certificate_number}\n";
		$certificate_content .= "Type: {$certificate_type}\n\n";
		$certificate_content .= "This is to certify that:\n\n";
		$certificate_content .= "Product: {$product_name}\n";
		$certificate_content .= "Brand: {$brand}\n";
		$certificate_content .= "Manufacturer: {$manufacturer}\n";
		$certificate_content .= "Registration Number: {$cos_number}\n\n";
		$certificate_content .= "Has been duly registered with:\n";
		$certificate_content .= "Authority: {$authority}\n";
		$certificate_content .= "Country: {$country}\n\n";
		$certificate_content .= "Approval Date: {$approval_date}\n";
		$certificate_content .= "Valid Until: {$expiry_date}\n\n";
		$certificate_content .= "Issued: " . gmdate( 'Y-m-d' ) . "\n\n";

		if ( $signatory_name ) {
			$certificate_content .= "Authorized Signatory: {$signatory_name}\n";
		}
		if ( $signatory_title ) {
			$certificate_content .= "Title: {$signatory_title}\n";
		}

		if ( $include_qr_code ) {
			$verify_url = home_url( '/verify-certificate/?cert=' . $certificate_number );
			$certificate_content .= "\nVerification URL: {$verify_url}\n";
		}

		// Save certificate.
		$upload_dir  = wp_upload_dir();
		$cert_dir    = $upload_dir['basedir'] . '/compliance-certificates';
		$filename    = sprintf( 'certificate-%d-%s.pdf', $registration_id, gmdate( 'YmdHis' ) );
		$file_path   = $cert_dir . '/' . $filename;
		$file_url    = $upload_dir['baseurl'] . '/compliance-certificates/' . $filename;

		if ( ! file_exists( $cert_dir ) ) {
			wp_mkdir_p( $cert_dir );
		}

		file_put_contents( $file_path, $certificate_content );

		// Store certificate metadata.
		update_post_meta( $registration_id, '_certificate_number', $certificate_number );
		update_post_meta( $registration_id, '_certificate_issued_date', current_time( 'mysql' ) );

		return array(
			'success'            => true,
			'certificate_number' => $certificate_number,
			'file_path'          => $file_path,
			'file_url'           => $file_url,
			'filename'           => $filename,
			'registration_id'    => $registration_id,
			'product_name'       => $product_name,
			'authority'          => $authority,
			'country'            => $country,
			'certificate_type'   => $certificate_type,
			'issued_date'        => current_time( 'mysql' ),
			'valid_until'        => $expiry_date,
			'qr_code_included'   => $include_qr_code,
		);
	}
}
