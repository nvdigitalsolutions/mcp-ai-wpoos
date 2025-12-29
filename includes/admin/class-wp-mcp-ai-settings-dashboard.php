<?php
/**
 * Settings Dashboard Controller for NV oOS
 *
 * Manages the modern tabbed settings interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Dashboard' ) ) {
	/**
	 * Main controller for the settings dashboard.
	 */
	class WP_MCP_AI_Settings_Dashboard {
		const PAGE_SLUG = 'wp-mcp-ai-dashboard';

		/**
		 * AJAX handlers component.
		 *
		 * @var WP_MCP_AI_Admin_AJAX_Handlers
		 */
		private $ajax_handlers;

		/**
		 * Constructor.
		 *
		 * @param WP_MCP_AI_Admin_AJAX_Handlers|null $ajax_handlers Optional. AJAX handlers instance for dependency injection.
		 */
		public function __construct( $ajax_handlers = null ) {
			// Initialize AJAX handlers using dependency injection or create instance.
			$this->ajax_handlers = $ajax_handlers ?? wp_mcp_ai_container()->get( 'admin.ajax_handlers' );

			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_post_wp_mcp_ai_save_settings', array( $this, 'handle_save_settings' ) );

			// Hide WordPress admin footer on the settings dashboard to prevent overlay issues.
			add_filter( 'admin_footer_text', array( $this, 'hide_admin_footer_text' ) );
			add_filter( 'update_footer', array( $this, 'hide_update_footer_text' ), 999 );

			// Register AJAX handlers.
			add_action( 'wp_ajax_wp_mcp_ai_test_ollama_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_ollama_models', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_lm_studio_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_lm_studio_models', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_fetch_cloudways_data', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_cloudflare_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_brave_search_connection', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reset_user_token_usage', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reset_all_token_usage', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_tool_limits', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_tool_settings', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_orchestration_preset', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_export_token_usage_csv', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_bulk_assign_tier', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_all_recommendations', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_apply_preset', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_usage_trend', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_tier_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_tool_breakdown', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_provider_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_model_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_update_chart_period', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_refresh_chart', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_toggle_tool', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reseed_professions', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_reseed_teams', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_migrate_gemini_costs', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_regenerate_playbook', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_sync_all_playbooks', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_delete_old_playbooks', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
			add_action( 'wp_ajax_wp_mcp_ai_save_model_config', array( $this->ajax_handlers, 'handle_save_model_config' ) );
		}

		/**
		 * Register the settings page in WordPress admin menu.
		 */
		public function register_menu() {
			add_menu_page(
				__( 'NV oOS Settings', 'wp-mcp-ai' ),
				__( 'NV oOS', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_dashboard' ),
				'dashicons-format-chat',
				30
			);

			// Remove the auto-generated submenu item (has same title as top-level menu).
			remove_submenu_page( self::PAGE_SLUG, self::PAGE_SLUG );

			// Add submenu item for General Settings with proper label.
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'General Settings', 'wp-mcp-ai' ),
				__( 'General Settings', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_dashboard' )
			);
		}

		/**
		 * Register settings with WordPress.
		 */
		public function register_settings() {
			register_setting(
				'wp_mcp_ai_settings_group',
				WP_MCP_AI_Admin_Settings::OPTION_NAME,
				array(
					'type'              => 'array',
					'sanitize_callback' => null, // We handle sanitization in handle_save_settings().
				)
			);
		}

		/**
		 * Sanitize settings before saving.
		 *
		 * @param array  $input Raw settings input.
		 * @param string $active_tab Optional. The active tab to process. If not provided, processes all tabs.
		 * @return array Sanitized settings.
		 */
		public function sanitize_settings( $input, $active_tab = '' ) {
			$sanitized = array();

			// Get sections to process - either from a specific tab or all sections.
			if ( ! empty( $active_tab ) ) {
				$sections = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );
			} else {
				$sections = WP_MCP_AI_Settings_Registry::get_sections();
			}

			foreach ( $sections as $section ) {
				$section_input = $section->sanitize( $input );
				$validated     = $section->validate( $section_input );

				if ( is_wp_error( $validated ) ) {
					add_settings_error(
						'wp_mcp_ai_settings',
						$section->get_id(),
						$validated->get_error_message(),
						'error'
					);
					continue;
				}

				$sanitized = array_merge( $sanitized, $section_input );
			}

			// Clear settings cache.
			WP_MCP_AI_Admin_Settings::reset_settings_cache();

			return $sanitized;
		}

		/**
		 * Handle settings save via admin_post.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_settings' );

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array passed to sanitize_settings() method below.
			$posted_settings = isset( $_POST['wp_mcp_ai_settings'] ) ? wp_unslash( $_POST['wp_mcp_ai_settings'] ) : array();
			$active_tab      = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';
			$active_view     = isset( $_POST['view'] ) ? sanitize_key( $_POST['view'] ) : '';

			// Find subtab from section-specific subtab fields (subtab_sectionid format).
			// Multiple sections on same tab may have subtabs, so we check all subtab_* fields.
			$active_subtab = '';
			foreach ( $_POST as $key => $value ) {
				if ( strpos( $key, 'subtab_' ) === 0 && ! empty( $value ) ) {
					$active_subtab = sanitize_key( $value );
					break; // Use the first subtab found.
				}
			}

			// Fallback to legacy 'subtab' field for backward compatibility.
			if ( empty( $active_subtab ) && isset( $_POST['subtab'] ) ) {
				$active_subtab = sanitize_key( $_POST['subtab'] );
			}

			// Check if logging is enabled for diagnostic purposes.
			$existing_for_logging = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging       = ! empty( $existing_for_logging['enable_logging'] ) || ! empty( $existing_for_logging['enable_extended_logging'] );

			// Log save attempt for debugging (only if logging enabled).
			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] Save attempt - Tab: %s, Posted fields: %d, Posted keys: %s',
						$active_tab,
						count( $posted_settings ),
						implode( ', ', array_keys( $posted_settings ) )
					)
				);
			}

			// Only sanitize settings from the active tab to avoid clearing checkboxes from other tabs.
			$sanitized_new = $this->sanitize_settings( $posted_settings, $active_tab );

			// Log sanitization results.
			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] After sanitization - Sanitized fields: %d, Sanitized keys: %s',
						count( $sanitized_new ),
						implode( ', ', array_keys( $sanitized_new ) )
					)
				);
			}

			// Merge with existing settings to avoid wiping unrelated fields.
			// This is critical for display-only sections (like Overview) that have no editable fields,
			// and for preserving settings from other tabs.
			$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$merged_settings   = array_merge( $existing_settings, $sanitized_new );

			// Save to database and log result.
			$update_result = update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged_settings );

			if ( $enable_logging ) {
				error_log(
					sprintf(
						'[NV oOS Settings] Database update - Result: %s, Existing fields: %d, Merged fields: %d',
						$update_result ? 'SUCCESS' : 'UNCHANGED',
						count( $existing_settings ),
						count( $merged_settings )
					)
				);
			}

			// Clear caches when settings are updated.
			if ( 'orchestration' === $active_tab ) {
				// Clear orchestration-related caches using Cache Helper.
				WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();

				if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
					WP_MCP_AI_Orchestration_Health_Service::clear_health_cache();
				}
			}

			// Redirect back to the same tab that was being edited.
			$redirect_args = array(
				'page'    => self::PAGE_SLUG,
				'updated' => 'true',
			);

			if ( ! empty( $active_tab ) ) {
				$redirect_args['tab'] = $active_tab;
			}

			// Preserve subtab parameter for sections with sub-navigation (e.g., Authentication).
			if ( ! empty( $active_subtab ) ) {
				$redirect_args['subtab'] = $active_subtab;
			}

			// Preserve view parameter for sections with view-based navigation (e.g., Orchestration, Token Manager).
			if ( ! empty( $active_view ) ) {
				$redirect_args['view'] = $active_view;
			}

			wp_safe_redirect(
				add_query_arg(
					$redirect_args,
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Get the appropriate asset file (minified or unminified).
		 *
		 * Uses minified version in production, unminified when SCRIPT_DEBUG is enabled.
		 * Falls back to unminified if minified doesn't exist.
		 *
		 * @param string $file_path Relative path to the asset file (e.g., 'assets/js/settings-dashboard.js').
		 * @return array Array with 'url', 'path', and 'version' keys.
		 */
		private function get_asset_file( $file_path ) {
			$use_minified = ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
			$extension    = pathinfo( $file_path, PATHINFO_EXTENSION );
			$base_path    = preg_replace( '/\.' . $extension . '$/', '', $file_path );

			// Try minified version first if not in debug mode.
			if ( $use_minified ) {
				$minified_path = $base_path . '.min.' . $extension;
				$full_path     = WP_MCP_AI_PATH . $minified_path;

				if ( file_exists( $full_path ) ) {
					return array(
						'url'     => WP_MCP_AI_URL . $minified_path,
						'path'    => $full_path,
						'version' => filemtime( $full_path ),
					);
				}
			}

			// Fall back to unminified version.
			$full_path = WP_MCP_AI_PATH . $file_path;
			return array(
				'url'     => WP_MCP_AI_URL . $file_path,
				'path'    => $full_path,
				'version' => file_exists( $full_path ) ? filemtime( $full_path ) : WP_MCP_AI_VERSION,
			);
		}

		/**
		 * Enqueue CSS and JavaScript assets for the dashboard.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			// Get asset files (automatically uses minified in production, unminified in debug mode).
			$dashboard_css = $this->get_asset_file( 'assets/css/settings-dashboard.css' );
			$ajax_error_js = $this->get_asset_file( 'assets/js/ajax-error-service.js' );
			$dashboard_js  = $this->get_asset_file( 'assets/js/settings-dashboard.js' );

			// Enqueue dashboard styles with file modification time for cache busting.
			wp_enqueue_style(
				'wp-mcp-ai-dashboard',
				$dashboard_css['url'],
				array(),
				$dashboard_css['version']
			);

			// Enqueue tools manager styles if on tools tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) {
				$tools_css = $this->get_asset_file( 'assets/css/tools-manager.css' );
				wp_enqueue_style(
					'wp-mcp-ai-tools-manager',
					$tools_css['url'],
					array( 'wp-mcp-ai-dashboard' ),
					$tools_css['version']
				);
			}

			// Enqueue AJAX error service (must be loaded before other scripts) with filemtime for cache busting.
			wp_enqueue_script(
				'wp-mcp-ai-ajax-error-service',
				$ajax_error_js['url'],
				array( 'jquery' ),
				$ajax_error_js['version'],
				true
			);

			// Enqueue dashboard scripts with jQuery UI Sortable dependency and filemtime for cache busting.
			wp_enqueue_script(
				'wp-mcp-ai-dashboard',
				$dashboard_js['url'],
				array( 'jquery', 'jquery-ui-sortable', 'wp-mcp-ai-ajax-error-service' ),
				$dashboard_js['version'],
				true
			);

			// Enqueue tools manager scripts if on tools tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ) {
				$tools_js = $this->get_asset_file( 'assets/js/tools-manager.js' );
				wp_enqueue_script(
					'wp-mcp-ai-tools-manager',
					$tools_js['url'],
					array( 'jquery', 'wp-mcp-ai-ajax-error-service' ),
					$tools_js['version'],
					true
				);
			}

			// Enqueue tool orchestration scripts if on orchestration tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'orchestration' === $_GET['tab'] ) {
				$orchestration_js = $this->get_asset_file( 'assets/js/admin-tool-orchestration.js' );
				wp_enqueue_script(
					'wp-mcp-ai-tool-orchestration',
					$orchestration_js['url'],
					array( 'jquery', 'wp-mcp-ai-ajax-error-service', 'wp-i18n' ),
					$orchestration_js['version'],
					true
				);
				wp_set_script_translations( 'wp-mcp-ai-tool-orchestration', 'wp-mcp-ai' );
			}

			// Enqueue performance admin scripts if on advanced tab with performance_monitoring subtab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
			if ( isset( $_GET['tab'] ) && 'advanced' === $_GET['tab'] && isset( $_GET['subtab'] ) && 'performance_monitoring' === $_GET['subtab'] ) {
				$performance_js = $this->get_asset_file( 'assets/js/performance-admin.js' );
				wp_enqueue_script(
					'wp-mcp-ai-performance-admin',
					$performance_js['url'],
					array( 'jquery' ),
					$performance_js['version'],
					true
				);

				wp_localize_script(
					'wp-mcp-ai-performance-admin',
					'wpMcpAiPerformance',
					array(
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'nonce'       => wp_create_nonce( 'wp_mcp_ai_performance' ),
						'runningText' => __( 'Running...', 'wp-mcp-ai' ),
					)
				);
			}

			// Localize script with settings.
			wp_localize_script(
				'wp-mcp-ai-dashboard',
				'wpMcpAiDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
				)
			);

			// Add admin nonce for tools manager and connection tests.
			// Using 'wp-mcp-ai-settings' nonce to match AJAX handler expectations.
			wp_localize_script(
				'wp-mcp-ai-dashboard',
				'wpMcpAiAdmin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp-mcp-ai-settings' ),
					'i18n'    => array(
						'enabled'  => __( 'Enabled', 'wp-mcp-ai' ),
						'disabled' => __( 'Disabled', 'wp-mcp-ai' ),
					),
				)
			);
		}

		/**
		 * Get the currently active tab.
		 *
		 * @return string Active tab ID.
		 */
		private function get_active_tab() {
			$tabs       = WP_MCP_AI_Settings_Registry::get_tabs();
			$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

			if ( ! isset( $tabs[ $active_tab ] ) ) {
				$active_tab = 'general';
			}

			return $active_tab;
		}

		/**
		 * Render the settings dashboard.
		 */
		public function render_dashboard() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			$active_tab = $this->get_active_tab();
			$tabs       = WP_MCP_AI_Settings_Registry::get_tabs();
			$sections   = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );

			?>
			<div class="wrap wp-mcp-ai-settings-dashboard">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

				<?php settings_errors( 'wp_mcp_ai_settings' ); ?>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Settings tabs', 'wp-mcp-ai' ); ?>">
					<?php foreach ( $tabs as $tab_id => $tab ) : ?>
						<?php
						$tab_url = add_query_arg(
							array(
								'page' => self::PAGE_SLUG,
								'tab'  => $tab_id,
							),
							admin_url( 'admin.php' )
						);
						$active  = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
						?>
						<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
							<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
							<?php echo esc_html( $tab['title'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<form id="wp-mcp-ai-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
					<input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

					<div class="tab-content">
						<?php if ( empty( $sections ) ) : ?>
							<div class="notice notice-info">
								<p><?php esc_html_e( 'No settings available for this tab.', 'wp-mcp-ai' ); ?></p>
							</div>
						<?php else : ?>
							<?php foreach ( $sections as $section ) : ?>
								<?php $section->render_wrapper(); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $sections ) ) : ?>
						<?php submit_button(); ?>
					<?php endif; ?>
				</form>
			</div>
			<?php
		}

		/**
		 * Hide the WordPress admin footer text on the NV oOS settings dashboard.
		 *
		 * @param string $footer_text The default footer text.
		 * @return string Empty string on settings dashboard, original text elsewhere.
		 */
		public function hide_admin_footer_text( $footer_text ) {
			if ( ! $this->is_settings_dashboard() ) {
				return $footer_text;
			}

			return '';
		}

		/**
		 * Hide the WordPress version footer text on the NV oOS settings dashboard.
		 *
		 * @param string $footer_text The default version footer text.
		 * @return string Empty string on settings dashboard, original text elsewhere.
		 */
		public function hide_update_footer_text( $footer_text ) {
			if ( ! $this->is_settings_dashboard() ) {
				return $footer_text;
			}

			return '';
		}

		/**
		 * Check if the current admin page is the NV oOS settings dashboard.
		 *
		 * @return bool True if on settings dashboard, false otherwise.
		 */
		protected function is_settings_dashboard() {
			if ( ! function_exists( 'get_current_screen' ) ) {
				return false;
			}

			$screen = get_current_screen();
			if ( ! $screen ) {
				return false;
			}

			// Check if we're on the settings dashboard.
			// The screen ID format is 'toplevel_page_{page_slug}'.
			return 'toplevel_page_' . self::PAGE_SLUG === $screen->id;
		}
	}
}
