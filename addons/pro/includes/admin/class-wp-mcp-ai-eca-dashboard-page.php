<?php
/**
 * ECA Dashboard Page
 *
 * Provides a dedicated admin dashboard for Extra-Curricular Activities (ECA) data.
 * Displays KPI cards, enrollment analytics, attendance trends, capacity utilisation,
 * and activity tables so administrators can monitor the ECA programme at a glance.
 *
 * Data is queried server-side via WP Admin AJAX from the ECA CPT and its post meta.
 * Charts are rendered with Chart.js (bundled with the plugin).
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
 * ECA Dashboard Admin Page
 */
class WP_MCP_AI_ECA_Dashboard_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'eca-dashboard';

	/**
	 * Parent menu slug for the ECA CPT.
	 *
	 * @var string
	 */
	const PARENT_SLUG = 'edit.php?post_type=mcp_ai_eca';

	/**
	 * Initialize the page (hooks).
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 5 );
		add_action( 'admin_menu', array( __CLASS__, 'make_dashboard_default' ), 999 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_eca_dashboard_data', array( __CLASS__, 'ajax_get_dashboard_data' ) );
	}

	/**
	 * Add submenu page under the ECA (mcp_ai_eca) menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'ECA Dashboard', 'mcp-ai-wpoos-pro' ),
			__( 'Dashboard', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Reorder the ECA submenu so Dashboard is the first item, making it the
	 * default page when clicking the top-level ECAs menu.
	 *
	 * Runs at a very late priority (999) so all submenu items are already registered.
	 */
	public static function make_dashboard_default() {
		global $submenu;

		$parent = self::PARENT_SLUG;

		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		$dashboard_item = null;
		$dashboard_key  = null;

		foreach ( $submenu[ $parent ] as $key => $item ) {
			if ( self::PAGE_SLUG === $item[2] ) {
				$dashboard_item = $item;
				$dashboard_key  = $key;
				break;
			}
		}

		if ( null === $dashboard_item ) {
			return;
		}

		// Remove the dashboard entry from its current position.
		unset( $submenu[ $parent ][ $dashboard_key ] );

		// Prepend it so it becomes the first (default) submenu item.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentional reorder.
		$submenu[ $parent ] = array_values( array_merge( array( $dashboard_item ), $submenu[ $parent ] ) );
	}

	/**
	 * Enqueue Chart.js and page-specific inline styles/scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_eca_page_' . self::PAGE_SLUG !== $hook ) {
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
			'wpMcpAiEcaDashboard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_eca_dashboard' ),
				'strings' => array(
					'loading'   => __( 'Loading…', 'mcp-ai-wpoos-pro' ),
					'noData'    => __( 'No ECA data available yet. Create your first activity to get started.', 'mcp-ai-wpoos-pro' ),
					'error'     => __( 'Failed to load data. Please try again.', 'mcp-ai-wpoos-pro' ),
					'noEcas'    => __( 'No ECAs found.', 'mcp-ai-wpoos-pro' ),
					'active'    => __( 'Active', 'mcp-ai-wpoos-pro' ),
					'inactive'  => __( 'Inactive', 'mcp-ai-wpoos-pro' ),
					'full'      => __( 'Full', 'mcp-ai-wpoos-pro' ),
					'cancelled' => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX: return dashboard data aggregated from ECA and Student CPTs.
	 */
	public static function ajax_get_dashboard_data() {
		check_ajax_referer( 'wp_mcp_ai_eca_dashboard', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		// Get all ECAs.
		$ecas = get_posts(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Get all students.
		$students = get_posts(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$total_ecas          = count( $ecas );
		$total_students      = count( $students );
		$total_enrollments   = 0;
		$total_capacity      = 0;
		$total_waitlisted    = 0;
		$active_count        = 0;
		$inactive_count      = 0;
		$full_count          = 0;
		$cancelled_count     = 0;
		$categories          = array();
		$types               = array();
		$eca_list            = array();
		$attendance_sessions = 0;
		$attendance_present  = 0;
		$attendance_total    = 0;

		foreach ( $ecas as $eca ) {
			$status             = get_post_meta( $eca->ID, '_eca_status', true );
			$max_students       = absint( get_post_meta( $eca->ID, '_eca_max_students', true ) );
			$enrolled_students  = get_post_meta( $eca->ID, '_eca_enrolled_students', true );
			$enrolled_students  = is_array( $enrolled_students ) ? $enrolled_students : array();
			$current_enrollment = count( $enrolled_students );
			$waitlist           = get_post_meta( $eca->ID, '_eca_waitlist', true );
			$waitlist           = is_array( $waitlist ) ? $waitlist : array();
			$eca_type           = get_post_meta( $eca->ID, '_eca_type', true );
			$eca_day            = get_post_meta( $eca->ID, '_eca_day', true );
			$eca_venue          = get_post_meta( $eca->ID, '_eca_venue', true );
			$attendance_log     = get_post_meta( $eca->ID, '_eca_attendance_log', true );
			$attendance_log     = is_array( $attendance_log ) ? $attendance_log : array();

			// Status counts.
			switch ( $status ) {
				case 'active':
					++$active_count;
					break;
				case 'inactive':
					++$inactive_count;
					break;
				case 'full':
					++$full_count;
					break;
				case 'cancelled':
					++$cancelled_count;
					break;
				default:
					++$active_count; // Default to active.
					break;
			}

			$total_enrollments += $current_enrollment;
			$total_waitlisted  += count( $waitlist );
			if ( $max_students > 0 ) {
				$total_capacity += $max_students;
			}

			// Category breakdown.
			$terms = wp_get_object_terms( $eca->ID, 'mcp_ai_eca_category', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term_name ) {
					if ( ! isset( $categories[ $term_name ] ) ) {
						$categories[ $term_name ] = 0;
					}
					++$categories[ $term_name ];
				}
			} else {
				if ( ! isset( $categories['Uncategorised'] ) ) {
					$categories['Uncategorised'] = 0;
				}
				++$categories['Uncategorised'];
			}

			// Type breakdown.
			if ( $eca_type ) {
				$type_label = ucwords( str_replace( '_', ' ', $eca_type ) );
				if ( ! isset( $types[ $type_label ] ) ) {
					$types[ $type_label ] = 0;
				}
				++$types[ $type_label ];
			}

			// Attendance aggregation.
			foreach ( $attendance_log as $session ) {
				++$attendance_sessions;
				if ( isset( $session['present_count'] ) ) {
					$attendance_present += absint( $session['present_count'] );
				}
				if ( isset( $session['attendees'] ) && is_array( $session['attendees'] ) ) {
					$attendance_total += count( $session['attendees'] );
				}
			}

			// Build ECA list for table.
			$utilisation = ( $max_students > 0 ) ? round( ( $current_enrollment / $max_students ) * 100 ) : 0;
			$eca_list[]  = array(
				'id'          => $eca->ID,
				'title'       => $eca->post_title,
				'status'      => $status ? $status : 'active',
				'type'        => $eca_type ? ucwords( str_replace( '_', ' ', $eca_type ) ) : '—',
				'day'         => $eca_day ? $eca_day : '—',
				'venue'       => $eca_venue ? $eca_venue : '—',
				'enrolled'    => $current_enrollment,
				'capacity'    => $max_students > 0 ? $max_students : '∞',
				'waitlisted'  => count( $waitlist ),
				'utilisation' => $max_students > 0 ? $utilisation : 0,
				'sessions'    => count( $attendance_log ),
			);
		}

		// Students with at least one ECA.
		$students_with_eca = 0;
		foreach ( $students as $student ) {
			$student_ecas = get_post_meta( $student->ID, '_student_eca_enrollments', true );
			if ( is_array( $student_ecas ) && ! empty( $student_ecas ) ) {
				++$students_with_eca;
			}
		}

		$participation_rate   = ( $total_students > 0 ) ? round( ( $students_with_eca / $total_students ) * 100 ) : 0;
		$attendance_rate      = ( $attendance_total > 0 ) ? round( ( $attendance_present / $attendance_total ) * 100 ) : 0;
		$capacity_rate        = ( $total_capacity > 0 ) ? round( ( $total_enrollments / $total_capacity ) * 100 ) : 0;
		$avg_ecas_per_student = ( $total_students > 0 ) ? round( $total_enrollments / $total_students, 1 ) : 0;

		// Sort ECA list by enrollment count descending.
		usort(
			$eca_list,
			function ( $a, $b ) {
				return $b['enrolled'] - $a['enrolled'];
			}
		);

		wp_send_json_success(
			array(
				'kpis'       => array(
					'total_ecas'           => $total_ecas,
					'total_students'       => $total_students,
					'total_enrollments'    => $total_enrollments,
					'total_capacity'       => $total_capacity,
					'students_with_eca'    => $students_with_eca,
					'participation_rate'   => $participation_rate,
					'attendance_rate'      => $attendance_rate,
					'capacity_rate'        => $capacity_rate,
					'total_waitlisted'     => $total_waitlisted,
					'avg_ecas_per_student' => $avg_ecas_per_student,
					'active_count'         => $active_count,
					'inactive_count'       => $inactive_count,
					'full_count'           => $full_count,
					'cancelled_count'      => $cancelled_count,
					'attendance_sessions'  => $attendance_sessions,
				),
				'categories' => $categories,
				'types'      => $types,
				'ecas'       => $eca_list,
			)
		);
	}

	/**
	 * Render the main dashboard page HTML.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		?>
		<div class="wrap wp-mcp-ai-eca-dashboard-page">
			<h1>
				<span class="dashicons dashicons-calendar-alt" style="vertical-align:middle;margin-right:6px;color:#1565c0"></span>
				<?php esc_html_e( 'ECA Dashboard', 'mcp-ai-wpoos-pro' ); ?>
			</h1>
			<hr class="wp-header-end">

			<p class="eca-dash-description">
				<?php esc_html_e( 'Real-time overview of your Extra-Curricular Activities programme — participation, enrollment, attendance, and capacity.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- KPI Cards Section -->
			<div class="eca-dash-section">
				<h2 class="eca-dash-section-title">
					<span class="dashicons dashicons-chart-bar" style="color:#1565c0;vertical-align:middle;margin-right:6px"></span>
					<?php esc_html_e( 'Key Performance Indicators', 'mcp-ai-wpoos-pro' ); ?>
				</h2>

				<div class="eca-dash-kpi-grid" id="eca-dash-kpi-grid">
					<div class="eca-dash-kpi eca-dash-kpi-primary">
						<div class="eca-dash-kpi-icon">📋</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-total-ecas">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Total ECAs', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-status-breakdown"></div>
					</div>
					<div class="eca-dash-kpi">
						<div class="eca-dash-kpi-icon">👨‍🎓</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-total-students">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Total Students', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-participation-rate"></div>
					</div>
					<div class="eca-dash-kpi">
						<div class="eca-dash-kpi-icon">📝</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-total-enrollments">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Total Enrollments', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-avg-per-student"></div>
					</div>
					<div class="eca-dash-kpi">
						<div class="eca-dash-kpi-icon">✅</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-attendance-rate">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Attendance Rate', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-sessions-count"></div>
					</div>
					<div class="eca-dash-kpi">
						<div class="eca-dash-kpi-icon">📊</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-capacity-rate">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Capacity Utilisation', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-capacity-detail"></div>
					</div>
					<div class="eca-dash-kpi">
						<div class="eca-dash-kpi-icon">⏳</div>
						<div class="eca-dash-kpi-value" id="eca-kpi-waitlisted">—</div>
						<div class="eca-dash-kpi-label"><?php esc_html_e( 'Waitlisted Students', 'mcp-ai-wpoos-pro' ); ?></div>
						<div class="eca-dash-kpi-sub" id="eca-kpi-waitlist-detail"></div>
					</div>
				</div>
			</div>

			<!-- Charts Section -->
			<div class="eca-dash-section">
				<h2 class="eca-dash-section-title">
					<span class="dashicons dashicons-chart-pie" style="color:#1565c0;vertical-align:middle;margin-right:6px"></span>
					<?php esc_html_e( 'Analytics', 'mcp-ai-wpoos-pro' ); ?>
				</h2>

				<div class="eca-dash-charts-row">
					<div class="eca-dash-chart-card">
						<h3 class="eca-dash-chart-title"><?php esc_html_e( 'ECAs by Category', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="eca-dash-chart-wrap"><canvas id="eca-chart-categories" height="200"></canvas></div>
					</div>
					<div class="eca-dash-chart-card">
						<h3 class="eca-dash-chart-title"><?php esc_html_e( 'ECAs by Type', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="eca-dash-chart-wrap"><canvas id="eca-chart-types" height="200"></canvas></div>
					</div>
					<div class="eca-dash-chart-card">
						<h3 class="eca-dash-chart-title"><?php esc_html_e( 'Status Overview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="eca-dash-chart-wrap"><canvas id="eca-chart-status" height="200"></canvas></div>
					</div>
					<div class="eca-dash-chart-card">
						<h3 class="eca-dash-chart-title"><?php esc_html_e( 'Top 10 — Enrollment by Activity', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="eca-dash-chart-wrap"><canvas id="eca-chart-enrollment" height="200"></canvas></div>
					</div>
				</div>
			</div>

			<!-- Activities Table Section -->
			<div class="eca-dash-section">
				<h2 class="eca-dash-section-title">
					<span class="dashicons dashicons-list-view" style="color:#1565c0;vertical-align:middle;margin-right:6px"></span>
					<?php esc_html_e( 'All Activities', 'mcp-ai-wpoos-pro' ); ?>
				</h2>

				<div class="eca-dash-table-wrap">
					<table class="wp-list-table widefat fixed striped eca-dash-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Activity', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Day', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Enrolled', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Capacity', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Utilisation', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Waitlist', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Sessions', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody id="eca-dash-tbody">
							<tr><td colspan="9" class="eca-dash-placeholder"><?php esc_html_e( 'Loading activities…', 'mcp-ai-wpoos-pro' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Quick Actions Section -->
			<div class="eca-dash-section eca-dash-actions-section">
				<h2 class="eca-dash-section-title">
					<span class="dashicons dashicons-admin-tools" style="color:#1565c0;vertical-align:middle;margin-right:6px"></span>
					<?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?>
				</h2>
				<div class="eca-dash-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_eca' ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'Add New ECA', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_student' ) ); ?>" class="button">
						<span class="dashicons dashicons-groups" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'Add Student', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=research-eca' ) ); ?>" class="button">
						<span class="dashicons dashicons-search" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=consolidate-eca' ) ); ?>" class="button">
						<span class="dashicons dashicons-media-document" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'Consolidate & Add', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=eca-settings' ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-settings" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'ECA Settings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_eca&page=eca-settings&tab=tools' ) ); ?>" class="button">
						<span class="dashicons dashicons-hammer" style="vertical-align:middle;margin-right:4px"></span>
						<?php esc_html_e( 'View All 35 Tools', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</div>
			</div>

			<!-- Loading spinner overlay -->
			<div id="eca-dash-loading" style="display:none" class="eca-dash-loading">
				<span class="spinner is-active"></span>
				<span><?php esc_html_e( 'Loading dashboard…', 'mcp-ai-wpoos-pro' ); ?></span>
			</div>

		</div><!-- /.wrap -->
		<?php
	}

	/**
	 * Returns the inline CSS for the ECA dashboard page.
	 *
	 * @return string
	 */
	private static function get_dashboard_css() {
		return '
/* ── ECA Dashboard ──────────────────────────────────────────── */
.eca-dash-description{color:#555;font-size:14px;margin:4px 0 16px;}
.eca-dash-section{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:20px 24px;margin:20px 0;}
.eca-dash-section-title{font-size:18px;margin:0 0 16px;padding-bottom:10px;border-bottom:2px solid #f0f0f1;}
.eca-dash-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:8px;}
.eca-dash-kpi{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px 10px;text-align:center;position:relative;overflow:hidden;}
.eca-dash-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:#1565c0;}
.eca-dash-kpi-primary::before{background:#2e7d32;}
.eca-dash-kpi-icon{font-size:22px;line-height:1.2;}
.eca-dash-kpi-value{font-size:22px;font-weight:700;color:#1e1e1e;line-height:1.2;margin:4px 0;}
.eca-dash-kpi-label{font-size:11px;color:#757575;text-transform:uppercase;letter-spacing:.4px;}
.eca-dash-kpi-sub{font-size:11px;margin-top:3px;color:#757575;}
.eca-dash-kpi-sub.status-good{color:#2e7d32;}
.eca-dash-kpi-sub.status-ok{color:#e65100;}
.eca-dash-kpi-sub.status-low{color:#c62828;}

.eca-dash-charts-row{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:8px;}
@media(max-width:782px){.eca-dash-charts-row{grid-template-columns:1fr;}}
.eca-dash-chart-card{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:14px;}
.eca-dash-chart-wrap{position:relative;height:220px;}
.eca-dash-chart-title{font-size:13px;font-weight:600;margin:0 0 10px;color:#1e1e1e;}

.eca-dash-table-wrap{margin-bottom:8px;overflow-x:auto;}
.eca-dash-table td,.eca-dash-table th{padding:8px 10px;font-size:13px;}
.eca-dash-placeholder{color:#757575;text-align:center;padding:20px!important;}
.eca-dash-loading{display:flex;align-items:center;gap:10px;padding:20px;color:#757575;}

.eca-dash-status-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;text-transform:uppercase;}
.eca-dash-status-active{background:#e8f5e9;color:#2e7d32;}
.eca-dash-status-inactive{background:#fff3e0;color:#e65100;}
.eca-dash-status-full{background:#e3f2fd;color:#1565c0;}
.eca-dash-status-cancelled{background:#ffebee;color:#c62828;}

.eca-dash-util-bar{display:inline-block;width:60px;height:8px;background:#e0e0e0;border-radius:4px;vertical-align:middle;margin-right:6px;overflow:hidden;}
.eca-dash-util-fill{height:100%;border-radius:4px;transition:width .3s;}
.eca-dash-util-fill.util-green{background:#2e7d32;}
.eca-dash-util-fill.util-amber{background:#e65100;}
.eca-dash-util-fill.util-red{background:#c62828;}

.eca-dash-actions-section .eca-dash-actions{display:flex;gap:10px;flex-wrap:wrap;}
.eca-dash-actions .button .dashicons{font-size:16px;width:16px;height:16px;}

@media(max-width:782px){
.eca-dash-table thead{display:none;}
.eca-dash-table tbody tr{display:block;margin-bottom:12px;border:1px solid #dcdcde;border-radius:4px;overflow:hidden;}
.eca-dash-table tbody td{display:flex;justify-content:space-between;align-items:flex-start;width:100%;box-sizing:border-box;border-bottom:1px solid #f0f0f1;white-space:normal;word-break:break-word;}
.eca-dash-cell-label{display:block;font-weight:600;color:#555;flex-shrink:0;margin-right:10px;min-width:40%;}
}
@media(min-width:783px){
.eca-dash-cell-label{display:none;}
}
		';
	}

	/**
	 * Returns the inline JavaScript for the ECA dashboard page.
	 *
	 * @return string
	 */
	private static function get_dashboard_js() {
		return '(function($){
\'use strict\';

/* ── Chart colour palette ──────────────────────────────────── */
var ECA_PALETTE = [
	\'#1565c0\',\'#2e7d32\',\'#c62828\',\'#6a1b9a\',\'#e65100\',
	\'#00838f\',\'#558b2f\',\'#ad1457\',\'#4527a0\',\'#bf360c\',
	\'#00695c\',\'#283593\',\'#880e4f\',\'#37474f\',\'#f57f17\'
];

/* ── Chart registry (destroy before rebuilding) ────────────── */
var chartInsts = {};

function destroyChart(id){
	if(chartInsts[id]){chartInsts[id].destroy();delete chartInsts[id];}
}

/* ── Doughnut chart helper ─────────────────────────────────── */
function buildDoughnut(canvasId,labels,data,colors){
	destroyChart(canvasId);
	var el = document.getElementById(canvasId);
	if(!el) return;
	chartInsts[canvasId] = new Chart(el,{
		type:\'doughnut\',
		data:{
			labels:labels,
			datasets:[{data:data,backgroundColor:colors||ECA_PALETTE.slice(0,data.length),borderWidth:1}]
		},
		options:{
			responsive:true,
			maintainAspectRatio:false,
			plugins:{legend:{position:\'right\',labels:{boxWidth:12,font:{size:11}}}}
		}
	});
}

/* ── Horizontal bar chart helper ───────────────────────────── */
function buildHBar(canvasId,labels,data,color){
	destroyChart(canvasId);
	var el = document.getElementById(canvasId);
	if(!el) return;
	chartInsts[canvasId] = new Chart(el,{
		type:\'bar\',
		data:{
			labels:labels,
			datasets:[{data:data,backgroundColor:color||\'#1565c0\',borderRadius:3}]
		},
		options:{
			indexAxis:\'y\',
			responsive:true,
			maintainAspectRatio:false,
			plugins:{legend:{display:false}},
			scales:{x:{beginAtZero:true,ticks:{stepSize:1}}}
		}
	});
}

/* ── Status badge helper ───────────────────────────────────── */
function statusBadge(status){
	var cls = \'eca-dash-status-\' + (status||\'active\');
	var label = status ? status.charAt(0).toUpperCase()+status.slice(1) : \'Active\';
	return \'<span class="eca-dash-status-badge \'+cls+\'">\'+label+\'</span>\';
}

/* ── Utilisation bar helper ────────────────────────────────── */
function utilBar(pct){
	var cls = pct >= 90 ? \'util-red\' : pct >= 70 ? \'util-amber\' : \'util-green\';
	return \'<span class="eca-dash-util-bar"><span class="eca-dash-util-fill \'+cls+\'" style="width:\'+Math.min(pct,100)+\'%"></span></span>\'+pct+\'%\';
}

/* ── Render dashboard ──────────────────────────────────────── */
function renderDashboard(data){
	var k = data.kpis;

	/* KPI cards */
	$(\'#eca-kpi-total-ecas\').text(k.total_ecas);
	var statusParts = [];
	if(k.active_count)    statusParts.push(k.active_count+\' active\');
	if(k.full_count)      statusParts.push(k.full_count+\' full\');
	if(k.inactive_count)  statusParts.push(k.inactive_count+\' inactive\');
	if(k.cancelled_count) statusParts.push(k.cancelled_count+\' cancelled\');
	$(\'#eca-kpi-status-breakdown\').text(statusParts.join(\', \'));

	$(\'#eca-kpi-total-students\').text(k.total_students);
	var pCls = k.participation_rate >= 70 ? \'status-good\' : k.participation_rate >= 40 ? \'status-ok\' : \'status-low\';
	$(\'#eca-kpi-participation-rate\').text(k.participation_rate+\'% participation\').removeClass().addClass(\'eca-dash-kpi-sub \'+pCls);

	$(\'#eca-kpi-total-enrollments\').text(k.total_enrollments);
	$(\'#eca-kpi-avg-per-student\').text(k.avg_ecas_per_student+\' avg per student\');

	$(\'#eca-kpi-attendance-rate\').text(k.attendance_rate > 0 ? k.attendance_rate+\'%\' : \'—\');
	$(\'#eca-kpi-sessions-count\').text(k.attendance_sessions+\' sessions recorded\');

	$(\'#eca-kpi-capacity-rate\').text(k.capacity_rate > 0 ? k.capacity_rate+\'%\' : \'—\');
	var cCls = k.capacity_rate >= 90 ? \'status-low\' : k.capacity_rate >= 60 ? \'status-ok\' : \'status-good\';
	var capLabel = k.total_capacity > 0 ? k.total_enrollments+\' / \'+k.total_capacity+\' spots used\' : k.total_enrollments+\' enrolled (no capacity limits set)\';
	$(\'#eca-kpi-capacity-detail\').text(capLabel).removeClass().addClass(\'eca-dash-kpi-sub \'+cCls);

	$(\'#eca-kpi-waitlisted\').text(k.total_waitlisted);
	$(\'#eca-kpi-waitlist-detail\').text(k.total_waitlisted > 0 ? \'Across all activities\' : \'No waitlists\');

	/* Charts */
	/* Categories doughnut */
	var catLabels = Object.keys(data.categories);
	var catData   = Object.values(data.categories);
	if(catLabels.length){
		buildDoughnut(\'eca-chart-categories\',catLabels,catData);
	}

	/* Types doughnut */
	var typeLabels = Object.keys(data.types);
	var typeData   = Object.values(data.types);
	if(typeLabels.length){
		buildDoughnut(\'eca-chart-types\',typeLabels,typeData);
	}

	/* Status doughnut */
	var sLabels = [];
	var sData   = [];
	var sColors = [];
	if(k.active_count)    { sLabels.push(\'Active\');    sData.push(k.active_count);    sColors.push(\'#2e7d32\'); }
	if(k.full_count)      { sLabels.push(\'Full\');      sData.push(k.full_count);      sColors.push(\'#1565c0\'); }
	if(k.inactive_count)  { sLabels.push(\'Inactive\');  sData.push(k.inactive_count);  sColors.push(\'#e65100\'); }
	if(k.cancelled_count) { sLabels.push(\'Cancelled\'); sData.push(k.cancelled_count); sColors.push(\'#c62828\'); }
	if(sLabels.length){
		buildDoughnut(\'eca-chart-status\',sLabels,sData,sColors);
	}

	/* Top 10 enrollment horizontal bar */
	var top10 = data.ecas.slice(0,10);
	if(top10.length){
		var eLabels = top10.map(function(e){ return e.title.length>25 ? e.title.substring(0,22)+\'…\' : e.title; });
		var eData   = top10.map(function(e){ return e.enrolled; });
		buildHBar(\'eca-chart-enrollment\',eLabels,eData,\'#1565c0\');
	}

	/* Activities table */
	var tbody = $(\'#eca-dash-tbody\').empty();
	if(!data.ecas.length){
		tbody.html(\'<tr><td colspan="9" class="eca-dash-placeholder">\'+wpMcpAiEcaDashboard.strings.noEcas+\'</td></tr>\');
		return;
	}

	$.each(data.ecas,function(_,e){
		var editUrl = wpMcpAiEcaDashboard.ajaxUrl.replace(\'admin-ajax.php\',\'post.php?post=\'+e.id+\'&action=edit\');
		tbody.append(
			\'<tr>\'+
			\'<td><span class="eca-dash-cell-label">Activity</span><a href="\'+editUrl+\'"><strong>\'+e.title+\'</strong></a></td>\'+
			\'<td><span class="eca-dash-cell-label">Status</span>\'+statusBadge(e.status)+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Type</span>\'+e.type+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Day</span>\'+e.day+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Enrolled</span>\'+e.enrolled+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Capacity</span>\'+e.capacity+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Utilisation</span>\'+(e.capacity !== \'∞\' ? utilBar(e.utilisation) : \'—\')+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Waitlist</span>\'+(e.waitlisted > 0 ? \'<strong style="color:#c62828">\'+e.waitlisted+\'</strong>\' : \'0\')+\'</td>\'+
			\'<td><span class="eca-dash-cell-label">Sessions</span>\'+e.sessions+\'</td>\'+
			\'</tr>\'
		);
	});
}

/* ── Main load flow ──────────────────────────────────────────── */
function loadDashboard(){
	$(\'#eca-dash-loading\').show();

	$.ajax({
		url:  wpMcpAiEcaDashboard.ajaxUrl,
		type: \'POST\',
		data: {
			action: \'wp_mcp_ai_eca_dashboard_data\',
			nonce:  wpMcpAiEcaDashboard.nonce
		},
		success: function(res){
			if(res.success && res.data){
				renderDashboard(res.data);
			} else {
				$(\'#eca-dash-tbody\').html(\'<tr><td colspan="9" class="eca-dash-placeholder">\'+wpMcpAiEcaDashboard.strings.noData+\'</td></tr>\');
			}
		},
		error: function(){
			$(\'#eca-dash-tbody\').html(\'<tr><td colspan="9" class="eca-dash-placeholder">\'+wpMcpAiEcaDashboard.strings.error+\'</td></tr>\');
		},
		complete: function(){
			$(\'#eca-dash-loading\').hide();
		}
	});
}

/* ── Initialise on ready ─────────────────────────────────────── */
$(document).ready(function(){
	loadDashboard();
});

})(jQuery);';
	}
}

WP_MCP_AI_ECA_Dashboard_Page::init();
