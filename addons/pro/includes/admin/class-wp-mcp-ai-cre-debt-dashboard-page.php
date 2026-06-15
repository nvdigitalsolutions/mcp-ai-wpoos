<?php
/**
 * CRE Debt & Securitization Portfolio Dashboard Page
 *
 * Provides an admin dashboard with Chart.js visualizations for the CRE Debt
 * loan portfolio, including composition charts, risk metric KPIs, maturity
 * schedules, and loan status breakdowns.
 *
 * Data is sourced from the CRE Loan CPT (mcp_ai_cre_loan) and linked
 * CRE Property CPT (mcp_ai_cre_property) post meta.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRE Debt Portfolio Dashboard Page
 */
class WP_MCP_AI_CRE_Debt_Dashboard_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'cre-debt-dashboard';

	/**
	 * Parent menu slug.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'edit.php?post_type=mcp_ai_cre_loan';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 24 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_cre_dashboard_filter', array( __CLASS__, 'ajax_filter_portfolio' ) );
	}

	/**
	 * Add submenu page under CRE Debt menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Portfolio Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue Chart.js and page-specific assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_cre_loan_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'wp-mcp-ai-chartjs',
			WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_add_inline_script(
			'wp-mcp-ai-chartjs',
			self::get_dashboard_js(),
			'after'
		);

		wp_add_inline_style(
			'wp-admin',
			self::get_dashboard_css()
		);

		// Load settings thresholds.
		$cre_settings = get_option( 'wp_mcp_ai_cre_debt_settings', array() );

		// Pass PHP-side data to JS (mirrors Health dashboard's wp_localize_script pattern).
		wp_localize_script(
			'wp-mcp-ai-chartjs',
			'wpMcpAiCreDashboard',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_cre_dashboard' ),
				'thresholds' => array(
					'min_dscr'        => isset( $cre_settings['default_dscr_minimum'] ) ? (float) $cre_settings['default_dscr_minimum'] : 1.25,
					'max_ltv'         => isset( $cre_settings['default_max_ltv'] ) ? (float) $cre_settings['default_max_ltv'] : 75,
					'min_debt_yield'  => isset( $cre_settings['default_min_debt_yield'] ) ? (float) $cre_settings['default_min_debt_yield'] : 9,
					'target_cap_rate' => isset( $cre_settings['default_cap_rate'] ) ? (float) $cre_settings['default_cap_rate'] : 6.5,
				),
				'strings'    => array(
					'loading'   => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'noData'    => __( 'No loans match the selected filters.', 'mcp-ai-wpoos-pro' ),
					'error'     => __( 'Failed to load data. Please try again.', 'mcp-ai-wpoos-pro' ),
					'allTypes'  => __( 'All Loan Types', 'mcp-ai-wpoos-pro' ),
					'allStatus' => __( 'All Statuses', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX: return filtered portfolio data.
	 *
	 * Mirrors the Health & Wellness dashboard AJAX pattern where a member/date
	 * selector triggers data reload. Here filters are loan_type and loan_status.
	 */
	public static function ajax_filter_portfolio() {
		check_ajax_referer( 'wp_mcp_ai_cre_dashboard', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$loan_type   = isset( $_POST['loan_type'] ) ? sanitize_text_field( wp_unslash( $_POST['loan_type'] ) ) : '';
		$loan_status = isset( $_POST['loan_status'] ) ? sanitize_text_field( wp_unslash( $_POST['loan_status'] ) ) : '';

		$portfolio = self::get_portfolio_data( $loan_type, $loan_status );
		wp_send_json_success( $portfolio );
	}

	/**
	 * Gather portfolio data from CPT posts.
	 *
	 * @param string $filter_loan_type   Optional loan type taxonomy filter.
	 * @param string $filter_loan_status Optional loan status meta filter.
	 * @return array Portfolio statistics.
	 */
	private static function get_portfolio_data( $filter_loan_type = '', $filter_loan_status = '' ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_cre_loan',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		// Taxonomy filter.
		if ( $filter_loan_type ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'mcp_ai_cre_loan_type',
					'field'    => 'name',
					'terms'    => $filter_loan_type,
				),
			);
		}

		// Meta filter.
		if ( $filter_loan_status ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_cre_loan_status',
					'value' => $filter_loan_status,
				),
			);
		}

		$loans = get_posts( $query_args );

		$data = array(
			'total_loans'       => count( $loans ),
			'total_balance'     => 0,
			'avg_rate'          => 0,
			'avg_dscr'          => 0,
			'avg_ltv'           => 0,
			'avg_debt_yield'    => 0,
			'by_status'         => array(),
			'by_loan_type'      => array(),
			'by_property_type'  => array(),
			'maturity_schedule' => array(),
			'rate_distribution' => array(),
			'loan_list'         => array(),
		);

		if ( empty( $loans ) ) {
			return $data;
		}

		$rates       = array();
		$dscrs       = array();
		$ltvs        = array();
		$debt_yields = array();
		$balances    = array();

		foreach ( $loans as $loan_id ) {
			$balance = (float) get_post_meta( $loan_id, '_cre_current_balance', true );
			if ( ! $balance ) {
				$balance = (float) get_post_meta( $loan_id, '_cre_loan_amount', true );
			}
			$data['total_balance'] += $balance;
			$balances[]             = $balance;

			// Rate.
			$rate = (float) get_post_meta( $loan_id, '_cre_interest_rate', true );
			if ( $rate > 0 ) {
				$rates[] = $rate;
			}

			// DSCR.
			$dscr = (float) get_post_meta( $loan_id, '_cre_dscr', true );
			if ( $dscr > 0 ) {
				$dscrs[] = $dscr;
			}

			// LTV.
			$ltv = (float) get_post_meta( $loan_id, '_cre_ltv', true );
			if ( $ltv > 0 ) {
				$ltvs[] = $ltv;
			}

			// Debt Yield.
			$dy = (float) get_post_meta( $loan_id, '_cre_debt_yield', true );
			if ( $dy > 0 ) {
				$debt_yields[] = $dy;
			}

			// Status.
			$status = get_post_meta( $loan_id, '_cre_loan_status', true );
			if ( ! $status ) {
				$status = 'performing';
			}
			if ( ! isset( $data['by_status'][ $status ] ) ) {
				$data['by_status'][ $status ] = 0;
			}
			++$data['by_status'][ $status ];

			// Loan Type (taxonomy).
			$types = wp_get_object_terms( $loan_id, 'mcp_ai_cre_loan_type', array( 'fields' => 'names' ) );
			$type  = ( ! empty( $types ) && ! is_wp_error( $types ) ) ? $types[0] : 'Unclassified';
			if ( ! isset( $data['by_loan_type'][ $type ] ) ) {
				$data['by_loan_type'][ $type ] = 0;
			}
			++$data['by_loan_type'][ $type ];

			// Property Type (via linked property).
			$prop_id    = absint( get_post_meta( $loan_id, '_cre_property_id', true ) );
			$prop_types = array();
			if ( $prop_id ) {
				$prop_types = wp_get_object_terms( $prop_id, 'mcp_ai_cre_prop_type', array( 'fields' => 'names' ) );
			}
			$ptype = ( ! empty( $prop_types ) && ! is_wp_error( $prop_types ) ) ? $prop_types[0] : 'Unknown';
			if ( ! isset( $data['by_property_type'][ $ptype ] ) ) {
				$data['by_property_type'][ $ptype ] = 0;
			}
			++$data['by_property_type'][ $ptype ];

			// Maturity schedule (bucket by year).
			$maturity = get_post_meta( $loan_id, '_cre_maturity_date', true );
			if ( $maturity ) {
				$parsed = date_parse( $maturity );
				$year   = ( $parsed && ! empty( $parsed['year'] ) && 0 === $parsed['error_count'] ) ? (string) $parsed['year'] : '';
				if ( $year ) {
					if ( ! isset( $data['maturity_schedule'][ $year ] ) ) {
						$data['maturity_schedule'][ $year ] = 0;
					}
					$data['maturity_schedule'][ $year ] += $balance;
				}
			}

			// Rate distribution bucket.
			if ( $rate > 0 ) {
				$bucket = floor( $rate ) . '-' . ( floor( $rate ) + 1 ) . '%';
				if ( ! isset( $data['rate_distribution'][ $bucket ] ) ) {
					$data['rate_distribution'][ $bucket ] = 0;
				}
				++$data['rate_distribution'][ $bucket ];
			}

			// Loan list entry for summary table (mirrors Health dashboard goals table).
			$data['loan_list'][] = array(
				'id'       => $loan_id,
				'title'    => get_the_title( $loan_id ),
				'balance'  => $balance,
				'rate'     => $rate,
				'dscr'     => $dscr,
				'ltv'      => $ltv,
				'dy'       => $dy,
				'status'   => $status,
				'type'     => $type,
				'maturity' => $maturity ? $maturity : '',
			);
		}

		// Averages.
		$data['avg_rate']       = ! empty( $rates ) ? round( array_sum( $rates ) / count( $rates ), 2 ) : 0;
		$data['avg_dscr']       = ! empty( $dscrs ) ? round( array_sum( $dscrs ) / count( $dscrs ), 2 ) : 0;
		$data['avg_ltv']        = ! empty( $ltvs ) ? round( array_sum( $ltvs ) / count( $ltvs ), 1 ) : 0;
		$data['avg_debt_yield'] = ! empty( $debt_yields ) ? round( array_sum( $debt_yields ) / count( $debt_yields ), 2 ) : 0;

		// Sort maturity schedule by year.
		ksort( $data['maturity_schedule'] );

		return $data;
	}

	/**
	 * Render the dashboard page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		$portfolio = self::get_portfolio_data();

		// Load thresholds from settings for status indicators (like Health dashboard goals).
		$cre_settings   = get_option( 'wp_mcp_ai_cre_debt_settings', array() );
		$min_dscr       = isset( $cre_settings['default_dscr_minimum'] ) ? (float) $cre_settings['default_dscr_minimum'] : 1.25;
		$max_ltv        = isset( $cre_settings['default_max_ltv'] ) ? (float) $cre_settings['default_max_ltv'] : 75;
		$min_debt_yield = isset( $cre_settings['default_min_debt_yield'] ) ? (float) $cre_settings['default_min_debt_yield'] : 9;

		// Get available loan types and statuses for the filter bar.
		$loan_type_terms = get_terms(
			array(
				'taxonomy'   => 'mcp_ai_cre_loan_type',
				'hide_empty' => false,
			)
		);
		$status_options  = array(
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
		);
		?>
		<div class="wrap cre-dash-page">
			<h1><?php esc_html_e( 'CRE Debt Portfolio Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Filter Bar (mirrors Health dashboard member selector + date range) -->
			<div class="cre-dash-filter-bar">
				<label for="cre-dash-type-select" class="cre-dash-filter-label">
					<strong><?php esc_html_e( 'Loan Type:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select id="cre-dash-type-select" class="cre-dash-select">
					<option value=""><?php esc_html_e( '— All Loan Types —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php if ( ! is_wp_error( $loan_type_terms ) ) : ?>
						<?php foreach ( $loan_type_terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->name ); ?>"><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>

				<label for="cre-dash-status-select" class="cre-dash-filter-label">
					<strong><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select id="cre-dash-status-select" class="cre-dash-select">
					<option value=""><?php esc_html_e( '— All Statuses —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $status_options as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="button" id="cre-dash-filter-btn" class="button button-primary">
					<?php esc_html_e( 'Apply Filters', 'mcp-ai-wpoos-pro' ); ?>
				</button>
				<button type="button" id="cre-dash-reset-btn" class="button button-secondary">
					<?php esc_html_e( 'Reset', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</div>

			<!-- Loading spinner (mirrors Health dashboard loading overlay) -->
			<div id="cre-dash-loading" style="display:none" class="cre-dash-loading">
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Loading dashboard…', 'mcp-ai-wpoos-pro' ); ?></span>
			</div>

			<?php if ( 0 === $portfolio['total_loans'] ) : ?>
				<div class="notice notice-info" id="cre-dash-empty-notice">
					<p>
						<?php esc_html_e( 'No CRE loans found. Add loans to see portfolio analytics.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_cre_loan' ) ); ?>" class="button button-primary" style="margin-left:10px;">
							<?php esc_html_e( 'Add First Loan', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div id="cre-dash-content" <?php echo 0 === $portfolio['total_loans'] ? 'style="display:none"' : ''; ?>>

				<!-- KPI Cards with threshold status indicators (like Health's sodium status) -->
				<div class="cre-dash-kpi-grid">
					<div class="cre-dash-kpi cre-dash-kpi-loans">
						<div class="cre-dash-kpi-icon">🏢</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-loans"><?php echo esc_html( number_format( $portfolio['total_loans'] ) ); ?></div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Total Loans', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-balance">
						<div class="cre-dash-kpi-icon">💰</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-balance">$<?php echo esc_html( number_format( $portfolio['total_balance'], 0 ) ); ?></div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Total Balance', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-rate">
						<div class="cre-dash-kpi-icon">📊</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-rate"><?php echo esc_html( $portfolio['avg_rate'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg Interest Rate', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-dscr">
						<div class="cre-dash-kpi-icon">📈</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-dscr"><?php echo esc_html( $portfolio['avg_dscr'] ); ?>x</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg DSCR', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="cre-dash-kpi-sub <?php echo $portfolio['avg_dscr'] >= $min_dscr ? 'status-normal' : 'status-alert'; ?>" id="cre-kpi-dscr-status">
							<?php
							if ( $portfolio['avg_dscr'] > 0 ) {
								echo $portfolio['avg_dscr'] >= $min_dscr
									? '✓ ' . esc_html(
											/* translators: %s: Minimum DSCR ratio (numeric) */
										sprintf( __( 'Above %sx min', 'mcp-ai-wpoos-pro' ), $min_dscr )
									)
									: '⚠ ' . esc_html(
											/* translators: %s: Minimum DSCR ratio (numeric) */
										sprintf( __( 'Below %sx min', 'mcp-ai-wpoos-pro' ), $min_dscr )
									);
							}
							?>
						</div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-ltv">
						<div class="cre-dash-kpi-icon">🏠</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-ltv"><?php echo esc_html( $portfolio['avg_ltv'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg LTV', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="cre-dash-kpi-sub <?php echo $portfolio['avg_ltv'] <= $max_ltv || 0 === (int) $portfolio['avg_ltv'] ? 'status-normal' : 'status-alert'; ?>" id="cre-kpi-ltv-status">
							<?php
							if ( $portfolio['avg_ltv'] > 0 ) {
								echo $portfolio['avg_ltv'] <= $max_ltv
									? '✓ ' . esc_html(
											/* translators: %s: Maximum LTV percentage (numeric) */
										sprintf( __( 'Under %s%% max', 'mcp-ai-wpoos-pro' ), $max_ltv )
									)
									: '⚠ ' . esc_html(
											/* translators: %s: Maximum LTV percentage (numeric) */
										sprintf( __( 'Over %s%% max', 'mcp-ai-wpoos-pro' ), $max_ltv )
									);
							}
							?>
						</div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-dy">
						<div class="cre-dash-kpi-icon">🎯</div>
						<div class="cre-dash-kpi-value" id="cre-kpi-dy"><?php echo esc_html( $portfolio['avg_debt_yield'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg Debt Yield', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="cre-dash-kpi-sub <?php echo $portfolio['avg_debt_yield'] >= $min_debt_yield || 0 === (int) $portfolio['avg_debt_yield'] ? 'status-normal' : 'status-warning'; ?>" id="cre-kpi-dy-status">
							<?php
							if ( $portfolio['avg_debt_yield'] > 0 ) {
								echo $portfolio['avg_debt_yield'] >= $min_debt_yield
									? '✓ ' . esc_html(
											/* translators: %s: Minimum debt yield percentage (numeric) */
										sprintf( __( 'Above %s%% min', 'mcp-ai-wpoos-pro' ), $min_debt_yield )
									)
									: '⚠ ' . esc_html(
											/* translators: %s: Minimum debt yield percentage (numeric) */
										sprintf( __( 'Below %s%% min', 'mcp-ai-wpoos-pro' ), $min_debt_yield )
									);
							}
							?>
						</div>
					</div>
				</div>

				<!-- Charts Row 1: Composition -->
				<div class="cre-dash-section">
					<h2 class="cre-dash-section-title">
						<span class="dashicons dashicons-chart-pie" style="vertical-align:middle;margin-right:6px;"></span>
						<?php esc_html_e( 'Portfolio Composition', 'mcp-ai-wpoos-pro' ); ?>
					</h2>
					<div class="cre-dash-charts-row">
						<div class="cre-dash-chart-card">
							<h3 class="cre-dash-chart-title"><?php esc_html_e( 'By Loan Type', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="cre-dash-chart-wrap"><canvas id="cre-chart-loan-type" height="200"></canvas></div>
						</div>
						<div class="cre-dash-chart-card">
							<h3 class="cre-dash-chart-title"><?php esc_html_e( 'By Property Type', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="cre-dash-chart-wrap"><canvas id="cre-chart-property-type" height="200"></canvas></div>
						</div>
						<div class="cre-dash-chart-card">
							<h3 class="cre-dash-chart-title"><?php esc_html_e( 'By Loan Status', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="cre-dash-chart-wrap"><canvas id="cre-chart-status" height="200"></canvas></div>
						</div>
					</div>
				</div>

				<!-- Charts Row 2: Risk & Maturity -->
				<div class="cre-dash-section">
					<h2 class="cre-dash-section-title">
						<span class="dashicons dashicons-chart-bar" style="vertical-align:middle;margin-right:6px;"></span>
						<?php esc_html_e( 'Risk & Maturity Analysis', 'mcp-ai-wpoos-pro' ); ?>
					</h2>
					<div class="cre-dash-charts-row">
						<div class="cre-dash-chart-card cre-dash-chart-wide">
							<h3 class="cre-dash-chart-title"><?php esc_html_e( 'Maturity Schedule ($)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="cre-dash-chart-wrap"><canvas id="cre-chart-maturity" height="160"></canvas></div>
						</div>
						<div class="cre-dash-chart-card">
							<h3 class="cre-dash-chart-title"><?php esc_html_e( 'Interest Rate Distribution', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="cre-dash-chart-wrap"><canvas id="cre-chart-rate-dist" height="160"></canvas></div>
						</div>
					</div>
				</div>

				<!-- Loan Summary Table (mirrors Health dashboard Goals & Achievements table) -->
				<div class="cre-dash-section">
					<h2 class="cre-dash-section-title">
						<span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-right:6px;"></span>
						<?php esc_html_e( 'Loan Summary & Risk Metrics', 'mcp-ai-wpoos-pro' ); ?>
					</h2>
					<table class="wp-list-table widefat fixed striped cre-dash-loan-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Loan', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Balance', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Rate', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'DSCR', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'LTV', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Debt Yield', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Maturity', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="cre-dash-loan-tbody">
							<?php if ( empty( $portfolio['loan_list'] ) ) : ?>
								<tr><td colspan="9" class="cre-dash-placeholder"><?php esc_html_e( 'No loans to display.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $portfolio['loan_list'] as $loan ) : ?>
									<?php
									$dscr_class = '';
									if ( $loan['dscr'] > 0 ) {
										$dscr_class = $loan['dscr'] >= $min_dscr ? 'status-normal' : 'status-alert';
									}
									$ltv_class = '';
									if ( $loan['ltv'] > 0 ) {
										$ltv_class = $loan['ltv'] <= $max_ltv ? 'status-normal' : 'status-alert';
									}
									$dy_class = '';
									if ( $loan['dy'] > 0 ) {
										$dy_class = $loan['dy'] >= $min_debt_yield ? 'status-normal' : 'status-warning';
									}
									$status_slug = sanitize_title( $loan['status'] );
									?>
									<tr>
										<td data-label="<?php esc_attr_e( 'Loan', 'mcp-ai-wpoos-pro' ); ?>">
											<a href="<?php echo esc_url( get_edit_post_link( $loan['id'] ) ); ?>">
												<?php echo esc_html( $loan['title'] ); ?>
											</a>
										</td>
										<td data-label="<?php esc_attr_e( 'Type', 'mcp-ai-wpoos-pro' ); ?>"><?php echo esc_html( $loan['type'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Balance', 'mcp-ai-wpoos-pro' ); ?>">$<?php echo esc_html( number_format( $loan['balance'], 0 ) ); ?></td>
										<td data-label="<?php esc_attr_e( 'Rate', 'mcp-ai-wpoos-pro' ); ?>"><?php echo $loan['rate'] > 0 ? esc_html( $loan['rate'] . '%' ) : '—'; ?></td>
										<td data-label="<?php esc_attr_e( 'DSCR', 'mcp-ai-wpoos-pro' ); ?>" class="<?php echo esc_attr( $dscr_class ); ?>"><?php echo $loan['dscr'] > 0 ? esc_html( $loan['dscr'] . 'x' ) : '—'; ?></td>
										<td data-label="<?php esc_attr_e( 'LTV', 'mcp-ai-wpoos-pro' ); ?>" class="<?php echo esc_attr( $ltv_class ); ?>"><?php echo $loan['ltv'] > 0 ? esc_html( $loan['ltv'] . '%' ) : '—'; ?></td>
										<td data-label="<?php esc_attr_e( 'Debt Yield', 'mcp-ai-wpoos-pro' ); ?>" class="<?php echo esc_attr( $dy_class ); ?>"><?php echo $loan['dy'] > 0 ? esc_html( $loan['dy'] . '%' ) : '—'; ?></td>
										<td data-label="<?php esc_attr_e( 'Maturity', 'mcp-ai-wpoos-pro' ); ?>"><?php echo $loan['maturity'] ? esc_html( $loan['maturity'] ) : '—'; ?></td>
										<td data-label="<?php esc_attr_e( 'Status', 'mcp-ai-wpoos-pro' ); ?>"><span class="cre-status-badge cre-status-<?php echo esc_attr( $status_slug ); ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $loan['status'] ) ) ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

			</div><!-- /#cre-dash-content -->
		</div>

		<?php if ( $portfolio['total_loans'] > 0 ) : ?>
		<script>
		var creDashData = <?php echo wp_json_encode( $portfolio ); ?>;
		</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Inline CSS for the dashboard.
	 *
	 * @return string
	 */
	private static function get_dashboard_css() {
		return '
/* ── CRE Debt Dashboard ─────────────────────────────────────── */
/* Filter Bar (mirrors Health dashboard member selector) */
.cre-dash-filter-bar{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:14px 20px;margin:16px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
.cre-dash-filter-label{font-size:13px;color:#1e1e1e;}
.cre-dash-select{min-width:180px;}
.cre-dash-loading{display:flex;align-items:center;gap:10px;padding:20px;color:#757575;}
/* KPIs */
.cre-dash-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin:20px 0;}
.cre-dash-kpi{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:16px 12px;text-align:center;position:relative;overflow:hidden;}
.cre-dash-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;}
.cre-dash-kpi-loans::before{background:#1565c0;}
.cre-dash-kpi-balance::before{background:#2e7d32;}
.cre-dash-kpi-rate::before{background:#e65100;}
.cre-dash-kpi-dscr::before{background:#6a1b9a;}
.cre-dash-kpi-ltv::before{background:#00838f;}
.cre-dash-kpi-dy::before{background:#c62828;}
.cre-dash-kpi-icon{font-size:22px;line-height:1.2;}
.cre-dash-kpi-value{font-size:22px;font-weight:700;color:#1e1e1e;margin:4px 0;}
.cre-dash-kpi-label{font-size:11px;color:#757575;text-transform:uppercase;letter-spacing:.4px;}
.cre-dash-kpi-sub{font-size:11px;margin-top:3px;color:#757575;}
/* Threshold status indicators (mirrors Health dashboard status classes) */
.status-normal{color:#2e7d32!important;font-weight:600;}
.status-warning{color:#e65100!important;font-weight:600;}
.status-alert{color:#c62828!important;font-weight:600;}
/* Sections & Charts */
.cre-dash-section{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.cre-dash-section-title{font-size:18px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f1;}
.cre-dash-charts-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:10px;}
@media(max-width:1200px){.cre-dash-charts-row{grid-template-columns:repeat(2,1fr);}}
@media(max-width:782px){.cre-dash-charts-row{grid-template-columns:1fr;}}
.cre-dash-chart-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.cre-dash-chart-wide{grid-column:span 2;}
@media(max-width:782px){.cre-dash-chart-wide{grid-column:span 1;}}
.cre-dash-chart-wrap{position:relative;height:220px;}
.cre-dash-chart-title{font-size:13px;font-weight:600;margin:0 0 10px;color:#1e1e1e;}
/* Summary Table (mirrors Health dashboard Goals table) */
.cre-dash-loan-table th,.cre-dash-loan-table td{padding:8px 10px;font-size:13px;}
.cre-dash-placeholder{color:#757575;text-align:center;padding:20px!important;}
.cre-status-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:capitalize;}
.cre-status-performing{background:#e8f5e9;color:#2e7d32;}
.cre-status-watchlist{background:#fff3e0;color:#e65100;}
.cre-status-special-servicing,.cre-status-delinquent-30,.cre-status-delinquent-60,.cre-status-delinquent-90,.cre-status-foreclosure{background:#ffebee;color:#c62828;}
.cre-status-paid-off,.cre-status-defeased{background:#e3f2fd;color:#1565c0;}
.cre-status-reo{background:#fce4ec;color:#ad1457;}
/* Mobile: stack table rows as cards (mirrors Health dashboard mobile pattern) */
@media(max-width:782px){
.cre-dash-loan-table{table-layout:auto;width:100%;}
.cre-dash-loan-table thead{display:none;}
.cre-dash-loan-table tbody tr{display:block;margin-bottom:12px;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;}
.cre-dash-loan-table tbody td{display:flex;justify-content:space-between;align-items:flex-start;width:100%;box-sizing:border-box;border-bottom:1px solid #f0f0f1;white-space:normal;word-break:break-word;}
.cre-dash-loan-table tbody td::before{content:attr(data-label);font-weight:600;color:#555;flex-shrink:0;margin-right:10px;min-width:40%;}
.cre-dash-placeholder::before{content:none!important;}
.cre-dash-placeholder{display:block!important;text-align:center;}
}
		';
	}

	/**
	 * Inline JavaScript for Chart.js rendering.
	 *
	 * @return string
	 */
	private static function get_dashboard_js() {
		return '(function($){
\'use strict\';

if(typeof creDashData===\'undefined\') var creDashData=null;

var PALETTE = [\'#1565c0\',\'#2e7d32\',\'#e65100\',\'#6a1b9a\',\'#00838f\',\'#c62828\',\'#f9a825\',\'#00695c\',\'#ad1457\',\'#4527a0\',\'#37474f\',\'#ef6c00\'];

/* ── Chart registry (destroy before rebuilding — mirrors Health dashboard pattern) ── */
var chartInsts = {};

function destroyChart(id){
if(chartInsts[id]){chartInsts[id].destroy();delete chartInsts[id];}
}

function buildDoughnut(id,labelMap){
destroyChart(id);
var el=document.getElementById(id);if(!el)return;
var keys=Object.keys(labelMap);
var vals=keys.map(function(k){return labelMap[k];});
var colors=keys.map(function(_,i){return PALETTE[i%PALETTE.length];});
chartInsts[id] = new Chart(el,{
type:\'doughnut\',
data:{labels:keys,datasets:[{data:vals,backgroundColor:colors,borderWidth:1}]},
options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:\'right\',labels:{boxWidth:12,font:{size:11}}}}}
});
}

function buildBar(id,labelMap,color,labelText){
destroyChart(id);
var el=document.getElementById(id);if(!el)return;
var keys=Object.keys(labelMap);
var vals=keys.map(function(k){return labelMap[k];});
chartInsts[id] = new Chart(el,{
type:\'bar\',
data:{labels:keys,datasets:[{label:labelText||\'\',data:vals,backgroundColor:color||PALETTE[0],borderRadius:3}]},
options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{maxTicksLimit:6}},x:{ticks:{maxRotation:45}}}}
});
}

/* Friendly status labels */
var statusLabels = {
performing: \'Performing\',
watchlist: \'Watchlist\',
special_service: \'Special Servicing\',
delinquent_30: \'Delinquent 30+\',
delinquent_60: \'Delinquent 60+\',
delinquent_90: \'Delinquent 90+\',
foreclosure: \'Foreclosure\',
reo: \'REO\',
paid_off: \'Paid Off\',
defeased: \'Defeased\'
};

/* ── Render all charts and KPIs from portfolio data ── */
function renderDashboard(data){
/* KPIs */
$(\'#cre-kpi-loans\').text(data.total_loans.toLocaleString());
$(\'#cre-kpi-balance\').text(\'$\'+data.total_balance.toLocaleString());
$(\'#cre-kpi-rate\').text(data.avg_rate+\'%\');
$(\'#cre-kpi-dscr\').text(data.avg_dscr+\'x\');
$(\'#cre-kpi-ltv\').text(data.avg_ltv+\'%\');
$(\'#cre-kpi-dy\').text(data.avg_debt_yield+\'%\');

/* Threshold status indicators (mirrors Health dashboard sodium/BP status) */
if(typeof wpMcpAiCreDashboard!==\'undefined\'){
var t=wpMcpAiCreDashboard.thresholds;
updateKpiStatus(\'#cre-kpi-dscr-status\', data.avg_dscr, t.min_dscr, true, t.min_dscr+\'x min\');
updateKpiStatus(\'#cre-kpi-ltv-status\', data.avg_ltv, t.max_ltv, false, t.max_ltv+\'% max\');
updateKpiStatus(\'#cre-kpi-dy-status\', data.avg_debt_yield, t.min_debt_yield, true, t.min_debt_yield+\'% min\');
}

/* Status map */
var friendlyStatus = {};
Object.keys(data.by_status).forEach(function(k){
friendlyStatus[statusLabels[k]||k] = data.by_status[k];
});

buildDoughnut(\'cre-chart-loan-type\', data.by_loan_type);
buildDoughnut(\'cre-chart-property-type\', data.by_property_type);
buildDoughnut(\'cre-chart-status\', friendlyStatus);
buildBar(\'cre-chart-maturity\', data.maturity_schedule, \'#1565c0\', \'Maturing Balance ($)\');
buildBar(\'cre-chart-rate-dist\', data.rate_distribution, \'#e65100\', \'Loans\');
}

function updateKpiStatus(sel, val, threshold, isMin, label){
var $el = $(sel);
if(!$el.length||!val) return;
var ok = isMin ? val >= threshold : val <= threshold;
$el.removeClass(\'status-normal status-warning status-alert\');
$el.addClass(ok?\'status-normal\':\'status-alert\');
$el.text((ok?\'✓ \':\'⚠ \')+(isMin?\'Above \':\'Under \')+label);
}

/* ── AJAX filter handler (mirrors Health dashboard member selector reload) ── */
$(document).on(\'click\',\'#cre-dash-filter-btn\',function(){
var loanType = $(\'#cre-dash-type-select\').val();
var loanStatus = $(\'#cre-dash-status-select\').val();
$(\'#cre-dash-loading\').show();
$(\'#cre-dash-content\').css(\'opacity\',\'0.5\');

$.post(wpMcpAiCreDashboard.ajaxUrl,{
action:\'wp_mcp_ai_cre_dashboard_filter\',
nonce: wpMcpAiCreDashboard.nonce,
loan_type: loanType,
loan_status: loanStatus
},function(resp){
$(\'#cre-dash-loading\').hide();
$(\'#cre-dash-content\').css(\'opacity\',\'1\');
if(resp.success && resp.data){
if(resp.data.total_loans>0){
$(\'#cre-dash-empty-notice\').hide();
$(\'#cre-dash-content\').show();
renderDashboard(resp.data);
} else {
$(\'#cre-dash-content\').hide();
$(\'#cre-dash-empty-notice\').show().find(\'p\').first().text(wpMcpAiCreDashboard.strings.noData);
}
}
}).fail(function(){
$(\'#cre-dash-loading\').hide();
$(\'#cre-dash-content\').css(\'opacity\',\'1\');
});
});

$(document).on(\'click\',\'#cre-dash-reset-btn\',function(){
$(\'#cre-dash-type-select\').val(\'\');
$(\'#cre-dash-status-select\').val(\'\');
$(\'#cre-dash-filter-btn\').trigger(\'click\');
});

/* ── Initial render ── */
if(creDashData) renderDashboard(creDashData);

})(jQuery);';
	}
}
