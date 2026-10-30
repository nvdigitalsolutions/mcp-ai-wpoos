<?php
/**
 * REST controller for the console dashboards feature (Proposal 084
 * Phase 4, W13 — the repeating-dashboard port, CRE pilot).
 *
 * Serves per-dashboard configs (filters + metric cards) and the
 * dashboard data:
 *   GET /mcp-ai-pro/v1/dashboards/schema     — dashboard catalogue
 *   GET /mcp-ai-pro/v1/dashboards/{id}/data  — filtered metric payload
 *
 * Data providers are the wp-admin dashboard pages' own gatherers
 * (promoted public seams) — the controller never re-implements a query.
 * W13a ships the CRE Debt Portfolio pilot; W13c adds the remaining
 * repeating dashboards (ECA, health, vitals, registration, law-firm).
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboards REST controller.
 */
class WP_MCP_AI_Pro_Dashboards_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/dashboards';

	/**
	 * Boot the controller (idempotent).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the schema + data routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/schema',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_schema' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/(?P<id>[a-z0-9_-]+)/data',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_data' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'filters' => array(
						'type'              => 'object',
						'default'           => array(),
						'sanitize_callback' => array( __CLASS__, 'sanitize_filters' ),
					),
				),
			)
		);
	}

	/**
	 * Permission check — dashboards are administrator-only console
	 * surfaces.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Sanitize the dashboards `filters` argument.
	 *
	 * The console SPA sends filters as a JSON-encoded query string
	 * (`?filters={"loan_status":"performing"}`). The REST pipeline runs
	 * this callback before type validation, so the string must become an
	 * associative array here or the request dies with rest_invalid_type.
	 * Arrays pass through untouched (array-style query params and direct
	 * handler calls). Anything else — including JSON that does not decode
	 * to an object — degrades to an empty array.
	 *
	 * @since 2.4.1
	 *
	 * @param mixed           $value   Raw `filters` value.
	 * @param WP_REST_Request $request Unused (REST callback signature).
	 * @param string          $param   Unused (REST callback signature).
	 * @return array Decoded associative filters, or array() on failure.
	 */
	public static function sanitize_filters( $value, $request = null, $param = '' ) {
		unset( $request, $param );

		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value );
			if ( $decoded instanceof stdClass ) {
				return get_object_vars( $decoded );
			}
		}

		return array();
	}

	/**
	 * The dashboard catalogue (W13a: CRE portfolio pilot).
	 *
	 * @since 2.4.0
	 * @return array<string, array<string, mixed>>
	 */
	private function dashboard_catalogue() {
		return array(
			'cre'           => array(
				'label'       => __( 'CRE Debt Portfolio', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Commercial real-estate loan portfolio: balances, averages and distributions with loan-type and status filters.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🏦',
				'filters'     => array(
					array(
						'key'      => 'loan_type',
						'label'    => __( 'Loan Type', 'mcp-ai-wpoos-pro' ),
						'type'     => 'taxonomy',
						'taxonomy' => 'mcp_ai_cre_loan_type',
					),
					array(
						'key'     => 'loan_status',
						'label'   => __( 'Loan Status', 'mcp-ai-wpoos-pro' ),
						'type'    => 'select',
						'options' => array(
							''                => __( 'All Statuses', 'mcp-ai-wpoos-pro' ),
							'performing'      => __( 'Performing', 'mcp-ai-wpoos-pro' ),
							'watchlist'       => __( 'Watchlist', 'mcp-ai-wpoos-pro' ),
							'special_service' => __( 'Special Servicing', 'mcp-ai-wpoos-pro' ),
							'delinquent_30'   => __( 'Delinquent 30+', 'mcp-ai-wpoos-pro' ),
							'delinquent_60'   => __( 'Delinquent 60+', 'mcp-ai-wpoos-pro' ),
							'delinquent_90'   => __( 'Delinquent 90+', 'mcp-ai-wpoos-pro' ),
							'foreclosure'     => __( 'Foreclosure', 'mcp-ai-wpoos-pro' ),
							'reo'             => __( 'REO', 'mcp-ai-wpoos-pro' ),
							'paid_off'        => __( 'Paid Off', 'mcp-ai-wpoos-pro' ),
							'defeased'        => __( 'Defeased', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'metrics'     => array(
					array(
						'key'    => 'total_loans',
						'label'  => __( 'Loans', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_balance',
						'label'  => __( 'Total Balance', 'mcp-ai-wpoos-pro' ),
						'format' => 'money',
					),
					array(
						'key'    => 'avg_rate',
						'label'  => __( 'Avg Rate', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'avg_dscr',
						'label'  => __( 'Avg DSCR', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'avg_ltv',
						'label'  => __( 'Avg LTV', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'avg_debt_yield',
						'label'  => __( 'Avg Debt Yield', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
				),
				'charts'      => array(
					array(
						'key'   => 'by_loan_type',
						'title' => __( 'By Loan Type', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
					array(
						'key'   => 'by_status',
						'title' => __( 'By Status', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
					array(
						'key'   => 'by_property_type',
						'title' => __( 'By Property Type', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
					array(
						'key'   => 'maturity_schedule',
						'title' => __( 'Maturity Schedule', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
				),
				'lists'       => array(
					array(
						'key'     => 'loan_list',
						'title'   => __( 'Loan summary', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'title',
								'label' => __( 'Loan', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'    => 'balance',
								'label'  => __( 'Balance', 'mcp-ai-wpoos-pro' ),
								'format' => 'money',
							),
							array(
								'key'   => 'type',
								'label' => __( 'Type', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'status',
								'label' => __( 'Status', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'law'           => array(
				'label'       => __( 'Law Firm', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Active matters, clients, time entries and trust transactions.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '⚖️',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'active_matters',
						'label'  => __( 'Active Matters', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_clients',
						'label'  => __( 'Clients', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_time_entries',
						'label'  => __( 'Time Entries', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_trust_txns',
						'label'  => __( 'Trust Transactions', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
				),
			),
			'registration'  => array(
				'label'       => __( 'Registration', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Product registrations by status and country.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '📋',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'total_products',
						'label'  => __( 'Products', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_registrations',
						'label'  => __( 'Registrations', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'approved',
						'label'  => __( 'Approved', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'under_review',
						'label'  => __( 'Under Review', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'draft',
						'label'  => __( 'Drafts', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'expiring_soon',
						'label'  => __( 'Expiring Soon', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
				),
				'lists'       => array(
					array(
						'key'     => 'countries',
						'title'   => __( 'By Country', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'name',
								'label' => __( 'Country', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'authority',
								'label' => __( 'Authority', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'total',
								'label' => __( 'Total', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'approved',
								'label' => __( 'Approved', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'pending',
								'label' => __( 'Pending', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'eca'           => array(
				'label'       => __( 'ECA', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Extra-curricular activities: enrollments, capacity, participation and attendance.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🏫',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'total_ecas',
						'label'  => __( 'Activities', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_students',
						'label'  => __( 'Students', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_enrollments',
						'label'  => __( 'Enrollments', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'participation_rate',
						'label'  => __( 'Participation', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'attendance_rate',
						'label'  => __( 'Attendance', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'capacity_rate',
						'label'  => __( 'Capacity Utilisation', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'total_waitlisted',
						'label'  => __( 'Waitlisted', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
				),
				'charts'      => array(
					array(
						'key'   => 'categories',
						'title' => __( 'By Category', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
					array(
						'key'   => 'types',
						'title' => __( 'By Type', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
				),
				'lists'       => array(
					array(
						'key'     => 'ecas',
						'title'   => __( 'Activities', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'title',
								'label' => __( 'Activity', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'type',
								'label' => __( 'Type', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'day',
								'label' => __( 'Day', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'venue',
								'label' => __( 'Venue', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'enrolled',
								'label' => __( 'Enrolled', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'status',
								'label' => __( 'Status', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'health'        => array(
				'label'       => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Daily health metrics history per member (steps, water, sleep, calories, mood).', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🫀',
				'filters'     => array(
					array(
						'key'       => 'member_id',
						'label'     => __( 'Member', 'mcp-ai-wpoos-pro' ),
						'type'      => 'member',
						'post_type' => 'mcp_ai_member',
					),
					array(
						'key'     => 'days_back',
						'label'   => __( 'Date Range', 'mcp-ai-wpoos-pro' ),
						'type'    => 'select',
						'options' => array(
							'7'  => __( 'Last 7 days', 'mcp-ai-wpoos-pro' ),
							'30' => __( 'Last 30 days', 'mcp-ai-wpoos-pro' ),
							'90' => __( 'Last 90 days', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'metrics'     => array(),
				'lists'       => array(
					array(
						'key'     => 'history',
						'title'   => __( 'Metrics history', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'date',
								'label' => __( 'Date', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'steps',
								'label' => __( 'Steps', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'water',
								'label' => __( 'Water', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'sleep',
								'label' => __( 'Sleep', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'calories',
								'label' => __( 'Calories', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'mood',
								'label' => __( 'Mood', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'vitals'        => array(
				'label'       => __( 'Medical Vitals', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Vital signs history per member (heart rate, temperature, blood pressure, weight).', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🩺',
				'filters'     => array(
					array(
						'key'       => 'member_id',
						'label'     => __( 'Member', 'mcp-ai-wpoos-pro' ),
						'type'      => 'member',
						'post_type' => 'mcp_ai_member',
					),
					array(
						'key'     => 'days_back',
						'label'   => __( 'Date Range', 'mcp-ai-wpoos-pro' ),
						'type'    => 'select',
						'options' => array(
							'7'  => __( 'Last 7 days', 'mcp-ai-wpoos-pro' ),
							'30' => __( 'Last 30 days', 'mcp-ai-wpoos-pro' ),
							'90' => __( 'Last 90 days', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'metrics'     => array(),
				'lists'       => array(
					array(
						'key'     => 'history',
						'title'   => __( 'Vital signs history', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'timestamp',
								'label' => __( 'Recorded', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'heart_rate',
								'label' => __( 'Heart Rate', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'temperature',
								'label' => __( 'Temperature', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'blood_pressure_systolic',
								'label' => __( 'BP Systolic', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'blood_pressure_diastolic',
								'label' => __( 'BP Diastolic', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'weight',
								'label' => __( 'Weight', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			// Proposal 089 Phase 4f — the commerce toolkit dashboards
			// (formerly wp-admin dashboard widgets; same promoted seams).
			'ezuite'        => array(
				'label'       => __( 'EZuite Inventory', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'EZuite ERP inventory health: stock buckets, cached rows, total value and top warehouses.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🏭',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'in_stock',
						'label'  => __( 'In Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'low_stock',
						'label'  => __( 'Low Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'out_of_stock',
						'label'  => __( 'Out of Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_items',
						'label'  => __( 'Cached Items', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_value',
						'label'  => __( 'Total Value', 'mcp-ai-wpoos-pro' ),
						'format' => 'money',
					),
				),
				'lists'       => array(
					array(
						'key'     => 'warehouses',
						'title'   => __( 'Top Warehouses', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'name',
								'label' => __( 'Warehouse', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'count',
								'label' => __( 'Items', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			'shopify'       => array(
				'label'       => __( 'Shopify Sync', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Shopify GraphQL cost budget, cached items and sync health per connection.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🛍️',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'cost_remaining_pct',
						'label'  => __( 'Cost Budget', 'mcp-ai-wpoos-pro' ),
						'format' => 'percent',
					),
					array(
						'key'    => 'cost_used',
						'label'  => __( 'Points Used', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'cost_limit',
						'label'  => __( 'Point Limit', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'cached_items',
						'label'  => __( 'Cached Items', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'connections',
						'label'  => __( 'Connections', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
				),
			),
			'flowhub'       => array(
				'label'       => __( 'FlowHub Inventory', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'FlowHub POS inventory health: stock buckets, cached rows, total value and top locations. Read-mostly — compliance reporting stays in FlowHub.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🌿',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'in_stock',
						'label'  => __( 'In Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'low_stock',
						'label'  => __( 'Low Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'out_of_stock',
						'label'  => __( 'Out of Stock', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_items',
						'label'  => __( 'Cached Items', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'total_value',
						'label'  => __( 'Total Value', 'mcp-ai-wpoos-pro' ),
						'format' => 'money',
					),
				),
				'lists'       => array(
					array(
						'key'     => 'locations',
						'title'   => __( 'Top Locations', 'mcp-ai-wpoos-pro' ),
						'columns' => array(
							array(
								'key'   => 'name',
								'label' => __( 'Location', 'mcp-ai-wpoos-pro' ),
							),
							array(
								'key'   => 'count',
								'label' => __( 'Items', 'mcp-ai-wpoos-pro' ),
							),
						),
					),
				),
			),
			// Proposal 091 Track E — AI observability (annotations + recent
			// GenAI spans). Ungated: core observability, not a toolkit.
			'observability' => array(
				'label'       => __( 'AI Observability', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Chat annotation totals and recent GenAI span counts from the audit activity log.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '📡',
				'filters'     => array(),
				'metrics'     => array(
					array(
						'key'    => 'annotations_total',
						'label'  => __( 'Annotations', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'annotations_positive',
						'label'  => __( 'Positive', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'annotations_negative',
						'label'  => __( 'Negative', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'transcripts_annotated',
						'label'  => __( 'Transcripts Annotated', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'spans_chat',
						'label'  => __( 'Chat Spans (recent)', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
					array(
						'key'    => 'spans_tool',
						'label'  => __( 'Tool Spans (recent)', 'mcp-ai-wpoos-pro' ),
						'format' => 'number',
					),
				),
				'charts'      => array(
					array(
						'key'   => 'by_rating',
						'title' => __( 'Annotations by Rating', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
					array(
						'key'   => 'spans_by_operation',
						'title' => __( 'Spans by Operation (recent)', 'mcp-ai-wpoos-pro' ),
						'type'  => 'bar',
					),
				),
				'lists'       => array(),
			),
		);
	}

	/**
	 * Settings flag that gates each dashboard (the toolkit enable toggles
	 * from Settings → NV oOS). Dashboards not in this map stay ungated.
	 *
	 * @since 2.4.0
	 * @return array<string, string> Dashboard id => settings flag key.
	 */
	public static function dashboard_flag_keys() {
		return array(
			'cre'          => 'enable_cre_debt_toolkit',
			'law'          => 'enable_law_firm_toolkit',
			'registration' => 'enable_regulatory_registration_toolkit',
			'eca'          => 'enable_eca_management',
			'health'       => 'enable_health_wellness_management',
			'vitals'       => 'enable_medical_vitals',
			// Proposal 089 Phase 4f — commerce toolkit dashboards.
			'ezuite'       => 'enable_ezuite_toolkit',
			'shopify'      => 'enable_shopify_sync_toolkit',
			'flowhub'      => 'enable_flowhub_toolkit',
		);
	}

	/**
	 * The effective settings flag that gates a dashboard.
	 *
	 * Unknown ids return an empty string — the callers treat them as
	 * ungated. Medical Vitals falls back to the Health & Wellness toggle
	 * for BC, mirroring includes/tools/healthcare/init.php.
	 *
	 * @since 2.4.2
	 * @param string $id Dashboard key.
	 * @return string Settings flag key, or '' when ungated.
	 */
	public static function effective_flag_key( $id ) {
		$flag_keys = self::dashboard_flag_keys();
		if ( ! isset( $flag_keys[ $id ] ) ) {
			return '';
		}

		$flag_key = $flag_keys[ $id ];

		// Medical Vitals mirrors includes/tools/healthcare/init.php: the
		// dedicated toggle wins when present, otherwise the dashboard
		// follows the Health & Wellness toggle for BC.
		if ( 'vitals' === $id ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}
			if ( ! array_key_exists( 'enable_medical_vitals', $settings ) ) {
				$flag_key = 'enable_health_wellness_management';
			}
		}

		return $flag_key;
	}

	/**
	 * Whether a dashboard's toolkit is enabled for this site.
	 *
	 * Unknown ids are treated as available — the callers perform their own
	 * existence checks. Medical Vitals falls back to the Health & Wellness
	 * toggle for BC, mirroring includes/tools/healthcare/init.php.
	 *
	 * @since 2.4.0
	 * @param string $id Dashboard key.
	 * @return bool
	 */
	public static function is_dashboard_available( $id ) {
		$flag_key = self::effective_flag_key( $id );
		if ( '' === $flag_key ) {
			return true;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return ! empty( $settings[ $flag_key ] );
	}

	/**
	 * GET /dashboards/schema — dashboard configs with resolved filter
	 * options (taxonomy terms fetched live).
	 *
	 * The envelope also carries a `disabled` map (label/icon/description +
	 * the settings flag that gates it) for dashboards whose toolkit is off,
	 * so the console can explain an empty catalogue instead of rendering a
	 * blank page when every toolkit is disabled (the default state).
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_schema( WP_REST_Request $request ) {
		unset( $request );

		// Only expose dashboards whose toolkit is enabled — the console
		// renders this catalogue verbatim, so disabled features must not
		// show up in the app (REST is the enforcement).
		$catalogue  = $this->dashboard_catalogue();
		$dashboards = array_filter(
			$catalogue,
			array( __CLASS__, 'is_dashboard_available' ),
			ARRAY_FILTER_USE_KEY
		);

		// Metadata-only entries for the dashboards that were filtered out.
		// The console renders these as guidance in its empty state.
		$disabled = array();
		foreach ( $catalogue as $id => $config ) {
			if ( isset( $dashboards[ $id ] ) ) {
				continue;
			}
			$disabled[ $id ] = array(
				'label'        => $config['label'],
				'description'  => $config['description'],
				'icon'         => $config['icon'],
				'toolkit_flag' => self::effective_flag_key( $id ),
			);
		}

		foreach ( $dashboards as $id => $config ) {
			foreach ( $config['filters'] as $index => $filter ) {
				if ( 'taxonomy' === $filter['type'] && taxonomy_exists( $filter['taxonomy'] ) ) {
					$terms   = get_terms(
						array(
							'taxonomy'   => $filter['taxonomy'],
							'hide_empty' => false,
						)
					);
					$options = array( '' => __( 'All', 'mcp-ai-wpoos-pro' ) );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$options[ sanitize_text_field( $term->name ) ] = sanitize_text_field( $term->name );
						}
					}
					$dashboards[ $id ]['filters'][ $index ]['options'] = $options;
				}

				if ( 'member' === $filter['type'] && ! empty( $filter['post_type'] ) && post_type_exists( $filter['post_type'] ) ) {
					$members = get_posts(
						array(
							'post_type'      => $filter['post_type'],
							'post_status'    => 'publish',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					$options = array( '' => __( '— Select a member —', 'mcp-ai-wpoos-pro' ) );
					foreach ( $members as $member ) {
						$options[ (string) $member->ID ] = get_the_title( $member );
					}
					$dashboards[ $id ]['filters'][ $index ]['options'] = $options;
				}
			}
			$dashboards[ $id ]['id'] = $id;
		}

		return rest_ensure_response(
			array(
				'dashboards' => $dashboards,
				'disabled'   => $disabled,
			)
		);
	}

	/**
	 * GET /dashboards/{id}/data — filtered metric payload from the
	 * dashboard's own gatherer.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_data( WP_REST_Request $request ) {
		$dashboard_id = $request->get_param( 'id' );
		$catalogue    = $this->dashboard_catalogue();

		if ( ! isset( $catalogue[ $dashboard_id ] ) ) {
			return new WP_Error(
				'dashboards_unknown',
				__( 'Unknown dashboard.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::is_dashboard_available( $dashboard_id ) ) {
			return new WP_Error(
				'dashboards_unavailable',
				__( 'This dashboard requires a toolkit that is not enabled on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Whitelist filter values against the dashboard's filter keys.
		// The console SPA may hand over a JSON string directly; decode it
		// defensively so handler-level calls behave like the REST pipeline.
		$raw_filters = $request->get_param( 'filters' );
		if ( is_string( $raw_filters ) ) {
			$raw_filters = self::sanitize_filters( $raw_filters );
		}
		$filters = array();
		if ( is_array( $raw_filters ) ) {
			foreach ( $catalogue[ $dashboard_id ]['filters'] as $filter ) {
				$key = $filter['key'];
				if ( isset( $raw_filters[ $key ] ) && is_string( $raw_filters[ $key ] ) ) {
					$filters[ $key ] = sanitize_text_field( $raw_filters[ $key ] );
				}
			}
		}

		$data = $this->gather( $dashboard_id, $filters );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return rest_ensure_response(
			array(
				'dashboard' => $dashboard_id,
				'data'      => $data,
			)
		);
	}

	/**
	 * Dispatch to the dashboard's own gatherer (public seams on the
	 * wp-admin pages — never a re-implemented query).
	 *
	 * @since 2.4.0
	 * @param string $dashboard_id Dashboard key.
	 * @param array  $filters      Whitelisted filter values.
	 * @return array|WP_Error
	 */
	private function gather( $dashboard_id, $filters ) {
		if ( 'cre' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cre-debt-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_CRE_Debt_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$loan_type   = isset( $filters['loan_type'] ) ? $filters['loan_type'] : '';
			$loan_status = isset( $filters['loan_status'] ) ? $filters['loan_status'] : '';

			return WP_MCP_AI_CRE_Debt_Dashboard_Page::get_portfolio_data( $loan_type, $loan_status );
		}

		if ( 'law' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-law-firm-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_Law_Firm_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Law_Firm_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			return WP_MCP_AI_Law_Firm_Dashboard_Page::get_dashboard_data();
		}

		if ( 'registration' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-registration-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_Registration_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Registration_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$stats     = WP_MCP_AI_Registration_Dashboard_Page::get_registration_stats();
			$countries = WP_MCP_AI_Registration_Dashboard_Page::get_countries_stats();

			return array_merge( $stats, array( 'countries' => $countries ) );
		}

		if ( 'eca' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-eca-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_ECA_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_ECA_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$data = WP_MCP_AI_ECA_Dashboard_Page::get_dashboard_data();
			$kpis = isset( $data['kpis'] ) && is_array( $data['kpis'] ) ? $data['kpis'] : array();

			return array_merge(
				$kpis,
				array(
					'categories' => isset( $data['categories'] ) ? $data['categories'] : array(),
					'types'      => isset( $data['types'] ) ? $data['types'] : array(),
					'ecas'       => isset( $data['ecas'] ) ? $data['ecas'] : array(),
				)
			);
		}

		if ( 'health' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-wellness-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Health_Wellness_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
			$days_back = isset( $filters['days_back'] ) ? absint( $filters['days_back'] ) : 30;

			return WP_MCP_AI_Health_Wellness_Dashboard_Page::get_history( $member_id, $days_back );
		}

		if ( 'vitals' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php';
			if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Medical_Vitals_Dashboard_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
			$days_back = isset( $filters['days_back'] ) ? absint( $filters['days_back'] ) : 90;

			return WP_MCP_AI_Medical_Vitals_Dashboard_Page::get_history( $member_id, $days_back );
		}

		// Proposal 089 Phase 4f — the commerce dashboard widgets (promoted
		// public seams on the widget classes; the wp-admin widgets render
		// the same payloads).
		$commerce_providers = array(
			'ezuite'  => array(
				'file'  => WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-ezuite-dashboard-widget.php',
				'class' => 'WP_MCP_AI_EZuite_Dashboard_Widget',
			),
			'shopify' => array(
				'file'  => WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-shopify-sync-dashboard-widget.php',
				'class' => 'WP_MCP_AI_Shopify_Sync_Dashboard_Widget',
			),
			'flowhub' => array(
				'file'  => WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-flowhub-dashboard-widget.php',
				'class' => 'WP_MCP_AI_FlowHub_Dashboard_Widget',
			),
		);
		if ( isset( $commerce_providers[ $dashboard_id ] ) ) {
			$provider = $commerce_providers[ $dashboard_id ];
			if ( ! class_exists( $provider['class'] ) && file_exists( $provider['file'] ) ) {
				require_once $provider['file'];
			}
			if ( ! class_exists( $provider['class'] ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			return call_user_func( array( $provider['class'], 'get_dashboard_data' ) );
		}

		// Proposal 091 Track E — AI observability dashboard (ungated).
		if ( 'observability' === $dashboard_id ) {
			$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-analytics-service-page.php';
			if ( ! class_exists( 'WP_MCP_AI_Analytics_Service_Page' ) && file_exists( $page_file ) ) {
				require_once $page_file;
			}
			if ( ! class_exists( 'WP_MCP_AI_Analytics_Service_Page' ) ) {
				return new WP_Error(
					'dashboards_provider_unavailable',
					__( 'The dashboard provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 501 )
				);
			}

			return WP_MCP_AI_Analytics_Service_Page::get_observability_data();
		}

		return new WP_Error(
			'dashboards_unknown',
			__( 'Unknown dashboard.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
	}
}
