<?php
/**
 * Test Profession Admin Page
 *
 * Provides an interface for administrators to test AI professions directly from the WordPress admin.
 * Allows testing a profession by creating a temporary assistant and chatting with it.
 * Uses base class for better SoC and code reuse.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-page-base.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Profession' ) ) {
	/**
	 * Test Profession admin page handler.
	 * Extends base class for shared functionality.
	 */
	class WP_MCP_AI_Admin_Test_Profession extends WP_MCP_AI_Admin_Test_Page_Base {

		/**
		 * Get the post type for this test page.
		 *
		 * @return string
		 */
		protected function get_post_type() {
			if ( ! defined( 'WP_MCP_AI_Profession_CPT::POST_TYPE' ) ) {
				return 'mcp_ai_profession';
			}
			return WP_MCP_AI_Profession_CPT::POST_TYPE;
		}

		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		protected function get_page_slug() {
			return 'wp-mcp-ai-test-profession';
		}

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		protected function get_page_title() {
			return __( 'Test Profession', 'wp-mcp-ai' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		protected function get_menu_title() {
			return __( 'Test Profession', 'wp-mcp-ai' );
		}

		/**
		 * Customize chat strings for profession testing.
		 *
		 * @return array
		 */
		protected function get_chat_strings() {
			$strings = parent::get_chat_strings();

			// Customize specific strings for profession context.
			$strings['missingAssistant']        = __( 'Profession configuration was not found.', 'wp-mcp-ai' );
			$strings['notAuthorized']           = __( 'You do not have permission to test this profession.', 'wp-mcp-ai' );
			$strings['roleLabels']['assistant'] = __( 'Professional', 'wp-mcp-ai' );

			return $strings;
		}

		/**
		 * Enqueue page-specific assets.
		 */
		protected function enqueue_page_assets() {
			$test_script_relative = 'assets/js/admin-test-profession.js';
			$test_style_relative  = 'assets/css/admin-test-profession.css';

			wp_enqueue_style(
				'wp-mcp-ai-admin-test-profession',
				WP_MCP_AI_URL . $test_style_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_style_relative )
			);

			wp_enqueue_script(
				'wp-mcp-ai-admin-test-profession',
				WP_MCP_AI_URL . $test_script_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_script_relative ),
				true
			);
		}

		/**
		 * Render the test profession page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
			}

			// Safety check: Ensure the Profession CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
				?>
				<div class="wrap">
					<h1><?php echo esc_html__( 'Test AI Professions', 'wp-mcp-ai' ); ?></h1>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'The Profession CPT class is not loaded. Please contact support.', 'wp-mcp-ai' ); ?></p>
					</div>
				</div>
				<?php
				return;
			}

			$post_type = defined( 'WP_MCP_AI_Profession_CPT::POST_TYPE' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

			// Get all published professions.
			$professions = get_posts(
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
				<h1><?php echo esc_html__( 'Test AI Professions', 'wp-mcp-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Test your AI professions directly from the admin dashboard. Click "Test" next to any profession to create a temporary assistant and validate its behavior.', 'wp-mcp-ai' ); ?></p>

				<?php if ( empty( $professions ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new profession */
								esc_html__( 'No professions found. %s to get started.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first profession', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Profession Name', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Category', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Expertise Areas', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Default Tools', 'wp-mcp-ai' ); ?></th>
								<th scope="col" class="column-actions"><?php echo esc_html__( 'Actions', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $professions as $profession ) : ?>
								<?php
								$category              = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
								$expertise             = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
								$tools                 = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, true );
								$role_description      = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, true );
								$knowledge_base        = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, true );
								$associated_assistant  = get_post_meta( $profession->ID, WP_MCP_AI_Profession_CPT::META_ASSOCIATED_ASSISTANT, true );
								$edit_url              = get_edit_post_link( $profession->ID );

								$category_labels = array(
									'advisory'   => __( 'Advisory/Consulting', 'wp-mcp-ai' ),
									'creative'   => __( 'Creative Services', 'wp-mcp-ai' ),
									'technical'  => __( 'Technical', 'wp-mcp-ai' ),
									'healthcare' => __( 'Healthcare', 'wp-mcp-ai' ),
									'legal'      => __( 'Legal', 'wp-mcp-ai' ),
									'financial'  => __( 'Financial', 'wp-mcp-ai' ),
									'other'      => __( 'Other', 'wp-mcp-ai' ),
								);

								$category_display = isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ( $category ? ucfirst( $category ) : '—' );
								$expertise_count  = is_array( $expertise ) ? count( $expertise ) : 0;
								$tools_count      = is_array( $tools ) ? count( $tools ) : 0;

								// Get associated assistant title if set and validate the assistant exists.
								$assistant_title = '';
								$valid_associated_assistant = 0;
								if ( $associated_assistant ) {
									$assistant_post = get_post( $associated_assistant );
									if ( $assistant_post && 'publish' === $assistant_post->post_status ) {
										$assistant_title = $assistant_post->post_title;
										$valid_associated_assistant = absint( $associated_assistant );
									}
								}

								// Prepare profession data for JavaScript.
								$profession_data = array(
									'id'                   => $profession->ID,
									'title'                => $profession->post_title,
									'category'             => $category_display,
									'role_description'     => $role_description,
									'expertise'            => is_array( $expertise ) ? $expertise : array(),
									'tools'                => is_array( $tools ) ? $tools : array(),
									'knowledge_base'       => ! empty( $knowledge_base ) ? substr( wp_strip_all_tags( $knowledge_base ), 0, 200 ) : '',
									'associated_assistant' => $valid_associated_assistant,
									'assistant_title'      => $assistant_title,
								);
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $profession->post_title ); ?></strong>
										<div class="row-actions">
											<span class="edit">
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html__( 'Edit', 'wp-mcp-ai' ); ?>
												</a>
											</span>
										</div>
									</td>
									<td><?php echo esc_html( $category_display ); ?></td>
									<td>
										<?php
										if ( $expertise_count > 0 ) {
											printf(
												/* translators: %d: number of expertise areas */
												esc_html( _n( '%d area', '%d areas', $expertise_count, 'wp-mcp-ai' ) ),
												absint( $expertise_count )
											);
										} else {
											echo '—';
										}
										?>
									</td>
									<td>
										<?php
										if ( $tools_count > 0 ) {
											printf(
												/* translators: %d: number of default tools */
												esc_html( _n( '%d tool', '%d tools', $tools_count, 'wp-mcp-ai' ) ),
												absint( $tools_count )
											);
										} else {
											echo '—';
										}
										?>
									</td>
									<td>
										<button 
											type="button" 
											class="button button-primary wp-mcp-ai-test-profession-btn"
											data-profession-id="<?php echo esc_attr( $profession->ID ); ?>"
											data-profession-title="<?php echo esc_attr( $profession->post_title ); ?>"
											data-profession-data="<?php echo esc_attr( wp_json_encode( $profession_data ) ); ?>"
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
				<div id="wp-mcp-ai-test-profession-modal" class="wp-mcp-ai-test-modal" style="display: none;">
					<div class="wp-mcp-ai-test-modal__backdrop"></div>
					<div class="wp-mcp-ai-test-modal__panel">
						<div class="wp-mcp-ai-test-modal__header">
							<h2 id="wp-mcp-ai-test-profession-modal__title"><?php echo esc_html__( 'Test Profession', 'wp-mcp-ai' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'wp-mcp-ai' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<div class="wp-mcp-ai-test-profession-info">
								<p class="description">
									<?php echo esc_html__( 'Testing profession behavior by creating a temporary assistant. Review the profession details below to understand the capabilities being tested.', 'wp-mcp-ai' ); ?>
								</p>
							</div>
							<!-- Profession details will be populated here -->
							<div id="wp-mcp-ai-profession-details-container"></div>
							<!-- Chat interface will be initialized here -->
							<div id="wp-mcp-ai-test-profession-chat-container"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
