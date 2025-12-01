<?php
/**
 * Admin UI for Create Team button and modal.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Create AI Team" button to the assistant post type page and handles the modal UI.
 */
class WP_MCP_AI_Admin_Create_Team_Button {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_modal' ) );
		add_filter( 'views_edit-mcp_ai_assistant', array( __CLASS__, 'add_create_button' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_team_from_modal', array( __CLASS__, 'handle_ajax_create' ) );
	}

	/**
	 * Enqueue scripts and styles for the create team modal.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		// Only load on the assistant list page.
		if ( 'edit.php' !== $hook || ! isset( $_GET['post_type'] ) || 'mcp_ai_assistant' !== $_GET['post_type'] ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-create-team-modal',
			WP_MCP_AI_URL . 'assets/css/admin-create-team-modal.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-create-team-modal',
			WP_MCP_AI_URL . 'assets/js/admin-create-team-modal.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-create-team-modal',
			'wpMcpAiCreateTeam',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_create_team' ),
				'strings'     => array(
					'creating'       => __( 'Creating team...', 'wp-mcp-ai' ),
					'success'        => __( 'Team created successfully!', 'wp-mcp-ai' ),
					'error'          => __( 'Error creating team. Please try again.', 'wp-mcp-ai' ),
					'required'       => __( 'This field is required.', 'wp-mcp-ai' ),
					'minProfessions' => __( 'Please select at least 2 professions to create a team.', 'wp-mcp-ai' ),
				),
				'professions' => self::get_professions(),
			)
		);
	}

	/**
	 * Add create team button to the post type page.
	 *
	 * @param array $views Views array.
	 * @return array Modified views.
	 */
	public static function add_create_button( $views ) {
		?>
		<style>
			.wp-mcp-ai-create-team-btn {
				margin-left: 10px;
				vertical-align: middle;
			}
		</style>
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Add button after the Create AI Assistant button
				var button = '<button type="button" class="page-title-action wp-mcp-ai-create-team-btn" id="wp-mcp-ai-open-create-team-modal"><?php echo esc_js( __( 'Create AI Team', 'wp-mcp-ai' ) ); ?></button>';
				$('#wp-mcp-ai-open-create-modal').after(button);
			});
		</script>
		<?php
		return $views;
	}

	/**
	 * Render the create team modal HTML.
	 */
	public static function render_modal() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-mcp_ai_assistant' !== $screen->id ) {
			return;
		}

		?>
		<div id="wp-mcp-ai-create-team-modal" class="wp-mcp-ai-modal" style="display:none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Create AI Team', 'wp-mcp-ai' ); ?></h2>
					<button type="button" class="button button-secondary wp-mcp-ai-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wp-mcp-ai' ); ?>">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<form id="wp-mcp-ai-create-team-form">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row">
										<label for="team-title">
											<?php esc_html_e( 'Team Name', 'wp-mcp-ai' ); ?> <span class="required">*</span>
										</label>
									</th>
									<td>
										<input type="text" id="team-title" name="title" class="regular-text" required>
										<p class="description">
											<?php esc_html_e( 'E.g., "Jamaica Business Advisory Team", "International Trade Team"', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="team-professions">
											<?php esc_html_e( 'Team Members (Professions)', 'wp-mcp-ai' ); ?> <span class="required">*</span>
										</label>
									</th>
									<td>
										<select id="team-professions" name="professions[]" multiple class="regular-text" required style="height: 200px;">
											<?php foreach ( self::get_professions() as $profession_id => $profession_title ) : ?>
												<option value="<?php echo esc_attr( $profession_id ); ?>"><?php echo esc_html( $profession_title ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">
											<?php esc_html_e( 'Select at least 2 professions to form your team. Hold Ctrl/Cmd to select multiple.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="team-description">
											<?php esc_html_e( 'Team Description', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<textarea id="team-description" name="description" class="large-text" rows="4"></textarea>
										<p class="description">
											<?php esc_html_e( 'Optional: Describe the purpose and focus of this team.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="team-provider">
											<?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<select id="team-provider" name="provider" class="regular-text">
											<option value=""><?php esc_html_e( '-- Use Profession Defaults --', 'wp-mcp-ai' ); ?></option>
											<option value="openai"><?php esc_html_e( 'OpenAI', 'wp-mcp-ai' ); ?></option>
											<option value="gemini"><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
											<option value="anthropic"><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
											<option value="ollama"><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
											<option value="lm_studio"><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Override profession defaults with a single provider for all team members.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="team-model">
											<?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<input type="text" id="team-model" name="model" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., gpt-4, gemini-pro', 'wp-mcp-ai' ); ?>">
										<p class="description">
											<?php esc_html_e( 'Override profession defaults with a single model for all team members.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="team-temperature">
											<?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?>
										</label>
									</th>
									<td>
										<input type="number" id="team-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" placeholder="0.7">
										<p class="description">
											<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative. Leave empty to use profession defaults.', 'wp-mcp-ai' ); ?>
										</p>
									</td>
								</tr>
							</tbody>
						</table>
					</form>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-link wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
					</button>
					<button type="submit" form="wp-mcp-ai-create-team-form" class="button button-primary" id="wp-mcp-ai-submit-create-team">
						<?php esc_html_e( 'Create Team', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create team.
	 */
	public static function handle_ajax_create() {
		check_ajax_referer( 'wp_mcp_ai_create_team', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get form data.
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$professions = isset( $_POST['professions'] ) && is_array( $_POST['professions'] ) ? array_map( 'absint', wp_unslash( $_POST['professions'] ) ) : array();
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$provider    = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$model       = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$temperature = isset( $_POST['temperature'] ) && '' !== $_POST['temperature'] ? floatval( $_POST['temperature'] ) : '';

		// Validate required fields.
		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Team name is required.', 'wp-mcp-ai' ) ) );
		}

		if ( empty( $professions ) || count( $professions ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Please select at least 2 professions to create a team.', 'wp-mcp-ai' ) ) );
		}

		// Validate that all profession IDs exist and are valid.
		$valid_professions = array();
		foreach ( $professions as $profession_id ) {
			$profession = get_post( $profession_id );
			if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type || 'publish' !== $profession->post_status ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: profession ID */
							__( 'Invalid profession ID: %d', 'wp-mcp-ai' ),
							$profession_id
						),
					)
				);
			}
			$valid_professions[] = $profession_id;
		}

		// Create the team post.
		$team_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_team',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $team_id ) ) {
			wp_send_json_error( array( 'message' => $team_id->get_error_message() ) );
		}

		// Set team meta.
		update_post_meta( $team_id, '_wp_mcp_ai_team_members', $valid_professions );

		if ( ! empty( $provider ) ) {
			update_post_meta( $team_id, '_wp_mcp_ai_team_default_provider', $provider );
		}

		if ( ! empty( $model ) ) {
			update_post_meta( $team_id, '_wp_mcp_ai_team_default_model', $model );
		}

		if ( '' !== $temperature ) {
			update_post_meta( $team_id, '_wp_mcp_ai_team_default_temperature', $temperature );
		}

		wp_send_json_success(
			array(
				'team_id'  => $team_id,
				'edit_url' => get_edit_post_link( $team_id, 'raw' ),
				'message'  => sprintf(
					/* translators: %d: number of team members */
					_n( 'Team created successfully with %d member!', 'Team created successfully with %d members!', count( $valid_professions ), 'wp-mcp-ai' ),
					count( $valid_professions )
				),
			)
		);
	}

	/**
	 * Get profession options from CPT system.
	 *
	 * @return array Profession ID => title pairs.
	 */
	protected static function get_professions() {
		$professions = array();

		$profession_posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		foreach ( $profession_posts as $profession ) {
			$professions[ $profession->ID ] = $profession->post_title;
		}

		return $professions;
	}
}
