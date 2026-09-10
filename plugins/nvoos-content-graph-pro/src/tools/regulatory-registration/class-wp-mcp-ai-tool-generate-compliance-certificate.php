<?php
/**
 * WP_MCP_AI_Tool_Generate_Compliance_Certificate (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'Generate Compliance Certificate', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates an official compliance certificate for approved registrations with regulatory details, approval dates, and validity period.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID for certificate generation (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'certificate_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of certificate (optional, default: "standard")', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'standard', 'gcc', 'coa', 'free_sale' ),
					'default'     => 'standard',
				),
				'include_qr_code'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include QR code for verification (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'signatory_name'   => array(
					'type'        => 'string',
					'description' => __( 'Signatory name (optional)', 'nvoos-content-graph-pro' ),
				),
				'signatory_title'  => array(
					'type'        => 'string',
					'description' => __( 'Signatory title (optional)', 'nvoos-content-graph-pro' ),
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate compliance certificates.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$registration_id  = absint( $arguments['registration_id'] );
		$certificate_type = ! empty( $arguments['certificate_type'] ) ? sanitize_text_field( $arguments['certificate_type'] ) : 'standard';
		$include_qr_code  = isset( $arguments['include_qr_code'] ) ? (bool) $arguments['include_qr_code'] : true;
		$signatory_name   = ! empty( $arguments['signatory_name'] ) ? sanitize_text_field( $arguments['signatory_name'] ) : '';
		$signatory_title  = ! empty( $arguments['signatory_title'] ) ? sanitize_text_field( $arguments['signatory_title'] ) : '';

		// Verify registration exists and is approved.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'nvoos-content-graph-pro' ) );
		}

		// Check if registration is approved.
		$statuses    = wp_get_post_terms( $registration_id, 'mcp_ai_reg_status' );
		$is_approved = false;
		if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
			$status_name = $statuses[0]->slug;
			$is_approved = in_array( $status_name, array( 'approved', 'active' ), true );
		}

		if ( ! $is_approved ) {
			return new WP_Error( 'wp_mcp_ai_invalid_status', __( 'Certificate can only be generated for approved registrations.', 'nvoos-content-graph-pro' ) );
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
		$certificate_content .= 'Issued: ' . gmdate( 'Y-m-d' ) . "\n\n";

		if ( $signatory_name ) {
			$certificate_content .= "Authorized Signatory: {$signatory_name}\n";
		}
		if ( $signatory_title ) {
			$certificate_content .= "Title: {$signatory_title}\n";
		}

		if ( $include_qr_code ) {
			$verify_url           = home_url( '/verify-certificate/?cert=' . $certificate_number );
			$certificate_content .= "\nVerification URL: {$verify_url}\n";
		}

		// Save certificate.
		$upload_dir = wp_upload_dir();
		$cert_dir   = $upload_dir['basedir'] . '/compliance-certificates';
		$filename   = sprintf( 'certificate-%d-%s.pdf', $registration_id, gmdate( 'YmdHis' ) );
		$file_path  = $cert_dir . '/' . $filename;
		$file_url   = $upload_dir['baseurl'] . '/compliance-certificates/' . $filename;

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
