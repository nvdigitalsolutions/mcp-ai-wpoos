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
	 * Curated real-world header → canonical-key alias dictionary.
	 *
	 * Mirrors the Healthcare Vitals `CSV_COLUMN_MAP` pattern: every variant,
	 * abbreviation and unit-suffixed spelling an operator is likely to see in an
	 * exported POS / supplier / payroll / inventory spread is mapped onto one
	 * canonical key, so the universal ingestor and `resolve_column()` both
	 * resolve messy headers without relying on narrow per-table aliases or
	 * fragile numeric scoring alone.
	 *
	 * Keys are already normalised (lowercase, underscores/spaces collapsed);
	 * lookups normalise the incoming header before comparing.
	 *
	 * @var array<string,string>
	 */
	const HEADER_ALIASES = array(
		// Identity + keys.
		'item id'           => 'item_id',
		'item_id'           => 'item_id',
		'sku'               => 'item_id',
		'product id'        => 'item_id',
		'ingredient id'     => 'ingredient_id',
		'ingredient_id'     => 'ingredient_id',
		'supplier id'       => 'supplier_id',
		'supplier_id'       => 'supplier_id',
		'booking id'        => 'booking_id',
		'entry id'          => 'entry_id',
		'vendor invoice'    => 'vendor_invoice',
		'invoice'           => 'invoice',
		'invoice no'        => 'invoice',
		'invoice number'    => 'invoice',
		'inv no'            => 'invoice',
		'inv'               => 'invoice',
		// Money (currency-neutral).
		'price'             => 'price',
		'unit price'        => 'price',
		'sale price'        => 'price',
		'price lkr'         => 'price',
		'revenue'           => 'revenue',
		'sales'             => 'revenue',
		'total'             => 'revenue',
		'gross'             => 'revenue',
		'net'               => 'revenue',
		'pack price'        => 'pack_price',
		'unit cost'         => 'unit_cost',
		'cost'              => 'unit_cost',
		'closing value'     => 'closing_value',
		'line total'        => 'line_total',
		'amount'            => 'amount',
		'expense amount'    => 'amount',
		'budget'            => 'budget',
		'budget lkr'        => 'budget',
		// Quantities.
		'qty sold'          => 'qty_sold',
		'quantity sold'     => 'qty_sold',
		'units sold'        => 'qty_sold',
		'qty delivered'     => 'qty_delivered',
		'quantity delivered' => 'qty_delivered',
		'qty'               => 'qty',
		'quantity'          => 'qty',
		'units'             => 'qty',
		'qty per portion'   => 'qty_per_portion',
		// Dates.
		'date'              => 'date',
		'day'               => 'day',
		'delivery date'     => 'delivery_date',
		'count date'        => 'count_date',
		'effective from'    => 'effective_from',
		'invoice date'      => 'invoice_date',
		'due date'          => 'due_date',
		'paid date'         => 'paid_date',
		'for month'         => 'for_month',
		'month'             => 'month',
		// Common operators' synonyms.
		'covers'            => 'total_covers',
		'lunch covers'      => 'lunch_covers',
		'dinner covers'     => 'dinner_covers',
		'bar only guests'   => 'bar_only_guests',
		'reason'            => 'reason',
		'logged by'         => 'logged_by',
		'notes'             => 'notes',
		'note'              => 'notes',
		'comments'          => 'notes',
		'status'            => 'status',
	);

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
			'canonical' => array( 'item_id', 'menu', 'section', 'item', 'price', 'type', 'source' ),
			'aliases'   => array(
				'item_id' => array( 'item_id' ),
				'price'   => array( 'price', 'price_lkr' ),
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
			'canonical' => array( 'supplier_id', 'ingredient_id', 'pack', 'pack_size', 'unit', 'pack_price', 'effective_from' ),
			'aliases'   => array(
				'supplier_id'    => array( 'supplier_id' ),
				'ingredient_id'  => array( 'ingredient_id' ),
				'pack_size'      => array( 'pack_size' ),
				'pack_price'     => array( 'pack_price', 'pack_price_lkr' ),
				'effective_from' => array( 'effective_from' ),
			),
		),
		'6.0'         => array(
			'label'     => 'Daily sales',
			'canonical' => array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price', 'revenue' ),
			'aliases'   => array(
				'date'     => array( 'date' ),
				'month'    => array( 'month' ),
				'item_id'  => array( 'item_id' ),
				'type'     => array( 'type' ),
				'qty_sold' => array( 'qty_sold' ),
				'price'    => array( 'price', 'price_lkr' ),
				'revenue'  => array( 'revenue', 'revenue_lkr' ),
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
			'canonical' => array( 'delivery_date', 'month', 'supplier_id', 'invoice', 'ingredient_id', 'category', 'packs', 'pack_size', 'qty_delivered', 'pack_price', 'line_total' ),
			'aliases'   => array(
				'delivery_date' => array( 'delivery_date' ),
				'month'         => array( 'month' ),
				'supplier_id'   => array( 'supplier_id' ),
				'invoice'       => array( 'invoice' ),
				'ingredient_id' => array( 'ingredient_id' ),
				'category'      => array( 'category' ),
				'qty_delivered' => array( 'qty_delivered' ),
				'pack_price'    => array( 'pack_price', 'pack_price_lkr' ),
				'line_total'    => array( 'line_total', 'line_total_lkr' ),
			),
		),
		'9.0'         => array(
			'label'     => 'Stock counts',
			'canonical' => array( 'count_date', 'count_type', 'period_start', 'ingredient_id', 'unit', 'category', 'opening', 'bought', 'closing', 'used', 'unit_cost', 'closing_value' ),
			'aliases'   => array(
				'count_date'    => array( 'count_date' ),
				'count_type'    => array( 'count_type' ),
				'period_start'  => array( 'period_start' ),
				'ingredient_id' => array( 'ingredient_id' ),
				'category'      => array( 'category' ),
				'opening'       => array( 'opening' ),
				'bought'        => array( 'bought' ),
				'closing'       => array( 'closing' ),
				'unit_cost'     => array( 'unit_cost', 'unit_cost_lkr' ),
				'closing_value' => array( 'closing_value', 'closing_value_lkr' ),
			),
		),
		'10.0'        => array(
			'label'     => 'Waste log',
			'canonical' => array( 'date', 'month', 'ingredient_id', 'ingredient', 'qty', 'unit', 'reason', 'logged_by', 'value' ),
			'aliases'   => array(
				'date'          => array( 'date' ),
				'month'         => array( 'month' ),
				'ingredient_id' => array( 'ingredient_id' ),
				'qty'           => array( 'qty' ),
				'reason'        => array( 'reason' ),
				'logged_by'     => array( 'logged_by' ),
				'value'         => array( 'value', 'value_lkr' ),
			),
		),
		'11.0'        => array(
			'label'     => 'Payroll',
			'canonical' => array( 'month', 'department', 'basic_pay', 'epf', 'etf', 'overtime_hours', 'overtime_pay', 'casual_shifts', 'casual_pay', 'total_payroll_cost' ),
			'aliases'   => array(
				'month'                => array( 'month' ),
				'department'           => array( 'department' ),
				'basic_pay'            => array( 'basic_pay', 'basic_pay_lkr' ),
				'epf'                  => array( 'epf', 'epf_lkr' ),
				'etf'                  => array( 'etf', 'etf_lkr' ),
				'overtime_hours'       => array( 'overtime_hours' ),
				'overtime_pay'         => array( 'overtime_pay', 'overtime_pay_lkr' ),
				'casual_shifts'        => array( 'casual_shifts' ),
				'casual_pay'           => array( 'casual_pay', 'casual_pay_lkr' ),
				'total_payroll_cost'   => array( 'total_payroll_cost', 'total_payroll_cost_lkr' ),
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
			'canonical' => array( 'entry_id', 'vendor', 'vendor_invoice', 'expense_line', 'group', 'for_month', 'invoice_date', 'amount', 'due_date', 'paid_date', 'status' ),
			'aliases'   => array(
				'entry_id'       => array( 'entry_id' ),
				'vendor'         => array( 'vendor' ),
				'vendor_invoice' => array( 'vendor_invoice' ),
				'expense_line'   => array( 'expense_line' ),
				'group'          => array( 'group' ),
				'for_month'      => array( 'for_month' ),
				'invoice_date'   => array( 'invoice_date' ),
				'amount'         => array( 'amount', 'amount_lkr' ),
				'due_date'       => array( 'due_date' ),
				'paid_date'      => array( 'paid_date' ),
				'status'         => array( 'status' ),
			),
		),
		'15.0'        => array(
			'label'     => 'Budgets',
			'canonical' => array( 'month', 'line', 'budget' ),
			'aliases'   => array(
				'month'  => array( 'month' ),
				'line'   => array( 'line' ),
				'budget' => array( 'budget', 'budget_lkr' ),
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
	 * Single native (CPT-backed) reader instance.
	 *
	 * @var WP_MCP_AI_Fnb_Table_Reader|null
	 */
	private static $native_reader;

	/**
	 * Public accessor for a table registry entry.
	 *
	 * Used by the CPT reader and the import bridge so rows, columns and
	 * canonical keys stay aligned with the spec table definitions.
	 *
	 * @param string $table_id Spec table ID.
	 * @return array|null Table definition or null when unknown.
	 */
	public static function table_definition( $table_id ) {
		return isset( self::$tables[ $table_id ] ) ? self::$tables[ $table_id ] : null;
	}

	/**
	 * Normalise a header cell to a snake_case key.
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
	 * Resolve a raw (un-normalised) header to a canonical key via the curated
	 * HEADER_ALIASES dictionary.
	 *
	 * Provides the messy-real-world-import mapping (vendor abbreviations,
	 * unit-suffixed spellings, common synonyms) that the per-table `aliases`
	 * map doesn't cover. Returns the incoming header unchanged when no
	 * dictionary entry matches (so the normal per-table alias + normalisation
	 * path still applies).
	 *
	 * @param string $header Raw header value.
	 * @return string Canonical key, or the input when unknown.
	 */
	public static function resolve_header_alias( $header ) {
		// Normalise the same way the dictionary keys are stored (spaces resolved,
		// not underscores) for matching.
		$key = strtolower( trim( (string) $header ) );
		$key = str_replace( array( '_', '-' ), ' ', $key );
		$key = preg_replace( '/\s+/', ' ', $key );

		if ( isset( self::HEADER_ALIASES[ $key ] ) ) {
			return self::HEADER_ALIASES[ $key ];
		}

		// Fall back to the snake_case normalisation so the dictionary still
		// works with underscore-style headers.
		$snake = self::normalise_header( $header );
		if ( isset( self::HEADER_ALIASES[ $snake ] ) ) {
			return self::HEADER_ALIASES[ $snake ];
		}

		return (string) $header;
	}

	/**
	 * Resolve the column index for a canonical key in a row set.
	 *
	 * Tolerates header drift via per-table aliases, the curated
	 * HEADER_ALIASES dictionary and normalisation.
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

		$adapter     = WP_MCP_AI_Fnb_Settings::get( 'adapter', 'drive' );
		$ingest_mode = WP_MCP_AI_Fnb_Settings::get( 'ingest_mode', 'folder' );
		$folder_id   = trim( (string) WP_MCP_AI_Fnb_Settings::get( 'data_folder_id', '' ) );

		if ( 'folder' === $ingest_mode && 'drive' === $adapter && '' !== $folder_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
				require_once __DIR__ . '/class-wp-mcp-ai-fnb-universal-reader.php';
			}
			self::$reader = new WP_MCP_AI_Fnb_Universal_Reader();

			return self::$reader;
		}

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
	 * Get the native (CPT-backed) reader adapter.
	 *
	 * @return WP_MCP_AI_Fnb_Table_Reader|WP_Error
	 */
	public static function native_reader() {
		if ( null !== self::$native_reader ) {
			return self::$native_reader;
		}

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-fnb-cpt-reader.php';
		}
		self::$native_reader = new WP_MCP_AI_Fnb_Cpt_Reader();

		return self::$native_reader;
	}

	/**
	 * Whether a table resolves through native storage on this request.
	 *
	 * @param string $table_id Spec table ID.
	 * @return bool
	 */
	public static function is_native_table( $table_id ) {
		if ( ! WP_MCP_AI_Fnb_Settings::is_native_storage_enabled() ) {
			return false;
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-fnb-cpt-reader.php';
		}

		return WP_MCP_AI_Fnb_Cpt_Reader::is_native_table( $table_id );
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
	 * When native storage is enabled, the five transactional tables resolve
	 * through the CPT reader; every other table keeps the Drive/CSV adapter.
	 * The import bridge passes `$native = false` to always read the source
	 * backend, and metrics/reports pass nothing to follow the toggle.
	 *
	 * @param string    $table_id Spec table ID.
	 * @param bool|null $native   Optional backend override: true = CPT, false = Drive/CSV, null = follow the toggle.
	 * @return array|WP_Error array{table_id,label,columns,rows,count,source}
	 */
	public static function get_table( $table_id, $native = null ) {
		if ( ! isset( self::$tables[ $table_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_unknown_table',
				/* translators: %s: table id */
				sprintf( __( 'Unknown F&B data table "%s".', 'mcp-ai-wpoos-pro' ), $table_id )
			);
		}

		$use_native = null === $native ? self::is_native_table( $table_id ) : (bool) $native;
		$cache_key  = 'fnb_table_' . md5( $table_id . ( $use_native ? ':cpt' : '' ) );
		$cached     = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$reader = $use_native ? self::native_reader() : self::reader();
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
			'source'       => $use_native ? 'fnb_native_cpt' : 'fnb_data_source',
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
