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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🔬</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-wbc">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'WBC (×10³/µL)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-wbc-status"></div>
						</div>
						<div class="hw-dash-kpi">
							<div class="hw-dash-kpi-icon">🩹</div>
							<div class="hw-dash-kpi-value" id="mv-kpi-plt">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Platelets (×10³/µL)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="mv-kpi-plt-status"></div>
						</div>
					</div>

					<!-- Vitals trend charts -->
					<div class="hw-dash-charts-row">
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Blood Pressure (mmHg)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-bp" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-hr" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'SpO₂ (%)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-spo2" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Temperature (°F)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-temp" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Blood Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-glucose" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'eGFR (mL/min/1.73m²)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-egfr" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Creatinine (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-creatinine" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'BUN (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-bun" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Potassium / Sodium (mEq/L)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-electrolytes" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Phosphorus (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-phosphorus" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Albumin (g/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-albumin" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Hemoglobin (g/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-hemoglobin" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'WBC (×10³/µL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-wbc" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Hematocrit (%)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-hematocrit" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Platelets (×10³/µL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-platelets" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Chloride / CO2 (mEq/L)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-electrolytes-ext" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Calcium (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-calcium" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Magnesium (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-magnesium" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'AST / ALT (U/L)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-liver" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Total Bilirubin (mg/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-bilirubin" height="120"></canvas></div>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Total Protein (g/dL)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<div class="hw-dash-chart-wrap"><canvas id="mv-chart-total-protein" height="120"></canvas></div>
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
									<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mv-kidney-tbody"></tbody>
						</table>
					</div>

					<!-- CBC / Anemia panel summary table -->
					<div class="hw-dash-table-wrap" id="mv-cbc-table-wrap" style="display:none">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'CBC / Anemia Panel — Latest Reading', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed striped hw-dash-kidney-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Marker', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Normal Range', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mv-cbc-tbody"></tbody>
						</table>
					</div>

					<!-- Extended BMP / LFT panel summary table -->
					<div class="hw-dash-table-wrap" id="mv-lft-table-wrap" style="display:none">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Extended BMP / Liver Function — Latest Reading', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed striped hw-dash-kidney-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Marker', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Normal Range', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="mv-lft-tbody"></tbody>
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
.hw-dash-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;}
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
@media(max-width:782px){.hw-dash-charts-row{grid-template-columns:1fr;}.hw-dash-chart-wrap{height:240px;}}
.hw-dash-chart-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.hw-dash-chart-wrap{position:relative;height:160px;}
.hw-dash-chart-title{font-size:13px;font-weight:600;margin:0 0 10px;color:#1e1e1e;}
.hw-dash-table-wrap{margin-bottom:20px;}
.hw-dash-table-title{font-size:14px;font-weight:600;margin:0 0 8px;}
.hw-dash-goals-table td,.hw-dash-goals-table th,.hw-dash-vitals-table td,.hw-dash-vitals-table th,.hw-dash-kidney-table td,.hw-dash-kidney-table th{padding:8px 10px;font-size:13px;}
.mv-cell-label{display:none;}
.hw-dash-placeholder{color:#757575;text-align:center;padding:20px!important;}
.hw-dash-loading{display:flex;align-items:center;gap:10px;padding:20px;color:#757575;}
.hw-dash-no-members{color:#757575;}
.status-normal{color:#2e7d32!important;font-weight:600;}
.status-warning{color:#e65100!important;font-weight:600;}
.status-alert{color:#c62828!important;font-weight:600;}
/* Alternating row colours for vitals table — index-based classes set by JS so
   stripes remain correct even when every data row has a notes sub-row. */
.hw-dash-vitals-table tbody tr.mv-vitals-data-row.mv-row-odd td{background:#fff;}
.hw-dash-vitals-table tbody tr.mv-vitals-data-row.mv-row-even td{background:#f6f7f7;}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row.mv-row-odd td{background:#e8f0fe;}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row.mv-row-even td{background:#f0f4ff;}
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
/* ── Mobile: stack table rows as cards ───────────────────────── */
@media(max-width:782px){
.hw-dash-vitals-table,.hw-dash-kidney-table{table-layout:auto;width:100%;}
.hw-dash-vitals-table thead,.hw-dash-kidney-table thead{display:none;}
.hw-dash-vitals-table tbody tr.mv-vitals-data-row,.hw-dash-kidney-table tbody tr{display:block;margin-bottom:12px;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;}
.hw-dash-vitals-table tbody tr.mv-vitals-data-row td,.hw-dash-kidney-table tbody td{display:flex;justify-content:space-between;align-items:flex-start;width:100%;box-sizing:border-box;border-bottom:1px solid #f0f0f1;white-space:normal;word-break:break-word;background:inherit;}
.hw-dash-vitals-table tbody tr.mv-vitals-data-row td::before,.hw-dash-kidney-table tbody td::before{content:none;}
.mv-cell-label{display:block;font-weight:600;color:#555;flex-shrink:0;margin-right:10px;min-width:40%;}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row{display:block;}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row td{display:block;}
.hw-dash-vitals-table tbody tr.mv-vitals-notes-row td::before{content:"📋 Notes: ";display:inline;font-weight:600;color:#1565c0;}
.hw-dash-placeholder::before{content:none!important;}
.hw-dash-placeholder{display:block!important;text-align:center;}
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
		return '(function($){
\'use strict\';

/* ── Colour palettes ─────────────────────────────────────────── */
var MV_COLORS = {
	systolic:   \'#c62828\',
	diastolic:  \'#e57373\',
	hr:         \'#e91e63\',
	spo2:       \'#0288d1\',
	temp:       \'#ff6f00\',
	glucose:    \'#6a1b9a\',
	egfr:       \'#1565c0\',
	creatinine: \'#4a148c\',
	bun:        \'#880e4f\',
	potassium:  \'#1b5e20\',
	sodium_mv:  \'#006064\',
	phosphorus: \'#bf360c\',
	albumin:    \'#37474f\',
	hemoglobin: \'#b71c1c\',
	hematocrit: \'#c62828\',
	wbc:        \'#00838f\',
	platelets:  \'#6a1b9a\',
	chloride:   \'#00695c\',
	co2:        \'#4e342e\',
	calcium:    \'#558b2f\',
	magnesium:  \'#283593\',
	bilirubin:  \'#f57f17\',
	ast:        \'#e65100\',
	alt:        \'#bf360c\',
	total_protein: \'#546e7a\'
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
		label: \'\',
		data: data,
		borderColor: color,
		backgroundColor: color+\'22\',
		tension: 0.3,
		pointRadius: data.length <= 30 ? 3 : 1,
		fill: true
	}];
	if(refLine!==undefined){
		datasets.push({
			label: refLabel||\'Goal\',
			data: labels.map(function(){return refLine;}),
			borderColor: \'#bdbdbd\',
			borderDash: [6,4],
			pointRadius: 0,
			fill: false
		});
	}
	chartInsts[canvasId] = new Chart(el,{
		type:\'line\',
		data:{labels:labels,datasets:datasets},
		options:{
			responsive:true,
			maintainAspectRatio:false,
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
		type:\'line\',
		data:{labels:labels,datasets:datasets},
		options:{
			responsive:true,
			maintainAspectRatio:false,
			plugins:{legend:{display:true,position:\'top\'}},
			scales:{
				y:{beginAtZero:false,ticks:{maxTicksLimit:5}},
				x:{ticks:{maxTicksLimit:8,maxRotation:45}}
			}
		}
	});
}

/* ── Temperature normalisation helper ───────────────────────── */
function normTempToF(value, unit){
	var u=(unit||\'F\').toUpperCase();
	if(u===\'C\') return Math.round(((value*9/5)+32)*10)/10;
	return value;
}

/* ── Vital value extractor ───────────────────────────────────── */
function extractVitalValue(entry, fieldOrPath){
	/* Temperature: handle first (before the generic early-return) so that
	   normTempToF is always applied — legacy Celsius entries stored in both
	   flat CCT rows and nested options-storage objects are converted to °F. */
	if(fieldOrPath===\'temperature\'){
		if(entry.measurements&&entry.measurements.temperature)
			return normTempToF(entry.measurements.temperature.value||0, entry.measurements.temperature.unit||\'F\');
		if(entry.temperature!==undefined)
			return normTempToF(parseFloat(entry.temperature)||0, entry.temperature_unit||\'F\');
		return 0;
	}
	/* Supports both flat (from JetEngine CCT: bp_systolic) and
	   nested (from options storage: measurements.blood_pressure.systolic) */
	if(entry[fieldOrPath]!==undefined) return entry[fieldOrPath];
	/* Try nested measurements object */
	if(entry.measurements){
		var m=entry.measurements;
		if(fieldOrPath===\'bp_systolic\'&&m.blood_pressure)  return m.blood_pressure.systolic||0;
		if(fieldOrPath===\'bp_diastolic\'&&m.blood_pressure) return m.blood_pressure.diastolic||0;
		if(fieldOrPath===\'heart_rate\'&&m.heart_rate)        return m.heart_rate.value||0;
		if(fieldOrPath===\'oxygen_saturation\'&&m.oxygen_saturation) return m.oxygen_saturation.value||0;
		if(fieldOrPath===\'blood_glucose\'&&m.blood_glucose) return m.blood_glucose.value||0;
		if(fieldOrPath===\'egfr\'&&m.egfr)                   return m.egfr.value||0;
		if(fieldOrPath===\'creatinine\'&&m.creatinine)       return m.creatinine.value||0;
		if(fieldOrPath===\'bun\'&&m.bun)                     return m.bun.value||0;
		if(fieldOrPath===\'potassium\'&&m.potassium)         return m.potassium.value||0;
		if(fieldOrPath===\'sodium\'&&m.sodium)               return m.sodium.value||0;
		if(fieldOrPath===\'phosphorus\'&&m.phosphorus)       return m.phosphorus.value||0;
		if(fieldOrPath===\'albumin\'&&m.albumin)             return m.albumin.value||0;
		if(fieldOrPath===\'hemoglobin\'&&m.hemoglobin)       return m.hemoglobin.value||0;
		if(fieldOrPath===\'hematocrit\'&&m.hematocrit)       return m.hematocrit.value||0;
		if(fieldOrPath===\'rbc\'&&m.rbc)                     return m.rbc.value||0;
		if(fieldOrPath===\'wbc\'&&m.wbc)                     return m.wbc.value||0;
		if(fieldOrPath===\'platelets\'&&m.platelets)         return m.platelets.value||0;
		if(fieldOrPath===\'mcv\'&&m.mcv)                     return m.mcv.value||0;
		if(fieldOrPath===\'mch\'&&m.mch)                     return m.mch.value||0;
		if(fieldOrPath===\'mchc\'&&m.mchc)                   return m.mchc.value||0;
		if(fieldOrPath===\'rdw\'&&m.rdw)                     return m.rdw.value||0;
		if(fieldOrPath===\'neutrophils_percent\'&&m.neutrophils_percent) return m.neutrophils_percent.value||0;
		if(fieldOrPath===\'lymphocytes_percent\'&&m.lymphocytes_percent) return m.lymphocytes_percent.value||0;
		if(fieldOrPath===\'monocytes_percent\'&&m.monocytes_percent)     return m.monocytes_percent.value||0;
		if(fieldOrPath===\'eosinophils_percent\'&&m.eosinophils_percent) return m.eosinophils_percent.value||0;
		if(fieldOrPath===\'basophils_percent\'&&m.basophils_percent)     return m.basophils_percent.value||0;
	}
	return 0;
}

function getEntryDate(entry){
	return entry.measurement_date || entry.date || (entry.timestamp ? new Date(entry.timestamp*1000).toISOString().slice(0,10) : \'\');
}

/* ── Status helpers ──────────────────────────────────────────── */
function bpStatusClass(sys,dia){
	if(sys<120&&dia<80) return \'status-normal\';
	if(sys<130&&dia<80) return \'status-warning\';
	return \'status-alert\';
}
function hrStatus(v){ return (v>=60&&v<=100)?\'status-normal\':(v<50||v>110)?\'status-alert\':\'status-warning\'; }
function spo2Status(v){ return v>=95?\'status-normal\':v>=90?\'status-warning\':\'status-alert\'; }
function tempStatus(v){ return (v>=97&&v<=99)?\'status-normal\':(v>=99.1&&v<=100.4)?\'status-warning\':\'status-alert\'; }
function egfrCkd(v){
	if(!v||v===0) return \'—\';
	if(v>=90) return \'Stage 1 (Normal)\';
	if(v>=60) return \'Stage 2 (Mild)\';
	if(v>=45) return \'Stage 3a (Moderate)\';
	if(v>=30) return \'Stage 3b (Moderate)\';
	if(v>=15) return \'Stage 4 (Severe)\';
	return \'Stage 5 (Kidney Failure)\';
}
function egfrStatusClass(v){ return v>=60?\'status-normal\':v>=30?\'status-warning\':\'status-alert\'; }
function hgbStatus(v){ return v>=12?\'status-normal\':v>=11?\'status-warning\':\'status-alert\'; }
function wbcStatus(v){ return (v>=4&&v<=11)?\'status-normal\':v<=15?\'status-warning\':\'status-alert\'; }
function pltStatus(v){ return (v>=150&&v<=400)?\'status-normal\':v>=100?\'status-warning\':\'status-alert\'; }
function hctStatus(v){ return (v>=36&&v<=52)?\'status-normal\':v>=30?\'status-warning\':\'status-alert\'; }

/* ── Medical Vitals rendering ────────────────────────────────── */
function renderMVDashboard(history){
	if(!history||!history.length){
		$(\'#mv-dash-vitals-tbody\').html(\'<tr><td colspan="8" class="hw-dash-placeholder">\'+wpMcpAiMvDashboard.strings.noData+\'</td></tr>\');
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
	function latestDateFor(field){
		for(var i=history.length-1;i>=0;i--){
			var v=parseFloat(extractVitalValue(history[i],field));
			if(v>0) return getEntryDate(history[i]);
		}
		return \'\';
	}
	var sys   = latestFor(\'bp_systolic\');
	var dia   = latestFor(\'bp_diastolic\');
	var hr    = latestFor(\'heart_rate\');
	var spo2  = latestFor(\'oxygen_saturation\');
	var temp  = latestFor(\'temperature\');
	var egfr  = latestFor(\'egfr\');
	var creat = latestFor(\'creatinine\');
	var bun   = latestFor(\'bun\');
	var pot   = latestFor(\'potassium\');
	var sodMv = latestFor(\'sodium\');
	var phos  = latestFor(\'phosphorus\');
	var alb   = latestFor(\'albumin\');
	var hgb   = latestFor(\'hemoglobin\');
	var wbc   = latestFor(\'wbc\');
	var hct   = latestFor(\'hematocrit\');
	var plt   = latestFor(\'platelets\');
	var chlor = latestFor(\'chloride\');
	var co2   = latestFor(\'co2\');
	var calc  = latestFor(\'calcium\');
	var mag   = latestFor(\'magnesium\');
	var bili  = latestFor(\'bilirubin\');
	var ast   = latestFor(\'ast\');
	var alt   = latestFor(\'alt\');
	var tprot = latestFor(\'total_protein\');

	/* BP KPI */
	if(sys||dia){
		$(\'#mv-kpi-bp\').text(sys+\'/\'+dia+\' mmHg\');
		var bpCls=bpStatusClass(sys,dia);
		var bpLabel=bpCls===\'status-normal\'?\'Normal\':bpCls===\'status-warning\'?\'Monitor\':\'Alert\';
		$(\'#mv-kpi-bp-status\').text(bpLabel).removeClass().addClass(\'hw-dash-kpi-sub \'+bpCls);
	}
	if(hr){ $(\'#mv-kpi-hr\').text(hr+\' bpm\'); $(\'#mv-kpi-hr-status\').text(hr>=60&&hr<=100?\'Normal\':\'Out of range\').removeClass().addClass(\'hw-dash-kpi-sub \'+hrStatus(hr)); }
	if(spo2){ $(\'#mv-kpi-spo2\').text(spo2+\'%\'); $(\'#mv-kpi-spo2-status\').text(spo2>=95?\'Normal\':spo2>=90?\'Low\':\'Critical\').removeClass().addClass(\'hw-dash-kpi-sub \'+spo2Status(spo2)); }
	if(temp){ $(\'#mv-kpi-temp\').text(temp+\'°F\'); $(\'#mv-kpi-temp-status\').text(temp>=97&&temp<=99?\'Normal\':\'Abnormal\').removeClass().addClass(\'hw-dash-kpi-sub \'+tempStatus(temp)); }
	if(egfr){ $(\'#mv-kpi-egfr\').text(egfr); $(\'#mv-kpi-egfr-stage\').text(egfrCkd(egfr)).removeClass().addClass(\'hw-dash-kpi-sub \'+egfrStatusClass(egfr)); }
	if(hgb){ $(\'#mv-kpi-hgb\').text(hgb+\' g/dL\'); $(\'#mv-kpi-hgb-status\').text(hgb>=12?\'Normal\':hgb>=11?\'Low\':\'Anaemia\').removeClass().addClass(\'hw-dash-kpi-sub \'+hgbStatus(hgb)); }
	if(wbc){ $(\'#mv-kpi-wbc\').text(wbc); $(\'#mv-kpi-wbc-status\').text((wbc>=4&&wbc<=11)?\'Normal\':wbc<4?\'Low\':\'High\').removeClass().addClass(\'hw-dash-kpi-sub \'+wbcStatus(wbc)); }
	if(plt){ $(\'#mv-kpi-plt\').text(plt); $(\'#mv-kpi-plt-status\').text((plt>=150&&plt<=400)?\'Normal\':plt<150?\'Low\':\'High\').removeClass().addClass(\'hw-dash-kpi-sub \'+pltStatus(plt)); }

	/* Build chart data */
	var labels    = history.map(function(r){var d=getEntryDate(r);return d?d.slice(5):\'\';});
	var sysArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'bp_systolic\'))||null;});
	var diaArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'bp_diastolic\'))||null;});
	var hrArr     = history.map(function(r){return parseFloat(extractVitalValue(r,\'heart_rate\'))||null;});
	var spo2Arr   = history.map(function(r){return parseFloat(extractVitalValue(r,\'oxygen_saturation\'))||null;});
	var tempArr   = history.map(function(r){return parseFloat(extractVitalValue(r,\'temperature\'))||null;});
	var glucArr   = history.map(function(r){return parseFloat(extractVitalValue(r,\'blood_glucose\'))||null;});
	var egfrArr   = history.map(function(r){return parseFloat(extractVitalValue(r,\'egfr\'))||null;});
	var creatArr  = history.map(function(r){return parseFloat(extractVitalValue(r,\'creatinine\'))||null;});
	var bunArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'bun\'))||null;});
	var potArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'potassium\'))||null;});
	var sodMvArr  = history.map(function(r){return parseFloat(extractVitalValue(r,\'sodium\'))||null;});
	var phosArr   = history.map(function(r){return parseFloat(extractVitalValue(r,\'phosphorus\'))||null;});
	var albArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'albumin\'))||null;});
	var hgbArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'hemoglobin\'))||null;});
	var wbcArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'wbc\'))||null;});
	var hctArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'hematocrit\'))||null;});
	var pltArr    = history.map(function(r){return parseFloat(extractVitalValue(r,\'platelets\'))||null;});
	var chlorArr  = history.map(function(r){return parseFloat(r.chloride)||null;});
	var co2Arr    = history.map(function(r){return parseFloat(r.co2)||null;});
	var calcArr   = history.map(function(r){return parseFloat(r.calcium)||null;});
	var magArr    = history.map(function(r){return parseFloat(r.magnesium)||null;});
	var biliArr   = history.map(function(r){return parseFloat(r.bilirubin)||null;});
	var astArr    = history.map(function(r){return parseFloat(r.ast)||null;});
	var altArr    = history.map(function(r){return parseFloat(r.alt)||null;});
	var tprotArr  = history.map(function(r){return parseFloat(r.total_protein)||null;});

	/* BP dual-line */
	buildMultiLineChart(\'mv-chart-bp\', labels, [
		{label:\'Systolic\',data:sysArr,borderColor:MV_COLORS.systolic,backgroundColor:MV_COLORS.systolic+\'22\',tension:.3,fill:false},
		{label:\'Diastolic\',data:diaArr,borderColor:MV_COLORS.diastolic,backgroundColor:MV_COLORS.diastolic+\'22\',tension:.3,fill:false},
		{label:\'Normal <120\',data:labels.map(function(){return 120;}),borderColor:\'#bdbdbd\',borderDash:[6,4],pointRadius:0,fill:false}
	]);
	buildLineChart(\'mv-chart-hr\',        labels, hrArr,    MV_COLORS.hr,        null,  null, null);
	buildLineChart(\'mv-chart-spo2\',      labels, spo2Arr,  MV_COLORS.spo2,      95,    \'Normal ≥95%\', null);
	buildLineChart(\'mv-chart-temp\',      labels, tempArr,  MV_COLORS.temp,      null,  null, null);
	buildLineChart(\'mv-chart-glucose\',   labels, glucArr,  MV_COLORS.glucose,   99,    \'Normal <100\', null);
	buildLineChart(\'mv-chart-egfr\',      labels, egfrArr,  MV_COLORS.egfr,      60,    \'Normal ≥60\',  null);
	buildLineChart(\'mv-chart-creatinine\',labels, creatArr, MV_COLORS.creatinine,1.2,   \'Normal ≤1.2\', null);
	buildLineChart(\'mv-chart-bun\',       labels, bunArr,   MV_COLORS.bun,       20,    \'Normal ≤20\',  null);
	buildMultiLineChart(\'mv-chart-electrolytes\', labels, [
		{label:\'K⁺ Potassium\',data:potArr,borderColor:MV_COLORS.potassium,tension:.3,fill:false},
		{label:\'Na⁺ Sodium\',data:sodMvArr.map(function(v){return v?v/10:null;}),borderColor:MV_COLORS.sodium_mv,tension:.3,fill:false,borderDash:[4,2]}
	]);
	buildLineChart(\'mv-chart-phosphorus\',labels, phosArr,  MV_COLORS.phosphorus,4.5,   \'Normal ≤4.5\', null);
	buildLineChart(\'mv-chart-albumin\',   labels, albArr,   MV_COLORS.albumin,   null,  null, null);
	buildLineChart(\'mv-chart-hemoglobin\',labels, hgbArr,   MV_COLORS.hemoglobin,12,    \'Normal ≥12 g/dL\', null);
	buildLineChart(\'mv-chart-wbc\',        labels, wbcArr,   MV_COLORS.wbc,       null,  null, null);
	buildLineChart(\'mv-chart-hematocrit\', labels, hctArr,   MV_COLORS.hematocrit,null,  null, null);
	buildLineChart(\'mv-chart-platelets\',  labels, pltArr,   MV_COLORS.platelets, 150,   \'Low <150\', null);
	buildMultiLineChart(\'mv-chart-electrolytes-ext\', labels, [
		{label:\'Cl⁻ Chloride\',data:chlorArr,borderColor:MV_COLORS.chloride,tension:.3,fill:false},
		{label:\'HCO₃⁻ CO2\',data:co2Arr,borderColor:MV_COLORS.co2,tension:.3,fill:false,borderDash:[4,2]}
	]);
	buildLineChart(\'mv-chart-calcium\',    labels, calcArr,  MV_COLORS.calcium,   10.2,  \'Normal ≤10.2\', null);
	buildLineChart(\'mv-chart-magnesium\',  labels, magArr,   MV_COLORS.magnesium, null,  null, null);
	buildMultiLineChart(\'mv-chart-liver\', labels, [
		{label:\'AST\',data:astArr,borderColor:MV_COLORS.ast,tension:.3,fill:false},
		{label:\'ALT\',data:altArr,borderColor:MV_COLORS.alt,tension:.3,fill:false,borderDash:[4,2]}
	]);
	buildLineChart(\'mv-chart-bilirubin\',  labels, biliArr,  MV_COLORS.bilirubin, 1.2,   \'Normal ≤1.2\', null);
	buildLineChart(\'mv-chart-total-protein\', labels, tprotArr, MV_COLORS.total_protein, null, null, null);

	/* Vitals table (last 20 entries, newest first)
	   Notes are rendered as a full-width second row beneath each data record.
	   Index-based mv-row-odd/mv-row-even classes are stamped so stripes alternate
	   correctly even when every record has a notes sub-row. */
	var tbody = $(\'#mv-dash-vitals-tbody\').empty();
	var tableRows = history.slice().reverse().slice(0,20);
	$.each(tableRows,function(idx,r){
		var rowParity = idx%2===0 ? \'mv-row-odd\' : \'mv-row-even\';
		var rSys   = parseFloat(extractVitalValue(r,\'bp_systolic\'))||\'\';
		var rDia   = parseFloat(extractVitalValue(r,\'bp_diastolic\'))||\'\';
		var rHr    = parseFloat(extractVitalValue(r,\'heart_rate\'))||\'\';
		var rSpo2  = parseFloat(extractVitalValue(r,\'oxygen_saturation\'))||\'\';
		var rTemp  = parseFloat(extractVitalValue(r,\'temperature\'))||\'\';
		var rGluc  = parseFloat(extractVitalValue(r,\'blood_glucose\'))||\'\';
		var rEgfr  = parseFloat(extractVitalValue(r,\'egfr\'))||\'\';
		var rCreat = parseFloat(extractVitalValue(r,\'creatinine\'))||\'\';
		var rHgb   = parseFloat(extractVitalValue(r,\'hemoglobin\'))||\'\';
		var rNotes = r.notes||\'\';

		tbody.append(
			\'<tr class="mv-vitals-data-row \'+rowParity+\'">\'+
			\'<td data-label="Date"><span class="mv-cell-label">Date</span>\'+getEntryDate(r)+\'</td>\'+
			\'<td data-label="BP (sys/dia)"><span class="mv-cell-label">BP (sys/dia)</span>\'+(rSys&&rDia?rSys+\'/\'+rDia:\'—\')+\'</td>\'+
			\'<td data-label="HR"><span class="mv-cell-label">HR</span>\'+(rHr||\'—\')+\'</td>\'+
			\'<td data-label="SpO₂"><span class="mv-cell-label">SpO₂</span>\'+(rSpo2||\'—\')+\'</td>\'+
			\'<td data-label="Temp °F"><span class="mv-cell-label">Temp °F</span>\'+(rTemp||\'—\')+\'</td>\'+
			\'<td data-label="Glucose"><span class="mv-cell-label">Glucose</span>\'+(rGluc||\'—\')+\'</td>\'+
			\'<td data-label="eGFR"><span class="mv-cell-label">eGFR</span>\'+(rEgfr||\'—\')+\'</td>\'+
			\'<td data-label="Creatinine"><span class="mv-cell-label">Creatinine</span>\'+(rCreat||\'—\')+\'</td>\'+
			\'<td data-label="Hgb (g/dL)"><span class="mv-cell-label">Hgb (g/dL)</span>\'+(rHgb||\'—\')+\'</td>\'+
			\'</tr>\'
		);

		if(rNotes){
			tbody.append(
				\'<tr class="mv-vitals-notes-row \'+rowParity+\'">\'+
				\'<td colspan="9">\'+rNotes+\'</td>\'+
				\'</tr>\'
			);
		}
	});
	if(!tableRows.length){
		tbody.html(\'<tr><td colspan="9" class="hw-dash-placeholder">\'+wpMcpAiMvDashboard.strings.noData+\'</td></tr>\');
	}

	/* Kidney health markers table */
	var kidneyMarkers=[
		{label:\'eGFR\',       value:egfr,   unit:\'mL/min/1.73m²\',normal:\'≥60\',   cls:egfrStatusClass(egfr),  date:latestDateFor(\'egfr\')},
		{label:\'Creatinine\', value:creat,  unit:\'mg/dL\',         normal:\'0.6–1.2\',cls:(creat>0&&creat<=1.2)?\'status-normal\':creat<=1.5?\'status-warning\':\'status-alert\', date:latestDateFor(\'creatinine\')},
		{label:\'BUN\',        value:bun,    unit:\'mg/dL\',         normal:\'7–20\',  cls:(bun>=7&&bun<=20)?\'status-normal\':bun<=25?\'status-warning\':\'status-alert\', date:latestDateFor(\'bun\')},
		{label:\'K⁺ Potassium\',value:pot,  unit:\'mEq/L\',         normal:\'3.5–5.0\',cls:(pot>=3.5&&pot<=5.0)?\'status-normal\':pot<=5.5?\'status-warning\':\'status-alert\', date:latestDateFor(\'potassium\')},
		{label:\'Na⁺ Sodium\', value:sodMv, unit:\'mEq/L\',         normal:\'136–145\',cls:(sodMv>=136&&sodMv<=145)?\'status-normal\':sodMv>=130?\'status-warning\':\'status-alert\', date:latestDateFor(\'sodium\')},
		{label:\'Phosphorus\', value:phos,  unit:\'mg/dL\',         normal:\'2.5–4.5\',cls:(phos>=2.5&&phos<=4.5)?\'status-normal\':phos<=5.5?\'status-warning\':\'status-alert\', date:latestDateFor(\'phosphorus\')},
		{label:\'Albumin\',    value:alb,   unit:\'g/dL\',          normal:\'3.5–5.0\',cls:(alb>=3.5&&alb<=5.0)?\'status-normal\':alb>=3.0?\'status-warning\':\'status-alert\', date:latestDateFor(\'albumin\')},
		{label:\'Hemoglobin\', value:hgb,   unit:\'g/dL\',          normal:\'≥12.0\', cls:hgbStatus(hgb), date:latestDateFor(\'hemoglobin\')}
	];
	var hasKidney = egfr||creat||bun||pot||sodMv||phos||alb||hgb;
	if(hasKidney){
		var kTbody=$(\'#mv-kidney-tbody\').empty();
		$.each(kidneyMarkers,function(_,m){
			var displayVal = m.value ? m.value+\' \'+m.unit : \'—\';
			var statusText = m.value ? (m.cls===\'status-normal\'?\'Normal\':m.cls===\'status-warning\'?\'Monitor\':\'Alert\') : \'—\';
			kTbody.append(
				\'<tr>\'+
				\'<td data-label="Marker"><span class="mv-cell-label">Marker</span><strong>\'+m.label+\'</strong></td>\'+
				\'<td data-label="Value"><span class="mv-cell-label">Value</span>\'+displayVal+\'</td>\'+
				\'<td data-label="Normal Range"><span class="mv-cell-label">Normal Range</span>\'+m.normal+\'</td>\'+
				\'<td data-label="Status" class="\'+m.cls+\'"><span class="mv-cell-label">Status</span>\'+statusText+\'</td>\'+
				\'<td data-label="Date"><span class="mv-cell-label">Date</span>\'+(m.date||\'—\')+\'</td>\'+
				\'</tr>\'
			);
		});
		$(\'#mv-kidney-table-wrap\').show();
	}

	/* CBC / anemia panel table */
	var rbc  = latestFor(\'rbc\');
	var mcv  = latestFor(\'mcv\');
	var mch  = latestFor(\'mch\');
	var mchc = latestFor(\'mchc\');
	var rdw  = latestFor(\'rdw\');
	var neutPct  = latestFor(\'neutrophils_percent\');
	var lymphPct = latestFor(\'lymphocytes_percent\');
	var monoPct  = latestFor(\'monocytes_percent\');
	var eoPct    = latestFor(\'eosinophils_percent\');
	var bsoPct   = latestFor(\'basophils_percent\');

	var cbcMarkers = [
		{label:\'Hemoglobin\', value:hgb, unit:\'g/dL\',       normal:\'≥12.0\',    cls:hgbStatus(hgb),                                                                         date:latestDateFor(\'hemoglobin\')},
		{label:\'Hematocrit\', value:hct, unit:\'%\',           normal:\'36–52\',    cls:hctStatus(hct),                                                                         date:latestDateFor(\'hematocrit\')},
		{label:\'RBC\',        value:rbc, unit:\'x10⁶/µL\',    normal:\'4.0–5.5\',  cls:(rbc>=4.0&&rbc<=5.5)?\'status-normal\':rbc>=3.5?\'status-warning\':\'status-alert\',         date:latestDateFor(\'rbc\')},
		{label:\'WBC\',        value:wbc, unit:\'x10³/µL\',    normal:\'4.0–11.0\', cls:wbcStatus(wbc),                                                                         date:latestDateFor(\'wbc\')},
		{label:\'Platelets\',  value:plt, unit:\'x10³/µL\',    normal:\'150–400\',  cls:pltStatus(plt),                                                                         date:latestDateFor(\'platelets\')},
		{label:\'MCV\',        value:mcv, unit:\'fL\',          normal:\'80–100\',   cls:(mcv>=80&&mcv<=100)?\'status-normal\':mcv>=70?\'status-warning\':\'status-alert\',           date:latestDateFor(\'mcv\')},
		{label:\'MCH\',        value:mch, unit:\'pg\',          normal:\'27–33\',    cls:(mch>=27&&mch<=33)?\'status-normal\':mch>=24?\'status-warning\':\'status-alert\',            date:latestDateFor(\'mch\')},
		{label:\'MCHC\',       value:mchc,unit:\'g/dL\',       normal:\'32–36\',    cls:(mchc>=32&&mchc<=36)?\'status-normal\':mchc>=30?\'status-warning\':\'status-alert\',         date:latestDateFor(\'mchc\')},
		{label:\'RDW\',        value:rdw, unit:\'%\',           normal:\'11.5–14.5\',cls:(rdw>=11.5&&rdw<=14.5)?\'status-normal\':rdw<=16?\'status-warning\':\'status-alert\',       date:latestDateFor(\'rdw\')},
		{label:\'Neutrophils %\', value:neutPct, unit:\'%\',   normal:\'50–70\',    cls:(neutPct>=50&&neutPct<=70)?\'status-normal\':\'status-warning\',                           date:latestDateFor(\'neutrophils_percent\')},
		{label:\'Lymphocytes %\', value:lymphPct,unit:\'%\',   normal:\'20–40\',    cls:(lymphPct>=20&&lymphPct<=40)?\'status-normal\':\'status-warning\',                         date:latestDateFor(\'lymphocytes_percent\')},
		{label:\'Monocytes %\',   value:monoPct, unit:\'%\',   normal:\'2–8\',      cls:(monoPct>=2&&monoPct<=8)?\'status-normal\':\'status-warning\',                             date:latestDateFor(\'monocytes_percent\')},
		{label:\'Eosinophils %\', value:eoPct,   unit:\'%\',   normal:\'1–4\',      cls:(eoPct>=1&&eoPct<=4)?\'status-normal\':eoPct<=6?\'status-warning\':\'status-alert\',        date:latestDateFor(\'eosinophils_percent\')},
		{label:\'Basophils %\',   value:bsoPct,  unit:\'%\',   normal:\'0–1\',      cls:(bsoPct>=0&&bsoPct<=1)?\'status-normal\':\'status-warning\',                              date:latestDateFor(\'basophils_percent\')}
	];
	var hasCbc = hgb||hct||rbc||wbc||plt||mcv||mch||mchc||rdw;
	if(hasCbc){
		var cTbody=$(\'#mv-cbc-tbody\').empty();
		$.each(cbcMarkers,function(_,m){
			if(!m.value) return; // skip absent markers.
			var displayVal = m.value+\' \'+m.unit;
			var statusText = m.cls===\'status-normal\'?\'Normal\':m.cls===\'status-warning\'?\'Monitor\':\'Alert\';
			cTbody.append(
				\'<tr>\'+
				\'<td data-label="Marker"><span class="mv-cell-label">Marker</span><strong>\'+m.label+\'</strong></td>\'+
				\'<td data-label="Value"><span class="mv-cell-label">Value</span>\'+displayVal+\'</td>\'+
				\'<td data-label="Normal Range"><span class="mv-cell-label">Normal Range</span>\'+m.normal+\'</td>\'+
				\'<td data-label="Status" class="\'+m.cls+\'"><span class="mv-cell-label">Status</span>\'+statusText+\'</td>\'+
				\'<td data-label="Date"><span class="mv-cell-label">Date</span>\'+(m.date||\'—\')+\'</td>\'+
				\'</tr>\'
			);
		});
		$(\'#mv-cbc-table-wrap\').show();
	}

	/* Extended BMP / Liver function table */
	var lftMarkers = [
		{label:\'Chloride Cl⁻\',  value:chlor, unit:\'mEq/L\', normal:\'98–107\',  cls:(chlor>=98&&chlor<=107)?\'status-normal\':chlor>=95?\'status-warning\':\'status-alert\', date:latestDateFor(\'chloride\')},
		{label:\'CO2/HCO₃⁻\',    value:co2,   unit:\'mEq/L\', normal:\'22–29\',   cls:(co2>=22&&co2<=29)?\'status-normal\':co2>=18?\'status-warning\':\'status-alert\',        date:latestDateFor(\'co2\')},
		{label:\'Calcium Ca2+\',  value:calc,  unit:\'mg/dL\', normal:\'8.5–10.2\',cls:(calc>=8.5&&calc<=10.2)?\'status-normal\':calc>=8.0?\'status-warning\':\'status-alert\', date:latestDateFor(\'calcium\')},
		{label:\'Magnesium Mg2+\',value:mag,   unit:\'mg/dL\', normal:\'1.7–2.2\', cls:(mag>=1.7&&mag<=2.2)?\'status-normal\':mag>=1.5?\'status-warning\':\'status-alert\',    date:latestDateFor(\'magnesium\')},
		{label:\'Bilirubin (T)\', value:bili,  unit:\'mg/dL\', normal:\'0.1–1.2\', cls:(bili<=1.2)?\'status-normal\':bili<=2.0?\'status-warning\':\'status-alert\',            date:latestDateFor(\'bilirubin\')},
		{label:\'AST / SGOT\',    value:ast,   unit:\'U/L\',   normal:\'10–40\',   cls:(ast>=10&&ast<=40)?\'status-normal\':ast<=80?\'status-warning\':\'status-alert\',        date:latestDateFor(\'ast\')},
		{label:\'ALT / SGPT\',    value:alt,   unit:\'U/L\',   normal:\'7–56\',    cls:(alt>=7&&alt<=56)?\'status-normal\':alt<=100?\'status-warning\':\'status-alert\',        date:latestDateFor(\'alt\')},
		{label:\'Total Protein\', value:tprot, unit:\'g/dL\',  normal:\'6.0–8.3\', cls:(tprot>=6.0&&tprot<=8.3)?\'status-normal\':tprot>=5.0?\'status-warning\':\'status-alert\', date:latestDateFor(\'total_protein\')}
	];
	var hasLft = chlor||co2||calc||mag||bili||ast||alt||tprot;
	if(hasLft){
		var lTbody=$(\'#mv-lft-tbody\').empty();
		$.each(lftMarkers,function(_,m){
			if(!m.value) return; // skip absent markers.
			var displayVal = m.value+\' \'+m.unit;
			var statusText = m.cls===\'status-normal\'?\'Normal\':m.cls===\'status-warning\'?\'Monitor\':\'Alert\';
			lTbody.append(
				\'<tr>\'+
				\'<td data-label="Marker"><span class="mv-cell-label">Marker</span><strong>\'+m.label+\'</strong></td>\'+
				\'<td data-label="Value"><span class="mv-cell-label">Value</span>\'+displayVal+\'</td>\'+
				\'<td data-label="Normal Range"><span class="mv-cell-label">Normal Range</span>\'+m.normal+\'</td>\'+
				\'<td data-label="Status" class="\'+m.cls+\'"><span class="mv-cell-label">Status</span>\'+statusText+\'</td>\'+
				\'<td data-label="Date"><span class="mv-cell-label">Date</span>\'+(m.date||\'—\')+\'</td>\'+
				\'</tr>\'
			);
		});
		$(\'#mv-lft-table-wrap\').show();
	}
}

/* ── Main load flow ──────────────────────────────────────────── */
function loadDashboard(){
	var memberId = $(\'#mv-dash-member-select\').val();
	var daysBack = $(\'#mv-dash-days-select\').val();

	if(!memberId){
		alert(wpMcpAiMvDashboard.strings.selectMember);
		return;
	}

	$(\'#mv-dash-loading\').show();
	$(\'#mv-dash-content\').hide();

	$.ajax({
		url:  wpMcpAiMvDashboard.ajaxUrl,
		type: \'POST\',
		data: {
			action:    \'wp_mcp_ai_mv_dashboard_get_vital_signs\',
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
			$(\'#mv-dash-loading\').hide();
			$(\'#mv-dash-content\').show();
		}
	});
}

/* ── Wire up UI ──────────────────────────────────────────────── */
$(document).ready(function(){
	$(\'#mv-dash-load-btn\').on(\'click\',function(){ loadDashboard(); });

	/* Auto-load if there\'s only one member */
	if($(\'#mv-dash-member-select option\').length===2){
		$(\'#mv-dash-member-select option:last\').prop(\'selected\',true);
		loadDashboard();
	}
});

})(jQuery);';
	}
}

WP_MCP_AI_Medical_Vitals_Dashboard_Page::init();
