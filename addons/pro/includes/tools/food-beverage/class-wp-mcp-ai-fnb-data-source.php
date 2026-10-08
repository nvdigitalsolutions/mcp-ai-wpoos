<?php
/**
 * Food & Beverage Management Toolkit — Data Source.
 *
 * Registry of the 17 spec data tables + setup artifacts, the table-reader
 * interface, adapter selection, per-table transient caching, and the
 * header normalisation / column-alias machinery that tolerates column-name
 * drift ("table and column names may change" — spec overview sheet).
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

require_once __DIR__ . '/class-wp-mcp-ai-fnb-table-reader.php';

/**
 * F&B data source: registry + adapters + caching.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Data_Source {

	const CACHE_GROUP = 'wp_mcp_ai_fnb_tables';
	const CACHE_TTL   = 300; // 5 minutes.

	/**
	 * Spec table registry.
	 *
	 * Canonical columns list the keys used by the metric engine; `aliases`
	 * are the normalised header names that map to each canonical key.
	 *
	 * @var array<string,array{label:string,canonical:string[],aliases:array<string,string[]>}>
	 */
	private static $tables = array(
		'1.0'         => array(
			'label'     => 'Menu items',
			'canonical' => array( 'item_id', 'menu', 'section', 'item', 'price_lkr', 'type', 'source' ),
			'aliases'   => array(
				'item_id'   => array( 'item_id' ),
				'price_lkr' => array( 'price_lkr' ),
			),
		),
		'2.0'         => array(
			'label'     => 'Recipes',
			'canonical' => array( 'item_id', 'ingredient_id', 'qty_per_portion', 'unit', 'cost_per_portion' ),
			'aliases'   => array(
				'item_id'          => array( 'item_id' ),
				'ingredient_id'    => array( 'ingredient_id' ),
				'qty_per_portion'  => array( 'qty_per_portion' ),
				'cost_per_portion' => array( 'cost_per_portion' ),
			),
		),
		'3.0'         => array(
			'label'     => 'Ingredients',
			'canonical' => array( 'ingredient_id', 'ingredient', 'unit', 'category', 'group', 'storage', 'shelf_life_days', 'minimum_level', 'supplier_id' ),
			'aliases'   => array(
				'ingredient_id'   => array( 'ingredient_id' ),
				'shelf_life_days' => array( 'shelf_life_days' ),
				'minimum_level'   => array( 'minimum_level' ),
				'supplier_id'     => array( 'supplier_id' ),
			),
		),
		'4.0'         => array(
			'label'     => 'Suppliers',
			'canonical' => array( 'supplier_id', 'supplier', 'supplies', 'delivery_days', 'lead_time', 'order_cutoff', 'payment_terms_days' ),
			'aliases'   => array(
				'supplier_id'  => array( 'supplier_id' ),
				'lead_time'    => array( 'lead_time' ),
				'order_cutoff' => array( 'order_cutoff', 'order_cut_off' ),
			),
		),
		'5.0'         => array(
			'label'     => 'Supplier prices',
			'canonical' => array( 'supplier_id', 'ingredient_id', 'pack', 'pack_size', 'unit', 'pack_price_lkr', 'effective_from' ),
			'aliases'   => array(
				'supplier_id'    => array( 'supplier_id' ),
				'ingredient_id'  => array( 'ingredient_id' ),
				'pack_size'      => array( 'pack_size' ),
				'pack_price_lkr' => array( 'pack_price_lkr' ),
				'effective_from' => array( 'effective_from' ),
			),
		),
		'6.0'         => array(
			'label'     => 'Daily sales',
			'canonical' => array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price_lkr', 'revenue_lkr' ),
			'aliases'   => array(
				'date'      => array( 'date' ),
				'month'     => array( 'month' ),
				'item_id'   => array( 'item_id' ),
				'type'      => array( 'type' ),
				'qty_sold'  => array( 'qty_sold' ),
				'price_lkr' => array( 'price_lkr' ),
			),
		),
		'7.0'         => array(
			'label'     => 'Daily covers',
			'canonical' => array( 'date', 'day', 'month', 'lunch_covers', 'dinner_covers', 'bar_only_guests', 'total_covers' ),
			'aliases'   => array(
				'date'            => array( 'date' ),
				'month'           => array( 'month' ),
				'lunch_covers'    => array( 'lunch_covers' ),
				'dinner_covers'   => array( 'dinner_covers' ),
				'bar_only_guests' => array( 'bar_only_guests', 'bar_only' ),
				'total_covers'    => array( 'total_covers' ),
			),
		),
		'8.0'         => array(
			'label'     => 'Purchases',
			'canonical' => array( 'delivery_date', 'month', 'supplier_id', 'invoice', 'ingredient_id', 'category', 'packs', 'pack_size', 'qty_delivered', 'pack_price_lkr', 'line_total_lkr' ),
			'aliases'   => array(
				'delivery_date'  => array( 'delivery_date' ),
				'month'          => array( 'month' ),
				'supplier_id'    => array( 'supplier_id' ),
				'invoice'        => array( 'invoice' ),
				'ingredient_id'  => array( 'ingredient_id' ),
				'category'       => array( 'category' ),
				'qty_delivered'  => array( 'qty_delivered' ),
				'pack_price_lkr' => array( 'pack_price_lkr' ),
			),
		),
		'9.0'         => array(
			'label'     => 'Stock counts',
			'canonical' => array( 'count_date', 'count_type', 'period_start', 'ingredient_id', 'unit', 'category', 'opening', 'bought', 'closing', 'used', 'unit_cost_lkr', 'closing_value_lkr' ),
			'aliases'   => array(
				'count_date'        => array( 'count_date' ),
				'count_type'        => array( 'count_type' ),
				'period_start'      => array( 'period_start' ),
				'ingredient_id'     => array( 'ingredient_id' ),
				'category'          => array( 'category' ),
				'opening'           => array( 'opening' ),
				'bought'            => array( 'bought' ),
				'closing'           => array( 'closing' ),
				'unit_cost_lkr'     => array( 'unit_cost_lkr' ),
				'closing_value_lkr' => array( 'closing_value_lkr' ),
			),
		),
		'10.0'        => array(
			'label'     => 'Waste log',
			'canonical' => array( 'date', 'month', 'ingredient_id', 'ingredient', 'qty', 'unit', 'reason', 'logged_by', 'value_lkr' ),
			'aliases'   => array(
				'date'          => array( 'date' ),
				'month'         => array( 'month' ),
				'ingredient_id' => array( 'ingredient_id' ),
				'qty'           => array( 'qty' ),
				'reason'        => array( 'reason' ),
				'logged_by'     => array( 'logged_by' ),
				'value_lkr'     => array( 'value_lkr' ),
			),
		),
		'11.0'        => array(
			'label'     => 'Payroll',
			'canonical' => array( 'month', 'department', 'basic_pay_lkr', 'epf_lkr', 'etf_lkr', 'overtime_hours', 'overtime_pay_lkr', 'casual_shifts', 'casual_pay_lkr', 'total_payroll_cost_lkr' ),
			'aliases'   => array(
				'month'                  => array( 'month' ),
				'department'             => array( 'department' ),
				'overtime_hours'         => array( 'overtime_hours' ),
				'casual_shifts'          => array( 'casual_shifts' ),
				'total_payroll_cost_lkr' => array( 'total_payroll_cost_lkr' ),
			),
		),
		'12.0'        => array(
			'label'     => 'Timesheets',
			'canonical' => array( 'date', 'month', 'department', 'staff_on_duty', 'contracted_hours', 'overtime_hours', 'casual_shifts' ),
			'aliases'   => array(
				'date'             => array( 'date' ),
				'month'            => array( 'month' ),
				'department'       => array( 'department' ),
				'contracted_hours' => array( 'contracted_hours' ),
				'overtime_hours'   => array( 'overtime_hours' ),
				'casual_shifts'    => array( 'casual_shifts' ),
			),
		),
		'13.0'        => array(
			'label'     => 'Utilities',
			'canonical' => array( 'date', 'month', 'electricity_kwh', 'water_m3', 'lpg_cylinders' ),
			'aliases'   => array(
				'date'            => array( 'date' ),
				'month'           => array( 'month' ),
				'electricity_kwh' => array( 'electricity_kwh' ),
				'water_m3'        => array( 'water_m3', 'water_m_' ),
				'lpg_cylinders'   => array( 'lpg_cylinders', 'lpg_cylinders_changed' ),
			),
		),
		'14.0'        => array(
			'label'     => 'Expense ledger',
			'canonical' => array( 'entry_id', 'vendor', 'vendor_invoice', 'expense_line', 'group', 'for_month', 'invoice_date', 'amount_lkr', 'due_date', 'paid_date', 'status' ),
			'aliases'   => array(
				'entry_id'       => array( 'entry_id' ),
				'vendor'         => array( 'vendor' ),
				'vendor_invoice' => array( 'vendor_invoice' ),
				'expense_line'   => array( 'expense_line' ),
				'group'          => array( 'group' ),
				'for_month'      => array( 'for_month' ),
				'invoice_date'   => array( 'invoice_date' ),
				'amount_lkr'     => array( 'amount_lkr' ),
				'due_date'       => array( 'due_date' ),
				'paid_date'      => array( 'paid_date' ),
				'status'         => array( 'status' ),
			),
		),
		'15.0'        => array(
			'label'     => 'Budgets',
			'canonical' => array( 'month', 'line', 'budget_lkr' ),
			'aliases'   => array(
				'month'      => array( 'month' ),
				'line'       => array( 'line' ),
				'budget_lkr' => array( 'budget_lkr' ),
			),
		),
		'16.0'        => array(
			'label'     => 'Bookings',
			'canonical' => array( 'booking_id', 'date', 'day', 'time', 'session', 'type', 'guests', 'notes', 'status' ),
			'aliases'   => array(
				'booking_id' => array( 'booking_id' ),
				'date'       => array( 'date' ),
				'session'    => array( 'session' ),
				'type'       => array( 'type' ),
				'guests'     => array( 'guests' ),
				'status'     => array( 'status' ),
			),
		),
		'17.0'        => array(
			'label'     => 'Asset log',
			'canonical' => array( 'image_id', 'deity', 'created', 'theme', 'featured_item', 'post_date', 'channel', 'status' ),
			'aliases'   => array(
				'image_id'  => array( 'image_id' ),
				'deity'     => array( 'deity' ),
				'post_date' => array( 'post_date' ),
				'channel'   => array( 'channel' ),
				'status'    => array( 'status' ),
			),
		),
		'assumptions' => array(
			'label'     => 'Assumptions',
			'canonical' => array( 'item', 'value', 'unit', 'source' ),
			'aliases'   => array(),
		),
	);

	/**
	 * Single reader instance.
	 *
	 * @var WP_MCP_AI_Fnb_Table_Reader|null
	 */
	private static $reader;

	/**
	 * Normalise a raw header cell to a snake_case key.
	 *
	 * @param string $header Raw header.
	 * @return string
	 */
	public static function normalise_header( $header ) {
		$normalised = strtolower( (string) $header );
		// Transliterate superscript digits before the ASCII-only pass.
		$normalised = str_replace( array( '³', '²' ), array( '3', '2' ), $normalised );
		$normalised = preg_replace( '/[^a-z0-9]+/', '_', $normalised );

		return trim( $normalised, '_' );
	}

	/**
	 * Resolve the column index for a canonical key in a row set.
	 *
	 * Tolerates header drift via per-table aliases and normalisation.
	 *
	 * @param string $table_id Spec table ID.
	 * @param array  $headers  Normalised header list.
	 * @param string $canonical Canonical key.
	 * @return int|false Column index or false.
	 */
	public static function resolve_column( $table_id, $headers, $canonical ) {
		$candidates = array( $canonical );
		$table      = isset( self::$tables[ $table_id ] ) ? self::$tables[ $table_id ] : array();
		$alias_map  = isset( $table['aliases'][ $canonical ] ) ? $table['aliases'][ $canonical ] : array();
		$candidates = array_merge( $candidates, $alias_map );
		$flipped    = array_flip( $headers );
		foreach ( $candidates as $candidate ) {
			if ( isset( $flipped[ $candidate ] ) ) {
				return $flipped[ $candidate ];
			}
		}

		return false;
	}

	/**
	 * Get the reader adapter (drive, csv or fixture).
	 *
	 * @return WP_MCP_AI_Fnb_Table_Reader|WP_Error
	 */
	public static function reader() {
		if ( null !== self::$reader ) {
			return self::$reader;
		}

		$adapter = WP_MCP_AI_Fnb_Settings::get( 'adapter', 'drive' );

		if ( 'csv' === $adapter || defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Fnb_Fixture_Reader' ) ) {
				require_once __DIR__ . '/class-wp-mcp-ai-fnb-fixture-reader.php';
			}
			self::$reader = new WP_MCP_AI_Fnb_Fixture_Reader();
		} else {
			if ( ! class_exists( 'WP_MCP_AI_Fnb_Google_Sheets_Reader' ) ) {
				require_once __DIR__ . '/class-wp-mcp-ai-fnb-google-sheets-reader.php';
			}
			self::$reader = new WP_MCP_AI_Fnb_Google_Sheets_Reader();
		}

		return self::$reader;
	}

	/**
	 * List all registered table IDs and labels.
	 *
	 * @return array<int,array{id:string,label:string}>
	 */
	public static function list_tables() {
		$out = array();
		foreach ( self::$tables as $id => $table ) {
			$out[] = array(
				'id'    => $id,
				'label' => $table['label'],
			);
		}

		return $out;
	}

	/**
	 * Read a table (cached) and return columns + normalised rows.
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error array{table_id,label,columns,rows,count,source}
	 */
	public static function get_table( $table_id ) {
		if ( ! isset( self::$tables[ $table_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_unknown_table',
				/* translators: %s: table id */
				sprintf( __( 'Unknown F&B data table "%s".', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		$cache_key = 'fnb_table_' . md5( $table_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$reader = self::reader();
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$raw = $reader->read_table( $table_id );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$columns = isset( $raw['columns'] ) && is_array( $raw['columns'] ) ? $raw['columns'] : array();
		$rows    = isset( $raw['rows'] ) && is_array( $raw['rows'] ) ? $raw['rows'] : array();

		$normalised_headers = array_map( array( __CLASS__, 'normalise_header' ), $columns );

		$table = self::$tables[ $table_id ];
		$out   = array(
			'table_id'     => $table_id,
			'label'        => $table['label'],
			'columns'      => $columns,
			'rows'         => $rows,
			'count'        => count( $rows ),
			'source'       => 'fnb_data_source',
			'header_index' => $normalised_headers,
		);

		set_transient( $cache_key, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * Get the normalised header index for a table.
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|WP_Error
	 */
	public static function headers( $table_id ) {
		$table = self::get_table( $table_id );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		return $table['header_index'];
	}

	/**
	 * Fetch the value of a canonical column from a row.
	 *
	 * @param string $table_id  Spec table ID.
	 * @param array  $header_index Normalised header index.
	 * @param array  $row        Raw row values.
	 * @param string $canonical Canonical key.
	 * @param mixed  $fallback  Default value.
	 * @return mixed
	 */
	public static function cell( $table_id, $header_index, $row, $canonical, $fallback = '' ) {
		$idx = self::resolve_column( $table_id, $header_index, $canonical );
		if ( false === $idx ) {
			return $fallback;
		}

		return isset( $row[ $idx ] ) && '' !== $row[ $idx ] ? $row[ $idx ] : $fallback;
	}

	/**
	 * Read the Assumptions sheet as key-value pairs.
	 *
	 * @return array<string,array{value:mixed,unit:string,source:string}>
	 */
	public static function assumptions() {
		$table = self::get_table( 'assumptions' );
		if ( is_wp_error( $table ) ) {
			return array();
		}

		$headers = $table['header_index'];
		$out     = array();
		foreach ( $table['rows'] as $row ) {
			$item = self::cell( 'assumptions', $headers, $row, 'item' );
			if ( '' === $item ) {
				continue;
			}
			$out[ $item ] = array(
				'value'  => self::cell( 'assumptions', $headers, $row, 'value' ),
				'unit'   => self::cell( 'assumptions', $headers, $row, 'unit' ),
				'source' => self::cell( 'assumptions', $headers, $row, 'source' ),
			);
		}

		return $out;
	}

	/**
	 * Search a table with optional column/value and date-range filters.
	 *
	 * @param string      $table_id Spec table ID.
	 * @param string      $column   Canonical column to filter on.
	 * @param string      $value    Value to match (case-insensitive).
	 * @param string      $date_column Canonical date column.
	 * @param string|null $from     Start date (Y-m-d).
	 * @param string|null $to       End date (Y-m-d).
	 * @param int         $limit    Row limit.
	 * @return array|WP_Error
	 */
	public static function search_table( $table_id, $column = '', $value = '', $date_column = 'date', $from = null, $to = null, $limit = 500 ) {
		$table = self::get_table( $table_id );
		if ( is_wp_error( $table ) ) {
			return $table;
		}

		$headers = $table['header_index'];
		$matches = array();
		foreach ( $table['rows'] as $row ) {
			if ( '' !== $column ) {
				$cell = (string) self::cell( $table_id, $headers, $row, $column );
				if ( '' === $value ) {
					if ( '' === $cell ) {
						continue;
					}
				} elseif ( false === stripos( $cell, (string) $value ) ) {
					continue;
				}
			}

			if ( null !== $from || null !== $to ) {
				$cell_date = self::date_only( self::cell( $table_id, $headers, $row, $date_column ) );
				if ( null !== $from && '' !== $cell_date && $cell_date < $from ) {
					continue;
				}
				if ( null !== $to && '' !== $cell_date && $cell_date > $to ) {
					continue;
				}
			}

			$matches[] = $row;
			if ( count( $matches ) >= $limit ) {
				break;
			}
		}

		return array(
			'success'      => true,
			'table_id'     => $table_id,
			'label'        => $table['label'],
			'columns'      => $table['columns'],
			'header_index' => $headers,
			'rows'         => $matches,
			'count'        => count( $matches ),
			'source'       => 'fnb_data_source',
		);
	}

	/**
	 * Reduce a date/time value to Y-m-d.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function date_only( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		// Timestamps serialised by Sheets ("2026-07-01 00:00:00" or epoch).
		if ( is_numeric( $value ) ) {
			return gmdate( 'Y-m-d', (int) $value );
		}
		$date = substr( trim( $value ), 0, 10 );

		// Accept plain "YYYY-MM" month strings (payroll, budgets, ledger).
		if ( preg_match( '/^\d{4}-\d{2}$/', $date ) ) {
			return $date;
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * Month prefix (Y-m) of a date value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function month_of( $value ) {
		$date = self::date_only( $value );

		return '' === $date ? '' : substr( $date, 0, 7 );
	}

	/**
	 * Coerce a cell to float (strip thousands separators).
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function float_cell( $value ) {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		$clean = str_replace( array( ',', ' ' ), '', (string) $value );

		return is_numeric( $clean ) ? (float) $clean : 0.0;
	}
}
