<?php
/**
 * Contact Importer Service - CSV contact import/export using csv-parse and csv-stringify NPM packages.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for importing and exporting contacts via CSV.
 *
 * This service provides:
 * - CSV parsing with custom delimiters
 * - Contact data validation
 * - Field mapping
 * - Bulk import
 * - CSV export generation
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Contact_Importer_Service {

	/**
	 * Check if CSV packages are available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		$csv_parse     = WP_MCP_AI_PRO_PATH . 'node_modules/csv-parse';
		$csv_stringify = WP_MCP_AI_PRO_PATH . 'node_modules/csv-stringify';

		return file_exists( $csv_parse ) && file_exists( $csv_stringify );
	}

	/**
	 * Parse CSV file.
	 *
	 * @param string $file_path Path to CSV file.
	 * @param array  $options Parsing options.
	 * @return array|WP_Error Array of parsed data or error.
	 */
	public function parse_csv( $file_path, $options = array() ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'CSV file not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check file size.
		$max_size = apply_filters( 'wp_mcp_ai_csv_max_file_size', 10 * 1024 * 1024 ); // 10MB default.
		if ( filesize( $file_path ) > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %s: maximum file size in MB */
					__( 'CSV file is too large. Maximum size: %sMB', 'mcp-ai-wpoos-pro' ),
					$max_size / ( 1024 * 1024 )
				)
			);
		}

		$defaults = array(
			'delimiter' => ',',
			'columns'   => true,
			'skip_empty_lines' => true,
		);

		$options = wp_parse_args( $options, $defaults );

		// Allow Node.js-based parsing via filter.
		$result = apply_filters( 'wp_mcp_ai_csv_parse', false, array(
			'file_path' => $file_path,
			'options'   => $options,
		) );

		if ( false === $result ) {
			// Fallback to PHP CSV parsing.
			return $this->parse_csv_php( $file_path, $options );
		}

		return $result;
	}

	/**
	 * PHP-based CSV parsing (fallback).
	 *
	 * @param string $file_path Path to CSV file.
	 * @param array  $options Parsing options.
	 * @return array Parsed data.
	 */
	private function parse_csv_php( $file_path, $options ) {
		$data    = array();
		$headers = array();
		$handle  = fopen( $file_path, 'r' );

		if ( ! $handle ) {
			return new WP_Error(
				'file_open_failed',
				__( 'Failed to open CSV file.', 'mcp-ai-wpoos-pro' )
			);
		}

		$row_index = 0;

		while ( ( $row = fgetcsv( $handle, 0, $options['delimiter'] ) ) !== false ) {
			// First row as headers.
			if ( 0 === $row_index && $options['columns'] ) {
				$headers = $row;
				$row_index++;
				continue;
			}

			// Skip empty lines.
			if ( $options['skip_empty_lines'] && empty( array_filter( $row ) ) ) {
				continue;
			}

			// Map row to headers.
			if ( ! empty( $headers ) ) {
				$row_data = array();
				foreach ( $headers as $index => $header ) {
					$row_data[ $header ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
				}
				$data[] = $row_data;
			} else {
				$data[] = $row;
			}

			$row_index++;
		}

		fclose( $handle );

		return $data;
	}

	/**
	 * Generate CSV from data.
	 *
	 * @param array $data Data to export.
	 * @param array $options Generation options.
	 * @return string|WP_Error CSV string or error.
	 */
	public function generate_csv( $data, $options = array() ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new WP_Error(
				'invalid_data',
				__( 'Data must be a non-empty array.', 'mcp-ai-wpoos-pro' )
			);
		}

		$defaults = array(
			'delimiter' => ',',
			'header'    => true,
		);

		$options = wp_parse_args( $options, $defaults );

		// Allow Node.js-based generation via filter.
		$result = apply_filters( 'wp_mcp_ai_csv_stringify', false, array(
			'data'    => $data,
			'options' => $options,
		) );

		if ( false === $result ) {
			// Fallback to PHP CSV generation.
			return $this->generate_csv_php( $data, $options );
		}

		return $result;
	}

	/**
	 * PHP-based CSV generation (fallback).
	 *
	 * @param array $data Data to export.
	 * @param array $options Generation options.
	 * @return string CSV string.
	 */
	private function generate_csv_php( $data, $options ) {
		$output = '';
		$handle = fopen( 'php://temp', 'r+' );

		// Add header row.
		if ( $options['header'] && ! empty( $data[0] ) ) {
			$headers = array_keys( $data[0] );
			fputcsv( $handle, $headers, $options['delimiter'] );
		}

		// Add data rows.
		foreach ( $data as $row ) {
			fputcsv( $handle, $row, $options['delimiter'] );
		}

		rewind( $handle );
		$output = stream_get_contents( $handle );
		fclose( $handle );

		return $output;
	}

	/**
	 * Import contacts from parsed CSV data.
	 *
	 * @param array $data Parsed CSV data.
	 * @param array $options Import options.
	 * @return array Import results.
	 */
	public function import_contacts( $data, $options = array() ) {
		$defaults = array(
			'post_type'      => 'crm_contact',
			'field_mapping'  => array(),
			'validate'       => true,
			'skip_duplicates' => true,
			'update_existing' => false,
		);

		$options = wp_parse_args( $options, $defaults );

		$results = array(
			'total'     => count( $data ),
			'imported'  => 0,
			'skipped'   => 0,
			'errors'    => array(),
		);

		// Require validator service.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
		$validator = new WP_MCP_AI_Validator_Service();

		foreach ( $data as $index => $row ) {
			// Map fields.
			$contact_data = $this->map_fields( $row, $options['field_mapping'] );

			// Validate.
			if ( $options['validate'] ) {
				$validation = $this->validate_contact_data( $contact_data, $validator );
				if ( is_wp_error( $validation ) ) {
					$results['errors'][] = array(
						'row'   => $index + 1,
						'error' => $validation->get_error_message(),
					);
					$results['skipped']++;
					continue;
				}
			}

			// Check for duplicates.
			if ( $options['skip_duplicates'] ) {
				$existing = $this->find_existing_contact( $contact_data['email'], $options['post_type'] );
				if ( $existing ) {
					if ( $options['update_existing'] ) {
						// Update existing contact.
						$contact_data['ID'] = $existing;
					} else {
						$results['skipped']++;
						continue;
					}
				}
			}

			// Import contact.
			$contact_id = $this->create_contact( $contact_data, $options['post_type'] );

			if ( is_wp_error( $contact_id ) ) {
				$results['errors'][] = array(
					'row'   => $index + 1,
					'error' => $contact_id->get_error_message(),
				);
				$results['skipped']++;
			} else {
				$results['imported']++;
			}
		}

		return $results;
	}

	/**
	 * Map CSV fields to contact fields.
	 *
	 * @param array $row CSV row data.
	 * @param array $mapping Field mapping.
	 * @return array Mapped contact data.
	 */
	public function map_fields( $row, $mapping ) {
		if ( empty( $mapping ) ) {
			// Auto-detect common fields.
			$mapping = $this->auto_detect_mapping( array_keys( $row ) );
		}

		$contact_data = array();

		foreach ( $mapping as $csv_field => $contact_field ) {
			if ( isset( $row[ $csv_field ] ) ) {
				$contact_data[ $contact_field ] = $row[ $csv_field ];
			}
		}

		// Allow filtering mapping.
		return apply_filters( 'wp_mcp_ai_contact_field_mapping', $contact_data, $row, $mapping );
	}

	/**
	 * Auto-detect field mapping from CSV headers.
	 *
	 * @param array $headers CSV headers.
	 * @return array Field mapping.
	 */
	private function auto_detect_mapping( $headers ) {
		$mapping = array();

		$common_mappings = array(
			'email'      => array( 'email', 'e-mail', 'email address', 'mail' ),
			'first_name' => array( 'first name', 'firstname', 'fname', 'given name' ),
			'last_name'  => array( 'last name', 'lastname', 'lname', 'surname', 'family name' ),
			'phone'      => array( 'phone', 'telephone', 'phone number', 'mobile' ),
			'company'    => array( 'company', 'organization', 'business' ),
			'address'    => array( 'address', 'street', 'street address' ),
			'city'       => array( 'city', 'town' ),
			'state'      => array( 'state', 'province', 'region' ),
			'zip'        => array( 'zip', 'zipcode', 'postal code', 'postcode' ),
			'country'    => array( 'country' ),
		);

		foreach ( $headers as $header ) {
			$header_lower = strtolower( trim( $header ) );

			foreach ( $common_mappings as $contact_field => $csv_variations ) {
				if ( in_array( $header_lower, $csv_variations, true ) ) {
					$mapping[ $header ] = $contact_field;
					break;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Validate contact data.
	 *
	 * @param array                         $contact_data Contact data.
	 * @param WP_MCP_AI_Validator_Service $validator Validator service.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	private function validate_contact_data( $contact_data, $validator ) {
		// Email is required.
		if ( empty( $contact_data['email'] ) ) {
			return new WP_Error(
				'missing_email',
				__( 'Email is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate email.
		$email_valid = $validator->is_email( $contact_data['email'] );
		if ( is_wp_error( $email_valid ) ) {
			return $email_valid;
		}

		// Validate phone if provided.
		if ( ! empty( $contact_data['phone'] ) ) {
			$phone_valid = $validator->is_phone_number( $contact_data['phone'] );
			if ( is_wp_error( $phone_valid ) ) {
				return $phone_valid;
			}
		}

		return true;
	}

	/**
	 * Find existing contact by email.
	 *
	 * @param string $email Email address.
	 * @param string $post_type Post type.
	 * @return int|false Contact ID or false if not found.
	 */
	private function find_existing_contact( $email, $post_type ) {
		$query = new WP_Query( array(
			'post_type'      => $post_type,
			'meta_query'     => array(
				array(
					'key'     => 'email',
					'value'   => $email,
					'compare' => '=',
				),
			),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true, // Performance optimization - we don't need pagination data.
		) );

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return false;
	}

	/**
	 * Create or update contact.
	 *
	 * @param array  $contact_data Contact data.
	 * @param string $post_type Post type.
	 * @return int|WP_Error Contact ID or error.
	 */
	private function create_contact( $contact_data, $post_type ) {
		$post_data = array(
			'post_type'   => $post_type,
			'post_title'  => isset( $contact_data['first_name'] ) && isset( $contact_data['last_name'] )
				? $contact_data['first_name'] . ' ' . $contact_data['last_name']
				: $contact_data['email'],
			'post_status' => 'publish',
		);

		// Update if ID provided.
		if ( isset( $contact_data['ID'] ) ) {
			$post_data['ID'] = $contact_data['ID'];
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Require validator service for proper sanitization.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-validator-service.php';
		$validator = new WP_MCP_AI_Validator_Service();

		// Save contact meta with proper sanitization based on field type.
		$field_types = array(
			'email'      => 'email',
			'phone'      => 'phone',
			'website'    => 'url',
			'address'    => 'textarea',
			'first_name' => 'text',
			'last_name'  => 'text',
			'company'    => 'text',
			'city'       => 'text',
			'state'      => 'text',
			'zip'        => 'text',
			'country'    => 'text',
		);

		foreach ( $contact_data as $key => $value ) {
			if ( 'ID' === $key ) {
				continue;
			}

			// Determine sanitization type.
			$sanitize_type = isset( $field_types[ $key ] ) ? $field_types[ $key ] : 'text';
			$sanitized_value = $validator->sanitize_input( $value, $sanitize_type );

			update_post_meta( $post_id, $key, $sanitized_value );
		}

		return $post_id;
	}
}
