<?php
/**
 * Add Assistant Page.
 *
 * Admin page for creating assistants from professional templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Add Assistant page in admin.
 */
class WP_MCP_AI_Add_Assistant_Page {
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
		add_action( 'wp_ajax_wp_mcp_ai_create_from_professional', array( $instance, 'handle_ajax_create' ) );
	}

	/**
	 * Register the admin page.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Create Assistant', 'wp-mcp-ai' ),
			__( 'Create Assistant', 'wp-mcp-ai' ),
			'edit_posts',
			'wp-mcp-ai-add-assistant',
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
			'wp-mcp-ai-add-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-add-assistant.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-add-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-add-assistant.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-add-assistant',
			'wpMcpAiAddAssistant',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_create_from_professional' ),
				'strings' => array(
					'creating' => __( 'Creating assistant...', 'wp-mcp-ai' ),
					'success'  => __( 'Assistant created successfully!', 'wp-mcp-ai' ),
					'error'    => __( 'Error creating assistant. Please try again.', 'wp-mcp-ai' ),
				),
			)
		);
	}

	/**
	 * Render the page content.
	 */
	public function render_page() {
		// Get all published professionals.
		$professions = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Add New Assistant', 'wp-mcp-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select a professional template to create a new AI assistant. Each professional has pre-configured instructions, tools, and knowledge base.', 'wp-mcp-ai' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create profession */
							esc_html__( 'No professional templates found. Please %s first to create assistants from templates.', 'wp-mcp-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a professional', 'wp-mcp-ai' ) . '</a>'
						);
						?>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: URL to classic add page */
							esc_html__( 'Alternatively, you can %s to create a custom assistant without using a template.', 'wp-mcp-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ) . '">' . esc_html__( 'add a new assistant directly', 'wp-mcp-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="wp-mcp-ai-professionals-grid">
					<?php foreach ( $professions as $profession ) : ?>
						<?php
						$category        = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_category', true );
						$default_tools   = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_default_tools', true );
						$expertise       = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_expertise', true );
						$thumbnail_id    = get_post_thumbnail_id( $profession->ID );
						$thumbnail_url   = $thumbnail_id ? get_the_post_thumbnail_url( $profession->ID, 'medium' ) : '';
						$tools_count     = is_array( $default_tools ) ? count( $default_tools ) : 0;
						$expertise_count = is_array( $expertise ) ? count( $expertise ) : 0;
						?>
						<div class="wp-mcp-ai-professional-card" data-profession-id="<?php echo esc_attr( $profession->ID ); ?>">
							<?php if ( $thumbnail_url ) : ?>
								<div class="professional-thumbnail">
									<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $profession->post_title ); ?>">
								</div>
							<?php endif; ?>
							
							<div class="professional-header">
								<h3><?php echo esc_html( $profession->post_title ); ?></h3>
								<?php if ( $category ) : ?>
									<span class="professional-category category-<?php echo esc_attr( $category ); ?>">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', $category ) ) ); ?>
									</span>
								<?php endif; ?>
							</div>

							<div class="professional-content">
								<?php if ( $profession->post_excerpt ) : ?>
									<p class="professional-excerpt"><?php echo esc_html( $profession->post_excerpt ); ?></p>
								<?php elseif ( $profession->post_content ) : ?>
									<p class="professional-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $profession->post_content ), 20 ) ); ?></p>
								<?php endif; ?>

								<div class="professional-meta">
									<?php if ( $tools_count > 0 ) : ?>
										<span class="meta-item">
											<span class="dashicons dashicons-admin-tools"></span>
											<?php
											printf(
												/* translators: %d: number of tools */
												esc_html( _n( '%d tool', '%d tools', $tools_count, 'wp-mcp-ai' ) ),
												absint( $tools_count )
											);
											?>
										</span>
									<?php endif; ?>
									<?php if ( $expertise_count > 0 ) : ?>
										<span class="meta-item">
											<span class="dashicons dashicons-star-filled"></span>
											<?php
											printf(
												/* translators: %d: number of expertise areas */
												esc_html( _n( '%d expertise', '%d expertise areas', $expertise_count, 'wp-mcp-ai' ) ),
												absint( $expertise_count )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<div class="professional-actions">
								<button type="button" class="button button-primary button-large wp-mcp-ai-create-assistant" data-profession-id="<?php echo esc_attr( $profession->ID ); ?>">
									<?php esc_html_e( 'Create Assistant', 'wp-mcp-ai' ); ?>
								</button>
								<a href="<?php echo esc_url( get_edit_post_link( $profession->ID ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'View Template', 'wp-mcp-ai' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Create Assistant Modal -->
		<div id="wp-mcp-ai-create-modal" class="wp-mcp-ai-modal" style="display:none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Create Assistant from Template', 'wp-mcp-ai' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal-close">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<form id="wp-mcp-ai-create-form">
						<input type="hidden" name="profession_id" id="profession-id" value="">
						
						<p>
							<label for="assistant-title">
								<strong><?php esc_html_e( 'Assistant Title', 'wp-mcp-ai' ); ?> <span class="required">*</span></strong>
							</label>
							<input type="text" id="assistant-title" name="title" class="regular-text widefat" required placeholder="<?php esc_attr_e( 'e.g., "Jamaica Tax Assistant"', 'wp-mcp-ai' ); ?>">
							<span class="description"><?php esc_html_e( 'Give your assistant a descriptive name', 'wp-mcp-ai' ); ?></span>
						</p>

						<p>
							<label for="assistant-provider">
								<strong><?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?></strong>
							</label>
							<select id="assistant-provider" name="provider" class="regular-text widefat">
								<option value=""><?php esc_html_e( '-- Use Template Default --', 'wp-mcp-ai' ); ?></option>
								<option value="openai"><?php esc_html_e( 'OpenAI', 'wp-mcp-ai' ); ?></option>
								<option value="gemini"><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
								<option value="anthropic"><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
								<option value="ollama"><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
								<option value="lm_studio"><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
							</select>
							<span class="description"><?php esc_html_e( 'Override the template default if needed', 'wp-mcp-ai' ); ?></span>
						</p>
					</form>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-secondary wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Cancel', 'wp-mcp-ai' ); ?>
					</button>
					<button type="submit" form="wp-mcp-ai-create-form" class="button button-primary" id="wp-mcp-ai-submit-create">
						<?php esc_html_e( 'Create Assistant', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create assistant from professional template.
	 */
	public function handle_ajax_create() {
		check_ajax_referer( 'wp_mcp_ai_create_from_professional', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
		}

		// Get form data.
		$profession_id = isset( $_POST['profession_id'] ) ? absint( $_POST['profession_id'] ) : 0;
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$provider      = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		// Validate profession ID.
		if ( ! $profession_id || 'mcp_ai_profession' !== get_post_type( $profession_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid professional template.', 'wp-mcp-ai' ) ) );
		}

		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Title is required.', 'wp-mcp-ai' ) ) );
		}

		// Get profession data.
		$profession           = get_post( $profession_id );
		$profession_meta      = get_post_meta( $profession_id );
		$role_description     = isset( $profession_meta['_wp_mcp_ai_profession_role_description'][0] ) ? $profession_meta['_wp_mcp_ai_profession_role_description'][0] : '';
		$default_tools        = isset( $profession_meta['_wp_mcp_ai_profession_default_tools'][0] ) ? maybe_unserialize( $profession_meta['_wp_mcp_ai_profession_default_tools'][0] ) : array();
		$knowledge_base       = isset( $profession_meta['_wp_mcp_ai_profession_knowledge_base'][0] ) ? $profession_meta['_wp_mcp_ai_profession_knowledge_base'][0] : '';
		$memory_files         = isset( $profession_meta['_wp_mcp_ai_profession_memory_files'][0] ) ? maybe_unserialize( $profession_meta['_wp_mcp_ai_profession_memory_files'][0] ) : array();
		$default_provider_val = isset( $profession_meta['_wp_mcp_ai_profession_default_provider'][0] ) ? $profession_meta['_wp_mcp_ai_profession_default_provider'][0] : 'openai';
		$default_model_val    = isset( $profession_meta['_wp_mcp_ai_profession_default_model'][0] ) ? $profession_meta['_wp_mcp_ai_profession_default_model'][0] : 'gpt-4.1';
		$default_temp_val     = isset( $profession_meta['_wp_mcp_ai_profession_default_temperature'][0] ) ? floatval( $profession_meta['_wp_mcp_ai_profession_default_temperature'][0] ) : 0.7;

		// Override provider with user's choice if provided. Model uses template default.
		$final_provider    = ! empty( $provider ) ? $provider : $default_provider_val;
		$final_model       = $default_model_val;
		$final_temperature = $default_temp_val;

		// Set the profession as a primary role for programmatic prompt construction.
		// This enables the new Primary Roles feature.
		$primary_roles = array( $profession_id );

		// Build system prompt from profession data (backward compatibility).
		// Note: With primary roles set, get_assistant_configuration() will programmatically
		// build the prompt. We keep this for assistants that may not use primary roles.
		$system_prompt = $role_description;
		if ( ! empty( $knowledge_base ) ) {
			$system_prompt .= "\n\n" . __( 'Knowledge Base:', 'wp-mcp-ai' ) . "\n" . $knowledge_base;
		}

		// Create the assistant post.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => $title,
				'post_content' => $profession->post_content,
				'post_status'  => 'publish',
			)
		);

		if ( is_wp_error( $assistant_id ) ) {
			wp_send_json_error( array( 'message' => $assistant_id->get_error_message() ) );
		}

		// Set assistant meta.
		update_post_meta( $assistant_id, '_wp_mcp_ai_provider', $final_provider );
		update_post_meta( $assistant_id, '_wp_mcp_ai_model', $final_model );
		update_post_meta( $assistant_id, '_wp_mcp_ai_temperature', $final_temperature );
		update_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', $system_prompt );

		// Set primary role - enables programmatic prompt construction.
		update_post_meta( $assistant_id, '_wp_mcp_ai_primary_roles', $primary_roles );

		if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_tools', $default_tools );
		}

		if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
			update_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', $memory_files );
		}

		// Store reference to source profession.
		update_post_meta( $assistant_id, '_wp_mcp_ai_source_profession', $profession_id );

		wp_send_json_success(
			array(
				'assistant_id' => $assistant_id,
				'edit_url'     => get_edit_post_link( $assistant_id, 'raw' ),
				'message'      => __( 'Assistant created successfully!', 'wp-mcp-ai' ),
			)
		);
	}
}
