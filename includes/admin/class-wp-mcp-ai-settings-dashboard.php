<?php
/**
 * Settings Dashboard Controller for WP oOS
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
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'admin_post_wp_mcp_ai_save_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Register the settings page in WordPress admin menu.
		 */
		public function register_menu() {
			add_menu_page(
				__( 'WP oOS Settings', 'wp-mcp-ai' ),
				__( 'WP oOS', 'wp-mcp-ai' ),
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
					'sanitize_callback' => array( $this, 'sanitize_settings' ),
				)
			);
		}

		/**
		 * Sanitize settings before saving.
		 *
		 * @param array $input Raw settings input.
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

			$posted_settings = isset( $_POST['wp_mcp_ai_settings'] ) ? $_POST['wp_mcp_ai_settings'] : array();
			$active_tab      = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';
			
			// Only sanitize settings from the active tab to avoid clearing checkboxes from other tabs.
			$sanitized_new = $this->sanitize_settings( $posted_settings, $active_tab );

			// Merge with existing settings to avoid wiping unrelated fields.
			// This is critical for display-only sections (like Overview) that have no editable fields,
			// and for preserving settings from other tabs.
			$existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$merged_settings   = array_merge( $existing_settings, $sanitized_new );

			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged_settings );

			// Redirect back to the same tab that was being edited.
			$redirect_args = array(
				'page'    => self::PAGE_SLUG,
				'updated' => 'true',
			);
			
			if ( ! empty( $active_tab ) ) {
				$redirect_args['tab'] = $active_tab;
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
		 * Enqueue CSS and JavaScript assets for the dashboard.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
				return;
			}

			$plugin_url = plugin_dir_url( dirname( dirname( __FILE__ ) ) );

			// Enqueue dashboard styles.
			wp_enqueue_style(
				'wp-mcp-ai-dashboard',
				$plugin_url . 'assets/css/settings-dashboard.css',
				array(),
				WP_MCP_AI_VERSION
			);

			// Enqueue dashboard scripts with jQuery UI Sortable dependency.
			wp_enqueue_script(
				'wp-mcp-ai-dashboard',
				$plugin_url . 'assets/js/settings-dashboard.js',
				array( 'jquery', 'jquery-ui-sortable' ),
				WP_MCP_AI_VERSION,
				true
			);

			// Localize script with settings.
			wp_localize_script(
				'wp-mcp-ai-dashboard',
				'wpMcpAiDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_dashboard' ),
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

				<?php if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) : ?>
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

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
	}
}
