<?php
/**
 * Tool that syncs data with QuickBooks Desktop via a QODBC relay API.
 *
 * Architecture: QuickBooks Desktop → QODBC Driver → PHP Relay API → This Tool.
 *
 * The relay API is a lightweight PHP endpoint running on the same Windows machine
 * (or accessible via QRemote) where QuickBooks Desktop and the QODBC driver are
 * installed.  This tool sends JSON commands to that relay and returns the results.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs data between WordPress and QuickBooks Desktop through a QODBC relay.
 */
class WP_MCP_AI_Pro_Tool_QuickBooks_Desktop_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Maximum number of rows a single query may return.
	 *
	 * @var int
	 */
	const MAX_ROWS = 500;

	/**
	 * Default HTTP timeout in seconds for relay requests.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Supported QODBC entity tables that can be queried.
	 *
	 * @var string[]
	 */
	const ALLOWED_ENTITIES = array(
		// Customers & Receivables.
		'Customer',
		'Invoice',
		'InvoiceLine',
		'SalesReceipt',
		'SalesReceiptLine',
		'CreditMemo',
		'Estimate',
		'ReceivePayment',
		// Vendors & Payables.
		'Vendor',
		'Bill',
		'BillExpenseLine',
		'BillItemLine',
		'BillPaymentCheck',
		'BillPaymentCreditCard',
		'PurchaseOrder',
		'PurchaseOrderLine',
		// Employees & Payroll.
		'Employee',
		'TimeTracking',
		// Items & Inventory.
		'ItemInventory',
		'ItemNonInventory',
		'ItemService',
		'ItemOtherCharge',
		'ItemDiscount',
		'ItemPayment',
		'ItemFixedAsset',
		'ItemGroup',
		'ItemSalesTax',
		'ItemSalesTaxGroup',
		'ItemSubtotal',
		// Company & Lists.
		'Account',
		'Class',
		'Terms',
		'CustomerType',
		'VendorType',
		'JobType',
		'ShipMethod',
		'PaymentMethod',
		'SalesRep',
		'PriceLevel',
		'SalesTaxCode',
		// General Transactions.
		'Check',
		'Deposit',
		'JournalEntry',
		'Transfer',
		'CreditCardCharge',
		'CreditCardCredit',
		'ItemReceipt',
		// Company info.
		'Company',
	);

	/**
	 * Entities that support write operations (create/update) through QODBC.
	 *
	 * @var string[]
	 */
	const WRITABLE_ENTITIES = array(
		'Customer',
		'Invoice',
		'Vendor',
		'Bill',
		'Estimate',
		'SalesReceipt',
		'PurchaseOrder',
		'Employee',
		'ItemInventory',
		'ItemNonInventory',
		'ItemService',
		'Check',
		'JournalEntry',
		'CreditMemo',
		'ReceivePayment',
		'TimeTracking',
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'quickbooks_desktop_sync';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'QuickBooks Desktop Sync', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Syncs data with QuickBooks Desktop through a QODBC relay API. Supports querying customers, invoices, vendors, items, employees, and other QuickBooks Desktop entities. Can also create and update records when the relay is configured with a read-write QODBC license. Requires a Remote Sites connection of type "quickbooks_desktop" pointing to the PHP relay endpoint.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the QuickBooks Desktop relay.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'enum'        => array(
						'query',
						'list_tables',
						'get_customers',
						'get_invoices',
						'get_items',
						'get_vendors',
						'get_employees',
						'get_accounts',
						'create_record',
						'update_record',
						'sync_status',
					),
					'default'     => 'query',
					'description' => __(
						'Action to perform. Use "list_tables" to discover available tables, "query" for custom SQL against any QODBC table, or a shortcut like "get_customers" for common entities. "create_record" and "update_record" require a write-enabled QODBC license on the relay.',
						'mcp-ai-wpoos-pro'
					),
				),
				'entity'        => array(
					'type'        => 'string',
					'description' => __( 'QODBC table/entity name for query, create_record, or update_record actions (e.g. Customer, Invoice, ItemInventory).', 'mcp-ai-wpoos-pro' ),
				),
				'fields'        => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated list of columns to retrieve. Use * for all columns. Defaults to * when omitted.', 'mcp-ai-wpoos-pro' ),
				),
				'where'         => array(
					'type'        => 'string',
					'description' => __( 'SQL WHERE clause without the WHERE keyword (e.g. "IsActive = 1 AND Balance > 0"). Parameterised by the relay.', 'mcp-ai-wpoos-pro' ),
				),
				'order_by'      => array(
					'type'        => 'string',
					'description' => __( 'Column name to sort results by (e.g. "Name", "TxnDate DESC").', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_ROWS,
					'default'     => 50,
					'description' => __( 'Maximum number of rows to return (1–500, default 50).', 'mcp-ai-wpoos-pro' ),
				),
				'record_data'   => array(
					'type'        => 'object',
					'description' => __( 'Key-value pairs of column names and values for create_record or update_record actions.', 'mcp-ai-wpoos-pro' ),
				),
				'record_id'     => array(
					'type'        => 'string',
					'description' => __( 'The ListID or TxnID of the record to update (required for update_record).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'connection_id', 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// ── Permission check ──────────────────────────────────────────────
		$user_id             = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$required_capability = apply_filters( 'wp_mcp_ai_quickbooks_desktop_required_capability', 'manage_options', $context );

		if ( ! $user_id || ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_forbidden',
				__( 'You do not have permission to access QuickBooks Desktop data.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// ── Resolve connection ────────────────────────────────────────────
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $connection_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_connection',
				__( 'A connection_id is required. Use list_connections on the remote_wp_connection tool to discover available connections.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_no_manager',
				__( 'Remote Sites Manager is not available. Please ensure the Pro addon is active.', 'mcp-ai-wpoos-pro' )
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_connection_not_found',
				__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['connection_type'] ) || 'quickbooks_desktop' !== $connection['connection_type'] ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_wrong_type',
				__( 'This connection is not a QuickBooks Desktop connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_disabled',
				__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// ── Extract connection credentials ────────────────────────────────
		$relay_url = ! empty( $connection['url'] ) ? esc_url_raw( trim( (string) $connection['url'] ) ) : '';
		$api_key   = '';
		$dsn_name  = ! empty( $connection['dsn_name'] ) ? sanitize_text_field( trim( (string) $connection['dsn_name'] ) ) : '';

		if ( ! empty( $connection['api_key'] ) ) {
			$api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		}

		if ( empty( $relay_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_url',
				__( 'The QODBC relay URL is not configured for this connection.', 'mcp-ai-wpoos-pro' )
			);
		}

		// ── Route to action handler ───────────────────────────────────────
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'query';

		switch ( $action ) {
			case 'list_tables':
				return $this->handle_list_tables( $relay_url, $api_key, $dsn_name );

			case 'query':
				return $this->handle_query( $relay_url, $api_key, $dsn_name, $arguments );

			case 'get_customers':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'Customer', $arguments );

			case 'get_invoices':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'Invoice', $arguments );

			case 'get_items':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'ItemInventory', $arguments );

			case 'get_vendors':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'Vendor', $arguments );

			case 'get_employees':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'Employee', $arguments );

			case 'get_accounts':
				return $this->handle_shortcut( $relay_url, $api_key, $dsn_name, 'Account', $arguments );

			case 'create_record':
				return $this->handle_create( $relay_url, $api_key, $dsn_name, $arguments );

			case 'update_record':
				return $this->handle_update( $relay_url, $api_key, $dsn_name, $arguments );

			case 'sync_status':
				return $this->handle_sync_status( $relay_url, $api_key, $dsn_name );

			default:
				return new WP_Error(
					'wp_mcp_ai_qbd_invalid_action',
					sprintf(
						/* translators: %s: action name. */
						__( 'Invalid action "%s". Supported actions: query, list_tables, get_customers, get_invoices, get_items, get_vendors, get_employees, get_accounts, create_record, update_record, sync_status.', 'mcp-ai-wpoos-pro' ),
						esc_html( $action )
					)
				);
		}
	}

	// ─── Action Handlers ─────────────────────────────────────────────────

	/**
	 * List available QODBC tables on the relay.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @return array|WP_Error
	 */
	protected function handle_list_tables( $relay_url, $api_key, $dsn_name ) {
		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'  => 'list_tables',
				'dsn_name' => $dsn_name,
			)
		);
	}

	/**
	 * Execute a custom query against a QODBC entity.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_query( $relay_url, $api_key, $dsn_name, array $arguments ) {
		$entity = isset( $arguments['entity'] ) ? sanitize_text_field( $arguments['entity'] ) : '';

		if ( empty( $entity ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_entity',
				__( 'The "entity" parameter is required for query actions. Use list_tables to discover available entities.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_allowed_entity( $entity ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_invalid_entity',
				sprintf(
					/* translators: %s: entity name. */
					__( 'Entity "%s" is not in the allowed list. Use list_tables to discover available entities.', 'mcp-ai-wpoos-pro' ),
					esc_html( $entity )
				)
			);
		}

		$fields   = isset( $arguments['fields'] ) ? sanitize_text_field( $arguments['fields'] ) : '*';
		$where    = isset( $arguments['where'] ) ? wp_strip_all_tags( $arguments['where'] ) : '';
		$order_by = isset( $arguments['order_by'] ) ? sanitize_text_field( $arguments['order_by'] ) : '';
		$limit    = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), self::MAX_ROWS ) : 50;

		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'  => 'query',
				'dsn_name' => $dsn_name,
				'entity'   => $entity,
				'fields'   => $fields,
				'where'    => $where,
				'order_by' => $order_by,
				'limit'    => $limit,
			)
		);
	}

	/**
	 * Shortcut handler for common entity queries.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @param string $entity    Default entity name for the shortcut.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_shortcut( $relay_url, $api_key, $dsn_name, $entity, array $arguments ) {
		// Allow overriding entity via arguments for flexibility.
		if ( ! empty( $arguments['entity'] ) ) {
			$entity = sanitize_text_field( $arguments['entity'] );
		}

		$fields   = isset( $arguments['fields'] ) ? sanitize_text_field( $arguments['fields'] ) : '*';
		$where    = isset( $arguments['where'] ) ? wp_strip_all_tags( $arguments['where'] ) : '';
		$order_by = isset( $arguments['order_by'] ) ? sanitize_text_field( $arguments['order_by'] ) : '';
		$limit    = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), self::MAX_ROWS ) : 50;

		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'  => 'query',
				'dsn_name' => $dsn_name,
				'entity'   => $entity,
				'fields'   => $fields,
				'where'    => $where,
				'order_by' => $order_by,
				'limit'    => $limit,
			)
		);
	}

	/**
	 * Create a new record in QuickBooks Desktop.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_create( $relay_url, $api_key, $dsn_name, array $arguments ) {
		$entity = isset( $arguments['entity'] ) ? sanitize_text_field( $arguments['entity'] ) : '';

		if ( empty( $entity ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_entity',
				__( 'The "entity" parameter is required for create_record.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! in_array( $entity, self::WRITABLE_ENTITIES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_not_writable',
				sprintf(
					/* translators: %s: entity name. */
					__( 'Entity "%s" does not support write operations.', 'mcp-ai-wpoos-pro' ),
					esc_html( $entity )
				)
			);
		}

		$record_data = isset( $arguments['record_data'] ) && is_array( $arguments['record_data'] )
			? $this->sanitize_record_data( $arguments['record_data'] )
			: array();

		if ( empty( $record_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_record_data',
				__( 'The "record_data" parameter with column key-value pairs is required for create_record.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'     => 'create',
				'dsn_name'    => $dsn_name,
				'entity'      => $entity,
				'record_data' => $record_data,
			)
		);
	}

	/**
	 * Update an existing record in QuickBooks Desktop.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @param array  $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_update( $relay_url, $api_key, $dsn_name, array $arguments ) {
		$entity = isset( $arguments['entity'] ) ? sanitize_text_field( $arguments['entity'] ) : '';

		if ( empty( $entity ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_entity',
				__( 'The "entity" parameter is required for update_record.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! in_array( $entity, self::WRITABLE_ENTITIES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_not_writable',
				sprintf(
					/* translators: %s: entity name. */
					__( 'Entity "%s" does not support write operations.', 'mcp-ai-wpoos-pro' ),
					esc_html( $entity )
				)
			);
		}

		$record_id = isset( $arguments['record_id'] ) ? sanitize_text_field( $arguments['record_id'] ) : '';

		if ( empty( $record_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_record_id',
				__( 'The "record_id" (ListID or TxnID) is required for update_record.', 'mcp-ai-wpoos-pro' )
			);
		}

		$record_data = isset( $arguments['record_data'] ) && is_array( $arguments['record_data'] )
			? $this->sanitize_record_data( $arguments['record_data'] )
			: array();

		if ( empty( $record_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_missing_record_data',
				__( 'The "record_data" parameter with column key-value pairs is required for update_record.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'     => 'update',
				'dsn_name'    => $dsn_name,
				'entity'      => $entity,
				'record_id'   => $record_id,
				'record_data' => $record_data,
			)
		);
	}

	/**
	 * Check the sync/connectivity status of the QODBC relay.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key for authentication.
	 * @param string $dsn_name  ODBC DSN name.
	 * @return array|WP_Error
	 */
	protected function handle_sync_status( $relay_url, $api_key, $dsn_name ) {
		return $this->relay_request(
			$relay_url,
			$api_key,
			array(
				'command'  => 'sync_status',
				'dsn_name' => $dsn_name,
			)
		);
	}

	// ─── Internal Helpers ────────────────────────────────────────────────

	/**
	 * Send a request to the QODBC relay API.
	 *
	 * @param string $relay_url Relay endpoint URL.
	 * @param string $api_key   API key / shared secret for the relay.
	 * @param array  $payload   JSON-serialisable command payload.
	 * @return array|WP_Error Parsed relay response or error.
	 */
	protected function relay_request( $relay_url, $api_key, array $payload ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$timeout  = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : self::DEFAULT_TIMEOUT;

		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		/**
		 * Filter the relay request payload before sending.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $payload   Command payload.
		 * @param string $relay_url Relay URL.
		 */
		$payload = apply_filters( 'wp_mcp_ai_qbd_relay_payload', $payload, $relay_url );

		$response = wp_remote_post(
			$relay_url,
			array(
				'timeout' => $timeout,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'QuickBooks Desktop relay request failed.',
				array( 'error' => $response->get_error_message() )
			);

			return new WP_Error(
				'wp_mcp_ai_qbd_http_error',
				__( 'The request to the QODBC relay failed. Please verify the relay URL is reachable.', 'mcp-ai-wpoos-pro' ),
				$response
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			WP_MCP_AI_Admin_Settings::log(
				'QuickBooks Desktop relay returned unexpected status.',
				array( 'status' => $status_code )
			);

			return new WP_Error(
				'wp_mcp_ai_qbd_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The QODBC relay returned HTTP status %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body       = wp_remote_retrieve_body( $response );
		$decoded    = json_decode( $body, true );
		$json_error = json_last_error();

		if ( JSON_ERROR_NONE !== $json_error ) {
			WP_MCP_AI_Admin_Settings::log(
				'QuickBooks Desktop relay returned invalid JSON.',
				array( 'body' => $body )
			);

			return new WP_Error(
				'wp_mcp_ai_qbd_invalid_json',
				__( 'The QODBC relay returned an invalid JSON response.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check for relay-level error envelope.
		if ( ! empty( $decoded['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_qbd_relay_error',
				sprintf(
					/* translators: %s: relay error message. */
					__( 'QODBC relay error: %s', 'mcp-ai-wpoos-pro' ),
					sanitize_text_field( $decoded['error'] )
				),
				$decoded
			);
		}

		return $this->sanitize_response_payload( $decoded );
	}

	/**
	 * Check whether a given entity name is in the allowed list.
	 *
	 * @param string $entity Entity name to check.
	 * @return bool
	 */
	protected function is_allowed_entity( $entity ) {
		/**
		 * Filter the list of allowed QODBC entities.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $entities Default allowed entity list.
		 */
		$allowed = apply_filters( 'wp_mcp_ai_qbd_allowed_entities', self::ALLOWED_ENTITIES );

		return in_array( $entity, $allowed, true );
	}

	/**
	 * Sanitize record data before sending to the relay.
	 *
	 * @param array $data Key-value pairs of column names and values.
	 * @return array Sanitized data.
	 */
	protected function sanitize_record_data( array $data ) {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$clean_key = sanitize_text_field( (string) $key );

			if ( empty( $clean_key ) ) {
				continue;
			}

			if ( is_string( $value ) ) {
				$sanitized[ $clean_key ] = sanitize_textarea_field( $value );
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $clean_key ] = $value;
			} elseif ( is_null( $value ) ) {
				$sanitized[ $clean_key ] = null;
			}
			// Skip non-scalar values for safety.
		}

		return $sanitized;
	}

	/**
	 * Recursively sanitise the relay response so it is safe for AI consumption.
	 *
	 * @param mixed $data Response data.
	 * @return mixed Sanitized data.
	 */
	protected function sanitize_response_payload( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();

			foreach ( $data as $key => $value ) {
				$sanitized_key               = is_string( $key ) ? sanitize_text_field( $key ) : $key;
				$sanitized[ $sanitized_key ] = $this->sanitize_response_payload( $value );
			}

			return $sanitized;
		}

		if ( is_scalar( $data ) ) {
			if ( is_string( $data ) ) {
				return sanitize_text_field( $data );
			}

			return $data;
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'external-api',
			'requires-credentials',
			'network-dependent',
			'requires-capability',
		);
	}
}
