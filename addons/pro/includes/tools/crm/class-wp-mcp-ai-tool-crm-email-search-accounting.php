<?php
/**
 * Tool for searching accounting and service-tracking emails via CRM contact records.
 *
 * Implements industry-standard accounting email categorisation (invoices, payments,
 * quotes, reminders, disputes), billing-status filtering, compliance audit metadata,
 * and scheduled cache refresh via WP Cron.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Email Search – Accounting & Service Tracking Tool.
 *
 * Searches CRM contacts for accounting and service-related email correspondence
 * following enterprise CRM and accounting-software integration standards:
 *
 * - Transaction types: invoice, payment, quote, reminder, dispute, statement, contract
 * - Billing/payment status: pending, overdue, paid, disputed, voided, refunded
 * - Invoice amount range filtering
 * - Compliance-ready audit metadata (data_retention_flag, audit_trail)
 * - Integration hints for QuickBooks Online, Xero, FreshBooks
 * - Results cached (WP_MCP_AI_Cache_Helper) and auto-refreshed via WP Cron
 *
 * Industry references: AccountingSuite CRM Classification, Freshsales/Zoho Accounting
 * Integration, Monday.com CRM-with-Invoicing, Microsoft Dynamics 365 Finance.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Tool_CRM_Email_Search_Accounting implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * WP Cron hook for scheduled cache refresh.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_crm_email_search_accounting_refresh';

	/**
	 * Cache key prefix for accounting search results.
	 *
	 * @var string
	 */
	const CACHE_KEY_PREFIX = 'crm_accounting_search_';

	/**
	 * Default cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Allowed transaction type values.
	 *
	 * @var string[]
	 */
	const TRANSACTION_TYPES = array( 'invoice', 'payment', 'quote', 'reminder', 'dispute', 'statement', 'contract', 'all' );

	/**
	 * Allowed billing/payment status values.
	 *
	 * @var string[]
	 */
	const BILLING_STATUSES = array( 'pending', 'overdue', 'paid', 'disputed', 'voided', 'refunded', 'all' );

	/**
	 * Allowed service category values.
	 *
	 * @var string[]
	 */
	const SERVICE_CATEGORIES = array( 'accounting', 'bookkeeping', 'tax', 'payroll', 'audit', 'advisory', 'consulting', 'all' );

	/**
	 * Allowed cron schedule recurrences.
	 *
	 * @var string[]
	 */
	const CRON_SCHEDULES = array( 'hourly', 'twicedaily', 'daily' );

	/**
	 * Constructor – registers WP Cron callback.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_search' ) );
	}

	/**
	 * Determine whether this tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The CRM Email Search (Accounting) tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'crm_email_search_accounting';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'CRM Email Search: Accounting & Service Tracking', 'mcp-ai-wpoos-pro' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Search CRM contacts for accounting and service-tracking emails. Supports industry-standard transaction types (invoice, payment, quote, reminder, dispute), billing-status filtering, invoice-amount ranges, service categories, and compliance audit metadata. Results are cached for efficient throughout-the-day querying and can be auto-refreshed on a WP Cron schedule.', 'mcp-ai-wpoos-pro' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'              => array(
					'type'        => 'string',
					'enum'        => array( 'search', 'get_cached', 'clear_cache', 'schedule', 'unschedule' ),
					'description' => __( 'Action: search (execute and cache), get_cached (return cached results), clear_cache (invalidate cache), schedule (register WP Cron refresh), unschedule (remove schedule).', 'mcp-ai-wpoos-pro' ),
					'default'     => 'search',
				),
				'transaction_type'    => array(
					'type'        => 'string',
					'enum'        => self::TRANSACTION_TYPES,
					'description' => __( 'Type of accounting transaction to search for. invoice = unpaid invoices; payment = received payments; quote = open quotes/estimates; reminder = overdue payment reminders; dispute = billing disputes; statement = periodic statements; contract = service agreements.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'billing_status'      => array(
					'type'        => 'string',
					'enum'        => self::BILLING_STATUSES,
					'description' => __( 'Filter by billing/payment status. Industry-standard values: pending, overdue, paid, disputed, voided, refunded.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'service_category'    => array(
					'type'        => 'string',
					'enum'        => self::SERVICE_CATEGORIES,
					'description' => __( 'Filter by professional service category: accounting, bookkeeping, tax, payroll, audit, advisory, consulting.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'all',
				),
				'invoice_amount_min'  => array(
					'type'        => 'number',
					'description' => __( 'Minimum invoice/transaction amount (inclusive). Use with invoice_amount_max for a range.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'invoice_amount_max'  => array(
					'type'        => 'number',
					'description' => __( 'Maximum invoice/transaction amount (inclusive).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'days_overdue_min'    => array(
					'type'        => 'integer',
					'description' => __( 'Only return records overdue by at least this many days. Requires billing_status=overdue or transaction_type=reminder.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'email_domain'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by contact email domain (e.g. "clientcorp.com").', 'mcp-ai-wpoos-pro' ),
				),
				'date_from'           => array(
					'type'        => 'string',
					'description' => __( 'Include transactions issued on or after this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'             => array(
					'type'        => 'string',
					'description' => __( 'Include transactions issued on or before this date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'include_audit_meta'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true, include compliance audit metadata (data_retention_flag, audit_trail_available, accounting_platform_hint) in each result.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'per_page'            => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (1–100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'page'                => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'cache_ttl'           => array(
					'type'        => 'integer',
					'description' => __( 'Cache lifetime in seconds (minimum 60, default 3600).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 60,
					'default'     => HOUR_IN_SECONDS,
				),
				'schedule'            => array(
					'type'        => 'string',
					'enum'        => self::CRON_SCHEDULES,
					'description' => __( 'WP Cron recurrence for automatic cache refresh (hourly, twicedaily, daily). Used with action=schedule.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'hourly',
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function requires_base_pro() {
		return true;
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
		);
	}

	/**
	 * Get extended tool definition (toolkit metadata).
	 *
	 * @since 2.1.0
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'crm',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'accountant', 'finance_manager', 'billing_specialist', 'account_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_tool_unavailable', self::get_unavailable_reason() );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to search CRM accounting records.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';

		switch ( $action ) {
			case 'search':
				return $this->run_search( $arguments );
			case 'get_cached':
				return $this->get_cached_results( $arguments );
			case 'clear_cache':
				return $this->clear_cached_results( $arguments );
			case 'schedule':
				return $this->schedule_search( $arguments );
			case 'unschedule':
				return $this->unschedule_search();
			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid action. Must be one of: search, get_cached, clear_cache, schedule, unschedule.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Execute search, cache results, and return them.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function run_search( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$cache_ttl = isset( $arguments['cache_ttl'] ) ? max( 60, absint( $arguments['cache_ttl'] ) ) : self::DEFAULT_CACHE_TTL;

		$results = $this->query_accounting( $filters );

		$this->cache_set( $cache_key, $results, $cache_ttl );

		$results['cached']    = true;
		$results['cache_ttl'] = $cache_ttl;
		$results['cached_at'] = current_time( 'mysql' );
		$results['cache_key'] = $cache_key;

		return $results;
	}

	/**
	 * Return previously cached results without re-querying.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function get_cached_results( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$cached    = $this->cache_get( $cache_key );

		if ( false === $cached ) {
			return array(
				'success'  => false,
				'message'  => __( 'No cached results found. Run action=search first to populate the cache.', 'mcp-ai-wpoos-pro' ),
				'records'  => array(),
				'total'    => 0,
			);
		}

		$cached['from_cache'] = true;
		return $cached;
	}

	/**
	 * Invalidate the cache for the given filter set.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function clear_cached_results( array $arguments ) {
		$filters   = $this->extract_filters( $arguments );
		$cache_key = $this->build_cache_key( $filters );
		$this->cache_delete( $cache_key );

		return array(
			'success' => true,
			'message' => __( 'Accounting search cache cleared successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Register a WP Cron event to auto-refresh the cached accounting search.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	private function schedule_search( array $arguments ) {
		$recurrence = isset( $arguments['schedule'] ) ? sanitize_key( $arguments['schedule'] ) : 'hourly';

		if ( ! in_array( $recurrence, self::CRON_SCHEDULES, true ) ) {
			$recurrence = 'hourly';
		}

		$filters = $this->extract_filters( $arguments );
		update_option( 'wp_mcp_ai_crm_accounting_search_params', $filters, false );

		$existing = wp_next_scheduled( self::CRON_HOOK );
		if ( $existing ) {
			wp_unschedule_event( $existing, self::CRON_HOOK );
		}

		$result = wp_schedule_event( time(), $recurrence, self::CRON_HOOK );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success'    => true,
			/* translators: %s: cron recurrence label */
			'message'    => sprintf( __( 'Accounting search scheduled to auto-refresh %s.', 'mcp-ai-wpoos-pro' ), $recurrence ),
			'recurrence' => $recurrence,
			'hook'       => self::CRON_HOOK,
			'next_run'   => wp_next_scheduled( self::CRON_HOOK ),
		);
	}

	/**
	 * Remove the scheduled WP Cron refresh.
	 *
	 * @return array
	 */
	private function unschedule_search() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}

		delete_option( 'wp_mcp_ai_crm_accounting_search_params' );

		return array(
			'success' => true,
			'message' => __( 'Accounting search schedule removed.', 'mcp-ai-wpoos-pro' ),
		);
	}

	// -------------------------------------------------------------------------
	// Cron callback
	// -------------------------------------------------------------------------

	/**
	 * WP Cron callback – refresh cached accounting search results.
	 *
	 * @return void
	 */
	public function run_scheduled_search() {
		$filters = get_option( 'wp_mcp_ai_crm_accounting_search_params', array() );
		if ( empty( $filters ) ) {
			return;
		}

		$cache_key = $this->build_cache_key( $filters );
		$results   = $this->query_accounting( $filters );

		$results['cached']    = true;
		$results['cached_at'] = current_time( 'mysql' );
		$results['cache_key'] = $cache_key;

		$this->cache_set( $cache_key, $results, self::DEFAULT_CACHE_TTL );

		/**
		 * Fires after a scheduled CRM accounting search cache refresh completes.
		 *
		 * @since 2.1.0
		 *
		 * @param array $results Refreshed results.
		 * @param array $filters Filters used for the query.
		 */
		do_action( 'wp_mcp_ai_crm_accounting_search_refreshed', $results, $filters );
	}

	// -------------------------------------------------------------------------
	// Core query
	// -------------------------------------------------------------------------

	/**
	 * Query CRM contacts for accounting and service-tracking records.
	 *
	 * Applies transaction type, billing status, service category, invoice-amount range,
	 * days-overdue minimum, email-domain, and date filters. Optionally enriches results
	 * with compliance audit metadata.
	 *
	 * @param array $filters Sanitised filter parameters.
	 * @return array
	 */
	private function query_accounting( array $filters ) {
		$per_page           = min( max( absint( $filters['per_page'] ), 1 ), 100 );
		$page               = max( absint( $filters['page'] ), 1 );
		$include_audit_meta = ! empty( $filters['include_audit_meta'] );

		$query_args = array(
			'post_type'      => 'mcp_crm_contacts',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array( 'relation' => 'AND' );

		// Transaction type filter.
		if ( ! empty( $filters['transaction_type'] ) && 'all' !== $filters['transaction_type'] ) {
			$meta_query[] = array(
				'key'     => 'accounting_transaction_type',
				'value'   => sanitize_key( $filters['transaction_type'] ),
				'compare' => '=',
			);
		}

		// Billing / payment status filter.
		if ( ! empty( $filters['billing_status'] ) && 'all' !== $filters['billing_status'] ) {
			$meta_query[] = array(
				'key'     => 'billing_status',
				'value'   => sanitize_key( $filters['billing_status'] ),
				'compare' => '=',
			);
		}

		// Service category filter.
		if ( ! empty( $filters['service_category'] ) && 'all' !== $filters['service_category'] ) {
			$meta_query[] = array(
				'key'     => 'service_category',
				'value'   => sanitize_key( $filters['service_category'] ),
				'compare' => '=',
			);
		}

		// Invoice amount range.
		if ( isset( $filters['invoice_amount_min'] ) || isset( $filters['invoice_amount_max'] ) ) {
			$amount_clause = array(
				'key'  => 'invoice_amount',
				'type' => 'DECIMAL(10,2)',
			);
			if ( isset( $filters['invoice_amount_min'] ) && isset( $filters['invoice_amount_max'] ) ) {
				$amount_clause['value']   = array(
					(float) $filters['invoice_amount_min'],
					(float) $filters['invoice_amount_max'],
				);
				$amount_clause['compare'] = 'BETWEEN';
			} elseif ( isset( $filters['invoice_amount_min'] ) ) {
				$amount_clause['value']   = (float) $filters['invoice_amount_min'];
				$amount_clause['compare'] = '>=';
			} else {
				$amount_clause['value']   = (float) $filters['invoice_amount_max'];
				$amount_clause['compare'] = '<=';
			}
			$meta_query[] = $amount_clause;
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for CRM accounting filtering on indexed meta fields.
		}

		// Date range (transaction / email date).
		$date_query = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$date_query['after'] = sanitize_text_field( $filters['date_from'] );
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$date_query['before']    = sanitize_text_field( $filters['date_to'] );
			$date_query['inclusive'] = true;
		}
		if ( ! empty( $date_query ) ) {
			$query_args['date_query'] = array( $date_query );
		}

		$query   = new WP_Query( $query_args );
		$records = array();

		foreach ( $query->posts as $post ) {
			$email = (string) get_post_meta( $post->ID, 'email', true );

			// Email domain filter.
			if ( ! empty( $filters['email_domain'] ) ) {
				$domain = sanitize_text_field( $filters['email_domain'] );
				if ( false === strpos( $email, '@' . $domain ) ) {
					continue;
				}
			}

			$billing_status  = sanitize_key( (string) get_post_meta( $post->ID, 'billing_status', true ) );
			$due_date_raw    = (string) get_post_meta( $post->ID, 'invoice_due_date', true );
			$days_overdue    = $this->compute_days_overdue( $billing_status, $due_date_raw );

			// Days-overdue minimum filter.
			if ( isset( $filters['days_overdue_min'] ) && ( null === $days_overdue || $days_overdue < absint( $filters['days_overdue_min'] ) ) ) {
				continue;
			}

			$raw_amount      = get_post_meta( $post->ID, 'invoice_amount', true );
			$invoice_amount  = is_numeric( $raw_amount ) ? (float) $raw_amount : null;

			$record = array(
				'id'               => $post->ID,
				'name'             => $post->post_title,
				'email'            => sanitize_email( $email ),
				'first_name'       => sanitize_text_field( (string) get_post_meta( $post->ID, 'first_name', true ) ),
				'last_name'        => sanitize_text_field( (string) get_post_meta( $post->ID, 'last_name', true ) ),
				'company'          => sanitize_text_field( (string) get_post_meta( $post->ID, 'company', true ) ),
				'transaction_type' => sanitize_key( (string) get_post_meta( $post->ID, 'accounting_transaction_type', true ) ),
				'billing_status'   => $billing_status,
				'service_category' => sanitize_key( (string) get_post_meta( $post->ID, 'service_category', true ) ),
				'invoice_number'   => sanitize_text_field( (string) get_post_meta( $post->ID, 'invoice_number', true ) ),
				'invoice_amount'   => $invoice_amount,
				'invoice_due_date' => sanitize_text_field( $due_date_raw ),
				'days_overdue'     => $days_overdue,
				'currency'         => sanitize_text_field( (string) get_post_meta( $post->ID, 'invoice_currency', true ) ) ?: 'USD',
				'added_date'       => $post->post_date,
				'edit_url'         => get_edit_post_link( $post->ID, 'raw' ),
			);

			// Compliance audit metadata (industry standard: separate sensitive records).
			if ( $include_audit_meta ) {
				$record['audit_meta'] = $this->build_audit_meta( $post->ID, $billing_status );
			}

			$records[] = $record;
		}

		$response = array(
			'success'  => true,
			'records'  => $records,
			'total'    => $query->found_posts,
			'per_page' => $per_page,
			'page'     => $page,
			'pages'    => max( 1, $query->max_num_pages ),
			'filters'  => $filters,
			'summary'  => $this->build_summary( $records ),
		);

		return $response;
	}

	// -------------------------------------------------------------------------
	// Accounting helpers
	// -------------------------------------------------------------------------

	/**
	 * Compute days overdue for a contact record.
	 *
	 * @param string $billing_status Payment status slug.
	 * @param string $due_date_raw   MySQL/ISO date string of due date.
	 * @return int|null Days overdue (>= 0) or null if not overdue / no due date.
	 */
	private function compute_days_overdue( $billing_status, $due_date_raw ) {
		if ( in_array( $billing_status, array( 'paid', 'voided', 'refunded' ), true ) ) {
			return null;
		}

		if ( ! $due_date_raw ) {
			return null;
		}

		$due_ts  = strtotime( $due_date_raw );
		$now_ts  = current_time( 'timestamp', true );
		$seconds = $now_ts - $due_ts;

		if ( $seconds <= 0 ) {
			return null;
		}

		return (int) floor( $seconds / DAY_IN_SECONDS );
	}

	/**
	 * Build compliance audit metadata for a contact record.
	 *
	 * Industry requirement: accounting/billing emails must be kept separate from
	 * marketing communications and tagged with data-retention and audit flags.
	 *
	 * @param int    $post_id        Contact post ID.
	 * @param string $billing_status Billing/payment status slug.
	 * @return array
	 */
	private function build_audit_meta( $post_id, $billing_status ) {
		// Paid/completed records require standard 7-year data retention (US/GAAP).
		$retention_years = in_array( $billing_status, array( 'paid', 'voided', 'refunded' ), true ) ? 7 : 3;

		$accounting_platforms = array();
		if ( class_exists( 'WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report' ) ) {
			$accounting_platforms[] = 'QuickBooks Online';
		}

		// Check for Xero or FreshBooks integrations via filter.
		$accounting_platforms = apply_filters( 'wp_mcp_ai_crm_accounting_platforms', $accounting_platforms, $post_id );

		return array(
			'data_retention_years'       => $retention_years,
			'data_retention_flag'        => sprintf(
				/* translators: %d: number of years for data retention */
				__( 'Retain for %d years per accounting regulations', 'mcp-ai-wpoos-pro' ),
				$retention_years
			),
			'audit_trail_available'      => ! empty( get_post_meta( $post_id, 'audit_trail', true ) ),
			'accounting_platform_hints'  => ! empty( $accounting_platforms )
				? $accounting_platforms
				: array( __( 'No accounting integration detected', 'mcp-ai-wpoos-pro' ) ),
			'compliance_notes'           => __( 'Billing/accounting emails must be stored separately from marketing correspondence per industry data-governance standards.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build a financial summary across the returned records.
	 *
	 * Provides totals by billing status – useful for quick dashboard overviews
	 * (industry standard in accounting CRM reports).
	 *
	 * @param array $records Array of result records.
	 * @return array
	 */
	private function build_summary( array $records ) {
		$totals = array(
			'total_records'   => count( $records ),
			'total_amount'    => 0.0,
			'overdue_count'   => 0,
			'overdue_amount'  => 0.0,
			'pending_count'   => 0,
			'pending_amount'  => 0.0,
			'paid_count'      => 0,
			'paid_amount'     => 0.0,
			'disputed_count'  => 0,
			'disputed_amount' => 0.0,
		);

		foreach ( $records as $record ) {
			$amount  = is_numeric( $record['invoice_amount'] ) ? (float) $record['invoice_amount'] : 0.0;
			$status  = isset( $record['billing_status'] ) ? $record['billing_status'] : '';

			$totals['total_amount'] += $amount;

			switch ( $status ) {
				case 'overdue':
					++$totals['overdue_count'];
					$totals['overdue_amount'] += $amount;
					break;
				case 'pending':
					++$totals['pending_count'];
					$totals['pending_amount'] += $amount;
					break;
				case 'paid':
					++$totals['paid_count'];
					$totals['paid_amount'] += $amount;
					break;
				case 'disputed':
					++$totals['disputed_count'];
					$totals['disputed_amount'] += $amount;
					break;
			}
		}

		// Round to 2 decimal places for currency display.
		foreach ( array( 'total_amount', 'overdue_amount', 'pending_amount', 'paid_amount', 'disputed_amount' ) as $key ) {
			$totals[ $key ] = round( $totals[ $key ], 2 );
		}

		return $totals;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract and sanitise filter parameters from raw tool arguments.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array Sanitised filter set.
	 */
	private function extract_filters( array $arguments ) {
		$transaction_type = isset( $arguments['transaction_type'] ) ? sanitize_key( $arguments['transaction_type'] ) : 'all';
		if ( ! in_array( $transaction_type, self::TRANSACTION_TYPES, true ) ) {
			$transaction_type = 'all';
		}

		$billing_status = isset( $arguments['billing_status'] ) ? sanitize_key( $arguments['billing_status'] ) : 'all';
		if ( ! in_array( $billing_status, self::BILLING_STATUSES, true ) ) {
			$billing_status = 'all';
		}

		$service_category = isset( $arguments['service_category'] ) ? sanitize_key( $arguments['service_category'] ) : 'all';
		if ( ! in_array( $service_category, self::SERVICE_CATEGORIES, true ) ) {
			$service_category = 'all';
		}

		$filters = array(
			'transaction_type'   => $transaction_type,
			'billing_status'     => $billing_status,
			'service_category'   => $service_category,
			'email_domain'       => isset( $arguments['email_domain'] ) ? sanitize_text_field( $arguments['email_domain'] ) : '',
			'date_from'          => isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '',
			'date_to'            => isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '',
			'include_audit_meta' => isset( $arguments['include_audit_meta'] ) ? (bool) $arguments['include_audit_meta'] : false,
			'per_page'           => isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20,
			'page'               => isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1,
		);

		if ( isset( $arguments['invoice_amount_min'] ) ) {
			$filters['invoice_amount_min'] = max( 0.0, (float) $arguments['invoice_amount_min'] );
		}
		if ( isset( $arguments['invoice_amount_max'] ) ) {
			$filters['invoice_amount_max'] = max( 0.0, (float) $arguments['invoice_amount_max'] );
		}
		if ( isset( $arguments['days_overdue_min'] ) ) {
			$filters['days_overdue_min'] = absint( $arguments['days_overdue_min'] );
		}

		return $filters;
	}

	/**
	 * Build a stable, deterministic cache key from a filter set.
	 *
	 * @param array $filters Sanitised filters.
	 * @return string
	 */
	private function build_cache_key( array $filters ) {
		ksort( $filters );
		return self::CACHE_KEY_PREFIX . md5( (string) wp_json_encode( $filters ) );
	}

	/**
	 * Store a value in the plugin cache (Cache_Helper → transient fallback).
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $value      Data to cache.
	 * @param int    $expiration TTL in seconds.
	 * @return void
	 */
	private function cache_set( $key, $value, $expiration ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::set( $key, $value, $expiration );
		} else {
			set_transient( 'wp_mcp_ai_' . $key, $value, $expiration );
		}
	}

	/**
	 * Retrieve a cached value (Cache_Helper → transient fallback).
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	private function cache_get( $key ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			return WP_MCP_AI_Cache_Helper::get( $key );
		}
		return get_transient( 'wp_mcp_ai_' . $key );
	}

	/**
	 * Delete a cached value (Cache_Helper → transient fallback).
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	private function cache_delete( $key ) {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
			WP_MCP_AI_Cache_Helper::delete( $key );
		} else {
			delete_transient( 'wp_mcp_ai_' . $key );
		}
	}
}
