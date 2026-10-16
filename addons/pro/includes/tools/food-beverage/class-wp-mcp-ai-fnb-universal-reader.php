<?php
/**
 * Food & Beverage Management Toolkit — Universal Ingestor Reader.
 *
 * Schema-free single-folder adapter: the operator points the toolkit at one
 * Google Drive folder of *unorganised* files (any mix of native Sheets, CSVs,
 * TSVs, XLSX or ODS) and this reader auto-assigns each file to a spec table
 * role by scoring its header row against the canonical column ontology. Files
 * mapping to the same role are concatenated (with a `_source_file` provenance
 * column). A review manifest (file → role → confidence) is persisted so the
 * operator can correct any mis-assignment on the settings page.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Universal (single-folder) reader adapter.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Universal_Reader implements WP_MCP_AI_Fnb_Table_Reader {

	const CACHE_GROUP = 'wp_mcp_ai_fnb_tables';
	const CACHE_TTL   = 300; // 5 minutes.
	const MANIFEST_KEY = 'wp_mcp_ai_fnb_ingest_manifest';

	/**
	 * MIME types treated as readable spreadsheets.
	 *
	 * @var array<int,string>
	 */
	private static $spreadsheet_mimes = array(
		'application/vnd.google-apps.spreadsheet',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-excel',
		'application/vnd.oasis.opendocument.spreadsheet',
		'text/csv',
		'text/tab-separated-values',
		'application/json',
	);

	/**
	 * Per-request auth cache.
	 *
	 * @var array|null
	 */
	private $auth = null;

	/**
	 * Resolve Drive credentials/token.
	 *
	 * @return array|WP_Error array{access_token:string,timeout:int}
	 */
	private function token() {
		if ( null !== $this->auth ) {
			return $this->auth;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' ) ) {
			$this->auth = new WP_Error(
				'wp_mcp_ai_fnb_no_drive_client',
				__( 'The Google Workspace toolkit (Drive client) is not available.', 'mcp-ai-wpoos-pro' )
			);

			return $this->auth;
		}

		$connection_id = WP_MCP_AI_Fnb_Settings::get( 'connection_id', '' );
		$credentials   = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( $connection_id );
		if ( is_wp_error( $credentials ) ) {
			$this->auth = $credentials;

			return $this->auth;
		}

		$timeout      = WP_MCP_AI_Pro_Google_Drive_Client::get_request_timeout();
		$access_token = WP_MCP_AI_Pro_Google_Drive_Client::request_access_token(
			$credentials['client_id'],
			$credentials['client_secret'],
			$credentials['refresh_token'],
			$timeout
		);
		if ( is_wp_error( $access_token ) ) {
			$this->auth = $access_token;

			return $this->auth;
		}

		$this->auth = array(
			'access_token' => $access_token,
			'timeout'      => $timeout,
		);

		return $this->auth;
	}

	/**
	 * Normalise a header cell / file name for fuzzy comparison.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalise( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( '_', '-' ), ' ', $value );

		return preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * List candidate files in the configured single data folder.
	 *
	 * @return array<int,array> File metadata list (possibly empty).
	 */
	private function list_candidates( $auth ) {
		$folder_id = WP_MCP_AI_Fnb_Settings::get( 'data_folder_id', '' );
		$folder_id = is_string( $folder_id ) ? trim( $folder_id ) : '';
		if ( '' === $folder_id ) {
			return array();
		}

		$children = WP_MCP_AI_Pro_Google_Drive_Client::list_children(
			$folder_id,
			$auth['access_token'],
			$auth['timeout'],
			1000
		);
		if ( is_wp_error( $children ) || ! isset( $children['files'] ) || ! is_array( $children['files'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $children['files'] as $file ) {
			$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : '';
			if ( in_array( $mime, self::$spreadsheet_mimes, true )
				|| in_array( strtok( $mime, ';' ), self::$spreadsheet_mimes, true ) ) {
				$out[] = $file;
			}
		}

		return $out;
	}

	/**
	 * Read the header row (and optionally rows) of a single candidate file.
	 *
	 * @param array $file File metadata.
	 * @param array $auth Auth array.
	 * @return array{columns:array<int,string>,rows:array<int,array>}|WP_Error
	 */
	private function read_file( $file, $auth ) {
		$file_id = isset( $file['id'] ) ? (string) $file['id'] : '';
		$mime    = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : '';

		// Native Sheets: read via the Sheets API (first worksheet, full range).
		if ( 'application/vnd.google-apps.spreadsheet' === $mime ) {
			$metadata = WP_MCP_AI_Pro_Google_Drive_Client::get_sheets_metadata( $file_id, $auth['access_token'], $auth['timeout'] );
			if ( is_wp_error( $metadata ) ) {
				return $metadata;
			}
			$tab = '';
			if ( isset( $metadata['sheets'] ) && is_array( $metadata['sheets'] ) && isset( $metadata['sheets'][0]['properties']['title'] ) ) {
				$tab = (string) $metadata['sheets'][0]['properties']['title'];
			}
			if ( '' === $tab ) {
				return new WP_Error( 'wp_mcp_ai_fnb_ingest_no_tab', __( 'Spreadsheet has no readable worksheet.', 'mcp-ai-wpoos-pro' ) );
			}
			$values = WP_MCP_AI_Pro_Google_Drive_Client::get_sheet_values( $file_id, $tab, $auth['access_token'], $auth['timeout'] );
			if ( is_wp_error( $values ) ) {
				return $values;
			}

			return $this->matrix_to_rows( isset( $values['values'] ) && is_array( $values['values'] ) ? $values['values'] : array() );
		}

		// Native JSON file (application/json): download raw and map records.
		if ( 'application/json' === $mime ) {
			$raw = WP_MCP_AI_Pro_Google_Drive_Client::request_raw(
				'files/' . rawurlencode( $file_id ),
				$auth['access_token'],
				$auth['timeout'],
				array( 'alt' => 'media' )
			);
			if ( is_wp_error( $raw ) ) {
				return $raw;
			}

			return $this->parse_json( (string) $raw );
		}

		// Uploaded/exported files: request as CSV text.
		$csv = WP_MCP_AI_Pro_Google_Drive_Client::request_raw(
			'files/' . rawurlencode( $file_id ) . '/export',
			$auth['access_token'],
			$auth['timeout'],
			array( 'mimeType' => 'text/csv' )
		);
		if ( is_wp_error( $csv ) ) {
			// text/csv native files support direct download too.
			$csv = WP_MCP_AI_Pro_Google_Drive_Client::request_raw(
				'files/' . rawurlencode( $file_id ),
				$auth['access_token'],
				$auth['timeout'],
				array( 'alt' => 'media' )
			);
			if ( is_wp_error( $csv ) ) {
				return $csv;
			}
		}

		return $this->parse_csv( (string) $csv );
	}

	/**
	 * Convert a Sheets values matrix to the columns/rows contract.
	 *
	 * @param array<int,array<int,mixed>> $values Raw matrix.
	 * @return array{columns:array<int,string>,rows:array<int,array>}
	 */
	private function matrix_to_rows( $values ) {
		$columns = array();
		$rows    = array();
		if ( empty( $values ) ) {
			return array( 'columns' => $columns, 'rows' => $rows );
		}
		$columns = array_map( 'strval', array_shift( $values ) );
		foreach ( $values as $row ) {
			$has = false;
			foreach ( $row as $cell ) {
				if ( null !== $cell && '' !== trim( (string) $cell ) ) {
					$has = true;
					break;
				}
			}
			if ( $has ) {
				$rows[] = array_map( 'strval', $row );
			}
		}

		return array( 'columns' => $columns, 'rows' => $rows );
	}

	/**
	 * Parse CSV/TSV text into columns + rows.
	 *
	 * @param string $csv CSV text.
	 * @return array{columns:array<int,string>,rows:array<int,array>}
	 */
	private function parse_csv( $csv ) {
		$columns = array();
		$rows    = array();
		$stream  = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $stream, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$first = true;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV read loop.
		while ( false !== ( $data = fgetcsv( $stream ) ) ) {
			if ( $first ) {
				$columns = $data;
				$first   = false;
				continue;
			}
			$has = false;
			foreach ( $data as $cell ) {
				if ( '' !== trim( (string) $cell ) ) {
					$has = true;
					break;
				}
			}
			if ( $has ) {
				$rows[] = $data;
			}
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array( 'columns' => $columns, 'rows' => $rows );
	}

	/**
	 * Parse a JSON array of records (or a single record) into columns + rows.
	 *
	 * Accepts an array of objects (each row) or a single object; columns are
	 * the union of keys across the first record (subsequent records align by
	 * key, missing values become empty strings).
	 *
	 * @param string $json Raw JSON text.
	 * @return array{columns:array<int,string>,rows:array<int,array>}|WP_Error
	 */
	private function parse_json( $json ) {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_ingest_bad_json', __( 'JSON file could not be parsed.', 'mcp-ai-wpoos-pro' ) );
		}

		// Normalise to a list of records.
		if ( isset( $decoded[0] ) && ! is_array( $decoded[0] ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_ingest_bad_json', __( 'JSON file is not a list of records.', 'mcp-ai-wpoos-pro' ) );
		}
		$records = array_values( $decoded );
		if ( ! isset( $records[0] ) || ! is_array( $records[0] ) ) {
			// Single object (associative) → wrap as one record.
			$records = array( $decoded );
		}

		// Determine the column set from the first record (plus any extra keys
		// seen in later records).
		$columns = array();
		foreach ( $records as $record ) {
			foreach ( array_keys( (array) $record ) as $key ) {
				if ( ! in_array( (string) $key, $columns, true ) ) {
					$columns[] = (string) $key;
				}
			}
		}

		$rows = array();
		foreach ( $records as $record ) {
			$row = array();
			foreach ( $columns as $col ) {
				$value    = isset( $record[ $col ] ) ? $record[ $col ] : '';
				$row[]    = is_scalar( $value ) || null === $value ? (string) $value : '';
			}
			$rows[] = $row;
		}

		return array( 'columns' => $columns, 'rows' => $rows );
	}

	/**
	 * Score a file's header set against a table's canonical column ontology.
	 *
	 * Returns a match score 0..1 (fraction of the table's canonical columns
	 * that appear in the file's headers — via canonical names, per-table
	 * aliases, and the curated HEADER_ALIASES dictionary).
	 *
	 * @param array<int,string> $headers  Raw header list.
	 * @param string            $table_id Spec table ID.
	 * @return float
	 */
	private function score_headers( $headers, $table_id ) {
		$definition = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
		if ( null === $definition || empty( $definition['canonical'] ) ) {
			return 0.0;
		}

		// Build the canonical-key set for this table.
		$canonical_set = array_flip( $definition['canonical'] );

		// Resolve every incoming header through the rich alias dictionary + the
		// per-table aliases, so variants like "inv no", "quantity sold", or
		// "sales" map onto canonical keys before scoring.
		$resolved_headers = array();
		foreach ( $headers as $header ) {
			$canonical = WP_MCP_AI_Fnb_Data_Source::resolve_header_alias( $header );
			// If the alias didn't resolve (returned input unchanged), try the
			// per-table alias map via normalisation against canonical keys.
			if ( (string) $header === (string) $canonical ) {
				$normalised = $this->normalise( $header );
				foreach ( $definition['canonical'] as $c ) {
					if ( $normalised === $this->normalise( $c ) ) {
						$canonical = $c;
						break;
					}
				}
			}
			$resolved_headers[] = $canonical;
		}
		$resolved_set = array_flip( $resolved_headers );

		// Count canonical keys present.
		$hits = 0;
		foreach ( $definition['canonical'] as $canonical ) {
			if ( isset( $resolved_set[ $canonical ] ) ) {
				++$hits;
			}
		}

		$denominator = count( $definition['canonical'] );

		return $denominator > 0 ? $hits / $denominator : 0.0;
	}

	/**
	 * Assign a file to its best table role by header scoring.
	 *
	 * @param array                $file File metadata.
	 * @param array<int,string>    $columns Raw header row.
	 * @return array{table_id:string,confidence:float}
	 */
	private function assign_role( $file, $columns ) {
		// File-name hint first (fuzzy match against the canonical sheet map).
		$name      = isset( $file['name'] ) ? (string) $file['name'] : '';
		$name_hint = $this->normalise( pathinfo( $name, PATHINFO_FILENAME ) );

		$best           = '';
		$best_score     = 0.0;
		$best_name_rank = PHP_INT_MAX;

		$tables = WP_MCP_AI_Fnb_Data_Source::list_tables();
		foreach ( $tables as $entry ) {
			$table_id = $entry['id'];
			$score    = $this->score_headers( $columns, $table_id );

			// Weak name-hint boost for a bare/ambiguous header (e.g. a single
			// "date" column) so file names still guide role assignment.
			$def       = WP_MCP_AI_Fnb_Data_Source::table_definition( $table_id );
			$role_name = $this->normalise( isset( $def['label'] ) ? $def['label'] : '' );
			$name_rank = PHP_INT_MAX;
			if ( '' !== $role_name && '' !== $name_hint && false !== strpos( $name_hint, $role_name ) ) {
				$name_rank = strpos( $name_hint, $role_name );
			}

			if ( $score > $best_score || ( $score === $best_score && $name_rank < $best_name_rank ) ) {
				$best           = $table_id;
				$best_score     = $score;
				$best_name_rank = $name_rank;
			}
		}

		// If nothing matches at all, fall back to the file-name hint mapping via
		// the canonical sheet map (menu.csv → 1.0, etc.).
		if ( 0.0 === $best_score && '' !== $name_hint ) {
			$sheet_map = $this->sheet_map();
			foreach ( $sheet_map as $slug => $table_id ) {
				if ( $name_hint === $this->normalise( $slug ) || false !== strpos( $name_hint, $this->normalise( $slug ) ) ) {
					$best       = $table_id;
					$best_score = 0.2; // name-only match → low confidence.
					break;
				}
			}
		}

		return array(
			'table_id'  => $best,
			'confidence' => $best_score,
		);
	}

	/**
	 * Canonical sheet-name → table-ID map (mirrors the fixture reader).
	 *
	 * @return array<string,string>
	 */
	private function sheet_map() {
		return array(
			'menu'            => '1.0',
			'recipes'         => '2.0',
			'ingredients'     => '3.0',
			'suppliers'       => '4.0',
			'supplier prices' => '5.0',
			'daily sales'     => '6.0',
			'daily covers'    => '7.0',
			'purchases'       => '8.0',
			'stock counts'    => '9.0',
			'waste log'       => '10.0',
			'payroll'         => '11.0',
			'timesheets'      => '12.0',
			'utilities'       => '13.0',
			'expense ledger'  => '14.0',
			'budgets'         => '15.0',
			'bookings'        => '16.0',
			'asset log'       => '17.0',
			'assumptions'     => 'assumptions',
		);
	}

	/**
	 * Build (and persist) the review manifest for the configured folder.
	 *
	 * In dry-run mode the manifest is returned with per-file sample rows but
	 * NOT persisted, so the operator can preview the auto-mapping (and a
	 * human approve/correct it) before any ingest is committed.
	 *
	 * @param bool $dry_run Preview only (do not persist) when true.
	 * @return array<int,array{file:string,role:string,table_id:string,confidence:float,sample_rows:array<int,array>}>
	 */
	public function build_manifest( $dry_run = false ) {
		$auth = $this->token();
		if ( is_wp_error( $auth ) ) {
			return array();
		}

		$manifest = array();
		foreach ( $this->list_candidates( $auth ) as $file ) {
			$result = $this->read_file( $file, $auth );
			if ( is_wp_error( $result ) ) {
				// Include unreadable files so the operator can see and fix them.
				$manifest[] = array(
					'file'       => isset( $file['name'] ) ? (string) $file['name'] : '',
					'role'       => '',
					'table_id'   => '',
					'confidence' => 0.0,
					'error'      => $result->get_error_message(),
				);
				continue;
			}
			$assigned = $this->assign_role( $file, $result['columns'] );
			$manifest[] = array(
				'file'        => isset( $file['name'] ) ? (string) $file['name'] : '',
				'role'        => $assigned['table_id'],
				'table_id'    => $assigned['table_id'],
				'confidence'  => $assigned['confidence'],
				'sample_rows' => array_slice( $result['rows'], 0, 3 ),
			);
		}

		if ( ! $dry_run ) {
			set_transient( self::MANIFEST_KEY, $manifest, self::CACHE_TTL );
		}

		return $manifest;
	}

	/**
	 * Get the persisted review manifest (or build it on first access).
	 *
	 * @return array<int,array>
	 */
	public function manifest() {
		$cached = get_transient( self::MANIFEST_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		return $this->build_manifest();
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error array{columns,rows,source,provenance}
	 */
	public function read_table( $table_id ) {
		$auth = $this->token();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$columns = array();
		$rows    = array();
		$provenance = array();

		foreach ( $this->list_candidates( $auth ) as $file ) {
			$result = $this->read_file( $file, $auth );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$assigned = $this->assign_role( $file, $result['columns'] );
			if ( $assigned['table_id'] !== $table_id ) {
				continue;
			}

			// Adopt the first file's header; align subsequent files' columns by
			// canonical/alias resolution is handled downstream — here we keep the
			// raw headers but record provenance per file.
			if ( empty( $columns ) ) {
				$columns = $result['columns'];
			}

			$file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
			foreach ( $result['rows'] as $row ) {
				$rows[] = $row;
				$provenance[ count( $rows ) - 1 ] = $file_name;
			}
		}

		if ( empty( $columns ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_ingest_no_table',
				/* translators: %s: table id */
				sprintf( __( 'No file in the configured folder mapped to F&B table "%s". Confirm the folder and review the data manifest.', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		return array(
			'columns'    => $columns,
			'rows'       => $rows,
			'source'     => 'fnb_universal_reader',
			'provenance' => $provenance,
		);
	}
}
