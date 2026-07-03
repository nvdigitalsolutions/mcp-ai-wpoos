<?php
/**
 * Shopify Sync CCT Manager.
 *
 * Manages the JetEngine Custom Content Type (CCT) that serves as the local
 * cache for Shopify inventory, product, and order data. Handles column
 * auto-creation, upsert operations from JSONL (bulk operation output),
 * and cached read queries — so tools never hit the Shopify API for reads.
 *
 * Reuses the existing WP_MCP_AI_Shopify_Client for GraphQL operations
 * (bulk queries, single-product sync). The client is consumed as-is
 * with no modifications.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {

	/**
	 * Shopify Sync CCT Manager.
	 *
	 * Provides read/write access to the Shopify inventory sync CCT.
	 * All public methods return canonical shapes ready for tool envelopes.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Shopify_Sync_CCT_Manager {

		/**
		 * Default CCT slug.
		 *
		 * @var string
		 */
		const CCT_SLUG_DEFAULT = 'shopify_inventory_sync';

		/**
		 * Base ID for meta field identifiers (41000 range).
		 *
		 * @var int
		 */
		const FIELD_ID_BASE = 41000;

		/**
		 * Current CCT slug.
		 *
		 * @var string
		 */
		protected $cct_slug = '';

		/**
		 * Remote Sites connection ID for the Shopify store.
		 *
		 * @var string|null
		 */
		protected $connection_id = null;

		/**
		 * Column definitions for the CCT.
		 *
		 * Keys are column names, values are JetEngine field types.
		 *
		 * @var array
		 */
		protected $columns = array(
			'shopify_product_id' => 'text',
			'shopify_variant_id' => 'text',
			'inventory_item_id'  => 'text',
			'sku'                => 'text',
			'product_title'      => 'text',
			'variant_title'      => 'text',
			'product_type'       => 'text',
			'vendor'             => 'text',
			'tags'               => 'text',
			'status'             => 'text',
			'location_id'        => 'text',
			'location_name'      => 'text',
			'available_qty'      => 'number',
			'on_hand_qty'        => 'number',
			'incoming_qty'       => 'number',
			'reserved_qty'       => 'number',
			'price'              => 'number',
			'compare_at_price'   => 'number',
			'image_url'          => 'text',
			'handle'             => 'text',
			'woo_product_id'     => 'number',
			'woo_variation_id'   => 'number',
			'shopify_updated_at' => 'datetime-local',
			'last_synced_at'     => 'datetime-local',
			'sync_hash'          => 'text',
			'sync_status'        => 'text',
			'raw_data'           => 'textarea',
		);

		/**
		 * Constructor.
		 *
		 * @since 1.3.0
		 *
		 * @param string|null $connection_id Optional. Remote Sites connection ID.
		 */
		public function __construct( $connection_id = null ) {
			$this->connection_id = $connection_id;
			$this->cct_slug      = $this->get_configured_cct_slug();
		}

		// ------------------------------------------------------------------ //
		// Configuration                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the configured CCT slug from toolkit settings.
		 *
		 * @since 1.3.0
		 *
		 * @return string
		 */
		public function get_configured_cct_slug() {
			$settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			return ! empty( $settings['cct_slug'] )
				? sanitize_key( $settings['cct_slug'] )
				: self::CCT_SLUG_DEFAULT;
		}

		/**
		 * Get the current CCT slug.
		 *
		 * @since 1.3.0
		 *
		 * @return string
		 */
		public function get_cct_slug() {
			return $this->cct_slug;
		}

		/**
		 * Get the connection ID.
		 *
		 * @since 1.3.0
		 *
		 * @return string|null
		 */
		public function get_connection_id() {
			return $this->connection_id;
		}

		/**
		 * Set the CCT slug.
		 *
		 * @since 1.3.0
		 *
		 * @param string $slug CCT slug.
		 */
		public function set_cct_slug( $slug ) {
			$this->cct_slug = sanitize_key( $slug );
		}

		/**
		 * Check if JetEngine and the CCT are available.
		 *
		 * @since 1.3.0
		 *
		 * @return bool|WP_Error True if available, WP_Error otherwise.
		 */
		public function is_cct_available() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_jetengine_missing',
					__( 'JetEngine plugin is required for Shopify Sync storage. Please install and activate JetEngine.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Use the module system instead of jet_engine()->cct directly.
			// The ->cct shorthand can be null when the CCT module is inactive,
			// even on a fully-loaded request. get_cct_module() properly checks
			// is_module_active() and returns the instance via modules API.
			$cct_module = self::get_cct_module();
			if ( ! $cct_module ) {
				// Try a one-shot activation in case it wasn't enabled yet.
				self::maybe_enable_cct_module();
				$cct_module = self::get_cct_module();
			}

			if ( ! $cct_module ) {
				// Table-exists fallback (follows the vitals-log CCT pattern).
				if ( $this->table_exists() ) {
					return true;
				}

				return new WP_Error(
					'wp_mcp_ai_shopify_sync_jetengine_not_ready',
					__( 'JetEngine Custom Content Types module is not active. Please enable it in JetEngine → JetEngine Settings → Modules.', 'mcp-ai-wpoos-pro' )
				);
			}

			$data = $cct_module->manager->data;

			if ( empty( $data->db ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_cct_not_ready',
					__( 'JetEngine CCT database is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$records = $data->db->query(
				'post_types',
				array(
					'slug'   => $this->cct_slug,
					'status' => 'content-type',
				),
				null,
				false
			);

			if ( empty( $records ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_cct_missing',
					sprintf(
						/* translators: %s: CCT slug */
						__( 'JetEngine CCT "%s" does not exist. Please create it or trigger a sync to auto-create it.', 'mcp-ai-wpoos-pro' ),
						esc_html( $this->cct_slug )
					)
				);
			}

			return true;
		}

		/**
		 * Check whether the CCT database table physically exists.
		 *
		 * Follows the vitals-log CCT pattern: a direct SHOW TABLES query
		 * that bypasses JetEngine's module system entirely.  Used as a
		 * lightweight fallback when get_cct_module() can't obtain a handle
		 * but the table was already created by a prior sync.
		 *
		 * @since 1.7.1
		 *
		 * @return bool
		 */
		protected function table_exists() {
			global $wpdb;
			$table = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}

		/**
		 * Ensure the JetEngine CCT exists, creating it if needed.
		 *
		 * Uses JetEngine's set_request() / create_item(false) pipeline
		 * (same pattern used by Vitals Log CCT and FlowHub CCT) instead
		 * of calling create_item() directly with raw data, which fails
		 * because the data format mismatches JetEngine's expected shape.
		 *
		 * @since 1.3.0
		 *
		 * @return array|WP_Error Result array with 'created' (bool) and
		 *                         'cct_id' (int|false), or WP_Error on failure.
		 */
		public function ensure_cct_exists() {
			// First try the static bootstrap path.
			if ( method_exists( __CLASS__, 'maybe_register_cct' ) ) {
				self::maybe_register_cct();
			}

			// Then check if it exists.
			$available = $this->is_cct_available();

			if ( is_wp_error( $available ) ) {
				if ( 'wp_mcp_ai_shopify_sync_jetengine_not_ready' === $available->get_error_code() ) {
					return $available;
				}
				if ( 'wp_mcp_ai_shopify_sync_cct_missing' === $available->get_error_code() ) {
					return $available;
				}
				return $available;
			}

			return array(
				'created' => false,
				'slug'    => $this->cct_slug,
			);
		}

		// ------------------------------------------------------------------ //
		// Column Management                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Get the column definitions.
		 *
		 * @since 1.3.0
		 *
		 * @return array
		 */
		public function get_column_definitions() {
			return $this->columns;
		}

		/**
		 * Ensure all required columns exist in the CCT.
		 *
		 * Auto-creates any missing columns via JetEngine API.
		 *
		 * @since 1.3.0
		 *
		 * @return int|WP_Error Number of columns created or WP_Error.
		 */
		public function ensure_columns() {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			global $wpdb;
			$table   = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;
			$created = 0;

			// Guard: table must exist before we query its columns.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $table_exists ) {
				return 0;
			}

			$existing_fields = $this->get_existing_cct_fields();

			foreach ( $this->columns as $column_name => $column_type ) {
				// Check JetEngine CCT field definitions first (fast path).
				if ( in_array( $column_name, $existing_fields, true ) ) {
					continue;
				}

				// Fallback: check MySQL directly — the column may exist in the DB
				// even though JetEngine's meta_fields config doesn't list it.
				if ( $this->column_exists_in_table( $table, $column_name ) ) {
					continue;
				}

				$sql_type = self::map_jet_type_to_sql( $column_type );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column_name}` {$sql_type} NULL DEFAULT NULL" );
				++$created;
			}

			/**
			 * Fires after CCT columns are ensured for Shopify Sync.
			 *
			 * @since 1.3.0
			 *
			 * @param string $cct_slug      The CCT slug.
			 * @param int    $created       Number of columns created.
			 * @param string $connection_id The Shopify connection ID.
			 */
			do_action( 'wp_mcp_ai_shopify_sync_after_columns_ensure', $this->cct_slug, $created, $this->connection_id );

			return $created;
		}

		/**
		 * Get existing CCT field names.
		 *
		 * @since 1.3.0
		 *
		 * @return array Array of field name strings.
		 */
		protected function get_existing_cct_fields() {
			$cct = $this->get_cct_record_by_slug( $this->cct_slug );

			if ( ! $cct || empty( $cct['meta_fields'] ) ) {
				return array();
			}

			$meta_fields = $cct['meta_fields'];
			if ( is_string( $meta_fields ) ) {
				$meta_fields = json_decode( $meta_fields, true );
			}

			if ( ! is_array( $meta_fields ) ) {
				return array();
			}

			return wp_list_pluck( $meta_fields, 'name' );
		}

		/**
		 * Check whether a column already exists in a given MySQL table.
		 *
		 * Used as a safety net when JetEngine's CCT meta_fields config
		 * may be out of sync with the actual table schema.
		 *
		 * @since 1.3.0
		 *
		 * @param string $table       MySQL table name.
		 * @param string $column_name Column name to check.
		 * @return bool True if the column exists.
		 */
		protected function column_exists_in_table( $table, $column_name ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$table,
					$column_name
				)
			);

			return ! empty( $result );
		}

		/**
		 * Map a JetEngine field type to a MySQL column type.
		 *
		 * Used by ensure_columns() for direct ALTER TABLE statements
		 * when the jet_engine()->cct API is unavailable.
		 *
		 * @since 1.3.0
		 *
		 * @param string $jet_type JetEngine field type.
		 * @return string MySQL column type.
		 */
		protected static function map_jet_type_to_sql( $jet_type ) {
			$map = array(
				'text'           => 'TEXT',
				'textarea'       => 'LONGTEXT',
				'number'         => 'BIGINT(20)',
				'datetime-local' => 'DATETIME',
				'datetime'       => 'DATETIME',
			);
			return isset( $map[ $jet_type ] ) ? $map[ $jet_type ] : 'TEXT';
		}

		/**
		 * Get a human-readable label for a CCT column.
		 *
		 * @since 1.3.0
		 *
		 * @param string $column_name Column name.
		 * @return string
		 */
		protected function get_column_label( $column_name ) {
			$labels = array(
				'shopify_product_id' => __( 'Shopify Product ID', 'mcp-ai-wpoos-pro' ),
				'shopify_variant_id' => __( 'Shopify Variant ID', 'mcp-ai-wpoos-pro' ),
				'inventory_item_id'  => __( 'Inventory Item ID', 'mcp-ai-wpoos-pro' ),
				'sku'                => __( 'SKU', 'mcp-ai-wpoos-pro' ),
				'product_title'      => __( 'Product Title', 'mcp-ai-wpoos-pro' ),
				'variant_title'      => __( 'Variant Title', 'mcp-ai-wpoos-pro' ),
				'product_type'       => __( 'Product Type', 'mcp-ai-wpoos-pro' ),
				'vendor'             => __( 'Vendor', 'mcp-ai-wpoos-pro' ),
				'tags'               => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'status'             => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'location_id'        => __( 'Location ID', 'mcp-ai-wpoos-pro' ),
				'location_name'      => __( 'Location Name', 'mcp-ai-wpoos-pro' ),
				'available_qty'      => __( 'Available Qty', 'mcp-ai-wpoos-pro' ),
				'on_hand_qty'        => __( 'On Hand Qty', 'mcp-ai-wpoos-pro' ),
				'incoming_qty'       => __( 'Incoming Qty', 'mcp-ai-wpoos-pro' ),
				'reserved_qty'       => __( 'Reserved Qty', 'mcp-ai-wpoos-pro' ),
				'price'              => __( 'Price', 'mcp-ai-wpoos-pro' ),
				'compare_at_price'   => __( 'Compare At Price', 'mcp-ai-wpoos-pro' ),
				'image_url'          => __( 'Image URL', 'mcp-ai-wpoos-pro' ),
				'handle'             => __( 'Handle', 'mcp-ai-wpoos-pro' ),
				'woo_product_id'     => __( 'WC Product ID', 'mcp-ai-wpoos-pro' ),
				'woo_variation_id'   => __( 'WC Variation ID', 'mcp-ai-wpoos-pro' ),
				'shopify_updated_at' => __( 'Shopify Updated At', 'mcp-ai-wpoos-pro' ),
				'last_synced_at'     => __( 'Last Synced At', 'mcp-ai-wpoos-pro' ),
				'sync_hash'          => __( 'Sync Hash', 'mcp-ai-wpoos-pro' ),
				'sync_status'        => __( 'Sync Status', 'mcp-ai-wpoos-pro' ),
				'raw_data'           => __( 'Raw API Data', 'mcp-ai-wpoos-pro' ),
			);

			return isset( $labels[ $column_name ] ) ? $labels[ $column_name ] : ucwords( str_replace( '_', ' ', $column_name ) );
		}

		// ------------------------------------------------------------------ //
		// Read Operations (CCT queries, no API calls)                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get cached items with filtering, sorting, and pagination.
		 *
		 * @since 1.3.0
		 *
		 * @param array $filters Optional. Filter parameters.
		 * @return array Array of CCT items.
		 */
		public function get_cached_items( $filters = array() ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return array();
			}

			$per_page = isset( $filters['per_page'] ) ? min( absint( $filters['per_page'] ), 100 ) : 50;
			$page     = isset( $filters['page'] ) ? max( 1, absint( $filters['page'] ) ) : 1;

			$query_args = array(
				'number'  => $per_page,
				'offset'  => ( $page - 1 ) * $per_page,
				'order'   => isset( $filters['order'] ) && 'asc' === $filters['order'] ? 'ASC' : 'DESC',
				'orderby' => $this->get_orderby_field( isset( $filters['orderby'] ) ? $filters['orderby'] : 'last_synced_at' ),
				'status'  => 'publish',
			);

			// Full-text search across product_title and sku.
			if ( ! empty( $filters['search'] ) ) {
				$query_args['s'] = sanitize_text_field( $filters['search'] );
			}

			// Meta queries for structured filters.
			$meta_query = array();

			if ( ! empty( $filters['vendor'] ) ) {
				$meta_query[] = array(
					'key'     => 'vendor',
					'value'   => sanitize_text_field( $filters['vendor'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['product_type'] ) ) {
				$meta_query[] = array(
					'key'     => 'product_type',
					'value'   => sanitize_text_field( $filters['product_type'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['location_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'location_id',
					'value'   => sanitize_text_field( $filters['location_id'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['location_name'] ) ) {
				$meta_query[] = array(
					'key'     => 'location_name',
					'value'   => sanitize_text_field( $filters['location_name'] ),
					'compare' => 'LIKE',
				);
			}

			if ( ! empty( $filters['sku'] ) ) {
				$meta_query[] = array(
					'key'     => 'sku',
					'value'   => sanitize_text_field( $filters['sku'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['variant_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'shopify_variant_id',
					'value'   => sanitize_text_field( $filters['variant_id'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['product_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'shopify_product_id',
					'value'   => sanitize_text_field( $filters['product_id'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['status'] ) ) {
				$meta_query[] = array(
					'key'     => 'status',
					'value'   => sanitize_text_field( $filters['status'] ),
					'compare' => '=',
				);
			}

			// Stock status filter.
			if ( ! empty( $filters['stock_status'] ) ) {
				$settings      = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
				$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

				switch ( $filters['stock_status'] ) {
					case 'in_stock':
						$meta_query[] = array(
							'key'     => 'available_qty',
							'value'   => $low_threshold,
							'compare' => '>=',
							'type'    => 'NUMERIC',
						);
						break;
					case 'low_stock':
						$meta_query[] = array(
							'key'     => 'available_qty',
							'value'   => array( 1, $low_threshold - 1 ),
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						);
						break;
					case 'out_of_stock':
						$meta_query[] = array(
							'key'     => 'available_qty',
							'value'   => 0,
							'compare' => '<=',
							'type'    => 'NUMERIC',
						);
						break;
				}
			}

			if ( ! empty( $meta_query ) ) {
				$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			$items = jet_engine()->cct->data->get_items( $this->cct_slug, $query_args );

			return is_array( $items ) ? $items : array();
		}

		/**
		 * Get a single cached item by identifier.
		 *
		 * @since 1.3.0
		 *
		 * @param string $identifier The lookup value.
		 * @param string $by         Lookup field: 'sku', 'variant_id', 'product_id', or 'cct_id'.
		 * @return array|null Item array or null if not found.
		 */
		public function get_cached_item( $identifier, $by = 'sku' ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return null;
			}

			$identifier = sanitize_text_field( $identifier );
			$by         = sanitize_key( $by );

			if ( 'cct_id' === $by ) {
				return jet_engine()->cct->data->get_item( absint( $identifier ), $this->cct_slug );
			}

			$filters = array(
				$by        => $identifier,
				'per_page' => 1,
			);

			$items = $this->get_cached_items( $filters );

			return ! empty( $items ) ? $items[0] : null;
		}

		/**
		 * Get a cached item by Shopify variant GID and optional location.
		 *
		 * @since 1.3.0
		 *
		 * @param string      $variant_gid Shopify variant GID.
		 * @param string|null $location_id Optional location GID for disambiguation.
		 * @return array|null Item array or null if not found.
		 */
		public function get_cached_item_by_variant_id( $variant_gid, $location_id = null ) {
			$filters = array(
				'variant_id' => sanitize_text_field( $variant_gid ),
				'per_page'   => 1,
			);

			if ( null !== $location_id ) {
				$filters['location_id'] = sanitize_text_field( $location_id );
			}

			$items = $this->get_cached_items( $filters );

			return ! empty( $items ) ? $items[0] : null;
		}

		/**
		 * Get distinct values for a CCT column (for filter dropdowns/analytics).
		 *
		 * @since 1.3.0
		 *
		 * @param string $column Column name.
		 * @return array Array of distinct values.
		 */
		public function get_distinct_values( $column ) {
			global $wpdb;

			$column = sanitize_key( $column );
			$table  = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;

			// Column and table names are sanitized with sanitize_key() above.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$results = $wpdb->get_col(
				"SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` != '' ORDER BY `{$column}` ASC LIMIT 100"
			);
			// phpcs:enable

			return is_array( $results ) ? $results : array();
		}

		/**
		 * Get the total row count.
		 *
		 * @since 1.3.0
		 *
		 * @return int
		 */
		public function get_row_count() {
					global $wpdb;
					$table = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;

					// Bail gracefully if the table doesn't exist (CCT not created yet).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $table_exists ) {
				return 0;
			}

					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
					// phpcs:enable

					return absint( $count );
		}

		/**
		 * Check if the cached data is fresh.
		 *
		 * @since 1.3.0
		 *
		 * @param int $max_age_seconds Maximum age in seconds. Default 900 (15 minutes).
		 * @return bool True if fresh, false if stale.
		 */
		public function is_fresh( $max_age_seconds = 900 ) {
			$last_sync = get_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, '' );

			if ( empty( $last_sync ) ) {
				return false;
			}

			$last_sync_time = strtotime( $last_sync );
			if ( false === $last_sync_time ) {
				return false;
			}

			return ( time() - $last_sync_time ) < absint( $max_age_seconds );
		}

		/**
		 * Get the last sync timestamp.
		 *
		 * @since 1.3.0
		 *
		 * @return string ISO 8601 timestamp or empty string.
		 */
		public function get_last_sync_time() {
			return get_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, '' );
		}

		/**
		 * Get aggregated inventory summary from CCT.
		 *
		 * @since 1.3.0
		 *
		 * @return array Summary data.
		 */
		public function get_inventory_summary() {
			$all = $this->get_cached_items( array( 'per_page' => 100 ) );

			$total_items     = 0;
			$total_value     = 0.0;
			$total_available = 0;
			$locations       = array();
			$vendors         = array();

			foreach ( $all as $item ) {
				$qty   = absint( isset( $item['available_qty'] ) ? $item['available_qty'] : 0 );
				$price = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );

				$total_items     += $qty;
				$total_value     += $qty * $price;
				$total_available += $qty;

				$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
				if ( ! isset( $locations[ $loc ] ) ) {
					$locations[ $loc ] = 0;
				}
				$locations[ $loc ] += $qty;

				$ven = isset( $item['vendor'] ) ? $item['vendor'] : 'Unknown';
				if ( ! isset( $vendors[ $ven ] ) ) {
					$vendors[ $ven ] = array(
						'items' => 0,
						'value' => 0.0,
					);
				}
				$vendors[ $ven ]['items'] += $qty;
				$vendors[ $ven ]['value'] += $qty * $price;
			}

			return array(
				'total_items'     => count( $all ),
				'total_quantity'  => $total_available,
				'total_value'     => round( $total_value, 2 ),
				'locations'       => $locations,
				'vendors'         => $vendors,
				'low_stock_count' => 0, // Computed separately with threshold.
			);
		}

		// ------------------------------------------------------------------ //
		// Write Operations                                                    //
		// ------------------------------------------------------------------ //

		/**
		 * Upsert a Shopify row into the CCT.
		 *
		 * Matches on shopify_variant_id + location_id compound key.
		 * If a matching row exists and sync_hash differs, it is updated.
		 * If sync_hash is identical, the row is skipped.
		 * If no matching row exists, a new row is created.
		 *
		 * @since 1.3.0
		 *
		 * @param array $shopify_row Mapped Shopify data row.
		 * @return int|WP_Error CCT item ID or WP_Error.
		 */
		public function upsert( $shopify_row ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			if ( empty( $shopify_row['shopify_variant_id'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_missing_variant_id',
					__( 'Shopify row is missing required shopify_variant_id.', 'mcp-ai-wpoos-pro' )
				);
			}

			$location_id = isset( $shopify_row['location_id'] ) ? $shopify_row['location_id'] : '';

			// Check for existing row (compound key: variant_id + location_id).
			$existing = $this->get_cached_item_by_variant_id(
				$shopify_row['shopify_variant_id'],
				$location_id
			);

			if ( $existing ) {
				// Skip if unchanged (hash match).
				$new_hash = isset( $shopify_row['sync_hash'] ) ? $shopify_row['sync_hash'] : '';
				$old_hash = isset( $existing['sync_hash'] ) ? $existing['sync_hash'] : '';
				if ( $new_hash === $old_hash ) {
					return absint( $existing['_ID'] );
				}

				// Update existing item.
				$shopify_row['_ID'] = $existing['_ID'];
				$result             = jet_engine()->cct->data->update_item( $shopify_row, $this->cct_slug );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				/**
				 * Fires after a CCT item is updated during Shopify sync.
				 *
				 * @since 1.3.0
				 *
				 * @param int    $cct_item_id  The CCT item ID.
				 * @param array  $shopify_row  The updated row data.
				 * @param string $connection_id The Shopify connection ID.
				 */
				do_action( 'wp_mcp_ai_shopify_sync_item_updated', absint( $existing['_ID'] ), $shopify_row, $this->connection_id );

				return absint( $existing['_ID'] );
			}

			// Create new item.
			$shopify_row['cct_status'] = 'publish';
			$result                    = jet_engine()->cct->data->create_item( $shopify_row, $this->cct_slug );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			/**
			 * Fires after a CCT item is created during Shopify sync.
			 *
			 * @since 1.3.0
			 *
			 * @param int    $cct_item_id  The CCT item ID.
			 * @param array  $shopify_row  The created row data.
			 * @param string $connection_id The Shopify connection ID.
			 */
			do_action( 'wp_mcp_ai_shopify_sync_item_created', absint( $result ), $shopify_row, $this->connection_id );

			return absint( $result );
		}

		/**
		 * Bulk upsert items from a JSONL (bulk operation output).
		 *
		 * Parses each JSONL line, maps fields, computes sync_hash,
		 * and upserts into the CCT. Skips unchanged rows via hash comparison.
		 *
		 * @since 1.3.0
		 *
		 * @param array         $jsonl_items Array of parsed JSONL objects.
		 * @param array         $mapping     Field mapping array.
		 * @param callable|null $progress    Optional progress callback( $index, $total, $inserted, $updated, $skipped ).
		 * @return array Sync result with counts.
		 */
		public function bulk_upsert_from_jsonl( $jsonl_items, $mapping = array(), $progress = null ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return array(
					'inserted' => 0,
					'updated'  => 0,
					'skipped'  => 0,
					'errors'   => 0,
					'total'    => count( $jsonl_items ),
					'error'    => $available->get_error_message(),
				);
			}

			$effective_mapping = ! empty( $mapping )
				? array_merge( $this->get_default_field_mapping(), $mapping )
				: $this->get_default_field_mapping();

			$inserted = 0;
			$updated  = 0;
			$skipped  = 0;
			$errors   = 0;
			$total    = count( $jsonl_items );

			foreach ( $jsonl_items as $index => $jsonl_item ) {
				$rows = $this->map_bulk_item_to_cct_rows( $jsonl_item, $effective_mapping );

				foreach ( $rows as $row ) {
					$result = $this->upsert( $row );

					if ( is_wp_error( $result ) ) {
						++$errors;
					} else {
						// Determine if inserted, updated, or skipped.
						// upsert returns the existing ID for skips/updates.
						$existing = $this->get_cached_item_by_variant_id(
							$row['shopify_variant_id'],
							isset( $row['location_id'] ) ? $row['location_id'] : ''
						);
						if ( $existing && isset( $existing['sync_hash'] ) && $existing['sync_hash'] === $row['sync_hash'] ) {
							++$skipped;
						} elseif ( $result > 0 ) {
							++$updated;
						} else {
							++$inserted;
						}
					}
				}

				if ( is_callable( $progress ) ) {
					call_user_func( $progress, $index + 1, $total, $inserted, $updated, $skipped );
				}
			}

			return array(
				'inserted' => $inserted,
				'updated'  => $updated,
				'skipped'  => $skipped,
				'errors'   => $errors,
				'total'    => $total,
			);
		}

		/**
		 * Truncate all items from the CCT.
		 *
		 * @since 1.3.0
		 *
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function truncate() {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$items = $this->get_cached_items( array( 'per_page' => 100 ) );

			while ( ! empty( $items ) ) {
				foreach ( $items as $item ) {
					if ( ! empty( $item['_ID'] ) ) {
						jet_engine()->cct->data->delete_item( absint( $item['_ID'] ), $this->cct_slug );
					}
				}
				$items = $this->get_cached_items( array( 'per_page' => 100 ) );
			}

			return true;
		}

		/**
		 * Mark all items as stale.
		 *
		 * @since 1.3.0
		 */
		public function mark_stale() {
			global $wpdb;
			$table = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'sync_status' => 'stale' ),
				array( 'sync_status' => 'synced' )
			);
		}

		/**
		 * Mark items as deleted by Shopify product GID.
		 *
		 * @since 1.3.0
		 *
		 * @param string $product_gid Shopify product GID.
		 */
		public function mark_deleted_by_product_gid( $product_gid ) {
			global $wpdb;
			$table = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'sync_status' => 'deleted' ),
				array( 'shopify_product_id' => sanitize_text_field( $product_gid ) )
			);
		}

		/**
		 * Update inventory delta for a specific inventory item at a location.
		 *
		 * Used by webhook handler for inventory_levels/update events.
		 *
		 * @since 1.3.0
		 *
		 * @param string $inventory_item_id Shopify InventoryItem GID.
		 * @param string $location_id       Shopify Location GID.
		 * @param int    $available         New available quantity.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function update_inventory_delta( $inventory_item_id, $location_id, $available ) {
			$items = $this->get_cached_items(
				array(
					'location_id' => sanitize_text_field( $location_id ),
					'per_page'    => 100,
				)
			);

			$updated = false;

			foreach ( $items as $item ) {
				$item_inventory_id = isset( $item['inventory_item_id'] ) ? $item['inventory_item_id'] : '';
				if ( $item_inventory_id === $inventory_item_id ) {
					$item['available_qty']  = absint( $available );
					$item['sync_hash']      = md5( wp_json_encode( $item ) );
					$item['sync_status']    = 'synced';
					$item['last_synced_at'] = current_time( 'mysql' );
					$item['_ID']            = absint( $item['_ID'] );

					$result = jet_engine()->cct->data->update_item( $item, $this->cct_slug );
					if ( ! is_wp_error( $result ) ) {
						$updated = true;
					}
					break;
				}
			}

			return $updated ? true : new WP_Error(
				'wp_mcp_ai_shopify_sync_item_not_found',
				__( 'Inventory item not found in CCT for delta update.', 'mcp-ai-wpoos-pro' )
			);
		}

		/**
		 * Delete a single item from the CCT.
		 *
		 * @since 1.3.0
		 *
		 * @param int $cct_item_id CCT item ID.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function delete_item( $cct_item_id ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			return jet_engine()->cct->data->delete_item( absint( $cct_item_id ), $this->cct_slug );
		}

		/**
		 * Update the woo_product_id for a CCT item.
		 *
		 * @since 1.3.0
		 *
		 * @param int $cct_item_id    CCT item ID.
		 * @param int $woo_product_id WooCommerce product ID.
		 * @return bool|WP_Error
		 */
		public function update_woo_product_id( $cct_item_id, $woo_product_id ) {
			$item = $this->get_cached_item( $cct_item_id, 'cct_id' );
			if ( ! $item ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_item_not_found',
					__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item['woo_product_id'] = absint( $woo_product_id );
			$item['_ID']            = absint( $cct_item_id );

			return jet_engine()->cct->data->update_item( $item, $this->cct_slug );
		}

		// ------------------------------------------------------------------ //
		// Sync Orchestration                                                  //
		// ------------------------------------------------------------------ //

		/**
		 * Sync from Shopify Bulk Operation.
		 *
		 * Runs a Shopify bulk operation to export all products, parses the JSONL,
		 * and upserts into the CCT with hash change detection.
		 *
		 * @since 1.3.0
		 *
		 * @param callable|null $progress Optional progress callback.
		 * @param bool          $dry_run  If true, skip CCT writes and only
		 *                                validate the GraphQL query + count
		 *                                items. Default false.
		 * @return array|WP_Error Sync result or WP_Error.
		 */
		public function sync_from_bulk_operation( $progress = null, $dry_run = false ) {
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_no_client',
					__( 'Shopify Client is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

				$start_time = microtime( true );

				// Ensure CCT columns exist (skip in dry-run, already handled upstream).
			if ( ! $dry_run ) {
				$columns_result = $this->ensure_columns();
				if ( is_wp_error( $columns_result ) ) {
					return $columns_result;
				}
			}

				$client = new WP_MCP_AI_Shopify_Client( $this->connection_id );

				// Catalog API connections cannot run inventory syncs -
				// they only support product search, not Admin GraphQL.
			if ( 'catalog_api' === $client->get_api_mode() ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_catalog_only',
					__( 'This connection is configured for Shopify Catalog API only. Inventory sync requires Shopify Admin API. Please switch the connection mode to Admin API in Remote Sites settings and provide a store URL and Admin API access token.', 'mcp-ai-wpoos-pro' )
				);
			}

				// Shopify Bulk Operation query — export all products with variants and inventory.
				$bulk_query = '{
						products {
							edges {
								node {
									id
									title
									handle
									status
									vendor
									productType
									tags
									updatedAt
									images(first: 1) {
										edges {
											node {
												url
											}
										}
									}
									variants {
										edges {
											node {
												id
												title
												sku
												price
												compareAtPrice
												inventoryItem {
													id
												}
												inventoryLevels {
													edges {
														node {
															quantities(names: ["available", "on_hand", "incoming", "reserved"]) {
																name
																quantity
															}
															location {
																id
																name
															}
														}
													}
												}
											}
										}
									}
								}
							}
						}
					}';

				$bulk_result = $client->bulk_query( $bulk_query, true );

			if ( is_wp_error( $bulk_result ) ) {
				return $bulk_result;
			}

				$settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
				$mapping  = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();

				$items = isset( $bulk_result['items'] ) ? $bulk_result['items'] : array();

			if ( $dry_run ) {
				// In dry-run mode, count items but skip the actual CCT write.
				$duration = round( microtime( true ) - $start_time, 2 );

				// Count how many CCT rows the items would produce.
				$would_insert = 0;
				$would_update = 0;
				$would_skip   = 0;
				$item_count   = count( $items );

				foreach ( $items as $jsonl_item ) {
					$rows = $this->map_bulk_item_to_cct_rows( $jsonl_item, $mapping );
					foreach ( $rows as $row ) {
						// Check if this row already exists in CCT.
						$existing = $this->get_cached_item_by_variant_id(
							$row['shopify_variant_id'],
							isset( $row['location_id'] ) ? $row['location_id'] : ''
						);
						if ( $existing ) {
							$new_hash = isset( $row['sync_hash'] ) ? $row['sync_hash'] : '';
							$old_hash = isset( $existing['sync_hash'] ) ? $existing['sync_hash'] : '';
							if ( $new_hash === $old_hash ) {
								++$would_skip;
							} else {
								++$would_update;
							}
						} else {
							++$would_insert;
						}
					}
				}

				return array(
					'inserted'      => $would_insert,
					'updated'       => $would_update,
					'skipped'       => $would_skip,
					'errors'        => 0,
					'total'         => $item_count,
					'duration'      => $duration,
					'timestamp'     => current_time( 'mysql' ),
					'connection_id' => $this->connection_id,
					'bulk_op_id'    => isset( $bulk_result['bulk_operation_id'] ) ? $bulk_result['bulk_operation_id'] : '',
					'dry_run'       => true,
				);
			}

				$sync_result = $this->bulk_upsert_from_jsonl(
					$items,
					$mapping,
					$progress
				);

				$duration = round( microtime( true ) - $start_time, 2 );

				update_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, current_time( 'mysql' ) );
				delete_option( 'wp_mcp_ai_shopify_last_sync_error_' . $this->connection_id );

				$result = array_merge(
					$sync_result,
					array(
						'duration'      => $duration,
						'timestamp'     => current_time( 'mysql' ),
						'connection_id' => $this->connection_id,
						'bulk_op_id'    => isset( $bulk_result['bulk_operation_id'] ) ? $bulk_result['bulk_operation_id'] : '',
					)
				);

				/**
				 * Fires after a Shopify sync operation completes.
				 *
				 * @since 1.3.0
				 *
				 * @param array  $result        Sync result data.
				 * @param string $connection_id The Shopify connection ID.
				 */
				do_action( 'wp_mcp_ai_shopify_sync_after_sync', $result, $this->connection_id );

				return $result;
		}

		/**
		 * Sync a single product from Shopify into the CCT.
		 *
		 * Used by webhook handler for products/update events.
		 *
		 * @since 1.3.0
		 *
		 * @param string $product_gid Shopify product GID.
		 * @return array|WP_Error Sync result or WP_Error.
		 */
		public function sync_single_product( $product_gid ) {
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_no_client',
					__( 'Shopify Client is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$client = new WP_MCP_AI_Shopify_Client( $this->connection_id );

			if ( 'catalog_api' === $client->get_api_mode() ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_catalog_only',
					__( 'This connection is configured for Shopify Catalog API only. Inventory sync requires Shopify Admin API.', 'mcp-ai-wpoos-pro' )
				);
			}

			$product_result = $client->get_product( $product_gid );

			if ( is_wp_error( $product_result ) ) {
				return $product_result;
			}

			$product_data = isset( $product_result['data']['product'] ) ? $product_result['data']['product'] : null;

			if ( ! $product_data ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_sync_product_not_found',
					sprintf(
						/* translators: %s: product GID */
						__( 'Product "%s" not found in Shopify.', 'mcp-ai-wpoos-pro' ),
						$product_gid
					)
				);
			}

			$settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			$mapping  = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();

			// Map the single product to CCT rows (one per variant per location).
			$rows   = $this->map_graphql_product_to_cct_rows( $product_data, $mapping );
			$synced = 0;

			foreach ( $rows as $row ) {
				$result = $this->upsert( $row );
				if ( ! is_wp_error( $result ) ) {
					++$synced;
				}
			}

			return array(
				'product_gid' => $product_gid,
				'rows_synced' => $synced,
				'total_rows'  => count( $rows ),
			);
		}

		// ------------------------------------------------------------------ //
		// Field Mapping                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the default field mapping from Shopify GraphQL fields to CCT columns.
		 *
		 * @since 1.3.0
		 *
		 * @return array
		 */
		public function get_default_field_mapping() {
			return array(
				'shopify_product_id' => 'id',
				'shopify_variant_id' => 'variant_id',
				'inventory_item_id'  => 'inventory_item_id',
				'sku'                => 'sku',
				'product_title'      => 'title',
				'variant_title'      => 'variant_title',
				'product_type'       => 'productType',
				'vendor'             => 'vendor',
				'tags'               => 'tags',
				'status'             => 'status',
				'location_id'        => 'location_id',
				'location_name'      => 'location_name',
				'available_qty'      => 'available_qty',
				'on_hand_qty'        => 'on_hand_qty',
				'incoming_qty'       => 'incoming_qty',
				'reserved_qty'       => 'reserved_qty',
				'price'              => 'price',
				'compare_at_price'   => 'compareAtPrice',
				'image_url'          => 'image_url',
				'handle'             => 'handle',
				'shopify_updated_at' => 'updatedAt',
			);
		}

		/**
		 * Map a single JSONL bulk operation item to multiple CCT rows.
		 *
		 * One Shopify product with N variants × M locations produces N×M CCT rows.
		 *
		 * @since 1.3.0
		 *
		 * @param array $item    Bulk operation JSONL product item.
		 * @param array $mapping Field mapping array.
		 * @return array Array of CCT row arrays.
		 */
		protected function map_bulk_item_to_cct_rows( $item, $mapping ) {
			unset( $mapping ); // Reserved for Phase 2 custom overrides.
			$rows         = array();
			$product_data = $this->extract_bulk_product_fields( $item );

			$variants = isset( $item['variants']['edges'] ) ? $item['variants']['edges'] : array();
			if ( isset( $item['variants'] ) && ! isset( $item['variants']['edges'] ) ) {
				$variants = array( array( 'node' => $item['variants'] ) );
			}

			foreach ( $variants as $variant_edge ) {
				$variant = isset( $variant_edge['node'] ) ? $variant_edge['node'] : $variant_edge;

				$inventory_item_id = '';
				if ( isset( $variant['inventoryItem']['id'] ) ) {
					$inventory_item_id = $variant['inventoryItem']['id'];
				}

				$inventory_levels = isset( $variant['inventoryLevels']['edges'] ) ? $variant['inventoryLevels']['edges'] : array();

				if ( empty( $inventory_levels ) ) {
					// No inventory levels — create one row with zero quantities.
					$row                   = array_merge(
						$product_data,
						array(
							'shopify_variant_id' => isset( $variant['id'] ) ? sanitize_text_field( $variant['id'] ) : '',
							'variant_title'      => isset( $variant['title'] ) ? sanitize_text_field( $variant['title'] ) : '',
							'sku'                => isset( $variant['sku'] ) ? sanitize_text_field( $variant['sku'] ) : '',
							'price'              => isset( $variant['price'] ) ? number_format( floatval( $variant['price'] ), 2, '.', '' ) : '0.00',
							'compare_at_price'   => isset( $variant['compareAtPrice'] ) ? number_format( floatval( $variant['compareAtPrice'] ), 2, '.', '' ) : '0.00',
							'inventory_item_id'  => $inventory_item_id,
							'location_id'        => '',
							'location_name'      => '',
							'available_qty'      => 0,
							'on_hand_qty'        => 0,
							'incoming_qty'       => 0,
							'reserved_qty'       => 0,
						)
					);
					$row['sync_hash']      = md5( wp_json_encode( $row ) );
					$row['sync_status']    = 'synced';
					$row['last_synced_at'] = current_time( 'mysql' );
					$row['raw_data']       = wp_json_encode( $variant );
					$rows[]                = $row;
				} else {
					foreach ( $inventory_levels as $level_edge ) {
						$level    = isset( $level_edge['node'] ) ? $level_edge['node'] : $level_edge;
						$location = isset( $level['location'] ) ? $level['location'] : array();

						$quantities = array(
							'available' => 0,
							'on_hand'   => 0,
							'incoming'  => 0,
							'reserved'  => 0,
						);

						if ( isset( $level['quantities'] ) && is_array( $level['quantities'] ) ) {
							foreach ( $level['quantities'] as $q ) {
								if ( isset( $q['name'], $q['quantity'] ) ) {
									$quantities[ $q['name'] ] = absint( $q['quantity'] );
								}
							}
						}

						$row                   = array_merge(
							$product_data,
							array(
								'shopify_variant_id' => isset( $variant['id'] ) ? sanitize_text_field( $variant['id'] ) : '',
								'variant_title'      => isset( $variant['title'] ) ? sanitize_text_field( $variant['title'] ) : '',
								'sku'                => isset( $variant['sku'] ) ? sanitize_text_field( $variant['sku'] ) : '',
								'price'              => isset( $variant['price'] ) ? number_format( floatval( $variant['price'] ), 2, '.', '' ) : '0.00',
								'compare_at_price'   => isset( $variant['compareAtPrice'] ) ? number_format( floatval( $variant['compareAtPrice'] ), 2, '.', '' ) : '0.00',
								'inventory_item_id'  => $inventory_item_id,
								'location_id'        => isset( $location['id'] ) ? sanitize_text_field( $location['id'] ) : '',
								'location_name'      => isset( $location['name'] ) ? sanitize_text_field( $location['name'] ) : '',
								'available_qty'      => $quantities['available'],
								'on_hand_qty'        => $quantities['on_hand'],
								'incoming_qty'       => $quantities['incoming'],
								'reserved_qty'       => $quantities['reserved'],
							)
						);
						$row['sync_hash']      = md5( wp_json_encode( $row ) );
						$row['sync_status']    = 'synced';
						$row['last_synced_at'] = current_time( 'mysql' );
						$row['raw_data']       = wp_json_encode( $variant );
						$rows[]                = $row;
					}
				}
			}

			return $rows;
		}

		/**
		 * Extract common product-level fields from a bulk operation item.
		 *
		 * @since 1.3.0
		 *
		 * @param array $item Bulk operation JSONL item.
		 * @return array Product-level fields.
		 */
		protected function extract_bulk_product_fields( $item ) {
			$image_url = '';
			$images    = isset( $item['images']['edges'] ) ? $item['images']['edges'] : array();
			if ( ! empty( $images ) && isset( $images[0]['node']['url'] ) ) {
				$image_url = esc_url_raw( $images[0]['node']['url'] );
			}

			return array(
				'shopify_product_id' => isset( $item['id'] ) ? sanitize_text_field( $item['id'] ) : '',
				'product_title'      => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'product_type'       => isset( $item['productType'] ) ? sanitize_text_field( $item['productType'] ) : '',
				'vendor'             => isset( $item['vendor'] ) ? sanitize_text_field( $item['vendor'] ) : '',
				'tags'               => isset( $item['tags'] ) ? ( is_array( $item['tags'] ) ? implode( ', ', $item['tags'] ) : sanitize_text_field( $item['tags'] ) ) : '',
				'status'             => isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : 'ACTIVE',
				'image_url'          => $image_url,
				'handle'             => isset( $item['handle'] ) ? sanitize_text_field( $item['handle'] ) : '',
				'shopify_updated_at' => isset( $item['updatedAt'] ) ? sanitize_text_field( $item['updatedAt'] ) : '',
			);
		}

		/**
		 * Map a GraphQL single-product response to CCT rows.
		 *
		 * @since 1.3.0
		 *
		 * @param array $product_data GraphQL product node.
		 * @param array $mapping      Field mapping overrides.
		 * @return array Array of CCT row arrays.
		 */
		protected function map_graphql_product_to_cct_rows( $product_data, $mapping = array() ) {
			// Convert GraphQL product to bulk-operation-like shape.
			$item = array(
				'id'          => isset( $product_data['id'] ) ? $product_data['id'] : '',
				'title'       => isset( $product_data['title'] ) ? $product_data['title'] : '',
				'handle'      => isset( $product_data['handle'] ) ? $product_data['handle'] : '',
				'status'      => isset( $product_data['status'] ) ? $product_data['status'] : 'ACTIVE',
				'vendor'      => isset( $product_data['vendor'] ) ? $product_data['vendor'] : '',
				'productType' => isset( $product_data['productType'] ) ? $product_data['productType'] : '',
				'tags'        => isset( $product_data['tags'] ) ? ( is_array( $product_data['tags'] ) ? $product_data['tags'] : array( $product_data['tags'] ) ) : array(),
				'updatedAt'   => isset( $product_data['updatedAt'] ) ? $product_data['updatedAt'] : '',
				'images'      => isset( $product_data['images'] ) ? $product_data['images'] : array( 'edges' => array() ),
				'variants'    => isset( $product_data['variants'] ) ? $product_data['variants'] : array( 'edges' => array() ),
			);

			return $this->map_bulk_item_to_cct_rows( $item, $mapping );
		}

		// ------------------------------------------------------------------ //
		// Helpers                                                             //
		// ------------------------------------------------------------------ //

		/**
		 * Map a user-facing orderby value to a CCT field name.
		 *
		 * @since 1.3.0
		 *
		 * @param string $orderby User-facing orderby value.
		 * @return string CCT field name.
		 */
		protected function get_orderby_field( $orderby ) {
			$map = array(
				'product_title'      => 'product_title',
				'available_qty'      => 'available_qty',
				'price'              => 'price',
				'last_synced_at'     => 'last_synced_at',
				'sku'                => 'sku',
				'vendor'             => 'vendor',
				'product_type'       => 'product_type',
				'location_name'      => 'location_name',
				'shopify_updated_at' => 'shopify_updated_at',
			);

			$orderby = sanitize_key( $orderby );

			return isset( $map[ $orderby ] ) ? $map[ $orderby ] : 'last_synced_at';
		}

		// ------------------------------------------------------------------ //
			// Module Bootstrap & Safe Access                                        //
			// ------------------------------------------------------------------ //

			/**
			 * Look up a single CCT record by slug.
			 *
			 * Uses JetEngine's DB query layer instead of the non-existent
			 * get_item_by_slug() method on the Data class.
			 *
			 * @since 1.7.0
			 *
			 * @param string $slug CCT slug.
			 * @return array|null The CCT record as an associative array, or null if not found.
			 */
		protected function get_cct_record_by_slug( $slug ) {
			$module = self::get_cct_module();

			if ( ! $module ) {
				return null;
			}

			$data = $module->manager->data;

			if ( empty( $data->db ) ) {
				return null;
			}

			$records = $data->db->query(
				'post_types',
				array(
					'slug'   => $slug,
					'status' => 'content-type',
				),
				null,
				false
			);

			if ( empty( $records ) || ! is_array( $records ) ) {
				return null;
			}

			$record = reset( $records );

			if ( ! is_array( $record ) && ! is_object( $record ) ) {
				return null;
			}

			// Normalise to associative array for consistent access.
			if ( is_object( $record ) ) {
				$record = get_object_vars( $record );
			}

			return $record;
		}

			/**
			 * Bootstrap the CCT manager.
			 *
			 * Hooks module auto-activation and CCT registration on init.
			 *
			 * @since 1.7.0
			 */
		public static function bootstrap() {
			add_action( 'init', array( __CLASS__, 'maybe_enable_cct_module' ), 10 );
			add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );
			add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
		}

		/**
		 * Retrieve the JetEngine Custom Content Types module instance safely.
		 *
		 * Goes through JetEngine's modules API rather than relying on the
		 * fragile jet_engine()->cct shorthand, which can be null even on a
		 * fully-loaded request when the CCT module is inactive.
		 *
		 * @since 1.7.0
		 *
		 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
		 */
		protected static function get_cct_module() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return null;
			}

			$engine = jet_engine();

			if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
				return null;
			}

			if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
				return null;
			}

			$module_wrapper = $engine->modules->get_module( 'custom-content-types' );

			if ( empty( $module_wrapper ) ) {
				return null;
			}

			// Use the pre-built instance when available.
			if ( ! empty( $module_wrapper->instance ) && ! empty( $module_wrapper->instance->data ) ) {
				return $module_wrapper->instance;
			}

			// Last-resort: call get_module() to force lazy-init (the same method
			// JetEngine itself uses to populate ->instance internally). This
			// covers edge cases where the module is active but ->instance was
			// never materialised (e.g. activation mid-request).
			if ( method_exists( $module_wrapper, 'get_module' ) ) {
				$instance = $module_wrapper->get_module();
				if ( ! empty( $instance ) && ! empty( $instance->data ) ) {
					return $instance;
				}
			}

			return null;
		}

		/**
		 * Automatically enable the JetEngine CCT module if it's not already active.
		 *
		 * Shopify Sync depends on the Custom Content Types module. This runs on
		 * init priority 10 so the module is ready before CCT interactions.
		 *
		 * @since 1.7.0
		 */
		public static function maybe_enable_cct_module() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return;
			}

			$engine = jet_engine();

			if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
				return;
			}

			// Already active — nothing to do.
			if ( $engine->modules->is_module_active( 'custom-content-types' ) ) {
				return;
			}

			// Check if the module exists before activating.
			if ( ! method_exists( $engine->modules, 'get_module' ) ) {
				return;
			}

			$module = $engine->modules->get_module( 'custom-content-types' );

			if ( ! $module ) {
				return;
			}

			// Activate the CCT module.
			if ( method_exists( $engine->modules, 'activate_module' ) ) {
				$engine->modules->activate_module( 'custom-content-types' );
			}
		}

		/**
		 * Register the Shopify Sync CCT in JetEngine if it is missing.
		 *
		 * Uses the explicit sanitize-update lifecycle pipeline proven by the
		 * FlowHub CCT manager, which is more reliable across JetEngine
		 * versions than the simplified create_item(false) two-liner.
		 *
		 * @since 1.8.0
		 */
		public static function maybe_register_cct() {
			$module = self::get_cct_module();

			if ( ! $module ) {
				return;
			}

			if ( self::cct_exists( $module ) ) {
				return;
			}

			if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
				return;
			}

			$data    = $module->manager->data;
			$request = self::get_registration_request();

			$data->set_request( $request );

			if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
				return;
			}

			$item = $data->sanitize_item_from_request();

			if ( empty( $item ) || ! is_array( $item ) ) {
				return;
			}

			$data->before_item_update( $item, true );

			$item_id = $data->update_item_in_db( $item );

			if ( ! $item_id ) {
				return;
			}

			$item['id'] = $item_id;

			$data->after_item_update( $item, true );

			if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
				$data->db->query_raw( 'post_types' );
			}
		}

		/**
		 * Check whether the Shopify Sync CCT already exists in JetEngine.
		 *
		 * @since 1.8.0
		 *
		 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module CCT module instance.
		 * @return bool
		 */
		protected static function cct_exists( $module ) {
			if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
				return false;
			}

			$slug     = self::CCT_SLUG_DEFAULT;
			$settings = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			if ( ! empty( $settings['cct_slug'] ) ) {
				$slug = sanitize_key( $settings['cct_slug'] );
			}

			$records = $module->manager->data->db->query(
				'post_types',
				array(
					'slug'   => $slug,
					'status' => 'content-type',
				),
				null,
				false
			);

			return ! empty( $records );
		}

		/**
		 * Build the JetEngine registration request payload.
		 *
		 * Mirrors the pattern used by Vitals Log CCT and FlowHub CCT.
		 *
		 * @since 1.8.0
		 *
		 * @return array
		 */
		protected static function get_registration_request() {
			$label = __( 'Shopify Inventory Sync', 'mcp-ai-wpoos-pro' );

			return array(
				'name'        => $label,
				'slug'        => self::CCT_SLUG_DEFAULT,
				'args'        => array(
					'name'                => $label,
					'slug'                => self::CCT_SLUG_DEFAULT,
					'position'            => '-1',
					'icon'                => 'dashicons-update',
					'capability'          => 'manage_woocommerce',
					'has_single'          => false,
					'create_index'        => true,
					'hide_field_names'    => false,
					'rest_get_enabled'    => false,
					'rest_put_enabled'    => false,
					'rest_post_enabled'   => false,
					'rest_delete_enabled' => false,
					'admin_columns'       => array(
						'_ID'    => array(
							'enabled'     => true,
							'prefix'      => '#',
							'is_sortable' => true,
							'is_num'      => true,
						),
						'sku'    => array(
							'enabled'     => true,
							'is_sortable' => true,
						),
						'status' => array(
							'enabled'     => true,
							'is_sortable' => true,
						),
					),
				),
				'meta_fields' => self::get_meta_fields(),
			);
		}

		/**
		 * Build meta field definitions from column definitions.
		 *
		 * Mirrors the pattern used by FlowHub CCT manager.
		 *
		 * @since 1.8.0
		 *
		 * @return array
		 */
		protected static function get_meta_fields() {
			$instance = new self();
			$columns  = $instance->get_column_definitions();
			$fields   = array();
			$field_id = self::FIELD_ID_BASE;

			foreach ( $columns as $column_name => $column_type ) {
				$args = array();

				// Map internal type names to JetEngine field types.
				switch ( $column_type ) {
					case 'number':
						$jet_type           = 'number';
						$args['is_numeric'] = true;
						break;
					case 'textarea':
						$jet_type = 'textarea';
						break;
					case 'datetime-local':
						$jet_type = 'datetime-local';
						break;
					default:
						$jet_type = 'text';
						break;
				}

				$fields[] = self::build_field(
					$field_id,
					$column_name,
					$instance->get_column_label( $column_name ),
					$jet_type,
					$args
				);

				++$field_id;
			}

			return $fields;
		}

		/**
		 * Build a field definition for JetEngine.
		 *
		 * @since 1.8.0
		 *
		 * @param int    $id    Field ID.
		 * @param string $name  Field name.
		 * @param string $label Field label.
		 * @param string $type  Field type.
		 * @param array  $args  Additional arguments.
		 * @return array
		 */
		protected static function build_field( $id, $name, $label, $type, $args = array() ) {
			return array_merge(
				array(
					'id'          => (string) $id,
					'name'        => $name,
					'title'       => $label,
					'type'        => $type,
					'object_type' => 'field',
				),
				$args
			);
		}

		/**
		 * Automatically enable the JetEngine data stores module if it's not already active.
		 *
		 * @since 1.7.0
		 */
		public static function maybe_enable_data_stores() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return;
			}

			$engine = jet_engine();

			if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
				return;
			}

			// Check if data stores module is already active.
			if ( $engine->modules->is_module_active( 'data-stores' ) ) {
				return;
			}

			// Check if the module exists.
			if ( ! method_exists( $engine->modules, 'get_module' ) ) {
				return;
			}

			$module = $engine->modules->get_module( 'data-stores' );

			if ( ! $module ) {
				return;
			}

			// Activate the data stores module.
			if ( method_exists( $engine->modules, 'activate_module' ) ) {
				$engine->modules->activate_module( 'data-stores' );
			}
		}
	}
}
