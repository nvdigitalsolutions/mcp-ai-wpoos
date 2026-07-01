<?php
/**
 * FlowHub CCT Manager.
 *
 * Manages the JetEngine Custom Content Type (CCT) that serves as the local
 * cache for FlowHub inventory data. Handles column auto-creation, upsert
 * operations, and cached read queries — so tools never hit the FlowHub API
 * for reads.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the PRO FlowHub client on-demand.
// NOTE: The PRO client (WP_MCP_AI_FlowHub_Client, uppercase H) shares the same
// case-insensitive class name as the base client (WP_MCP_AI_Flowhub_Client).
// The init.php no longer loads it early to avoid collisions with base tools.
// Load it here so that sync_from_api() and sync_single_product() have access
// to from_settings().
if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
}

if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {

	/**
	 * FlowHub CCT Manager.
	 *
	 * Provides read/write access to the FlowHub inventory CCT.
	 * All public methods return canonical shapes ready for tool envelopes.
	 *
	 * @since 1.2.0
	 */
	class WP_MCP_AI_FlowHub_CCT_Manager {

		/**
		 * Default CCT slug.
		 *
		 * @var string
		 */
		const CCT_SLUG_DEFAULT = 'flowhub_inventory';

		/**
		 * Schema version for auto-discovery of new columns on update.
		 *
		 * @since 1.4.0
		 *
		 * @var string
		 */
		const SCHEMA_VERSION = '1.4.0';

		/**
		 * Base ID for meta field identifiers.
		 *
		 * Using 40000 range to avoid conflicts with other CCT fields.
		 *
		 * @since 1.5.0
		 *
		 * @var int
		 */
		const FIELD_ID_BASE = 40000;

		/**
		 * Current CCT slug.
		 *
		 * @var string
		 */
		protected $cct_slug = '';

		/**
		 * FlowHub API client instance.
		 *
		 * @var WP_MCP_AI_FlowHub_Client|null
		 */
		protected $client = null;

		/**
		 * Remote Sites connection ID.
		 *
		 * When set, option keys are suffixed with this ID
		 * for per-connection isolation.
		 *
		 * @since 1.6.0
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
			'product_id'             => 'text',
			'variant_id'             => 'text',
			'parent_product_id'      => 'text',
			'sku'                    => 'text',
			'product_name'           => 'text',
			'variant_name'           => 'text',
			'category'               => 'text',
			'custom_category_name'   => 'text',
			'purchase_category'      => 'text',
			'product_description'    => 'textarea',
			'quantity'               => 'number',
			'location_id'            => 'text',
			'location_name'          => 'text',
			'unit_of_measure'        => 'text',
			'image_url'              => 'text',
			'price'                  => 'number',
			'woo_product_id'         => 'number',
			'last_updated'           => 'datetime',
			'item_data'              => 'textarea',
			'sync_status'            => 'text',
			'sync_hash'              => 'text',
			// Compliance fields (v1.4.0).
			'strain_name'            => 'text',
			'thc_percentage'         => 'number',
			'cbd_percentage'         => 'number',
			'lab_test_id'            => 'text',
			'compliance_status'      => 'text',
			'metrc_uid'              => 'text',
			'previous_quantity'      => 'number',
			'quantity_change_reason' => 'text',
		);

		/**
		 * Constructor.
		 *
		 * @since 1.2.0
		 * @since 1.6.0 Added $connection_id parameter for per-connection isolation.
		 *
		 * @param WP_MCP_AI_FlowHub_Client|string|null $client_or_connection FlowHub API client for sync operations,
		 *                                                                  or a Remote Sites connection ID string.
		 */
		public function __construct( $client_or_connection = null ) {
			// Support both old API ($client) and new API ($connection_id).
			if ( is_string( $client_or_connection ) && ! empty( $client_or_connection ) ) {
				$this->connection_id = $client_or_connection;
				$this->client        = null;
			} elseif ( is_object( $client_or_connection ) && $client_or_connection instanceof WP_MCP_AI_FlowHub_Client ) {
				$this->client        = $client_or_connection;
				$this->connection_id = null;
			} else {
				$this->client        = null;
				$this->connection_id = null;
			}
			$this->cct_slug = $this->get_configured_cct_slug();
		}

		/**
		 * Get the connection ID.
		 *
		 * @since 1.6.0
		 *
		 * @return string|null
		 */
		public function get_connection_id() {
			return $this->connection_id;
		}

		// ------------------------------------------------------------------ //
		// Configuration                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the configured CCT slug from toolkit settings.
		 *
		 * @since 1.2.0
		 *
		 * @return string
		 */
		public function get_configured_cct_slug() {
			$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
			return ! empty( $settings['cct_slug'] )
				? sanitize_key( $settings['cct_slug'] )
				: self::CCT_SLUG_DEFAULT;
		}

		/**
		 * Get the current CCT slug.
		 *
		 * @since 1.2.0
		 *
		 * @return string
		 */
		public function get_cct_slug() {
			return $this->cct_slug;
		}

		/**
		 * Set the CCT slug.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug CCT slug.
		 */
		public function set_cct_slug( $slug ) {
			$this->cct_slug = sanitize_key( $slug );
		}

		/**
		 * Check if JetEngine and the CCT are available.
		 *
		 * @since 1.2.0
		 *
		 * @return bool|WP_Error True if available, WP_Error otherwise.
		 */
		public function is_cct_available() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_jetengine_missing',
					__( 'JetEngine plugin is required for FlowHub inventory storage. Please install and activate JetEngine.', 'mcp-ai-wpoos-pro' )
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
				return new WP_Error(
					'wp_mcp_ai_flowhub_jetengine_not_ready',
					__( 'JetEngine Custom Content Types module is not active. Please enable it in JetEngine → JetEngine Settings → Modules.', 'mcp-ai-wpoos-pro' )
				);
			}

			$data = $cct_module->manager->data;

			if ( empty( $data->db ) ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_cct_not_ready',
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
					'wp_mcp_ai_flowhub_cct_missing',
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
			 * Ensure the JetEngine CCT exists, creating it if needed.
			 *
			 * Uses the existing maybe_register_cct() logic that
			 * creates the CCT via JetEngine's module system.
			 * Only creates the CCT shell; columns are added
			 * separately via ensure_columns().
			 *
			 * @since 1.5.0
			 *
			 * @return array|WP_Error Result array or WP_Error.
			 */
		public function ensure_cct_exists() {
			// First try the static bootstrap path (handles JetEngine init timing).
			if ( method_exists( __CLASS__, 'maybe_register_cct' ) ) {
				self::maybe_register_cct();
			}

			// Now check if it exists.
			$available = $this->is_cct_available();

			if ( is_wp_error( $available ) ) {
				// If the error is "JetEngine not ready", surface it.
				if ( 'wp_mcp_ai_flowhub_jetengine_not_ready' === $available->get_error_code() ) {
					return $available;
				}
				// If CCT still missing after bootstrap attempt, surface the error.
				if ( 'wp_mcp_ai_flowhub_cct_missing' === $available->get_error_code() ) {
					return $available;
				}
				return $available;
			}

			return array(
				'created' => false,
				'slug'    => $this->cct_slug,
			);
		}

			/**
			 * Check if the FlowHub API is configured with credentials.
			 *
			 * @since 1.5.0
			 *
			 * @return bool True if API keys are set.
			 */
		public function is_api_configured() {
			// If we have a connection ID, check that the connection exists in Remote Sites.
			if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
				if ( $connection && ! empty( $connection['enabled'] ) ) {
					return true;
				}
			}

			// Fall back to legacy global API credentials.
			$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
			return ! empty( $settings['client_id'] ) && ! empty( $settings['api_key'] );
		}

		// ------------------------------------------------------------------ //
		// Column Management                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Get the column definitions.
		 *
		 * @since 1.2.0
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
		 * @since 1.2.0
		 *
		 * @return int|WP_Error Number of columns created or WP_Error.
		 */
		public function ensure_columns() {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$created         = 0;
			$existing_fields = $this->get_existing_cct_fields();

			foreach ( $this->columns as $column_name => $column_type ) {
					if ( in_array( $column_name, $existing_fields, true ) ) {
						continue;
					}

					$sql_type = self::map_jet_type_to_sql( $column_type );
					global $wpdb;
					$table = $wpdb->prefix . 'jet_cct_' . $this->cct_slug;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column_name}` {$sql_type} NULL DEFAULT NULL" );
					++$created;
				}

				do_action( 'wp_mcp_ai_flowhub_after_columns_ensure', $this->cct_slug, $created );

			// Track schema version so columns are only auto-created once per version.
			$schema_key = 'wp_mcp_ai_flowhub_sync_db_version';
			if ( ! empty( $this->connection_id ) ) {
				$schema_key .= '_' . $this->connection_id;
			}
			update_option( $schema_key, self::SCHEMA_VERSION );

			return $created;
		}

		/**
		 * Get existing CCT field names.
		 *
		 * @since 1.2.0
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
	 * Map a JetEngine field type to a MySQL column type.
	 *
	 * Used by ensure_columns() for direct ALTER TABLE statements
	 * when the jet_engine()->cct API is unavailable.
	 *
	 * @since 1.8.0
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
		 * @since 1.2.0
		 *
		 * @param string $column_name Column name.
		 * @return string
		 */
		protected function get_column_label( $column_name ) {
			$labels = array(
				'product_id'             => __( 'Product ID', 'mcp-ai-wpoos-pro' ),
				'variant_id'             => __( 'Variant ID', 'mcp-ai-wpoos-pro' ),
				'parent_product_id'      => __( 'Parent Product ID', 'mcp-ai-wpoos-pro' ),
				'sku'                    => __( 'SKU', 'mcp-ai-wpoos-pro' ),
				'product_name'           => __( 'Product Name', 'mcp-ai-wpoos-pro' ),
				'variant_name'           => __( 'Variant Name', 'mcp-ai-wpoos-pro' ),
				'category'               => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'custom_category_name'   => __( 'Custom Category', 'mcp-ai-wpoos-pro' ),
				'purchase_category'      => __( 'Purchase Category', 'mcp-ai-wpoos-pro' ),
				'product_description'    => __( 'Description', 'mcp-ai-wpoos-pro' ),
				'quantity'               => __( 'Quantity', 'mcp-ai-wpoos-pro' ),
				'location_id'            => __( 'Location ID', 'mcp-ai-wpoos-pro' ),
				'location_name'          => __( 'Location Name', 'mcp-ai-wpoos-pro' ),
				'unit_of_measure'        => __( 'Unit of Measure', 'mcp-ai-wpoos-pro' ),
				'image_url'              => __( 'Image URL', 'mcp-ai-wpoos-pro' ),
				'price'                  => __( 'Price', 'mcp-ai-wpoos-pro' ),
				'woo_product_id'         => __( 'WooCommerce Product ID', 'mcp-ai-wpoos-pro' ),
				'last_updated'           => __( 'Last Updated', 'mcp-ai-wpoos-pro' ),
				'item_data'              => __( 'Raw API Data', 'mcp-ai-wpoos-pro' ),
				'sync_status'            => __( 'Sync Status', 'mcp-ai-wpoos-pro' ),
				'sync_hash'              => __( 'Sync Hash', 'mcp-ai-wpoos-pro' ),
				'strain_name'            => __( 'Strain Name', 'mcp-ai-wpoos-pro' ),
				'thc_percentage'         => __( 'THC %', 'mcp-ai-wpoos-pro' ),
				'cbd_percentage'         => __( 'CBD %', 'mcp-ai-wpoos-pro' ),
				'lab_test_id'            => __( 'Lab Test ID', 'mcp-ai-wpoos-pro' ),
				'compliance_status'      => __( 'Compliance Status', 'mcp-ai-wpoos-pro' ),
				'metrc_uid'              => __( 'Metrc UID', 'mcp-ai-wpoos-pro' ),
				'previous_quantity'      => __( 'Previous Quantity', 'mcp-ai-wpoos-pro' ),
				'quantity_change_reason' => __( 'Change Reason', 'mcp-ai-wpoos-pro' ),
			);

			return isset( $labels[ $column_name ] ) ? $labels[ $column_name ] : ucwords( str_replace( '_', ' ', $column_name ) );
		}

		// ------------------------------------------------------------------ //
		// Read Operations (CCT queries, no API calls)                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get cached inventory items with filtering, sorting, and pagination.
		 *
		 * @since 1.2.0
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
				'orderby' => $this->get_orderby_field( isset( $filters['orderby'] ) ? $filters['orderby'] : 'last_updated' ),
				'status'  => 'publish',
			);

			// Full-text search across product_name and sku.
			if ( ! empty( $filters['search'] ) ) {
				$query_args['s'] = sanitize_text_field( $filters['search'] );
			}

			// Meta queries for structured filters.
			$meta_query = array();

			if ( ! empty( $filters['category'] ) ) {
				$meta_query[] = array(
					'key'     => 'category',
					'value'   => sanitize_text_field( $filters['category'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['location'] ) ) {
				$meta_query[] = array(
					'key'     => 'location_name',
					'value'   => sanitize_text_field( $filters['location'] ),
					'compare' => 'LIKE',
				);
			}

			if ( ! empty( $filters['location_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'location_id',
					'value'   => sanitize_text_field( $filters['location_id'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['sku'] ) ) {
				$meta_query[] = array(
					'key'     => 'sku',
					'value'   => sanitize_text_field( $filters['sku'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['product_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'product_id',
					'value'   => sanitize_text_field( $filters['product_id'] ),
					'compare' => '=',
				);
			}

			// Stock status filter.
			if ( ! empty( $filters['stock_status'] ) ) {
				$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
				$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

				switch ( $filters['stock_status'] ) {
					case 'in_stock':
						$meta_query[] = array(
							'key'     => 'quantity',
							'value'   => $low_threshold,
							'compare' => '>=',
							'type'    => 'NUMERIC',
						);
						break;
					case 'low_stock':
						$meta_query[] = array(
							'key'     => 'quantity',
							'value'   => array( 1, $low_threshold - 1 ),
							'compare' => 'BETWEEN',
							'type'    => 'NUMERIC',
						);
						break;
					case 'out_of_stock':
						$meta_query[] = array(
							'key'     => 'quantity',
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

			$module = self::get_cct_module();
			if ( ! $module ) {
				return array();
			}
			$items = $module->data->get_items( $this->cct_slug, $query_args );

			return is_array( $items ) ? $items : array();
		}

		/**
		 * Get a single cached item by identifier.
		 *
		 * @since 1.2.0
		 *
		 * @param string $identifier The lookup value.
		 * @param string $by         Lookup field: 'sku', 'product_id', or 'cct_id'.
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
				$module = self::get_cct_module();
				if ( ! $module ) {
					return null;
				}
				return $module->data->get_item( absint( $identifier ), $this->cct_slug );
			}

			$filters = array(
				$by        => $identifier,
				'per_page' => 1,
			);

			$items = $this->get_cached_items( $filters );

			return ! empty( $items ) ? $items[0] : null;
		}

		/**
		 * Get a cached item by FlowHub product_id and optional location.
		 *
		 * @since 1.2.0
		 *
		 * @param string      $product_id  FlowHub product ID.
		 * @param string|null $location_id Optional location ID for disambiguation.
		 * @return array|null Item array or null if not found.
		 */
		public function get_cached_item_by_product_id( $product_id, $location_id = null ) {
			$filters = array(
				'product_id' => sanitize_text_field( $product_id ),
				'per_page'   => 1,
			);

			if ( null !== $location_id ) {
				$filters['location_id'] = sanitize_text_field( $location_id );
			}

			$items = $this->get_cached_items( $filters );

			return ! empty( $items ) ? $items[0] : null;
		}

		/**
		 * Get distinct values for a CCT column (for filter dropdowns).
		 *
		 * @since 1.2.0
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
		 * @since 1.2.0
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

						// Table name is constructed from a sanitized CCT slug.
						// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
						// phpcs:enable

						return absint( $count );
		}

		/**
		 * Check if the cached data is fresh.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_age_seconds Maximum age in seconds (default 15 minutes).
		 * @return bool True if fresh, false if stale.
		 */
		public function is_fresh( $max_age_seconds = 900 ) {
			$option_key = 'wp_mcp_ai_flowhub_last_sync';
			if ( ! empty( $this->connection_id ) ) {
				$option_key .= '_' . $this->connection_id;
			}
			$last_sync = get_option( $option_key, '' );

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
		 * @since 1.2.0
		 *
		 * @return string ISO 8601 timestamp or empty string.
		 */
		public function get_last_sync_time() {
			$option_key = 'wp_mcp_ai_flowhub_last_sync';
			if ( ! empty( $this->connection_id ) ) {
				$option_key .= '_' . $this->connection_id;
			}
			return get_option( $option_key, '' );
		}

		// ------------------------------------------------------------------ //
		// Write Operations                                                    //
		// ------------------------------------------------------------------ //

		/**
		 * Upsert a FlowHub item into the CCT.
		 *
		 * Matches on product_id + location_id compound key. If a matching
		 * row exists, it is updated; otherwise a new row is created.
		 *
		 * @since 1.2.0
		 *
		 * @param array $flowhub_item Raw FlowHub API item.
		 * @param array $mapping      Optional. Custom field mapping overrides.
		 * @return int|WP_Error CCT item ID or WP_Error.
		 */
		public function upsert( $flowhub_item, $mapping = array() ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$mapped = $this->map_flowhub_item_to_cct_row( $flowhub_item, $mapping );

			if ( empty( $mapped['product_id'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_missing_product_id',
					__( 'FlowHub item is missing required product_id.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check for existing row (compound key: product_id + location_id).
			$existing = $this->get_cached_item_by_product_id(
				$mapped['product_id'],
				$mapped['location_id']
			);

			if ( $existing ) {
				// Update existing item.
				$mapped['_ID'] = $existing['_ID'];
				$module        = self::get_cct_module();
				if ( ! $module ) {
					return new WP_Error(
						'wp_mcp_ai_flowhub_jetengine_not_ready',
						__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				$result = $module->data->update_item( $mapped, $this->cct_slug );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $existing['_ID'];
			}

			// Create new item.
			$mapped['cct_status'] = 'publish';
			$module               = self::get_cct_module();
			if ( ! $module ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_jetengine_not_ready',
					__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
				);
			}
			$result = $module->data->create_item( $mapped, $this->cct_slug );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return absint( $result );
		}

		/**
		 * Truncate all items from the CCT.
		 *
		 * @since 1.2.0
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
						$module = self::get_cct_module();
						if ( $module ) {
							$module->data->delete_item( absint( $item['_ID'] ), $this->cct_slug );
						}
					}
				}
				// Fetch next batch.
				$items = $this->get_cached_items( array( 'per_page' => 100 ) );
			}

			return true;
		}

		/**
		 * Mark all items as stale.
		 *
		 * @since 1.2.0
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
		 * Delete a single item from the CCT.
		 *
		 * @since 1.2.0
		 *
		 * @param int $cct_item_id CCT item ID.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		public function delete_item( $cct_item_id ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			$module = self::get_cct_module();
			if ( ! $module ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_jetengine_not_ready',
					__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
				);
			}
			return $module->data->delete_item( absint( $cct_item_id ), $this->cct_slug );
		}

		/**
		 * Update the woo_product_id for a CCT item.
		 *
		 * @since 1.2.0
		 *
		 * @param int $cct_item_id     CCT item ID.
		 * @param int $woo_product_id  WooCommerce product ID.
		 * @return bool|WP_Error
		 */
		public function update_woo_product_id( $cct_item_id, $woo_product_id ) {
			$item = $this->get_cached_item( $cct_item_id, 'cct_id' );
			if ( ! $item ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_item_not_found',
					__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item['woo_product_id'] = absint( $woo_product_id );
			$item['_ID']            = absint( $cct_item_id );

			$module = self::get_cct_module();
			if ( ! $module ) {
				return new WP_Error(
					'wp_mcp_ai_flowhub_jetengine_not_ready',
					__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
				);
			}
			return $module->data->update_item( $item, $this->cct_slug );
		}

		// ------------------------------------------------------------------ //
		// Sync Operations                                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Sync inventory from FlowHub API into the CCT.
		 *
		 * @since 1.2.0
		 *
		 * @param bool          $force    Force full sync even if data is fresh.
		 * @param callable|null $progress Optional progress callback.
		 * @param bool          $dry_run  If true, skip CCT writes and only validate
		 *                                the API query + count items. Default false.
		 * @return array|WP_Error Sync result with item_count, location_count, duration.
		 */
		public function sync_from_api( $force = false, $progress = null, $dry_run = false ) {
			if ( ! $this->client ) {
				// When a connection ID is set, load credentials from Remote Sites.
				if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
					if ( $connection && ! empty( $connection['client_id'] ) && ! empty( $connection['api_key'] ) ) {
						$client_id   = $connection['client_id'];
						$api_key     = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
						$location_id = isset( $connection['location_id'] ) ? $connection['location_id'] : '';

						$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
						$base_url = isset( $settings['api_base_url'] ) ? wp_unslash( $settings['api_base_url'] ) : '';

						// Apply proxy settings from toolkit config.
						$proxy_url  = '';
						$proxy_auth = '';
						if ( ! empty( $settings['proxy_enabled'] ) && ! empty( $settings['proxy_url'] ) ) {
							$proxy_url  = wp_unslash( $settings['proxy_url'] );
							$proxy_auth = ! empty( $settings['proxy_username'] )
								? wp_unslash( $settings['proxy_username'] ) . ':' . wp_unslash( $settings['proxy_password'] )
								: '';
						}

						$this->client = new WP_MCP_AI_FlowHub_Client( $client_id, $api_key, $base_url, null, $location_id, $proxy_url, $proxy_auth );
					}
				}

				// Fall back to global settings if no connection-based client was created.
				if ( ! $this->client ) {
					$this->client = WP_MCP_AI_FlowHub_Client::from_settings();
					if ( is_wp_error( $this->client ) ) {
						return $this->client;
					}
				}
			}

			$start_time = microtime( true );

			// Ensure CCT columns exist (skip in dry-run, handled upstream).
			if ( ! $dry_run ) {
				$columns_result = $this->ensure_columns();
				if ( is_wp_error( $columns_result ) ) {
					return $columns_result;
				}

				if ( $force ) {
					$this->truncate();
				}
			}

			$location_count = 0;
			$item_count     = 0;
			$error_count    = 0;
			$locations      = array();

			$all_items = $this->client->get_all_inventory( $progress );

			if ( is_wp_error( $all_items ) ) {
				return $all_items;
			}

			$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
			$mapping  = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : array();

			if ( $dry_run ) {
				// In dry-run mode, count items but skip CCT writes.
				foreach ( $all_items as $item ) {
					++$item_count;

					$loc_id = isset( $item['locationId'] ) ? $item['locationId'] : '';
					if ( ! empty( $loc_id ) && ! isset( $locations[ $loc_id ] ) ) {
						$locations[ $loc_id ] = true;
						++$location_count;
					}
				}

				$duration = round( microtime( true ) - $start_time, 2 );

				return array(
					'item_count'     => $item_count,
					'location_count' => $location_count,
					'error_count'    => 0,
					'duration'       => $duration,
					'timestamp'      => current_time( 'mysql' ),
					'dry_run'        => true,
				);
			}

			foreach ( $all_items as $item ) {
				$result = $this->upsert( $item, $mapping );

				if ( is_wp_error( $result ) ) {
					++$error_count;
				} else {
					++$item_count;
				}

				// Track unique locations.
				$loc_id = isset( $item['locationId'] ) ? $item['locationId'] : '';
				if ( ! empty( $loc_id ) && ! isset( $locations[ $loc_id ] ) ) {
					$locations[ $loc_id ] = true;
					++$location_count;
				}
			}

			$duration = round( microtime( true ) - $start_time, 2 );

			$last_sync_key       = 'wp_mcp_ai_flowhub_last_sync';
			$last_sync_error_key = 'wp_mcp_ai_flowhub_last_sync_error';
			if ( ! empty( $this->connection_id ) ) {
				$last_sync_key       .= '_' . $this->connection_id;
				$last_sync_error_key .= '_' . $this->connection_id;
			}
			update_option( $last_sync_key, current_time( 'mysql' ) );
			delete_option( $last_sync_error_key );

			$result = array(
				'item_count'     => $item_count,
				'location_count' => $location_count,
				'error_count'    => $error_count,
				'duration'       => $duration,
				'timestamp'      => current_time( 'mysql' ),
			);

			do_action( 'wp_mcp_ai_flowhub_after_sync', $result );

			return $result;
		}

		/**
		 * Sync a single product from FlowHub API into the CCT.
		 *
		 * @since 1.2.0
		 *
		 * @param string $product_id FlowHub product ID.
		 * @return array|WP_Error Sync result or WP_Error.
		 */
		public function sync_single_product( $product_id ) {
			if ( ! $this->client ) {
				// When a connection ID is set, load credentials from Remote Sites.
				if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
					if ( $connection && ! empty( $connection['client_id'] ) && ! empty( $connection['api_key'] ) ) {
						$client_id   = $connection['client_id'];
						$api_key     = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
						$location_id = isset( $connection['location_id'] ) ? $connection['location_id'] : '';

						$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
						$base_url = isset( $settings['api_base_url'] ) ? wp_unslash( $settings['api_base_url'] ) : '';

						// Apply proxy settings from toolkit config.
						$proxy_url  = '';
						$proxy_auth = '';
						if ( ! empty( $settings['proxy_enabled'] ) && ! empty( $settings['proxy_url'] ) ) {
							$proxy_url  = wp_unslash( $settings['proxy_url'] );
							$proxy_auth = ! empty( $settings['proxy_username'] )
								? wp_unslash( $settings['proxy_username'] ) . ':' . wp_unslash( $settings['proxy_password'] )
								: '';
						}

						$this->client = new WP_MCP_AI_FlowHub_Client( $client_id, $api_key, $base_url, null, $location_id, $proxy_url, $proxy_auth );
					}
				}

				// Fall back to global settings.
				if ( ! $this->client ) {
					$this->client = WP_MCP_AI_FlowHub_Client::from_settings();
					if ( is_wp_error( $this->client ) ) {
						return $this->client;
					}
				}
			}

			$item = $this->client->get_product( $product_id );

			if ( is_wp_error( $item ) ) {
				return $item;
			}

			$result = $this->upsert( $item );

			return array(
				'product_id'  => $product_id,
				'cct_item_id' => is_wp_error( $result ) ? 0 : $result,
				'success'     => ! is_wp_error( $result ),
			);
		}

		// ------------------------------------------------------------------ //
		// Field Mapping                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the default field mapping from FlowHub API fields to CCT columns.
		 *
		 * @since 1.2.0
		 *
		 * @return array
		 */
		public function get_default_field_mapping() {
			return array(
				'product_id'           => 'productId',
				'variant_id'           => 'variantId',
				'parent_product_id'    => 'parentProductId',
				'sku'                  => 'sku',
				'product_name'         => 'productName',
				'variant_name'         => 'variantName',
				'category'             => 'category',
				'custom_category_name' => 'customCategoryName',
				'purchase_category'    => 'purchaseCategory',
				'product_description'  => 'productDescription',
				'quantity'             => 'quantity',
				'location_id'          => 'locationId',
				'location_name'        => 'locationName',
				'unit_of_measure'      => 'inventoryUnitOfMeasure',
				// Special extractors (parsed from item_data JSON).
				'image_url'            => '_extracted.image_url',
				'price'                => '_extracted.price',
			);
		}

		/**
		 * Map a FlowHub API item to a CCT row using field mapping.
		 *
		 * @since 1.2.0
		 *
		 * @param array $flowhub_item Raw FlowHub API item.
		 * @param array $mapping      Optional. Custom field mapping overrides.
		 * @return array CCT row data.
		 */
		protected function map_flowhub_item_to_cct_row( $flowhub_item, $mapping = array() ) {
			$effective_mapping = ! empty( $mapping )
				? array_merge( $this->get_default_field_mapping(), $mapping )
				: $this->get_default_field_mapping();

			$row = array();

			foreach ( $effective_mapping as $cct_column => $fh_field ) {
				if ( 0 === strpos( $fh_field, '_extracted.' ) ) {
					// Special extractor — parse from item_data JSON.
					$extracted_key      = substr( $fh_field, 11 );
					$row[ $cct_column ] = $this->extract_from_item_data( $flowhub_item, $extracted_key );
				} elseif ( isset( $flowhub_item[ $fh_field ] ) ) {
					$row[ $cct_column ] = $flowhub_item[ $fh_field ];
				} else {
					$row[ $cct_column ] = '';
				}
			}

			// Always set computed fields.
			$row['item_data']    = wp_json_encode( $flowhub_item );
			$row['sync_hash']    = md5( wp_json_encode( $flowhub_item ) );
			$row['sync_status']  = 'synced';
			$row['last_updated'] = current_time( 'mysql' );

			return $row;
		}

		/**
		 * Extract a value from the item_data JSON in a FlowHub item.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $item FlowHub item.
		 * @param string $key  Key to extract (e.g. 'image_url', 'price').
		 * @return string Extracted value or empty string.
		 */
		protected function extract_from_item_data( $item, $key ) {
			// Some FlowHub items nest extra data inside a JSON string in item_data.
			$raw_data = isset( $item['itemData'] ) ? $item['itemData'] : '';

			if ( empty( $raw_data ) ) {
				return '';
			}

			if ( is_string( $raw_data ) ) {
				$parsed = json_decode( $raw_data, true );
				if ( is_array( $parsed ) ) {
					$raw_data = $parsed;
				}
			}

			if ( ! is_array( $raw_data ) ) {
				return '';
			}

			switch ( $key ) {
				case 'image_url':
					// Try common image field names.
					foreach ( array( 'imageUrl', 'image_url', 'image', 'thumbnailUrl', 'thumbnail' ) as $field ) {
						if ( ! empty( $raw_data[ $field ] ) ) {
							return esc_url_raw( $raw_data[ $field ] );
						}
					}
					// Check nested images array.
					if ( ! empty( $raw_data['images'] ) && is_array( $raw_data['images'] ) ) {
						$first = reset( $raw_data['images'] );
						if ( is_array( $first ) && ! empty( $first['url'] ) ) {
							return esc_url_raw( $first['url'] );
						}
						if ( is_string( $first ) ) {
							return esc_url_raw( $first );
						}
					}
					return '';

				case 'price':
					foreach ( array( 'price', 'unitPrice', 'retailPrice', 'sellingPrice' ) as $field ) {
						if ( isset( $raw_data[ $field ] ) && is_numeric( $raw_data[ $field ] ) ) {
							return number_format( floatval( $raw_data[ $field ] ), 2, '.', '' );
						}
					}
					return '0.00';

				default:
					return isset( $raw_data[ $key ] ) ? sanitize_text_field( (string) $raw_data[ $key ] ) : '';
			}
		}

		// ------------------------------------------------------------------ //
		// Helpers                                                             //
		// ------------------------------------------------------------------ //

		/**
		 * Map a user-facing orderby value to a CCT field name.
		 *
		 * @since 1.2.0
		 *
		 * @param string $orderby User-facing orderby value.
		 * @return string CCT field name.
		 */
		protected function get_orderby_field( $orderby ) {
			$map = array(
				'product_name' => 'product_name',
				'quantity'     => 'quantity',
				'last_updated' => 'last_updated',
				'sku'          => 'sku',
				'category'     => 'category',
				'location'     => 'location_name',
				'price'        => 'price',
			);

			$orderby = sanitize_key( $orderby );

			return isset( $map[ $orderby ] ) ? $map[ $orderby ] : 'last_updated';
		}

		// ------------------------------------------------------------------ //
		// CCT Auto-Registration                                               //
		// ------------------------------------------------------------------ //

		/**
		 * Hook into JetEngine to auto-create the FlowHub inventory CCT on init.
		 *
		 * JetEngine's CCT module hydrates its table cache on `init` at priorities
		 * 1-10; registering inside that window races with it and stomps
		 * JetEngine's CCT state. Priority 11 is the documented safe window.
		 *
		 * @since 1.5.0
		 */
		public static function bootstrap() {
			add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );
			add_action( 'init', array( __CLASS__, 'maybe_enable_cct_module' ), 10 );
			add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );
		}

		/**
		 * Register the FlowHub inventory CCT if it is missing.
		 *
		 * @since 1.5.0
		 */
		public static function maybe_register_cct() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			if ( empty( $settings['enable_flowhub_toolkit'] ) ) {
				return;
			}

			$module = self::get_cct_module();

			if ( ! $module ) {
				return;
			}

			if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
				return;
			}

			if ( self::cct_exists( $module ) ) {
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
		 * Determine whether the FlowHub inventory CCT already exists.
		 *
		 * @since 1.5.0
		 *
		 * @param \Jet_Engine\Modules\Custom_Content_Types\Module $module Module instance.
		 * @return bool
		 */
		protected static function cct_exists( $module ) {
			$data = $module->manager->data;

			if ( empty( $data->db ) ) {
				return false;
			}

			$slug     = self::CCT_SLUG_DEFAULT;
			$settings = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
			if ( ! empty( $settings['cct_slug'] ) ) {
				$slug = sanitize_key( $settings['cct_slug'] );
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

			return ! empty( $records );
		}

			/**
			 * Look up a single CCT record by slug.
			 *
			 * Uses JetEngine's DB query layer instead of the non-existent
			 * get_item_by_slug() method on the Data class.
			 *
			 * @since 1.5.0
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
			 * Retrieve the JetEngine Custom Content Types module instance.
			 *
			 * @since 1.5.0
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

			if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
				return null;
			}

			return $module_wrapper->instance;
		}

		/**
		 * Automatically enable the JetEngine CCT module if it's not already active.
		 *
		 * FlowHub (and other toolkit storage features) depend on the Custom Content
		 * Types module. This runs on init priority 10 so the module is ready before
		 * CCT registration at priority 11.
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
		 * Automatically enable the JetEngine data stores module if it's not already active.
		 *
		 * @since 1.5.0
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

		/**
		 * Build the request payload used to register the content type.
		 *
		 * @since 1.5.0
		 *
		 * @return array
		 */
		protected static function get_registration_request() {
			$label = __( 'FlowHub Inventory', 'mcp-ai-wpoos-pro' );

			return array(
				'name'        => $label,
				'slug'        => self::CCT_SLUG_DEFAULT,
				'args'        => self::get_cct_args( $label ),
				'meta_fields' => self::get_meta_fields(),
			);
		}

		/**
		 * Assemble the JetEngine arguments for the FlowHub inventory CCT.
		 *
		 * @since 1.5.0
		 *
		 * @param string $label Human-readable label for the content type.
		 * @return array
		 */
		protected static function get_cct_args( $label ) {
			return array(
				'name'                => $label,
				'slug'                => self::CCT_SLUG_DEFAULT,
				'position'            => '-1',
				'icon'                => 'dashicons-store',
				'capability'          => 'manage_woocommerce',
				'has_single'          => false,
				'create_index'        => true,
				'hide_field_names'    => false,
				'rest_get_enabled'    => false,
				'rest_put_enabled'    => false,
				'rest_post_enabled'   => false,
				'rest_delete_enabled' => false,
				'admin_columns'       => array(
					'_ID'           => array(
						'enabled'     => true,
						'prefix'      => '#',
						'is_sortable' => true,
						'is_num'      => true,
					),
					'product_name'  => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
					'sku'           => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
					'quantity'      => array(
						'enabled'     => true,
						'is_sortable' => true,
						'is_num'      => true,
					),
					'location_name' => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
					'sync_status'   => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
				),
			);
		}

		/**
		 * Define the meta fields for the FlowHub inventory CCT.
		 *
		 * Uses the column definitions from {@see $columns} and labels from
		 * {@see get_column_label()} to stay in sync with the runtime schema.
		 *
		 * @since 1.5.0
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
					case 'datetime':
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
		 * @since 1.5.0
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
	}
}
