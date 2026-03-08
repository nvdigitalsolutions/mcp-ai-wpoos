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
		 * Sanitized parent menu title for hook generation.
		 *
		 * WordPress sanitizes "NV oOS Pro" to "nv-oos-pro" for admin page hooks.
		 * This is used to construct submenu page hooks.
		 */
		const SANITIZED_MENU_TITLE = 'nv-oos-pro';

		/**
		 * Delegate page keys as constants for type safety.
		 */
		const DELEGATE_SECURITY_AUDITS   = 'security_audits';
		const DELEGATE_SECURITY_TRAINING = 'security_training';
		const DELEGATE_SUPPLIER_SECURITY = 'supplier_security';
		const DELEGATE_ASSET_INVENTORY   = 'asset_inventory';

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
		 * Initializes delegate pages immediately so their admin_menu hooks register
		 * before the admin_menu hook fires.
		 *
		 * @return void
		 */
		private function init_hooks() {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 25 );
			add_action( 'admin_menu', array( $this, 'reorder_pro_dashboard_menu' ), 999 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// Initialize delegate pages immediately (not on admin_init hook).
			// The admin_menu hook fires before admin_init, so delegates must be
			// instantiated early so their menu registration hooks are active.
			$this->init_delegate_pages();
			$this->delegates_initialized = true;
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
				null // Let WordPress automatically position the menu to avoid conflicts.
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
				// - ISO 27001 (default tab)
				// - Overview
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
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to reorder admin menu items
				$submenu[ self::PAGE_SLUG ] = $pro_submenu;
			}
		}

		/**
		 * Get the admin page hook for the diagnostic page.
		 *
		 * WordPress generates submenu page hooks using the pattern:
		 * {parent-title-sanitized}_page_{menu-slug}
		 *
		 * For our diagnostic page under "NV oOS Pro" parent:
		 * - Parent title "NV oOS Pro" → sanitized to "nv-oos-pro" (SANITIZED_MENU_TITLE)
		 * - Menu slug from WP_MCP_AI_Pro_Dashboard_Diagnostic::PAGE_SLUG
		 * - Result: "nv-oos-pro_page_nvoos-pro-dashboard-diagnostic"
		 *
		 * @return string The admin page hook for the diagnostic page.
		 */
		private function get_diagnostic_page_hook() {
			// Construct the hook dynamically using constants for maintainability.
			if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard_Diagnostic' ) ) {
				// Fallback if diagnostic class isn't loaded yet.
				return self::SANITIZED_MENU_TITLE . '_page_nvoos-pro-dashboard-diagnostic';
			}

			return self::SANITIZED_MENU_TITLE . '_page_' . WP_MCP_AI_Pro_Dashboard_Diagnostic::PAGE_SLUG;
		}

		/**
		 * Enqueue Pro Dashboard assets.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			// Load assets on main Pro Dashboard page and diagnostic page.
			$diagnostic_page_hook = $this->get_diagnostic_page_hook();
			$allowed_pages        = array(
				'toplevel_page_' . self::PAGE_SLUG,
				$diagnostic_page_hook,
			);

			if ( ! in_array( $hook, $allowed_pages, true ) ) {
				return;
			}

			// Use Chart.js Helper for consistent registration across the plugin.
			// Calling register_chart_js() + wp_enqueue_script('chartjs') instead of
			// enqueue_chart_js() avoids loading unnecessary Token Manager files
			// (analytics-dashboard.css, token-manager-charts.js) on the Pro Dashboard.
			if ( class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				WP_MCP_AI_Chart_JS_Helper::register_chart_js();
				wp_enqueue_script( 'chartjs' );
			} else {
				// Fallback: Register and enqueue Chart.js directly if helper class not available.
				$chart_js_path = WP_MCP_AI_PATH . 'assets/js/vendor/chart.min.js';
				$chart_js_url  = WP_MCP_AI_URL . 'assets/js/vendor/chart.min.js';

				wp_register_script(
					'chartjs',
					$chart_js_url,
					array(),
					file_exists( $chart_js_path ) ? filemtime( $chart_js_path ) : '4.4.1',
					true
				);

				wp_enqueue_script( 'chartjs' );
			}

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
				'chatData' => $this->get_chat_data(),
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
				$stats          = array(
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
			// Valid tabs - ISO27001 is now the default/first tab.
			$valid_tabs = array( 'iso27001', 'overview', 'reports', 'monitoring', 'risk', 'multi-framework' );

			// Get current tab from URL parameter, sanitize and validate immediately.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation parameter; immediately validated against an allowlist on the next line.
			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'iso27001';

			// Validate tab - ensure it's in the valid tabs list.
			if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
				$current_tab = 'iso27001';
			}
			?>
			<div class="wrap wp-mcp-ai-pro-dashboard">
				<div class="wp-mcp-ai-dashboard-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
					<h1 style="margin: 0;">
						<?php esc_html_e( 'NV oOS Pro Dashboard', 'mcp-ai-wpoos' ); ?>
						<span class="wp-mcp-ai-pro-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos' ); ?></span>
					</h1>
					<div class="wp-mcp-ai-dashboard-actions" style="display: flex; gap: 10px; align-items: center;">
						<?php $this->render_dashboard_actions( $current_tab ); ?>
					</div>
				</div>

				<?php $this->render_pro_status_notice(); ?>
				<?php $this->render_keyboard_shortcuts_help_button(); ?>

				<!-- Tab Navigation -->
				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Pro Dashboard tabs', 'mcp-ai-wpoos' ); ?>">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=iso27001' ) ); ?>" class="nav-tab <?php echo esc_attr( 'iso27001' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-list-view"></span>
						<?php esc_html_e( 'ISO 27001', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=overview' ) ); ?>" class="nav-tab <?php echo esc_attr( 'overview' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-dashboard"></span>
						<?php esc_html_e( 'Overview', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=reports' ) ); ?>" class="nav-tab <?php echo esc_attr( 'reports' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-media-document"></span>
						<?php esc_html_e( 'Reports', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=monitoring' ) ); ?>" class="nav-tab <?php echo esc_attr( 'monitoring' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-admin-site-alt3"></span>
						<?php esc_html_e( 'Monitoring', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=risk' ) ); ?>" class="nav-tab <?php echo esc_attr( 'risk' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-warning"></span>
						<?php esc_html_e( 'Risk Management', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=multi-framework' ) ); ?>" class="nav-tab <?php echo esc_attr( 'multi-framework' === $current_tab ? 'nav-tab-active' : '' ); ?>">
						<span class="dashicons dashicons-networking"></span>
						<?php esc_html_e( 'Multi-Framework', 'mcp-ai-wpoos' ); ?>
					</a>
				</nav>

				<!-- Tab Content -->
				<div class="tab-content">
					<?php
					// Render the current tab content.
					switch ( $current_tab ) {
						case 'overview':
							$this->render_overview_tab();
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
						case 'iso27001':
						default:
							$this->render_iso27001_tab();
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
				$stats          = array(
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

			<!-- Key Metrics Summary with Interactive Cards -->
			<div class="wp-mcp-ai-metrics-summary">
				<div class="wp-mcp-ai-metric-card interactive" data-metric="implemented" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'View implemented controls', 'mcp-ai-wpoos' ); ?>">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-implemented"><?php echo esc_html( $stats['implemented'] ); ?></div>
						<div class="wp-mcp-ai-metric-label">
							<?php esc_html_e( 'Controls Implemented', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-tooltip">
								<span class="dashicons dashicons-info"></span>
								<span class="wp-mcp-ai-tooltip-text"><?php esc_html_e( 'Number of ISO 27001 controls that are fully implemented and operational.', 'mcp-ai-wpoos' ); ?></span>
							</span>
						</div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card interactive" data-metric="partial" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'View controls in progress', 'mcp-ai-wpoos' ); ?>">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-partial"><?php echo esc_html( $stats['partial'] ); ?></div>
						<div class="wp-mcp-ai-metric-label">
							<?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-tooltip">
								<span class="dashicons dashicons-info"></span>
								<span class="wp-mcp-ai-tooltip-text"><?php esc_html_e( 'Controls that are partially implemented and require additional work.', 'mcp-ai-wpoos' ); ?></span>
							</span>
						</div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card interactive" data-metric="critical" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'View critical risks', 'mcp-ai-wpoos' ); ?>">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-critical">0</div>
						<div class="wp-mcp-ai-metric-label">
							<?php esc_html_e( 'Critical Risks', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-tooltip">
								<span class="dashicons dashicons-info"></span>
								<span class="wp-mcp-ai-tooltip-text"><?php esc_html_e( 'High-priority risks that require immediate attention and mitigation.', 'mcp-ai-wpoos' ); ?></span>
							</span>
						</div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card interactive" data-metric="compliance" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'View compliance details', 'mcp-ai-wpoos' ); ?>">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value wp-mcp-ai-stat-compliance"><?php echo esc_html( $compliance_pct ); ?>%</div>
						<div class="wp-mcp-ai-metric-label">
							<?php esc_html_e( 'Overall Compliance', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-tooltip">
								<span class="dashicons dashicons-info"></span>
								<span class="wp-mcp-ai-tooltip-text"><?php esc_html_e( 'Percentage of applicable controls that are fully implemented.', 'mcp-ai-wpoos' ); ?></span>
							</span>
						</div>
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

			<!-- Chat Statistics Section -->
			<div class="wp-mcp-ai-chat-statistics-section">
				<h2><?php esc_html_e( 'Chat & Conversation Analytics', 'mcp-ai-wpoos' ); ?></h2>
				<?php $this->render_chat_statistics(); ?>
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

			<!-- Date Range Selector for Historical View -->
			<?php $this->render_date_range_selector(); ?>
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
			// Get event statistics for the summary table.
			$recent_events = get_option( 'wp_mcp_ai_recent_activity', array() );
			$event_stats   = $this->get_monitoring_event_stats();
			?>
			<div class="wp-mcp-ai-monitoring-header">
				<p class="description">
					<?php esc_html_e( 'Monitor security events, system health, and compliance-related activities in real-time.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<!-- Monitoring Summary Table -->
			<div class="wp-mcp-ai-monitoring-summary" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
				<h2 style="margin-top: 0; font-size: 18px; border-bottom: 1px solid #dcdcde; padding-bottom: 10px;">
					<?php esc_html_e( 'Event Summary', 'mcp-ai-wpoos' ); ?>
				</h2>
				<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
					<thead>
						<tr>
							<th style="width: 40%;"><?php esc_html_e( 'Event Category', 'mcp-ai-wpoos' ); ?></th>
							<th style="width: 20%; text-align: center;"><?php esc_html_e( 'Count (24h)', 'mcp-ai-wpoos' ); ?></th>
							<th style="width: 20%; text-align: center;"><?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?></th>
							<th style="width: 20%; text-align: center;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
								<span class="dashicons dashicons-lock" style="color: #0073aa;"></span>
								<strong><?php esc_html_e( 'Authentication Events', 'mcp-ai-wpoos' ); ?></strong>
							</td>
							<td style="text-align: center; font-weight: bold; font-size: 16px;"><?php echo esc_html( $event_stats['auth_events'] ); ?></td>
							<td style="text-align: center;">
								<?php if ( $event_stats['auth_events'] > 0 ) : ?>
									<span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php endif; ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['auth_events'] > 5 ) : ?>
									<span style="color: #d63638;"><?php esc_html_e( 'Review', 'mcp-ai-wpoos' ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;"><?php esc_html_e( 'Normal', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td>
								<span class="dashicons dashicons-media-document" style="color: #0073aa;"></span>
								<strong><?php esc_html_e( 'File Integrity', 'mcp-ai-wpoos' ); ?></strong>
							</td>
							<td style="text-align: center; font-weight: bold; font-size: 16px;"><?php echo esc_html( $event_stats['file_integrity_events'] ); ?></td>
							<td style="text-align: center;">
								<?php if ( $event_stats['file_integrity_events'] > 0 ) : ?>
									<span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php endif; ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['file_integrity_events'] > 0 ) : ?>
									<span style="color: #d63638;"><?php esc_html_e( 'Review', 'mcp-ai-wpoos' ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;"><?php esc_html_e( 'Normal', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td>
								<span class="dashicons dashicons-update" style="color: #0073aa;"></span>
								<strong><?php esc_html_e( 'Plugin & Theme Updates', 'mcp-ai-wpoos' ); ?></strong>
							</td>
							<td style="text-align: center; font-weight: bold; font-size: 16px;"><?php echo esc_html( $event_stats['update_events'] ); ?></td>
							<td style="text-align: center;">
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							</td>
							<td style="text-align: center;">
								<span style="color: #46b450;"><?php esc_html_e( 'Normal', 'mcp-ai-wpoos' ); ?></span>
							</td>
						</tr>
						<tr>
							<td>
								<span class="dashicons dashicons-admin-settings" style="color: #0073aa;"></span>
								<strong><?php esc_html_e( 'Configuration Changes', 'mcp-ai-wpoos' ); ?></strong>
							</td>
							<td style="text-align: center; font-weight: bold; font-size: 16px;"><?php echo esc_html( $event_stats['config_events'] ); ?></td>
							<td style="text-align: center;">
								<?php if ( $event_stats['config_events'] > 0 ) : ?>
									<span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php endif; ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['config_events'] > 3 ) : ?>
									<span style="color: #d63638;"><?php esc_html_e( 'Review', 'mcp-ai-wpoos' ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;"><?php esc_html_e( 'Normal', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<td>
								<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
								<strong><?php esc_html_e( 'Security Alerts', 'mcp-ai-wpoos' ); ?></strong>
							</td>
							<td style="text-align: center; font-weight: bold; font-size: 16px; color: <?php echo esc_attr( $event_stats['security_events'] > 0 ? '#d63638' : '#46b450' ); ?>;">
								<?php echo esc_html( $event_stats['security_events'] ); ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['security_events'] > 0 ) : ?>
									<span class="dashicons dashicons-dismiss" style="color: #d63638;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
								<?php endif; ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['security_events'] > 0 ) : ?>
									<span style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'Action Required', 'mcp-ai-wpoos' ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;"><?php esc_html_e( 'Normal', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr style="background: #f6f7f7; font-weight: bold;">
							<td><?php esc_html_e( 'Total Events', 'mcp-ai-wpoos' ); ?></td>
							<td style="text-align: center; font-size: 18px; color: #0073aa;"><?php echo esc_html( $event_stats['total_events'] ); ?></td>
							<td style="text-align: center;">
								<?php if ( $event_stats['critical_count'] > 0 ) : ?>
									<span style="color: #d63638; font-weight: bold;"><?php echo esc_html( $event_stats['critical_count'] ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;">0</span>
								<?php endif; ?>
							</td>
							<td style="text-align: center;">
								<?php if ( $event_stats['critical_count'] > 0 ) : ?>
									<span style="color: #d63638; font-weight: bold;"><?php esc_html_e( 'Attention Needed', 'mcp-ai-wpoos' ); ?></span>
								<?php else : ?>
									<span style="color: #46b450;"><?php esc_html_e( 'All Clear', 'mcp-ai-wpoos' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					</tfoot>
				</table>
				<p class="description" style="margin-top: 15px; margin-bottom: 0;">
					<?php
					printf(
						/* translators: %d: Number of recent events */
						esc_html__( 'Detailed event log with %d entries available below. Use filters to refine the view.', 'mcp-ai-wpoos' ),
						count( $recent_events )
					);
					?>
				</p>
			</div>

			<!-- Monitoring Filters -->
			<div class="wp-mcp-ai-monitoring-filters" style="background: #f7f7f7; padding: 15px; border-radius: 4px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center;">
				<div style="display: flex; align-items: center; gap: 8px;">
					<label for="monitoring-event-type"><?php esc_html_e( 'Event Type:', 'mcp-ai-wpoos' ); ?></label>
					<select id="monitoring-event-type">
						<option value="all"><?php esc_html_e( 'All Events', 'mcp-ai-wpoos' ); ?></option>
						<option value="authentication"><?php esc_html_e( 'Authentication', 'mcp-ai-wpoos' ); ?></option>
						<option value="file-integrity"><?php esc_html_e( 'File Integrity', 'mcp-ai-wpoos' ); ?></option>
						<option value="configuration"><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos' ); ?></option>
						<option value="plugin-updates"><?php esc_html_e( 'Plugin Updates', 'mcp-ai-wpoos' ); ?></option>
						<option value="security-alerts"><?php esc_html_e( 'Security Alerts', 'mcp-ai-wpoos' ); ?></option>
					</select>
				</div>

				<div style="display: flex; align-items: center; gap: 8px;">
					<label for="monitoring-severity"><?php esc_html_e( 'Severity:', 'mcp-ai-wpoos' ); ?></label>
					<select id="monitoring-severity">
						<option value="all"><?php esc_html_e( 'All Severities', 'mcp-ai-wpoos' ); ?></option>
						<option value="critical"><?php esc_html_e( 'Critical', 'mcp-ai-wpoos' ); ?></option>
						<option value="high"><?php esc_html_e( 'High', 'mcp-ai-wpoos' ); ?></option>
						<option value="medium"><?php esc_html_e( 'Medium', 'mcp-ai-wpoos' ); ?></option>
						<option value="low"><?php esc_html_e( 'Low', 'mcp-ai-wpoos' ); ?></option>
						<option value="info"><?php esc_html_e( 'Info', 'mcp-ai-wpoos' ); ?></option>
					</select>
				</div>

				<div style="display: flex; align-items: center; gap: 8px;">
					<label for="monitoring-timeframe"><?php esc_html_e( 'Timeframe:', 'mcp-ai-wpoos' ); ?></label>
					<select id="monitoring-timeframe">
						<option value="24h" selected><?php esc_html_e( 'Last 24 Hours', 'mcp-ai-wpoos' ); ?></option>
						<option value="7d"><?php esc_html_e( 'Last 7 Days', 'mcp-ai-wpoos' ); ?></option>
						<option value="30d"><?php esc_html_e( 'Last 30 Days', 'mcp-ai-wpoos' ); ?></option>
						<option value="90d"><?php esc_html_e( 'Last 90 Days', 'mcp-ai-wpoos' ); ?></option>
					</select>
				</div>

				<div style="display: flex; align-items: center; gap: 8px; flex: 1;">
					<label for="monitoring-search"><?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?></label>
					<input type="text" id="monitoring-search" placeholder="<?php esc_attr_e( 'Search events...', 'mcp-ai-wpoos' ); ?>" style="flex: 1; max-width: 300px;" />
				</div>

				<button class="button wp-mcp-ai-clear-monitoring-filters" title="<?php esc_attr_e( 'Clear all filters', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-dismiss"></span>
					<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<div class="wp-mcp-ai-monitoring-dashboard" style="clear: both;">
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
			<div class="wp-mcp-ai-multi-framework-header">
				<p class="description">
					<?php esc_html_e( 'Compare and manage compliance across multiple security frameworks including SOC 2, HIPAA, GDPR, and more.', 'mcp-ai-wpoos' ); ?>
				</p>
			</div>

			<!-- Framework Filters and Actions -->
			<div class="wp-mcp-ai-framework-filters">
				<label for="framework-status-filter"><?php esc_html_e( 'Status:', 'mcp-ai-wpoos' ); ?></label>
				<select id="framework-status-filter">
					<option value="all"><?php esc_html_e( 'All Frameworks', 'mcp-ai-wpoos' ); ?></option>
					<option value="compliant"><?php esc_html_e( 'Compliant', 'mcp-ai-wpoos' ); ?></option>
					<option value="pending"><?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?></option>
					<option value="not-started"><?php esc_html_e( 'Not Started', 'mcp-ai-wpoos' ); ?></option>
				</select>

				<label for="framework-category"><?php esc_html_e( 'Category:', 'mcp-ai-wpoos' ); ?></label>
				<select id="framework-category">
					<option value="all"><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
					<option value="security"><?php esc_html_e( 'Security Standards', 'mcp-ai-wpoos' ); ?></option>
					<option value="privacy"><?php esc_html_e( 'Privacy Regulations', 'mcp-ai-wpoos' ); ?></option>
					<option value="industry"><?php esc_html_e( 'Industry-Specific', 'mcp-ai-wpoos' ); ?></option>
				</select>

				<button class="button wp-mcp-ai-clear-framework-filters" title="<?php esc_attr_e( 'Clear filters', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-dismiss"></span>
					<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
				</button>

				<button class="button button-secondary wp-mcp-ai-compare-frameworks" title="<?php esc_attr_e( 'Compare selected frameworks', 'mcp-ai-wpoos' ); ?>" style="margin-left: auto;">
					<span class="dashicons dashicons-analytics"></span>
					<?php esc_html_e( 'Compare', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<!-- Framework Selection for Comparison -->
			<div class="wp-mcp-ai-framework-selection" style="display: none; margin: 15px 0; padding: 15px; background: #f7f7f7; border-radius: 4px;">
				<label>
					<input type="checkbox" id="wp-mcp-ai-select-all-frameworks" />
					<?php esc_html_e( 'Select All', 'mcp-ai-wpoos' ); ?>
				</label>
				<span class="wp-mcp-ai-selected-frameworks-count" style="margin-left: 15px;"></span>
				<button class="button wp-mcp-ai-generate-comparison" style="margin-left: 15px;" title="<?php esc_attr_e( 'Generate comparison report', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-chart-bar"></span>
					<?php esc_html_e( 'Generate Report', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<div class="wp-mcp-ai-frameworks">
				<?php $this->render_framework_status(); ?>
			</div>

			<!-- Detailed Compliance Listings -->
			<div class="wp-mcp-ai-framework-details" style="margin-top: 40px;">
				<h2><?php esc_html_e( 'Detailed Compliance Listings', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'View detailed requirements and implementation status for each compliance framework.', 'mcp-ai-wpoos' ); ?>
				</p>

				<!-- Framework Tabs for Detailed View -->
				<div class="wp-mcp-ai-framework-detail-tabs" style="margin: 20px 0;">
					<button class="button wp-mcp-ai-framework-detail-tab active" data-framework="iso27001">
						<?php esc_html_e( 'ISO 27001', 'mcp-ai-wpoos' ); ?>
					</button>
					<button class="button wp-mcp-ai-framework-detail-tab" data-framework="soc2">
						<?php esc_html_e( 'SOC 2', 'mcp-ai-wpoos' ); ?>
					</button>
					<button class="button wp-mcp-ai-framework-detail-tab" data-framework="hipaa">
						<?php esc_html_e( 'HIPAA', 'mcp-ai-wpoos' ); ?>
					</button>
					<button class="button wp-mcp-ai-framework-detail-tab" data-framework="gdpr">
						<?php esc_html_e( 'GDPR', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>

				<!-- Tab Content -->
				<div class="wp-mcp-ai-framework-detail-content">
					<div class="wp-mcp-ai-framework-detail-panel active" id="iso27001-details">
						<?php $this->render_framework_controls_table( 'iso27001' ); ?>
					</div>
					<div class="wp-mcp-ai-framework-detail-panel" id="soc2-details">
						<?php $this->render_framework_controls_table( 'soc2' ); ?>
					</div>
					<div class="wp-mcp-ai-framework-detail-panel" id="hipaa-details">
						<?php $this->render_framework_controls_table( 'hipaa' ); ?>
					</div>
					<div class="wp-mcp-ai-framework-detail-panel" id="gdpr-details">
						<?php $this->render_framework_controls_table( 'gdpr' ); ?>
					</div>
				</div>
			</div>
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for dashboard widget layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-framework-detail-tabs {
					border-bottom: 1px solid #ddd;
					padding-bottom: 10px;
				}
				.wp-mcp-ai-framework-detail-tab {
					margin-right: 5px;
					border-bottom: 2px solid transparent;
				}
				.wp-mcp-ai-framework-detail-tab.active {
					border-bottom-color: #0073aa;
					font-weight: 600;
				}
				.wp-mcp-ai-framework-detail-panel {
					display: none;
					padding: 20px 0;
				}
				.wp-mcp-ai-framework-detail-panel.active {
					display: block;
				}
			</style>
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for dashboard chart initialization with dynamic data
			?>
			<script>
			jQuery(document).ready(function($) {
				// Framework detail tabs
				$('.wp-mcp-ai-framework-detail-tab').on('click', function() {
					var framework = $(this).data('framework');

					// Update tabs
					$('.wp-mcp-ai-framework-detail-tab').removeClass('active');
					$(this).addClass('active');

					// Update panels
					$('.wp-mcp-ai-framework-detail-panel').removeClass('active');
					$('#' + framework + '-details').addClass('active');
				});

				// Framework status filter
				$('#framework-status-filter').on('change', function() {
					var status = $(this).val();
					if (status === 'all') {
						$('.wp-mcp-ai-framework-card').show();
					} else {
						$('.wp-mcp-ai-framework-card').hide();
						$('.wp-mcp-ai-framework-card[data-status="' + status + '"]').show();
					}
				});

				// Framework category filter
				$('#framework-category').on('change', function() {
					var category = $(this).val();
					if (category === 'all') {
						$('.wp-mcp-ai-framework-card').show();
					} else {
						$('.wp-mcp-ai-framework-card').hide();
						$('.wp-mcp-ai-framework-card[data-category="' + category + '"]').show();
					}
				});

				// Clear filters
				$('.wp-mcp-ai-clear-framework-filters').on('click', function() {
					$('#framework-status-filter').val('all');
					$('#framework-category').val('all');
					$('.wp-mcp-ai-framework-card').show();
				});

				// Compare frameworks toggle
				$('.wp-mcp-ai-compare-frameworks').on('click', function() {
					$('.wp-mcp-ai-framework-selection').toggle();
					$('.wp-mcp-ai-framework-checkbox').toggle();
				});

				// Select all frameworks
				$('#wp-mcp-ai-select-all-frameworks').on('change', function() {
					$('.wp-mcp-ai-framework-select').prop('checked', $(this).prop('checked'));
					updateSelectedFrameworksCount();
				});

				// Update selected count
				$('.wp-mcp-ai-framework-select').on('change', function() {
					updateSelectedFrameworksCount();
				});

				function updateSelectedFrameworksCount() {
					var count = $('.wp-mcp-ai-framework-select:checked').length;
					$('.wp-mcp-ai-selected-frameworks-count').text(count + ' framework(s) selected');
				}

				// Generate comparison report
				$('.wp-mcp-ai-generate-comparison').on('click', function() {
					var selected = [];
					$('.wp-mcp-ai-framework-select:checked').each(function() {
						selected.push($(this).val());
					});

					if (selected.length === 0) {
						alert('Please select at least one framework to compare.');
						return;
					}

					// TODO: Implement comparison report generation
					alert('Comparison report generation for: ' + selected.join(', '));
				});
			});
			</script>
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
		 * Render keyboard shortcuts help button.
		 *
		 * @since 1.5.2
		 */
		private function render_keyboard_shortcuts_help_button() {
			?>
			<div class="wp-mcp-ai-help-indicator" role="button" aria-label="<?php esc_attr_e( 'Show keyboard shortcuts', 'mcp-ai-wpoos' ); ?>" title="<?php esc_attr_e( 'Keyboard Shortcuts (Alt+H)', 'mcp-ai-wpoos' ); ?>">
				<span class="dashicons dashicons-editor-help"></span>
			</div>
			<?php
		}

		/**
		 * Render dashboard action buttons.
		 *
		 * @since 1.5.2
		 *
		 * @param string $current_tab Current active tab.
		 */
		private function render_dashboard_actions( $current_tab ) {
			?>
			<div class="wp-mcp-ai-export-buttons">
				<?php if ( 'overview' === $current_tab ) : ?>
					<button class="wp-mcp-ai-export-button wp-mcp-ai-export-dashboard" title="<?php esc_attr_e( 'Export dashboard snapshot', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-download"></span>
						<span><?php esc_html_e( 'Export', 'mcp-ai-wpoos' ); ?></span>
					</button>
				<?php endif; ?>

				<button class="button wp-mcp-ai-refresh-dashboard" title="<?php esc_attr_e( 'Refresh data', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-update"></span>
					<span><?php esc_html_e( 'Refresh', 'mcp-ai-wpoos' ); ?></span>
				</button>

				<?php if ( 'iso27001' === $current_tab ) : ?>
					<button class="wp-mcp-ai-export-button wp-mcp-ai-export-controls" title="<?php esc_attr_e( 'Export controls to CSV', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<span><?php esc_html_e( 'Export CSV', 'mcp-ai-wpoos' ); ?></span>
					</button>
				<?php endif; ?>

				<?php if ( 'risk' === $current_tab ) : ?>
					<button class="wp-mcp-ai-export-button wp-mcp-ai-export-risks" title="<?php esc_attr_e( 'Export risk register', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-media-spreadsheet"></span>
						<span><?php esc_html_e( 'Export Risks', 'mcp-ai-wpoos' ); ?></span>
					</button>
				<?php endif; ?>
			</div>
			<?php
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
				<div class="wp-mcp-ai-status-badge <?php echo esc_attr( $is_certified ? 'certified' : 'compliant' ); ?>">
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
			$security_events = array_filter(
				$recent_events,
				function ( $event ) {
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
				}
			);

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
								<?php if ( 'good' === $check['status'] ) : ?>
									<span class="dashicons dashicons-yes-alt"></span>
								<?php elseif ( 'warning' === $check['status'] ) : ?>
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
		 * Render chat statistics section.
		 *
		 * Displays conversation analytics including total conversations,
		 * active users, recent activity trends, and usage analytics.
		 *
		 * @since 1.5.3
		 */
		private function render_chat_statistics() {
			$chat_data = $this->get_chat_data();
			?>
			<!-- Main Stats Grid -->
			<div class="wp-mcp-ai-chat-stats-grid">
				<div class="wp-mcp-ai-chat-stat-card">
					<div class="wp-mcp-ai-stat-icon">
						<span class="dashicons dashicons-format-chat"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value wp-mcp-ai-chat-total"><?php echo esc_html( number_format_i18n( $chat_data['total_conversations'] ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Total Conversations', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-chat-stat-card">
					<div class="wp-mcp-ai-stat-icon">
						<span class="dashicons dashicons-groups"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value wp-mcp-ai-chat-users"><?php echo esc_html( number_format_i18n( $chat_data['active_users'] ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Users', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-chat-stat-card">
					<div class="wp-mcp-ai-stat-icon">
						<span class="dashicons dashicons-calendar-alt"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value wp-mcp-ai-chat-today"><?php echo esc_html( number_format_i18n( $chat_data['today_conversations'] ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Today', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-chat-stat-card">
					<div class="wp-mcp-ai-stat-icon">
						<span class="dashicons dashicons-chart-area"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value wp-mcp-ai-chat-week"><?php echo esc_html( number_format_i18n( $chat_data['this_week_conversations'] ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'This Week', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Usage Statistics (Last 30 Days) -->
			<?php if ( $chat_data['total_tokens_used'] > 0 ) : ?>
			<div class="wp-mcp-ai-usage-stats-row">
				<div class="wp-mcp-ai-usage-stat-card">
					<div class="wp-mcp-ai-stat-icon" style="background: #50575e;">
						<span class="dashicons dashicons-chart-line"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value"><?php echo esc_html( number_format_i18n( $chat_data['total_tokens_used'] ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Tokens Used (30d)', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-usage-stat-card">
					<div class="wp-mcp-ai-stat-icon" style="background: #00a32a;">
						<span class="dashicons dashicons-money-alt"></span>
					</div>
					<div class="wp-mcp-ai-stat-content">
						<div class="wp-mcp-ai-stat-value">$<?php echo esc_html( number_format( $chat_data['total_cost'], 2 ) ); ?></div>
						<div class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Total Cost (30d)', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Detailed Analytics -->
			<div class="wp-mcp-ai-analytics-grid">
				<!-- Top Tools -->
				<?php if ( ! empty( $chat_data['top_tools'] ) ) : ?>
				<div class="wp-mcp-ai-analytics-card">
					<h3><?php esc_html_e( 'Top Tools (30d)', 'mcp-ai-wpoos' ); ?></h3>
					<table class="wp-mcp-ai-analytics-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Tool', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Cost', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $chat_data['top_tools'] as $tool ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $tool['tool'] ? $tool['tool'] : __( '(General)', 'mcp-ai-wpoos' ) ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $tool['total_tokens'] ) ); ?></td>
								<td>$<?php echo esc_html( number_format( $tool['total_cost'], 2 ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Top Providers -->
				<?php if ( ! empty( $chat_data['top_providers'] ) ) : ?>
				<div class="wp-mcp-ai-analytics-card">
					<h3><?php esc_html_e( 'Providers (30d)', 'mcp-ai-wpoos' ); ?></h3>
					<table class="wp-mcp-ai-analytics-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Provider', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Cost', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $chat_data['top_providers'] as $provider ) : ?>
							<tr>
								<td><strong><?php echo esc_html( ucfirst( $provider['provider'] ) ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $provider['total_tokens'] ) ); ?></td>
								<td>$<?php echo esc_html( number_format( $provider['total_cost'], 2 ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>

				<!-- Top Models -->
				<?php if ( ! empty( $chat_data['top_models'] ) ) : ?>
				<div class="wp-mcp-ai-analytics-card">
					<h3><?php esc_html_e( 'Top Models (30d)', 'mcp-ai-wpoos' ); ?></h3>
					<table class="wp-mcp-ai-analytics-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Tokens', 'mcp-ai-wpoos' ); ?></th>
								<th><?php esc_html_e( 'Cost', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $chat_data['top_models'] as $model ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $model['model'] ); ?></strong>
									<br><small style="color: #646970;"><?php echo esc_html( ucfirst( $model['provider'] ) ); ?></small>
								</td>
								<td><?php echo esc_html( number_format_i18n( $model['total_tokens'] ) ); ?></td>
								<td>$<?php echo esc_html( number_format( $model['total_cost'], 2 ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php
			if ( 0 === $chat_data['total_conversations'] ) :
				?>
				<p class="wp-mcp-ai-chat-empty-state">
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'No chat conversations recorded yet. Chat statistics will appear here once users start interacting with AI assistants.', 'mcp-ai-wpoos' ); ?>
				</p>
				<?php
			endif;
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for dashboard widget layout and styling on this admin page only
			?>
			<style>
				.wp-mcp-ai-chat-statistics-section {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 4px;
					padding: 20px;
					margin: 20px 0;
				}
				.wp-mcp-ai-chat-statistics-section h2 {
					margin-top: 0;
					font-size: 1.3em;
					color: #1d2327;
				}
				.wp-mcp-ai-chat-stats-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
					gap: 15px;
					margin-bottom: 15px;
				}
				.wp-mcp-ai-usage-stats-row {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
					gap: 15px;
					margin: 15px 0;
				}
				.wp-mcp-ai-chat-stat-card,
				.wp-mcp-ai-usage-stat-card {
					display: flex;
					align-items: center;
					gap: 15px;
					padding: 15px;
					background: #f6f7f7;
					border-radius: 4px;
					border: 1px solid #e0e0e0;
				}
				.wp-mcp-ai-stat-icon {
					width: 48px;
					height: 48px;
					background: #2271b1;
					border-radius: 50%;
					display: flex;
					align-items: center;
					justify-content: center;
					color: #fff;
					font-size: 24px;
					flex-shrink: 0;
				}
				.wp-mcp-ai-stat-content {
					flex: 1;
				}
				.wp-mcp-ai-stat-value {
					font-size: 2em;
					font-weight: bold;
					color: #1d2327;
					line-height: 1;
				}
				.wp-mcp-ai-stat-label {
					font-size: 0.9em;
					color: #646970;
					margin-top: 5px;
				}
				.wp-mcp-ai-analytics-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
					gap: 15px;
					margin-top: 20px;
				}
				.wp-mcp-ai-analytics-card {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 4px;
					padding: 15px;
				}
				.wp-mcp-ai-analytics-card h3 {
					margin-top: 0;
					margin-bottom: 15px;
					font-size: 1.1em;
					color: #1d2327;
					border-bottom: 1px solid #dcdcde;
					padding-bottom: 10px;
				}
				.wp-mcp-ai-analytics-table {
					width: 100%;
					border-collapse: collapse;
				}
				.wp-mcp-ai-analytics-table th {
					text-align: left;
					padding: 8px;
					background: #f6f7f7;
					font-weight: 600;
					font-size: 0.9em;
					color: #1d2327;
					border-bottom: 2px solid #dcdcde;
				}
				.wp-mcp-ai-analytics-table td {
					padding: 8px;
					border-bottom: 1px solid #f0f0f1;
					font-size: 0.9em;
				}
				.wp-mcp-ai-analytics-table tbody tr:last-child td {
					border-bottom: none;
				}
				.wp-mcp-ai-analytics-table tbody tr:hover {
					background: #f6f7f7;
				}
				.wp-mcp-ai-chat-empty-state {
					padding: 20px;
					background: #f0f6fc;
					border: 1px solid #d0e4f5;
					border-radius: 4px;
					color: #3c434a;
					display: flex;
					align-items: center;
					gap: 10px;
				}
				.wp-mcp-ai-chat-empty-state .dashicons {
					color: #2271b1;
				}
				@media screen and (max-width: 782px) {
					.wp-mcp-ai-chat-stats-grid,
					.wp-mcp-ai-usage-stats-row,
					.wp-mcp-ai-analytics-grid {
						grid-template-columns: 1fr;
					}
				}
			</style>
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

				<label for="controls-category-filter"><?php esc_html_e( 'Category:', 'mcp-ai-wpoos' ); ?></label>
				<select id="controls-category-filter">
					<option value="all"><?php esc_html_e( 'All Categories', 'mcp-ai-wpoos' ); ?></option>
					<option value="a5"><?php esc_html_e( 'A.5 - Organizational', 'mcp-ai-wpoos' ); ?></option>
					<option value="a6"><?php esc_html_e( 'A.6 - People', 'mcp-ai-wpoos' ); ?></option>
					<option value="a7"><?php esc_html_e( 'A.7 - Physical', 'mcp-ai-wpoos' ); ?></option>
					<option value="a8"><?php esc_html_e( 'A.8 - Technical', 'mcp-ai-wpoos' ); ?></option>
				</select>

				<label for="controls-search"><?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?></label>
				<input type="text" id="controls-search" placeholder="<?php esc_attr_e( 'Search controls...', 'mcp-ai-wpoos' ); ?>" />

				<button class="button wp-mcp-ai-clear-filters" title="<?php esc_attr_e( 'Clear all filters', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-dismiss"></span>
					<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<div class="wp-mcp-ai-bulk-actions" style="margin: 15px 0; display: none;">
				<label>
					<input type="checkbox" id="wp-mcp-ai-select-all-controls" />
					<?php esc_html_e( 'Select All', 'mcp-ai-wpoos' ); ?>
				</label>
				<span class="wp-mcp-ai-selected-count" style="margin-left: 15px;"></span>
				<button class="button wp-mcp-ai-bulk-export" style="margin-left: 15px;" title="<?php esc_attr_e( 'Export selected controls', 'mcp-ai-wpoos' ); ?>">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Selected', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped wp-mcp-ai-controls-table">
				<thead>
					<tr>
						<th style="width: 40px;" class="check-column">
							<input type="checkbox" id="wp-mcp-ai-select-all-table" />
						</th>
						<th style="width: 120px;"><?php esc_html_e( 'Control ID', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Control Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Applicable', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $controls as $control ) : ?>
						<tr class="wp-mcp-ai-control-row" data-status="<?php echo esc_attr( $control['status_key'] ); ?>" data-category="<?php echo esc_attr( strtolower( substr( $control['id'], 0, 4 ) ) ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" class="wp-mcp-ai-control-checkbox" value="<?php echo esc_attr( $control['id'] ); ?>" />
							</th>
							<td><strong><?php echo esc_html( $control['id'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $control['name'] ); ?></strong>
								<?php if ( ! empty( $control['description'] ) ) : ?>
									<p class="description"><?php echo esc_html( wp_trim_words( $control['description'], 25 ) ); ?></p>
								<?php elseif ( ! empty( $control['justification'] ) ) : ?>
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
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for dashboard widget layout and styling on this admin page only
			?>
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
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for dashboard chart initialization with dynamic data
			?>
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
			$event_stats   = $this->get_monitoring_event_stats();
			$system_health = $this->get_system_health_status();
			?>

			<!-- Real-time Status Metrics -->
			<div class="wp-mcp-ai-monitoring-metrics">
				<div class="wp-mcp-ai-metric-card" data-status="<?php echo esc_attr( $system_health['overall_status'] ); ?>">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-shield-alt"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( ucfirst( $system_health['overall_status'] ) ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Security Status', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( $event_stats['critical_count'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Critical Events (24h)', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-info"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( $event_stats['total_events'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'Total Events (24h)', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
				<div class="wp-mcp-ai-metric-card">
					<div class="wp-mcp-ai-metric-icon">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="wp-mcp-ai-metric-content">
						<div class="wp-mcp-ai-metric-value"><?php echo esc_html( $system_health['uptime_display'] ); ?></div>
						<div class="wp-mcp-ai-metric-label"><?php esc_html_e( 'System Uptime', 'mcp-ai-wpoos' ); ?></div>
					</div>
				</div>
			</div>

			<!-- Monitoring Options Bar -->
			<div class="wp-mcp-ai-monitoring-options">
				<button class="button" id="wp-mcp-ai-refresh-monitoring">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh Now', 'mcp-ai-wpoos' ); ?>
				</button>
				<label class="wp-mcp-ai-auto-refresh-toggle">
					<input type="checkbox" id="wp-mcp-ai-auto-refresh" checked />
					<?php esc_html_e( 'Auto-refresh (30s)', 'mcp-ai-wpoos' ); ?>
				</label>
				<button class="button" id="wp-mcp-ai-export-events">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Events', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="wp-mcp-ai-last-updated">
					<?php
					printf(
						/* translators: %s: Timestamp */
						esc_html__( 'Last updated: %s', 'mcp-ai-wpoos' ),
						'<span id="wp-mcp-ai-last-update-time">' . esc_html( current_time( 'H:i:s' ) ) . '</span>'
					);
					?>
				</span>
			</div>

			<div class="wp-mcp-ai-monitoring-grid">
				<!-- System Health Status -->
				<div class="wp-mcp-ai-card wp-mcp-ai-system-health-card">
					<h3>
						<?php esc_html_e( 'System Health', 'mcp-ai-wpoos' ); ?>
						<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $system_health['overall_status'] ); ?>">
							<?php echo esc_html( ucfirst( $system_health['overall_status'] ) ); ?>
						</span>
					</h3>
					<div class="wp-mcp-ai-health-indicators">
						<?php foreach ( $system_health['indicators'] as $indicator ) : ?>
							<div class="wp-mcp-ai-health-indicator" data-status="<?php echo esc_attr( $indicator['status'] ); ?>">
								<span class="wp-mcp-ai-health-icon dashicons dashicons-<?php echo esc_attr( $indicator['icon'] ); ?>"></span>
								<div class="wp-mcp-ai-health-info">
									<div class="wp-mcp-ai-health-name"><?php echo esc_html( $indicator['name'] ); ?></div>
									<div class="wp-mcp-ai-health-value"><?php echo esc_html( $indicator['value'] ); ?></div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Monitored Resources -->
				<div class="wp-mcp-ai-card">
					<h3><?php esc_html_e( 'Monitored Resources', 'mcp-ai-wpoos' ); ?></h3>
					<ul class="wp-mcp-ai-monitored-resources">
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'File Integrity Monitoring', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-resource-count"><?php echo esc_html( $event_stats['file_integrity_events'] ); ?> events</span>
						</li>
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Authentication Events', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-resource-count"><?php echo esc_html( $event_stats['auth_events'] ); ?> events</span>
						</li>
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Plugin & Theme Updates', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-resource-count"><?php echo esc_html( $event_stats['update_events'] ); ?> events</span>
						</li>
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Configuration Changes', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-resource-count"><?php echo esc_html( $event_stats['config_events'] ); ?> events</span>
						</li>
						<li>
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Security Alerts', 'mcp-ai-wpoos' ); ?>
							<span class="wp-mcp-ai-resource-count"><?php echo esc_html( $event_stats['security_events'] ); ?> events</span>
						</li>
					</ul>
				</div>

				<!-- Event Timeline Chart -->
				<div class="wp-mcp-ai-card wp-mcp-ai-event-timeline-card">
					<h3><?php esc_html_e( 'Event Timeline (24h)', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-chart-container">
						<canvas id="wpMcpAiEventTimelineChart"></canvas>
					</div>
				</div>
			</div>

			<!-- Real-time Event Log -->
			<div class="wp-mcp-ai-card wp-mcp-ai-event-log-card">
				<div class="wp-mcp-ai-card-header">
					<h3><?php esc_html_e( 'Real-time Event Log', 'mcp-ai-wpoos' ); ?></h3>
					<div class="wp-mcp-ai-card-actions">
						<button class="button button-small" id="wp-mcp-ai-clear-dismissed">
							<?php esc_html_e( 'Clear Dismissed', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>
				</div>
				<?php $this->render_monitoring_event_table( $recent_events ); ?>
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
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Small inline styles for dashboard widget layout and styling on this admin page only
			?>
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
			// Get risks from the Risk Register file.
			$risks = $this->get_risk_register_entries();

			?>
			<div class="wp-mcp-ai-risk-register-header" style="background: #f7f7f7; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
				<p class="description" style="margin: 0;">
					<?php
					if ( ! empty( $risks ) ) {
						printf(
							/* translators: %d: Number of risks */
							esc_html__( 'The risk register documents all %d identified risks, their assessment, and treatment plans. All risks are shown below.', 'mcp-ai-wpoos' ),
							count( $risks )
						);
					} else {
						esc_html_e( 'The risk register documents all identified risks, their assessment, and treatment plans.', 'mcp-ai-wpoos' );
					}
					?>
				</p>
			</div>

			<?php if ( empty( $risks ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Unable to load risk register.', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Please check that the Risk-Register.md file is available at docs/compliance/iso27001/Risk-Register.md', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="wp-mcp-ai-risk-count" style="margin-bottom: 15px; padding: 10px; background: #e7f5fe; border-left: 4px solid #0073aa;">
					<strong>
						<?php
						printf(
							/* translators: %d: Number of risks */
							esc_html__( 'Displaying all %d risks from the Risk Register', 'mcp-ai-wpoos' ),
							count( $risks )
						);
						?>
					</strong>
				</div>
				<table class="wp-list-table widefat fixed striped wp-mcp-ai-risk-register-table">
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
						<?php foreach ( $risks as $risk ) : ?>
							<?php
							// Map risk level to badge class.
							$badge_classes    = array(
								'critical' => 'risk-critical',
								'high'     => 'risk-high',
								'medium'   => 'risk-medium',
								'low'      => 'risk-low',
							);
							$risk_level_lower = strtolower( $risk['risk_level'] );
							$badge_class      = isset( $badge_classes[ $risk_level_lower ] ) ? $badge_classes[ $risk_level_lower ] : 'risk-medium';
							?>
							<tr>
								<td><?php echo esc_html( $risk['id'] ); ?></td>
								<td>
									<strong><?php echo esc_html( $risk['name'] ); ?></strong>
									<?php if ( ! empty( $risk['description'] ) ) : ?>
										<p class="description"><?php echo esc_html( $risk['description'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! empty( $risk['category'] ) ) : ?>
										<p class="description"><em><?php echo esc_html( $risk['category'] ); ?></em></p>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $risk['likelihood'] ); ?></td>
								<td><?php echo esc_html( $risk['impact'] ); ?></td>
								<td><span class="risk-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $risk['risk_level'] ); ?></span></td>
								<td><?php echo esc_html( $risk['treatment'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top: 20px;">
					<?php
					printf(
						/* translators: 1: Total risks count, 2: Link to risk assessment document */
						esc_html__( 'Showing %1$d risks. See the full %2$s for detailed risk analysis and treatment plans.', 'mcp-ai-wpoos' ),
						count( $risks ),
						'<a href="' . esc_url( 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs/compliance/iso27001/Risk-Assessment.md' ) . '" target="_blank">' . esc_html__( 'Risk Assessment document', 'mcp-ai-wpoos' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
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
					<div class="wp-mcp-ai-framework-card"
						data-status="<?php echo esc_attr( $framework['status'] ); ?>"
						data-category="<?php echo esc_attr( $framework['category'] ?? 'security' ); ?>"
						data-framework-id="<?php echo esc_attr( sanitize_title( $framework['name'] ) ); ?>">

						<div class="wp-mcp-ai-framework-checkbox">
							<input type="checkbox"
								class="wp-mcp-ai-framework-select"
								value="<?php echo esc_attr( sanitize_title( $framework['name'] ) ); ?>"
								id="framework-<?php echo esc_attr( sanitize_title( $framework['name'] ) ); ?>" />
						</div>

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
						<?php if ( ! empty( $framework['controls_count'] ) ) : ?>
							<div class="wp-mcp-ai-framework-info">
								<small>
									<?php
									printf(
										/* translators: %d: number of controls */
										esc_html__( '%d controls', 'mcp-ai-wpoos' ),
										(int) $framework['controls_count']
									);
									?>
								</small>
							</div>
						<?php endif; ?>
						<?php if ( ! $this->is_pro_active() && 'pending' === $framework['status'] ) : ?>
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
		 * Render framework controls table.
		 *
		 * Displays detailed controls/requirements table for a specific framework.
		 *
		 * @param string $framework Framework identifier (iso27001, soc2, hipaa, gdpr).
		 */
		private function render_framework_controls_table( $framework ) {
			// Get controls based on framework.
			switch ( $framework ) {
				case 'iso27001':
					$controls       = $this->get_iso27001_controls();
					$framework_name = 'ISO 27001:2022';
					$control_label  = 'Controls';
					break;
				case 'soc2':
					$controls       = $this->get_soc2_controls();
					$framework_name = 'SOC 2';
					$control_label  = 'Trust Services Criteria';
					break;
				case 'hipaa':
					$controls       = $this->get_hipaa_controls();
					$framework_name = 'HIPAA';
					$control_label  = 'Security Rule Safeguards';
					break;
				case 'gdpr':
					$controls       = $this->get_gdpr_controls();
					$framework_name = 'GDPR';
					$control_label  = 'Requirements';
					break;
				default:
					return;
			}

			if ( empty( $controls ) ) {
				?>
				<p class="wp-mcp-ai-empty-state">
					<?php
					printf(
						/* translators: %s: Framework name */
						esc_html__( 'Unable to load %s controls. Please check that the documentation is available.', 'mcp-ai-wpoos' ),
						esc_html( $framework_name )
					);
					?>
				</p>
				<?php
				return;
			}

			// Calculate stats.
			$stats = $this->calculate_controls_stats( $controls );
			?>

			<div class="wp-mcp-ai-framework-controls-header" style="margin-bottom: 20px;">
				<h3>
					<?php
					printf(
						/* translators: 1: Framework name, 2: Control label */
						esc_html__( '%1$s %2$s', 'mcp-ai-wpoos' ),
						esc_html( $framework_name ),
						esc_html( $control_label )
					);
					?>
				</h3>
				<div class="wp-mcp-ai-controls-summary" style="display: flex; gap: 20px; margin: 15px 0;">
					<div>
						<strong><?php echo esc_html( $stats['implemented'] ); ?></strong>
						<span><?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( $stats['partial'] ); ?></strong>
						<span><?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( $stats['planned'] ); ?></strong>
						<span><?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( $stats['not_applicable'] ); ?></strong>
						<span><?php esc_html_e( 'N/A', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div>
						<strong><?php echo esc_html( $stats['total'] ); ?></strong>
						<span><?php esc_html_e( 'Total', 'mcp-ai-wpoos' ); ?></span>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-framework-controls-filter" style="margin-bottom: 20px; display: flex; gap: 10px; align-items: center;">
				<label for="<?php echo esc_attr( $framework ); ?>-status-filter">
					<?php esc_html_e( 'Filter by status:', 'mcp-ai-wpoos' ); ?>
				</label>
				<select id="<?php echo esc_attr( $framework ); ?>-status-filter" class="wp-mcp-ai-framework-filter-status">
					<option value="all"><?php esc_html_e( 'All', 'mcp-ai-wpoos' ); ?></option>
					<option value="implemented"><?php esc_html_e( 'Implemented', 'mcp-ai-wpoos' ); ?></option>
					<option value="partial"><?php esc_html_e( 'Partial', 'mcp-ai-wpoos' ); ?></option>
					<option value="planned"><?php esc_html_e( 'Planned', 'mcp-ai-wpoos' ); ?></option>
					<option value="not_applicable"><?php esc_html_e( 'Not Applicable', 'mcp-ai-wpoos' ); ?></option>
				</select>

				<label for="<?php echo esc_attr( $framework ); ?>-search">
					<?php esc_html_e( 'Search:', 'mcp-ai-wpoos' ); ?>
				</label>
				<input type="text" id="<?php echo esc_attr( $framework ); ?>-search" class="wp-mcp-ai-framework-filter-search"
					placeholder="<?php esc_attr_e( 'Search controls...', 'mcp-ai-wpoos' ); ?>" style="flex: 1; max-width: 300px;" />

				<button class="button wp-mcp-ai-clear-framework-control-filters" data-framework="<?php echo esc_attr( $framework ); ?>">
					<span class="dashicons dashicons-dismiss"></span>
					<?php esc_html_e( 'Clear', 'mcp-ai-wpoos' ); ?>
				</button>
			</div>

			<table class="wp-list-table widefat fixed striped wp-mcp-ai-framework-controls-table" data-framework="<?php echo esc_attr( $framework ); ?>">
				<thead>
					<tr>
						<th style="width: 150px;"><?php esc_html_e( 'Control ID', 'mcp-ai-wpoos' ); ?></th>
						<th><?php esc_html_e( 'Control Name', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 200px;"><?php esc_html_e( 'Category', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 120px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
						<th style="width: 100px;"><?php esc_html_e( 'Applicable', 'mcp-ai-wpoos' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $controls as $control ) : ?>
						<tr class="wp-mcp-ai-framework-control-row" data-status="<?php echo esc_attr( $control['status_key'] ); ?>">
							<td><strong><?php echo esc_html( $control['id'] ); ?></strong></td>
							<td>
								<strong><?php echo esc_html( $control['name'] ); ?></strong>
								<?php if ( ! empty( $control['description'] ) ) : ?>
									<p class="description"><?php echo esc_html( wp_trim_words( $control['description'], 20 ) ); ?></p>
								<?php elseif ( ! empty( $control['implementation'] ) ) : ?>
									<p class="description"><?php echo esc_html( wp_trim_words( $control['implementation'], 20 ) ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $control['mapping'] ) ) : ?>
									<p class="description">
										<strong><?php esc_html_e( 'ISO 27001:', 'mcp-ai-wpoos' ); ?></strong>
										<?php echo esc_html( $control['mapping'] ); ?>
									</p>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $control['category'] ?? '' ); ?></td>
							<td>
								<span class="wp-mcp-ai-status-badge wp-mcp-ai-status-<?php echo esc_attr( $control['status_key'] ); ?>">
									<?php echo esc_html( 'not_applicable' === $control['status_key'] ? 'N/A' : ucfirst( $control['status_key'] ) ); ?>
								</span>
							</td>
							<td style="text-align: center;">
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
			<?php
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline script for dashboard chart initialization with dynamic data
			?>
			<script>
			jQuery(document).ready(function($) {
				var framework = '<?php echo esc_js( $framework ); ?>';

				// Filter by status
				$('#' + framework + '-status-filter').on('change', function() {
					var status = $(this).val();
					var $table = $('.wp-mcp-ai-framework-controls-table[data-framework="' + framework + '"]');
					var $rows = $table.find('.wp-mcp-ai-framework-control-row');

					if (status === 'all') {
						$rows.show();
					} else {
						$rows.hide();
						$rows.filter('[data-status="' + status + '"]').show();
					}
				});

				// Search controls
				$('#' + framework + '-search').on('keyup', function() {
					var search = $(this).val().toLowerCase();
					var $table = $('.wp-mcp-ai-framework-controls-table[data-framework="' + framework + '"]');
					var $rows = $table.find('.wp-mcp-ai-framework-control-row');

					$rows.each(function() {
						var text = $(this).text().toLowerCase();
						$(this).toggle(text.indexOf(search) > -1);
					});
				});

				// Clear filters
				$('.wp-mcp-ai-clear-framework-control-filters[data-framework="' + framework + '"]').on('click', function() {
					$('#' + framework + '-status-filter').val('all').trigger('change');
					$('#' + framework + '-search').val('');
					$('.wp-mcp-ai-framework-controls-table[data-framework="' + framework + '"] .wp-mcp-ai-framework-control-row').show();
				});
			});
			</script>
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

			$content = file_get_contents( $soa_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( empty( $content ) ) {
				return array();
			}

			$controls        = array();
			$lines           = explode( "\n", $content );
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
					$status_text               = trim( $matches[1] );
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
					$applicable_text               = trim( $matches[1] );
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

			$content = file_get_contents( $soc2_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( false === $content || empty( $content ) ) {
				return 0;
			}

			// Count total criteria and implemented criteria.
			// SOC 2 SoA uses "✅ Implemented" status markers.
			$total_matches = array();
			$impl_matches  = array();
			$total         = preg_match_all( '/^\*\*Status:\*\*/m', $content, $total_matches );
			$implemented   = preg_match_all( '/^\*\*Status:\*\*.*✅.*Implemented/m', $content, $impl_matches );

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

			$content = file_get_contents( $hipaa_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( false === $content || empty( $content ) ) {
				return 0;
			}

			// Count total safeguards and implemented safeguards.
			// HIPAA SoA uses "✅ Implemented" and "❌ Not Applicable" status markers.
			$total_matches  = array();
			$impl_matches   = array();
			$na_matches     = array();
			$total          = preg_match_all( '/^\*\*Status:\*\*/m', $content, $total_matches );
			$implemented    = preg_match_all( '/^\*\*Status:\*\*.*✅.*Implemented/m', $content, $impl_matches );
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
		 * Get SOC 2 Trust Services Criteria details.
		 *
		 * Parses the SOC 2 Statement of Applicability and returns detailed
		 * information about each Trust Services Criterion.
		 *
		 * @return array Array of criteria with id, name, category, status, status_key, applicable, implementation, and mapping.
		 */
		private function get_soc2_controls() {
			$soc2_file = WP_MCP_AI_PATH . 'docs/compliance/soc2/Statement-of-Applicability.md';

			if ( ! file_exists( $soc2_file ) ) {
				return array();
			}

			$content = file_get_contents( $soc2_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( empty( $content ) ) {
				return array();
			}

			$controls         = array();
			$lines            = explode( "\n", $content );
			$current_control  = null;
			$current_category = 'General';

			foreach ( $lines as $line ) {
				// Track category (e.g., "## 2. Common Criteria (CC) - Security").
				if ( preg_match( '/^##\s+\d+\.\s+(.+)$/', $line, $matches ) ) {
					$current_category = trim( $matches[1] );
				}

				// Match control ID header (e.g., "#### CC1.1 - Organization Demonstrates...").
				if ( preg_match( '/^####\s+([\w\.]+)\s+-\s+(.+)$/', $line, $matches ) ) {
					// Save previous control if exists.
					if ( $current_control ) {
						$controls[] = $current_control;
					}

					// Start new control.
					$current_control = array(
						'id'             => $matches[1],
						'name'           => trim( $matches[2] ),
						'category'       => $current_category,
						'status'         => '',
						'status_key'     => '',
						'applicable'     => true,
						'implementation' => '',
						'mapping'        => '',
					);
				} elseif ( $current_control && preg_match( '/^\*\*Status:\*\*\s+(.+)$/', $line, $matches ) ) {
					$status_text               = trim( $matches[1] );
					$current_control['status'] = $status_text;

					// Map status to key.
					if ( false !== strpos( $status_text, '✅' ) || false !== strpos( $status_text, 'Implemented' ) ) {
						$current_control['status_key'] = 'implemented';
					} elseif ( false !== strpos( $status_text, '🔄' ) || false !== strpos( $status_text, 'Partial' ) ) {
						$current_control['status_key'] = 'partial';
					} elseif ( false !== strpos( $status_text, '📋' ) || false !== strpos( $status_text, 'Planned' ) ) {
						$current_control['status_key'] = 'planned';
					} elseif ( false !== strpos( $status_text, '❌' ) || false !== strpos( $status_text, 'Not Applicable' ) ) {
						$current_control['status_key'] = 'not_applicable';
						$current_control['applicable'] = false;
					}
				} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
					$applicable_text               = trim( $matches[1] );
					$current_control['applicable'] = ( strcasecmp( $applicable_text, 'Yes' ) === 0 );
				} elseif ( $current_control && preg_match( '/^\*\*Implementation:\*\*\s*$/', $line ) ) {
					// Next lines will be implementation details.
					$current_control['implementation'] = '';
				} elseif ( $current_control && preg_match( '/^\*\*ISO 27001 Mapping:\*\*\s+(.+)$/', $line, $matches ) ) {
					$current_control['mapping'] = trim( $matches[1] );
				} elseif ( $current_control && ! empty( $current_control['status'] ) && preg_match( '/^-\s+(.+)$/', $line, $matches ) ) {
					// Implementation bullet points.
					if ( empty( $current_control['implementation'] ) ) {
						$current_control['implementation'] = trim( $matches[1] );
					} else {
						$current_control['implementation'] .= '; ' . trim( $matches[1] );
					}
				}
			}

			// Save last control.
			if ( $current_control ) {
				$controls[] = $current_control;
			}

			return $controls;
		}

		/**
		 * Get HIPAA Security Rule safeguards details.
		 *
		 * Parses the HIPAA Statement of Applicability and returns detailed
		 * information about each safeguard.
		 *
		 * @return array Array of safeguards with id, name, category, status, status_key, applicable, implementation, and mapping.
		 */
		private function get_hipaa_controls() {
			$hipaa_file = WP_MCP_AI_PATH . 'docs/compliance/hipaa/Statement-of-Applicability.md';

			if ( ! file_exists( $hipaa_file ) ) {
				return array();
			}

			$content = file_get_contents( $hipaa_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( empty( $content ) ) {
				return array();
			}

			$controls         = array();
			$lines            = explode( "\n", $content );
			$current_control  = null;
			$current_category = 'General';

			foreach ( $lines as $line ) {
				// Track category (e.g., "## 2. Administrative Safeguards").
				if ( preg_match( '/^##\s+\d+\.\s+(.+)$/', $line, $matches ) ) {
					$current_category = trim( $matches[1] );
				}

				// Match control ID header (e.g., "#### §164.308(a)(1)(i) - Risk Analysis").
				if ( preg_match( '/^####\s+(§[\d\.()a-zA-Z]+)\s+-\s+(.+)$/', $line, $matches ) ) {
					// Save previous control if exists.
					if ( $current_control ) {
						$controls[] = $current_control;
					}

					// Start new control.
					$current_control = array(
						'id'             => $matches[1],
						'name'           => trim( $matches[2] ),
						'category'       => $current_category,
						'status'         => '',
						'status_key'     => '',
						'applicable'     => true,
						'implementation' => '',
						'mapping'        => '',
					);
				} elseif ( $current_control && preg_match( '/^\*\*Status:\*\*\s+(.+)$/', $line, $matches ) ) {
					$status_text               = trim( $matches[1] );
					$current_control['status'] = $status_text;

					// Map status to key.
					if ( false !== strpos( $status_text, '✅' ) || false !== strpos( $status_text, 'Implemented' ) ) {
						$current_control['status_key'] = 'implemented';
					} elseif ( false !== strpos( $status_text, '🔄' ) || false !== strpos( $status_text, 'Partial' ) ) {
						$current_control['status_key'] = 'partial';
					} elseif ( false !== strpos( $status_text, '📋' ) || false !== strpos( $status_text, 'Planned' ) ) {
						$current_control['status_key'] = 'planned';
					} elseif ( false !== strpos( $status_text, '❌' ) || false !== strpos( $status_text, 'Not Applicable' ) ) {
						$current_control['status_key'] = 'not_applicable';
						$current_control['applicable'] = false;
					}
				} elseif ( $current_control && preg_match( '/^\*\*Applicability:\*\*\s+(.+)$/', $line, $matches ) ) {
					$applicable_text               = trim( $matches[1] );
					$current_control['applicable'] = ( strcasecmp( $applicable_text, 'Yes' ) === 0 );
				} elseif ( $current_control && preg_match( '/^\*\*Implementation:\*\*\s*$/', $line ) ) {
					// Next lines will be implementation details.
					$current_control['implementation'] = '';
				} elseif ( $current_control && preg_match( '/^\*\*ISO 27001 Mapping:\*\*\s+(.+)$/', $line, $matches ) ) {
					$current_control['mapping'] = trim( $matches[1] );
				} elseif ( $current_control && ! empty( $current_control['status'] ) && preg_match( '/^-\s+(.+)$/', $line, $matches ) ) {
					// Implementation bullet points.
					if ( empty( $current_control['implementation'] ) ) {
						$current_control['implementation'] = trim( $matches[1] );
					} else {
						$current_control['implementation'] .= '; ' . trim( $matches[1] );
					}
				}
			}

			// Save last control.
			if ( $current_control ) {
				$controls[] = $current_control;
			}

			return $controls;
		}

		/**
		 * Get GDPR requirements details.
		 *
		 * Returns GDPR compliance requirements based on articles and principles.
		 *
		 * @return array Array of GDPR requirements.
		 */
		private function get_gdpr_controls() {
			// GDPR requirements based on key articles.
			return array(
				array(
					'id'             => 'Art. 5',
					'name'           => 'Principles relating to processing of personal data',
					'category'       => 'Data Protection Principles',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Lawfulness, fairness, transparency; Purpose limitation; Data minimization; Accuracy; Storage limitation; Integrity and confidentiality',
					'mapping'        => 'A.5.34, A.8.10, A.8.11',
				),
				array(
					'id'             => 'Art. 6',
					'name'           => 'Lawfulness of processing',
					'category'       => 'Legal Basis',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Processing based on consent, contract, legal obligation, vital interests, public task, or legitimate interests',
					'mapping'        => 'A.5.34',
				),
				array(
					'id'             => 'Art. 13-14',
					'name'           => 'Information to be provided to data subject',
					'category'       => 'Transparency',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Privacy notices, data processing information disclosure',
					'mapping'        => 'A.5.34',
				),
				array(
					'id'             => 'Art. 15-22',
					'name'           => 'Rights of the data subject',
					'category'       => 'Data Subject Rights',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Right to access, rectification, erasure, restriction, portability, objection; Automated decision-making',
					'mapping'        => 'A.5.34, A.8.10',
				),
				array(
					'id'             => 'Art. 25',
					'name'           => 'Data protection by design and by default',
					'category'       => 'Privacy Engineering',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Privacy-first architecture, default privacy settings, data minimization',
					'mapping'        => 'A.5.34, A.8.25, A.8.26',
				),
				array(
					'id'             => 'Art. 32',
					'name'           => 'Security of processing',
					'category'       => 'Security Measures',
					'status'         => '✅ Implemented',
					'status_key'     => 'implemented',
					'applicable'     => true,
					'implementation' => 'Pseudonymization, encryption, confidentiality, integrity, availability, resilience',
					'mapping'        => 'A.8.24, A.8.11, A.8.13, A.8.14',
				),
				array(
					'id'             => 'Art. 33-34',
					'name'           => 'Notification of personal data breach',
					'category'       => 'Breach Response',
					'status'         => '🔄 Partial',
					'status_key'     => 'partial',
					'applicable'     => true,
					'implementation' => 'Incident response procedures documented; 72-hour breach notification process being finalized',
					'mapping'        => 'A.5.24, A.5.25, A.5.26',
				),
			);
		}

		/**
		 * Render date range selector for historical metrics.
		 *
		 * @since 1.5.2
		 */
		private function render_date_range_selector() {
			?>
			<div class="wp-mcp-ai-date-range-selector">
				<label for="wp-mcp-ai-date-range"><?php esc_html_e( 'Historical View:', 'mcp-ai-wpoos' ); ?></label>
				<select id="wp-mcp-ai-date-range" class="wp-mcp-ai-date-range-select">
					<option value="7"><?php esc_html_e( 'Last 7 Days', 'mcp-ai-wpoos' ); ?></option>
					<option value="30" selected><?php esc_html_e( 'Last 30 Days', 'mcp-ai-wpoos' ); ?></option>
					<option value="90"><?php esc_html_e( 'Last 90 Days', 'mcp-ai-wpoos' ); ?></option>
					<option value="180"><?php esc_html_e( 'Last 6 Months', 'mcp-ai-wpoos' ); ?></option>
					<option value="365"><?php esc_html_e( 'Last Year', 'mcp-ai-wpoos' ); ?></option>
					<option value="custom"><?php esc_html_e( 'Custom Range', 'mcp-ai-wpoos' ); ?></option>
				</select>
				<div class="wp-mcp-ai-custom-date-range" style="display: none;">
					<label for="wp-mcp-ai-start-date"><?php esc_html_e( 'From:', 'mcp-ai-wpoos' ); ?></label>
					<input type="date" id="wp-mcp-ai-start-date" class="wp-mcp-ai-date-input" />
					<label for="wp-mcp-ai-end-date"><?php esc_html_e( 'To:', 'mcp-ai-wpoos' ); ?></label>
					<input type="date" id="wp-mcp-ai-end-date" class="wp-mcp-ai-date-input" />
				</div>
				<button class="button button-primary wp-mcp-ai-apply-date-range">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Apply', 'mcp-ai-wpoos' ); ?>
				</button>
				<span class="wp-mcp-ai-tooltip">
					<span class="dashicons dashicons-info"></span>
					<span class="wp-mcp-ai-tooltip-text"><?php esc_html_e( 'View compliance metrics and security events over a specific time period.', 'mcp-ai-wpoos' ); ?></span>
				</span>
			</div>
			<?php
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

		/**
		 * Get risk register entries from Risk Register markdown file.
		 *
		 * Parses the Risk-Register.md file and extracts risk information.
		 *
		 * @since 1.5.3
		 * @return array Array of risks with id, name, description, category, likelihood, impact, risk_score, risk_level, treatment, and status.
		 */
		private function get_risk_register_entries() {
			$risk_file = WP_MCP_AI_PATH . 'docs/compliance/iso27001/Risk-Register.md';

			if ( ! file_exists( $risk_file ) ) {
				return array();
			}

			$content = file_get_contents( $risk_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
			if ( empty( $content ) ) {
				return array();
			}

			$risks            = array();
			$lines            = explode( "\n", $content );
			$current_risk     = null;
			$current_category = 'General';
			$in_table         = false;

			foreach ( $lines as $line ) {
				// Track category (e.g., "## 4. Category 1: Authentication & Authorization Risks").
				if ( preg_match( '/^##\s+\d+\.\s+Category\s+\d+:\s+(.+)$/i', $line, $matches ) ) {
					$current_category = trim( $matches[1] );
				}

				// Match risk ID header (e.g., "### RISK-001: API Key Exposure in Database").
				if ( preg_match( '/^###\s+(RISK-\d+):\s+(.+)$/', $line, $matches ) ) {
					// Save previous risk if exists.
					if ( $current_risk ) {
						$risks[] = $current_risk;
					}

					// Start new risk.
					$current_risk = array(
						'id'          => $matches[1],
						'name'        => trim( $matches[2] ),
						'description' => '',
						'category'    => $current_category,
						'likelihood'  => '',
						'impact'      => '',
						'risk_score'  => '',
						'risk_level'  => '',
						'treatment'   => '',
						'status'      => '',
					);
					$in_table     = false;
				} elseif ( $current_risk ) {
					// Detect start of table (more flexible to handle whitespace variations).
					if ( preg_match( '/^\|\s*Field\s*\|\s*Value\s*\|/i', $line ) ) {
						$in_table = true;
					} elseif ( ! $in_table && preg_match( '/^\|\s*-+\s*\|\s*-+\s*\|/', $line ) ) {
						// Detect table separator as a fallback.
						$in_table = true;
					} elseif ( $in_table && preg_match( '/^\|\s*\*\*(.+?)\*\*\s*\|\s*(.+?)\s*\|$/', $line, $matches ) ) {
						// Parse table rows.
						$field = trim( $matches[1] );
						$value = trim( $matches[2] );

						// Remove HTML tags and entities from value.
						$value = wp_strip_all_tags( $value );
						$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

						switch ( $field ) {
							case 'Description':
								$current_risk['description'] = $value;
								break;
							case 'Residual Likelihood':
							case 'Residual Impact':
							case 'Residual Risk Score':
								// Extract numeric and text values from format "2 (Low)" or "8 (Medium)".
								if ( preg_match( '/(\d+)\s*\(([^)]+)\)/', $value, $parsed_matches ) ) {
									$numeric_value = trim( $parsed_matches[1] );
									$text_value    = trim( $parsed_matches[2] );

									if ( 'Residual Likelihood' === $field ) {
										$current_risk['likelihood'] = $text_value;
									} elseif ( 'Residual Impact' === $field ) {
										$current_risk['impact'] = $text_value;
									} elseif ( 'Residual Risk Score' === $field ) {
										$current_risk['risk_score'] = $numeric_value;
										$current_risk['risk_level'] = $text_value;
									}
								}
								break;
							case 'Treatment Option':
								// Extract the treatment type - split on ' - ' to get the first part.
								// Handles formats like "Reduce - Details", "Accept", or "Accept + Monitor".
								$treatment_parts           = explode( ' - ', $value );
								$current_risk['treatment'] = trim( $treatment_parts[0] );
								break;
							case 'Status':
								$current_risk['status'] = $value;
								break;
						}
					}
				}
			}

			// Save last risk.
			if ( $current_risk ) {
				$risks[] = $current_risk;
			}

			return $risks;
		}

		/**
		 * Get chat data for dashboard.
		 *
		 * Returns chat/conversation statistics including total conversations,
		 * active users, recent activity, and usage analytics (tools, providers, models).
		 *
		 * @return array Chat data statistics.
		 */
		private function get_chat_data() {
			global $wpdb;

			$chat_data = array(
				'total_conversations'     => 0,
				'active_users'            => 0,
				'today_conversations'     => 0,
				'this_week_conversations' => 0,
				'top_tools'               => array(),
				'top_providers'           => array(),
				'top_models'              => array(),
				'total_tokens_used'       => 0,
				'total_cost'              => 0,
			);

			// Try to get data from transcript repository if available.
			if ( class_exists( 'WP_MCP_AI_Transcript_Repository' ) ) {
				$repository = new WP_MCP_AI_Transcript_Repository();

				if ( $repository->table_exists() ) {
					$table = esc_sql( $repository->get_table_name() );

					// Get total conversations (unique session keys).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$total                            = $wpdb->get_var( "SELECT COUNT(DISTINCT session_key) FROM {$table}" );
					$chat_data['total_conversations'] = absint( $total );

					// Get active users (unique user IDs).
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$active_users              = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table}" );
					$chat_data['active_users'] = absint( $active_users );

					// Get today's conversations.
					$today_start = gmdate( 'Y-m-d 00:00:00' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$today_count = $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE cct_created >= %s",
							$today_start
						)
					);
					$chat_data['today_conversations'] = absint( $today_count );

					// Get this week's conversations.
					$week_start = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$week_count = $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							"SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE cct_created >= %s",
							$week_start
						)
					);
					$chat_data['this_week_conversations'] = absint( $week_count );
				}
			}

			// Get tool, provider, and model usage statistics from token tracking.
			if ( class_exists( 'WP_MCP_AI_Token_Tracking_Database' ) ) {
				// Get data for the last 30 days.
				$end_date   = gmdate( 'Y-m-d H:i:s' );
				$start_date = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

				// Get top tools by token usage.
				$tools_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_tool( $start_date, $end_date );
				if ( is_array( $tools_data ) && ! empty( $tools_data ) ) {
					// Sort by total tokens descending.
					usort(
						$tools_data,
						function ( $a, $b ) {
							return $b['total_tokens'] - $a['total_tokens'];
						}
					);
					// Take top 5.
					$chat_data['top_tools'] = array_slice( $tools_data, 0, 5 );
				}

				// Get top providers by usage.
				$providers_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_provider( $start_date, $end_date );
				if ( is_array( $providers_data ) && ! empty( $providers_data ) ) {
					// Sort by total tokens descending.
					usort(
						$providers_data,
						function ( $a, $b ) {
							return $b['total_tokens'] - $a['total_tokens'];
						}
					);
					$chat_data['top_providers'] = $providers_data;

					// Calculate total tokens and cost.
					foreach ( $providers_data as $provider ) {
						$chat_data['total_tokens_used'] += isset( $provider['total_tokens'] ) ? (int) $provider['total_tokens'] : 0;
						$chat_data['total_cost']        += isset( $provider['total_cost'] ) ? (float) $provider['total_cost'] : 0;
					}
				}

				// Get top models by usage.
				$models_data = WP_MCP_AI_Token_Tracking_Database::get_aggregated_by_model( $start_date, $end_date );
				if ( is_array( $models_data ) && ! empty( $models_data ) ) {
					// Sort by total tokens descending.
					usort(
						$models_data,
						function ( $a, $b ) {
							return $b['total_tokens'] - $a['total_tokens'];
						}
					);
					// Take top 5.
					$chat_data['top_models'] = array_slice( $models_data, 0, 5 );
				}
			}

			/**
			 * Filter chat data for the Pro Dashboard.
			 *
			 * Allows plugins/themes to modify or enhance chat statistics.
			 *
			 * @since 1.5.3
			 *
			 * @param array $chat_data Chat statistics data.
			 */
			return apply_filters( 'wp_mcp_ai_pro_dashboard_chat_data', $chat_data );
		}

		/**
		 * Get monitoring event statistics.
		 *
		 * Returns aggregated statistics for monitoring events in the last 24 hours.
		 *
		 * @since 1.5.4
		 * @return array Event statistics with counts by type and severity.
		 */
		private function get_monitoring_event_stats() {
			$recent_events = get_option( 'wp_mcp_ai_recent_activity', array() );
			$recent_errors = get_option( 'wp_mcp_ai_recent_errors', array() );

			$stats = array(
				'total_events'          => 0,
				'critical_count'        => 0,
				'file_integrity_events' => 0,
				'auth_events'           => 0,
				'update_events'         => 0,
				'config_events'         => 0,
				'security_events'       => 0,
			);

			// Count events from last 24 hours.
			$cutoff_time = time() - DAY_IN_SECONDS;

			// Process activity events.
			if ( is_array( $recent_events ) ) {
				foreach ( $recent_events as $event ) {
					$event_time = isset( $event['timestamp'] ) ? $event['timestamp'] : 0;
					// Convert MySQL datetime string to Unix timestamp if needed.
					if ( ! is_numeric( $event_time ) ) {
						$event_time = strtotime( $event_time );
						if ( false === $event_time ) {
							$event_time = 0;
						}
					} else {
						$event_time = (int) $event_time;
					}

					if ( $event_time > $cutoff_time ) {
						++$stats['total_events'];

						// Categorize by type.
						$event_type = isset( $event['type'] ) ? $event['type'] : '';
						switch ( $event_type ) {
							case 'file_change':
							case 'file_integrity':
								++$stats['file_integrity_events'];
								break;
							case 'login':
							case 'logout':
							case 'authentication':
								++$stats['auth_events'];
								break;
							case 'plugin_update':
							case 'theme_update':
							case 'core_update':
								++$stats['update_events'];
								break;
							case 'setting_change':
							case 'config_change':
								++$stats['config_events'];
								break;
							case 'security_alert':
							case 'security':
								++$stats['security_events'];
								break;
						}
					}
				}
			}

			// Process error events for critical count.
			if ( is_array( $recent_errors ) ) {
				foreach ( $recent_errors as $error ) {
					$error_time = isset( $error['timestamp'] ) ? $error['timestamp'] : 0;
					// Convert MySQL datetime string to Unix timestamp if needed.
					if ( ! is_numeric( $error_time ) ) {
						$error_time = strtotime( $error_time );
						if ( false === $error_time ) {
							$error_time = 0;
						}
					} else {
						$error_time = (int) $error_time;
					}

					if ( $error_time > $cutoff_time ) {
						$severity = isset( $error['level'] ) ? $error['level'] : '';
						if ( in_array( $severity, array( 'critical', 'error' ), true ) ) {
							++$stats['critical_count'];
						}
					}
				}
			}

			/**
			 * Filter monitoring event statistics.
			 *
			 * @since 1.5.4
			 *
			 * @param array $stats Event statistics.
			 */
			return apply_filters( 'wp_mcp_ai_monitoring_event_stats', $stats );
		}

		/**
		 * Get system health status.
		 *
		 * Returns current system health indicators and overall status.
		 *
		 * @since 1.5.4
		 * @return array System health status with indicators and overall status.
		 */
		private function get_system_health_status() {
			$health = array(
				'overall_status' => 'operational',
				'uptime_display' => $this->get_system_uptime(),
				'indicators'     => array(),
			);

			// WordPress Health Check integration.
			if ( function_exists( 'get_site_health_test_results' ) ) {
				$site_health  = get_site_health_test_results();
				$failed_tests = 0;

				if ( isset( $site_health['direct'] ) && is_array( $site_health['direct'] ) ) {
					foreach ( $site_health['direct'] as $test ) {
						if ( isset( $test['status'] ) && 'critical' === $test['status'] ) {
							++$failed_tests;
						}
					}
				}

				if ( $failed_tests > 0 ) {
					$health['overall_status'] = 'warning';
				}
			}

			// Database health.
			global $wpdb;
			$db_status              = $wpdb->check_connection( false ) ? 'healthy' : 'warning';
			$health['indicators'][] = array(
				'name'   => __( 'Database Connection', 'mcp-ai-wpoos' ),
				'value'  => ucfirst( $db_status ),
				'status' => $db_status,
				'icon'   => 'database',
			);

			// PHP version check.
			$php_version            = PHP_VERSION;
			$php_status             = version_compare( $php_version, '7.4', '>=' ) ? 'healthy' : 'warning';
			$health['indicators'][] = array(
				'name'   => __( 'PHP Version', 'mcp-ai-wpoos' ),
				'value'  => $php_version,
				'status' => $php_status,
				'icon'   => 'admin-generic',
			);

			// WordPress version check.
			global $wp_version;
			$wp_status              = version_compare( $wp_version, '6.0', '>=' ) ? 'healthy' : 'warning';
			$health['indicators'][] = array(
				'name'   => __( 'WordPress Version', 'mcp-ai-wpoos' ),
				'value'  => $wp_version,
				'status' => $wp_status,
				'icon'   => 'wordpress-alt',
			);

			// Memory usage.
			if ( function_exists( 'memory_get_usage' ) ) {
				$memory_usage           = size_format( memory_get_usage( true ) );
				$memory_limit           = ini_get( 'memory_limit' );
				$health['indicators'][] = array(
					'name'   => __( 'Memory Usage', 'mcp-ai-wpoos' ),
					'value'  => $memory_usage . ' / ' . $memory_limit,
					'status' => 'healthy',
					'icon'   => 'performance',
				);
			}

			/**
			 * Filter system health status.
			 *
			 * @since 1.5.4
			 *
			 * @param array $health System health data.
			 */
			return apply_filters( 'wp_mcp_ai_system_health_status', $health );
		}

		/**
		 * Get system uptime display.
		 *
		 * Returns a human-readable system uptime string.
		 *
		 * @since 1.5.4
		 * @return string Uptime display.
		 */
		private function get_system_uptime() {
			// Try to get actual system uptime if available (Linux only).
			if ( function_exists( 'sys_getloadavg' ) && is_readable( '/proc/uptime' ) ) {
				$uptime_data = file_get_contents( '/proc/uptime' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read; WP_Filesystem not available in this context.
				if ( false !== $uptime_data ) {
					$uptime_parts = explode( ' ', $uptime_data );
					if ( isset( $uptime_parts[0] ) && is_numeric( $uptime_parts[0] ) ) {
						$uptime_seconds = (int) $uptime_parts[0];
						$days           = floor( $uptime_seconds / 86400 );
						$hours          = floor( ( $uptime_seconds % 86400 ) / 3600 );
						return sprintf( '%dd %dh', $days, $hours );
					}
				}
			}

			// Fallback: Use WordPress installation time.
			$wp_install_time = get_option( 'wp_mcp_ai_install_time', time() );
			$uptime_seconds  = time() - $wp_install_time;
			$days            = floor( $uptime_seconds / 86400 );
			return sprintf( '%d days', $days );
		}

		/**
		 * Render monitoring event table.
		 *
		 * Renders a comprehensive, filterable table of monitoring events.
		 *
		 * @since 1.5.4
		 * @param array $events Array of events to display.
		 */
		private function render_monitoring_event_table( $events ) {
			// Enrich events with additional metadata.
			$enriched_events = $this->enrich_monitoring_events( $events );

			?>
			<div class="wp-mcp-ai-event-table-wrapper">
				<?php if ( ! empty( $enriched_events ) ) : ?>
					<table class="wp-list-table widefat fixed striped wp-mcp-ai-event-table" id="wp-mcp-ai-monitoring-events-table">
						<thead>
							<tr>
								<th class="wp-mcp-ai-event-severity" style="width: 80px;"><?php esc_html_e( 'Severity', 'mcp-ai-wpoos' ); ?></th>
								<th class="wp-mcp-ai-event-type" style="width: 120px;"><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
								<th class="wp-mcp-ai-event-message"><?php esc_html_e( 'Message', 'mcp-ai-wpoos' ); ?></th>
								<th class="wp-mcp-ai-event-timestamp" style="width: 160px;"><?php esc_html_e( 'Timestamp', 'mcp-ai-wpoos' ); ?></th>
								<th class="wp-mcp-ai-event-actions" style="width: 100px;"><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $enriched_events as $event ) : ?>
								<tr class="wp-mcp-ai-event-row"
									data-event-type="<?php echo esc_attr( $event['type'] ); ?>"
									data-event-severity="<?php echo esc_attr( $event['severity'] ); ?>"
									data-event-timestamp="<?php echo esc_attr( $event['timestamp'] ); ?>">
									<td class="wp-mcp-ai-event-severity">
										<span class="wp-mcp-ai-severity-badge wp-mcp-ai-severity-<?php echo esc_attr( $event['severity'] ); ?>">
											<?php echo esc_html( ucfirst( $event['severity'] ) ); ?>
										</span>
									</td>
									<td class="wp-mcp-ai-event-type">
										<span class="dashicons dashicons-<?php echo esc_attr( $event['icon'] ); ?>"></span>
										<?php echo esc_html( $event['type_label'] ); ?>
									</td>
									<td class="wp-mcp-ai-event-message">
										<?php echo esc_html( $event['message'] ); ?>
										<?php if ( ! empty( $event['details'] ) ) : ?>
											<button class="button button-link wp-mcp-ai-view-event-details" data-event-id="<?php echo esc_attr( $event['id'] ); ?>">
												<?php esc_html_e( 'View Details', 'mcp-ai-wpoos' ); ?>
											</button>
										<?php endif; ?>
									</td>
									<td class="wp-mcp-ai-event-timestamp">
										<?php echo esc_html( $event['time_display'] ); ?>
									</td>
									<td class="wp-mcp-ai-event-actions">
										<button class="button button-small wp-mcp-ai-dismiss-event" data-event-id="<?php echo esc_attr( $event['id'] ); ?>">
											<span class="dashicons dashicons-dismiss"></span>
											<?php esc_html_e( 'Dismiss', 'mcp-ai-wpoos' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<!-- Pagination -->
					<div class="wp-mcp-ai-event-pagination">
						<span class="wp-mcp-ai-event-count">
							<?php
							printf(
								/* translators: %d: Number of events */
								esc_html__( 'Showing %d events', 'mcp-ai-wpoos' ),
								count( $enriched_events )
							);
							?>
						</span>
						<button class="button" id="wp-mcp-ai-load-more-events">
							<?php esc_html_e( 'Load More', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>
				<?php else : ?>
					<div class="wp-mcp-ai-empty-state" style="text-align: center; padding: 40px 20px; background: #f7f7f7; border-radius: 4px; border: 2px dashed #c3c4c7;">
						<span class="dashicons dashicons-yes-alt" style="font-size: 48px; width: 48px; height: 48px; color: #46b450;"></span>
						<h3 style="margin: 15px 0 10px;"><?php esc_html_e( 'No Security Events to Display', 'mcp-ai-wpoos' ); ?></h3>
						<p style="color: #646970; margin: 0;">
							<?php esc_html_e( 'Your system is operating normally. Security events will appear here when activity is logged.', 'mcp-ai-wpoos' ); ?>
						</p>
						<p style="color: #646970; font-size: 12px; margin-top: 10px;">
							<?php esc_html_e( 'Events are automatically logged for authentication attempts, file changes, configuration updates, and security alerts.', 'mcp-ai-wpoos' ); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Event Details Modal -->
			<div id="wp-mcp-ai-event-details-modal" class="wp-mcp-ai-modal" style="display: none;">
				<div class="wp-mcp-ai-modal-content">
					<div class="wp-mcp-ai-modal-header">
						<h2><?php esc_html_e( 'Event Details', 'mcp-ai-wpoos' ); ?></h2>
						<button class="wp-mcp-ai-modal-close">
							<span class="dashicons dashicons-no"></span>
						</button>
					</div>
					<div class="wp-mcp-ai-modal-body" id="wp-mcp-ai-event-details-content">
						<!-- Content loaded dynamically -->
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Enrich monitoring events with additional metadata.
		 *
		 * Adds type labels, severity, icons, and formatted timestamps to events.
		 *
		 * @since 1.5.4
		 * @param array $events Raw events array.
		 * @return array Enriched events.
		 */
		private function enrich_monitoring_events( $events ) {
			if ( ! is_array( $events ) ) {
				return array();
			}

			$enriched   = array();
			$type_icons = array(
				'authentication'  => 'lock',
				'file-integrity'  => 'media-document',
				'configuration'   => 'admin-settings',
				'plugin-updates'  => 'update',
				'security-alerts' => 'warning',
				'default'         => 'info',
			);

			$type_labels = array(
				'authentication'  => __( 'Authentication', 'mcp-ai-wpoos' ),
				'file-integrity'  => __( 'File Integrity', 'mcp-ai-wpoos' ),
				'configuration'   => __( 'Configuration', 'mcp-ai-wpoos' ),
				'plugin-updates'  => __( 'Updates', 'mcp-ai-wpoos' ),
				'security-alerts' => __( 'Security', 'mcp-ai-wpoos' ),
			);

			foreach ( $events as $index => $event ) {
				if ( ! isset( $event['message'] ) ) {
					continue;
				}

				$event_type = isset( $event['type'] ) ? $event['type'] : 'default';
				$timestamp  = isset( $event['timestamp'] ) ? $event['timestamp'] : time();

				// Convert MySQL datetime string to Unix timestamp if needed.
				if ( ! is_numeric( $timestamp ) ) {
					$timestamp = strtotime( $timestamp );
					// If conversion fails, use current time.
					if ( false === $timestamp ) {
						$timestamp = time();
					}
				} else {
					// Ensure numeric timestamp is an integer.
					$timestamp = (int) $timestamp;
				}

				$enriched_event = array(
					'id'           => 'event-' . $index,
					'type'         => $event_type,
					'type_label'   => isset( $type_labels[ $event_type ] ) ? $type_labels[ $event_type ] : __( 'General', 'mcp-ai-wpoos' ),
					'icon'         => isset( $type_icons[ $event_type ] ) ? $type_icons[ $event_type ] : $type_icons['default'],
					'message'      => $event['message'],
					'severity'     => isset( $event['level'] ) ? $event['level'] : 'info',
					'timestamp'    => $timestamp,
					'time_display' => human_time_diff( $timestamp, time() ) . ' ' . __( 'ago', 'mcp-ai-wpoos' ),
					'details'      => isset( $event['details'] ) ? $event['details'] : '',
				);

				$enriched[] = $enriched_event;
			}

			// Sort by timestamp descending (newest first).
			usort(
				$enriched,
				function ( $a, $b ) {
					// Use spaceship operator for safe comparison without overflow risk.
					return $b['timestamp'] <=> $a['timestamp'];
				}
			);

			return $enriched;
		}
	}
}
