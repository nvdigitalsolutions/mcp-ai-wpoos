<?php
/**
 * Test Team Admin Page
 *
 * Provides an interface for administrators to test AI teams directly from the WordPress admin.
 * Allows testing a team by creating temporary assistants for each team member and chatting with them.
 * Uses base class for better SoC and code reuse.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-test-page-base.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Team' ) ) {
	/**
	 * Test Team admin page handler.
	 * Extends base class for shared functionality.
	 */
	class WP_MCP_AI_Admin_Test_Team extends WP_MCP_AI_Admin_Test_Page_Base {

		/**
		 * Get the post type for this test page.
		 *
		 * @return string
		 */
		protected function get_post_type() {
			if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
				return 'mcp_ai_team';
			}
			return WP_MCP_AI_Team_CPT::POST_TYPE;
		}

		/**
		 * Get the page slug.
		 *
		 * @return string
		 */
		protected function get_page_slug() {
			return 'wp-mcp-ai-test-team';
		}

		/**
		 * Get the page title.
		 *
		 * @return string
		 */
		protected function get_page_title() {
			return __( 'Test Team', 'wp-mcp-ai' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		protected function get_menu_title() {
			return __( 'Test Team', 'wp-mcp-ai' );
		}

		/**
		 * Customize chat strings for team testing.
		 *
		 * @return array
		 */
		protected function get_chat_strings() {
			$strings = parent::get_chat_strings();

			// Customize specific strings for team context.
			$strings['waiting']                 = __( 'Waiting for team member…', 'wp-mcp-ai' );
			$strings['missingAssistant']        = __( 'Team configuration was not found.', 'wp-mcp-ai' );
			$strings['notAuthorized']           = __( 'You do not have permission to test this team.', 'wp-mcp-ai' );
			$strings['teamMemberLoadError']     = __( 'Failed to load team members. Please try again.', 'wp-mcp-ai' );
			$strings['roleLabels']['assistant'] = __( 'Team Member', 'wp-mcp-ai' );

			return $strings;
		}

		/**
		 * Enqueue page-specific assets.
		 */
		protected function enqueue_page_assets() {
			$test_script_relative = 'assets/js/admin-test-team.js';
			$test_style_relative  = 'assets/css/admin-test-team.css';

			wp_enqueue_style(
				'wp-mcp-ai-admin-test-team',
				WP_MCP_AI_URL . $test_style_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_style_relative )
			);

			wp_enqueue_script(
				'wp-mcp-ai-admin-test-team',
				WP_MCP_AI_URL . $test_script_relative,
				array( 'wp-mcp-ai-chat', 'jquery' ),
				$this->get_asset_version( $test_script_relative ),
				true
			);
		}

		/**
		 * Render the test team page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
			}

			// Safety check: Ensure the Team CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
				?>
				<div class="wrap">
					<h1><?php echo esc_html__( 'Test AI Teams', 'wp-mcp-ai' ); ?></h1>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'The Team CPT class is not loaded. Please contact support.', 'wp-mcp-ai' ); ?></p>
					</div>
				</div>
				<?php
				return;
			}

			$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

			// Get all published teams.
			$teams = get_posts(
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
				<h1><?php echo esc_html__( 'Test AI Teams', 'wp-mcp-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Test your AI teams directly from the admin dashboard. Click "Test" next to any team to create temporary assistants for each team member and validate the team configuration.', 'wp-mcp-ai' ); ?></p>

				<?php if ( empty( $teams ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new team */
								esc_html__( 'No teams found. %s to get started.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first team', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Team Name', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Members', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Provider', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Model', 'wp-mcp-ai' ); ?></th>
								<th scope="col" class="column-actions"><?php echo esc_html__( 'Actions', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $teams as $team ) : ?>
								<?php
								$team_members     = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
								$default_provider = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, true );
								$default_model    = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, true );
								$edit_url         = get_edit_post_link( $team->ID );

								$member_count = is_array( $team_members ) ? count( $team_members ) : 0;

								$provider_labels = array(
									'openai'    => 'OpenAI',
									'gemini'    => 'Gemini',
									'anthropic' => 'Claude',
									'ollama'    => 'Ollama',
									'lm_studio' => 'LM Studio',
								);

								$provider_display = $default_provider && isset( $provider_labels[ $default_provider ] ) ? $provider_labels[ $default_provider ] : __( 'Default', 'wp-mcp-ai' );
								$model_display    = $default_model ? $default_model : __( 'Default', 'wp-mcp-ai' );

								// Get member names.
								$member_names = array();
								if ( is_array( $team_members ) && ! empty( $team_members ) ) {
									foreach ( $team_members as $member_id ) {
										$member = get_post( $member_id );
										if ( $member && 'mcp_ai_profession' === $member->post_type ) {
											$member_names[] = $member->post_title;
										}
									}
								}
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $team->post_title ); ?></strong>
										<div class="row-actions">
											<span class="edit">
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html__( 'Edit', 'wp-mcp-ai' ); ?>
												</a>
											</span>
										</div>
									</td>
									<td>
										<?php
										if ( $member_count > 0 ) {
											printf(
												/* translators: %d: number of team members */
												'<strong>' . esc_html( _n( '%d professional', '%d professionals', $member_count, 'wp-mcp-ai' ) ) . '</strong>',
												absint( $member_count )
											);
											if ( ! empty( $member_names ) ) {
												echo '<br><small>' . esc_html( implode( ', ', array_slice( $member_names, 0, 3 ) ) );
												if ( count( $member_names ) > 3 ) {
													echo ', ' . esc_html( sprintf( __( 'and %d more', 'wp-mcp-ai' ), count( $member_names ) - 3 ) );
												}
												echo '</small>';
											}
										} else {
											echo '<span class="description">' . esc_html__( 'No members', 'wp-mcp-ai' ) . '</span>';
										}
										?>
									</td>
									<td><?php echo esc_html( $provider_display ); ?></td>
									<td><code><?php echo esc_html( $model_display ); ?></code></td>
									<td>
										<button 
											type="button" 
											class="button button-primary wp-mcp-ai-test-team-btn"
											data-team-id="<?php echo esc_attr( $team->ID ); ?>"
											data-team-title="<?php echo esc_attr( $team->post_title ); ?>"
											data-member-count="<?php echo esc_attr( $member_count ); ?>"
											<?php echo esc_attr( ( 0 === $member_count ) ? 'disabled' : '' ); ?>
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
				<div id="wp-mcp-ai-test-team-modal" class="wp-mcp-ai-test-modal" style="display: none;">
					<div class="wp-mcp-ai-test-modal__backdrop"></div>
					<div class="wp-mcp-ai-test-modal__panel wp-mcp-ai-test-modal__panel--wide">
						<div class="wp-mcp-ai-test-modal__header">
							<h2 id="wp-mcp-ai-test-team-modal__title"><?php echo esc_html__( 'Test Team', 'wp-mcp-ai' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'wp-mcp-ai' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<div class="wp-mcp-ai-test-team-info">
								<p class="description">
									<?php echo esc_html__( 'Testing team by creating temporary assistants for each team member. Select a team member below to start chatting and validate the team configuration.', 'wp-mcp-ai' ); ?>
								</p>
							</div>
							<!-- Team member selector -->
							<div id="wp-mcp-ai-test-team-selector" class="wp-mcp-ai-test-team-selector"></div>
							<!-- Chat interface will be initialized here -->
							<div id="wp-mcp-ai-test-team-chat-container"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
