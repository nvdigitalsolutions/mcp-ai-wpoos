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
			array(),
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
	}

	/**
	 * Gather portfolio data from CPT posts.
	 *
	 * @return array Portfolio statistics.
	 */
	private static function get_portfolio_data() {
		$loans = get_posts(
			array(
				'post_type'      => 'mcp_ai_cre_loan',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$data = array(
			'total_loans'         => count( $loans ),
			'total_balance'       => 0,
			'avg_rate'            => 0,
			'avg_dscr'            => 0,
			'avg_ltv'             => 0,
			'avg_debt_yield'      => 0,
			'by_status'           => array(),
			'by_loan_type'        => array(),
			'by_property_type'    => array(),
			'maturity_schedule'   => array(),
			'rate_distribution'   => array(),
		);

		if ( empty( $loans ) ) {
			return $data;
		}

		$rates        = array();
		$dscrs        = array();
		$ltvs         = array();
		$debt_yields  = array();
		$balances     = array();

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
				$year = substr( $maturity, 0, 4 );
				if ( ! isset( $data['maturity_schedule'][ $year ] ) ) {
					$data['maturity_schedule'][ $year ] = 0;
				}
				$data['maturity_schedule'][ $year ] += $balance;
			}

			// Rate distribution bucket.
			if ( $rate > 0 ) {
				$bucket = floor( $rate ) . '-' . ( floor( $rate ) + 1 ) . '%';
				if ( ! isset( $data['rate_distribution'][ $bucket ] ) ) {
					$data['rate_distribution'][ $bucket ] = 0;
				}
				++$data['rate_distribution'][ $bucket ];
			}
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
		?>
		<div class="wrap cre-dash-page">
			<h1><?php esc_html_e( 'CRE Debt Portfolio Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<?php if ( 0 === $portfolio['total_loans'] ) : ?>
				<div class="notice notice-info">
					<p>
						<?php esc_html_e( 'No CRE loans found. Add loans to see portfolio analytics.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_cre_loan' ) ); ?>" class="button button-primary" style="margin-left:10px;">
							<?php esc_html_e( 'Add First Loan', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>

				<!-- KPI Cards -->
				<div class="cre-dash-kpi-grid">
					<div class="cre-dash-kpi cre-dash-kpi-loans">
						<div class="cre-dash-kpi-icon">🏢</div>
						<div class="cre-dash-kpi-value"><?php echo esc_html( number_format( $portfolio['total_loans'] ) ); ?></div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Total Loans', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-balance">
						<div class="cre-dash-kpi-icon">💰</div>
						<div class="cre-dash-kpi-value">$<?php echo esc_html( number_format( $portfolio['total_balance'], 0 ) ); ?></div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Total Balance', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-rate">
						<div class="cre-dash-kpi-icon">📊</div>
						<div class="cre-dash-kpi-value"><?php echo esc_html( $portfolio['avg_rate'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg Interest Rate', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-dscr">
						<div class="cre-dash-kpi-icon">📈</div>
						<div class="cre-dash-kpi-value"><?php echo esc_html( $portfolio['avg_dscr'] ); ?>x</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg DSCR', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-ltv">
						<div class="cre-dash-kpi-icon">🏠</div>
						<div class="cre-dash-kpi-value"><?php echo esc_html( $portfolio['avg_ltv'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg LTV', 'mcp-ai-wpoos-pro' ); ?></div>
					</div>
					<div class="cre-dash-kpi cre-dash-kpi-dy">
						<div class="cre-dash-kpi-icon">🎯</div>
						<div class="cre-dash-kpi-value"><?php echo esc_html( $portfolio['avg_debt_yield'] ); ?>%</div>
						<div class="cre-dash-kpi-label"><?php esc_html_e( 'Avg Debt Yield', 'mcp-ai-wpoos-pro' ); ?></div>
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

			<?php endif; ?>
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
		';
	}

	/**
	 * Inline JavaScript for Chart.js rendering.
	 *
	 * @return string
	 */
	private static function get_dashboard_js() {
		return <<<'JS'
(function(){
'use strict';

if(typeof creDashData==='undefined') return;

var PALETTE = ['#1565c0','#2e7d32','#e65100','#6a1b9a','#00838f','#c62828','#f9a825','#00695c','#ad1457','#4527a0','#37474f','#ef6c00'];

function buildDoughnut(id,labelMap){
var el=document.getElementById(id);if(!el)return;
var keys=Object.keys(labelMap);
var vals=keys.map(function(k){return labelMap[k];});
var colors=keys.map(function(_,i){return PALETTE[i%PALETTE.length];});
new Chart(el,{
type:'doughnut',
data:{labels:keys,datasets:[{data:vals,backgroundColor:colors,borderWidth:1}]},
options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{boxWidth:12,font:{size:11}}}}}
});
}

function buildBar(id,labelMap,color,labelText){
var el=document.getElementById(id);if(!el)return;
var keys=Object.keys(labelMap);
var vals=keys.map(function(k){return labelMap[k];});
new Chart(el,{
type:'bar',
data:{labels:keys,datasets:[{label:labelText||'',data:vals,backgroundColor:color||PALETTE[0],borderRadius:3}]},
options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{maxTicksLimit:6}},x:{ticks:{maxRotation:45}}}}
});
}

/* Friendly status labels */
var statusLabels = {
performing: 'Performing',
watchlist: 'Watchlist',
special_service: 'Special Servicing',
delinquent_30: 'Delinquent 30+',
delinquent_60: 'Delinquent 60+',
delinquent_90: 'Delinquent 90+',
foreclosure: 'Foreclosure',
reo: 'REO',
paid_off: 'Paid Off',
defeased: 'Defeased'
};
var friendlyStatus = {};
Object.keys(creDashData.by_status).forEach(function(k){
friendlyStatus[statusLabels[k]||k] = creDashData.by_status[k];
});

buildDoughnut('cre-chart-loan-type', creDashData.by_loan_type);
buildDoughnut('cre-chart-property-type', creDashData.by_property_type);
buildDoughnut('cre-chart-status', friendlyStatus);
buildBar('cre-chart-maturity', creDashData.maturity_schedule, '#1565c0', 'Maturing Balance ($)');
buildBar('cre-chart-rate-dist', creDashData.rate_distribution, '#e65100', 'Loans');

})();
JS;
	}
}
