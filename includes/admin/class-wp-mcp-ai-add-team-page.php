<?php
/**
 * Add Team Page.
 *
 * Admin page for deploying teams of assistants.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Add Team page in admin.
 */
class WP_MCP_AI_Add_Team_Page {
	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook;

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wp_mcp_ai_deploy_team', array( $instance, 'handle_ajax_deploy' ) );
	}

	/**
	 * Register the admin page.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=mcp_ai_team',
			__( 'Build Team', 'wp-mcp-ai' ),
			__( 'Build Team', 'wp-mcp-ai' ),
			'edit_posts',
			'wp-mcp-ai-add-team',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for this page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-add-team',
			WP_MCP_AI_URL . 'assets/css/admin-add-team.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-add-team',
			WP_MCP_AI_URL . 'assets/js/admin-add-team.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-add-team',
			'wpMcpAiAddTeam',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_deploy_team' ),
				'strings' => array(
					'deploying' => __( 'Deploying team...', 'wp-mcp-ai' ),
					'success'   => __( 'Team deployed successfully!', 'wp-mcp-ai' ),
					'error'     => __( 'Error deploying team. Please try again.', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Render the page content.
	 */
	public function render_page() {
		// Get all published teams.
		$teams = get_posts(
			array(
				'post_type'      => 'mcp_ai_team',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add Team', 'wp-mcp-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Deploy a team of AI assistants with one click. Each team member will be created as a separate assistant based on their professional template.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( empty( $teams ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create team */
							esc_html__( 'No teams found. Please %s first to deploy teams of assistants.', 'wp-mcp-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_team' ) ) . '">' . esc_html__( 'create a team', 'wp-mcp-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="wp-mcp-ai-teams-list">
					<?php foreach ( $teams as $team ) : ?>
						<?php
						$team_members        = get_post_meta( $team->ID, '_wp_mcp_ai_team_members', true );
						$default_provider    = get_post_meta( $team->ID, '_wp_mcp_ai_team_default_provider', true );
						$default_model       = get_post_meta( $team->ID, '_wp_mcp_ai_team_default_model', true );
						$default_temperature = get_post_meta( $team->ID, '_wp_mcp_ai_team_default_temperature', true );
						$members_count       = is_array( $team_members ) ? count( $team_members ) : 0;

						// Get member details.
						$member_titles = array();
						if ( is_array( $team_members ) ) {
							foreach ( $team_members as $member_id ) {
								$member = get_post( $member_id );
								if ( $member ) {
									$member_titles[] = $member->post_title;
								}
							}
						}
						?>
						<div class="wp-mcp-ai-team-card">
							<div class="team-header">
								<h3><?php echo esc_html( $team->post_title ); ?></h3>
								<span class="team-members-count">
									<?php
									printf(
										/* translators: %d: number of team members */
										esc_html( _n( '%d member', '%d members', $members_count, 'wp-mcp-ai' ) ),
										absint( $members_count )
									);
									?>
								</span>
							</div>

							<div class="team-content">
								<?php if ( $team->post_content ) : ?>
									<p class="team-description"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $team->post_content ), 30 ) ); ?></p>
								<?php endif; ?>

								<?php if ( ! empty( $member_titles ) ) : ?>
									<div class="team-members">
										<strong><?php esc_html_e( 'Team Members:', 'wp-mcp-ai' ); ?></strong>
										<ul>
											<?php foreach ( $member_titles as $member_title ) : ?>
												<li><?php echo esc_html( $member_title ); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>

								<div class="team-settings">
									<?php if ( $default_provider ) : ?>
										<div class="team-setting">
											<strong><?php esc_html_e( 'Provider:', 'wp-mcp-ai' ); ?></strong>
											<?php echo esc_html( ucfirst( str_replace( '_', ' ', $default_provider ) ) ); ?>
										</div>
									<?php endif; ?>
									<?php if ( $default_model ) : ?>
										<div class="team-setting">
											<strong><?php esc_html_e( 'Model:', 'wp-mcp-ai' ); ?></strong>
											<?php echo esc_html( $default_model ); ?>
										</div>
									<?php endif; ?>
									<?php if ( $default_temperature ) : ?>
										<div class="team-setting">
											<strong><?php esc_html_e( 'Temperature:', 'wp-mcp-ai' ); ?></strong>
											<?php echo esc_html( $default_temperature ); ?>
										</div>
									<?php endif; ?>
								</div>
							</div>

							<div class="team-actions">
								<?php if ( $members_count > 0 ) : ?>
									<button type="button" class="button button-primary button-large wp-mcp-ai-deploy-team" data-team-id="<?php echo esc_attr( $team->ID ); ?>">
										<?php
										printf(
											/* translators: %d: number of assistants to create */
											esc_html( _n( 'Deploy Team (%d Assistant)', 'Deploy Team (%d Assistants)', $members_count, 'wp-mcp-ai' ) ),
											absint( $members_count )
										);
										?>
									</button>
								<?php else : ?>
									<button type="button" class="button button-primary button-large" disabled>
										<?php esc_html_e( 'No Members', 'wp-mcp-ai' ); ?>
									</button>
								<?php endif; ?>
								<a href="<?php echo esc_url( get_edit_post_link( $team->ID ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'Edit Team', 'wp-mcp-ai' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Deploy Results Modal -->
		<div id="wp-mcp-ai-deploy-results-modal" class="wp-mcp-ai-modal" style="display:none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Team Deployment Results', 'wp-mcp-ai' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal-close">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<div id="deploy-results-content"></div>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-primary wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Close', 'wp-mcp-ai' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'View Assistants', 'wp-mcp-ai' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to deploy a team.
	 */
	public function handle_ajax_deploy() {
		check_ajax_referer( 'wp_mcp_ai_deploy_team', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get team ID.
		$team_id = isset( $_POST['team_id'] ) ? absint( $_POST['team_id'] ) : 0;

		// Validate team ID.
		if ( ! $team_id || 'mcp_ai_team' !== get_post_type( $team_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid team.', 'wp-mcp-ai' ) ) );
		}

		// Get team data.
		$team                = get_post( $team_id );
		$team_members        = get_post_meta( $team_id, '_wp_mcp_ai_team_members', true );
		$default_provider    = get_post_meta( $team_id, '_wp_mcp_ai_team_default_provider', true );
		$default_model       = get_post_meta( $team_id, '_wp_mcp_ai_team_default_model', true );
		$default_temperature = get_post_meta( $team_id, '_wp_mcp_ai_team_default_temperature', true );

		if ( ! is_array( $team_members ) || empty( $team_members ) ) {
			wp_send_json_error( array( 'message' => __( 'Team has no members.', 'wp-mcp-ai' ) ) );
		}

		$created_assistants = array();
		$errors             = array();

		// Create an assistant for each team member.
		foreach ( $team_members as $profession_id ) {
			$profession = get_post( $profession_id );
			if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type ) {
				$errors[] = sprintf(
					/* translators: %d: profession ID */
					__( 'Invalid profession ID: %d', 'wp-mcp-ai' ),
					$profession_id
				);
				continue;
			}

			// Get profession data.
			$profession_meta  = get_post_meta( $profession_id );
			$role_description = isset( $profession_meta['_wp_mcp_ai_profession_role_description'][0] ) ? $profession_meta['_wp_mcp_ai_profession_role_description'][0] : '';
			$default_tools    = isset( $profession_meta['_wp_mcp_ai_profession_default_tools'][0] ) ? maybe_unserialize( $profession_meta['_wp_mcp_ai_profession_default_tools'][0] ) : array();
			$knowledge_base   = isset( $profession_meta['_wp_mcp_ai_profession_knowledge_base'][0] ) ? $profession_meta['_wp_mcp_ai_profession_knowledge_base'][0] : '';
			$memory_files     = isset( $profession_meta['_wp_mcp_ai_profession_memory_files'][0] ) ? maybe_unserialize( $profession_meta['_wp_mcp_ai_profession_memory_files'][0] ) : array();
			$prof_provider    = isset( $profession_meta['_wp_mcp_ai_profession_default_provider'][0] ) ? $profession_meta['_wp_mcp_ai_profession_default_provider'][0] : 'openai';
			$prof_model       = isset( $profession_meta['_wp_mcp_ai_profession_default_model'][0] ) ? $profession_meta['_wp_mcp_ai_profession_default_model'][0] : 'gpt-4o';
			$prof_temp        = isset( $profession_meta['_wp_mcp_ai_profession_default_temperature'][0] ) ? floatval( $profession_meta['_wp_mcp_ai_profession_default_temperature'][0] ) : 0.7;

			// Team defaults override profession defaults.
			$final_provider    = ! empty( $default_provider ) ? $default_provider : $prof_provider;
			$final_model       = ! empty( $default_model ) ? $default_model : $prof_model;
			$final_temperature = ! empty( $default_temperature ) ? floatval( $default_temperature ) : $prof_temp;

			// Build system prompt.
			$system_prompt = $role_description;
			if ( ! empty( $knowledge_base ) ) {
				$system_prompt .= "\n\n" . __( 'Knowledge Base:', 'wp-mcp-ai' ) . "\n" . $knowledge_base;
			}

			// Create assistant title with team prefix.
			$assistant_title = sprintf(
				/* translators: 1: team name, 2: profession name */
				__( '%1$s - %2$s', 'wp-mcp-ai' ),
				$team->post_title,
				$profession->post_title
			);

			// Create the assistant post.
			$assistant_id = wp_insert_post(
				array(
					'post_type'    => 'mcp_ai_assistant',
					'post_title'   => $assistant_title,
					'post_content' => $profession->post_content,
					'post_status'  => 'publish',
				)
			);

			if ( is_wp_error( $assistant_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: profession name, 2: error message */
					__( 'Failed to create %1$s: %2$s', 'wp-mcp-ai' ),
					$profession->post_title,
					$assistant_id->get_error_message()
				);
				continue;
			}

			// Set assistant meta.
			update_post_meta( $assistant_id, '_wp_mcp_ai_provider', $final_provider );
			update_post_meta( $assistant_id, '_wp_mcp_ai_model', $final_model );
			update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', $final_temperature );
			update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', $system_prompt );

			if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
				update_post_meta( $assistant_id, '_wp_mcp_ai_tools', $default_tools );
			}

			if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
				update_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', $memory_files );
			}

			// Store references.
			update_post_meta( $assistant_id, '_wp_mcp_ai_source_profession', $profession_id );
			update_post_meta( $assistant_id, '_wp_mcp_ai_source_team', $team_id );

			$created_assistants[] = array(
				'id'    => $assistant_id,
				'title' => $assistant_title,
				'url'   => get_edit_post_link( $assistant_id, 'raw' ),
			);
		}

		if ( empty( $created_assistants ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to create any assistants.', 'wp-mcp-ai' ),
					'errors'  => $errors,
				)
			);
		}

		wp_send_json_success(
			array(
				'message'    => sprintf(
					/* translators: %d: number of assistants created */
					_n( '%d assistant created successfully!', '%d assistants created successfully!', count( $created_assistants ), 'wp-mcp-ai' ),
					count( $created_assistants )
				),
				'assistants' => $created_assistants,
				'errors'     => $errors,
			)
		);
	}
}
