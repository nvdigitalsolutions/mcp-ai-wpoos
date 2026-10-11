<?php
/**
 * Food & Beverage Management Toolkit — Fixture Reader.
 *
 * CSV-backed table reader: powers the unit test suite without network access
 * and doubles as a no-network fallback adapter for local deployments.
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
 * CSV fixture reader adapter.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Fixture_Reader implements WP_MCP_AI_Fnb_Table_Reader {

	/**
	 * Sheet-name → table-ID map for the demo workbook.
	 *
	 * @var array<string,string>
	 */
	private static $sheet_map = array(
		'menu'            => '1.0',
		'recipes'         => '2.0',
		'ingredients'     => '3.0',
		'suppliers'       => '4.0',
		'supplier_prices' => '5.0',
		'daily_sales'     => '6.0',
		'daily_covers'    => '7.0',
		'purchases'       => '8.0',
		'stock_counts'    => '9.0',
		'waste_log'       => '10.0',
		'payroll'         => '11.0',
		'timesheets'      => '12.0',
		'utilities'       => '13.0',
		'expense_ledger'  => '14.0',
		'budgets'         => '15.0',
		'bookings'        => '16.0',
		'asset_log'       => '17.0',
		'assumptions'     => 'assumptions',
	);

	/**
	 * Directories searched for CSV files (in order).
	 *
	 * @return array<int,string>
	 */
	private function directories() {
		$dirs   = array( __DIR__ . '/tests/fixtures' );
		$custom = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		if ( is_string( $custom ) && '' !== $custom ) {
			$dirs[] = untrailingslashit( $custom );
		}

		return $dirs;
	}

	/**
	 * Locate the CSV file for a table ID.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string|false
	 */
	private function locate( $table_id ) {
		$slugs = array();
		foreach ( self::$sheet_map as $slug => $id ) {
			if ( $id === $table_id ) {
				$slugs[] = $slug;
			}
		}
		$slugs[] = str_replace( '.', '_', $table_id );

		foreach ( $this->directories() as $dir ) {
			foreach ( array_unique( $slugs ) as $slug ) {
				$path = trailingslashit( $dir ) . $slug . '.csv';
				if ( file_exists( $path ) ) {
					return $path;
				}
			}
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error
	 */
	public function read_table( $table_id ) {
		$path = $this->locate( $table_id );
		if ( false === $path ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_missing_fixture',
				/* translators: %s: table id */
				sprintf( __( 'No CSV fixture found for F&B table "%s".', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new WP_Error( 'wp_mcp_ai_fnb_fixture_unreadable', __( 'CSV fixture could not be opened.', 'mcp-ai-wpoos-pro' ) );
		}

		$rows    = array();
		$columns = array();
		$first   = true;
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard CSV read loop.
		while ( false !== ( $data = fgetcsv( $handle ) ) ) {
			if ( $first ) {
				$columns = $data;
				$first   = false;
				continue;
			}
			// Skip fully empty rows.
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
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array(
			'columns' => $columns,
			'rows'    => $rows,
			'source'  => 'fnb_fixture_reader',
		);
	}
}
