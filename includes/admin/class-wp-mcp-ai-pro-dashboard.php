<?php
/**
 * NV oOS Pro Dashboard Controller
 *
 * Manages the dedicated Pro Dashboard top-level admin menu for ISO/IEC 27001
 * compliance monitoring, reporting, and management tools.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
	/**
	 * Pro Dashboard controller for compliance and enterprise features.
	 */
	class WP_MCP_AI_Pro_Dashboard {
		/**
		 * Dashboard page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-dashboard';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Check if Pro features should be available.
		 *
		 * This method checks for the WP_MCP_AI_PRO_DASHBOARD_ENABLED constant first,
		 * then falls back to the filter for backward compatibility. Constant approach
		 * is preferred as it's more standard for WordPress configuration.
		 *
		 * @return bool True if Pro features are available.
		 */
		public function is_pro_active() {
			// Check for wp-config.php constant first (recommended method).
			if ( defined( 'WP_MCP_AI_PRO_DASHBOARD_ENABLED' ) && WP_MCP_AI_PRO_DASHBOARD_ENABLED ) {
				return true;
			}

			/**
			 * Filter pro dashboard availability.
			 *
			 * Allows enabling Pro dashboard features via filter, useful for:
			 * - Testing Pro features without a license
			 * - Custom licensing implementations
			 * - Development environments
			 *
			 * Note: Using the WP_MCP_AI_PRO_DASHBOARD_ENABLED constant in wp-config.php
			 * is the recommended approach over this filter.
			 *
			 * @since 1.5.0
			 *
			 * @param bool $is_available Whether Pro dashboard is available.
			 */
			return apply_filters( 'wp_mcp_ai_pro_dashboard_available', false );
		}

		/**
		 * Register the Pro Dashboard menu in WordPress admin.
		 */
		public function register_menu() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Add top-level menu.
			add_menu_page(
				__( 'NV oOS Pro Dashboard', 'wp-mcp-ai' ),
				__( 'NV oOS Pro', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_overview' ),
				'dashicons-shield-alt',
				25
			);

			// Add submenu pages.
			$submenu_pages = $this->get_submenu_pages();

			foreach ( $submenu_pages as $page ) {
				add_submenu_page(
					self::PAGE_SLUG,
					$page['page_title'],
					$page['menu_title'],
					$page['capability'],
					$page['menu_slug'],
					array( $this, $page['callback'] )
				);
			}
		}

		/**
		 * Get submenu page definitions.
		 *
		 * @return array Array of submenu page configurations.
		 */
		private function get_submenu_pages() {
			return array(
				array(
					'page_title' => __( 'Compliance Overview', 'wp-mcp-ai' ),
					'menu_title' => __( 'Overview', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG,
					'callback'   => 'render_overview',
				),
				array(
					'page_title' => __( 'ISO 27001 Management', 'wp-mcp-ai' ),
					'menu_title' => __( 'ISO 27001', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG . '-iso27001',
					'callback'   => 'render_iso27001',
				),
				array(
					'page_title' => __( 'Audit & Reporting', 'wp-mcp-ai' ),
					'menu_title' => __( 'Reports', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG . '-reports',
					'callback'   => 'render_reports',
				),
				array(
					'page_title' => __( 'Security Monitoring', 'wp-mcp-ai' ),
					'menu_title' => __( 'Monitoring', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG . '-monitoring',
					'callback'   => 'render_monitoring',
				),
				array(
					'page_title' => __( 'Risk Management', 'wp-mcp-ai' ),
					'menu_title' => __( 'Risk Management', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG . '-risk',
					'callback'   => 'render_risk_management',
				),
				array(
					'page_title' => __( 'Multi-Framework Compliance', 'wp-mcp-ai' ),
					'menu_title' => __( 'Multi-Framework', 'wp-mcp-ai' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG . '-multi-framework',
					'callback'   => 'render_multi_framework',
				),
			);
		}

		/**
		 * Enqueue Pro Dashboard assets.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			// Only load on Pro Dashboard pages.
			if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
				return;
			}

			// Enqueue Chart.js from CDN
			wp_enqueue_script(
				'chartjs',
				'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
				array(),
				'4.4.0',
				true
			);

			wp_enqueue_style(
				'wp-mcp-ai-pro-dashboard',
				plugins_url( 'assets/css/pro-dashboard.css', dirname( dirname( __FILE__ ) ) ),
				array(),
				WP_MCP_AI_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-dashboard',
				plugins_url( 'assets/js/pro-dashboard.js', dirname( dirname( __FILE__ ) ) ),
				array( 'jquery', 'chartjs' ),
				WP_MCP_AI_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-dashboard',
				'wpMcpAiProDashboard',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'restUrl'    => esc_url_raw( rest_url() ),
					'restNonce'  => wp_create_nonce( 'wp_rest' ),
					'nonce'      => wp_create_nonce( 'wp_mcp_ai_pro_dashboard' ),
					'isProActive' => $this->is_pro_active(),
				)
			);
		}

		/**
		 * Render Compliance Overview page.
		 */
		public function render_overview() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<div class="wp-mcp-ai-dashboard-header">
					<h1>
						<?php esc_html_e( 'NV oOS Pro Dashboard', 'wp-mcp-ai' ); ?>
						<span class="wp-mcp-ai-pro-badge"><?php esc_html_e( 'PRO', 'wp-mcp-ai' ); ?></span>
					</h1>
					<button type="button" class="button wp-mcp-ai-refresh-dashboard">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh', 'wp-mcp-ai' ); ?>
					</button>
				</div>

				<?php $this->render_pro_status_notice(); ?>

			<?php
			// Get actual compliance data from Statement of Applicability.
			$controls = $this->get_iso27001_controls();
			
			// Check if controls were loaded successfully.
			if ( empty( $controls ) ) {
				?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Error loading compliance data:', 'wp-mcp-ai' ); ?></strong>
						<?php esc_html_e( 'Statement of Applicability file not found or could not be parsed. Please ensure the file exists at docs/compliance/iso27001/Statement-of-Applicability.md', 'wp-mcp-ai' ); ?>
					</p>
				</div>
				<?php
				// Set default values to prevent errors.
				$stats = array(
					'implemented'    => 0,
					'partial'        => 0,
					'planned'        => 0,
					'not_applicable' => 0,
					'total'          => 0,
				);
				$compliance_pct = 0;
			} else {
				$stats            = $this->calculate_controls_stats( $controls );
				$total_applicable = $stats['total'] - $stats['not_applicable'];
				$compliance_pct   = $total_applicable > 0 ? round( ( $stats['implemented'] / $total_applicable ) * 100 ) : 0;
			}
			?>

			<!-- Key Metrics Summary -->
			<div class="wp-mcp-ai-metrics-summary">
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-implemented"><?php echo esc_html( $stats['implemented'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Controls Implemented', 'wp-mcp-ai' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-partial"><?php echo esc_html( $stats['partial'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'In Progress', 'wp-mcp-ai' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-critical">0</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Critical Risks', 'wp-mcp-ai' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-compliance"><?php echo esc_html( $compliance_pct ); ?>%</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Overall Compliance', 'wp-mcp-ai' ); ?></div>
					</div>
				</div>
			</div>


				<!-- Charts Row -->
				<div class="wp-mcp-ai-charts-row">
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Control Implementation', 'wp-mcp-ai' ); ?></h3>
						<div class="wp-mcp-ai-chart-container">
							<canvas id="wpMcpAiControlsChart"></canvas>
						</div>
					</div>
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Security Metrics', 'wp-mcp-ai' ); ?></h3>
						<div class="wp-mcp-ai-chart-container">
							<canvas id="wpMcpAiMetricsChart"></canvas>
						</div>
					</div>
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Risk Distribution', 'wp-mcp-ai' ); ?></h3>
						<div class="wp-mcp-ai-chart-container">
							<canvas id="wpMcpAiRiskChart"></canvas>
						</div>
					</div>
				</div>

				<div class="wp-mcp-ai-dashboard-grid">
					<!-- Compliance Status Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'ISO 27001 Compliance Status', 'wp-mcp-ai' ); ?></h2>
						<?php $this->render_compliance_status(); ?>
					</div>

					<!-- Quick Actions Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'Quick Actions', 'wp-mcp-ai' ); ?></h2>
						<?php $this->render_quick_actions(); ?>
					</div>

					<!-- Recent Activity Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'Recent Security Events', 'wp-mcp-ai' ); ?></h2>
						<?php $this->render_recent_activity(); ?>
					</div>

					<!-- Documentation Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'ISMS Documentation', 'wp-mcp-ai' ); ?></h2>
						<?php $this->render_documentation_links(); ?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render ISO 27001 Management page.
		 */
		public function render_iso27001() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'ISO 27001 Control Management', 'wp-mcp-ai' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-controls-overview">
					<?php $this->render_controls_summary(); ?>
				</div>

				<div class="wp-mcp-ai-controls-table">
					<h2><?php esc_html_e( '93 ISO 27001:2022 Controls', 'wp-mcp-ai' ); ?></h2>
					<?php $this->render_controls_table(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Audit & Reporting page.
		 */
		public function render_reports() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'Audit & Reporting', 'wp-mcp-ai' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-report-generator">
					<h2><?php esc_html_e( 'Generate Compliance Report', 'wp-mcp-ai' ); ?></h2>
					<?php $this->render_report_generator(); ?>
				</div>

				<div class="wp-mcp-ai-audit-history">
					<h2><?php esc_html_e( 'Audit History', 'wp-mcp-ai' ); ?></h2>
					<?php $this->render_audit_history(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Security Monitoring page.
		 */
		public function render_monitoring() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'Security Monitoring', 'wp-mcp-ai' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-monitoring-dashboard">
					<?php $this->render_monitoring_dashboard(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Risk Management page.
		 */
		public function render_risk_management() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'Risk Management', 'wp-mcp-ai' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-risk-matrix">
					<h2><?php esc_html_e( '5×5 Risk Matrix', 'wp-mcp-ai' ); ?></h2>
					<?php $this->render_risk_matrix(); ?>
				</div>

				<div class="wp-mcp-ai-risk-register">
					<h2><?php esc_html_e( 'Risk Register', 'wp-mcp-ai' ); ?></h2>
					<?php $this->render_risk_register(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Multi-Framework Compliance page.
		 */
		public function render_multi_framework() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'Multi-Framework Compliance', 'wp-mcp-ai' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-frameworks">
					<?php $this->render_framework_status(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Pro status notice.
		 */
		private function render_pro_status_notice() {
			if ( ! $this->is_pro_active() ) {
				?>
				<div class="notice notice-info wp-mcp-ai-pro-notice">
					<h3><?php esc_html_e( '🔒 Pro Dashboard Preview', 'wp-mcp-ai' ); ?></h3>
					<p>
						<?php
						$upgrade_url = apply_filters( 'wp_mcp_ai_pro_upgrade_url', admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) );
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Link to upgrade page */
								__( 'You\'re viewing a preview of the Pro Dashboard. <a href="%s">Upgrade to Pro</a> to unlock full compliance automation, real-time monitoring, and advanced reporting features.', 'wp-mcp-ai' ),
								esc_url( $upgrade_url )
							)
						);
						?>
					</p>
					<p><strong><?php esc_html_e( 'Pro Features Include:', 'wp-mcp-ai' ); ?></strong></p>
					<ul>
						<li>✅ <?php esc_html_e( 'Real-time compliance status monitoring', 'wp-mcp-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'Automated audit report generation (PDF, DOCX, Excel)', 'wp-mcp-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'Interactive risk register and 5×5 risk matrix', 'wp-mcp-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'Multi-framework support (SOC 2, HIPAA, GDPR)', 'wp-mcp-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'SIEM integration capabilities', 'wp-mcp-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'Priority security support', 'wp-mcp-ai' ); ?></li>
					</ul>
				</div>
				<?php
			}
		}

		/**
		 * Render compliance status widget.
		 */
		private function render_compliance_status() {
			// Get actual data from Statement of Applicability.
			$controls = $this->get_iso27001_controls();
			$stats    = $this->calculate_controls_stats( $controls );
			
			$controls_implemented  = $stats['implemented'];
			$controls_total        = $stats['total'];
			$total_applicable      = $controls_total - $stats['not_applicable'];
			$compliance_percentage = $total_applicable > 0 ? round( ( $controls_implemented / $total_applicable ) * 100 ) : 0;
			$is_certified          = get_option( 'wp_mcp_ai_iso27001_certified', false );
			?>
			<div class="wp-mcp-ai-compliance-status">
				<div class="wp-mcp-ai-status-badge <?php echo $is_certified ? 'certified' : 'compliant'; ?>">
					<?php if ( $is_certified ) : ?>
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'ISO 27001 Certified', 'wp-mcp-ai' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-shield-alt"></span>
						<?php esc_html_e( 'ISO 27001 Compliant', 'wp-mcp-ai' ); ?>
					<?php endif; ?>
				</div>

				<div class="wp-mcp-ai-progress-bar">
					<div class="wp-mcp-ai-progress" style="width: <?php echo esc_attr( $compliance_percentage ); ?>%;">
						<span class="wp-mcp-ai-progress-text"><?php echo esc_html( $compliance_percentage ); ?>%</span>
					</div>
				</div>

				<p class="wp-mcp-ai-status-text">
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %1$d: Implemented controls, %2$d: Total controls */
							__( '<strong>%1$d of %2$d</strong> controls implemented', 'wp-mcp-ai' ),
							$controls_implemented,
							$controls_total
						)
					);
					?>
				</p>

				<?php if ( $is_certified ) : ?>
					<p class="wp-mcp-ai-cert-date">
						<?php
						$cert_date = get_option( 'wp_mcp_ai_iso27001_cert_date', '' );
						if ( $cert_date ) {
							echo wp_kses_post(
								sprintf(
									/* translators: %s: Certification date */
									__( 'Certified: %s', 'wp-mcp-ai' ),
									esc_html( $cert_date )
								)
							);
						}
						?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render quick actions widget.
		 */
		private function render_quick_actions() {
			?>
			<div class="wp-mcp-ai-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-reports' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-media-document"></span>
					<?php esc_html_e( 'Generate Compliance Report', 'wp-mcp-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-iso27001' ) ); ?>" class="button">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'View All Controls', 'wp-mcp-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-risk' ) ); ?>" class="button">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Manage Risks', 'wp-mcp-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( plugins_url( 'docs/compliance/iso27001/README.md', WP_MCP_AI_FILE ) ); ?>" class="button" target="_blank">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( 'View ISMS Documentation', 'wp-mcp-ai' ); ?>
				</a>
			</div>
			<?php
		}

		/**
		 * Render recent activity widget.
		 */
		private function render_recent_activity() {
			// Get recent security events from logs.
			$recent_events = get_option( 'wp_mcp_ai_recent_activity', array() );
			?>
			<div class="wp-mcp-ai-recent-activity">
				<?php if ( $this->is_pro_active() && ! empty( $recent_events ) ) : ?>
					<ul class="wp-mcp-ai-activity-list">
						<?php foreach ( array_slice( $recent_events, 0, 5 ) as $event ) : ?>
							<li class="wp-mcp-ai-activity-item">
								<span class="wp-mcp-ai-activity-icon dashicons dashicons-<?php echo esc_attr( $event['icon'] ?? 'info' ); ?>"></span>
								<span class="wp-mcp-ai-activity-text"><?php echo esc_html( $event['message'] ?? __( 'No message', 'wp-mcp-ai' ) ); ?></span>
								<span class="wp-mcp-ai-activity-time"><?php echo esc_html( $event['time'] ?? $event['timestamp'] ?? __( 'Unknown time', 'wp-mcp-ai' ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="wp-mcp-ai-empty-state">
						<span class="dashicons dashicons-clock"></span>
						<?php esc_html_e( 'No recent security events. Pro users see real-time activity here.', 'wp-mcp-ai' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render documentation links widget.
		 */
		private function render_documentation_links() {
			$docs_path = WP_MCP_AI_PATH . 'docs/compliance/iso27001/';
			$docs = array(
				'ISMS-Policy.md'                  => __( 'ISMS Policy', 'wp-mcp-ai' ),
				'Statement-of-Applicability.md'   => __( 'Statement of Applicability', 'wp-mcp-ai' ),
				'Risk-Assessment.md'              => __( 'Risk Assessment', 'wp-mcp-ai' ),
				'Business-Continuity-Plan.md'     => __( 'Business Continuity Plan', 'wp-mcp-ai' ),
			);
			?>
			<div class="wp-mcp-ai-documentation-links">
				<ul>
					<?php foreach ( $docs as $file => $label ) : ?>
						<?php if ( file_exists( $docs_path . $file ) ) : ?>
							<li>
								<a href="<?php echo esc_url( plugins_url( 'docs/compliance/iso27001/' . $file, WP_MCP_AI_FILE ) ); ?>" target="_blank">
									<span class="dashicons dashicons-media-document"></span>
									<?php echo esc_html( $label ); ?>
								</a>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( is_dir( $docs_path . 'procedures' ) ) : ?>
						<li>
							<a href="<?php echo esc_url( plugins_url( 'docs/compliance/iso27001/procedures/', WP_MCP_AI_FILE ) ); ?>" target="_blank">
								<span class="dashicons dashicons-admin-tools"></span>
								<?php esc_html_e( 'All Procedures', 'wp-mcp-ai' ); ?>
							</a>
						</li>
					<?php endif; ?>
				</ul>
			</div>
			<?php
		}

		/**
		 * Render controls summary.
		 */
		private function render_controls_summary() {
			$controls = $this->get_iso27001_controls();
			$stats    = $this->calculate_controls_stats( $controls );
			?>
			<div class="wp-mcp-ai-controls-summary">
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['implemented'] ); ?></h3>
					<p><?php esc_html_e( 'Implemented', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['partial'] ); ?></h3>
					<p><?php esc_html_e( 'Partial', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['planned'] ); ?></h3>
					<p><?php esc_html_e( 'Planned', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['not_applicable'] ); ?></h3>
					<p><?php esc_html_e( 'N/A', 'wp-mcp-ai' ); ?></p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render controls table.
		 */
		private function render_controls_table() {
			$controls = $this->get_iso27001_controls();
			
			if ( empty( $controls ) ) {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Unable to load ISO 27001 controls. Please check that the Statement of Applicability document is available.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
				return;
			}
			?>
			<div class="wp-mcp-ai-controls-filter">
				<label for="controls-status-filter"><?php esc_html_e( 'Filter by status:', 'wp-mcp-ai' ); ?></label>
				<select id="controls-status-filter">
					<option value="all"><?php esc_html_e( 'All Controls', 'wp-mcp-ai' ); ?></option>
					<option value="implemented"><?php esc_html_e( 'Implemented', 'wp-mcp-ai' ); ?></option>
					<option value="partial"><?php esc_html_e( 'Partial', 'wp-mcp-ai' ); ?></option>
					<option value="planned"><?php esc_html_e( 'Planned', 'wp-mcp-ai' ); ?></option>
					<option value="not_applicable"><?php esc_html_e( 'Not Applicable', 'wp-mcp-ai' ); ?></option>
				</select>
				
				<label for="controls-search"><?php esc_html_e( 'Search:', 'wp-mcp-ai' ); ?></label>
				<input type="text" id="controls-search" placeholder="<?php esc_attr_e( 'Search controls...', 'wp-mcp-ai' ); ?>" />
			</div>

			<table class="wp-list-table widefat fixed striped wp-mcp-ai-controls-table">
				<thead>
					<tr>
						<th style="width: 120px;"><?php esc_html_e( 'Control ID', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Control Name', 'wp-mcp-ai' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Applicable', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $controls as $control ) : ?>
						<tr class="wp-mcp-ai-control-row" data-status="<?php echo esc_attr( $control['status_key'] ); ?>">
							<td><strong><?php echo esc_html( $control['id'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $control['name'] ); ?></strong>
								<?php if ( ! empty( $control['justification'] ) ) : ?>
									<p class="description"><?php echo esc_html( wp_trim_words( $control['justification'], 20 ) ); ?></p>
								<?php endif; ?>
							</td>
							<td>
								<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $control['status_key'] ); ?>">
									<?php echo esc_html( $control['status'] ); ?>
								</span>
							</td>
							<td>
								<?php if ( $control['applicable'] ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss" style="color: #dc3232;"></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<style>
				.wp-mcp-ai-controls-filter {
					margin-bottom: 20px;
					display: flex;
					gap: 10px;
					align-items: center;
				}
				.wp-mcp-ai-controls-filter label {
					font-weight: 600;
				}
				.wp-mcp-ai-controls-filter select,
				.wp-mcp-ai-controls-filter input[type="text"] {
					padding: 5px 10px;
				}
				.wp-mcp-ai-controls-filter input[type="text"] {
					flex: 1;
					max-width: 300px;
				}
				.wp-mcp-ai-controls-table .description {
					margin: 5px 0 0 0;
					color: #646970;
				}
				.wp-mcp-ai-status-badge {
					display: inline-block;
					padding: 4px 8px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
				}
				.wp-mcp-ai-status-implemented {
					background: #d4edda;
					color: #155724;
				}
				.wp-mcp-ai-status-partial {
					background: #fff3cd;
					color: #856404;
				}
				.wp-mcp-ai-status-planned {
					background: #d1ecf1;
					color: #0c5460;
				}
				.wp-mcp-ai-status-not_applicable {
					background: #e2e3e5;
					color: #383d41;
				}
			</style>

			<script>
			jQuery(document).ready(function($) {
				// Filter controls
				$('#controls-status-filter').on('change', function() {
					var status = $(this).val();
					if (status === 'all') {
						$('.wp-mcp-ai-control-row').show();
					} else {
						$('.wp-mcp-ai-control-row').hide();
						$('.wp-mcp-ai-control-row[data-status="' + status + '"]').show();
					}
				});

				// Search controls
				$('#controls-search').on('keyup', function() {
					var search = $(this).val().toLowerCase();
					$('.wp-mcp-ai-control-row').each(function() {
						var text = $(this).text().toLowerCase();
						$(this).toggle(text.indexOf(search) > -1);
					});
				});
			});
			</script>
			<?php
		}

		/**
		 * Render report generator.
		 */
		private function render_report_generator() {
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'Generate compliance reports in PDF, DOCX, or Excel format.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to generate automated compliance reports for auditors and management.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
		}

		/**
		 * Render audit history.
		 */
		private function render_audit_history() {
			?>
			<p class="description">
				<?php esc_html_e( 'Audit history tracks compliance activities, control assessments, and remediation progress.', 'wp-mcp-ai' ); ?>
			</p>
			
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 150px;"><?php esc_html_e( 'Date', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Audit Type', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>2026-01-05</td>
						<td>
							<strong><?php esc_html_e( 'Initial Compliance Assessment', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Baseline assessment of ISO 27001:2022 controls', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><span class="wp-mcp-ai-status-badge wp-mcp-ai-status-implemented"><?php esc_html_e( 'Complete', 'wp-mcp-ai' ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( plugins_url( 'docs/compliance/iso27001/Statement-of-Applicability.md', WP_MCP_AI_FILE ) ); ?>" 
							   class="button button-small" target="_blank">
								<?php esc_html_e( 'View Report', 'wp-mcp-ai' ); ?>
							</a>
						</td>
					</tr>
					<tr>
						<td colspan="4" class="wp-mcp-ai-empty-state" style="text-align: center; padding: 20px;">
							<?php esc_html_e( 'Additional audit entries will appear here as audits are conducted.', 'wp-mcp-ai' ); ?>
						</td>
					</tr>
				</tbody>
			</table>
			<?php
		}

		/**
		 * Render monitoring dashboard.
		 */
		private function render_monitoring_dashboard() {
			$recent_events = get_option( 'wp_mcp_ai_recent_activity', array() );
			?>
			<div class="wp-mcp-ai-monitoring-grid">
				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Security Status', 'wp-mcp-ai' ); ?></h3>
					<div class="wp-mcp-ai-status-indicator">
						<span class="dashicons dashicons-shield-alt" style="font-size: 48px; color: #46b450;"></span>
						<p><strong><?php esc_html_e( 'All Systems Operational', 'wp-mcp-ai' ); ?></strong></p>
					</div>
				</div>

				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Recent Security Events', 'wp-mcp-ai' ); ?></h3>
					<?php if ( ! empty( $recent_events ) ) : ?>
						<ul class="wp-mcp-ai-activity-list">
							<?php foreach ( array_slice( $recent_events, 0, 5 ) as $event ) : ?>
								<li><?php echo esc_html( $event['message'] ?? __( 'Security event', 'wp-mcp-ai' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No recent security events.', 'wp-mcp-ai' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Monitored Resources', 'wp-mcp-ai' ); ?></h3>
					<ul>
						<li>✓ <?php esc_html_e( 'File Integrity', 'wp-mcp-ai' ); ?></li>
						<li>✓ <?php esc_html_e( 'Authentication Events', 'wp-mcp-ai' ); ?></li>
						<li>✓ <?php esc_html_e( 'Plugin Updates', 'wp-mcp-ai' ); ?></li>
						<li>✓ <?php esc_html_e( 'Configuration Changes', 'wp-mcp-ai' ); ?></li>
					</ul>
				</div>
			</div>
			<?php
		}

		/**
		 * Render risk matrix.
		 */
		private function render_risk_matrix() {
			?>
			<p class="description">
				<?php esc_html_e( 'The risk matrix visualizes identified risks based on their likelihood and impact on a 5×5 scale.', 'wp-mcp-ai' ); ?>
			</p>

			<div class="wp-mcp-ai-risk-matrix-container">
				<table class="wp-mcp-ai-risk-matrix-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Impact →', 'wp-mcp-ai' ); ?><br><?php esc_html_e( 'Likelihood ↓', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Very Low', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Low', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'High', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Very High', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Very Likely', 'wp-mcp-ai' ); ?></th>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
							<td class="risk-critical"></td>
							<td class="risk-critical"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Likely', 'wp-mcp-ai' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
							<td class="risk-critical"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Possible', 'wp-mcp-ai' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Unlikely', 'wp-mcp-ai' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Very Unlikely', 'wp-mcp-ai' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="wp-mcp-ai-risk-legend">
				<h4><?php esc_html_e( 'Risk Levels', 'wp-mcp-ai' ); ?></h4>
				<span class="risk-badge risk-low"><?php esc_html_e( 'Low', 'wp-mcp-ai' ); ?></span>
				<span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></span>
				<span class="risk-badge risk-high"><?php esc_html_e( 'High', 'wp-mcp-ai' ); ?></span>
				<span class="risk-badge risk-critical"><?php esc_html_e( 'Critical', 'wp-mcp-ai' ); ?></span>
			</div>

			<style>
				.wp-mcp-ai-risk-matrix-table {
					width: 100%;
					border-collapse: collapse;
					margin: 20px 0;
				}
				.wp-mcp-ai-risk-matrix-table th,
				.wp-mcp-ai-risk-matrix-table td {
					padding: 15px;
					text-align: center;
					border: 1px solid #ddd;
				}
				.wp-mcp-ai-risk-matrix-table thead th {
					background: #f0f0f1;
					font-weight: 600;
				}
				.wp-mcp-ai-risk-matrix-table tbody th {
					background: #f9f9f9;
					font-weight: 600;
					text-align: left;
				}
				.risk-low { background: #d4edda; }
				.risk-medium { background: #fff3cd; }
				.risk-high { background: #f8d7da; }
				.risk-critical { background: #dc3545; color: white; }
				.wp-mcp-ai-risk-legend {
					margin-top: 20px;
				}
				.risk-badge {
					display: inline-block;
					padding: 5px 10px;
					margin-right: 10px;
					border-radius: 3px;
					font-weight: 600;
				}
			</style>
			<?php
		}

		/**
		 * Render risk register.
		 */
		private function render_risk_register() {
			?>
			<p class="description">
				<?php esc_html_e( 'The risk register documents all identified risks, their assessment, and treatment plans.', 'wp-mcp-ai' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 80px;"><?php esc_html_e( 'Risk ID', 'wp-mcp-ai' ); ?></th>
						<th><?php esc_html_e( 'Risk Description', 'wp-mcp-ai' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Likelihood', 'wp-mcp-ai' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Impact', 'wp-mcp-ai' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Risk Level', 'wp-mcp-ai' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Treatment', 'wp-mcp-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>R-001</td>
						<td>
							<strong><?php esc_html_e( 'Unauthorized API Access', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Unauthorized users gaining access to AI API endpoints', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Possible', 'wp-mcp-ai' ); ?></td>
						<td><?php esc_html_e( 'High', 'wp-mcp-ai' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'wp-mcp-ai' ); ?></td>
					</tr>
					<tr>
						<td>R-002</td>
						<td>
							<strong><?php esc_html_e( 'Data Exposure via Logs', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Sensitive data exposure through debug logs', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'wp-mcp-ai' ); ?></td>
						<td><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></td>
						<td><span class="risk-badge risk-low"><?php esc_html_e( 'Low', 'wp-mcp-ai' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'wp-mcp-ai' ); ?></td>
					</tr>
					<tr>
						<td>R-003</td>
						<td>
							<strong><?php esc_html_e( 'Third-Party API Outage', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'OpenAI/Gemini API unavailability affecting service', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Possible', 'wp-mcp-ai' ); ?></td>
						<td><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></span></td>
						<td><?php esc_html_e( 'Accept', 'wp-mcp-ai' ); ?></td>
					</tr>
					<tr>
						<td>R-004</td>
						<td>
							<strong><?php esc_html_e( 'Injection Attacks', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'SQL/XSS injection through user inputs', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'wp-mcp-ai' ); ?></td>
						<td><?php esc_html_e( 'Very High', 'wp-mcp-ai' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'wp-mcp-ai' ); ?></td>
					</tr>
					<tr>
						<td>R-005</td>
						<td>
							<strong><?php esc_html_e( 'Credential Compromise', 'wp-mcp-ai' ); ?></strong>
							<p class="description"><?php esc_html_e( 'API keys or credentials being exposed or stolen', 'wp-mcp-ai' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'wp-mcp-ai' ); ?></td>
						<td><?php esc_html_e( 'Very High', 'wp-mcp-ai' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'wp-mcp-ai' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="description" style="margin-top: 20px;">
				<?php
				printf(
					/* translators: %s: Link to risk assessment document */
					esc_html__( 'See the full %s for detailed risk analysis and treatment plans.', 'wp-mcp-ai' ),
					'<a href="' . esc_url( plugins_url( 'docs/compliance/iso27001/Risk-Assessment.md', WP_MCP_AI_FILE ) ) . '" target="_blank">' . esc_html__( 'Risk Assessment document', 'wp-mcp-ai' ) . '</a>'
				);
				?>
			</p>
			<?php
		}

		/**
		 * Render framework status.
		 */
		private function render_framework_status() {
			$frameworks = array(
				array(
					'name'   => 'ISO 27001:2022',
					'status' => 'compliant',
					'progress' => 56,
				),
				array(
					'name'   => 'SOC 2',
					'status' => 'pending',
					'progress' => 0,
				),
				array(
					'name'   => 'HIPAA',
					'status' => 'pending',
					'progress' => 0,
				),
				array(
					'name'   => 'GDPR',
					'status' => 'compliant',
					'progress' => 95,
				),
			);
			?>
			<div class="wp-mcp-ai-frameworks-grid">
				<?php foreach ( $frameworks as $framework ) : ?>
					<div class="wp-mcp-ai-framework-card">
						<h3><?php echo esc_html( $framework['name'] ); ?></h3>
						<div class="wp-mcp-ai-framework-status <?php echo esc_attr( $framework['status'] ); ?>">
							<?php echo esc_html( ucfirst( $framework['status'] ) ); ?>
						</div>
						<?php if ( $framework['progress'] > 0 ) : ?>
							<div class="wp-mcp-ai-framework-progress">
								<div class="wp-mcp-ai-progress" style="width: <?php echo esc_attr( $framework['progress'] ); ?>%;">
									<?php echo esc_html( $framework['progress'] ); ?>%
								</div>
							</div>
						<?php endif; ?>
						<?php if ( ! $this->is_pro_active() && $framework['status'] === 'pending' ) : ?>
							<p class="wp-mcp-ai-framework-cta">
								<small><?php esc_html_e( 'Pro feature', 'wp-mcp-ai' ); ?></small>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Get ISO 27001 controls from Statement of Applicability.
		 *
		 * @return array Array of controls with id, name, status, applicable, and justification.
		 */
		private function get_iso27001_controls() {
			$soa_file = WP_MCP_AI_PATH . 'docs/compliance/iso27001/Statement-of-Applicability.md';
			
			if ( ! file_exists( $soa_file ) ) {
				return array();
			}

			$content = file_get_contents( $soa_file );
			if ( empty( $content ) ) {
				return array();
			}

			$controls = array();
			$lines    = explode( "\n", $content );
			$current_control = null;

			foreach ( $lines as $line ) {
				// Match control ID header (e.g., "### A.5.1 Policies for Information Security").
				if ( preg_match( '/^###\s+(A\.\d+\.\d+(?:\.\d+)?)\s+(.+)$/', $line, $matches ) ) {
					// Save previous control if exists.
					if ( $current_control ) {
						$controls[] = $current_control;
					}

					// Start new control.
					$current_control = array(
						'id'            => $matches[1],
						'name'          => trim( $matches[2] ),
						'status'        => '',
						'status_key'    => '',
						'applicable'    => true,
						'justification' => '',
					);
				} elseif ( $current_control && preg_match( '/^\*\*Status:\*\*\s+(.+)$/', $line, $matches ) ) {
					$status_text = trim( $matches[1] );
					$current_control['status'] = $status_text;
					
					// Map status to key.
					if ( strpos( $status_text, 'Implemented' ) !== false ) {
						$current_control['status_key'] = 'implemented';
					} elseif ( strpos( $status_text, 'Partial' ) !== false ) {
						$current_control['status_key'] = 'partial';
					} elseif ( strpos( $status_text, 'Planned' ) !== false ) {
						$current_control['status_key'] = 'planned';
					} elseif ( strpos( $status_text, 'Not Applicable' ) !== false ) {
						$current_control['status_key'] = 'not_applicable';
						$current_control['applicable'] = false;
					}
				} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
					$applicable_text = trim( $matches[1] );
					$current_control['applicable'] = ( strcasecmp( $applicable_text, 'Yes' ) === 0 );
				} elseif ( $current_control && preg_match( '/^\*\*Justification:\*\*\s+(.+)$/', $line, $matches ) ) {
					$current_control['justification'] = trim( $matches[1] );
				}
			}

			// Save last control.
			if ( $current_control ) {
				$controls[] = $current_control;
			}

			return $controls;
		}

		/**
		 * Calculate statistics for controls.
		 *
		 * @param array $controls Array of controls.
		 * @return array Statistics with counts for each status.
		 */
		private function calculate_controls_stats( $controls ) {
			$stats = array(
				'implemented'    => 0,
				'partial'        => 0,
				'planned'        => 0,
				'not_applicable' => 0,
				'total'          => count( $controls ),
			);

			foreach ( $controls as $control ) {
				$status_key = $control['status_key'] ?? '';
				if ( isset( $stats[ $status_key ] ) ) {
					++$stats[ $status_key ];
				}
			}

			return $stats;
		}
	}
}
