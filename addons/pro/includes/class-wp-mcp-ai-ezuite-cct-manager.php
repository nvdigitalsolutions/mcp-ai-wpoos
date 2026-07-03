<?php
/**
 * EZuite CCT Manager.
 *
 * Manages the JetEngine Custom Content Type (CCT) that serves as the local
 * cache for EZuite ERP inventory data. Handles column auto-creation, upsert
 * operations, and cached read queries — so tools never hit the EZuite API
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

if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {

	/**
	 * EZuite CCT Manager.
	 *
	 * Provides read/write access to the EZuite inventory CCT.
	 * All public methods return canonical shapes ready for tool envelopes.
	 *
	 * @since 1.9.0
	 */
	class WP_MCP_AI_EZuite_CCT_Manager {

		/**
		 * Default CCT slug.
		 *
		 * @var string
		 */
		const CCT_SLUG_DEFAULT = 'ezuite_inventory';

		/**
		 * Schema version for auto-discovery of new columns on update.
		 *
		 * @since 1.9.0
		 *
		 * @var string
		 */
		const SCHEMA_VERSION = '1.9.0';

		/**
		 * Base ID for meta field identifiers.
		 *
		 * Using 42000 range to avoid conflicts with other CCT fields.
		 *
		 * @since 1.9.0
		 *
		 * @var int
		 */
		const FIELD_ID_BASE = 42000;

		/**
		 * Current CCT slug.
		 *
		 * @var string
		 */
		protected $cct_slug = '';

		/**
		 * Remote Sites connection ID.
		 *
		 * When set, option keys are suffixed with this ID
		 * for per-connection isolation.
		 *
		 * @since 1.9.0
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
			'sku'            => 'text',
			'name'           => 'text',
			'quantity'       => 'number',
			'warehouse'      => 'text',
			'reorder_point'  => 'number',
			'supplier'       => 'text',
			'cost_price'     => 'number',
			'last_updated'   => 'datetime',
			'woo_product_id' => 'number',
			'connection_id'  => 'text',
		);

		/**
		 * Constructor.
		 *
		 * @since 1.9.0
		 *
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 */
		public function __construct( $connection_id = null ) {
			if ( is_string( $connection_id ) && ! empty( $connection_id ) ) {
				$this->connection_id = $connection_id;
			}
			$this->cct_slug = $this->get_configured_cct_slug();
		}

		// ------------------------------------------------------------------ //
		// Configuration                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the configured CCT slug from toolkit settings.
		 *
		 * @since 1.9.0
		 *
		 * @return string
		 */
		public function get_configured_cct_slug() {
			$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
			return ! empty( $settings['cct_slug'] )
				? sanitize_key( $settings['cct_slug'] )
				: self::CCT_SLUG_DEFAULT;
		}

		/**
		 * Get the current CCT slug.
		 *
		 * @since 1.9.0
		 *
		 * @return string
		 */
		public function get_cct_slug() {
			return $this->cct_slug;
		}

		/**
		 * Get the connection ID.
		 *
		 * @since 1.9.0
		 *
		 * @return string|null
		 */
		public function get_connection_id() {
			return $this->connection_id;
		}

		/**
		 * Set the CCT slug.
		 *
		 * @since 1.9.0
		 *
		 * @param string $slug CCT slug.
		 */
		public function set_cct_slug( $slug ) {
			$this->cct_slug = sanitize_key( $slug );
		}

		/**
		 * Check if JetEngine and the CCT are available.
		 *
		 * @since 1.9.0
		 *
		 * @return bool|WP_Error True if available, WP_Error otherwise.
		 */
		public function is_cct_available() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_jetengine_missing',
					__( 'JetEngine plugin is required for EZuite inventory storage. Please install and activate JetEngine.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Use the module system instead of jet_engine()->cct directly.
			$cct_module = self::get_cct_module();
			if ( ! $cct_module ) {
				// Try a one-shot activation in case it wasn't enabled yet.
				self::maybe_enable_cct_module();
				$cct_module = self::get_cct_module();
			}

			if ( ! $cct_module ) {
				// Table-exists fallback (follows the vitals-log CCT pattern).
				// When JetEngine's module system can't provide a handle but
				// the physical table was already created by a prior sync,
				// treat the CCT as available so dry runs and status checks
				// still work. Write operations will surface a clear error if
				// they can't obtain a module instance later.
				if ( $this->table_exists() ) {
					return true;
				}

				return new WP_Error(
					'wp_mcp_ai_ezuite_jetengine_not_ready',
					__( 'JetEngine Custom Content Types module is not active. Please enable it in JetEngine → JetEngine Settings → Modules.', 'mcp-ai-wpoos-pro' )
				);
			}

			$data = $cct_module->manager->data;

			if ( empty( $data->db ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_cct_not_ready',
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
					'wp_mcp_ai_ezuite_cct_missing',
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
		 * @since 1.9.1
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
		 * Uses the existing maybe_register_cct() logic that
		 * creates the CCT via JetEngine's module system.
		 * Only creates the CCT shell; columns are added
		 * separately via ensure_columns().
		 *
		 * @since 1.9.0
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
				if ( 'wp_mcp_ai_ezuite_jetengine_not_ready' === $available->get_error_code() ) {
					return $available;
				}
				// If CCT still missing after bootstrap attempt, surface the error.
				if ( 'wp_mcp_ai_ezuite_cct_missing' === $available->get_error_code() ) {
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
		 * Check if the EZuite API is configured with credentials.
		 *
		 * Checks that at least one ezuite_erp Remote Sites connection
		 * exists and is enabled, or falls back to checking for a
		 * specific connection_id.
		 *
		 * @since 1.9.0
		 *
		 * @return bool True if API is configured.
		 */
		public function is_api_configured() {
			// If we have a connection ID, check that the connection exists in Remote Sites.
			if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
				if ( $connection && ! empty( $connection['enabled'] ) ) {
					return true;
				}
				return false;
			}

			// No specific connection ID — check if any ezuite_erp connection exists.
			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_connections();
				foreach ( $connections as $conn ) {
					if ( ! empty( $conn['enabled'] ) && 'ezuite_erp' === $conn['type'] ) {
						return true;
					}
				}
			}

			return false;
		}

		// ------------------------------------------------------------------ //
		// Column Management                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Get the column definitions.
		 *
		 * @since 1.9.0
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
		 * @since 1.9.0
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

			// Track fields missing from JetEngine's meta_fields definition —
			// these need the DB column AND the JetEngine definition synced.
			$missing_from_meta = array();

			foreach ( $this->columns as $column_name => $column_type ) {
				// Check JetEngine CCT field definitions first (fast path).
				if ( in_array( $column_name, $existing_fields, true ) ) {
					continue;
				}

				// Track that JetEngine doesn't know about this field.
				$missing_from_meta[] = $column_name;

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

			// Sync JetEngine's CCT field definitions so create_item / update_item
			// recognise these columns and don't reject the data.
			if ( ! empty( $missing_from_meta ) ) {
				$sync_result = $this->sync_jetengine_field_definitions( $missing_from_meta );
				if ( is_wp_error( $sync_result ) ) {
					return $sync_result;
				}
			}

			/**
			 * Fires after CCT columns are ensured for EZuite.
			 *
			 * @since 1.9.0
			 *
			 * @param string $cct_slug      The CCT slug.
			 * @param int    $created        Number of columns created.
			 * @param string $connection_id  The EZuite connection ID.
			 */
			do_action( 'wp_mcp_ai_ezuite_after_columns_ensure', $this->cct_slug, $created, $this->connection_id );

			// Track schema version so columns are only auto-created once per version.
			$schema_key = 'wp_mcp_ai_ezuite_sync_db_version';
			if ( ! empty( $this->connection_id ) ) {
				$schema_key .= '_' . $this->connection_id;
			}
			update_option( $schema_key, self::SCHEMA_VERSION );

			return $created;
		}

		/**
		 * Get existing CCT field names.
		 *
		 * @since 1.9.0
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
		 * @since 1.9.0
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
		 * @since 1.9.0
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
		 * Sync JetEngine's CCT field definitions for missing columns.
		 *
		 * When the CCT already exists but its meta_fields definition is empty
		 * or incomplete (e.g. the CCT was created manually in JetEngine without
		 * the expected field definitions), create_item / update_item will reject
		 * unknown field keys.  This method updates the CCT's meta_fields record
		 * in JetEngine's post_types table so that every declared column has a
		 * matching field definition.
		 *
		 * @since 1.9.1
		 *
		 * @param string[] $missing_columns Column names that are missing from JetEngine's definition.
		 * @return bool|WP_Error True on success, WP_Error on failure.
		 */
		protected function sync_jetengine_field_definitions( $missing_columns ) {
			$module = self::get_cct_module();
			if ( ! $module || empty( $module->manager ) || empty( $module->manager->data ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_jetengine_not_ready',
					__( 'JetEngine CCT module is not available for field sync.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Get the existing CCT record so we can update it.
			$cct_record = $this->get_cct_record_by_slug( $this->cct_slug );
			if ( ! $cct_record || empty( $cct_record['id'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_cct_record_missing',
					__( 'Cannot sync fields: CCT record not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			$cct_id = absint( $cct_record['id'] );

			// Decode existing meta_fields (may be a JSON string).
			$existing_meta = isset( $cct_record['meta_fields'] ) ? $cct_record['meta_fields'] : array();
			if ( is_string( $existing_meta ) ) {
				$existing_meta = json_decode( $existing_meta, true );
			}
			if ( ! is_array( $existing_meta ) ) {
				$existing_meta = array();
			}

			// Determine the next available field ID (max existing + 1).
			$max_id = self::FIELD_ID_BASE - 1;
			foreach ( $existing_meta as $field ) {
				if ( ! empty( $field['id'] ) && absint( $field['id'] ) > $max_id ) {
					$max_id = absint( $field['id'] );
				}
			}

			// Build field definitions for missing columns.
			$columns       = $this->get_column_definitions();
			$new_fields    = array();
			$next_field_id = $max_id + 1;

			foreach ( $missing_columns as $column_name ) {
				if ( ! isset( $columns[ $column_name ] ) ) {
					continue;
				}

				$column_type = $columns[ $column_name ];
				$args        = array();

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

				$new_fields[] = self::build_field(
					$next_field_id,
					$column_name,
					$this->get_column_label( $column_name ),
					$jet_type,
					$args
				);

				++$next_field_id;
			}

			if ( empty( $new_fields ) ) {
				return true;
			}

			// Merge and update via JetEngine's API.
			$merged_meta = array_merge( $existing_meta, $new_fields );

			$data = $module->manager->data;

			// Build an update request from the existing CCT record,
			// preserving all existing values and only replacing meta_fields.
			// Use the real CCT slug (not the constant) in case the admin
			// configured a custom slug via toolkit settings.
			$request = $cct_record;
			unset( $request['id'] ); // JetEngine uses 'id' from set_request, not the record key.
			$request['meta_fields'] = $merged_meta;
			$request['slug']        = $this->cct_slug;
			$request['id']          = $cct_id;

			$data->set_request( $request );

			if ( method_exists( $data, 'sanitize_item_request' ) && ! $data->sanitize_item_request() ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_field_sync_sanitize_failed',
					__( 'Field definition sync failed during sanitization.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item = $data->sanitize_item_from_request();

			if ( empty( $item ) || ! is_array( $item ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_field_sync_invalid',
					__( 'Field definition sync produced invalid item.', 'mcp-ai-wpoos-pro' )
				);
			}

			$data->before_item_update( $item, false );

			$updated_id = $data->update_item_in_db( $item );

			if ( ! $updated_id ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_field_sync_update_failed',
					__( 'Failed to update CCT field definitions in database.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item['id'] = $updated_id;

			$data->after_item_update( $item, false );

			if ( ! empty( $data->db ) && method_exists( $data->db, 'query_raw' ) ) {
				$data->db->query_raw( 'post_types' );
			}

			if ( function_exists( 'wp_mcp_ai_log' ) ) {
				wp_mcp_ai_log(
					sprintf(
						/* translators: 1: CCT slug, 2: comma-separated field names */
						__( 'EZuite CCT field definitions synced for "%1$s": %2$s', 'mcp-ai-wpoos-pro' ),
						$this->cct_slug,
						implode( ', ', $missing_columns )
					),
					'info'
				);
			}

			return true;
		}

		/**
		 * Get a human-readable label for a CCT column.
		 *
		 * @since 1.9.0
		 *
		 * @param string $column_name Column name.
		 * @return string
		 */
		protected function get_column_label( $column_name ) {
			$labels = array(
				'sku'            => __( 'SKU', 'mcp-ai-wpoos-pro' ),
				'name'           => __( 'Product Name', 'mcp-ai-wpoos-pro' ),
				'quantity'       => __( 'Quantity', 'mcp-ai-wpoos-pro' ),
				'warehouse'      => __( 'Warehouse', 'mcp-ai-wpoos-pro' ),
				'reorder_point'  => __( 'Reorder Point', 'mcp-ai-wpoos-pro' ),
				'supplier'       => __( 'Supplier', 'mcp-ai-wpoos-pro' ),
				'cost_price'     => __( 'Cost Price', 'mcp-ai-wpoos-pro' ),
				'last_updated'   => __( 'Last Updated', 'mcp-ai-wpoos-pro' ),
				'woo_product_id' => __( 'WooCommerce Product ID', 'mcp-ai-wpoos-pro' ),
				'connection_id'  => __( 'Connection ID', 'mcp-ai-wpoos-pro' ),
			);

			return isset( $labels[ $column_name ] ) ? $labels[ $column_name ] : ucwords( str_replace( '_', ' ', $column_name ) );
		}

		// ------------------------------------------------------------------ //
		// Read Operations (CCT queries, no API calls)                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get cached inventory items with filtering, sorting, and pagination.
		 *
		 * @since 1.9.0
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

			// Full-text search across name and sku.
			if ( ! empty( $filters['search'] ) ) {
				$query_args['s'] = sanitize_text_field( $filters['search'] );
			}

			// Meta queries for structured filters.
			$meta_query = array();

			if ( ! empty( $filters['sku'] ) ) {
				$meta_query[] = array(
					'key'     => 'sku',
					'value'   => sanitize_text_field( $filters['sku'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['warehouse'] ) ) {
				$meta_query[] = array(
					'key'     => 'warehouse',
					'value'   => sanitize_text_field( $filters['warehouse'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['supplier'] ) ) {
				$meta_query[] = array(
					'key'     => 'supplier',
					'value'   => sanitize_text_field( $filters['supplier'] ),
					'compare' => '=',
				);
			}

			if ( ! empty( $filters['connection_id'] ) ) {
				$meta_query[] = array(
					'key'     => 'connection_id',
					'value'   => sanitize_text_field( $filters['connection_id'] ),
					'compare' => '=',
				);
			}

			// Stock status filter.
			if ( ! empty( $filters['stock_status'] ) ) {
				$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
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
			if ( ! $module || empty( $module->data ) ) {
				return array();
			}
			$items = $module->data->get_items( $this->cct_slug, $query_args );

			return is_array( $items ) ? $items : array();
		}

		/**
		 * Get the total row count.
		 *
		 * @since 1.9.0
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
		 * @since 1.9.0
		 *
		 * @param int $max_age_seconds Maximum age in seconds. Default 900 (15 minutes).
		 * @return bool True if fresh, false if stale.
		 */
		public function is_fresh( $max_age_seconds = 900 ) {
			$last_sync = $this->get_last_sync_time();

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
		 * @since 1.9.0
		 *
		 * @return string ISO 8601 timestamp or empty string.
		 */
		public function get_last_sync_time() {
			$option_key = 'wp_mcp_ai_ezuite_last_sync';
			if ( ! empty( $this->connection_id ) ) {
				$option_key .= '_' . $this->connection_id;
			}
			return get_option( $option_key, '' );
		}

		// ------------------------------------------------------------------ //
		// Write Operations                                                    //
		// ------------------------------------------------------------------ //

		/**
		 * Upsert an EZuite item into the CCT.
		 *
		 * Matches on sku + warehouse compound key. If a matching
		 * row exists, it is updated; otherwise a new row is created.
		 *
		 * @since 1.9.0
		 *
		 * @param array $ezuite_item Mapped EZuite item data.
		 * @return int|WP_Error CCT item ID or WP_Error.
		 */
		public function upsert( $ezuite_item ) {
			$available = $this->is_cct_available();
			if ( is_wp_error( $available ) ) {
				return $available;
			}

			if ( empty( $ezuite_item['sku'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_missing_sku',
					__( 'EZuite item is missing required SKU.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check for existing row (compound key: sku + warehouse).
			$existing = $this->get_cached_item_by_sku(
				$ezuite_item['sku'],
				isset( $ezuite_item['warehouse'] ) ? $ezuite_item['warehouse'] : ''
			);

			if ( $existing ) {
				// Update existing item.
				$ezuite_item['_ID'] = $existing['_ID'];
				$module             = self::get_cct_module();
				if ( ! $module || empty( $module->data ) ) {
					return new WP_Error(
						'wp_mcp_ai_ezuite_jetengine_not_ready',
						__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
					);
				}
				$result = $module->data->update_item( $ezuite_item, $this->cct_slug );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				return $existing['_ID'];
			}

			// Create new item.
			$ezuite_item['cct_status'] = 'publish';
			$module                    = self::get_cct_module();
			if ( ! $module || empty( $module->data ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_jetengine_not_ready',
					__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
				);
			}
			$result = $module->data->create_item( $ezuite_item, $this->cct_slug );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return absint( $result );
		}

		/**
		 * Update the woo_product_id for a CCT item.
		 *
		 * @since 1.9.0
		 *
		 * @param int $cct_item_id     CCT item ID.
		 * @param int $woo_product_id  WooCommerce product ID.
		 * @return bool|WP_Error
		 */
		public function update_woo_product_id( $cct_item_id, $woo_product_id ) {
			$cct_item_id    = absint( $cct_item_id );
			$woo_product_id = absint( $woo_product_id );

			$module = self::get_cct_module();
			if ( ! $module || empty( $module->data ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_jetengine_not_ready',
					__( 'JetEngine CCT module is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item = $module->data->get_item( $cct_item_id, $this->cct_slug );
			if ( ! $item ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_item_not_found',
					__( 'CCT item not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			$item['woo_product_id'] = $woo_product_id;
			$item['_ID']            = $cct_item_id;

			return $module->data->update_item( $item, $this->cct_slug );
		}

		// ------------------------------------------------------------------ //
		// Sync Operations                                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Sync inventory from the EZuite ERP API into the local CCT cache.
		 *
		 * Calls the EZuite LX_ItemPull API action (POST with API_Key /
		 * API_Action / API_Body envelope) and maps every returned item to a
		 * CCT row via map_ezuite_item_to_cct_row().
		 *
		 * When $dry_run is true the method validates the API query and counts
		 * how many items would be written without touching the CCT.
		 *
		 * @since 1.9.0
		 *
		 * @param bool        $full          True for full sync; reserved for future
		 *                                   differential sync support.
		 * @param string|null $connection_id Remote Sites connection ID.
		 * @param bool        $dry_run       If true, skip CCT writes and only validate
		 *                                   the API query + count items. Default false.
		 * @return array|WP_Error Sync result with item_count, error_count, duration.
		 */
		public function sync_from_api( $full = true, $connection_id = null, $dry_run = false ) {
			unset( $full ); // Reserved for future differential sync support.

			// Resolve connection ID: parameter takes precedence over instance property.
			$effective_connection_id = ! empty( $connection_id ) ? $connection_id : $this->connection_id;

			if ( empty( $effective_connection_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_no_connection_id',
					__( 'No EZuite connection ID provided.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_no_remote_manager',
					__( 'Remote Site Manager is required for EZuite API access.', 'mcp-ai-wpoos-pro' )
				);
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $effective_connection_id );
			if ( ! $connection || empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_connection_not_found',
					sprintf(
						/* translators: %s: connection ID */
						__( 'EZuite connection "%s" not found or disabled.', 'mcp-ai-wpoos-pro' ),
						$effective_connection_id
					)
				);
			}

			$conn_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( 'ezuite_erp' !== $conn_type && 'ezuite' !== $conn_type ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_wrong_connection_type',
					sprintf(
						/* translators: %s: connection ID */
						__( 'Connection "%s" is not an EZuite ERP connection.', 'mcp-ai-wpoos-pro' ),
						$effective_connection_id
					)
				);
			}

			// Decrypt API key.
			$api_key = isset( $connection['api_key'] )
				? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] )
				: '';

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_missing_api_key',
					__( 'API key is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			$api_url = isset( $connection['url'] ) ? trailingslashit( $connection['url'] ) : '';
			if ( empty( $api_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_missing_url',
					__( 'API URL is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			$start_time = microtime( true );

			// Ensure CCT columns exist (skip in dry-run, handled upstream).
			if ( ! $dry_run ) {
				$columns_result = $this->ensure_columns();
				if ( is_wp_error( $columns_result ) ) {
					return $columns_result;
				}
			}

			// Load field mapping from settings.
			$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
			$mapping  = isset( $settings['field_mapping'] ) ? $settings['field_mapping'] : $this->get_default_field_mapping();

			// Call EZuite LX_ItemPull API directly (canonical POST envelope).
			$request_body = array(
				'API_Key'    => $api_key,
				'API_Action' => 'LX_ItemPull',
				'API_Body'   => array(
					array(
						'Location_Code' => 'ALL',
					),
				),
			);

			$args = array(
				'method'  => 'POST',
				'timeout' => 60,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $request_body ),
			);

			$response = wp_remote_post( $api_url, $args );

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_api_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'EZuite API request failed: %s', 'mcp-ai-wpoos-pro' ),
						$response->get_error_message()
					)
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code >= 400 ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_api_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'EZuite API returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
						$status_code
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( null === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_invalid_json',
					__( 'Invalid JSON response from EZuite API.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Check EZuite Status_Code.
			if ( isset( $decoded['Status_Code'] ) && 200 !== absint( $decoded['Status_Code'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_ezuite_api_error',
					isset( $decoded['Message'] ) ? $decoded['Message'] : __( 'Unknown EZuite API error.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Extract items from the canonical Response_Body (with legacy Data fallback).
			$items = array();
			if ( ! empty( $decoded['Response_Body'] ) && is_array( $decoded['Response_Body'] ) ) {
				$items = $decoded['Response_Body'];
			} elseif ( ! empty( $decoded['Data'] ) && is_array( $decoded['Data'] ) ) {
				$items = $decoded['Data'];
			}

			$item_count  = 0;
			$error_count = 0;

			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$mapped = $this->map_ezuite_item_to_cct_row( $item, $mapping, $effective_connection_id );

				if ( $dry_run ) {
					++$item_count;
					continue;
				}

				$result = $this->upsert( $mapped );
				if ( is_wp_error( $result ) ) {
					++$error_count;
				} else {
					++$item_count;
				}
			}

			$duration = round( microtime( true ) - $start_time, 2 );

			if ( ! $dry_run ) {
				$last_sync_key       = 'wp_mcp_ai_ezuite_last_sync';
				$last_sync_error_key = 'wp_mcp_ai_ezuite_last_sync_error';
				if ( ! empty( $effective_connection_id ) ) {
					$last_sync_key       .= '_' . $effective_connection_id;
					$last_sync_error_key .= '_' . $effective_connection_id;
				}
				update_option( $last_sync_key, current_time( 'mysql' ) );
				delete_option( $last_sync_error_key );
			}

			$result = array(
				'item_count'  => $item_count,
				'error_count' => $error_count,
				'duration'    => $duration,
				'timestamp'   => current_time( 'mysql' ),
			);

			/**
			 * Fires after an EZuite sync operation completes.
			 *
			 * @since 1.9.0
			 *
			 * @param array  $result        Sync result data.
			 * @param string $connection_id The EZuite connection ID.
			 */
			do_action( 'wp_mcp_ai_ezuite_after_sync', $result, $effective_connection_id );

			return $result;
		}

		// ------------------------------------------------------------------ //
		// Field Mapping                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Get the default field mapping from EZuite API fields to CCT columns.
		 *
		 * Maps the canonical EZuite LX_ItemPull API response field names
		 * (as returned in the Response_Body array) to CCT columns.
		 *
		 * @since 1.9.0
		 *
		 * @return array<string,string> CCT column => EZuite field.
		 */
		public function get_default_field_mapping() {
			return self::get_default_field_mapping_static();
		}

		/**
		 * Static accessor for the default field mapping.
		 *
		 * Used by the settings page JavaScript to pre-fill the field mapping
		 * table without needing a class instance.
		 *
		 * @since 1.9.0
		 *
		 * @return array<string,string> CCT column => EZuite field.
		 */
		public static function get_default_field_mapping_static() {
			return array(
				'sku'           => 'Item_Code',
				'name'          => 'Item_Name',
				'quantity'      => 'Qty',
				'warehouse'     => 'Location_Code',
				'supplier'      => 'Supplier_Name',
				'cost_price'    => 'Selling_Price',
				'reorder_point' => '', // Not present in LX_ItemPull; set per-deployment.
			);
		}

		/**
		 * Map an EZuite API item to a CCT row using field mapping.
		 *
		 * @since 1.9.0
		 *
		 * @param array  $ezuite_item   Raw EZuite API item.
		 * @param array  $mapping       Field mapping overrides.
		 * @param string $connection_id Connection ID to stamp on the row.
		 * @return array CCT row data.
		 */
		protected function map_ezuite_item_to_cct_row( $ezuite_item, $mapping = array(), $connection_id = '' ) {
			$effective_mapping = ! empty( $mapping )
				? array_merge( $this->get_default_field_mapping(), $mapping )
				: $this->get_default_field_mapping();

			$row = array();

			foreach ( $effective_mapping as $cct_column => $erp_field ) {
				$row[ $cct_column ] = isset( $ezuite_item[ $erp_field ] )
					? $ezuite_item[ $erp_field ]
					: '';
			}

			// Always stamp connection_id for per-connection isolation.
			$row['connection_id'] = sanitize_text_field( $connection_id );
			$row['last_updated']  = current_time( 'mysql' );

			// Coerce numeric types.
			if ( isset( $row['quantity'] ) ) {
				$row['quantity'] = absint( $row['quantity'] );
			}
			if ( isset( $row['reorder_point'] ) ) {
				$row['reorder_point'] = absint( $row['reorder_point'] );
			}
			if ( isset( $row['cost_price'] ) ) {
				$row['cost_price'] = number_format( floatval( $row['cost_price'] ), 2, '.', '' );
			}

			return $row;
		}

		// ------------------------------------------------------------------ //
		// Helpers                                                             //
		// ------------------------------------------------------------------ //

		/**
		 * Get a cached item by SKU and optional warehouse.
		 *
		 * @since 1.9.0
		 *
		 * @param string $sku       EZuite SKU.
		 * @param string $warehouse Optional warehouse for disambiguation.
		 * @return array|null Item array or null if not found.
		 */
		protected function get_cached_item_by_sku( $sku, $warehouse = '' ) {
			$filters = array(
				'sku'      => sanitize_text_field( $sku ),
				'per_page' => 1,
			);

			if ( ! empty( $warehouse ) ) {
				$filters['warehouse'] = sanitize_text_field( $warehouse );
			}

			$items = $this->get_cached_items( $filters );

			return ! empty( $items ) ? $items[0] : null;
		}

		/**
		 * Map a user-facing orderby value to a CCT field name.
		 *
		 * @since 1.9.0
		 *
		 * @param string $orderby User-facing orderby value.
		 * @return string CCT field name.
		 */
		protected function get_orderby_field( $orderby ) {
			$map = array(
				'name'          => 'name',
				'quantity'      => 'quantity',
				'sku'           => 'sku',
				'warehouse'     => 'warehouse',
				'supplier'      => 'supplier',
				'cost_price'    => 'cost_price',
				'last_updated'  => 'last_updated',
				'reorder_point' => 'reorder_point',
			);

			$orderby = sanitize_key( $orderby );

			return isset( $map[ $orderby ] ) ? $map[ $orderby ] : 'last_updated';
		}

		// ------------------------------------------------------------------ //
		// CCT Auto-Registration                                               //
		// ------------------------------------------------------------------ //

		/**
		 * Hook into JetEngine to auto-create the EZuite inventory CCT on init.
		 *
		 * JetEngine's CCT module hydrates its table cache on `init` at priorities
		 * 1-10; registering inside that window races with it. Priority 11 is
		 * the documented safe window.
		 *
		 * @since 1.9.0
		 */
		public static function bootstrap() {
			add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 11 );
			add_action( 'init', array( __CLASS__, 'maybe_enable_cct_module' ), 10 );
			add_action( 'init', array( __CLASS__, 'maybe_enable_data_stores' ), 11 );
		}

		/**
		 * Register the EZuite inventory CCT if it is missing.
		 *
		 * @since 1.9.0
		 */
		public static function maybe_register_cct() {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			if ( empty( $settings['enable_ezuite_toolkit'] ) ) {
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
		 * Determine whether the EZuite inventory CCT already exists.
		 *
		 * @since 1.9.0
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
			$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
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
		 * @since 1.9.0
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
		 * @since 1.9.0
		 *
		 * @return \Jet_Engine\Modules\Custom_Content_Types\Module|null
		 */
		protected static function get_cct_module() {
			if ( ! function_exists( 'jet_engine' ) ) {
				return null;
			}

			$engine = jet_engine();

			// Preferred path: the ->cct shorthand is the canonical accessor
			// on all modern JetEngine versions and guarantees the data handler
			// is fully initialised.
			if ( ! empty( $engine->cct ) && ! empty( $engine->cct->data ) ) {
				return $engine->cct;
			}

			// Fallback: walk the modules registry when ->cct is unavailable
			// (e.g. the CCT module was registered but hasn't set the shorthand).
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
				// Also populate ->cct so the preferred path succeeds next time.
				$engine->cct = $module_wrapper->instance;
				return $module_wrapper->instance;
			}

			// Last-resort: call get_module() to force lazy-init (the same method
			// JetEngine itself uses to populate ->instance internally). This
			// covers edge cases where the module is active but ->instance was
			// never materialised (e.g. activation mid-request).
			if ( method_exists( $module_wrapper, 'get_module' ) ) {
				$instance = $module_wrapper->get_module();
				if ( ! empty( $instance ) && ! empty( $instance->data ) ) {
					// Populate ->cct so every subsequent call hits the fast path.
					$engine->cct = $instance;
					return $instance;
				}
			}

			return null;
		}

		/**
		 * Automatically enable the JetEngine CCT module if it's not already active.
		 *
		 * EZuite (and other toolkit storage features) depend on the Custom Content
		 * Types module. This runs on init priority 10 so the module is ready before
		 * CCT registration at priority 11.
		 *
		 * @since 1.9.0
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
		 * @since 1.9.0
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
		 * @since 1.9.0
		 *
		 * @return array
		 */
		protected static function get_registration_request() {
			$label = __( 'EZuite Inventory', 'mcp-ai-wpoos-pro' );

			return array(
				'name'        => $label,
				'slug'        => self::CCT_SLUG_DEFAULT,
				'args'        => self::get_cct_args( $label ),
				'meta_fields' => self::get_meta_fields(),
			);
		}

		/**
		 * Assemble the JetEngine arguments for the EZuite inventory CCT.
		 *
		 * @since 1.9.0
		 *
		 * @param string $label Human-readable label for the content type.
		 * @return array
		 */
		protected static function get_cct_args( $label ) {
			return array(
				'name'                => $label,
				'slug'                => self::CCT_SLUG_DEFAULT,
				'position'            => '-1',
				'icon'                => 'dashicons-database',
				'capability'          => 'manage_woocommerce',
				'has_single'          => false,
				'create_index'        => true,
				'hide_field_names'    => false,
				'rest_get_enabled'    => false,
				'rest_put_enabled'    => false,
				'rest_post_enabled'   => false,
				'rest_delete_enabled' => false,
				'admin_columns'       => array(
					'_ID'       => array(
						'enabled'     => true,
						'prefix'      => '#',
						'is_sortable' => true,
						'is_num'      => true,
					),
					'name'      => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
					'sku'       => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
					'quantity'  => array(
						'enabled'     => true,
						'is_sortable' => true,
						'is_num'      => true,
					),
					'warehouse' => array(
						'enabled'     => true,
						'is_sortable' => true,
					),
				),
			);
		}

		/**
		 * Define the meta fields for the EZuite inventory CCT.
		 *
		 * Uses the column definitions from {@see $columns} and labels from
		 * {@see get_column_label()} to stay in sync with the runtime schema.
		 *
		 * @since 1.9.0
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
		 * @since 1.9.0
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
