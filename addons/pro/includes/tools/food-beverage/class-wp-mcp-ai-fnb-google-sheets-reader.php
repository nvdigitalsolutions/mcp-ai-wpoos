<?php
/**
 * Food & Beverage Management Toolkit — Google Sheets Reader.
 *
 * Drive-backed table reader that supports two workbook layouts:
 *
 *  1. Single workbook — a single Google Sheets (or uploaded .xlsx) file whose
 *     worksheets/tabs are named per table ("menu", "daily sales", …). This is
 *     the common real-world layout where one client's entire operating data
 *     lives in one spreadsheet. The reader resolves the requested table ID to
 *     the matching tab name and reads just that sheet via the Google Sheets
 *     API (spreadsheets.values.get).
 *
 *  2. Split files — one Drive file per table (the original demo layout), where
 *     each file is named exactly after its sheet ("menu.csv", "menu.xlsx", or
 *     just "menu"). This remains as a fallback but is no longer required.
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
 * Google Drive/Sheets reader adapter.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Google_Sheets_Reader implements WP_MCP_AI_Fnb_Table_Reader {

	/**
	 * Sheet-name → table-ID map (same contract as the fixture reader).
	 *
	 * Keys are the human worksheet/file names, in decreasing specificity order
	 * so "supplier prices" is checked before a hypothetical "supplier" prefix.
	 * Values are the spec table IDs. The reader matches case-insensitively and
	 * also tolerates underscores/dashes in tab names, since the same underlying
	 * data is frequently labelled differently across clients.
	 *
	 * @var array<string,string>
	 */
	private static $sheet_map = array(
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

	/**
	 * Per-instance cache of an already-resolved sheet name for a table ID.
	 *
	 * @var array<string,string>
	 */
	private $resolved_sheets = array();

	/**
	 * Per-instance cache of the located workbook's Drive file metadata.
	 *
	 * @var array|null
	 */
	private $workbook_cache = null;

	/**
	 * Per-instance WP_Error from the last explicit-workbook metadata lookup.
	 *
	 * @var WP_Error|null
	 */
	private $workbook_error = null;

	/**
	 * Candidate sheet names for a table ID, in lookup order.
	 *
	 * @param string $table_id Spec table ID.
	 * @return array<int,string>
	 */
	private function sheet_names( $table_id ) {
		$names = array();
		foreach ( self::$sheet_map as $name => $id ) {
			if ( $id === $table_id ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Normalise a worksheet/file name for fuzzy comparison.
	 *
	 * @param string $value Raw name.
	 * @return string
	 */
	private function normalise_name( $value ) {
		$value = strtolower( trim( (string) $value ) );
		// Treat "_" and "-" the same as a space so "daily_sales" matches "daily sales".
		$value = str_replace( array( '_', '-' ), ' ', $value );
		// Collapse runs of whitespace.
		return preg_replace( '/\s+/', ' ', $value );
	}

	/**
	 * Resolve the Drive client credentials/token or a WP_Error.
	 *
	 * @return array|WP_Error array{access_token:string,timeout:int}
	 */
	private function token() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_no_drive_client',
				__( 'The Google Workspace toolkit (Drive client) is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$connection_id = WP_MCP_AI_Fnb_Settings::get( 'connection_id', '' );
		$credentials   = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( $connection_id );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$timeout      = WP_MCP_AI_Pro_Google_Drive_Client::get_request_timeout();
		$access_token = WP_MCP_AI_Pro_Google_Drive_Client::request_access_token(
			$credentials['client_id'],
			$credentials['client_secret'],
			$credentials['refresh_token'],
			$timeout
		);
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		return array(
			'access_token' => $access_token,
			'timeout'      => $timeout,
		);
	}

	/**
	 * The configured Drive folder IDs to search, in order.
	 *
	 * @return array<int,string>
	 */
	private function folders() {
		return array_values(
			array_filter(
				array(
					WP_MCP_AI_Fnb_Settings::get( 'data_folder_id', '' ),
					WP_MCP_AI_Fnb_Settings::get( 'setup_folder_id', '' ),
				)
			)
		);
	}

	/**
	 * List the immediate children of a folder, tolerating API failures.
	 *
	 * @param string $folder_id Folder ID.
	 * @param array  $auth      Auth array from token().
	 * @return array<int,array> File metadata list (possibly empty).
	 */
	private function list_folder( $folder_id, $auth ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' ) ) {
			return array();
		}

		$children = WP_MCP_AI_Pro_Google_Drive_Client::list_children(
			(string) $folder_id,
			$auth['access_token'],
			$auth['timeout'],
			1000
		);
		if ( is_wp_error( $children ) ) {
			return array();
		}

		return isset( $children['files'] ) && is_array( $children['files'] ) ? $children['files'] : array();
	}

	/**
	 * Locate the single shared workbook (Google Sheets or uploaded .xlsx) in
	 * the configured folders, preferring an explicitly configured workbook ID.
	 *
	 * A file counts as a workbook when it is a native Google Sheets file or an
	 * uploaded spreadsheet (.xlsx/.xls/.ods). When several candidate files
	 * exist, the most recently modified one wins.
	 *
	 * @param array $auth Auth array from token().
	 * @return array{id:string,name:string,mime_type:string}|null Workbook metadata or null.
	 */
	private function locate_workbook( $auth ) {
		if ( null !== $this->workbook_cache ) {
			return $this->workbook_cache;
		}

		// Prefer an explicitly configured workbook ID, then fall back to the
		// canonical demo workbook (see WP_MCP_AI_Fnb_Settings::workbook_id()).
		$explicit = trim( WP_MCP_AI_Fnb_Settings::workbook_id() );
		if ( '' !== $explicit ) {
			$meta = WP_MCP_AI_Pro_Google_Drive_Client::get_metadata( $explicit, $auth['access_token'], $auth['timeout'] );
			if ( ! is_wp_error( $meta ) ) {
				$this->workbook_cache = array(
					'id'        => isset( $meta['id'] ) ? (string) $meta['id'] : $explicit,
					'name'      => isset( $meta['name'] ) ? (string) $meta['name'] : '',
					'mime_type' => isset( $meta['mimeType'] ) ? (string) $meta['mimeType'] : '',
				);

				return $this->workbook_cache;
			}
			// get_metadata failed — remember the error so read_table() can
			// surface it with an actionable message instead of falling through
			// to a misleading split-file "not found".
			$this->workbook_error = $meta;
		}

		// Also allow a workbook name to be configured (the file may sit in a
		// folder the user hasn't pointed at, but name matching is convenient).
		$workbook_name = WP_MCP_AI_Fnb_Settings::get( 'data_workbook_name', '' );
		$workbook_name = is_string( $workbook_name ) ? $this->normalise_name( $workbook_name ) : '';

		$best          = null;
		$best_modified = 0;

		foreach ( $this->folders() as $folder_id ) {
			foreach ( $this->list_folder( $folder_id, $auth ) as $file ) {
				$mime = isset( $file['mimeType'] ) ? strtolower( (string) $file['mimeType'] ) : '';
				if ( ! $this->is_spreadsheet_mime( $mime ) ) {
					continue;
				}

				$name = isset( $file['name'] ) ? (string) $file['name'] : '';

				// If a workbook name filter is set, require a (fuzzy) match.
				if ( '' !== $workbook_name ) {
					$normalised = $this->normalise_name( $name );
					if ( $normalised !== $workbook_name && false === strpos( $normalised, $workbook_name ) ) {
						continue;
					}
				}

				$modified = isset( $file['modifiedTime'] ) ? strtotime( (string) $file['modifiedTime'] ) : 0;
				$modified = false === $modified ? 0 : $modified;
				if ( null === $best || $modified > $best_modified ) {
					$best          = array(
						'id'        => (string) $file['id'],
						'name'      => $name,
						'mime_type' => $mime,
					);
					$best_modified = $modified;
				}
			}
		}

		$this->workbook_cache = $best;

		return $best;
	}

	/**
	 * Whether a MIME type represents an exportable/readable spreadsheet.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private function is_spreadsheet_mime( $mime ) {
		$mime = strtolower( (string) $mime );

		return in_array(
			$mime,
			array(
				'application/vnd.google-apps.spreadsheet',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.ms-excel',
				'application/vnd.oasis.opendocument.spreadsheet',
			),
			true
		);
	}

	/**
	 * Locate a per-table Drive file (split-file layout).
	 *
	 * Kept for backward compatibility with the original "one file per table"
	 * demo layout. Matching is case-insensitive and matches the bare sheet
	 * name, or the sheet name with a .csv / .xlsx / .xls extension.
	 *
	 * @param string $table_id Spec table ID.
	 * @param array  $auth     Auth array from token().
	 * @return array{id:string,name:string,mime_type:string}|WP_Error
	 */
	private function locate_split_file( $table_id, $auth ) {
		$names = $this->sheet_names( $table_id );
		foreach ( $this->folders() as $folder_id ) {
			foreach ( $this->list_folder( $folder_id, $auth ) as $file ) {
				$file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
				$stem      = $this->normalise_name( pathinfo( $file_name, PATHINFO_FILENAME ) );
				foreach ( $names as $name ) {
					$needle = $this->normalise_name( $name );
					if ( $stem === $needle ) {
						return array(
							'id'        => (string) $file['id'],
							'name'      => $file_name,
							'mime_type' => isset( $file['mimeType'] ) ? (string) $file['mimeType'] : '',
						);
					}
				}
			}
		}

		return new WP_Error(
			'wp_mcp_ai_fnb_sheet_not_found',
			sprintf(
				/* translators: 1: table id, 2: settings option name, 3: settings option name, 4: settings option name */
				__( 'No Drive file found for F&B table "%1$s" in the configured folders, and no workbook is configured. Set one of the following on the F&B settings page (option "wp_mcp_ai_fnb_settings"): "%2$s" to the Demo Data workbook ID, or "%3$s"/"%4$s" to folders containing the exported sheets.', 'mcp-ai-wpoos-pro' ),
				$table_id,
				'data_workbook_id',
				'data_folder_id',
				'setup_folder_id'
			)
		);
	}

	/**
	 * Read the rows of a specific worksheet by tab name from a workbook.
	 *
	 * Uses two passes: first list the workbook's tabs (so we can match the
	 * actual tab name, which may differ in casing/spacing), then read that
	 * tab's values. Falls back to reading the guessed tab name directly when
	 * metadata listing fails.
	 *
	 * @param array  $workbook Workbook metadata from locate_workbook().
	 * @param string $table_id Spec table ID.
	 * @param array  $auth     Auth array from token().
	 * @return array{columns:array<int,string>,rows:array<int,array>}|WP_Error
	 */
	private function read_worksheet( $workbook, $table_id, $auth ) {
		$names = $this->sheet_names( $table_id );

		$tab = null;

		// Try to discover the exact tab name from the spreadsheet metadata.
		$metadata = WP_MCP_AI_Pro_Google_Drive_Client::get_sheets_metadata(
			$workbook['id'],
			$auth['access_token'],
			$auth['timeout']
		);
		if ( ! is_wp_error( $metadata ) && isset( $metadata['sheets'] ) && is_array( $metadata['sheets'] ) ) {
			$tabs = array();
			foreach ( $metadata['sheets'] as $sheet ) {
				if ( isset( $sheet['properties']['title'] ) ) {
					$title          = (string) $sheet['properties']['title'];
					$tabs[ $title ] = $this->normalise_name( $title );
				}
			}

			foreach ( $names as $name ) {
				$needle = $this->normalise_name( $name );
				foreach ( $tabs as $title => $normalised ) {
					if ( $normalised === $needle ) {
						$tab = $title;
						break 2;
					}
				}
			}

			// Simpler fallback: substring match (e.g. tab "Daily Sales Report"
			// still contains "daily sales").
			if ( null === $tab ) {
				foreach ( $names as $name ) {
					$needle = $this->normalise_name( $name );
					if ( '' === $needle ) {
						continue;
					}
					foreach ( $tabs as $title => $normalised ) {
						if ( false !== strpos( $normalised, $needle ) ) {
							$tab = $title;
							break 2;
						}
					}
				}
			}
		}

		// No exact tab discovered — use the canonical sheet name directly and
		// let the API 400/404 out with a clear error if it doesn't exist.
		if ( null === $tab ) {
			$tab = isset( $names[0] ) ? $names[0] : '';
			if ( '' === $tab ) {
				return new WP_Error(
					'wp_mcp_ai_fnb_sheet_not_found',
					/* translators: %s: table id */
					sprintf( __( 'No worksheet mapped for F&B table "%s".', 'mcp-ai-wpoos-pro' ), $table_id )
				);
			}
		}

		$values = WP_MCP_AI_Pro_Google_Drive_Client::get_sheet_values(
			$workbook['id'],
			$tab,
			$auth['access_token'],
			$auth['timeout']
		);
		if ( is_wp_error( $values ) ) {
			return $values;
		}

		return $this->matrix_to_rows( isset( $values['values'] ) && is_array( $values['values'] ) ? $values['values'] : array() );
	}

	/**
	 * Convert a Sheets values matrix to the columns/rows contract.
	 *
	 * @param array<int,array<int,mixed>> $values Raw value matrix.
	 * @return array{columns:array<int,string>,rows:array<int,array>}
	 */
	private function matrix_to_rows( $values ) {
		$columns = array();
		$rows    = array();

		if ( empty( $values ) ) {
			return array(
				'columns' => $columns,
				'rows'    => $rows,
			);
		}

		$columns = array_map( 'strval', array_shift( $values ) );

		foreach ( $values as $row ) {
			$has_value = false;
			foreach ( $row as $cell ) {
				if ( null !== $cell && '' !== trim( (string) $cell ) ) {
					$has_value = true;
					break;
				}
			}
			if ( $has_value ) {
				$rows[] = array_map( 'strval', $row );
			}
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Export a Drive file as CSV text (split-file layout only).
	 *
	 * @param string $file_id Drive file ID.
	 * @param array  $auth    Auth array from token().
	 * @return string|WP_Error
	 */
	private function export_csv( $file_id, $auth ) {
		$csv = WP_MCP_AI_Pro_Google_Drive_Client::request_raw(
			'files/' . rawurlencode( $file_id ) . '/export',
			$auth['access_token'],
			$auth['timeout'],
			array( 'mimeType' => 'text/csv' )
		);

		if ( is_wp_error( $csv ) ) {
			return $csv;
		}

		return (string) $csv;
	}

	/**
	 * Parse CSV text into columns + rows.
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
			$has_value = false;
			foreach ( $data as $cell ) {
				if ( '' !== trim( (string) $cell ) ) {
					$has_value = true;
					break;
				}
			}
			if ( $has_value ) {
				$rows[] = $data;
			}
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error
	 */
	public function read_table( $table_id ) {
		$auth = $this->token();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		// Preferred path: a single workbook whose tabs hold the tables. This
		// matches the common real-world layout (one spreadsheet per client).
		$workbook = $this->locate_workbook( $auth );
		if ( is_array( $workbook ) ) {
			$result = $this->read_worksheet( $workbook, $table_id, $auth );
			if ( ! is_wp_error( $result ) ) {
				$result['source'] = 'fnb_google_sheets_reader';

				return $result;
			}

			// A worksheet-level error (e.g. the tab doesn't exist in this workbook)
			// should fall through to the split-file layout only when it indicates
			// the workbook isn't the right source — i.e. an HTTP 4xx (unknown tab
			// / no values) or an explicit "nothing here" error. Anything else
			// (auth, network, 5xx) is a real failure worth surfacing immediately.
			$error_data = $result->get_error_data();
			$status     = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
			$not_found  = ( 'wp_mcp_ai_drive_http_status' === $result->get_error_code() && $status >= 400 && $status < 500 );
			if ( ! $not_found ) {
				return $result;
			}
		} elseif ( $this->workbook_error instanceof WP_Error ) {
			// The explicit (or demo) workbook ID could not be read — surface the
			// real failure (auth/scope/400/404) rather than masking it as a
			// split-file "file not found". This is the distinct, upstream
			// Sheets-API break the read path should not conflate with lookup.
			return $this->workbook_error;
		}

		// Fallback: one file per table (original demo layout).
		$file = $this->locate_split_file( $table_id, $auth );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$csv = $this->export_csv( $file['id'], $auth );
		if ( is_wp_error( $csv ) ) {
			// An uploaded xlsx cannot be exported via the Drive API. Fall back
			// to the CSV fixture adapter so a local copy of the workbook keeps
			// the demo functional.
			if ( class_exists( 'WP_MCP_AI_Fnb_Fixture_Reader' ) ) {
				$fixture = new WP_MCP_AI_Fnb_Fixture_Reader();

				return $fixture->read_table( $table_id );
			}

			return $csv;
		}

		$parsed           = $this->parse_csv( $csv );
		$parsed['source'] = 'fnb_google_sheets_reader';

		return $parsed;
	}
}
