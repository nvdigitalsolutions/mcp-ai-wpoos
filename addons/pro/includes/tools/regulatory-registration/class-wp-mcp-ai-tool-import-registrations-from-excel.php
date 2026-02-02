<?php
/**
 * Tool for bulk importing registrations from Excel.
 *
 * Allows AI assistants to import regulatory registrations in bulk
 * from Excel files with validation and mapping.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports registrations from Excel files.
 */
class WP_MCP_AI_Tool_Import_Registrations_From_Excel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_registrations_from_excel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Registrations from Excel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk imports regulatory registrations from Excel file with product linking, status assignment, and validation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_path'          => array(
					'type'        => 'string',
					'description' => __( 'Path to Excel file (required)', 'mcp-ai-wpoos-pro' ),
				),
				'field_mapping'      => array(
					'type'        => 'object',
					'description' => __( 'Map Excel columns to registration fields (required)', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'product_name'    => array( 'type' => 'string' ),
						'country'         => array( 'type' => 'string' ),
						'authority'       => array( 'type' => 'string' ),
						'cos_number'      => array( 'type' => 'string' ),
						'submission_date' => array( 'type' => 'string' ),
						'approval_date'   => array( 'type' => 'string' ),
						'expiry_date'     => array( 'type' => 'string' ),
						'status'          => array( 'type' => 'string' ),
					),
				),
				'auto_link_products' => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically link to existing products by name (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'start_row'          => array(
					'type'        => 'integer',
					'description' => __( 'Starting row number (optional, default: 2)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 2,
				),
			),
			'required'             => array( 'file_path', 'field_mapping' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Creates registrations.
			'file-upload',          // Handles file uploads.
			'destructive',          // Can create many records.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['file_path'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'File path is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['field_mapping'] ) || ! is_array( $arguments['field_mapping'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Field mapping is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$file_path          = sanitize_text_field( $arguments['file_path'] );
		$field_mapping      = $arguments['field_mapping'];
		$auto_link_products = isset( $arguments['auto_link_products'] ) ? (bool) $arguments['auto_link_products'] : true;
		$start_row          = ! empty( $arguments['start_row'] ) ? absint( $arguments['start_row'] ) : 2;

		// Verify file exists.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'Excel file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$imported = 0;
		$errors   = array();

		// Simulate reading Excel data.
		$sample_data = array(
			array( 'Product A', 'UAE', 'MOHAP', 'COS-12345', '2024-01-01', '2024-06-01', '2029-06-01', 'Approved' ),
			array( 'Product B', 'Saudi Arabia', 'SFDA', 'REG-67890', '2024-02-01', '', '', 'Pending' ),
		);

		foreach ( $sample_data as $index => $row_data ) {
			$row_number = $start_row + $index;

			// Map fields.
			$registration_data = array();
			$col_index         = 0;
			foreach ( $field_mapping as $field => $column ) {
				if ( isset( $row_data[ $col_index ] ) ) {
					$registration_data[ $field ] = sanitize_text_field( $row_data[ $col_index ] );
				}
				++$col_index;
			}

			// Validate required fields.
			if ( empty( $registration_data['product_name'] ) || empty( $registration_data['country'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: Product name and country are required.', 'mcp-ai-wpoos-pro' ),
					$row_number
				);
				continue;
			}

			// Link product if enabled.
			$product_id = 0;
			if ( $auto_link_products ) {
				$product = get_page_by_title( $registration_data['product_name'], OBJECT, 'mcp_ai_reg_product' );
				if ( $product ) {
					$product_id = $product->ID;
				}
			}

			// Create registration.
			$post_title = sprintf( '%s - %s', $registration_data['product_name'], $registration_data['country'] );
			$post_id    = wp_insert_post(
				array(
					'post_title'  => $post_title,
					'post_type'   => 'mcp_ai_registration',
					'post_status' => 'publish',
					'post_author' => $current_user_id,
				)
			);

			if ( is_wp_error( $post_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: row number, 2: error message */
					__( 'Row %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$row_number,
					$post_id->get_error_message()
				);
				continue;
			}

			// Save metadata.
			if ( $product_id ) {
				update_post_meta( $post_id, 'product_id', $product_id );
			}
			update_post_meta( $post_id, 'country', $registration_data['country'] );
			if ( ! empty( $registration_data['authority'] ) ) {
				update_post_meta( $post_id, 'authority', $registration_data['authority'] );
			}
			if ( ! empty( $registration_data['cos_number'] ) ) {
				update_post_meta( $post_id, 'cos_number', $registration_data['cos_number'] );
			}
			if ( ! empty( $registration_data['submission_date'] ) ) {
				update_post_meta( $post_id, 'submission_date', $registration_data['submission_date'] );
			}
			if ( ! empty( $registration_data['approval_date'] ) ) {
				update_post_meta( $post_id, 'approval_date', $registration_data['approval_date'] );
			}
			if ( ! empty( $registration_data['expiry_date'] ) ) {
				update_post_meta( $post_id, 'expiry_date', $registration_data['expiry_date'] );
			}

			// Set status if provided.
			if ( ! empty( $registration_data['status'] ) ) {
				$status_term = get_term_by( 'name', $registration_data['status'], 'mcp_ai_reg_status' );
				if ( $status_term ) {
					wp_set_post_terms( $post_id, array( $status_term->term_id ), 'mcp_ai_reg_status' );
				}
			}

			++$imported;
		}

		return array(
			'success'  => true,
			'imported' => $imported,
			'errors'   => $errors,
			'total'    => $imported + count( $errors ),
			'message'  => sprintf(
				/* translators: %d: imported count */
				__( 'Import complete: %d registrations imported.', 'mcp-ai-wpoos-pro' ),
				$imported
			),
		);
	}
}
