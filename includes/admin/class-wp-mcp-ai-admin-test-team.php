<?php
/**
 * Test Team Admin Page
 *
 * Provides an interface for administrators to test AI teams directly from the WordPress admin.
 * Allows testing a team by creating temporary assistants for each team member and chatting with them.
 * Uses base class for better SoC and code reuse.
 *
 * IMPORTANT: Teams require a "driver assistant" to coordinate team operations. The driver assistant
 * acts as the orchestrator that manages communication between team members and aggregates their
 * responses. Without a driver assistant, teams cannot function properly.
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
			return __( 'Test Team', 'mcp-ai-wpoos' );
		}

		/**
		 * Get the menu title.
		 *
		 * @return string
		 */
		protected function get_menu_title() {
			return __( 'Test Team', 'mcp-ai-wpoos' );
		}

		/**
		 * Customize chat strings for team testing.
		 *
		 * @return array
		 */
		protected function get_chat_strings() {
			$strings = parent::get_chat_strings();

			// Customize specific strings for team context.
			$strings['waiting']                 = __( 'Waiting for team member…', 'mcp-ai-wpoos' );
			$strings['missingAssistant']        = __( 'Team configuration was not found.', 'mcp-ai-wpoos' );
			$strings['notAuthorized']           = __( 'You do not have permission to test this team.', 'mcp-ai-wpoos' );
			$strings['teamMemberLoadError']     = __( 'Failed to load team members. Please try again.', 'mcp-ai-wpoos' );
			$strings['roleLabels']['assistant'] = __( 'Team Member', 'mcp-ai-wpoos' );

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
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Safety check: Ensure the Team CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
				?>
				<div class="wrap">
					<h1><?php echo esc_html__( 'Test AI Teams', 'mcp-ai-wpoos' ); ?></h1>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'The Team CPT class is not loaded. Please contact support.', 'mcp-ai-wpoos' ); ?></p>
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
				<h1><?php echo esc_html__( 'Test AI Teams', 'mcp-ai-wpoos' ); ?></h1>
				<p><?php echo esc_html__( 'Test your AI teams directly from the admin dashboard. Click "Test" next to any team to create temporary assistants for each team member and validate the team configuration.', 'mcp-ai-wpoos' ); ?></p>

				<?php
				// Check if any teams are missing driver assistants.
				$teams_missing_driver = array();
				foreach ( $teams as $team ) {
					$driver_id = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DRIVER_ASSISTANT, true );
					if ( ! $driver_id ) {
						$driver_id = get_option( 'wp_mcp_ai_team_default_driver_assistant', 0 );
					}
					if ( ! $driver_id ) {
						$teams_missing_driver[] = $team;
					}
				}

				if ( ! empty( $teams_missing_driver ) ) :
					?>
					<div class="notice notice-warning">
						<p>
							<strong><?php esc_html_e( 'Configuration Required:', 'mcp-ai-wpoos' ); ?></strong>
							<?php
							printf(
								/* translators: %d: number of teams */
								esc_html( _n( '%d team is missing a driver assistant and cannot be tested.', '%d teams are missing driver assistants and cannot be tested.', count( $teams_missing_driver ), 'mcp-ai-wpoos' ) ),
								absint( count( $teams_missing_driver ) )
							);
							?>
							<?php
							printf(
								/* translators: %s: URL to team settings */
								esc_html__( 'Please assign driver assistants in %s or on individual team edit pages.', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=wp-mcp-ai-team-settings' ) ) . '">' . esc_html__( 'Team Settings', 'mcp-ai-wpoos' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( empty( $teams ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new team */
								esc_html__( 'No teams found. %s to get started.', 'mcp-ai-wpoos' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first team', 'mcp-ai-wpoos' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Team Name', 'mcp-ai-wpoos' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Members', 'mcp-ai-wpoos' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Provider', 'mcp-ai-wpoos' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Model', 'mcp-ai-wpoos' ); ?></th>
								<th scope="col" class="column-actions"><?php echo esc_html__( 'Actions', 'mcp-ai-wpoos' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $teams as $team ) : ?>
								<?php
								$team_members     = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
								$default_provider = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, true );
								$default_model    = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, true );
								$edit_url         = get_edit_post_link( $team->ID );

								// Check for driver assistant.
								$driver_assistant_id = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DRIVER_ASSISTANT, true );
								if ( ! $driver_assistant_id ) {
									$driver_assistant_id = get_option( 'wp_mcp_ai_team_default_driver_assistant', 0 );
								}
								$has_driver_assistant = (bool) $driver_assistant_id;

								// Get orchestration settings for multi-agent coordination.
								$orchestration_mode  = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_ORCHESTRATION_MODE, true );
								$result_aggregation  = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_RESULT_AGGREGATION_STRATEGY, true );
								$multi_agent_enabled = class_exists( 'WP_MCP_AI_Settings_Registry' ) ? WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true ) : true;

								$member_count = is_array( $team_members ) ? count( $team_members ) : 0;

								$provider_labels = array(
									'openai'    => 'OpenAI',
									'gemini'    => 'Gemini',
									'anthropic' => 'Claude',
									'ollama'    => 'Ollama',
									'lm_studio' => 'LM Studio',
								);

								$provider_display = $default_provider && isset( $provider_labels[ $default_provider ] ) ? $provider_labels[ $default_provider ] : __( 'Default', 'mcp-ai-wpoos' );
								$model_display    = $default_model ? $default_model : __( 'Default', 'mcp-ai-wpoos' );

								// Build complete member data array.
								$member_names = array();
								$members_data = array();
								if ( is_array( $team_members ) && ! empty( $team_members ) ) {
									foreach ( $team_members as $member_id ) {
										$member = get_post( $member_id );
										if ( $member && 'mcp_ai_profession' === $member->post_type ) {
											$member_names[] = $member->post_title;

											// Get profession metadata (only if Profession CPT class is loaded).
											$category  = '';
											$expertise = array();
											if ( class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
												$category  = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, true );
												$expertise = get_post_meta( $member_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, true );
											}

											$category_labels = array(
												'advisory'   => __( 'Advisory/Consulting', 'mcp-ai-wpoos' ),
												'creative'   => __( 'Creative Services', 'mcp-ai-wpoos' ),
												'technical'  => __( 'Technical', 'mcp-ai-wpoos' ),
												'healthcare' => __( 'Healthcare', 'mcp-ai-wpoos' ),
												'legal'      => __( 'Legal', 'mcp-ai-wpoos' ),
												'financial'  => __( 'Financial', 'mcp-ai-wpoos' ),
												'other'      => __( 'Other', 'mcp-ai-wpoos' ),
											);

											$category_display = isset( $category_labels[ $category ] ) ? $category_labels[ $category ] : ( $category ? ucfirst( $category ) : '' );

											$members_data[] = array(
												'id'       => $member_id,
												'title'    => $member->post_title,
												'category' => $category_display,
											);
										}
									}
								}

								// Build team data for JavaScript.
								$team_data = array(
									'members'                => $members_data,
									'orchestration_mode'     => $orchestration_mode ? $orchestration_mode : 'sequential',
									'result_aggregation'     => $result_aggregation ? $result_aggregation : 'consensus',
									'multi_agent_enabled'    => $multi_agent_enabled,
									'supports_unified_mode'  => $multi_agent_enabled && $member_count > 1,
								);
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $team->post_title ); ?></strong>
										<?php if ( ! $has_driver_assistant ) : ?>
											<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'Missing driver assistant', 'mcp-ai-wpoos' ); ?>"></span>
										<?php endif; ?>
										<div class="row-actions">
											<span class="edit">
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html__( 'Edit', 'mcp-ai-wpoos' ); ?>
												</a>
											</span>
											<?php if ( ! $has_driver_assistant ) : ?>
												<span class="configure-driver">
													| <a href="<?php echo esc_url( $edit_url ); ?>" style="color: #d63638;">
														<?php echo esc_html__( 'Configure Driver', 'mcp-ai-wpoos' ); ?>
													</a>
												</span>
											<?php endif; ?>
										</div>
									</td>
									<td>
										<?php
										if ( $member_count > 0 ) {
											printf(
												/* translators: %d: number of team members */
												'<strong>' . esc_html( _n( '%d professional', '%d professionals', $member_count, 'mcp-ai-wpoos' ) ) . '</strong>',
												absint( $member_count )
											);
											if ( ! empty( $member_names ) ) {
												echo '<br><small>' . esc_html( implode( ', ', array_slice( $member_names, 0, 3 ) ) );
												if ( count( $member_names ) > 3 ) {
													echo ', ' . esc_html( sprintf( __( 'and %d more', 'mcp-ai-wpoos' ), count( $member_names ) - 3 ) );
												}
												echo '</small>';
											}
										} else {
											echo '<span class="description">' . esc_html__( 'No members', 'mcp-ai-wpoos' ) . '</span>';
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
											data-team-data="<?php echo esc_attr( wp_json_encode( $team_data ) ); ?>"
											<?php disabled( 0, $member_count ); ?>
											<?php disabled( ! $has_driver_assistant ); ?>
										>
											<?php echo esc_html__( 'Test', 'mcp-ai-wpoos' ); ?>
										</button>
										<?php if ( ! $has_driver_assistant && $member_count > 0 ) : ?>
											<p class="description" style="color: #d63638; margin-top: 5px;">
												<?php esc_html_e( 'Missing driver assistant', 'mcp-ai-wpoos' ); ?>
											</p>
										<?php endif; ?>
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
							<h2 id="wp-mcp-ai-test-team-modal__title"><?php echo esc_html__( 'Test Team', 'mcp-ai-wpoos' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'mcp-ai-wpoos' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<div class="wp-mcp-ai-test-team-info">
								<p class="description">
									<?php echo esc_html__( 'Test team coordination with DeepSeek V4 multi-agent orchestration. When multi-agent mode is enabled, the entire team responds as one coordinated unit. You can also test individual team members separately.', 'mcp-ai-wpoos' ); ?>
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
