<?php
/**
 * Pro Schedule Manager Admin Page
 *
 * Registers a standalone admin page for the Pro Schedule Manager under the
 * NV oOS Pro Dashboard menu, mirroring the pattern used by the Pro Workflow
 * Builder page.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager_Page' ) ) {
	/**
	 * Standalone admin page for the Pro Schedule Manager.
	 *
	 * Registers the page under the NV oOS Pro Dashboard submenu and delegates
	 * rendering to the existing WP_MCP_AI_Section_Schedule_Manager section
	 * class so that all AJAX handlers and HTML remain in a single place.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Schedule_Manager_Page {

		/**
		 * Admin page slug.
		 */
		const PAGE_SLUG = 'nvoos-pro-schedule-manager';

		/**
		 * Actual WordPress hook name returned by add_submenu_page().
		 *
		 * Stored during register_page() so enqueue_assets() can compare against
		 * the real hook (which uses sanitize_title(menu_title) as prefix, not the
		 * raw parent slug).
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Priority 26: parent nvoos-pro-dashboard menu registers at priority 25.
			add_action( 'admin_menu', array( $this, 'register_page' ), 26 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the admin submenu page.
		 *
		 * @return void
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'nvoos-pro-dashboard',
				__( 'Pro Schedule Manager', 'mcp-ai-wpoos-pro' ),
				__( 'Schedule Manager', 'mcp-ai-wpoos-pro' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the standalone Schedule Manager page.
		 *
		 * Ensures CSS/JS load even when the section instance was not created
		 * early enough to register its own admin_enqueue_scripts hook (e.g. in the
		 * base + pro separate-plugin scenario where the Pro addon loads after the
		 * base plugin's settings-dashboard-init.php has already run).
		 *
		 * @param string $hook Current admin page hook.
		 * @return void
		 */
		public function enqueue_assets( $hook ) {
			// Use the actual page hook stored when add_submenu_page() was called.
			$is_page = ! empty( $this->page_hook ) && $hook === $this->page_hook;

			// Fallback: check $_GET['page'] for additional safety (covers edge
			// cases where the hook suffix may differ from the computed value).
			if ( ! $is_page ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking page slug for script enqueue only.
				$is_page = isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_text_field( wp_unslash( $_GET['page'] ) );
			}

			if ( ! $is_page ) {
				return;
			}

			// Resolve the section via the DI container and delegate asset enqueuing.
			// By the time admin_enqueue_scripts fires the Pro class file has been
			// loaded, so the container factory will return a real instance.
			if ( function_exists( 'wp_mcp_ai_container' ) ) {
				$section = wp_mcp_ai_container()->get( 'section.schedule_manager' );
				if ( $section instanceof WP_MCP_AI_Section_Schedule_Manager ) {
					$section->enqueue_assets( $hook );
				}
			}
		}

		/**
		 * Render the page.
		 *
		 * Delegates to the section class so that all markup and PHP logic live
		 * in a single place.
		 *
		 * @return void
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos-pro' ) );
			}
			?>
			<div class="wrap">
				<h1>
					<span class="dashicons dashicons-clock" style="font-size:28px;width:28px;height:28px;vertical-align:middle;margin-right:8px;color:#2271b1;"></span>
					<?php esc_html_e( 'Pro Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
					<span style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:4px 12px;border-radius:12px;font-size:11px;font-weight:600;margin-left:10px;text-transform:uppercase;letter-spacing:.5px;">PRO</span>
				</h1>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=research-pro-schedule' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Research & Add Schedule', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-pro-schedule-toolkit-settings' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Schedule Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>

				<?php
				// Use the container-managed section instance so that we share the
				// same object (and its registered AJAX handlers).
				if ( function_exists( 'wp_mcp_ai_container' ) ) {
					$section = wp_mcp_ai_container()->get( 'section.schedule_manager' );
					if ( $section instanceof WP_MCP_AI_Settings_Section ) {
						$section->render();
					}
				}
				?>
			</div>
			<?php
		}
	}
}

// Instantiate the page — mirrors the workflow-builder pattern.
if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	new WP_MCP_AI_Pro_Schedule_Manager_Page();
}
