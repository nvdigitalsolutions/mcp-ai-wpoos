<?php
/**
 * Medical Vitals Dashboard Page
 *
 * Provides a dedicated admin dashboard for Medical Vitals data, separate from
 * the Health & Wellness dashboard. Mirrors the Medical Vitals charts, KPIs, and
 * tables from the Telegram Mini App templates so site administrators can review
 * vital-sign data for any member without leaving wp-admin.
 *
 * Data is fetched via WP Admin AJAX using the same underlying tool
 * (log_vital_signs) that powers the Mini App.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Medical Vitals Admin Dashboard Page
 */
class WP_MCP_AI_Medical_Vitals_Dashboard_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'medical-vitals-dashboard';

	/**
	 * Parent menu slug for the Health & Wellness CPT.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'edit.php?post_type=mcp_ai_member';

	/**
	 * Initialize the page (hooks).
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_mv_dashboard_get_vital_signs', array( __CLASS__, 'ajax_get_vital_signs' ) );
	}

	/**
	 * Add submenu page under the Health & Wellness (mcp_ai_member) menu.
	 *
	 * Requires 'edit_posts' rather than 'read' because the dashboard
	 * displays health data for ALL members (not just the current user).
	 */
	public static function add_menu_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Medical Vitals Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Medical Vitals', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue Chart.js and page-specific inline styles/scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_member_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue Chart.js (bundled with the plugin).
		wp_enqueue_script(
			'wp-mcp-ai-chartjs',
			WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js',
			array(),
			WP_MCP_AI_VERSION,
			true
		);

		// Inline page script.
		wp_add_inline_script(
			'wp-mcp-ai-chartjs',
			self::get_dashboard_js(),
			'after'
		);

		// Inline page styles.
		wp_add_inline_style(
			'wp-admin',
			self::get_dashboard_css()
		);

		// Pass PHP-side data to JS.
		wp_localize_script(
			'wp-mcp-ai-chartjs',
			'wpMcpAiMvDashboard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_mv_dashboard' ),
				'strings' => array(
					'loading'      => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'noData'       => __( 'No data available for this member yet.', 'mcp-ai-wpoos-pro' ),
					'selectMember' => __( 'Select a member above to view their dashboard.', 'mcp-ai-wpoos-pro' ),
					'error'        => __( 'Failed to load data. Please try again.', 'mcp-ai-wpoos-pro' ),
					'noMembers'    => __( 'No members found. Add a member first.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX: return vital signs history for a member.
	 *
	 * Requires 'edit_posts' (not 'read') because the admin dashboard may display
	 * data for any member — broader access than the TMA tools which only expose
	 * a single authenticated user's own data.
	 */
	public static function ajax_get_vital_signs() {
		check_ajax_referer( 'wp_mcp_ai_mv_dashboard', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$days_back = isset( $_POST['days_back'] ) ? absint( $_POST['days_back'] ) : 90;

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
		}

		$tool_class = 'WP_MCP_AI_Tool_Log_Vital_Signs';
		if ( ! class_exists( $tool_class ) ) {
			// Fallback: read options directly.
			$vital_signs_key = 'wp_mcp_ai_vital_signs_' . $member_id;
			$vital_signs     = get_option( $vital_signs_key, array() );
			$cutoff          = time() - ( $days_back * DAY_IN_SECONDS );
			$filtered        = array_filter(
				is_array( $vital_signs ) ? $vital_signs : array(),
				function ( $entry ) use ( $cutoff ) {
					return isset( $entry['timestamp'] ) && $entry['timestamp'] >= $cutoff;
				}
			);
			wp_send_json_success( array( 'history' => array_values( $filtered ) ) );
			return;
		}

		$tool   = new $tool_class();
		$result = $tool->execute(
			array(
				'action'    => 'get_history',
				'member_id' => $member_id,
				'days_back' => $days_back,
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'history' => isset( $result['history'] ) ? $result['history'] : array() ) );
	}

	/**
	 * Render the main dashboard page HTML.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// Load all members for the selector.
		$members = get_posts(
			array(
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wrap wp-mcp-ai-mv-dashboard-page">
			<h1><?php esc_html_e( 'Medical Vitals Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
			<hr class="wp-header-end">

			<!-- Member Selector -->
			<div class="hw-dash-member-bar">
				<?php if ( empty( $members ) ) : ?>
					<p class="hw-dash-no-members">
						<?php esc_html_e( 'No members found.', 'mcp-ai-wpoos-pro' ); ?>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_member' ) ); ?>" class="button button-primary" style="margin-left:10px">
							<?php esc_html_e( 'Add Member', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php else : ?>
					<label for="mv-dash-member-select" class="hw-dash-member-label">
						<strong><?php esc_html_e( 'Member:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label>
					<select id="mv-dash-member-select" class="hw-dash-select">
						<option value=""><?php esc_html_e( '— Select a member —', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $members as $member ) : ?>
							<?php
							$types = wp_get_object_terms( $member->ID, 'mcp_ai_member_type', array( 'fields' => 'names' ) );
							$type  = ( ! empty( $types ) && ! is_wp_error( $types ) ) ? $types[0] : '';
							$icon  = ( 'pet' === strtolower( $type ) ) ? '🐶' : '👤';
							?>
							<option value="<?php echo esc_attr( $member->ID ); ?>">
								<?php echo esc_html( $icon . ' ' . $member->post_title . ( $type ? ' (' . ucfirst( $type ) . ')' : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<select id="mv-dash-days-select" class="hw-dash-select" title="<?php esc_attr_e( 'Date range', 'mcp-ai-wpoos-pro' ); ?>">
						<option value="30"><?php esc_html_e( 'Last 30 days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="90" selected="selected"><?php esc_html_e( 'Last 90 days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="180"><?php esc_html_e( 'Last 180 days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="365"><?php esc_html_e( 'Last 365 days', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<button type="button" id="mv-dash-load-btn" class="button button-primary">
						<?php esc_html_e( 'Load Dashboard', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Dashboard content (hidden until a member is selected) -->
			<div id="mv-dash-content" style="display:none">

				<!-- ── Medical Vitals Section ─────────────────────────────── -->
				<div class="hw-dash-section hw-dash-section-vitals">
					<h2 class="hw-dash-section-title">
						<span class="dashicons dashicons-clipboard" style="color:#1565c0;vertical-align:middle;margin-right:6px"></span>
						<?php esc_html_e( 'Medical Vitals', 'mcp-ai-wpoos-pro' ); ?>
					</h2>

					<!-- Latest readings KPIs -->
					<div class="hw-dash-kpi-grid" id="mv-dash-kpi-grid">
						<div class="hw-dash-kpi hw-dash-kpi-bp">
							<div class="hw-dash-kpi-icon">🩺</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-bp">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Blood Pressure', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-bp-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">❤️</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-hr">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-hr-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🫁</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-spo2">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'SpO₂ (%)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-spo2-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🌡️</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-temp">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Temperature (°F)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-temp-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🩸</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-glucose">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-glucose-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🫀</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-egfr">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'eGFR', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-egfr-stage"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🩸</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-hgb">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Hemoglobin (g/dL)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-hgb-status"></div>
						</div>
					</div>

					<!-- Vitals trend charts -->
					<div class="hw-dash-charts-row">
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Blood Pressure (mmHg)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-bp" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-hr" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'SpO₂ (%)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-spo2" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Temperature (°F)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-temp" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Blood Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-glucose" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'eGFR (mL/min/1.73m²)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-egfr" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Creatinine (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-creatinine" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'BUN (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-bun" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Potassium / Sodium (mEq/L)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-electrolytes" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Phosphorus (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-phosphorus" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Albumin (g/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-albumin" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Hemoglobin (g/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="mv-chart-hemoglobin" height="120"></canvas>
						</div>
					</div>

					<!-- Latest readings table — Notes appear as full-width second row -->
					<div class="hw-dash-table-wrap">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Vital Signs — Recent Readings', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed hw-dash-vitals-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'BP (sys/dia)', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'HR', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'SpO₂', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Temp °F', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Glucose', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'eGFR', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Creatinine', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Hgb (g/dL)', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mv-dash-vitals-tbody">
								<tr><td colspan="9" class="hw-dash-placeholder"><?php esc_html_e( 'Select a member to view vitals.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
							</tbody>
						</table>
					</div>

					<!-- Kidney health summary table -->
					<div class="hw-dash-table-wrap" id="mv-kidney-table-wrap" style="display:none">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Kidney Health Markers — Latest Reading', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed striped hw-dash-kidney-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Marker', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Normal Range', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mv-kidney-tbody"></tbody>
						</table>
					</div>

				</div><!-- /.hw-dash-section.hw-dash-section-vitals -->

			</div><!-- /#mv-dash-content -->

			<!-- Loading spinner overlay -->
			<div id="mv-dash-loading" style="display:none" class="hw-dash-loading">
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Loading dashboard…', 'mcp-ai-wpoos-pro' ); ?></span>
			</div>

		</div><!-- /.wrap -->
		<?php
	}

	/**
	 * Returns the inline CSS for the Medical Vitals dashboard page.
	 *
	 * @return string
	 */
	private static function get_dashboard_css() {
		return '
/* ── Medical Vitals Dashboard ──────────────────────────────────── */
.hw-dash-member-bar{display:flex;align-items:center;gap:10px;margin:16px 0;flex-wrap:wrap;}
.hw-dash-member-label{white-space:nowrap;}
.hw-dash-select{min-width:220px;}
.hw-dash-section{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.hw-dash-section-title{font-size:18px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f1;}
.hw-dash-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px;}
.hw-dash-kpi{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px 10px;text-align:center;position:relative;overflow:hidden;}
.hw-dash-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:#1565c0;}
.hw-dash-kpi-bp::before{background:#c62828;}
.hw-dash-section-vitals .hw-dash-kpi::before{background:#1565c0;}
.hw-dash-kpi-icon{font-size:22px;line-height:1.2;}
.hw-dash-kpi-value{font-size:22px;font-weight:700;color:#1e1e1e;line-height:1.2;margin:4px 0;}
.hw-dash-kpi-label{font-size:11px;color:#757575;text-transform:uppercase;letter-spacing:.4px;}
.hw-dash-kpi-sub{font-size:11px;margin-top:3px;color:#757575;}
.hw-dash-kpi-sub.status-normal{color:#2e7d32;}
.hw-dash-kpi-sub.status-warning{color:#e65100;}
.hw-dash-kpi-sub.status-alert{color:#c62828;}
.hw-dash-charts-row{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:24px;}
@media(max-width:782px){.hw-dash-charts-row{grid-template-columns:1fr;}}
.hw-dash-chart-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.hw-dash-chart-title{font-size:13px;font-weight:600;margin:0 0 10px;color:#1e1e1e;}
.hw-dash-table-wrap{margin-bottom:20px;}
.hw-dash-table-title{font-size:14px;font-weight:600;margin:0 0 8px;}
.hw-dash-goals-table td,.hw-dash-goals-table th,.hw-dash-vitals-table td,.hw-dash-vitals-table th,.hw-dash-kidney-table td,.hw-dash-kidney-table th{padding:8px 10px;font-size:13px;}
.hw-dash-placeholder{color:#757575;text-align:center;padding:20px!important;}
.hw-dash-loading{display:flex;align-items:center;gap:10px;padding:20px;color:#757575;}
.hw-dash-no-members{color:#757575;}
.status-normal{color:#2e7d32!important;font-weight:600;}
.status-warning{color:#e65100!important;font-weight:600;}
.status-alert{color:#c62828!important;font-weight:600;}
/* Alternating row colours for vitals table (manual, since notes rows break nth-child) */
.hw-dash-vitals-table tbody tr.mv-vitals-data-row:nth-of-type(odd) td{background:#fff;}
.hw-dash-vitals-table tbody tr.mv-vitals-data-row:nth-of-type(even) td{background:#f6f7f7;}
/* Notes row — full-width, visually attached to its data row */
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row td{
	background:#f0f4ff;
	border-top:none;
	color:#444;
	font-size:12px;
	line-height:1.6;
	padding:4px 12px 10px 24px;
	white-space:normal;
	word-break:break-word;
}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row td::before{
	content:"📋 Notes: ";
	font-weight:600;
	color:#1565c0;
}
		';
	}

	/**
	 * Returns the inline JavaScript for the Medical Vitals dashboard page.
	 *
	 * Uses wp.ajax / admin-ajax.php for data retrieval and Chart.js for rendering.
	 *
	 * @return string
	 */
	private static function get_dashboard_js() {
		return <<<'JS'
(function($){
'use strict';

/* ── Colour palettes ─────────────────────────────────────────── */
var MV_COLORS = {
	systolic:   '#c62828',
	diastolic:  '#e57373',
	hr:         '#e91e63',
	spo2:       '#0288d1',
	temp:       '#ff6f00',
	glucose:    '#6a1b9a',
	egfr:       '#1565c0',
	creatinine: '#4a148c',
	bun:        '#880e4f',
	potassium:  '#1b5e20',
	sodium_mv:  '#006064',
	phosphorus: '#bf360c',
	albumin:    '#37474f',
	hemoglobin: '#b71c1c'
};

/* ── Chart registry (so we can destroy before rebuilding) ────── */
var chartInsts = {};

function destroyChart(id){
	if(chartInsts[id]){chartInsts[id].destroy();delete chartInsts[id];}
}

/* ── Sparkline helper (single metric line chart) ─────────────── */
function buildLineChart(canvasId,labels,data,color,refLine,refLabel,maxY){
	destroyChart(canvasId);
	var el = document.getElementById(canvasId);
	if(!el) return;
	var datasets = [{
		label: '',
		data: data,
		borderColor: color,
		backgroundColor: color+'22',
		tension: 0.3,
		pointRadius: data.length <= 30 ? 3 : 1,
		fill: true
	}];
	if(refLine!==undefined){
		datasets.push({
			label: refLabel||'Goal',
			data: labels.map(function(){return refLine;}),
			borderColor: '#bdbdbd',
			borderDash: [6,4],
			pointRadius: 0,
			fill: false
		});
	}
	chartInsts[canvasId] = new Chart(el,{
		type:'line',
		data:{labels:labels,datasets:datasets},
		options:{
			responsive:true,
			plugins:{legend:{display:!!refLine}},
			scales:{
				y:{beginAtZero:true,max:maxY||undefined,ticks:{maxTicksLimit:5}},
				x:{ticks:{maxTicksLimit:8,maxRotation:45}}
			}
		}
	});
}

function buildMultiLineChart(canvasId,labels,datasets){
	destroyChart(canvasId);
	var el = document.getElementById(canvasId);
	if(!el) return;
	chartInsts[canvasId] = new Chart(el,{
		type:'line',
		data:{labels:labels,datasets:datasets},
		options:{
			responsive:true,
			plugins:{legend:{display:true,position:'top'}},
			scales:{
				y:{beginAtZero:false,ticks:{maxTicksLimit:5}},
				x:{ticks:{maxTicksLimit:8,maxRotation:45}}
			}
		}
	});
}

/* ── Temperature normalisation helper ───────────────────────── */
function normTempToF(value, unit){
	var u=(unit||'F').toUpperCase();
	if(u==='C') return Math.round(((value*9/5)+32)*10)/10;
	return value;
}

/* ── Vital value extractor ───────────────────────────────────── */
function extractVitalValue(entry, fieldOrPath){
	/* Supports both flat (from JetEngine CCT: bp_systolic) and
	   nested (from options storage: measurements.blood_pressure.systolic) */
	if(entry[fieldOrPath]!==undefined) return entry[fieldOrPath];
	/* Try nested measurements object */
	if(entry.measurements){
		var m=entry.measurements;
		if(fieldOrPath==='bp_systolic'&&m.blood_pressure)  return m.blood_pressure.systolic||0;
		if(fieldOrPath==='bp_diastolic'&&m.blood_pressure) return m.blood_pressure.diastolic||0;
		if(fieldOrPath==='heart_rate'&&m.heart_rate)        return m.heart_rate.value||0;
		if(fieldOrPath==='oxygen_saturation'&&m.oxygen_saturation) return m.oxygen_saturation.value||0;
		if(fieldOrPath==='temperature'&&m.temperature)     return m.temperature.value||0;
		if(fieldOrPath==='blood_glucose'&&m.blood_glucose) return m.blood_glucose.value||0;
		if(fieldOrPath==='egfr'&&m.egfr)                   return m.egfr.value||0;
		if(fieldOrPath==='creatinine'&&m.creatinine)       return m.creatinine.value||0;
		if(fieldOrPath==='bun'&&m.bun)                     return m.bun.value||0;
		if(fieldOrPath==='potassium'&&m.potassium)         return m.potassium.value||0;
		if(fieldOrPath==='sodium'&&m.sodium)               return m.sodium.value||0;
		if(fieldOrPath==='phosphorus'&&m.phosphorus)       return m.phosphorus.value||0;
		if(fieldOrPath==='albumin'&&m.albumin)             return m.albumin.value||0;
		if(fieldOrPath==='hemoglobin'&&m.hemoglobin)       return m.hemoglobin.value||0;
		/* Temperature: normalise legacy °C entries to °F for consistent display */
		if(fieldOrPath==='temperature'&&m.temperature)     return normTempToF(m.temperature.value||0, m.temperature.unit||'F');
	}
	/* Flat CCT row: temperature_unit column tells us the stored unit */
	if(fieldOrPath==='temperature'&&entry.temperature)     return normTempToF(parseFloat(entry.temperature)||0, entry.temperature_unit||'F');
	if(fieldOrPath==='hemoglobin'&&entry.hemoglobin!==undefined) return parseFloat(entry.hemoglobin)||0;
	return 0;
}

function getEntryDate(entry){
	return entry.measurement_date || entry.date || (entry.timestamp ? new Date(entry.timestamp*1000).toISOString().slice(0,10) : '');
}

/* ── Status helpers ──────────────────────────────────────────── */
function bpStatusClass(sys,dia){
	if(sys<120&&dia<80) return 'status-normal';
	if(sys<130&&dia<80) return 'status-warning';
	return 'status-alert';
}
function hrStatus(v){ return (v>=60&&v<=100)?'status-normal':(v<50||v>110)?'status-alert':'status-warning'; }
function spo2Status(v){ return v>=95?'status-normal':v>=90?'status-warning':'status-alert'; }
function tempStatus(v){ return (v>=97&&v<=99)?'status-normal':(v>=99.1&&v<=100.4)?'status-warning':'status-alert'; }
function glucoseStatus(v){ return (v>=70&&v<=99)?'status-normal':(v>=100&&v<=125)?'status-warning':'status-alert'; }
function egfrCkd(v){
	if(!v||v===0) return '—';
	if(v>=90) return 'Stage 1 (Normal)';
	if(v>=60) return 'Stage 2 (Mild)';
	if(v>=45) return 'Stage 3a (Moderate)';
	if(v>=30) return 'Stage 3b (Moderate)';
	if(v>=15) return 'Stage 4 (Severe)';
	return 'Stage 5 (Kidney Failure)';
}
function egfrStatusClass(v){ return v>=60?'status-normal':v>=30?'status-warning':'status-alert'; }
function hgbStatus(v){ return v>=12?'status-normal':v>=11?'status-warning':'status-alert'; }

/* ── Medical Vitals rendering ────────────────────────────────── */
function renderMVDashboard(history){
	if(!history||!history.length){
		$('#mv-dash-vitals-tbody').html('<tr><td colspan="8" class="hw-dash-placeholder">'+wpMcpAiMvDashboard.strings.noData+'</td></tr>');
		return;
	}

	/* Sort by date ascending */
	history.sort(function(a,b){
		return getEntryDate(a).localeCompare(getEntryDate(b));
	});

	/* Latest-reading KPIs — for each metric, scan backwards through history
	   to find the most recent entry that actually carries a value for that
	   metric.  Records are often stored as separate log rows per measurement
	   type (e.g. one row for BP/HR/SpO₂ and another for renal labs), so the
	   chronologically last entry frequently has only partial data and cannot
	   reliably represent all KPIs on its own. */
	function latestFor(field){
		for(var i=history.length-1;i>=0;i--){
			var v=parseFloat(extractVitalValue(history[i],field));
			if(v>0) return v;
		}
		return 0;
	}
	var sys   = latestFor('bp_systolic');
	var dia   = latestFor('bp_diastolic');
	var hr    = latestFor('heart_rate');
	var spo2  = latestFor('oxygen_saturation');
	var temp  = latestFor('temperature');
	var gluc  = latestFor('blood_glucose');
	var egfr  = latestFor('egfr');
	var creat = latestFor('creatinine');
	var bun   = latestFor('bun');
	var pot   = latestFor('potassium');
	var sodMv = latestFor('sodium');
	var phos  = latestFor('phosphorus');
	var alb   = latestFor('albumin');
	var hgb   = latestFor('hemoglobin');

	/* BP KPI */
	if(sys||dia){
		$('#mv-kpi-bp').text(sys+'/'+dia+' mmHg');
		var bpCls=bpStatusClass(sys,dia);
		var bpLabel=bpCls==='status-normal'?'Normal':bpCls==='status-warning'?'Monitor':'Alert';
		$('#mv-kpi-bp-status').text(bpLabel).removeClass().addClass('hw-dash-kpi-sub '+bpCls);
	}
	if(hr){ $('#mv-kpi-hr').text(hr+' bpm'); $('#mv-kpi-hr-status').text(hr>=60&&hr<=100?'Normal':'Out of range').removeClass().addClass('hw-dash-kpi-sub '+hrStatus(hr)); }
	if(spo2){ $('#mv-kpi-spo2').text(spo2+'%'); $('#mv-kpi-spo2-status').text(spo2>=95?'Normal':spo2>=90?'Low':'Critical').removeClass().addClass('hw-dash-kpi-sub '+spo2Status(spo2)); }
	if(temp){ $('#mv-kpi-temp').text(temp+'°F'); $('#mv-kpi-temp-status').text(temp>=97&&temp<=99?'Normal':'Abnormal').removeClass().addClass('hw-dash-kpi-sub '+tempStatus(temp)); }
	if(gluc){ $('#mv-kpi-glucose').text(gluc+' mg/dL'); $('#mv-kpi-glucose-status').text(gluc>=70&&gluc<=99?'Normal':gluc<=125?'Pre-diabetic':'High').removeClass().addClass('hw-dash-kpi-sub '+glucoseStatus(gluc)); }
	if(egfr){ $('#mv-kpi-egfr').text(egfr); $('#mv-kpi-egfr-stage').text(egfrCkd(egfr)).removeClass().addClass('hw-dash-kpi-sub '+egfrStatusClass(egfr)); }
	if(hgb){ $('#mv-kpi-hgb').text(hgb+' g/dL'); $('#mv-kpi-hgb-status').text(hgb>=12?'Normal':hgb>=11?'Low':'Anaemia').removeClass().addClass('hw-dash-kpi-sub '+hgbStatus(hgb)); }

	/* Build chart data */
	var labels    = history.map(function(r){var d=getEntryDate(r);return d?d.slice(5):'';});
	var sysArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'bp_systolic'))||null;});
	var diaArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'bp_diastolic'))||null;});
	var hrArr     = history.map(function(r){return parseFloat(extractVitalValue(r,'heart_rate'))||null;});
	var spo2Arr   = history.map(function(r){return parseFloat(extractVitalValue(r,'oxygen_saturation'))||null;});
	var tempArr   = history.map(function(r){return parseFloat(extractVitalValue(r,'temperature'))||null;});
	var glucArr   = history.map(function(r){return parseFloat(extractVitalValue(r,'blood_glucose'))||null;});
	var egfrArr   = history.map(function(r){return parseFloat(extractVitalValue(r,'egfr'))||null;});
	var creatArr  = history.map(function(r){return parseFloat(extractVitalValue(r,'creatinine'))||null;});
	var bunArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'bun'))||null;});
	var potArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'potassium'))||null;});
	var sodMvArr  = history.map(function(r){return parseFloat(extractVitalValue(r,'sodium'))||null;});
	var phosArr   = history.map(function(r){return parseFloat(extractVitalValue(r,'phosphorus'))||null;});
	var albArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'albumin'))||null;});
	var hgbArr    = history.map(function(r){return parseFloat(extractVitalValue(r,'hemoglobin'))||null;});

	/* BP dual-line */
	buildMultiLineChart('mv-chart-bp', labels, [
		{label:'Systolic',data:sysArr,borderColor:MV_COLORS.systolic,backgroundColor:MV_COLORS.systolic+'22',tension:.3,fill:false},
		{label:'Diastolic',data:diaArr,borderColor:MV_COLORS.diastolic,backgroundColor:MV_COLORS.diastolic+'22',tension:.3,fill:false},
		{label:'Normal <120',data:labels.map(function(){return 120;}),borderColor:'#bdbdbd',borderDash:[6,4],pointRadius:0,fill:false}
	]);
	buildLineChart('mv-chart-hr',        labels, hrArr,    MV_COLORS.hr,        null,  null, null);
	buildLineChart('mv-chart-spo2',      labels, spo2Arr,  MV_COLORS.spo2,      95,    'Normal ≥95%', null);
	buildLineChart('mv-chart-temp',      labels, tempArr,  MV_COLORS.temp,      null,  null, null);
	buildLineChart('mv-chart-glucose',   labels, glucArr,  MV_COLORS.glucose,   99,    'Normal <100', null);
	buildLineChart('mv-chart-egfr',      labels, egfrArr,  MV_COLORS.egfr,      60,    'Normal ≥60',  null);
	buildLineChart('mv-chart-creatinine',labels, creatArr, MV_COLORS.creatinine,1.2,   'Normal ≤1.2', null);
	buildLineChart('mv-chart-bun',       labels, bunArr,   MV_COLORS.bun,       20,    'Normal ≤20',  null);
	buildMultiLineChart('mv-chart-electrolytes', labels, [
		{label:'K⁺ Potassium',data:potArr,borderColor:MV_COLORS.potassium,tension:.3,fill:false},
		{label:'Na⁺ Sodium',data:sodMvArr.map(function(v){return v?v/10:null;}),borderColor:MV_COLORS.sodium_mv,tension:.3,fill:false,borderDash:[4,2]}
	]);
	buildLineChart('mv-chart-phosphorus',labels, phosArr,  MV_COLORS.phosphorus,4.5,   'Normal ≤4.5', null);
	buildLineChart('mv-chart-albumin',   labels, albArr,   MV_COLORS.albumin,   null,  null, null);
	buildLineChart('mv-chart-hemoglobin',labels, hgbArr,   MV_COLORS.hemoglobin,12,    'Normal ≥12 g/dL', null);

	/* Vitals table (last 20 entries, newest first)
	   Notes are rendered as a full-width second row beneath each data record. */
	var tbody = $('#mv-dash-vitals-tbody').empty();
	var tableRows = history.slice().reverse().slice(0,20);
	$.each(tableRows,function(_,r){
		var rSys   = parseFloat(extractVitalValue(r,'bp_systolic'))||'';
		var rDia   = parseFloat(extractVitalValue(r,'bp_diastolic'))||'';
		var rHr    = parseFloat(extractVitalValue(r,'heart_rate'))||'';
		var rSpo2  = parseFloat(extractVitalValue(r,'oxygen_saturation'))||'';
		var rTemp  = parseFloat(extractVitalValue(r,'temperature'))||'';
		var rGluc  = parseFloat(extractVitalValue(r,'blood_glucose'))||'';
		var rEgfr  = parseFloat(extractVitalValue(r,'egfr'))||'';
		var rCreat = parseFloat(extractVitalValue(r,'creatinine'))||'';
		var rHgb   = parseFloat(extractVitalValue(r,'hemoglobin'))||'';
		var rNotes = r.notes||'';

		tbody.append(
			'<tr class="mv-vitals-data-row">'+
			'<td>'+getEntryDate(r)+'</td>'+
			'<td>'+(rSys&&rDia?rSys+'/'+rDia:'—')+'</td>'+
			'<td>'+(rHr||'—')+'</td>'+
			'<td>'+(rSpo2||'—')+'</td>'+
			'<td>'+(rTemp||'—')+'</td>'+
			'<td>'+(rGluc||'—')+'</td>'+
			'<td>'+(rEgfr||'—')+'</td>'+
			'<td>'+(rCreat||'—')+'</td>'+
			'<td>'+(rHgb||'—')+'</td>'+
			'</tr>'
		);

		if(rNotes){
			tbody.append(
				'<tr class="mv-vitals-notes-row">'+
				'<td colspan="9">'+rNotes+'</td>'+
				'</tr>'
			);
		}
	});
	if(!tableRows.length){
		tbody.html('<tr><td colspan="9" class="hw-dash-placeholder">'+wpMcpAiMvDashboard.strings.noData+'</td></tr>');
	}

	/* Kidney health markers table */
	var kidneyMarkers=[
		{label:'eGFR',       value:egfr,   unit:'mL/min/1.73m²',normal:'≥60',   cls:egfrStatusClass(egfr)},
		{label:'Creatinine', value:creat,  unit:'mg/dL',         normal:'0.6–1.2',cls:(creat>0&&creat<=1.2)?'status-normal':creat<=1.5?'status-warning':'status-alert'},
		{label:'BUN',        value:bun,    unit:'mg/dL',         normal:'7–20',  cls:(bun>=7&&bun<=20)?'status-normal':bun<=25?'status-warning':'status-alert'},
		{label:'K⁺ Potassium',value:pot,  unit:'mEq/L',         normal:'3.5–5.0',cls:(pot>=3.5&&pot<=5.0)?'status-normal':pot<=5.5?'status-warning':'status-alert'},
		{label:'Na⁺ Sodium', value:sodMv, unit:'mEq/L',         normal:'136–145',cls:(sodMv>=136&&sodMv<=145)?'status-normal':sodMv>=130?'status-warning':'status-alert'},
		{label:'Phosphorus', value:phos,  unit:'mg/dL',         normal:'2.5–4.5',cls:(phos>=2.5&&phos<=4.5)?'status-normal':phos<=5.5?'status-warning':'status-alert'},
		{label:'Albumin',    value:alb,   unit:'g/dL',          normal:'3.5–5.0',cls:(alb>=3.5&&alb<=5.0)?'status-normal':alb>=3.0?'status-warning':'status-alert'},
		{label:'Hemoglobin', value:hgb,   unit:'g/dL',          normal:'≥12.0', cls:hgbStatus(hgb)}
	];
	var hasKidney = egfr||creat||bun||pot||sodMv||phos||alb||hgb;
	if(hasKidney){
		var kTbody=$('#mv-kidney-tbody').empty();
		$.each(kidneyMarkers,function(_,m){
			var displayVal = m.value ? m.value+' '+m.unit : '—';
			var statusText = m.value ? (m.cls==='status-normal'?'Normal':m.cls==='status-warning'?'Monitor':'Alert') : '—';
			kTbody.append(
				'<tr>'+
				'<td><strong>'+m.label+'</strong></td>'+
				'<td>'+displayVal+'</td>'+
				'<td>'+m.normal+'</td>'+
				'<td class="'+m.cls+'">'+statusText+'</td>'+
				'</tr>'
			);
		});
		$('#mv-kidney-table-wrap').show();
	}
}

/* ── Main load flow ──────────────────────────────────────────── */
function loadDashboard(){
	var memberId = $('#mv-dash-member-select').val();
	var daysBack = $('#mv-dash-days-select').val();

	if(!memberId){
		alert(wpMcpAiMvDashboard.strings.selectMember);
		return;
	}

	$('#mv-dash-loading').show();
	$('#mv-dash-content').hide();

	$.ajax({
		url:  wpMcpAiMvDashboard.ajaxUrl,
		type: 'POST',
		data: {
			action:    'wp_mcp_ai_mv_dashboard_get_vital_signs',
			nonce:     wpMcpAiMvDashboard.nonce,
			member_id: memberId,
			days_back: daysBack
		},
		success: function(res){
			if(res.success&&res.data&&res.data.history){
				renderMVDashboard(res.data.history);
			}
		},
		error: function(){ /* silent — show empty state */ },
		complete: function(){
			$('#mv-dash-loading').hide();
			$('#mv-dash-content').show();
		}
	});
}

/* ── Wire up UI ──────────────────────────────────────────────── */
$(document).ready(function(){
	$('#mv-dash-load-btn').on('click',function(){ loadDashboard(); });

	/* Auto-load if there's only one member */
	if($('#mv-dash-member-select option').length===2){
		$('#mv-dash-member-select option:last').prop('selected',true);
		loadDashboard();
	}
});

})(jQuery);
JS;
	}
}

WP_MCP_AI_Medical_Vitals_Dashboard_Page::init();
