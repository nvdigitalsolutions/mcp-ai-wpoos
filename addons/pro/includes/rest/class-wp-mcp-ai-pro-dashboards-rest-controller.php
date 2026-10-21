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
						'type'    => 'object',
						'default' => array(),
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
	 * The dashboard catalogue (W13a: CRE portfolio pilot).
	 *
	 * @since 2.4.0
	 * @return array<string, array<string, mixed>>
	 */
	private function dashboard_catalogue() {
		return array(
			'cre'          => array(
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
			'law'          => array(
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
			'registration' => array(
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
			'eca'          => array(
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
		);
	}

	/**
	 * GET /dashboards/schema — dashboard configs with resolved filter
	 * options (taxonomy terms fetched live).
	 *
	 * @since 2.4.0
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_schema( WP_REST_Request $request ) {
		unset( $request );

		$dashboards = $this->dashboard_catalogue();

		foreach ( $dashboards as $id => $config ) {
			foreach ( $config['filters'] as $index => $filter ) {
				if ( 'taxonomy' !== $filter['type'] || ! taxonomy_exists( $filter['taxonomy'] ) ) {
					continue;
				}
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
			$dashboards[ $id ]['id'] = $id;
		}

		return rest_ensure_response( array( 'dashboards' => $dashboards ) );
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

		// Whitelist filter values against the dashboard's filter keys.
		$raw_filters = $request->get_param( 'filters' );
		$filters     = array();
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

		return new WP_Error(
			'dashboards_unknown',
			__( 'Unknown dashboard.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
	}
}
