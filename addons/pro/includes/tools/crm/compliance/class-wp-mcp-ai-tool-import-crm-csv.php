<?php
/**
 * Import CRM CSV — bulk lead import with field mapping, dedupe, and dry-run.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk imports leads from CSV data with field mapping and deduplication.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Import_CRM_Csv implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'import_crm_csv';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import CRM CSV', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Bulk import leads from CSV data with field mapping, deduplication by email, and dry-run preview.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'csv_data'  => array(
					'type'        => 'string',
					'description' => __( 'CSV content as a string (first row = headers).', 'mcp-ai-wpoos-pro' ),
				),
				'field_map' => array(
					'type'        => 'object',
					'description' => __( 'Mapping of CSV column → lead meta key. Default: auto-detect from headers.', 'mcp-ai-wpoos-pro' ),
				),
				'source'    => array(
					'type'        => 'string',
					'default'     => 'import_csv',
					'description' => __( 'Lead source label.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'   => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Preview without creating leads.', 'mcp-ai-wpoos-pro' ),
				),
				'dedupe_by' => array(
					'type'    => 'string',
					'enum'    => array( 'email', 'phone' ),
					'default' => 'email',
				),
			),
			'required'   => array( 'csv_data' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		$csv_data  = $arguments['csv_data'];
		$dry_run   = ! empty( $arguments['dry_run'] );
		$source    = sanitize_key( $arguments['source'] ?? 'import_csv' );
		$dedupe_by = sanitize_key( $arguments['dedupe_by'] ?? 'email' );

		// Parse CSV.
		$lines = explode( "\n", trim( $csv_data ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'invalid_csv', __( 'CSV must contain at least a header row and one data row.', 'mcp-ai-wpoos-pro' ) );
		}
		$headers      = str_getcsv( array_shift( $lines ) );
		$rows         = array();
		$preview      = array();
		$created      = 0;
		$skipped      = 0;
		$known_fields = array( 'first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'notes' );
		// Auto-map headers.
		$map = array();
		foreach ( $headers as $h ) {
			$hl = strtolower( str_replace( ' ', '_', trim( $h ) ) );
			if ( in_array( $hl, $known_fields, true ) ) {
				$map[ $h ] = $hl;
			} else {
				$map[ $h ] = $hl;
			}
		}
		// Override with explicit map if provided.
		if ( ! empty( $arguments['field_map'] ) ) {
			foreach ( $arguments['field_map'] as $k => $v ) {
				$map[ sanitize_text_field( $k ) ] = sanitize_key( $v );
			}
		}

		$max_rows = min( 500, count( $lines ) );
		for ( $i = 0; $i < $max_rows; $i++ ) {
			$cols = str_getcsv( $lines[ $i ] );
			$row  = array();
			foreach ( $headers as $j => $h ) {
				$row[ isset( $map[ $h ] ) ? $map[ $h ] : sanitize_key( $h ) ] = isset( $cols[ $j ] ) ? trim( $cols[ $j ] ) : '';
			}
			$email = sanitize_email( $row['email'] ?? '' );
			// Dedupe.
			if ( $email && 'email' === $dedupe_by ) {
				$q = new WP_Query(
					array(
						'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => array(
							array(
								'key'   => 'email',
								'value' => $email,
							),
						),
						'no_found_rows'  => true,
					)
				);
				if ( $q->have_posts() ) {
					$preview[] = array(
						'row'         => $i + 1,
						'email'       => $email,
						'status'      => 'skipped_duplicate',
						'existing_id' => $q->posts[0],
					);
					++$skipped;
					continue;
				}
			}
			if ( $dry_run ) {
				$preview[] = array(
					'row'    => $i + 1,
					'email'  => $email,
					'status' => 'would_create',
				);
				continue;
			}
			$raw_title = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
			$title     = $raw_title ? $raw_title : __( 'Imported Lead', 'mcp-ai-wpoos-pro' );
			$lid       = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_title'  => $title,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $lid ) ) {
				$preview[] = array(
					'row'    => $i + 1,
					'status' => 'error',
					'error'  => $lid->get_error_message(),
				);
				continue;
			}
			foreach ( $row as $k => $v ) {
				if ( in_array( $k, $known_fields, true ) && ! empty( $v ) ) {
					update_post_meta( $lid, $k, 'email' === $k ? sanitize_email( $v ) : sanitize_text_field( $v ) );
				}
			}
			update_post_meta( $lid, 'source', $source );
			update_post_meta( $lid, 'lead_status', 'new' );
			update_post_meta( $lid, 'lifecycle_stage', 'lead' );
			$preview[] = array(
				'row'     => $i + 1,
				'email'   => $email,
				'status'  => 'created',
				'lead_id' => $lid,
			);
			++$created;
		}
		return array(
			'success'    => true,
			'dry_run'    => $dry_run,
			'total_rows' => $max_rows,
			'created'    => $created,
			'skipped'    => $skipped,
			'preview'    => $preview,
			'message'    => $dry_run
				? sprintf(
					/* translators: 1: rows to import, 2: skipped count */
					__( 'Dry-run: %1$d rows would be imported, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
					$max_rows - $skipped,
					$skipped
				)
				: sprintf(
					/* translators: 1: created count, 2: skipped count */
					__( 'Import complete: %1$d created, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
					$created,
					$skipped
				),
		);
	}
}
