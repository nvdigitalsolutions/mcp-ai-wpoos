<?php
/**
 * Add Agent Page — admin page for creating agents from professional templates.
 *
 * Extracted from `includes/admin/class-wp-mcp-ai-add-assistant-page.php`.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents\Admin;

use NvoosContentGraphAiPlatform\Agents\Agents;

/**
 * Handles the Add Agent page in admin.
 */
final class AddAgentPage {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerPage' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueScripts' ) );
		add_action( 'wp_ajax_nvoos_content_graph_platform_create_agent', array( $this, 'handleAjaxCreate' ) );
	}

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function registerPage(): void {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . Agents::POST_TYPE,
			__( 'Add Agent', 'nvoos-content-graph-ai-platform' ),
			__( 'Add Agent', 'nvoos-content-graph-ai-platform' ),
			'edit_posts',
			'nvoos-content-graph-ai-platform-add-agent',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue scripts and styles for this page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-content-graph-ai-platform-agents-add',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/css/agents-add.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION
		);

		wp_enqueue_script(
			'nvoos-content-graph-ai-platform-agents-add',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/js/agents-add.js',
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-content-graph-ai-platform-agents-add',
			'nvoosContentGraphPlatformAgentsAdd',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'nvoos_content_graph_platform_create_agent' ),
				'strings' => array(
					'creating' => __( 'Creating agent...', 'nvoos-content-graph-ai-platform' ),
					'success'  => __( 'Agent created successfully!', 'nvoos-content-graph-ai-platform' ),
					'error'    => __( 'Error creating agent. Please try again.', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);
	}

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	public function renderPage(): void {
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
			<h1><?php esc_html_e( 'Add New Agent', 'nvoos-content-graph-ai-platform' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select a professional template to create a new AI agent. Each professional has pre-configured instructions, tools, and knowledge base.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create profession */
							esc_html__( 'No professional templates found. Please %s first to create agents from templates.', 'nvoos-content-graph-ai-platform' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a professional', 'nvoos-content-graph-ai-platform' ) . '</a>'
						);
						?>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: URL to classic add page */
							esc_html__( 'Alternatively, you can %s to create a custom agent without using a template.', 'nvoos-content-graph-ai-platform' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ) . '">' . esc_html__( 'add a new agent directly', 'nvoos-content-graph-ai-platform' ) . '</a>'
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
						<div class="wp-mcp-ai-professional-card" data-profession-id="<?php echo esc_attr( (string) $profession->ID ); ?>">
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
												esc_html( _n( '%d tool', '%d tools', $tools_count, 'nvoos-content-graph-ai-platform' ) ),
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
												esc_html( _n( '%d expertise', '%d expertise areas', $expertise_count, 'nvoos-content-graph-ai-platform' ) ),
												absint( $expertise_count )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<div class="professional-actions">
								<button type="button" class="button button-primary button-large wp-mcp-ai-create-assistant" data-profession-id="<?php echo esc_attr( (string) $profession->ID ); ?>">
									<?php esc_html_e( 'Create Agent', 'nvoos-content-graph-ai-platform' ); ?>
								</button>
								<a href="<?php echo esc_url( get_edit_post_link( $profession->ID ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'View Template', 'nvoos-content-graph-ai-platform' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Create Agent Modal -->
		<div id="wp-mcp-ai-create-modal" class="wp-mcp-ai-modal" style="display:none;">
			<div class="wp-mcp-ai-modal-overlay"></div>
			<div class="wp-mcp-ai-modal-content">
				<div class="wp-mcp-ai-modal-header">
					<h2><?php esc_html_e( 'Create Agent from Template', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<button type="button" class="wp-mcp-ai-modal-close">&times;</button>
				</div>
				<div class="wp-mcp-ai-modal-body">
					<form id="wp-mcp-ai-create-form">
						<input type="hidden" name="profession_id" id="profession-id" value="">

						<p>
							<label for="assistant-title">
								<strong><?php esc_html_e( 'Agent Title', 'nvoos-content-graph-ai-platform' ); ?> <span class="required">*</span></strong>
							</label>
							<input type="text" id="assistant-title" name="title" class="regular-text widefat" required placeholder="<?php esc_attr_e( 'e.g., "Jamaica Tax Agent"', 'nvoos-content-graph-ai-platform' ); ?>">
							<span class="description"><?php esc_html_e( 'Give your agent a descriptive name', 'nvoos-content-graph-ai-platform' ); ?></span>
						</p>

						<p>
							<label for="assistant-provider">
								<strong><?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai-platform' ); ?></strong>
							</label>
							<select id="assistant-provider" name="provider" class="regular-text widefat">
								<option value=""><?php esc_html_e( '-- Use Template Default --', 'nvoos-content-graph-ai-platform' ); ?></option>
								<?php
								if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
									$available_providers = \WP_MCP_AI_Admin_Settings::get_available_providers();
									foreach ( $available_providers as $provider_slug => $provider_label ) {
										?>
										<option value="<?php echo esc_attr( $provider_slug ); ?>"><?php echo esc_html( $provider_label ); ?></option>
										<?php
									}
								}
								?>
							</select>
							<span class="description"><?php esc_html_e( 'Override the template default if needed', 'nvoos-content-graph-ai-platform' ); ?></span>
						</p>
					</form>
				</div>
				<div class="wp-mcp-ai-modal-footer">
					<button type="button" class="button button-secondary wp-mcp-ai-modal-close">
						<?php esc_html_e( 'Cancel', 'nvoos-content-graph-ai-platform' ); ?>
					</button>
					<button type="submit" form="wp-mcp-ai-create-form" class="button button-primary" id="wp-mcp-ai-submit-create">
						<?php esc_html_e( 'Create Agent', 'nvoos-content-graph-ai-platform' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create agent from professional template.
	 *
	 * @return void
	 */
	public function handleAjaxCreate(): void {
		check_ajax_referer( 'nvoos_content_graph_platform_create_agent', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		// Get form data.
		$profession_id = isset( $_POST['profession_id'] ) ? absint( wp_unslash( $_POST['profession_id'] ) ) : 0;
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$provider      = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		// Validate profession ID.
		if ( ! $profession_id || 'mcp_ai_profession' !== get_post_type( $profession_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid professional template.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		if ( empty( $title ) ) {
			wp_send_json_error( array( 'message' => __( 'Title is required.', 'nvoos-content-graph-ai-platform' ) ) );
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

		$final_provider    = ! empty( $provider ) ? $provider : $default_provider_val;
		$final_model       = $default_model_val;
		$final_temperature = $default_temp_val;

		$primary_roles = array( $profession_id );

		$system_prompt = $role_description;
		if ( ! empty( $knowledge_base ) ) {
			$system_prompt .= "\n\n" . __( 'Knowledge Base:', 'nvoos-content-graph-ai-platform' ) . "\n" . $knowledge_base;
		}

		// Create the agent post.
		$agent_id = wp_insert_post(
			array(
				'post_type'    => Agents::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $profession->post_content,
				'post_status'  => 'publish',
			)
		);

		if ( is_wp_error( $agent_id ) ) {
			wp_send_json_error( array( 'message' => $agent_id->get_error_message() ) );
		}

		// Set agent meta.
		update_post_meta( $agent_id, '_wp_mcp_ai_provider', $final_provider );
		update_post_meta( $agent_id, '_wp_mcp_ai_model', $final_model );
		update_post_meta( $agent_id, '_wp_mcp_ai_temperature', $final_temperature );
		update_post_meta( $agent_id, '_wp_mcp_ai_system_prompt', $system_prompt );
		update_post_meta( $agent_id, '_wp_mcp_ai_primary_roles', $primary_roles );

		if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
			update_post_meta( $agent_id, '_wp_mcp_ai_tools', $default_tools );
		}

		if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
			update_post_meta( $agent_id, '_wp_mcp_ai_memory_files', $memory_files );
		}

		update_post_meta( $agent_id, '_wp_mcp_ai_source_profession', $profession_id );

		wp_send_json_success(
			array(
				'agent_id' => $agent_id,
				'edit_url' => get_edit_post_link( $agent_id, 'raw' ),
				'message'  => __( 'Agent created successfully!', 'nvoos-content-graph-ai-platform' ),
			)
		);
	}
}
