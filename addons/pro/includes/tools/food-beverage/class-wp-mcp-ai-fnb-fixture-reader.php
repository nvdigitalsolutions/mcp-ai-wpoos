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
		$dirs   = array(
			__DIR__ . '/tests/fixtures',
			__DIR__ . '/data',
		);
		$custom = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		if ( is_string( $custom ) && '' !== $custom ) {
			$dirs[] = untrailingslashit( $custom );
		}

		return $dirs;
	}

	/**
	 * Locate the CSV file for a table ID.
	 *
	 * Resolution order: (1) operator `table_map` file hint, (2) the legacy slug
	 * match (deterministic, correct), (3) structural header match across the
	 * directory as a *last resort* (when infer_tables is on and the slug is
	 * absent). Structural matching never runs ahead of a known-good slug match
	 * — otherwise a file like ingredients.csv (which also carries supplier
	 * columns) could shadow suppliers.csv and corrupt dependent reads.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string|false
	 */
	private function locate( $table_id ) {
		// 1) Operator-authored file hint (highest trust).
		$map  = WP_MCP_AI_Fnb_Settings::table_map();
		$file = isset( $map[ $table_id ]['file'] ) ? (string) $map[ $table_id ]['file'] : '';
		if ( '' !== $file ) {
			foreach ( $this->directories() as $dir ) {
				$candidate = trailingslashit( $dir ) . basename( $file );
				if ( file_exists( $candidate ) ) {
					return $candidate;
				}
			}
		}

		// 2) Legacy slug match (deterministic).
		$slug_path = $this->locate_by_slug( $table_id );
		if ( false !== $slug_path ) {
			return $slug_path;
		}

		// 3) Structural header match — last resort only.
		if ( WP_MCP_AI_Fnb_Settings::infer_tables() ) {
			$inferred = $this->locate_by_structure( $table_id );
			if ( false !== $inferred ) {
				return $inferred;
			}
		}

		return false;
	}

	/**
	 * Resolve a table ID by the legacy sheet-map slug → `slug.csv`.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string|false
	 */
	private function locate_by_slug( $table_id ) {
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
	 * Infer the best-matching CSV file for a table ID by header structure.
	 *
	 * @param string $table_id Spec table ID.
	 * @return string|false
	 */
	private function locate_by_structure( $table_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Structure_Matcher' ) && file_exists( __DIR__ . '/class-wp-mcp-ai-fnb-structure-matcher.php' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-fnb-structure-matcher.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Structure_Matcher' ) ) {
			return false;
		}

		foreach ( $this->directories() as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = glob( trailingslashit( $dir ) . '*.csv' );
			if ( false === $files ) {
				$files = array();
			}
			foreach ( $files as $candidate ) {
				$headers = $this->read_header_row( $candidate );
				if ( empty( $headers ) ) {
					continue;
				}
				$score = WP_MCP_AI_Fnb_Structure_Matcher::match_table( $table_id, $headers );
				if ( is_array( $score ) && $score['confidence'] >= WP_MCP_AI_Fnb_Structure_Matcher::DEFAULT_THRESHOLD ) {
					return $candidate;
				}
			}
		}

		return false;
	}

	/**
	 * Read only the first non-empty row of a CSV as its header list.
	 *
	 * @param string $path Absolute CSV path.
	 * @return array<int,string>
	 */
	private function read_header_row( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return array();
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return array();
		}
		$headers = array();
		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Read until a non-empty row.
		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			$has_value = false;
			foreach ( $row as $cell ) {
				if ( '' !== trim( (string) $cell ) ) {
					$has_value = true;
					break;
				}
			}
			if ( $has_value ) {
				$headers = $row;
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return array_map( 'strval', $headers );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param string $table_id           Spec table ID.
	 * @param string $connection_override Ignored — fixtures are connection-less.
	 * @return array|WP_Error
	 */
	public function read_table( $table_id, $connection_override = '' ) {
		unset( $connection_override );

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
