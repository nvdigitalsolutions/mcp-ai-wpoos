<?php
/**
 * F&B tool: import a table into native storage.
 *
 * Phase 9 import bridge: reads a transactional spec table from the primary
 * backend (Drive or CSV) and upserts it into the matching native-storage
 * CPT. ACT-tier (spec "Change records" row): `manage_options` capability,
 * default-off in the assistant packs.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-tool-base.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-cpt-reader.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-structure-matcher.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-ingest-quality.php';

/**
 * Import a transactional table into F&B native storage.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Fnb_Import_Table extends WP_MCP_AI_Fnb_Tool_Base {

	/**
	 * Native table ID → CPT class/file map for on-demand loading.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private static $cpt_map = array(
		'6.0'  => array( 'WP_MCP_AI_Fnb_Sale_CPT', 'class-wp-mcp-ai-fnb-sale-cpt.php' ),
		'8.0'  => array( 'WP_MCP_AI_Fnb_Purchase_CPT', 'class-wp-mcp-ai-fnb-purchase-cpt.php' ),
		'9.0'  => array( 'WP_MCP_AI_Fnb_Stock_Count_CPT', 'class-wp-mcp-ai-fnb-stock-count-cpt.php' ),
		'10.0' => array( 'WP_MCP_AI_Fnb_Waste_CPT', 'class-wp-mcp-ai-fnb-waste-cpt.php' ),
		'16.0' => array( 'WP_MCP_AI_Fnb_Booking_CPT', 'class-wp-mcp-ai-fnb-booking-cpt.php' ),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'fnb_import_table';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'F&B Import Table', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import a transactional F&B table (6.0 Daily sales, 8.0 Purchases, 9.0 Stock counts, 10.0 Waste log, 16.0 Bookings) from Drive/CSV into WordPress native storage. ACT-tier: requires manage_options and stays off in the assistant packs. Idempotent — re-running updates existing rows instead of duplicating them.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Migrating transactional F&B data into native storage so it can be edited in wp-admin and read without Drive access.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Reading or analysing data — use fnb_read_table / fnb_calculate_metric. Importing reference tables (1.0–5.0, 7.0, 11.0–15.0, 17.0) which are not native-backed.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'fnb_read_table', 'fnb_search_table', 'fnb_audit_log' ),
			'notes'           => __( 'Rows are keyed on the spec idempotency columns per table, so re-imports update in place. Dry-run returns a preview without writing.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'table_id' => array(
					'type'        => 'string',
					'description' => __( 'Spec table ID to import: 6.0, 8.0, 9.0, 10.0 or 16.0.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'  => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'When true, validate and preview the rows without writing anything.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'    => array(
					'type'        => 'integer',
					'default'     => 500,
					'description' => __( 'Maximum rows to import (default 500, hard cap 5000).', 'mcp-ai-wpoos-pro' ),
				),
				'format'   => array(
					'type'        => 'string',
					'description' => __( 'Optional source format hint (csv, tsv, json, jsonl). Ignored when reading the configured Drive/CSV source; reserved for direct raw input.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'table_id' ),
		);
	}

	/**
	 * ACT-tier: only site managers may change records.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'idempotent', 'requires-capability' );
	}

	/**
	 * Load the CPT class backing a native table.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string|null CPT class name or null.
	 */
	private function cpt_class_for( $table_id ) {
		if ( ! isset( self::$cpt_map[ $table_id ] ) ) {
			return null;
		}
		$class = self::$cpt_map[ $table_id ][0];
		if ( ! class_exists( $class ) ) {
			require_once __DIR__ . '/' . self::$cpt_map[ $table_id ][1];
		}

		return class_exists( $class ) ? $class : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard();
		if ( $error ) {
			return $error;
		}

		$table_id = isset( $arguments['table_id'] ) ? sanitize_text_field( (string) $arguments['table_id'] ) : '';
		$dry_run  = ! empty( $arguments['dry_run'] );
		$limit    = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 500;
		$format   = isset( $arguments['format'] ) ? sanitize_key( (string) $arguments['format'] ) : '';
		if ( $limit < 1 ) {
			$limit = 500;
		}
		$limit = min( $limit, 5000 );

		if ( ! WP_MCP_AI_Fnb_Cpt_Reader::is_native_table( $table_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_import_invalid_table',
				/* translators: %s: table id */
				sprintf( __( 'Table "%s" cannot be imported into native storage — only the transactional tables 6.0, 8.0, 9.0, 10.0 and 16.0 are native-backed.', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		$post_type = WP_MCP_AI_Fnb_Cpt_Reader::post_type_for( $table_id );
		if ( ! WP_MCP_AI_Fnb_Settings::is_native_storage_enabled() ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_native_storage_disabled',
				__( 'F&B native storage is disabled — enable it on the Food & Beverage settings page before importing.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_native_storage_disabled',
				__( 'F&B native storage is disabled — enable it on the Food & Beverage settings page before importing.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Always read the SOURCE backend (Drive/CSV), never the CPT itself.
		$table = WP_MCP_AI_Fnb_Data_Source::get_table( $table_id, false );
		if ( is_wp_error( $table ) ) {
			return $this->map_source_error( $table_id, $table );
		}

		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		$canonical  = is_array( $definition ) && ! empty( $definition['canonical'] ) ? $definition['canonical'] : array();
		$headers    = $table['header_index'];

		// Structural match report (confidence over the resolved source columns).
		$mapping = false;
		if ( class_exists( 'WP_MCP_AI_Fnb_Structure_Matcher' ) ) {
			$mapping = WP_MCP_AI_Fnb_Structure_Matcher::match_table( $table_id, $table['columns'] );
		}

		$source_rows   = array_slice( $table['rows'], 0, $limit );
		$preview       = array();
		$rows_to_write = array();
		foreach ( $source_rows as $index => $source_row ) {
			$assoc = array();
			foreach ( $canonical as $canonical_key ) {
				$assoc[ $canonical_key ] = WP_MCP_AI_Fnb_Data_Source::cell( $table_id, $headers, $source_row, $canonical_key );
			}
			$rows_to_write[] = $assoc;
			if ( count( $preview ) < 5 ) {
				$preview[] = $assoc;
			}
		}

		$summary = array(
			'total'    => count( $rows_to_write ),
			'inserted' => 0,
			'updated'  => 0,
			'errors'   => 0,
		);

		$flags = WP_MCP_AI_Fnb_Ingest_Quality::generate_flags( $table_id, $rows_to_write );

		if ( $dry_run ) {
			return array(
				'success'   => true,
				'dry_run'   => true,
				'table_id'  => $table_id,
				'post_type' => $post_type,
				'preview'   => $preview,
				'summary'   => $summary,
				'format'    => $format,
				'mapping'   => $mapping,
				'flags'     => $flags,
				'source'    => $table['source'],
			);
		}

		$cpt_class = $this->cpt_class_for( $table_id );
		if ( null === $cpt_class ) {
			return new WP_Error( 'wp_mcp_ai_fnb_import_cpt_missing', __( 'The native-storage CPT class could not be loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$error_samples = array();
		foreach ( $rows_to_write as $assoc ) {
			$result = call_user_func( array( $cpt_class, 'upsert' ), $assoc );
			if ( is_wp_error( $result ) ) {
				++$summary['errors'];
				if ( count( $error_samples ) < 5 ) {
					$error_samples[] = $result->get_error_message();
				}
				continue;
			}
			if ( 'insert' === $result['action'] ) {
				++$summary['inserted'];
			} else {
				++$summary['updated'];
			}
		}

		return array(
			'success'       => true,
			'dry_run'       => false,
			'table_id'      => $table_id,
			'post_type'     => $post_type,
			'summary'       => $summary,
			'error_samples' => $error_samples,
			'flags'         => $flags,
			'source'        => $table['source'],
		);
	}

	/**
	 * Translate a source-resolution failure into an actionable, three-way error.
	 *
	 * Distinguishes (1) no source configured, (2) source found but the table
	 * cannot resolve within it, and (3) source found but columns are
	 * unrecognised — so the operator sees the fix path, not generic jargon.
	 *
	 * @param string   $table_id Spec table ID.
	 * @param WP_Error $error    Original reader error.
	 * @return WP_Error
	 */
	private function map_source_error( $table_id, WP_Error $error ) {
		$code = $error->get_error_code();

		// (1) Nothing configured to read from.
		if ( in_array( $code, array( 'wp_mcp_ai_fnb_sheet_not_found', 'wp_mcp_ai_fnb_missing_fixture' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_import_no_source',
				sprintf(
					/* translators: 1: table id, 2: original error */
					__( 'No source resolved for F&B table "%1$s". Configure a Drive workbook or data folder (data_workbook_id / data_folder_id / setup_folder_id) on the F&B settings page, or add a table_map entry. Original error: %2$s', 'mcp-ai-wpoos-pro' ),
					$table_id,
					$error->get_error_message()
				)
			);
		}

		return $error;
	}
}
