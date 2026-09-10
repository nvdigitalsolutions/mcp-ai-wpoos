<?php
/**
 * WP_MCP_AI_Tool_Generate_Cover_Letter (ecosystem port - Wave F2, regulatory-registration tool batch).
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
		return __( 'Generate Cover Letter', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a professional cover letter for regulatory submission with customizable content, recipient details, and submission type.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Registration ID for cover letter (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'submission_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of submission (optional, default: "new")', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation', 'withdrawal' ),
					'default'     => 'new',
				),
				'recipient_name'  => array(
					'type'        => 'string',
					'description' => __( 'Recipient name (optional)', 'nvoos-content-graph-pro' ),
				),
				'recipient_title' => array(
					'type'        => 'string',
					'description' => __( 'Recipient title (optional)', 'nvoos-content-graph-pro' ),
				),
				'custom_content'  => array(
					'type'        => 'string',
					'description' => __( 'Additional custom content to include (optional)', 'nvoos-content-graph-pro' ),
				),
				'format'          => array(
					'type'        => 'string',
					'description' => __( 'Output format (optional, default: "pdf")', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate cover letters.', 'nvoos-content-graph-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'nvoos-content-graph-pro' ) );
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
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'nvoos-content-graph-pro' ) );
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
		$filename   = sprintf( 'cover-letter-%d-%s.%s', $registration_id, gmdate( 'YmdHis' ), 'html' === $format ? 'html' : 'txt' );
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
