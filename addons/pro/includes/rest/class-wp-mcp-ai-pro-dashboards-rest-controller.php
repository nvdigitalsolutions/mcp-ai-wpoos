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
 *
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
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
			// 158 D1 — Member metrics. `health` and `vitals` were one dashboard in
			// two pieces: identical filters, identical structure, differing only in
			// columns and in which toolkit toggle reveals them. The sections below
			// keep those toggles EXACTLY (a section is visible when its own flag is
			// on) while retiring the second surface.
			//
			// A merged dashboard declares `sections` rather than `metrics`/`lists`;
			// get_schema() expands the enabled ones, namespacing each key by its
			// section so two connectors can both have an `in_stock`.
			'members'       => array(
				'label'       => __( 'Member metrics', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Health and vital-sign history per member. Each section follows its own toolkit toggle.', 'mcp-ai-wpoos-pro' ),
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
				'sections'    => array(
					'health' => array(
						'label'        => __( 'Health & Wellness', 'mcp-ai-wpoos-pro' ),
						'flag'         => 'enable_health_wellness_management',
						// The provider's own default when the filter is absent. Each section
						// keeps its pre-merge default, so the merged page cannot silently
						// change the window a user sees.
						'default_days' => 30,
						'metrics'      => array(),
						'lists'        => array(
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
					'vitals' => array(
						'label'         => __( 'Medical Vitals', 'mcp-ai-wpoos-pro' ),
						'flag'          => 'enable_medical_vitals',
						// BC, unchanged by the merge: the dedicated toggle wins when it was
						// saved, otherwise this section follows Health & Wellness, mirroring
						// includes/tools/healthcare/init.php.
						'fallback_flag' => 'enable_health_wellness_management',
						'default_days'  => 90,
						'metrics'       => array(),
						'lists'         => array(
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
				),
			),

			// 158 D1 — Connector health. `ezuite` and `flowhub` published a
			// byte-identical render spec and differed only in data source; `shopify`
			// shared the framing and added its GraphQL cost budget. One surface with
			// one section per connector, keys namespaced by connector, so enabling
			// two of them cannot collide on `in_stock`.
			'connectors'    => array(
				'label'       => __( 'Connector health', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Inventory stock buckets, sync freshness and GraphQL budget, one section per connector.', 'mcp-ai-wpoos-pro' ),
				'icon'        => '🏭',
				'filters'     => array(),
				'sections'    => array(
					'ezuite'  => array(
						'label'   => __( 'EZuite', 'mcp-ai-wpoos-pro' ),
						'flag'    => 'enable_ezuite_toolkit',
						'metrics' => array(
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
						'lists'   => array(
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
					'flowhub' => array(
						'label'   => __( 'FlowHub', 'mcp-ai-wpoos-pro' ),
						'flag'    => 'enable_flowhub_toolkit',
						'metrics' => array(
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
						'lists'   => array(
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
					'shopify' => array(
						'label'   => __( 'Shopify', 'mcp-ai-wpoos-pro' ),
						'flag'    => 'enable_shopify_sync_toolkit',
						'metrics' => array(
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
						'lists'   => array(),
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
	 * Dashboard ids retired by the 10 → 7 merge (158 D1), mapped to the
	 * surface that replaced them.
	 *
	 * Published in the schema envelope so a client can migrate its own saved
	 * state, and consulted by get_data() so an old link resolves instead of
	 * 404ing. A deletion alone is the one part of a merge a user notices.
	 *
	 * @since 2.4.6
	 * @return array<string, string> Retired id => surviving id.
	 */
	public static function aliases() {
		return array(
			'health'  => 'members',
			'vitals'  => 'members',
			'ezuite'  => 'connectors',
			'shopify' => 'connectors',
			'flowhub' => 'connectors',
		);
	}

	/**
	 * Resolve a retired dashboard id to the surface that replaced it.
	 *
	 * Idempotent for a live id, and for an id that never existed — the caller
	 * still performs its own unknown-id check, and doing it here would turn a
	 * 400 into a 404 for input that is simply wrong.
	 *
	 * @since 2.4.6
	 * @param string $id Dashboard key, current or retired.
	 * @return string
	 */
	public static function resolve_alias( $id ) {
		$aliases = self::aliases();
		return isset( $aliases[ $id ] ) ? $aliases[ $id ] : $id;
	}

	/**
	 * The settings flags that gate each dashboard, as a list.
	 *
	 * A merged dashboard is gated by ANY of its sections, so the value is a
	 * list rather than the single key this map used to hold. Dashboards not
	 * in the map stay ungated (observability).
	 *
	 * @since 2.4.6
	 * @return array<string, string[]>
	 */
	public static function dashboard_flags() {
		return array(
			'cre'          => array( 'enable_cre_debt_toolkit' ),
			'law'          => array( 'enable_law_firm_toolkit' ),
			'registration' => array( 'enable_regulatory_registration_toolkit' ),
			'eca'          => array( 'enable_eca_management' ),
			'members'      => array( 'enable_health_wellness_management', 'enable_medical_vitals' ),
			'connectors'   => array( 'enable_ezuite_toolkit', 'enable_shopify_sync_toolkit', 'enable_flowhub_toolkit' ),
		);
	}

	/**
	 * Settings flag that gates each dashboard (the toolkit enable toggles
	 * from Settings → NV oOS), first-of-list.
	 *
	 * Kept for BC: the disabled-guidance payload has always carried one flag
	 * per dashboard. A merged dashboard answers with the first flag that would
	 * enable it, and {@see self::effective_flag_keys()} answers with all of
	 * them for a caller that can render more than one.
	 *
	 * @since 2.4.0
	 * @return array<string, string> Dashboard id => settings flag key.
	 */
	public static function dashboard_flag_keys() {
		$keys = array();
		foreach ( self::dashboard_flags() as $id => $flags ) {
			$keys[ $id ] = (string) reset( $flags );
		}
		return $keys;
	}

	/**
	 * Every settings flag that would enable a dashboard.
	 *
	 * Alias-resolved, so `vitals` answers with `members`' candidates. Medical
	 * Vitals' BC rule is applied: when the dedicated toggle was never saved,
	 * that section follows Health & Wellness, so the candidate list names the
	 * key that actually decides it rather than one that does not exist.
	 *
	 * Unknown ids return an empty array — the callers treat them as ungated.
	 *
	 * @since 2.4.6
	 * @param string $id Dashboard key, current or retired.
	 * @return string[]
	 */
	public static function effective_flag_keys( $id ) {
		$id     = self::resolve_alias( $id );
		$flags  = self::dashboard_flags();
		$result = isset( $flags[ $id ] ) ? $flags[ $id ] : array();

		return array_values(
			array_unique( array_map( array( __CLASS__, 'effective_flag_key_for' ), $result ) )
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
	 * @param string $id Dashboard key, current or retired.
	 * @return string Settings flag key, or '' when ungated.
	 */
	public static function effective_flag_key( $id ) {
		$flags = self::effective_flag_keys( $id );
		return isset( $flags[0] ) ? $flags[0] : '';
	}

	/**
	 * Apply the Medical Vitals BC fallback to one candidate flag key.
	 *
	 * The dedicated toggle wins when it was ever saved; otherwise the section
	 * follows Health & Wellness. `array_key_exists`, not `empty`: a saved `0`
	 * is a decision and must not read as "never configured".
	 *
	 * @since 2.4.6
	 * @param string $flag_key Candidate flag key.
	 * @return string
	 */
	private static function effective_flag_key_for( $flag_key ) {
		if ( 'enable_medical_vitals' !== $flag_key ) {
			return $flag_key;
		}

		$settings = self::settings();

		return array_key_exists( 'enable_medical_vitals', $settings )
			? 'enable_medical_vitals'
			: 'enable_health_wellness_management';
	}

	/**
	 * Whether one section of a dashboard is visible.
	 *
	 * A section is visible when its own flag is on. Medical Vitals applies the
	 * BC fallback, so enabling Health & Wellness alone still reveals the vital
	 * signs section — the behaviour the two separate dashboards had.
	 *
	 * An unknown section, or a dashboard with no sections, falls back to the
	 * whole-dashboard gate rather than answering `false`: a typo in a section
	 * key should not silently hide data.
	 *
	 * @since 2.4.6
	 * @param string $dashboard_id Dashboard key, current or retired.
	 * @param string $section      Section key inside that dashboard.
	 * @return bool
	 */
	public static function section_enabled( $dashboard_id, $section ) {
		$catalogue = ( new self() )->dashboard_catalogue();
		$id        = self::resolve_alias( $dashboard_id );

		if ( ! isset( $catalogue[ $id ]['sections'][ $section ]['flag'] ) ) {
			return self::is_dashboard_available( $id );
		}

		$settings = self::settings();
		$flag_key = (string) $catalogue[ $id ]['sections'][ $section ]['flag'];

		// The Medical Vitals BC fallback, in the same direction as
		// effective_flag_key_for(): a saved toggle wins, an absent one defers.
		if ( 'enable_medical_vitals' === $flag_key && ! array_key_exists( 'enable_medical_vitals', $settings ) ) {
			$flag_key = 'enable_health_wellness_management';
		}

		return ! empty( $settings[ $flag_key ] );
	}

	/**
	 * The site's NV oOS settings, always as an array.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a dashboard's toolkit is enabled for this site.
	 *
	 * Unknown ids are treated as available — the callers perform their own
	 * existence checks. A merged dashboard is available when ANY of its
	 * sections is, which is what keeps enabling one connector from hiding the
	 * other two.
	 *
	 * @since 2.4.0
	 * @param string $id Dashboard key, current or retired.
	 * @return bool
	 */
	public static function is_dashboard_available( $id ) {
		$id        = self::resolve_alias( $id );
		$catalogue = ( new self() )->dashboard_catalogue();
		$settings  = self::settings();

		if ( isset( $catalogue[ $id ]['sections'] ) ) {
			foreach ( array_keys( $catalogue[ $id ]['sections'] ) as $section ) {
				if ( self::section_enabled( $id, $section ) ) {
					return true;
				}
			}

			return false;
		}

		foreach ( self::effective_flag_keys( $id ) as $flag_key ) {
			if ( ! empty( $settings[ $flag_key ] ) ) {
				return true;
			}
		}

		// No candidates at all means ungated.
		return empty( self::effective_flag_keys( $id ) );
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
	 * A merged dashboard declares `sections` instead of `metrics`/`lists`;
	 * {@see self::expand_sections()} turns the visible ones into the flat
	 * render contract the client already understands. The `sections` key is
	 * dropped from the payload — how a dashboard is gated is our business, and
	 * leaking it invites a client to re-implement the gate.
	 *
	 * `aliases` publishes the retired ids (158 D1) so a client can migrate its
	 * own saved state instead of discovering the merge as a 404.
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
				'label'         => $config['label'],
				'description'   => $config['description'],
				'icon'          => $config['icon'],
				// The first flag, for the single-flag guidance the console
				// has always rendered.
				'toolkit_flag'  => self::effective_flag_key( $id ),
				// Every flag that would enable it — a merged dashboard has
				// several, and naming one of three is incomplete advice.
				'toolkit_flags' => self::effective_flag_keys( $id ),
			);
		}

		foreach ( $dashboards as $id => $config ) {
			$dashboards[ $id ] = self::expand_sections( $id, $config );

			foreach ( $dashboards[ $id ]['filters'] as $index => $filter ) {
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
				// 158 D1 — the retired ids and what replaced them. Additive;
				// a client that ignores it keeps working.
				'aliases'    => self::aliases(),
			)
		);
	}

	/**
	 * Turn a merged dashboard's visible sections into the flat render contract.
	 *
	 * A merged dashboard declares `sections`, each with its own flag, and the
	 * sections that are not visible are simply absent from the payload — which
	 * is exactly the "section visibility driven by the toolkit flags" 158 D1
	 * asks for, expressed in the contract the client already renders.
	 *
	 * Keys are namespaced by section (`ezuite_in_stock`, `vitals_history`) for
	 * two reasons: two connectors both publish `in_stock`, and a flat list of
	 * metrics cannot carry two entries with one key. `group` carries the
	 * section's label so a renderer can head each block; a renderer that
	 * ignores it still gets distinct, labelled metrics.
	 *
	 * A dashboard with no sections is returned unchanged.
	 *
	 * @since 2.4.6
	 * @param string               $id     Dashboard key.
	 * @param array<string, mixed> $config Catalogue entry.
	 * @return array<string, mixed>
	 */
	private static function expand_sections( $id, array $config ) {
		if ( empty( $config['sections'] ) || ! is_array( $config['sections'] ) ) {
			return $config;
		}

		$metrics = array();
		$lists   = array();
		$charts  = array();

		foreach ( $config['sections'] as $section => $spec ) {
			if ( ! self::section_enabled( $id, $section ) ) {
				continue;
			}

			$group = isset( $spec['label'] ) ? (string) $spec['label'] : $section;

			foreach ( (array) ( $spec['metrics'] ?? array() ) as $metric ) {
				$metric['key']   = $section . '_' . $metric['key'];
				$metric['label'] = sprintf(
					/* translators: 1: connector or section name, 2: metric label. */
					__( '%1$s — %2$s', 'mcp-ai-wpoos-pro' ),
					$group,
					$metric['label']
				);
				$metric['group'] = $group;

				$metrics[] = $metric;
			}

			foreach ( (array) ( $spec['lists'] ?? array() ) as $list ) {
				$list['key']   = $section . '_' . $list['key'];
				$list['title'] = sprintf(
					/* translators: 1: connector or section name, 2: list title. */
					__( '%1$s — %2$s', 'mcp-ai-wpoos-pro' ),
					$group,
					$list['title']
				);
				$list['group'] = $group;

				$lists[] = $list;
			}

			foreach ( (array) ( $spec['charts'] ?? array() ) as $chart ) {
				$chart['key']   = $section . '_' . $chart['key'];
				$chart['group'] = $group;

				$charts[] = $chart;
			}
		}

		unset( $config['sections'] );

		$config['metrics'] = $metrics;
		$config['lists']   = $lists;
		$config['charts']  = $charts;

		return $config;
	}

	/**
	 * GET /dashboards/{id}/data — filtered metric payload from the
	 * dashboard's own gatherer.
	 *
	 * A retired id resolves through {@see self::aliases()} before anything else,
	 * and the response reports the **resolved** id: a client that asked for
	 * `ezuite` is told it received `connectors`, which is the one piece of
	 * information it needs to update its own state. An alias whose surface is
	 * also unavailable still answers 404 — resolving is not a grant.
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_data( WP_REST_Request $request ) {
		$dashboard_id = self::resolve_alias( (string) $request->get_param( 'id' ) );
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

		if ( 'members' === $dashboard_id ) {
			return $this->gather_members( $filters );
		}

		if ( 'connectors' === $dashboard_id ) {
			return $this->gather_connectors();
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

	/**
	 * Member metrics — the health and vital-signs sections, each gated by its
	 * own toolkit toggle (158 D1).
	 *
	 * Both providers return a single `history` list and refuse with a 400 when
	 * no member was chosen, so the merged payload namespaces the lists and lets
	 * that 400 through unchanged: an empty member filter is still a request for
	 * one member, not a request for all of them.
	 *
	 * `days_back` is one control for both sections. When the caller sends none,
	 * each section keeps its pre-merge default (30 for health, 90 for vitals) so
	 * the merge cannot silently change the window a reader was used to.
	 *
	 * @since 2.4.6
	 * @param array<string, string> $filters Whitelisted filter values.
	 * @return array<string, mixed>|WP_Error
	 */
	private function gather_members( array $filters ) {
		$member_id = isset( $filters['member_id'] ) ? absint( $filters['member_id'] ) : 0;
		$requested = isset( $filters['days_back'] ) ? absint( $filters['days_back'] ) : 0;

		$providers = array(
			'health' => array(
				'file'         => WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-health-wellness-dashboard-page.php',
				'class'        => 'WP_MCP_AI_Health_Wellness_Dashboard_Page',
				'default_days' => 30,
			),
			'vitals' => array(
				'file'         => WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-medical-vitals-dashboard-page.php',
				'class'        => 'WP_MCP_AI_Medical_Vitals_Dashboard_Page',
				'default_days' => 90,
			),
		);

		$data = array();

		foreach ( $providers as $section => $provider ) {
			if ( ! self::section_enabled( 'members', $section ) ) {
				continue;
			}

			$history = $this->call_provider_seam( $section, $provider, 'get_history', $member_id, $requested > 0 ? $requested : $provider['default_days'] );

			if ( is_wp_error( $history ) ) {
				return $history;
			}

			$data[ $section . '_history' ] = isset( $history['history'] ) && is_array( $history['history'] )
				? array_values( $history['history'] )
				: array();
		}

		return $data;
	}

	/**
	 * Connector health — the inventory and commerce connectors, one section per
	 * connector, keys namespaced by connector (158 D1).
	 *
	 * `ezuite` and `flowhub` published a byte-identical payload and `shopify`
	 * shared the framing, which is why they are one surface. They still publish
	 * the same keys, which is why the keys are namespaced: two connectors both
	 * have `in_stock`, and a flat payload cannot hold both.
	 *
	 * A connector whose provider is missing (its CCT manager class is not
	 * loaded) is **degraded, not fatal**: this page is called connector
	 * *health*, so one broken connector is a thing to show, not a reason to
	 * hide the other two. The failure is recorded as `<section>_error` and the
	 * other sections still load. Any other error propagates.
	 *
	 * @since 2.4.6
	 * @return array<string, mixed>
	 */
	private function gather_connectors() {
		$providers = array(
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

		$data = array();

		foreach ( $providers as $section => $provider ) {
			if ( ! self::section_enabled( 'connectors', $section ) ) {
				continue;
			}

			$payload = $this->call_provider_seam( $section, $provider, 'get_dashboard_data' );

			if ( is_wp_error( $payload ) ) {
				$status = $payload->get_error_data();
				$status = is_array( $status ) && isset( $status['status'] ) ? (int) $status['status'] : 0;

				// 501 is "this connector cannot answer here", which is a health
				// signal. Anything else is a real failure and belongs upstream.
				if ( 501 !== $status ) {
					return $payload;
				}

				$data[ $section . '_error' ] = $payload->get_error_message();

				continue;
			}

			if ( ! is_array( $payload ) ) {
				continue;
			}

			foreach ( $payload as $key => $value ) {
				$data[ $section . '_' . $key ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Load a provider class and call one of its public seams.
	 *
	 * The wp-admin page and widget classes are the authority for these numbers —
	 * `gather()` has always called them rather than re-implementing a query — and
	 * they are autoloaded lazily because a class that is never enabled should
	 * never be loaded.
	 *
	 * @since 2.4.6
	 * @param string               $section  Section key, for the error message.
	 * @param array<string, mixed> $provider Provider descriptor (`file`, `class`).
	 * @param string               $method   Static method to call.
	 * @param mixed                ...$args  Arguments for the method.
	 * @return mixed|WP_Error
	 */
	private function call_provider_seam( $section, array $provider, $method, ...$args ) {
		if ( ! class_exists( $provider['class'] ) && file_exists( $provider['file'] ) ) {
			require_once $provider['file'];
		}

		if ( ! class_exists( $provider['class'] ) ) {
			return new WP_Error(
				'dashboards_provider_unavailable',
				sprintf(
					/* translators: %s: dashboard section name. */
					__( 'The %s provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
					$section
				),
				array( 'status' => 501 )
			);
		}

		return call_user_func_array( array( $provider['class'], $method ), $args );
	}
}
