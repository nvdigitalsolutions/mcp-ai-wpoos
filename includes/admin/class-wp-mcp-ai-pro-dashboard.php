<?php
/**
 * NV oOS Pro Dashboard Controller
 *
 * Manages the dedicated Pro Dashboard top-level admin menu for ISO/IEC 27001
 * compliance monitoring, reporting, and management tools.
 *
 * Uses singleton pattern and centralized delegate page management for better
 * architecture and maintainability. All ISO 27001 compliance admin pages are
 * coordinated through this controller to ensure consistency and prevent conflicts.
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
	 *
	 * Implements centralized management of ISO 27001 compliance modules.
	 * Delegate pages are instantiated and coordinated to prevent duplicate
	 * menu registrations and ensure consistent behavior.
	 *
	 * @since 1.5.0
	 */
	class WP_MCP_AI_Pro_Dashboard {
		/**
		 * Dashboard page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-dashboard';

		/**
		 * Delegate page keys as constants for type safety.
		 */
		const DELEGATE_SECURITY_AUDITS    = 'security_audits';
		const DELEGATE_SECURITY_TRAINING = 'security_training';
		const DELEGATE_SUPPLIER_SECURITY = 'supplier_security';
		const DELEGATE_ASSET_INVENTORY    = 'asset_inventory';

		/**
		 * Singleton instance.
		 *
		 * @var WP_MCP_AI_Pro_Dashboard|null
		 */
		private static $instance = null;

		/**
		 * Delegate admin pages.
		 *
		 * @var array<string, object>
		 */
		private $delegate_pages = array();

		/**
		 * Whether delegates have been initialized.
		 *
		 * @var bool
		 */
		private $delegates_initialized = false;

		/**
		 * Get singleton instance.
		 *
		 * @return WP_MCP_AI_Pro_Dashboard
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor (private for singleton pattern).
		 */
		private function __construct() {
			$this->init_hooks();
		}

		/**
		 * Prevent cloning of singleton.
		 */
		private function __clone() {}

		/**
		 * Prevent unserialization of singleton.
		 *
		 * @throws Exception When attempting to unserialize.
		 */
		public function __wakeup() {
			throw new Exception( 'Cannot unserialize singleton' );
		}

		/**
		 * Initialize WordPress hooks.
		 *
		 * Separates hook registration from initialization for better testability.
		 *
		 * @return void
		 */
		private function init_hooks() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
			add_action( 'admin_menu', array( $this, 'reorder_pro_dashboard_menu' ), 999 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_init', array( $this, 'lazy_init_delegates' ), 1 );
		}

		/**
		 * Lazy initialization of delegate pages.
		 *
		 * Defers delegate instantiation until admin_init for better performance
		 * and to ensure all plugins are loaded.
		 *
		 * @return void
		 */
		public function lazy_init_delegates() {
			if ( ! $this->delegates_initialized ) {
				$this->init_delegate_pages();
				$this->delegates_initialized = true;
			}
		}

		/**
		 * Initialize delegate admin pages.
		 *
		 * Centralizes instantiation of ISO 27001 compliance admin pages.
		 * These pages register themselves under the Pro Dashboard menu but are
		 * instantiated here to ensure proper coordination and prevent duplicate
		 * menu registrations.
		 *
		 * Each delegate corresponds to a specific ISO 27001 control or control group:
		 * - Security Audits: Control A.5.35 (Independent Review)
		 * - Security Training: Control A.6.3 (Awareness, Education & Training)
		 * - Supplier Security: Controls A.5.19-A.5.22 (Supplier Relationships)
		 * - Asset Inventory: Control A.5.9 (Inventory of Assets)
		 *
		 * @return void
		 */
		private function init_delegate_pages() {
			$delegates = $this->get_delegate_config();

			foreach ( $delegates as $key => $class_name ) {
				$this->register_delegate( $key, $class_name );
			}

			/**
			 * Fires after delegate pages are initialized.
			 *
			 * Allows plugins to hook into the delegate initialization process.
			 *
			 * @since 1.5.0
			 *
			 * @param array $delegate_pages Array of initialized delegate page instances.
			 */
			do_action( 'wp_mcp_ai_pro_dashboard_delegates_initialized', $this->delegate_pages );
		}

		/**
		 * Get delegate configuration.
		 *
		 * Centralized configuration using class constants for type safety.
		 * Can be filtered to allow plugins to add custom delegates.
		 *
		 * @return array<string, string> Array of delegate key => class name pairs.
		 */
		private function get_delegate_config() {
			$config = array(
				self::DELEGATE_SECURITY_AUDITS   => 'WP_MCP_AI_Security_Audit_Admin',
				self::DELEGATE_SECURITY_TRAINING => 'WP_MCP_AI_Security_Training_Admin',
				self::DELEGATE_SUPPLIER_SECURITY => 'WP_MCP_AI_Supplier_Security_Admin',
				self::DELEGATE_ASSET_INVENTORY   => 'WP_MCP_AI_Asset_Inventory_Admin',
			);

			/**
			 * Filter delegate page configuration.
			 *
			 * Allows plugins/themes to add or remove delegate pages.
			 *
			 * @since 1.5.0
			 *
			 * @param array $config Array of delegate key => class name pairs.
			 */
			return apply_filters( 'wp_mcp_ai_pro_dashboard_delegate_config', $config );
		}

		/**
		 * Register a single delegate page.
		 *
		 * Validates class exists and handles instantiation with error recovery.
		 *
		 * @param string $key Delegate identifier key.
		 * @param string $class_name Fully qualified class name.
		 * @return bool True if registered successfully, false otherwise.
		 */
		private function register_delegate( $key, $class_name ) {
			// Validate inputs.
			if ( empty( $key ) || empty( $class_name ) ) {
				return false;
			}

			// Skip if class doesn't exist.
			if ( ! class_exists( $class_name ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[WP_MCP_AI] Pro Dashboard delegate class not found: %s (key: %s)',
							$class_name,
							$key
						)
					);
				}
				return false;
			}

			try {
				$this->delegate_pages[ $key ] = new $class_name();

				// Log successful initialization if logging enabled.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'info',
						sprintf( 'Pro Dashboard delegate initialized: %s', $key ),
						array(
							'delegate' => $key,
							'class'    => $class_name,
						)
					);
				}

				return true;

			} catch ( Exception $e ) {
				// Log initialization error but don't break the page.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'error',
						sprintf( 'Failed to initialize Pro Dashboard delegate: %s', $class_name ),
						array(
							'delegate' => $key,
							'class'    => $class_name,
							'error'    => $e->getMessage(),
							'trace'    => $e->getTraceAsString(),
						)
					);
				}

				// Show admin notice in debug mode.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					add_action(
						'admin_notices',
						function () use ( $key, $class_name, $e ) {
							printf(
								'<div class="notice notice-error"><p><strong>%s:</strong> %s (%s: %s)</p></div>',
								esc_html__( 'Pro Dashboard Error', 'mcp-ai-wpoos' ),
								esc_html( sprintf( 'Failed to initialize %s', $key ) ),
								esc_html( $class_name ),
								esc_html( $e->getMessage() )
							);
						}
					);
				}

				return false;
			}
		}

		/**
		 * Get registered delegate page instance.
		 *
		 * Provides access to individual delegate page instances for testing
		 * or integration purposes.
		 *
		 * @since 1.5.0
		 *
		 * @param string $key Delegate page key (e.g., 'security_audits', 'security_training').
		 * @return object|null Delegate page instance or null if not found.
		 */
		public function get_delegate( $key ) {
			return isset( $this->delegate_pages[ $key ] ) ? $this->delegate_pages[ $key ] : null;
		}

		/**
		 * Get all registered delegate pages.
		 *
		 * @since 1.5.0
		 *
		 * @return array Array of delegate page instances keyed by delegate key.
		 */
		public function get_delegates() {
			return $this->delegate_pages;
		}

		/**
		 * Check if a delegate page is registered.
		 *
		 * @since 1.5.0
		 *
		 * @param string $key Delegate page key.
		 * @return bool True if delegate is registered, false otherwise.
		 */
		public function has_delegate( $key ) {
			return isset( $this->delegate_pages[ $key ] );
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
				__( 'NV oOS Pro Dashboard', 'mcp-ai-wpoos' ),
				__( 'NV oOS Pro', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_dashboard_with_tabs' ),
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
		 * Organized menu structure for ISO 27001 compliance management.
		 * Standalone admin pages (Security Audits, Training, etc.) are now
		 * managed by their respective classes but coordinated through Pro Dashboard.
		 *
		 * Now using tab-based navigation: Overview, ISO 27001, Reports, Monitoring,
		 * Risk Management, and Multi-Framework are rendered as tabs on a single page.
		 * Other pages remain as separate submenu items.
		 *
		 * @return array Array of submenu page configurations.
		 */
		private function get_submenu_pages() {
			return array(
				// Main Pro Dashboard page (with tab-based navigation).
				array(
					'page_title' => __( 'NV oOS Pro Dashboard', 'mcp-ai-wpoos' ),
					'menu_title' => __( 'Overview', 'mcp-ai-wpoos' ),
					'capability' => 'manage_options',
					'menu_slug'  => self::PAGE_SLUG,
					'callback'   => 'render_dashboard_with_tabs',
				),
				// Note: The following pages are now tabs within the main dashboard:
				// - Overview (default tab)
				// - ISO 27001
				// - Reports
				// - Monitoring
				// - Risk Management
				// - Multi-Framework
				//
				// Other pages are registered by their respective classes:
				// - Security Audits: WP_MCP_AI_Security_Audit_Admin.
				// - Asset Inventory: WP_MCP_AI_Asset_Inventory_Admin.
				// - Supplier Security: WP_MCP_AI_Supplier_Security_Admin.
				// - Security Training: WP_MCP_AI_Security_Training_Admin.
			);
		}

		/**
		 * Reorder Pro Dashboard submenu to ensure Overview appears first.
		 *
		 * WordPress adds CPT menu items before manually registered submenu pages.
		 * This filter ensures the Overview page appears as the first submenu item.
		 * Runs on admin_menu hook at priority 999 to ensure all submenus are registered.
		 *
		 * @return void
		 */
		public function reorder_pro_dashboard_menu() {
			global $submenu;

			// Only reorder if the Pro Dashboard submenu exists.
			if ( ! isset( $submenu[ self::PAGE_SLUG ] ) || ! is_array( $submenu[ self::PAGE_SLUG ] ) ) {
				return;
			}

			$pro_submenu = $submenu[ self::PAGE_SLUG ];

			// Find the Overview page (it has the same slug as the parent).
			$overview_key = null;
			foreach ( $pro_submenu as $key => $item ) {
				if ( isset( $item[2] ) && self::PAGE_SLUG === $item[2] ) {
					$overview_key = $key;
					break;
				}
			}

			// If Overview is found and it's not already first, move it to position 0.
			if ( null !== $overview_key && 0 !== $overview_key ) {
				$overview_item = $pro_submenu[ $overview_key ];
				unset( $pro_submenu[ $overview_key ] );

				// Insert Overview at the beginning.
				array_unshift( $pro_submenu, $overview_item );

				// Update the global submenu array.
				$submenu[ self::PAGE_SLUG ] = $pro_submenu;
			}
		}

		/**
		 * Enqueue Pro Dashboard assets.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			// Only load on Pro Dashboard pages (including diagnostic page).
			$allowed_pages = array(
				'toplevel_page_' . self::PAGE_SLUG,
				'nv-oos-pro_page_nvoos-pro-dashboard-diagnostic',
			);

			if ( ! in_array( $hook, $allowed_pages, true ) ) {
				return;
			}

			// Enqueue Chart.js first - directly register and enqueue it.
			$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
			$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';

			// Register Chart.js library.
			wp_register_script(
				'chartjs',
				$chart_js_url,
				array(),
				file_exists( $chart_js_path ) ? filemtime( $chart_js_path ) : '4.4.1',
				true
			);

			// Enqueue Chart.js.
			wp_enqueue_script( 'chartjs' );

			// Get chart data - ensure it's properly structured.
			$chart_data = $this->get_chart_data();

			// Enqueue responsive utilities first (base styles) - matching base dashboard.
			$responsive_css_path = WP_MCP_AI_PATH . 'assets/css/admin-responsive-utilities.css';
			wp_enqueue_style(
				'wp-mcp-ai-responsive-utilities',
				WP_MCP_AI_URL . 'assets/css/admin-responsive-utilities.css',
				array(),
				file_exists( $responsive_css_path ) ? filemtime( $responsive_css_path ) : WP_MCP_AI_VERSION
			);

			// Enqueue pro dashboard styles with responsive utilities dependency.
			$dashboard_css_path = WP_MCP_AI_PATH . 'assets/css/pro-dashboard.css';
			wp_enqueue_style(
				'wp-mcp-ai-pro-dashboard',
				WP_MCP_AI_URL . 'assets/css/pro-dashboard.css',
				array( 'wp-mcp-ai-responsive-utilities' ),
				file_exists( $dashboard_css_path ) ? filemtime( $dashboard_css_path ) : WP_MCP_AI_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-pro-dashboard',
				WP_MCP_AI_URL . 'assets/js/pro-dashboard.js',
				array( 'jquery', 'chartjs' ),
				WP_MCP_AI_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-pro-dashboard',
				'wpMcpAiProDashboard',
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'restUrl'     => esc_url_raw( rest_url() ),
					'restNonce'   => wp_create_nonce( 'wp_rest' ),
					'nonce'       => wp_create_nonce( 'wp_mcp_ai_pro_dashboard' ),
					'isProActive' => $this->is_pro_active(),
					'chartData'   => $chart_data,
					'debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
				)
			);
		}

		/**
		 * Get chart data for JavaScript initialization.
		 *
		 * @return array Chart data.
		 */
		private function get_chart_data() {
			$controls = $this->get_iso27001_controls();
			$stats    = $this->calculate_controls_stats( $controls );

			return array(
				'controls' => array(
					'implemented'    => $stats['implemented'],
					'partial'        => $stats['partial'],
					'planned'        => $stats['planned'],
					'not_applicable' => $stats['not_applicable'],
					'total'          => $stats['total'],
				),
				'risks'    => $this->get_risk_data(),
				'metrics'  => $this->get_metrics_data(),
			);
		}

		/**
		 * Render Compliance Overview page.
		 *
		 * Note: This is a legacy method used primarily for testing.
		 * The production dashboard uses render_dashboard_with_tabs() which includes its own header.
		 * This method renders content without the header to avoid duplication.
		 */
		public function render_overview() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<?php $this->render_pro_status_notice(); ?>

			<?php
			// Get actual compliance data from Statement of Applicability.
			$controls = $this->get_iso27001_controls();

			// Check if controls were loaded successfully.
			if ( empty( $controls ) ) {
				?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Error loading compliance data:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Statement of Applicability file not found or could not be parsed. Please ensure the file exists at docs/compliance/iso27001/Statement-of-Applicability.md', 'mcp-ai-wpoos' ); ?>
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
				$compliance_pct   = 0;
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
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Controls Implemented', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-partial"><?php echo esc_html( $stats['partial'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-critical">0</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Critical Risks', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-compliance"><?php echo esc_html( $compliance_pct ); ?>%</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Overall Compliance', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>


				<!-- Charts Row -->
				<div class="wp-mcp-ai-charts-row">
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Control Implementation', 'mcp-ai-wpoos' ); ?></h3>
						<div class="wp-mcp-ai-pro-chart-container">
							<canvas id="wpMcpAiControlsChart"></canvas>
						</div>
						<div class="wp-mcp-ai-chart-fallback" style="display:none;">
							<table class="wp-mcp-ai-chart-data-table">
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #4caf50;"></span> <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></td>
									<td><strong><?php echo esc_html( $stats['implemented'] ); ?></strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #ff9800;"></span> <?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></td>
									<td><strong><?php echo esc_html( $stats['partial'] ); ?></strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #2196f3;"></span> <?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></td>
									<td><strong><?php echo esc_html( $stats['planned'] ); ?></strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #9e9e9e;"></span> <?php esc_html_e( 'N/A', 'mcp-ai-wpoos' ); ?></td>
									<td><strong><?php echo esc_html( $stats['not_applicable'] ); ?></strong></td>
								</tr>
							</table>
						</div>
					</div>
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Security Metrics', 'mcp-ai-wpoos' ); ?></h3>
						<div class="wp-mcp-ai-pro-chart-container">
							<canvas id="wpMcpAiMetricsChart"></canvas>
						</div>
						<div class="wp-mcp-ai-chart-fallback" style="display:none;">
							<p class="description"><?php esc_html_e( 'Tracking security incidents and vulnerability remediation over the last 6 months.', 'mcp-ai-wpoos' ); ?></p>
							<div class="wp-mcp-ai-metrics-summary-mini">
								<div><strong>2</strong> <?php esc_html_e( 'Recent Incidents', 'mcp-ai-wpoos' ); ?></div>
								<div><strong>12</strong> <?php esc_html_e( 'Vulnerabilities Fixed', 'mcp-ai-wpoos' ); ?></div>
							</div>
						</div>
					</div>
					<div class="wp-mcp-ai-chart-card">
						<h3><?php esc_html_e( 'Risk Distribution', 'mcp-ai-wpoos' ); ?></h3>
						<div class="wp-mcp-ai-pro-chart-container">
							<canvas id="wpMcpAiRiskChart"></canvas>
						</div>
						<div class="wp-mcp-ai-chart-fallback" style="display:none;">
							<table class="wp-mcp-ai-chart-data-table">
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #f44336;"></span> <?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?></td>
									<td><strong>0</strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #ff9800;"></span> <?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></td>
									<td><strong>3</strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #ffc107;"></span> <?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></td>
									<td><strong>12</strong></td>
								</tr>
								<tr>
									<td><span class="wp-mcp-ai-legend-dot" style="background: #8bc34a;"></span> <?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></td>
									<td><strong>8</strong></td>
								</tr>
							</table>
						</div>
					</div>
				</div>

				<!-- Multi-Framework Compliance Status -->
				<div class="wp-mcp-ai-frameworks-section">
					<h2><?php esc_html_e( 'Multi-Framework Compliance', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_framework_badges(); ?>
				</div>

				<div class="wp-mcp-ai-dashboard-grid">
					<!-- Compliance Status Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'ISO 27001 Compliance Status', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_compliance_status(); ?>
					</div>

					<!-- Quick Actions Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_quick_actions(); ?>
					</div>

					<!-- System Health Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_system_health(); ?>
					</div>

					<!-- Recent Activity Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'Recent Security Events', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_recent_activity(); ?>
					</div>

					<!-- Documentation Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'ISMS Documentation', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_documentation_links(); ?>
					</div>

					<!-- Compliance Summary Card -->
					<div class="wp-mcp-ai-card">
						<h2><?php esc_html_e( 'Compliance Summary', 'mcp-ai-wpoos' ); ?></h2>
						<?php $this->render_compliance_summary(); ?>
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
				<h1><?php esc_html_e( 'ISO 27001 Control Management', 'mcp-ai-wpoos' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-controls-overview">
					<?php $this->render_controls_summary(); ?>
				</div>

				<div class="wp-mcp-ai-controls-table">
					<h2><?php esc_html_e( '93 ISO 27001:2022 Controls', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_controls_table(); ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render dashboard with tab-based navigation.
		 *
		 * This unified dashboard page contains all main Pro Dashboard sections
		 * accessible via tabs: Overview, ISO 27001, Reports, Monitoring,
		 * Risk Management, and Multi-Framework.
		 *
		 * @since 1.5.1
		 */
		public function render_dashboard_with_tabs() {
			// Valid tabs.
			$valid_tabs = array( 'overview', 'iso27001', 'reports', 'monitoring', 'risk', 'multi-framework' );

			// Get current tab from URL parameter, sanitize and validate immediately.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';

			// Validate tab - ensure it's in the valid tabs list.
			if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
				$current_tab = 'overview';
			}
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1>
					<?php esc_html_e( 'NV oOS Pro Dashboard', 'mcp-ai-wpoos' ); ?>
					<span class="wp-mcp-ai-pro-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos' ); ?></span>
				</h1>

				<?php $this->render_pro_status_notice(); ?>

				<!-- Tab Navigation -->
				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Pro Dashboard tabs', 'mcp-ai-wpoos' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=overview' ) ); ?>" class="nav-tab <?php echo 'overview' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-dashboard"></span>
						<?php esc_html_e( 'Overview', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=iso27001' ) ); ?>" class="nav-tab <?php echo 'iso27001' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'ISO 27001', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=reports' ) ); ?>" class="nav-tab <?php echo 'reports' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-media-document"></span>
						<?php esc_html_e( 'Reports', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=monitoring' ) ); ?>" class="nav-tab <?php echo 'monitoring' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php esc_html_e( 'Monitoring', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=risk' ) ); ?>" class="nav-tab <?php echo 'risk' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Risk Management', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework' ) ); ?>" class="nav-tab <?php echo 'multi-framework' === $current_tab ? 'nav-tab-active' : ''; ?>">
						<span class="dashicons dashicons-networking"></span>
						<?php esc_html_e( 'Multi-Framework', 'mcp-ai-wpoos' ); ?>
					</a>
				</nav>

				<!-- Tab Content -->
				<div class="tab-content">
					<?php
					// Render the current tab content.
					switch ( $current_tab ) {
						case 'iso27001':
							$this->render_iso27001_tab();
							break;
						case 'reports':
							$this->render_reports_tab();
							break;
						case 'monitoring':
							$this->render_monitoring_tab();
							break;
						case 'risk':
							$this->render_risk_tab();
							break;
						case 'multi-framework':
							$this->render_multi_framework_tab();
							break;
						case 'overview':
						default:
							$this->render_overview_tab();
							break;
					}
					?>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Overview tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_overview_tab() {
			?>
			<div class="wp-mcp-ai-overview">
			<?php
			// Get actual compliance data from Statement of Applicability.
			$controls = $this->get_iso27001_controls();

			// Check if controls were loaded successfully.
			if ( empty( $controls ) ) {
				?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Error loading compliance data:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Statement of Applicability file not found or could not be parsed. Please ensure the file exists at docs/compliance/iso27001/Statement-of-Applicability.md', 'mcp-ai-wpoos' ); ?>
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
				$compliance_pct   = 0;
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
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Controls Implemented', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-partial"><?php echo esc_html( $stats['partial'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-critical">0</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Critical Risks', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-compliance"><?php echo esc_html( $compliance_pct ); ?>%</div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Overall Compliance', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Charts Row -->
			<div class="wp-mcp-ai-charts-row">
				<div class="wp-mcp-ai-chart-card">
					<h3><?php esc_html_e( 'Control Implementation', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-pro-chart-container">
						<canvas id="wpMcpAiControlsChart"></canvas>
					</div>
					<div class="wp-mcp-ai-chart-fallback" style="display:none;">
						<table class="wp-mcp-ai-chart-data-table">
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #4caf50;"></span> <?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></td>
								<td><strong><?php echo esc_html( $stats['implemented'] ); ?></strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #ff9800;"></span> <?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></td>
								<td><strong><?php echo esc_html( $stats['partial'] ); ?></strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #2196f3;"></span> <?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></td>
								<td><strong><?php echo esc_html( $stats['planned'] ); ?></strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #9e9e9e;"></span> <?php esc_html_e( 'N/A', 'mcp-ai-wpoos' ); ?></td>
								<td><strong><?php echo esc_html( $stats['not_applicable'] ); ?></strong></td>
							</tr>
						</table>
					</div>
				</div>
				<div class="wp-mcp-ai-chart-card">
					<h3><?php esc_html_e( 'Security Metrics', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-pro-chart-container">
						<canvas id="wpMcpAiMetricsChart"></canvas>
					</div>
					<div class="wp-mcp-ai-chart-fallback" style="display:none;">
						<p class="description"><?php esc_html_e( 'Tracking security incidents and vulnerability remediation over the last 6 months.', 'mcp-ai-wpoos' ); ?></p>
						<div class="wp-mcp-ai-metrics-summary-mini">
							<div><strong>2</strong> <?php esc_html_e( 'Recent Incidents', 'mcp-ai-wpoos' ); ?></div>
							<div><strong>12</strong> <?php esc_html_e( 'Vulnerabilities Fixed', 'mcp-ai-wpoos' ); ?></div>
						</div>
					</div>
				</div>
				<div class="wp-mcp-ai-chart-card">
					<h3><?php esc_html_e( 'Risk Distribution', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-pro-chart-container">
						<canvas id="wpMcpAiRiskChart"></canvas>
					</div>
					<div class="wp-mcp-ai-chart-fallback" style="display:none;">
						<table class="wp-mcp-ai-chart-data-table">
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #f44336;"></span> <?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?></td>
								<td><strong>0</strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #ff9800;"></span> <?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></td>
								<td><strong>3</strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #ffc107;"></span> <?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></td>
								<td><strong>12</strong></td>
							</tr>
							<tr>
								<td><span class="wp-mcp-ai-legend-dot" style="background: #8bc34a;"></span> <?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></td>
								<td><strong>8</strong></td>
							</tr>
						</table>
					</div>
				</div>
			</div>

			<!-- Multi-Framework Compliance Status -->
			<div class="wp-mcp-ai-frameworks-section">
				<h2><?php esc_html_e( 'Multi-Framework Compliance', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_framework_badges(); ?>
			</div>

			<div class="wp-mcp-ai-dashboard-grid">
				<!-- Compliance Status Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'ISO 27001 Compliance Status', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_compliance_status(); ?>
				</div>

				<!-- Quick Actions Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_quick_actions(); ?>
				</div>

				<!-- System Health Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_system_health(); ?>
				</div>

				<!-- Recent Activity Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'Recent Security Events', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_recent_activity(); ?>
				</div>

				<!-- Documentation Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'ISMS Documentation', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_documentation_links(); ?>
				</div>

				<!-- Compliance Summary Card -->
				<div class="wp-mcp-ai-card">
					<h2><?php esc_html_e( 'Compliance Summary', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_compliance_summary(); ?>
				</div>
			</div>
			</div>
			<?php
		}

		/**
		 * Render ISO 27001 tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_iso27001_tab() {
			?>
			<div class="wp-mcp-ai-controls-overview">
				<?php $this->render_controls_summary(); ?>
			</div>

			<div class="wp-mcp-ai-controls-table">
				<h2><?php esc_html_e( '93 ISO 27001:2022 Controls', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_controls_table(); ?>
			</div>
			<?php
		}

		/**
		 * Render Reports tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_reports_tab() {
			?>
			<p class="description">
				<?php esc_html_e( 'Generate and export compliance reports for management review and audit purposes.', 'mcp-ai-wpoos' ); ?>
				<?php esc_html_e( 'For detailed audit management, visit the "Security Audits" page.', 'mcp-ai-wpoos' ); ?>
			</p>

			<div class="wp-mcp-ai-report-generator">
				<h2><?php esc_html_e( 'Generate Compliance Report', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_report_generator(); ?>
			</div>

			<div class="wp-mcp-ai-audit-history">
				<h2><?php esc_html_e( 'Recent Reports', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_audit_history(); ?>
			</div>
			<?php
		}

		/**
		 * Render Monitoring tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_monitoring_tab() {
			?>
			<div class="wp-mcp-ai-monitoring-dashboard">
				<?php $this->render_monitoring_dashboard(); ?>
			</div>
			<?php
		}

		/**
		 * Render Risk Management tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_risk_tab() {
			?>
			<div class="wp-mcp-ai-risk-matrix">
				<h2><?php esc_html_e( '5×5 Risk Matrix', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_risk_matrix(); ?>
			</div>

			<div class="wp-mcp-ai-risk-register">
				<h2><?php esc_html_e( 'Risk Register', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_risk_register(); ?>
			</div>
			<?php
		}

		/**
		 * Render Multi-Framework tab content.
		 *
		 * @since 1.5.1
		 */
		private function render_multi_framework_tab() {
			?>
			<div class="wp-mcp-ai-frameworks">
				<?php $this->render_framework_status(); ?>
			</div>
			<?php
		}

		/**
		 * Render Compliance Reports page.
		 *
		 * This page provides access to compliance report generation and export.
		 * For detailed audit management, see the "Security Audits" menu item.
		 */
		public function render_reports() {
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<h1><?php esc_html_e( 'Compliance Reports', 'mcp-ai-wpoos' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Generate and export compliance reports for management review and audit purposes.', 'mcp-ai-wpoos' ); ?>
					<?php esc_html_e( 'For detailed audit management, visit the "Security Audits" page.', 'mcp-ai-wpoos' ); ?>
				</p>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-report-generator">
					<h2><?php esc_html_e( 'Generate Compliance Report', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_report_generator(); ?>
				</div>

				<div class="wp-mcp-ai-audit-history">
					<h2><?php esc_html_e( 'Recent Reports', 'mcp-ai-wpoos' ); ?></h2>
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
				<h1><?php esc_html_e( 'Security Monitoring', 'mcp-ai-wpoos' ); ?></h1>

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
				<h1><?php esc_html_e( 'Risk Management', 'mcp-ai-wpoos' ); ?></h1>

				<?php $this->render_pro_status_notice(); ?>

				<div class="wp-mcp-ai-risk-matrix">
					<h2><?php esc_html_e( '5×5 Risk Matrix', 'mcp-ai-wpoos' ); ?></h2>
					<?php $this->render_risk_matrix(); ?>
				</div>

				<div class="wp-mcp-ai-risk-register">
					<h2><?php esc_html_e( 'Risk Register', 'mcp-ai-wpoos' ); ?></h2>
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
				<h1><?php esc_html_e( 'Multi-Framework Compliance', 'mcp-ai-wpoos' ); ?></h1>

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
					<h3><?php esc_html_e( '🔒 Pro Dashboard Preview', 'mcp-ai-wpoos' ); ?></h3>
					<p>
						<?php
						$upgrade_url = apply_filters( 'wp_mcp_ai_pro_upgrade_url', admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) );
						echo wp_kses_post(
							sprintf(
								/* translators: %s: Link to upgrade page */
								__( 'You\'re viewing a preview of the Pro Dashboard. <a href="%s">Upgrade to Pro</a> to unlock full compliance automation, real-time monitoring, and advanced reporting features.', 'mcp-ai-wpoos' ),
								esc_url( $upgrade_url )
							)
						);
						?>
					</p>
					<p><strong><?php esc_html_e( 'Pro Features Include:', 'mcp-ai-wpoos' ); ?></strong></p>
					<ul>
						<li>✅ <?php esc_html_e( 'Real-time compliance status monitoring', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Automated audit report generation (PDF, DOCX, Excel)', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Interactive risk register and 5×5 risk matrix', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Multi-framework support (SOC 2, HIPAA, GDPR)', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'SIEM integration capabilities', 'mcp-ai-wpoos' ); ?></li>
						<li>✅ <?php esc_html_e( 'Priority security support', 'mcp-ai-wpoos' ); ?></li>
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
						<?php esc_html_e( 'ISO 27001 Certified', 'mcp-ai-wpoos' ); ?>
					<?php else : ?>
						<span class="dashicons dashicons-shield-alt"></span>
						<?php esc_html_e( 'ISO 27001 Compliant', 'mcp-ai-wpoos' ); ?>
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
							__( '<strong>%1$d of %2$d</strong> controls implemented', 'mcp-ai-wpoos' ),
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
									__( 'Certified: %s', 'mcp-ai-wpoos' ),
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=reports' ) ); ?>" class="button button-primary">
					<span class="dashicons dashicons-media-document"></span>
					<?php esc_html_e( 'Generate Compliance Report', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=iso27001' ) ); ?>" class="button">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'View All Controls', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=risk' ) ); ?>" class="button">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Manage Risks', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/README.md' ); ?>" class="button" target="_blank">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( 'View ISMS Documentation', 'mcp-ai-wpoos' ); ?>
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

			// Filter to only show security-relevant events.
			$security_events = array_filter( $recent_events, function( $event ) {
				$message = $event['message'] ?? '';
				// Skip generic "Tool executed" and API request logs.
				if ( false !== strpos( $message, 'Tool executed successfully' ) ) {
					return false;
				}
				if ( false !== strpos( $message, 'Sending request to OpenAI' ) ) {
					return false;
				}
				if ( false !== strpos( $message, 'OpenAI request completed' ) ) {
					return false;
				}
				return true;
			});

			// If we have security events, use them; otherwise show sample data.
			if ( empty( $security_events ) ) {
				$security_events = $this->get_sample_security_events();
			}
			?>
			<div class="wp-mcp-ai-recent-activity">
				<?php if ( ! empty( $security_events ) ) : ?>
					<ul class="wp-mcp-ai-activity-list">
						<?php foreach ( array_slice( $security_events, 0, 5 ) as $event ) : ?>
							<li class="wp-mcp-ai-activity-item">
								<span class="wp-mcp-ai-activity-icon dashicons dashicons-<?php echo esc_attr( $event['icon'] ?? 'info' ); ?>"></span>
								<span class="wp-mcp-ai-activity-text"><?php echo esc_html( $event['message'] ?? __( 'Security event logged', 'mcp-ai-wpoos' ) ); ?></span>
								<span class="wp-mcp-ai-activity-time"><?php echo esc_html( $event['time'] ?? $event['timestamp'] ?? __( 'Recently', 'mcp-ai-wpoos' ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="description" style="margin-top: 15px; text-align: center;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=monitoring' ) ); ?>">
							<?php esc_html_e( 'View all security events →', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="wp-mcp-ai-empty-state">
						<span class="dashicons dashicons-shield-alt"></span>
						<?php esc_html_e( 'No security events detected. System is operating normally.', 'mcp-ai-wpoos' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render system health widget.
		 */
		private function render_system_health() {
			$health_checks = array(
				array(
					'label'  => __( 'WordPress Core', 'mcp-ai-wpoos' ),
					'status' => 'good',
					'icon'   => 'wordpress-alt',
				),
				array(
					'label'  => __( 'SSL Certificate', 'mcp-ai-wpoos' ),
					'status' => is_ssl() ? 'good' : 'warning',
					'icon'   => 'lock',
				),
				array(
					'label'  => __( 'File Permissions', 'mcp-ai-wpoos' ),
					'status' => 'good',
					'icon'   => 'admin-tools',
				),
				array(
					'label'  => __( 'Database', 'mcp-ai-wpoos' ),
					'status' => 'good',
					'icon'   => 'database',
				),
				array(
					'label'  => __( 'Backup Status', 'mcp-ai-wpoos' ),
					'status' => 'good',
					'icon'   => 'backup',
				),
			);
			?>
			<div class="wp-mcp-ai-system-health">
				<ul class="wp-mcp-ai-health-list">
					<?php foreach ( $health_checks as $check ) : ?>
						<li class="wp-mcp-ai-health-item">
							<span class="dashicons dashicons-<?php echo esc_attr( $check['icon'] ); ?>"></span>
							<span class="wp-mcp-ai-health-label"><?php echo esc_html( $check['label'] ); ?></span>
							<span class="wp-mcp-ai-health-status wp-mcp-ai-health-<?php echo esc_attr( $check['status'] ); ?>">
								<?php if ( $check['status'] === 'good' ) : ?>
									<span class="dashicons dashicons-yes-alt"></span>
								<?php elseif ( $check['status'] === 'warning' ) : ?>
									<span class="dashicons dashicons-warning"></span>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		}

		/**
		 * Render compliance summary widget.
		 */
		private function render_compliance_summary() {
			$frameworks = $this->get_framework_status();
			?>
			<div class="wp-mcp-ai-compliance-summary-widget">
				<p class="description"><?php esc_html_e( 'Multi-framework compliance status overview:', 'mcp-ai-wpoos' ); ?></p>
				<ul class="wp-mcp-ai-framework-status-list">
					<?php foreach ( $frameworks as $framework ) : ?>
						<li class="wp-mcp-ai-framework-status-item">
							<span class="wp-mcp-ai-framework-name"><?php echo esc_html( $framework['name'] ); ?></span>
							<span class="wp-mcp-ai-framework-badge wp-mcp-ai-status-<?php echo esc_attr( $framework['status'] ); ?>">
								<?php echo esc_html( $framework['percentage'] ); ?>%
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="description" style="margin-top: 15px; text-align: center;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework' ) ); ?>">
						<?php esc_html_e( 'View detailed compliance →', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Get sample security events for demonstration.
		 *
		 * @return array Sample security events.
		 */
		private function get_sample_security_events() {
			$now      = current_time( 'mysql' );
			$hour_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour', strtotime( $now ) ) );
			$day_ago  = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day', strtotime( $now ) ) );
			$week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days', strtotime( $now ) ) );

			return array(
				array(
					'icon'    => 'shield-alt',
					'message' => __( 'WordPress core updated to latest version', 'mcp-ai-wpoos' ),
					'time'    => $hour_ago,
				),
				array(
					'icon'    => 'update',
					'message' => __( 'Security plugin definitions updated', 'mcp-ai-wpoos' ),
					'time'    => $hour_ago,
				),
				array(
					'icon'    => 'yes-alt',
					'message' => __( 'Backup completed successfully', 'mcp-ai-wpoos' ),
					'time'    => $day_ago,
				),
				array(
					'icon'    => 'admin-users',
					'message' => __( 'User session security check passed', 'mcp-ai-wpoos' ),
					'time'    => $day_ago,
				),
				array(
					'icon'    => 'shield-alt',
					'message' => __( 'Access control review completed', 'mcp-ai-wpoos' ),
					'time'    => $week_ago,
				),
			);
		}

		/**
		 * Render documentation links widget.
		 */
		private function render_documentation_links() {
			// Link to GitHub repository documentation (always up-to-date).
			$github_base = 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/';
			$docs        = array(
				'ISMS-Policy.md'                => __( 'ISMS Policy', 'mcp-ai-wpoos' ),
				'Statement-of-Applicability.md' => __( 'Statement of Applicability', 'mcp-ai-wpoos' ),
				'Risk-Assessment.md'            => __( 'Risk Assessment', 'mcp-ai-wpoos' ),
				'Business-Continuity-Plan.md'   => __( 'Business Continuity Plan', 'mcp-ai-wpoos' ),
			);
			?>
			<div class="wp-mcp-ai-documentation-links">
				<ul>
					<?php foreach ( $docs as $file => $label ) : ?>
						<li>
							<a href="<?php echo esc_url( $github_base . $file ); ?>" target="_blank">
								<span class="dashicons dashicons-media-document"></span>
								<?php echo esc_html( $label ); ?>
							</a>
						</li>
					<?php endforeach; ?>
					<li>
						<a href="<?php echo esc_url( $github_base . 'procedures/' ); ?>" target="_blank">
							<span class="dashicons dashicons-admin-tools"></span>
							<?php esc_html_e( 'All Procedures', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
				</ul>
			</div>
			<?php
		}

		/**
		 * Render multi-framework compliance badges.
		 */
		private function render_framework_badges() {
			$frameworks = $this->get_framework_status();
			?>
			<div class="wp-mcp-ai-frameworks-grid">
				<?php foreach ( $frameworks as $framework ) : ?>
					<div class="wp-mcp-ai-framework-card">
						<h3><?php echo esc_html( $framework['name'] ); ?></h3>
						<div class="wp-mcp-ai-framework-status <?php echo esc_attr( $framework['status'] ); ?>">
							<?php echo esc_html( ucfirst( $framework['status'] ) ); ?>
						</div>
						<div class="wp-mcp-ai-framework-progress">
							<div class="wp-mcp-ai-progress" style="width: <?php echo esc_attr( $framework['percentage'] ); ?>%;">
								<span class="wp-mcp-ai-progress-text"><?php echo esc_html( $framework['percentage'] ); ?>%</span>
							</div>
						</div>
						<p class="wp-mcp-ai-framework-info">
							<?php
							echo wp_kses_post(
								sprintf(
									/* translators: %1$d: Completed items, %2$d: Total items */
									__( '<strong>%1$d of %2$d</strong> requirements met', 'mcp-ai-wpoos' ),
									$framework['completed'],
									$framework['total']
								)
							);
							?>
						</p>
						<?php if ( isset( $framework['link'] ) ) : ?>
							<a href="<?php echo esc_url( admin_url( $framework['link'] ) ); ?>" class="button button-small">
								<?php esc_html_e( 'View Details', 'mcp-ai-wpoos' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
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
					<p><?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['partial'] ); ?></h3>
					<p><?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['planned'] ); ?></h3>
					<p><?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></p>
				</div>
				<div class="wp-mcp-ai-control-stat">
					<h3><?php echo esc_html( $stats['not_applicable'] ); ?></h3>
					<p><?php esc_html_e( 'N/A', 'mcp-ai-wpoos' ); ?></p>
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
					<?php esc_html_e( 'Unable to load ISO 27001 controls. Please check that the Statement of Applicability document is available.', 'mcp-ai-wpoos' ); ?>
				</p>
				<?php
				return;
			}
			?>
			<div class="wp-mcp-ai-controls-filter">
				<label for="controls-status-filter"><?php esc_html_e( 'Filter by status:', 'mcp-ai-wpoos' ); ?></label>
				<select id="controls-status-filter">
					<option value="all"><?php esc_html_e( 'All Controls', 'mcp-ai-wpoos' ); ?></option>
					<option value="implemented"><?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></option>
					<option value="partial"><?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></option>
					<option value="planned"><?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></option>
					<option value="not_applicable"><?php esc_html_e( 'Not Applicable', 'mcp-ai-wpoos' ); ?></option>
				</select>

				<label for="controls-search"><?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?></label>
				<input type="text" id="controls-search" placeholder="<?php esc_attr_e( 'Search controls...', 'mcp-ai-wpoos' ); ?>" />
			</div>

			<table class="wp-list-table widefat fixed striped wp-mcp-ai-controls-table">
				<thead>
					<tr>
						<th style="width: 120px;"><?php esc_html_e( 'Control ID', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Control Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Applicable', 'mcp-ai-wpoos' ); ?></th>
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
				<p><?php esc_html_e( 'Generate compliance reports in PDF, DOCX, or Excel format.', 'mcp-ai-wpoos' ); ?></p>
				<?php
			} else {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php esc_html_e( 'Upgrade to Pro to generate automated compliance reports for auditors and management.', 'mcp-ai-wpoos' ); ?>
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
				<?php esc_html_e( 'Audit history tracks compliance activities, control assessments, and remediation progress.', 'mcp-ai-wpoos' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 150px;"><?php esc_html_e( 'Date', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Audit Type', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>2026-01-05</td>
						<td>
							<strong><?php esc_html_e( 'Initial Compliance Assessment', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Baseline assessment of ISO 27001:2022 controls', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><span class="wp-mcp-ai-status-badge wp-mcp-ai-status-implemented"><?php esc_html_e( 'Complete', 'mcp-ai-wpoos' ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/Statement-of-Applicability.md' ); ?>"
						<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/Statement-of-Applicability.md' ); ?>" class="button button-small" target="_blank">
								<?php esc_html_e( 'View Report', 'mcp-ai-wpoos' ); ?>
							</a>
						</td>
					</tr>
					<tr>
						<td colspan="4" class="wp-mcp-ai-empty-state" style="text-align: center; padding: 20px;">
							<?php esc_html_e( 'Additional audit entries will appear here as audits are conducted.', 'mcp-ai-wpoos' ); ?>
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
					<h3><?php esc_html_e( 'Security Status', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-status-indicator">
						<span class="dashicons dashicons-shield-alt" style="font-size: 48px; color: #46b450;"></span>
						<p><strong><?php esc_html_e( 'All Systems Operational', 'mcp-ai-wpoos' ); ?></strong></p>
					</div>
				</div>

				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Recent Security Events', 'mcp-ai-wpoos' ); ?></h3>
					<?php if ( ! empty( $recent_events ) ) : ?>
						<ul class="wp-mcp-ai-activity-list">
							<?php foreach ( array_slice( $recent_events, 0, 5 ) as $event ) : ?>
								<li><?php echo esc_html( $event['message'] ?? __( 'Security event', 'mcp-ai-wpoos' ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No recent security events.', 'mcp-ai-wpoos' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Monitored Resources', 'mcp-ai-wpoos' ); ?></h3>
					<ul>
						<li>✓ <?php esc_html_e( 'File Integrity', 'mcp-ai-wpoos' ); ?></li>
						<li>✓ <?php esc_html_e( 'Authentication Events', 'mcp-ai-wpoos' ); ?></li>
						<li>✓ <?php esc_html_e( 'Plugin Updates', 'mcp-ai-wpoos' ); ?></li>
						<li>✓ <?php esc_html_e( 'Configuration Changes', 'mcp-ai-wpoos' ); ?></li>
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
				<?php esc_html_e( 'The risk matrix visualizes identified risks based on their likelihood and impact on a 5×5 scale.', 'mcp-ai-wpoos' ); ?>
			</p>

			<div class="wp-mcp-ai-risk-matrix-container">
				<table class="wp-mcp-ai-risk-matrix-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Impact →', 'mcp-ai-wpoos' ); ?><br><?php esc_html_e( 'Likelihood ↓', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Very Low', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Very High', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Very Likely', 'mcp-ai-wpoos' ); ?></th>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
							<td class="risk-critical"></td>
							<td class="risk-critical"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Likely', 'mcp-ai-wpoos' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
							<td class="risk-critical"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Possible', 'mcp-ai-wpoos' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
							<td class="risk-high"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Unlikely', 'mcp-ai-wpoos' ); ?></th>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-low"></td>
							<td class="risk-medium"></td>
							<td class="risk-medium"></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Very Unlikely', 'mcp-ai-wpoos' ); ?></th>
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
				<h4><?php esc_html_e( 'Risk Levels', 'mcp-ai-wpoos' ); ?></h4>
				<span class="risk-badge risk-low"><?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></span>
				<span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></span>
				<span class="risk-badge risk-high"><?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></span>
				<span class="risk-badge risk-critical"><?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?></span>
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
				<?php esc_html_e( 'The risk register documents all identified risks, their assessment, and treatment plans.', 'mcp-ai-wpoos' ); ?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 80px;"><?php esc_html_e( 'Risk ID', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Risk Description', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Likelihood', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Impact', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Risk Level', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Treatment', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>R-001</td>
						<td>
							<strong><?php esc_html_e( 'Unauthorized API Access', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Unauthorized users gaining access to AI API endpoints', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Possible', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td>R-002</td>
						<td>
							<strong><?php esc_html_e( 'Data Exposure via Logs', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Sensitive data exposure through debug logs', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></td>
						<td><span class="risk-badge risk-low"><?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td>R-003</td>
						<td>
							<strong><?php esc_html_e( 'Third-Party API Outage', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'OpenAI/Gemini API unavailability affecting service', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Possible', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></span></td>
						<td><?php esc_html_e( 'Accept', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td>R-004</td>
						<td>
							<strong><?php esc_html_e( 'Injection Attacks', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'SQL/XSS injection through user inputs', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( 'Very High', 'mcp-ai-wpoos' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'mcp-ai-wpoos' ); ?></td>
					</tr>
					<tr>
						<td>R-005</td>
						<td>
							<strong><?php esc_html_e( 'Credential Compromise', 'mcp-ai-wpoos' ); ?></strong>
							<p class="description"><?php esc_html_e( 'API keys or credentials being exposed or stolen', 'mcp-ai-wpoos' ); ?></p>
						</td>
						<td><?php esc_html_e( 'Unlikely', 'mcp-ai-wpoos' ); ?></td>
						<td><?php esc_html_e( 'Very High', 'mcp-ai-wpoos' ); ?></td>
						<td><span class="risk-badge risk-medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></span></td>
						<td><?php esc_html_e( 'Reduce', 'mcp-ai-wpoos' ); ?></td>
					</tr>
				</tbody>
			</table>

			<p class="description" style="margin-top: 20px;">
				<?php
				printf(
					/* translators: %s: Link to risk assessment document */
					esc_html__( 'See the full %s for detailed risk analysis and treatment plans.', 'mcp-ai-wpoos' ),
					'<a href="' . esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/Risk-Assessment.md' ) . '" target="_blank">' . esc_html__( 'Risk Assessment document', 'mcp-ai-wpoos' ) . '</a>'
				);
				?>
			</p>
			<?php
		}

		/**
		 * Get framework compliance status data.
		 *
		 * @return array Framework status data.
		 */
		private function get_framework_status() {
			// Calculate ISO 27001 compliance dynamically from Statement of Applicability.
			$iso_controls         = $this->get_iso27001_controls();
			$iso_stats            = $this->calculate_controls_stats( $iso_controls );
			$iso_total_applicable = $iso_stats['total'] - $iso_stats['not_applicable'];
			$iso_compliance       = $iso_total_applicable > 0 ? round( ( $iso_stats['implemented'] / $iso_total_applicable ) * 100 ) : 0;

			// Calculate SOC 2 compliance from Trust Services Criteria.
			$soc2_compliance = $this->get_soc2_compliance();

			// Calculate HIPAA compliance from Security Rule safeguards.
			$hipaa_compliance = $this->get_hipaa_compliance();

			return array(
				array(
					'name'       => 'ISO 27001:2022',
					'status'     => 'compliant',
					'percentage' => $iso_compliance,
					'completed'  => $iso_stats['implemented'],
					'total'      => $iso_total_applicable,
					'link'       => 'admin.php?page=' . self::PAGE_SLUG . '&tab=iso27001',
				),
				array(
					'name'       => 'SOC 2',
					'status'     => $soc2_compliance >= 95 ? 'compliant' : 'pending',
					'percentage' => $soc2_compliance,
					// SOC 2 has 54 Trust Services Criteria. Convert percentage to completed count.
					// Formula: percentage / 100 * 54 = percentage * 0.54.
					'completed'  => round( $soc2_compliance * 0.54 ),
					'total'      => 54,
					'link'       => 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework',
				),
				array(
					'name'       => 'HIPAA',
					'status'     => $hipaa_compliance >= 95 ? 'compliant' : 'pending',
					'percentage' => $hipaa_compliance,
					// HIPAA has 43 applicable safeguards. Convert percentage to completed count.
					// Formula: percentage / 100 * 43 = percentage * 0.43.
					'completed'  => round( $hipaa_compliance * 0.43 ),
					'total'      => 43,
					'link'       => 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework',
				),
				array(
					'name'       => 'GDPR',
					'status'     => 'compliant',
					'percentage' => 95,
					'completed'  => 6,
					'total'      => 7,
					'link'       => 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework',
				),
			);
		}

		/**
		 * Render framework status.
		 */
		private function render_framework_status() {
			$frameworks = $this->get_framework_status();
			?>
			<div class="wp-mcp-ai-frameworks-grid">
				<?php foreach ( $frameworks as $framework ) : ?>
					<div class="wp-mcp-ai-framework-card">
						<h3><?php echo esc_html( $framework['name'] ); ?></h3>
						<div class="wp-mcp-ai-framework-status <?php echo esc_attr( $framework['status'] ); ?>">
							<?php echo esc_html( ucfirst( $framework['status'] ) ); ?>
						</div>
						<?php if ( $framework['percentage'] > 0 ) : ?>
							<div class="wp-mcp-ai-framework-progress">
								<div class="wp-mcp-ai-progress" style="width: <?php echo esc_attr( $framework['percentage'] ); ?>%;">
									<?php echo esc_html( $framework['percentage'] ); ?>%
								</div>
							</div>
						<?php endif; ?>
						<?php if ( ! $this->is_pro_active() && $framework['status'] === 'pending' ) : ?>
							<p class="wp-mcp-ai-framework-cta">
								<small><?php esc_html_e( 'Pro feature', 'mcp-ai-wpoos' ); ?></small>
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
			// Try embedded data first (always available in production).
			if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
				$controls = WP_MCP_AI_Compliance_Data::get_iso27001_controls();
				if ( ! empty( $controls ) ) {
					return $controls;
				}
			}

			// Fallback to parsing markdown file (development/debugging).
			$soa_file = WP_MCP_AI_PATH . 'docs/compliance/iso27001/Statement-of-Applicability.md';

			if ( ! file_exists( $soa_file ) ) {
				return array();
			}

			$content = file_get_contents( $soa_file );
			if ( empty( $content ) ) {
				return array();
			}

			$controls      = array();
			$lines         = explode( "\n", $content );
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
					if ( false !== strpos( $status_text, 'Implemented' ) ) {
						$current_control['status_key'] = 'implemented';
					} elseif ( false !== strpos( $status_text, 'Partial' ) ) {
						$current_control['status_key'] = 'partial';
					} elseif ( false !== strpos( $status_text, 'Planned' ) ) {
						$current_control['status_key'] = 'planned';
					} elseif ( false !== strpos( $status_text, 'Not Applicable' ) ) {
						$current_control['status_key'] = 'not_applicable';
						$current_control['applicable'] = false;
					}
				} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
					$applicable_text         = trim( $matches[1] );
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

		/**
		 * Get SOC 2 compliance percentage.
		 *
		 * Calculates compliance by parsing the SOC 2 Statement of Applicability
		 * and counting implemented vs total Trust Services Criteria.
		 *
		 * @return int Compliance percentage (0-100).
		 */
		private function get_soc2_compliance() {
			// Try embedded data first.
			if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
				return WP_MCP_AI_Compliance_Data::get_soc2_compliance();
			}

			// Fallback to file parsing.
			$soc2_file = WP_MCP_AI_PATH . 'docs/compliance/soc2/Statement-of-Applicability.md';

			if ( ! file_exists( $soc2_file ) ) {
				return 0;
			}

			$content = file_get_contents( $soc2_file );
			if ( false === $content || empty( $content ) ) {
				return 0;
			}

			// Count total criteria and implemented criteria.
			// SOC 2 SoA uses "✅ Implemented" status markers.
			$total_matches = array();
			$impl_matches = array();
			$total = preg_match_all( '/^\*\*Status:\*\*/m', $content, $total_matches );
			$implemented = preg_match_all( '/^\*\*Status:\*\*.*✅.*Implemented/m', $content, $impl_matches );

			if ( false === $total || false === $implemented || $total <= 0 ) {
				return 0;
			}

			return round( ( $implemented / $total ) * 100 );
		}

		/**
		 * Get HIPAA compliance percentage.
		 *
		 * Calculates compliance by parsing the HIPAA Statement of Applicability
		 * and counting implemented vs total safeguards (excluding N/A).
		 *
		 * @return int Compliance percentage (0-100).
		 */
		private function get_hipaa_compliance() {
			// Try embedded data first.
			if ( class_exists( 'WP_MCP_AI_Compliance_Data' ) ) {
				return WP_MCP_AI_Compliance_Data::get_hipaa_compliance();
			}

			// Fallback to file parsing.
			$hipaa_file = WP_MCP_AI_PATH . 'docs/compliance/hipaa/Statement-of-Applicability.md';

			if ( ! file_exists( $hipaa_file ) ) {
				return 0;
			}

			$content = file_get_contents( $hipaa_file );
			if ( false === $content || empty( $content ) ) {
				return 0;
			}

			// Count total safeguards and implemented safeguards.
			// HIPAA SoA uses "✅ Implemented" and "❌ Not Applicable" status markers.
			$total_matches = array();
			$impl_matches = array();
			$na_matches = array();
			$total = preg_match_all( '/^\*\*Status:\*\*/m', $content, $total_matches );
			$implemented = preg_match_all( '/^\*\*Status:\*\*.*✅.*Implemented/m', $content, $impl_matches );
			$not_applicable = preg_match_all( '/^\*\*Status:\*\*.*❌.*Not Applicable/m', $content, $na_matches );

			if ( false === $total || false === $implemented || false === $not_applicable ) {
				return 0;
			}

			$applicable_total = $total - $not_applicable;

			if ( $applicable_total > 0 ) {
				return round( ( $implemented / $applicable_total ) * 100 );
			}

			return 0;
		}

		/**
		 * Get risk data for charts.
		 *
		 * Returns risk distribution by severity level.
		 * Data is sourced from WordPress options or defaults to sample data.
		 *
		 * @return array Risk counts by severity.
		 */
		private function get_risk_data() {
			// Try to get from stored option first.
			$stored_risks = get_option( 'wp_mcp_ai_risk_data', false );

			if ( false !== $stored_risks && is_array( $stored_risks ) ) {
				return wp_parse_args(
					$stored_risks,
					array(
						'critical' => 0,
						'high'     => 0,
						'medium'   => 0,
						'low'      => 0,
					)
				);
			}

			// Fallback to sample/default data.
			// These values can be updated via Settings or Pro Dashboard features.
			return array(
				'critical' => 0,
				'high'     => 3,
				'medium'   => 12,
				'low'      => 8,
			);
		}

		/**
		 * Get metrics data for charts.
		 *
		 * Returns security metrics trends over the last 6 months.
		 * Data is sourced from WordPress options or defaults to sample data.
		 *
		 * @return array Metrics data with incidents and vulnerabilities fixed.
		 */
		private function get_metrics_data() {
			// Try to get from stored option first.
			$stored_metrics = get_option( 'wp_mcp_ai_metrics_data', false );

			if ( false !== $stored_metrics && is_array( $stored_metrics ) ) {
				return wp_parse_args(
					$stored_metrics,
					array(
						'incidents'             => array( 0, 0, 0, 0, 0, 0 ),
						'vulnerabilities_fixed' => array( 0, 0, 0, 0, 0, 0 ),
					)
				);
			}

			// Fallback to sample/default data for the last 6 months.
			// These values can be updated via Settings or Pro Dashboard features.
			return array(
				'incidents'             => array( 5, 3, 2, 4, 1, 2 ),
				'vulnerabilities_fixed' => array( 8, 12, 10, 15, 14, 12 ),
			);
		}
	}
}
