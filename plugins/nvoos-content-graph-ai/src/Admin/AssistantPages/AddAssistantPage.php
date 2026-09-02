<?php
/**
 * Add Assistant admin page for the Content Graph AI addon (Wave D-UI-4).
 *
 * Aligned port of the base plugin's Add Assistant page
 * (`includes/admin/class-wp-mcp-ai-add-assistant-page.php`): the
 * professional-template card grid, the create-assistant modal, and the
 * AJAX create-from-template flow. Meta keys written on creation are
 * byte-identical (`_wp_mcp_ai_provider|model|temperature|system_prompt|
 * tools|memory_files|primary_roles|source_profession`). Class names use
 * the ecosystem's `nvoos-cg-*` prefix and the page slug is
 * `nvoos-cg-add-assistant` so monolith installs can run both pages side
 * by side (documented deviation).
 *
 * Standalone installs have no profession templates yet (the profession
 * runtime ports with its owning wave), so the page renders the same
 * "No professional templates found" notice the base shows on empty
 * stores; the creation flow stays fully tested against seeded
 * profession posts.
 *
 * @package NvoosContentGraphAi\Admin\AssistantPages
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\AssistantPages;

use NvoosContentGraphAi\Admin\AssistantPostType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Add Assistant page in admin.
 *
 * @since 1.1.0
 */
class AddAssistantPage {

	/**
	 * Page slug (ecosystem-specific; never collides with the base).
	 */
	const PAGE_SLUG = 'nvoos-cg-add-assistant';

