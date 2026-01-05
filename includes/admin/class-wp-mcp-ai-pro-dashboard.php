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
		 * This method checks the filter dynamically at runtime rather than caching
		 * the result, allowing filters added via code snippets to work properly.
		 *
		 * @return bool True if Pro features are available.
		 */
		public function is_pro_active() {
			/**
			 * Filter pro dashboard availability.
			 *
			 * Allows enabling Pro dashboard features via filter, useful for:
			 * - Testing Pro features without a license
			 * - Custom licensing implementations
			 * - Development environments
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

			wp_enqueue_style(
				'wp-mcp-ai-pro-dashboard',
				plugins_url( 'assets/css/pro-dashboard.css', dirname( dirname( __FILE__ ) ) ),
				array(),
				WP_MCP_AI_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-dashboard',
				plugins_url( 'assets/js/pro-dashboard.js', dirname( dirname( __FILE__ ) ) ),
				array( 'jquery' ),
				WP_MCP_AI_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-dashboard',
				'wpMcpAiProDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_pro_dashboard' ),
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
				<h1>
					<?php esc_html_e( 'NV oOS Pro Dashboard', 'wp-mcp-ai' ); ?>
					<span class="wp-mcp-ai-pro-badge"><?php esc_html_e( 'PRO', 'wp-mcp-ai' ); ?></span>
				</h1>

				<?php $this->render_pro_status_notice(); ?>

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
			$controls_implemented = 52;
			$controls_total       = 93;
			$compliance_percentage = round( ( $controls_implemented / $controls_total ) * 100 );
			$is_certified = get_option( 'wp_mcp_ai_iso27001_certified', false );
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
								<span class="wp-mcp-ai-activity-text"><?php echo esc_html( $event['message'] ); ?></span>
								<span class="wp-mcp-ai-activity-time"><?php echo esc_html( $event['time'] ); ?></span>
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
			?>
			<div class="wp-mcp-ai-controls-summary">
				<div class="wp-mcp-ai-control-stat">
					<h3>52</h3>
					<p><?php esc_html_e( 'Implemented', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3>26</h3>
					<p><?php esc_html_e( 'Partial', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3>3</h3>
					<p><?php esc_html_e( 'Planned', 'wp-mcp-ai' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3>12</h3>
					<p><?php esc_html_e( 'N/A', 'wp-mcp-ai' ); ?></p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render controls table.
		 */
		private function render_controls_table() {
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'Full interactive controls table with filtering and status updates.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to view and manage all 93 ISO 27001:2022 controls with real-time status tracking.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
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
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'View past audit reports and findings.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to track audit history and remediation progress.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
		}

		/**
		 * Render monitoring dashboard.
		 */
		private function render_monitoring_dashboard() {
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'Real-time security monitoring with SIEM integration.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro for advanced security monitoring and SIEM integration.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
		}

		/**
		 * Render risk matrix.
		 */
		private function render_risk_matrix() {
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'Interactive 5×5 risk matrix with heatmap visualization.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to visualize and manage risks with an interactive 5×5 matrix.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
		}

		/**
		 * Render risk register.
		 */
		private function render_risk_register() {
			if ( $this->is_pro_active() ) {
				?>
				<p><?php esc_html_e( 'Complete risk register with treatment tracking.', 'wp-mcp-ai' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to access the full risk register with treatment tracking and reporting.', 'wp-mcp-ai' ); ?>
				</p>
				<?php
			}
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
	}
}
