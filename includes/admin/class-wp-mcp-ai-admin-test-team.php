<?php
/**
 * Test Team Admin Page
 *
 * Provides an interface for administrators to test AI teams directly from the WordPress admin.
 * Allows testing a team by creating temporary assistants for each team member and chatting with them.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Team' ) ) {
	/**
	 * Test Team admin page handler.
	 */
	class WP_MCP_AI_Admin_Test_Team {

		/**
		 * Page hook suffix.
		 *
		 * @var string|false
		 */
		private $page_hook;

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the submenu page under Teams.
		 */
		public function register_submenu_page() {
			// Safety check: Ensure the Team CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
				return;
			}

			$post_type = defined( 'WP_MCP_AI_Team_CPT::POST_TYPE' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

			$this->page_hook = add_submenu_page(
				'edit.php?post_type=' . $post_type,
				__( 'Test Team', 'wp-mcp-ai' ),
				__( 'Test Team', 'wp-mcp-ai' ),
				'manage_options',
				'wp-mcp-ai-test-team',
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the test team page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( $hook !== $this->page_hook ) {
				return;
			}

			// Enqueue chat dependencies.
			$this->enqueue_chat_assets();

			// Enqueue test team specific assets.
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
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_script_relative ),
				true
			);
		}

		/**
		 * Enqueue chat interface assets.
		 */
		private function enqueue_chat_assets() {
			$script_relative             = 'assets/js/chat.js';
			$style_relative              = 'assets/css/chat.css';
			$cron_status_script_relative = 'assets/js/cron-status-service.js';
			$cron_status_style_relative  = 'assets/css/cron-status.css';

			$script_path             = WP_MCP_AI_URL . $script_relative;
			$style_path              = WP_MCP_AI_URL . $style_relative;
			$cron_status_script_path = WP_MCP_AI_URL . $cron_status_script_relative;
			$cron_status_style_path  = WP_MCP_AI_URL . $cron_status_style_relative;

			$script_version             = $this->get_asset_version( $script_relative );
			$style_version              = $this->get_asset_version( $style_relative );
			$cron_status_script_version = $this->get_asset_version( $cron_status_script_relative );
			$cron_status_style_version  = $this->get_asset_version( $cron_status_style_relative );

			// Enqueue cron status service first.
			wp_enqueue_script(
				'wp-mcp-ai-cron-status',
				$cron_status_script_path,
				array(),
				$cron_status_script_version,
				true
			);

			wp_enqueue_style(
				'wp-mcp-ai-cron-status',
				$cron_status_style_path,
				array(),
				$cron_status_style_version
			);

			wp_enqueue_style(
				'wp-mcp-ai-chat',
				$style_path,
				array( 'wp-mcp-ai-cron-status' ),
				$style_version
			);

			wp_enqueue_script(
				'wp-mcp-ai-chat',
				$script_path,
				array( 'wp-mcp-ai-cron-status' ),
				$script_version,
				true
			);

			// Safety check: Ensure REST constants exist.
			$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

			wp_localize_script(
				'wp-mcp-ai-chat',
				'wpMcpAiChat',
				array(
					'restUrl'             => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace ) ) ),
					'uploadEndpoint'      => esc_url_raw( $this->normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
					'filesEndpoint'       => esc_url_raw( trailingslashit( $this->normalise_rest_url( rest_url( $rest_namespace . '/files' ) ) ) ),
					'transcriptsEndpoint' => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/chat-transcripts' ) ) ),
					'historyPerPage'      => 20,
					'currentUserId'       => get_current_user_id(),
					'nonce'               => wp_create_nonce( 'wp_rest' ),
					'strings'             => $this->get_chat_strings(),
				)
			);
		}

		/**
		 * Normalize REST URL.
		 *
		 * @param string $url REST URL to normalize.
		 * @return string Normalized URL.
		 */
		private function normalise_rest_url( $url ) {
			if ( class_exists( 'WP_MCP_AI_Request_Context' ) && method_exists( 'WP_MCP_AI_Request_Context', 'normalise_rest_url' ) ) {
				return WP_MCP_AI_Request_Context::normalise_rest_url( $url );
			}
			return $url;
		}

		/**
		 * Get chat interface strings for localization.
		 *
		 * @return array
		 */
		private function get_chat_strings() {
			return array(
				'placeholder'                   => __( 'Ask something…', 'wp-mcp-ai' ),
				'send'                          => __( 'Send', 'wp-mcp-ai' ),
				'bundlingMessages'              => __( 'Preparing to send…', 'wp-mcp-ai' ),
				'sending'                       => __( 'Sending message…', 'wp-mcp-ai' ),
				'waiting'                       => __( 'Waiting for team member…', 'wp-mcp-ai' ),
				'error'                         => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
				'missingAssistant'              => __( 'Team configuration was not found.', 'wp-mcp-ai' ),
				'notAuthorized'                 => __( 'You do not have permission to test this team.', 'wp-mcp-ai' ),
				'toolExecuting'                 => __( 'Running tool: %s', 'wp-mcp-ai' ),
				'toolSuccess'                   => __( 'Tool response ready.', 'wp-mcp-ai' ),
				'toolError'                     => __( 'The tool request failed.', 'wp-mcp-ai' ),
				'emptyMessage'                  => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
				'attachFile'                    => __( 'Attach file', 'wp-mcp-ai' ),
				'newConversation'               => __( 'Start new conversation', 'wp-mcp-ai' ),
				'roleLabels'                    => array(
					'assistant' => __( 'Team Member', 'wp-mcp-ai' ),
					'user'      => __( 'You', 'wp-mcp-ai' ),
					'system'    => __( 'System', 'wp-mcp-ai' ),
					'tool'      => __( 'Tool', 'wp-mcp-ai' ),
				),
			);
		}

		/**
		 * Get asset version based on file modification time.
		 *
		 * @param string $relative_path Asset path relative to plugin root.
		 * @return string
		 */
		private function get_asset_version( $relative_path ) {
			$relative_path = ltrim( $relative_path, '/' );
			$absolute_path = WP_MCP_AI_PATH . $relative_path;

			if ( file_exists( $absolute_path ) ) {
				$modified = filemtime( $absolute_path );

				if ( $modified ) {
					return WP_MCP_AI_VERSION . '.' . $modified;
				}
			}

			return WP_MCP_AI_VERSION;
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

			$post_type = defined( 'WP_MCP_AI_Team_CPT::POST_TYPE' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

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
								$team_members        = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
								$default_provider    = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_PROVIDER, true );
								$default_model       = get_post_meta( $team->ID, WP_MCP_AI_Team_CPT::META_DEFAULT_MODEL, true );
								$edit_url            = get_edit_post_link( $team->ID );

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
											<?php echo ( 0 === $member_count ) ? 'disabled' : ''; ?>
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