	/**
	 * AJAX action for the create-from-template flow (ecosystem-specific).
	 */
	const CREATE_ACTION = 'nvoos_cg_ai_create_from_professional';

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . AssistantPostType::POST_TYPE,
			__( 'Create Assistant', 'nvoos-content-graph-ai' ),
			__( 'Create Assistant', 'nvoos-content-graph-ai' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for this page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( $hook ): void {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'nvoos-cg-add-assistant',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/css/admin-add-assistant.css',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION
		);

		wp_enqueue_script(
			'nvoos-cg-add-assistant-js',
			NVOOS_CONTENT_GRAPH_AI_URL . 'assets/js/admin-add-assistant.js',
			array(),
			NVOOS_CONTENT_GRAPH_AI_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-cg-add-assistant-js',
			'nvoosCgAddAssistant',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::CREATE_ACTION,
				'nonce'    => wp_create_nonce( self::CREATE_ACTION ),
				'redirect' => admin_url( 'edit.php?post_type=' . AssistantPostType::POST_TYPE ),
				'strings'  => array(
					'creating' => __( 'Creating assistant...', 'nvoos-content-graph-ai' ),
					'success'  => __( 'Assistant created successfully!', 'nvoos-content-graph-ai' ),
					'error'    => __( 'Error creating assistant. Please try again.', 'nvoos-content-graph-ai' ),
				),
			)
		);
	}

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	public function render_page(): void {
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
			<h1><?php esc_html_e( 'Add New Assistant', 'nvoos-content-graph-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select a professional template to create a new AI assistant. Each professional has pre-configured instructions, tools, and knowledge base.', 'nvoos-content-graph-ai' ); ?>
			</p>

			<?php if ( empty( $professions ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: URL to create profession */
							esc_html__( 'No professional templates found. Please %s first to create assistants from templates.', 'nvoos-content-graph-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_profession' ) ) . '">' . esc_html__( 'create a professional', 'nvoos-content-graph-ai' ) . '</a>'
						);
						?>
					</p>
					<p>
						<?php
						printf(
							/* translators: %s: URL to classic add page */
							esc_html__( 'Alternatively, you can %s to create a custom assistant without using a template.', 'nvoos-content-graph-ai' ),
							'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . AssistantPostType::POST_TYPE ) ) . '">' . esc_html__( 'add a new assistant directly', 'nvoos-content-graph-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="nvoos-cg-professionals-grid">
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
						<div class="nvoos-cg-professional-card" data-profession-id="<?php echo esc_attr( (string) $profession->ID ); ?>">
							<?php if ( $thumbnail_url ) : ?>
								<div class="professional-thumbnail">
									<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $profession->post_title ); ?>">
								</div>
							<?php endif; ?>

							<div class="professional-header">
								<h3><?php echo esc_html( $profession->post_title ); ?></h3>
								<?php if ( $category ) : ?>
									<span class="professional-category category-<?php echo esc_attr( sanitize_key( $category ) ); ?>">
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
												esc_html( _n( '%d tool', '%d tools', $tools_count, 'nvoos-content-graph-ai' ) ),
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
												esc_html( _n( '%d expertise', '%d expertise areas', $expertise_count, 'nvoos-content-graph-ai' ) ),
												absint( $expertise_count )
											);
											?>
										</span>
									<?php endif; ?>
								</div>
							</div>

							<div class="professional-actions">
								<button type="button" class="button button-primary button-large nvoos-cg-create-assistant" data-profession-id="<?php echo esc_attr( (string) $profession->ID ); ?>">
									<?php esc_html_e( 'Create Assistant', 'nvoos-content-graph-ai' ); ?>
								</button>
								<a href="<?php echo esc_url( get_edit_post_link( $profession->ID ) ); ?>" class="button button-secondary">
									<?php esc_html_e( 'View Template', 'nvoos-content-graph-ai' ); ?>
								</a>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<!-- Create Assistant Modal -->
		<div id="nvoos-cg-create-modal" class="nvoos-cg-modal" style="display:none;">
			<div class="nvoos-cg-modal-overlay"></div>
			<div class="nvoos-cg-modal-content">
				<div class="nvoos-cg-modal-header">
					<h2><?php esc_html_e( 'Create Assistant from Template', 'nvoos-content-graph-ai' ); ?></h2>
					<button type="button" class="nvoos-cg-modal-close">&times;</button>
				</div>
				<div class="nvoos-cg-modal-body">
					<form id="nvoos-cg-create-form">
						<input type="hidden" name="profession_id" id="profession-id" value="">

						<p>
							<label for="assistant-title">
								<strong><?php esc_html_e( 'Assistant Title', 'nvoos-content-graph-ai' ); ?> <span class="required">*</span></strong>
							</label>
							<input type="text" id="assistant-title" name="title" class="regular-text widefat" required placeholder="<?php esc_attr_e( 'e.g., "Jamaica Tax Assistant"', 'nvoos-content-graph-ai' ); ?>">
							<span class="description"><?php esc_html_e( 'Give your assistant a descriptive name', 'nvoos-content-graph-ai' ); ?></span>
						</p>

						<p>
							<label for="assistant-provider">
								<strong><?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai' ); ?></strong>
							</label>
							<select id="assistant-provider" name="provider" class="regular-text widefat">
								<option value=""><?php esc_html_e( '-- Use Template Default --', 'nvoos-content-graph-ai' ); ?></option>
								<?php
								$available_providers = ( new BuildAssistantPage() )->get_available_providers();
								foreach ( $available_providers as $provider_slug => $provider_label ) {
									?>
									<option value="<?php echo esc_attr( $provider_slug ); ?>"><?php echo esc_html( $provider_label ); ?></option>
									<?php
								}
								?>
							</select>
							<span class="description"><?php esc_html_e( 'Override the template default if needed', 'nvoos-content-graph-ai' ); ?></span>
						</p>
					</form>
				</div>
				<div class="nvoos-cg-modal-footer">
					<button type="button" class="button button-secondary nvoos-cg-modal-close">
						<?php esc_html_e( 'Cancel', 'nvoos-content-graph-ai' ); ?>
					</button>
					<button type="submit" form="nvoos-cg-create-form" class="button button-primary" id="nvoos-cg-submit-create">
						<?php esc_html_e( 'Create Assistant', 'nvoos-content-graph-ai' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create assistant from professional template.
	 *
	 * @return void
	 */
	public function handle_ajax_create(): void {
		if ( ! check_ajax_referer( self::CREATE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'nvoos-content-graph-ai' ) ), 403 );
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai' ) ), 403 );
		}

		$profession_id = isset( $_POST['profession_id'] ) ? absint( wp_unslash( $_POST['profession_id'] ) ) : 0;
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$provider      = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		$result = self::create_from_professional( $profession_id, $title, $provider );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create an assistant from a professional template.
	 *
	 * Mirrors the base handler byte-for-byte on the meta keys written.
	 *
	 * @param int    $profession_id Professional template post ID.
	 * @param string $title         Assistant title.
	 * @param string $provider      Optional provider override ('' = template default).
	 * @return array|WP_Error Assistant payload or error.
	 */
	public static function create_from_professional( $profession_id, $title, $provider ) {
		$profession_id = absint( $profession_id );
		$title         = trim( sanitize_text_field( (string) $title ) );
		$provider      = sanitize_key( (string) $provider );

		if ( ! $profession_id || 'mcp_ai_profession' !== get_post_type( $profession_id ) ) {
			return new \WP_Error( 'wp_mcp_ai_invalid_profession', __( 'Invalid professional template.', 'nvoos-content-graph-ai' ) );
		}

		if ( '' === $title ) {
			return new \WP_Error( 'wp_mcp_ai_missing_title', __( 'Title is required.', 'nvoos-content-graph-ai' ) );
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
		$final_provider    = '' !== $provider ? $provider : $default_provider_val;
		$final_model       = $default_model_val;
		$final_temperature = $default_temp_val;

		// Set the profession as a primary role for programmatic prompt construction.
		$primary_roles = array( $profession_id );

		// Build system prompt from profession data (backward compatibility).
		$system_prompt = $role_description;
		if ( '' !== $knowledge_base ) {
			$system_prompt .= "\n\n" . __( 'Knowledge Base:', 'nvoos-content-graph-ai' ) . "\n" . $knowledge_base;
		}

		// Create the assistant post.
		$assistant_id = wp_insert_post(
			array(
				'post_type'    => AssistantPostType::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $profession->post_content,
				'post_status'  => 'publish',
			)
		);

		if ( is_wp_error( $assistant_id ) ) {
			return $assistant_id;
		}

		// Set assistant meta.
		update_post_meta( $assistant_id, AssistantPostType::META_PROVIDER, $final_provider );
		update_post_meta( $assistant_id, AssistantPostType::META_MODEL, $final_model );
		update_post_meta( $assistant_id, AssistantPostType::META_TEMPERATURE, $final_temperature );
		update_post_meta( $assistant_id, AssistantPostType::META_SYSTEM_PROMPT, $system_prompt );

		// Set primary role - enables programmatic prompt construction.
		update_post_meta( $assistant_id, AssistantPostType::META_PRIMARY_ROLES, $primary_roles );

		if ( is_array( $default_tools ) && ! empty( $default_tools ) ) {
			update_post_meta( $assistant_id, AssistantPostType::META_TOOLS, $default_tools );
		}

		if ( is_array( $memory_files ) && ! empty( $memory_files ) ) {
			update_post_meta( $assistant_id, AssistantPostType::META_MEMORY_FILES, $memory_files );
		}

		// Store reference to source profession.
		update_post_meta( $assistant_id, AssistantPostType::META_SOURCE_PROFESSION, $profession_id );

		return array(
			'assistant_id' => $assistant_id,
			'edit_url'     => get_edit_post_link( $assistant_id, 'raw' ),
			'message'      => __( 'Assistant created successfully!', 'nvoos-content-graph-ai' ),
		);
	}
}
