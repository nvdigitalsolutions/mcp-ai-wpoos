<?php
/**
 * Health & Wellness Dashboard Page
 *
 * Mirrors the charts, KPIs, and tables from both the Health & Wellness and
 * Medical Vitals Telegram Mini App templates into an admin dashboard so
 * site administrators can review member health data without leaving wp-admin.
 *
 * Data is fetched via WP Admin AJAX using the same underlying tools
 * (log_health_metrics + log_vital_signs) that power the Mini App.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health & Wellness Admin Dashboard Page
 */
class WP_MCP_AI_Health_Wellness_Dashboard_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'health-wellness-dashboard';

	/**
	 * Initialize the page (hooks).
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 24 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_hw_dashboard_get_health_metrics', array( __CLASS__, 'ajax_get_health_metrics' ) );
		add_action( 'wp_ajax_wp_mcp_ai_hw_dashboard_get_vital_signs', array( __CLASS__, 'ajax_get_vital_signs' ) );
	}

	/**
	 * Add submenu page under the Health & Wellness (mcp_ai_member) menu.
	 *
	 * Requires 'edit_posts' rather than 'read' because the dashboard
	 * displays health data for ALL members (not just the current user),
	 * matching the capability used by WP_MCP_AI_Health_Records_Consolidate_Page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_member',
			__( 'Health & Wellness Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
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
			'wpMcpAiHwDashboard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_hw_dashboard' ),
				'strings' => array(
					'loading'       => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'noData'        => __( 'No data available for this member yet.', 'mcp-ai-wpoos-pro' ),
					'selectMember'  => __( 'Select a member above to view their dashboard.', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'Failed to load data. Please try again.', 'mcp-ai-wpoos-pro' ),
					'noMembers'     => __( 'No members found. Add a member first.', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX: return health metrics history for a member (steps, water, sleep, calories, sodium, mood).
	 *
	 * Requires 'edit_posts' (not 'read') because the admin dashboard may display
	 * data for any member — broader access than the TMA tools which only expose
	 * a single authenticated user's own data.
	 */
	public static function ajax_get_health_metrics() {
		check_ajax_referer( 'wp_mcp_ai_hw_dashboard', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
		$days_back = isset( $_POST['days_back'] ) ? absint( $_POST['days_back'] ) : 30;

		if ( ! $member_id ) {
			wp_send_json_error( array( 'message' => __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) ), 400 );
		}

		// Use the log_health_metrics tool directly.
		$tool_class = 'WP_MCP_AI_Tool_Log_Health_Metrics';
		if ( ! class_exists( $tool_class ) ) {
			// Fallback: read from the option directly.
			$option_key = 'wp_mcp_ai_health_metrics_' . $member_id;
			$all_data   = get_option( $option_key, array() );

			if ( ! is_array( $all_data ) ) {
				$all_data = array();
			}

			$cutoff  = gmdate( 'Y-m-d', time() - ( $days_back * DAY_IN_SECONDS ) );
			$history = array();
			foreach ( $all_data as $date => $entry ) {
				if ( $date >= $cutoff ) {
					$history[] = $entry;
				}
			}
			ksort( $history );

			wp_send_json_success( array( 'history' => array_values( $history ) ) );
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
	 * AJAX: return vital signs history for a member.
	 *
	 * Same capability rationale as ajax_get_health_metrics(): 'edit_posts' is
	 * required so the cross-member admin view is restricted to editors/admins.
	 */
	public static function ajax_get_vital_signs() {
		check_ajax_referer( 'wp_mcp_ai_hw_dashboard', 'nonce' );

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
		<div class="wrap wp-mcp-ai-hw-dashboard-page">
			<h1><?php esc_html_e( 'Health &amp; Wellness Dashboard', 'mcp-ai-wpoos-pro' ); ?></h1>
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
					<label for="hw-dash-member-select" class="hw-dash-member-label">
						<strong><?php esc_html_e( 'Member:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label>
					<select id="hw-dash-member-select" class="hw-dash-select">
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
					<select id="hw-dash-days-select" class="hw-dash-select" title="<?php esc_attr_e( 'Date range', 'mcp-ai-wpoos-pro' ); ?>">
						<option value="7"><?php esc_html_e( 'Last 7 days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="30" selected="selected"><?php esc_html_e( 'Last 30 days', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="90"><?php esc_html_e( 'Last 90 days', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
					<button type="button" id="hw-dash-load-btn" class="button button-primary">
						<?php esc_html_e( 'Load Dashboard', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<!-- Dashboard content (hidden until a member is selected) -->
			<div id="hw-dash-content" style="display:none">

				<!-- ── Health & Wellness Section ──────────────────────────── -->
				<div class="hw-dash-section">
					<h2 class="hw-dash-section-title">
						<span class="dashicons dashicons-heart" style="color:#2e7d32;vertical-align:middle;margin-right:6px"></span>
						<?php esc_html_e( 'Health &amp; Wellness', 'mcp-ai-wpoos-pro' ); ?>
					</h2>

					<!-- KPI Cards -->
					<div class="hw-dash-kpi-grid" id="hw-dash-kpi-grid">
						<div class="hw-dash-kpi hw-dash-kpi-steps">
							<div class="hw-dash-kpi-icon">🚶</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-steps">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Steps (today)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="hw-kpi-steps-pct"></div>
						</div>
						<div class="hw-dash-kpi hw-dash-kpi-water">
							<div class="hw-dash-kpi-icon">💧</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-water">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Water (glasses)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="hw-kpi-water-pct"></div>
						</div>
						<div class="hw-dash-kpi hw-dash-kpi-sleep">
							<div class="hw-dash-kpi-icon">😴</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-sleep">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Sleep (hrs)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="hw-kpi-sleep-pct"></div>
						</div>
						<div class="hw-dash-kpi hw-dash-kpi-calories">
							<div class="hw-dash-kpi-icon">🔥</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-calories">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Calories (kcal)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="hw-kpi-calories-pct"></div>
						</div>
						<div class="hw-dash-kpi hw-dash-kpi-sodium">
							<div class="hw-dash-kpi-icon">⚡</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-sodium">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Sodium (mg)', 'mcp-ai-wpoos-pro' ); ?></div>
							<div class="hw-dash-kpi-sub" id="hw-kpi-sodium-status"></div>
						</div>
						<div class="hw-dash-kpi hw-dash-kpi-mood">
							<div class="hw-dash-kpi-icon" id="hw-kpi-mood-emoji">😐</div>
							<div class="hw-dash-kpi-value" id="hw-kpi-mood">—</div>
							<div class="hw-dash-kpi-label"><?php esc_html_e( 'Mood', 'mcp-ai-wpoos-pro' ); ?></div>
						</div>
					</div>

					<!-- Trend Charts row -->
					<div class="hw-dash-charts-row">
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Steps — daily trend', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-steps" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Water — daily glasses', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-water" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Sleep — daily hours', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-sleep" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Calories — daily kcal', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-calories" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Sodium — daily mg (goal ≤2,300)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-sodium" height="120"></canvas>
						</div>
						<div class="hw-dash-chart-card">
							<h3 class="hw-dash-chart-title"><?php esc_html_e( 'Mood — daily rating (1–5)', 'mcp-ai-wpoos-pro' ); ?></h3>
							<canvas id="hw-chart-mood" height="120"></canvas>
						</div>
					</div>

					<!-- Weekly Goals summary table -->
					<div class="hw-dash-table-wrap">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Period Summary &amp; Goals', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed striped hw-dash-goals-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Metric', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Period Total / Avg', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Daily Goal', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Avg Achievement', 'mcp-ai-wpoos-pro' ); ?></th>
									<th><?php esc_html_e( 'Trend', 'mcp-ai-wpoos-pro' ); ?></th>
								</tr>
							</thead>
							<tbody id="hw-dash-goals-tbody">
								<tr><td colspan="5" class="hw-dash-placeholder"><?php esc_html_e( 'Select a member to view goals.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
							</tbody>
						</table>
					</div>

					<!-- Achievements/Badges -->
					<div id="hw-dash-badges-wrap" class="hw-dash-badges-wrap" style="display:none">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Achievements', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div id="hw-dash-badges" class="hw-dash-badges"></div>
					</div>
				</div><!-- /.hw-dash-section -->

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
					</div>

					<!-- Latest readings table -->
					<div class="hw-dash-table-wrap">
						<h3 class="hw-dash-table-title"><?php esc_html_e( 'Vital Signs — Recent Readings', 'mcp-ai-wpoos-pro' ); ?></h3>
						<table class="wp-list-table widefat fixed striped hw-dash-vitals-table">
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
									<th><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></th>
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

			</div><!-- /#hw-dash-content -->

			<!-- Loading spinner overlay -->
			<div id="hw-dash-loading" style="display:none" class="hw-dash-loading">
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Loading dashboard…', 'mcp-ai-wpoos-pro' ); ?></span>
			</div>

		</div><!-- /.wrap -->
		<?php
	}

	/**
	 * Returns the inline CSS for the dashboard page.
	 *
	 * @return string
	 */
	private static function get_dashboard_css() {
		return '
/* ── Health & Wellness Dashboard ───────────────────────────────── */
.hw-dash-member-bar{display:flex;align-items:center;gap:10px;margin:16px 0;flex-wrap:wrap;}
.hw-dash-member-label{white-space:nowrap;}
.hw-dash-select{min-width:220px;}
.hw-dash-section{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.hw-dash-section-title{font-size:18px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f1;}
.hw-dash-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-bottom:24px;}
.hw-dash-kpi{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px 10px;text-align:center;position:relative;overflow:hidden;}
.hw-dash-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:#2e7d32;}
.hw-dash-kpi-steps::before{background:#2e7d32;}
.hw-dash-kpi-water::before{background:#0288d1;}
.hw-dash-kpi-sleep::before{background:#7b1fa2;}
.hw-dash-kpi-calories::before{background:#e65100;}
.hw-dash-kpi-sodium::before{background:#f9a825;}
.hw-dash-kpi-mood::before{background:#00796b;}
.hw-dash-kpi-bp::before{background:#c62828;}
.hw-dash-section-vitals .hw-dash-kpi::before{background:#1565c0;}
.hw-dash-kpi-icon{font-size:22px;line-height:1.2;}
.hw-dash-kpi-value{font-size:22px;font-weight:700;color:#1e1e1e;line-height:1.2;margin:4px 0;}
.hw-dash-kpi-label{font-size:11px;color:#757575;text-transform:uppercase;letter-spacing:.4px;}
.hw-dash-kpi-sub{font-size:11px;margin-top:3px;color:#757575;}
.hw-dash-kpi-sub.status-normal{color:#2e7d32;}
.hw-dash-kpi-sub.status-warning{color:#e65100;}
.hw-dash-kpi-sub.status-alert{color:#c62828;}
.hw-dash-charts-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:24px;}
.hw-dash-chart-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.hw-dash-chart-title{font-size:13px;font-weight:600;margin:0 0 10px;color:#1e1e1e;}
.hw-dash-table-wrap{margin-bottom:20px;}
.hw-dash-table-title{font-size:14px;font-weight:600;margin:0 0 8px;}
.hw-dash-goals-table td,.hw-dash-goals-table th,.hw-dash-vitals-table td,.hw-dash-vitals-table th,.hw-dash-kidney-table td,.hw-dash-kidney-table th{padding:8px 10px;font-size:13px;}
.hw-dash-placeholder{color:#757575;text-align:center;padding:20px!important;}
.hw-dash-progress-wrap{width:100%;background:#e0e0e0;border-radius:4px;height:8px;overflow:hidden;}
.hw-dash-progress-fill{height:8px;border-radius:4px;background:#2e7d32;transition:width .3s;}
.hw-dash-progress-fill.over-goal{background:#e65100;}
.hw-dash-progress-fill.inverse-good{background:#2e7d32;}
.hw-dash-progress-fill.inverse-bad{background:#c62828;}
.hw-dash-badge-row{display:flex;flex-wrap:wrap;gap:10px;}
.hw-dash-badge{display:flex;align-items:center;gap:6px;padding:7px 14px;border-radius:20px;font-size:12px;font-weight:600;background:#f0f0f1;color:#757575;border:1px solid #dcdcde;}
.hw-dash-badge.earned{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7;}
.hw-dash-badges-wrap{margin-top:16px;}
.hw-dash-loading{display:flex;align-items:center;gap:10px;padding:20px;color:#757575;}
.status-normal{color:#2e7d32!important;font-weight:600;}
.status-warning{color:#e65100!important;font-weight:600;}
.status-alert{color:#c62828!important;font-weight:600;}
.hw-dash-no-members{color:#757575;}
		';
	}

	/**
	 * Returns the inline JavaScript for the dashboard page.
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
var HW_COLORS = {
	steps:    '#2e7d32',
	water:    '#0288d1',
	sleep:    '#7b1fa2',
	calories: '#e65100',
	sodium:   '#f9a825',
	mood:     '#00796b'
};
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
	albumin:    '#37474f'
};

/* ── Chart registry (so we can destroy before rebuilding) ────── */
var chartInsts = {};

function destroyChart(id){
	if(chartInsts[id]){chartInsts[id].destroy();delete chartInsts[id];}
}

/* ── Utility ─────────────────────────────────────────────────── */
function pct(val, goal){ return goal ? Math.round((val/goal)*100) : 0; }
function avg(arr){ return arr.length ? arr.reduce(function(a,b){return a+b;},0)/arr.length : 0; }
function round1(v){ return Math.round(v*10)/10; }

function statusClass(val, normal, warning){
	if(val<=normal) return 'status-normal';
	if(val<=warning) return 'status-warning';
	return 'status-alert';
}
function sodiumStatusClass(val){
	if(val===0||val==='') return '';
	if(val<=2300) return 'status-normal';
	if(val<=3000) return 'status-warning';
	return 'status-alert';
}
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

var MOOD_LABELS = ['','😢 Very Poor','😞 Poor','😐 Neutral','😊 Good','😄 Excellent'];
var MOOD_EMOJIS = ['','😢','😞','😐','😊','😄'];

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

/* ── Health & Wellness rendering ─────────────────────────────── */
function renderHWDashboard(history){
	/* Sort ascending by date */
	history.sort(function(a,b){return(a.date||'').localeCompare(b.date||'');});

	/* Latest day KPIs */
	var latest = history.length ? history[history.length-1] : {};
	var todaySteps    = latest.steps    || 0;
	var todayWater    = latest.water    || 0;
	var todaySleep    = latest.sleep    || 0;
	var todayCalories = latest.calories || 0;
	var todaySodium   = latest.sodium   || 0;
	var todayMood     = latest.mood     || 0;

	$('#hw-kpi-steps').text(todaySteps.toLocaleString());
	$('#hw-kpi-steps-pct').text(pct(todaySteps,10000)+'% of 10k goal');
	$('#hw-kpi-water').text(todayWater);
	$('#hw-kpi-water-pct').text(pct(todayWater,8)+'% of 8 glasses');
	$('#hw-kpi-sleep').text(round1(todaySleep)+' hrs');
	$('#hw-kpi-sleep-pct').text(pct(todaySleep,8)+'% of 8 hr goal');
	$('#hw-kpi-calories').text(todayCalories.toLocaleString()+' kcal');
	$('#hw-kpi-calories-pct').text(pct(todayCalories,2000)+'% of 2,000 goal');
	$('#hw-kpi-sodium').text(todaySodium.toLocaleString()+' mg');
	var sodClass = sodiumStatusClass(todaySodium);
	var sodLabel = todaySodium<=2300 ? '✓ Under 2,300 mg goal' : '⚠ Over 2,300 mg goal';
	$('#hw-kpi-sodium-status').text(todaySodium>0?sodLabel:'—').removeClass().addClass('hw-dash-kpi-sub '+sodClass);
	if(todayMood>0){
		$('#hw-kpi-mood-emoji').text(MOOD_EMOJIS[todayMood]);
		$('#hw-kpi-mood').text(MOOD_LABELS[todayMood]);
	} else {
		$('#hw-kpi-mood-emoji').text('—');
		$('#hw-kpi-mood').text('—');
	}

	/* Build chart data arrays */
	var labels    = history.map(function(r){return r.date?r.date.slice(5):'';});
	var stepsArr  = history.map(function(r){return r.steps||0;});
	var waterArr  = history.map(function(r){return r.water||0;});
	var sleepArr  = history.map(function(r){return r.sleep||0;});
	var calArr    = history.map(function(r){return r.calories||0;});
	var sodArr    = history.map(function(r){return r.sodium||0;});
	var moodArr   = history.map(function(r){return r.mood||0;});

	buildLineChart('hw-chart-steps',    labels, stepsArr,  HW_COLORS.steps,    10000, 'Goal 10k',   null);
	buildLineChart('hw-chart-water',    labels, waterArr,  HW_COLORS.water,    8,     'Goal 8',     null);
	buildLineChart('hw-chart-sleep',    labels, sleepArr,  HW_COLORS.sleep,    8,     'Goal 8 hrs', null);
	buildLineChart('hw-chart-calories', labels, calArr,    HW_COLORS.calories, 2000,  'Goal 2000',  null);
	buildLineChart('hw-chart-sodium',   labels, sodArr,    HW_COLORS.sodium,   2300,  'Limit 2300', null);
	buildLineChart('hw-chart-mood',     labels, moodArr,   HW_COLORS.mood,     null,  null,         5);

	/* Goals summary table */
	var nDays = history.length;
	var goals = [
		{icon:'🚶',label:'Steps',          total:stepsArr.reduce(function(a,b){return a+b;},0), goal:10000*nDays,  daily:10000,  unit:'steps', inverse:false},
		{icon:'💧',label:'Water',          total:waterArr.reduce(function(a,b){return a+b;},0), goal:8*nDays,      daily:8,      unit:'glasses',inverse:false},
		{icon:'😴',label:'Sleep',          total:round1(sleepArr.reduce(function(a,b){return a+b;},0)), goal:8*nDays, daily:8,   unit:'hrs',  inverse:false},
		{icon:'🔥',label:'Calories',       total:calArr.reduce(function(a,b){return a+b;},0), goal:2000*nDays,   daily:2000,   unit:'kcal', inverse:false},
		{icon:'⚡',label:'Sodium (kidney)',total:sodArr.reduce(function(a,b){return a+b;},0), goal:2300*nDays,   daily:2300,   unit:'mg',   inverse:true},
	];
	var tbody = $('#hw-dash-goals-tbody').empty();
	$.each(goals,function(_,g){
		var pctVal = g.goal ? Math.min(200,Math.round((g.total/g.goal)*100)) : 0;
		var fillW  = Math.min(100,pctVal);
		var fillCls= g.inverse ? (pctVal<=100?'hw-dash-progress-fill inverse-good':'hw-dash-progress-fill inverse-bad')
		                        : (pctVal>100?'hw-dash-progress-fill over-goal':'hw-dash-progress-fill');
		var avgDay = nDays ? round1(g.total/nDays) : 0;
		var trend  = stepsArr.length>=2 ? (function(arr){
			var last5  = arr.slice(-5);
			var prev5  = arr.slice(-10,-5);
			if(!prev5.length) return '—';
			return avg(last5) > avg(prev5) ? '↑' : avg(last5)<avg(prev5) ? '↓' : '→';
		})(g.label==='Steps'?stepsArr:g.label==='Water'?waterArr:g.label==='Sleep'?sleepArr:g.label==='Calories'?calArr:sodArr) : '—';
		tbody.append(
			'<tr>'+
			'<td>'+g.icon+' '+g.label+'</td>'+
			'<td>'+g.total.toLocaleString()+' '+g.unit+'</td>'+
			'<td>'+g.daily+' '+g.unit+'/day</td>'+
			'<td>'+avgDay+'/day &nbsp;<div class="hw-dash-progress-wrap"><div class="'+fillCls+'" style="width:'+fillW+'%"></div></div> <small>'+pctVal+'%</small></td>'+
			'<td>'+trend+'</td>'+
			'</tr>'
		);
	});

	/* Achievement badges */
	var streak = 0;
	for(var i=history.length-1;i>=0;i--){
		var e=history[i];
		if(!e.steps&&!e.water&&!e.sleep&&!e.calories) break;
		streak++;
	}
	var todayLog = latest;
	var badges=[
		{icon:'🔥',label:'3-Day Streak',        earned:streak>=3},
		{icon:'🎆',label:'7-Day Streak',        earned:streak>=7},
		{icon:'🚀',label:'10k Steps',           earned:todayLog.steps>=10000},
		{icon:'💧',label:'Hydration Hero',      earned:todayLog.water>=8},
		{icon:'😴',label:'Sleep Champion',      earned:todayLog.sleep>=8},
		{icon:'🫀',label:'Kidney Friendly',     earned:todayLog.sodium>0&&todayLog.sodium<=2300&&todayLog.water>=8},
		{icon:'⭐',label:'Perfect Day',         earned:todayLog.steps>=10000&&todayLog.water>=8&&todayLog.sleep>=8}
	];
	if(history.length){
		var badgeHtml='';
		$.each(badges,function(_,b){
			badgeHtml+='<span class="hw-dash-badge'+(b.earned?' earned':'')+'">'+b.icon+' '+b.label+'</span>';
		});
		$('#hw-dash-badges').html(badgeHtml);
		$('#hw-dash-badges-wrap').show();
	}
}

/* ── Medical Vitals rendering ────────────────────────────────── */
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
	}
	return 0;
}

function getEntryDate(entry){
	return entry.measurement_date || entry.date || (entry.timestamp ? new Date(entry.timestamp*1000).toISOString().slice(0,10) : '');
}

function renderMVDashboard(history){
	if(!history||!history.length){
		$('#mv-dash-vitals-tbody').html('<tr><td colspan="9" class="hw-dash-placeholder">'+wpMcpAiHwDashboard.strings.noData+'</td></tr>');
		return;
	}

	/* Sort by date ascending */
	history.sort(function(a,b){
		return getEntryDate(a).localeCompare(getEntryDate(b));
	});

	/* Latest entry KPIs */
	var latest = history[history.length-1];
	var sys     = parseFloat(extractVitalValue(latest,'bp_systolic'))   || 0;
	var dia     = parseFloat(extractVitalValue(latest,'bp_diastolic'))  || 0;
	var hr      = parseFloat(extractVitalValue(latest,'heart_rate'))    || 0;
	var spo2    = parseFloat(extractVitalValue(latest,'oxygen_saturation')) || 0;
	var temp    = parseFloat(extractVitalValue(latest,'temperature'))   || 0;
	var gluc    = parseFloat(extractVitalValue(latest,'blood_glucose')) || 0;
	var egfr    = parseFloat(extractVitalValue(latest,'egfr'))         || 0;
	var creat   = parseFloat(extractVitalValue(latest,'creatinine'))   || 0;
	var bun     = parseFloat(extractVitalValue(latest,'bun'))          || 0;
	var pot     = parseFloat(extractVitalValue(latest,'potassium'))    || 0;
	var sodMv   = parseFloat(extractVitalValue(latest,'sodium'))       || 0;
	var phos    = parseFloat(extractVitalValue(latest,'phosphorus'))   || 0;
	var alb     = parseFloat(extractVitalValue(latest,'albumin'))      || 0;

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

	/* Vitals table (last 20 entries, newest first) */
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
		var rNotes = r.notes||'';
		tbody.append(
			'<tr>'+
			'<td>'+getEntryDate(r)+'</td>'+
			'<td>'+(rSys&&rDia?rSys+'/'+rDia:'—')+'</td>'+
			'<td>'+(rHr||'—')+'</td>'+
			'<td>'+(rSpo2||'—')+'</td>'+
			'<td>'+(rTemp||'—')+'</td>'+
			'<td>'+(rGluc||'—')+'</td>'+
			'<td>'+(rEgfr||'—')+'</td>'+
			'<td>'+(rCreat||'—')+'</td>'+
			'<td>'+rNotes+'</td>'+
			'</tr>'
		);
	});
	if(!tableRows.length){
		tbody.html('<tr><td colspan="9" class="hw-dash-placeholder">'+wpMcpAiHwDashboard.strings.noData+'</td></tr>');
	}

	/* Kidney health markers table */
	var kidneyMarkers=[
		{label:'eGFR',       value:egfr,   unit:'mL/min/1.73m²',normal:'≥60',   cls:egfrStatusClass(egfr)},
		{label:'Creatinine', value:creat,  unit:'mg/dL',         normal:'0.6–1.2',cls:(creat>0&&creat<=1.2)?'status-normal':creat<=1.5?'status-warning':'status-alert'},
		{label:'BUN',        value:bun,    unit:'mg/dL',         normal:'7–20',  cls:(bun>=7&&bun<=20)?'status-normal':bun<=25?'status-warning':'status-alert'},
		{label:'K⁺ Potassium',value:pot,  unit:'mEq/L',         normal:'3.5–5.0',cls:(pot>=3.5&&pot<=5.0)?'status-normal':pot<=5.5?'status-warning':'status-alert'},
		{label:'Na⁺ Sodium', value:sodMv, unit:'mEq/L',         normal:'136–145',cls:(sodMv>=136&&sodMv<=145)?'status-normal':sodMv>=130?'status-warning':'status-alert'},
		{label:'Phosphorus', value:phos,  unit:'mg/dL',         normal:'2.5–4.5',cls:(phos>=2.5&&phos<=4.5)?'status-normal':phos<=5.5?'status-warning':'status-alert'},
		{label:'Albumin',    value:alb,   unit:'g/dL',          normal:'3.5–5.0',cls:(alb>=3.5&&alb<=5.0)?'status-normal':alb>=3.0?'status-warning':'status-alert'}
	];
	var hasKidney = egfr||creat||bun||pot||sodMv||phos||alb;
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
	var memberId = $('#hw-dash-member-select').val();
	var daysBack = $('#hw-dash-days-select').val();

	if(!memberId){
		alert(wpMcpAiHwDashboard.strings.selectMember);
		return;
	}

	$('#hw-dash-loading').show();
	$('#hw-dash-content').hide();

	var hwDone=false, mvDone=false;
	var hwHistory=[], mvHistory=[];

	function checkDone(){
		if(!hwDone||!mvDone) return;
		$('#hw-dash-loading').hide();
		$('#hw-dash-content').show();
		renderHWDashboard(hwHistory);
		renderMVDashboard(mvHistory);
	}

	/* Fetch health metrics */
	$.ajax({
		url:  wpMcpAiHwDashboard.ajaxUrl,
		type: 'POST',
		data: {
			action:    'wp_mcp_ai_hw_dashboard_get_health_metrics',
			nonce:     wpMcpAiHwDashboard.nonce,
			member_id: memberId,
			days_back: daysBack
		},
		success: function(res){
			if(res.success&&res.data&&res.data.history){
				hwHistory = res.data.history;
			}
		},
		error: function(){ /* silent — show empty state */ },
		complete: function(){ hwDone=true; checkDone(); }
	});

	/* Fetch vital signs */
	$.ajax({
		url:  wpMcpAiHwDashboard.ajaxUrl,
		type: 'POST',
		data: {
			action:    'wp_mcp_ai_hw_dashboard_get_vital_signs',
			nonce:     wpMcpAiHwDashboard.nonce,
			member_id: memberId,
			days_back: daysBack > 30 ? daysBack : 90
		},
		success: function(res){
			if(res.success&&res.data&&res.data.history){
				mvHistory = res.data.history;
			}
		},
		error: function(){ /* silent — show empty state */ },
		complete: function(){ mvDone=true; checkDone(); }
	});
}

/* ── Wire up UI ──────────────────────────────────────────────── */
$(document).ready(function(){
	$('#hw-dash-load-btn').on('click',function(){ loadDashboard(); });

	/* Auto-load if there's only one member */
	if($('#hw-dash-member-select option').length===2){
		$('#hw-dash-member-select option:last').prop('selected',true);
		loadDashboard();
	}
});

})(jQuery);
JS;
	}
}

WP_MCP_AI_Health_Wellness_Dashboard_Page::init();
