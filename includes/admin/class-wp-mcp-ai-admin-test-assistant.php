<?php
/**
 * Test Assistant Admin Page
 *
 * Provides an interface for administrators to test AI assistants directly from the WordPress admin.
 * Refactored to use base class for better SoC and code reuse.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-page-base.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Assistant' ) ) {
	/**
	 * Test Assistant admin page handler.
	 * Extends base class for shared functionality.
	 */
	class WP_MCP_AI_Admin_Test_Assistant extends WP_MCP_AI_Admin_Test_Page_Base {

		/**
		 * Get the post type for this test page.
		 *
		 * @return string
		 */
		protected function get_post_type() {
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				return 'mcp_ai_assistant';
			}
			return WP_MCP_AI_Assistant_CPT::POST_TYPE;
		}

		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		protected function get_page_slug() {
			return 'wp-mcp-ai-test-assistant';
		}

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		protected function get_page_title() {
			return __( 'Test Assistant', 'wp-mcp-ai' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		protected function get_menu_title() {
			return __( 'Test Assistant', 'wp-mcp-ai' );
		}

		/**
		 * Enqueue page-specific assets.
		 */
		protected function enqueue_page_assets() {
			$test_script_relative = 'assets/js/admin-test-assistant.js';
			$test_style_relative  = 'assets/css/admin-test-assistant.css';

			wp_enqueue_style(
				'wp-mcp-ai-admin-test-assistant',
				WP_MCP_AI_URL . $test_style_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_style_relative )
			);

			wp_enqueue_script(
				'wp-mcp-ai-admin-test-assistant',
				WP_MCP_AI_URL . $test_script_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_script_relative ),
				true
			);
		}

		/**
		 * Render the test assistant page.
		 */
		public function render_page() {
			$this->check_permission();

			// Safety check: Ensure the Assistant CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				?>
				<div class="wrap">
					<h1><?php echo esc_html__( 'Test AI Assistants', 'wp-mcp-ai' ); ?></h1>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'The Assistant CPT class is not loaded. Please contact support.', 'wp-mcp-ai' ); ?></p>
					</div>
				</div>
				<?php
				return;
			}

			$post_type = $this->get_post_type();

			// Get all published assistants.
			$assistants = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Test AI Assistants', 'wp-mcp-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Test your AI assistants directly from the admin dashboard. Click "Test" next to any assistant to open a chat interface and validate its behavior.', 'wp-mcp-ai' ); ?></p>

				<?php if ( empty( $assistants ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new assistant */
								esc_html__( 'No assistants found. %s to get started.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first assistant', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Assistant Name', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Provider', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Model', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Professionals', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Tools', 'wp-mcp-ai' ); ?></th>
								<th scope="col" class="column-actions"><?php echo esc_html__( 'Actions', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $assistants as $assistant ) : ?>
								<?php
								// Safety check: Ensure method exists before calling.
								if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
									$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant->ID );
								} else {
									$config = array();
								}

								$provider       = ! empty( $config['provider'] ) ? $config['provider'] : __( 'Default', 'wp-mcp-ai' );
								$model          = ! empty( $config['model'] ) ? $config['model'] : __( 'Default', 'wp-mcp-ai' );
								$tool_count     = isset( $config['tools'] ) && is_array( $config['tools'] ) ? count( $config['tools'] ) : 0;
								$edit_url       = get_edit_post_link( $assistant->ID );
								$tool_shortcuts = $this->get_assistant_tool_shortcuts( $assistant->ID );
								$professionals  = $this->get_assistant_professionals( $assistant->ID );
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $assistant->post_title ); ?></strong>
										<div class="row-actions">
											<span class="edit">
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html__( 'Edit', 'wp-mcp-ai' ); ?>
												</a>
											</span>
										</div>
									</td>
									<td><?php echo esc_html( ucfirst( $provider ) ); ?></td>
									<td><code><?php echo esc_html( $model ); ?></code></td>
									<td>
										<?php
										if ( empty( $professionals ) ) {
											echo '<em>' . esc_html__( 'None', 'wp-mcp-ai' ) . '</em>';
										} else {
											echo esc_html( implode( ', ', $professionals ) );
										}
										?>
									</td>
									<td>
										<?php
										/* translators: %d: number of tools enabled for the assistant */
										echo esc_html( sprintf( _n( '%d tool', '%d tools', $tool_count, 'wp-mcp-ai' ), $tool_count ) );
										?>
									</td>
									<td>
										<button 
											type="button" 
											class="button button-primary wp-mcp-ai-test-assistant-btn"
											data-assistant-id="<?php echo esc_attr( $assistant->ID ); ?>"
											data-assistant-title="<?php echo esc_attr( $assistant->post_title ); ?>"
											data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $tool_shortcuts ) ); ?>"
										>
											<?php echo esc_html__( 'Test', 'wp-mcp-ai' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<!-- Modal container for chat interface -->
				<div id="wp-mcp-ai-test-modal" class="wp-mcp-ai-test-modal" style="display: none;">
					<div class="wp-mcp-ai-test-modal__backdrop"></div>
					<div class="wp-mcp-ai-test-modal__panel">
						<div class="wp-mcp-ai-test-modal__header">
							<h2 id="wp-mcp-ai-test-modal__title"><?php echo esc_html__( 'Test Assistant', 'wp-mcp-ai' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'wp-mcp-ai' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<!-- Chat interface will be initialized here -->
							<div id="wp-mcp-ai-test-chat-container"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get assistant tool shortcuts.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array Array of tool shortcuts.
		 */
		private function get_assistant_tool_shortcuts( $assistant_id ) {
			$assistant_id = absint( $assistant_id );

			if ( ! $assistant_id ) {
				return array();
			}

			// Safety check: Ensure class exists.
			if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				return array();
			}

			// Use the shortcode class method if it exists.
			if ( method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
				return WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );
			}

			return array();
		}

		/**
		 * Get professionals associated with an assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array Array of profession names.
		 */
		private function get_assistant_professionals( $assistant_id ) {
			$assistant_id = absint( $assistant_id );

			if ( ! $assistant_id ) {
				return array();
			}

			// Safety check: Ensure class exists.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				return array();
			}

			// Get primary roles (profession post IDs).
			$primary_roles = get_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_PRIMARY_ROLES, true );

			if ( ! is_array( $primary_roles ) || empty( $primary_roles ) ) {
				return array();
			}

			$profession_names = array();

			foreach ( $primary_roles as $profession_id ) {
				$profession_id = absint( $profession_id );

				if ( ! $profession_id ) {
					continue;
				}

				// Get the profession post.
				$profession = get_post( $profession_id );

				// Skip if post doesn't exist or isn't a profession.
				if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type ) {
					continue;
				}

				$profession_names[] = $profession->post_title;
			}

			return $profession_names;
		}
	}
}
