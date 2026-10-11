<?php
/**
 * Food & Beverage Management Toolkit — Google Sheets Reader.
 *
 * Drive-backed table reader: locates the demo workbook sheets in the
 * configured Data folder and exports them as CSV, reusing the google-workspace
 * toolkit's Drive client and connection machinery.
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
	 * Sheet names for a table ID, in lookup order.
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
	 * Find the Drive file for a table in the configured folder(s).
	 *
	 * @param string $table_id Spec table ID.
	 * @param array  $auth     Auth array from token().
	 * @return array{id:string,name:string}|WP_Error
	 */
	private function locate_file( $table_id, $auth ) {
		$folders = array_filter(
			array(
				WP_MCP_AI_Fnb_Settings::get( 'data_folder_id', '' ),
				WP_MCP_AI_Fnb_Settings::get( 'setup_folder_id', '' ),
			)
		);

		if ( empty( $folders ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_no_folder',
				__( 'No F&B Drive folder configured. Set the data/setup folder IDs in the F&B toolkit settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$names = $this->sheet_names( $table_id );
		foreach ( $folders as $folder_id ) {
			$children = WP_MCP_AI_Pro_Google_Drive_Client::list_children(
				(string) $folder_id,
				$auth['access_token'],
				$auth['timeout'],
				200
			);
			if ( is_wp_error( $children ) ) {
				continue;
			}

			$files = isset( $children['files'] ) && is_array( $children['files'] ) ? $children['files'] : array();
			foreach ( $names as $name ) {
				foreach ( $files as $file ) {
					$file_name = isset( $file['name'] ) ? strtolower( trim( (string) $file['name'] ) ) : '';
					$needle    = strtolower( $name );
					// Match "Daily sales" exactly or as a "Daily sales.csv" /
					// workbook-name suffix ("… - Demo Data" contains sheet names
					// only when split per-sheet, so exact match is primary).
					if ( $file_name === $needle || $file_name === $needle . '.csv' || $file_name === $needle . '.xlsx' ) {
						return array(
							'id'   => $file['id'],
							'name' => $file['name'],
						);
					}
				}
			}
		}

		return new WP_Error(
			'wp_mcp_ai_fnb_sheet_not_found',
			/* translators: %s: table id */
			sprintf( __( 'No Drive file found for F&B table "%s" in the configured folders.', 'mcp-ai-wpoos-pro' ), $table_id )
		);
	}

	/**
	 * Export a Drive file as CSV text.
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

		$file = $this->locate_file( $table_id, $auth );
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
