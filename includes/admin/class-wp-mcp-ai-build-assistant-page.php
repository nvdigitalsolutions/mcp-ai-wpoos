<?php
/**
 * Build Assistant Page.
 *
 * Admin page for building assistants with a tabbed interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Build Assistant page in admin.
 */
class WP_MCP_AI_Build_Assistant_Page {
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
	}

	/**
	 * Register the admin page.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Build Assistant', 'wp-mcp-ai' ),
			__( 'Build Assistant', 'wp-mcp-ai' ),
			'edit_posts',
			'wp-mcp-ai-build-assistant',
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

		// Check if we're on the prompt tab.
		$active_tab = $this->get_active_tab();

		// Enqueue chat assets for the prompt tab.
		if ( 'prompt' === $active_tab ) {
			$this->enqueue_chat_assets();
		}

		wp_enqueue_style(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-build-assistant.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-build-assistant.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Enqueue scripts for Manual and Prompt tabs (from the former modal).
		wp_localize_script(
			'wp-mcp-ai-build-assistant',
			'wpMcpAiCreateAssistant',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'wp_mcp_ai_create_assistant' ),
				'strings'     => array(
					'creating'          => __( 'Creating assistant...', 'wp-mcp-ai' ),
					'createAssistant'   => __( 'Create Assistant', 'wp-mcp-ai' ),
					'success'           => __( 'Assistant created successfully!', 'wp-mcp-ai' ),
					'error'             => __( 'Error creating assistant. Please try again.', 'wp-mcp-ai' ),
					'required'          => __( 'This field is required.', 'wp-mcp-ai' ),
					'maxProfessions'    => __( 'You can select up to 3 professions.', 'wp-mcp-ai' ),
					'maxRegions'        => __( 'You can select up to 2 regions.', 'wp-mcp-ai' ),
					'emptyConversation' => __( 'Please describe what kind of assistant you want to create before clicking Build.', 'wp-mcp-ai' ),
				),
				'professions' => $this->get_professions(),
				'regions'     => $this->get_regions(),
			)
		);
	}

	/**
	 * Enqueue chat interface assets for the prompt tab.
	 *
	 * Uses the shortcode class to ensure all dependencies are properly registered and enqueued.
	 */
	private function enqueue_chat_assets() {
		// Ensure the shortcode assets are registered.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) && ! wp_script_is( 'wp-mcp-ai-chat', 'registered' ) ) {
			$shortcode = new WP_MCP_AI_Shortcode();
			$shortcode->register_assets();
		}

		// Enqueue the chat script and style.
		wp_enqueue_script( 'wp-mcp-ai-chat' );
		wp_enqueue_style( 'wp-mcp-ai-chat' );
	}

	/**
	 * Get the currently active tab.
	 *
	 * @return string Active tab ID.
	 */
	private function get_active_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'manual';

		$valid_tabs = array( 'manual', 'prompt', 'configuration', 'advanced' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'manual';
		}

		return $tab;
	}

	/**
	 * Get tab definitions.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'manual'        => array(
				'title' => __( 'Manual', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-edit',
			),
			'prompt'        => array(
				'title' => __( 'Prompt', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-format-chat',
			),
			'configuration' => array(
				'title' => __( 'Configuration', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'advanced'      => array(
				'title' => __( 'Advanced', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-admin-generic',
			),
		);
	}

	/**
	 * Render the page content.
	 */
	public function render_page() {
		$active_tab = $this->get_active_tab();
		$tabs       = $this->get_tabs();

		?>
		<div class="wrap wp-mcp-ai-build-assistant-page">
			<h1><?php esc_html_e( 'Build Assistant', 'wp-mcp-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure and build custom AI assistants with advanced settings and options.', 'wp-mcp-ai' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Build Assistant tabs', 'wp-mcp-ai' ); ?>">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'post_type' => 'mcp_ai_assistant',
							'page'      => 'wp-mcp-ai-build-assistant',
							'tab'       => $tab_id,
						),
						admin_url( 'edit.php' )
					);
					$active  = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'manual' === $active_tab ) {
					$this->render_manual_tab();
				} elseif ( 'prompt' === $active_tab ) {
					$this->render_prompt_tab();
				} elseif ( 'configuration' === $active_tab ) {
					$this->render_configuration_tab();
				} elseif ( 'advanced' === $active_tab ) {
					$this->render_advanced_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Manual tab content.
	 */
	private function render_manual_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-manual-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Create Assistant Manually', 'wp-mcp-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Fill in the form below to create a new AI assistant with custom settings.', 'wp-mcp-ai' ); ?></p>

				<form id="wp-mcp-ai-create-assistant-form" class="wp-mcp-ai-assistant-form">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="assistant-title">
										<?php esc_html_e( 'Assistant Title', 'wp-mcp-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-title" name="title" class="regular-text" required>
									<p class="description">
										<?php esc_html_e( 'E.g., "Jamaica Tax Assistant", "Sri Lanka Customs Broker - Perfumes"', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-professions">
										<?php esc_html_e( 'Professions', 'wp-mcp-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-professions" name="professions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_professions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 3 professions. Hold Ctrl/Cmd to select multiple.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-regions">
										<?php esc_html_e( 'Regions', 'wp-mcp-ai' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-regions" name="regions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_regions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 2 regions. Hold Ctrl/Cmd to select multiple.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-industry">
										<?php esc_html_e( 'Industry Focus', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-industry" name="industry_focus" class="regular-text">
									<p class="description">
										<?php esc_html_e( 'Optional: E.g., "perfumes", "technology", "restaurants"', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-attachments">
										<?php esc_html_e( 'Knowledge Files', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="file" id="assistant-attachments" name="attachments[]" multiple accept=".txt,.md,.pdf,.doc,.docx">
									<p class="description">
										<?php esc_html_e( 'Optional: Upload files to include in the assistant\'s knowledge base (.txt, .md, .pdf, .doc, .docx)', 'wp-mcp-ai' ); ?>
									</p>
									<ul id="assistant-attachments-list" class="wp-mcp-ai-attachments-list"></ul>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-provider">
										<?php esc_html_e( 'AI Provider', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<select id="assistant-provider" name="provider" class="regular-text">
										<option value="openai" selected><?php esc_html_e( 'OpenAI (Default)', 'wp-mcp-ai' ); ?></option>
										<option value="gemini"><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></option>
										<option value="anthropic"><?php esc_html_e( 'Anthropic Claude', 'wp-mcp-ai' ); ?></option>
										<option value="ollama"><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></option>
										<option value="lm_studio"><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-model">
										<?php esc_html_e( 'Model', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-model" name="model" class="regular-text" value="gpt-4">
									<p class="description">
										<?php esc_html_e( 'E.g., "gpt-4", "gpt-4-turbo", "gemini-pro"', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-temperature">
										<?php esc_html_e( 'Temperature', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="assistant-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" value="0.7">
									<p class="description">
										<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-async">
										<input type="checkbox" id="assistant-async" name="async" value="1">
										<?php esc_html_e( 'Create in Background', 'wp-mcp-ai' ); ?>
									</label>
								</th>
								<td>
									<p class="description">
										<?php esc_html_e( 'For complex assistants, create asynchronously via cron. You will be notified when complete.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary" id="wp-mcp-ai-submit-create">
							<?php esc_html_e( 'Create Assistant', 'wp-mcp-ai' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Prompt tab content.
	 */
	private function render_prompt_tab() {
		// Get all published assistants for the dropdown.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Get all available tools.
		$tools        = array();
		$group_map    = array();
		$group_labels = array();

		if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$tools    = $registry->get_tools();

			if ( method_exists( $registry, 'get_tool_group_map' ) ) {
				$group_map = $registry->get_tool_group_map();
			}
			if ( method_exists( $registry, 'get_tool_group_labels' ) ) {
				$group_labels = $registry->get_tool_group_labels();
			}
		}

		if ( ! is_array( $group_map ) ) {
			$group_map = array();
		}
		if ( ! is_array( $group_labels ) ) {
			$group_labels = array();
		}
		if ( ! isset( $group_labels['other'] ) ) {
			$group_labels['other'] = __( 'Other tools', 'wp-mcp-ai' );
		}

		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-prompt-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Build with AI Prompt', 'wp-mcp-ai' ); ?></h2>
				
				<!-- Step-by-step instructions -->
				<div class="wp-mcp-ai-build-steps">
					<div class="wp-mcp-ai-build-step wp-mcp-ai-build-step--active" data-step="1">
						<span class="wp-mcp-ai-build-step__number">1</span>
						<span class="wp-mcp-ai-build-step__label"><?php esc_html_e( 'Select Assistant', 'wp-mcp-ai' ); ?></span>
					</div>
					<div class="wp-mcp-ai-build-step" data-step="2">
						<span class="wp-mcp-ai-build-step__number">2</span>
						<span class="wp-mcp-ai-build-step__label"><?php esc_html_e( 'Configure Tools', 'wp-mcp-ai' ); ?></span>
					</div>
					<div class="wp-mcp-ai-build-step" data-step="3">
						<span class="wp-mcp-ai-build-step__number">3</span>
						<span class="wp-mcp-ai-build-step__label"><?php esc_html_e( 'Describe & Build', 'wp-mcp-ai' ); ?></span>
					</div>
				</div>

				<?php if ( empty( $assistants ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new assistant */
								esc_html__( 'No assistants found. %s to get started.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ) . '">' . esc_html__( 'Create your first assistant', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<!-- Step 1: Select Assistant -->
					<div class="wp-mcp-ai-build-section" id="wp-mcp-ai-build-step-1">
						<h3><?php esc_html_e( 'Step 1: Select an Assistant', 'wp-mcp-ai' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Choose an existing assistant to use as the foundation for your conversation. This assistant will help you create a new, customized assistant.', 'wp-mcp-ai' ); ?></p>
						
						<div class="wp-mcp-ai-assistant-selector">
							<select id="wp-mcp-ai-prompt-assistant-select" class="regular-text">
								<option value=""><?php esc_html_e( '— Select an assistant —', 'wp-mcp-ai' ); ?></option>
								<?php foreach ( $assistants as $assistant ) : ?>
									<?php
									$tool_shortcuts = array();
									if ( class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
										$tool_shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant->ID );
									}

									// Get selected tools for this assistant.
									$selected_tools = array();
									if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
										$selected_tools = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
										if ( ! is_array( $selected_tools ) ) {
											$selected_tools = array();
										}
									}

									// Get assistant configuration.
									$config   = array();
									$provider = '';
									$model    = '';
									if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
										$config   = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant->ID );
										$provider = ! empty( $config['provider'] ) ? $config['provider'] : '';
										$model    = ! empty( $config['model'] ) ? $config['model'] : '';
									}
									?>
									<option 
										value="<?php echo esc_attr( $assistant->ID ); ?>"
										data-assistant-title="<?php echo esc_attr( $assistant->post_title ); ?>"
										data-assistant-description="<?php echo esc_attr( wp_strip_all_tags( $assistant->post_content ) ); ?>"
										data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $tool_shortcuts ) ); ?>"
										data-selected-tools="<?php echo esc_attr( wp_json_encode( $selected_tools ) ); ?>"
										data-provider="<?php echo esc_attr( $provider ); ?>"
										data-model="<?php echo esc_attr( $model ); ?>"
									>
										<?php echo esc_html( $assistant->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<!-- Assistant Preview Card -->
						<div class="wp-mcp-ai-assistant-preview" id="wp-mcp-ai-assistant-preview" style="display: none;">
							<div class="wp-mcp-ai-assistant-preview__header">
								<span class="dashicons dashicons-admin-users"></span>
								<h4 id="wp-mcp-ai-assistant-preview-title"></h4>
							</div>
							<div class="wp-mcp-ai-assistant-preview__meta">
								<span class="wp-mcp-ai-assistant-preview__provider" id="wp-mcp-ai-assistant-preview-provider"></span>
								<span class="wp-mcp-ai-assistant-preview__model" id="wp-mcp-ai-assistant-preview-model"></span>
								<span class="wp-mcp-ai-assistant-preview__tools" id="wp-mcp-ai-assistant-preview-tools"></span>
							</div>
							<p class="wp-mcp-ai-assistant-preview__description" id="wp-mcp-ai-assistant-preview-description"></p>
						</div>
					</div>

					<!-- Step 2: Configure Tools -->
					<div class="wp-mcp-ai-build-section" id="wp-mcp-ai-build-step-2" style="display: none;">
						<h3><?php esc_html_e( 'Step 2: Configure Tools', 'wp-mcp-ai' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Select or deselect tools to customize what capabilities the assistant can use during this conversation. Changes apply immediately.', 'wp-mcp-ai' ); ?></p>
						
						<!-- Tool Actions -->
						<div class="wp-mcp-ai-prompt-tools-actions">
							<button type="button" class="button" id="wp-mcp-ai-tools-select-all">
								<?php esc_html_e( 'Select All', 'wp-mcp-ai' ); ?>
							</button>
							<button type="button" class="button" id="wp-mcp-ai-tools-deselect-all">
								<?php esc_html_e( 'Deselect All', 'wp-mcp-ai' ); ?>
							</button>
							<span class="wp-mcp-ai-tools-summary" id="wp-mcp-ai-tools-summary">
								<strong id="wp-mcp-ai-tools-selected-total">0</strong> <?php esc_html_e( 'tools selected', 'wp-mcp-ai' ); ?>
							</span>
						</div>
						
						<?php $this->render_prompt_tools_grid( $tools, $group_map, $group_labels ); ?>
					</div>

					<!-- Step 3: Describe & Build -->
					<div class="wp-mcp-ai-build-section" id="wp-mcp-ai-build-step-3" style="display: none;">
						<h3><?php esc_html_e( 'Step 3: Describe & Build', 'wp-mcp-ai' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Describe the assistant you want to create. Be specific about its purpose, expertise, target audience, and any specific capabilities. When ready, click the "Build" button.', 'wp-mcp-ai' ); ?></p>
						
						<!-- Configuration Summary -->
						<div class="wp-mcp-ai-config-summary" id="wp-mcp-ai-config-summary">
							<h4><?php esc_html_e( 'Configuration Summary', 'wp-mcp-ai' ); ?></h4>
							<div class="wp-mcp-ai-config-summary__grid">
								<div class="wp-mcp-ai-config-summary__item">
									<span class="wp-mcp-ai-config-summary__label"><?php esc_html_e( 'Base Assistant:', 'wp-mcp-ai' ); ?></span>
									<span class="wp-mcp-ai-config-summary__value" id="wp-mcp-ai-summary-assistant">—</span>
								</div>
								<div class="wp-mcp-ai-config-summary__item">
									<span class="wp-mcp-ai-config-summary__label"><?php esc_html_e( 'Provider:', 'wp-mcp-ai' ); ?></span>
									<span class="wp-mcp-ai-config-summary__value" id="wp-mcp-ai-summary-provider">—</span>
								</div>
								<div class="wp-mcp-ai-config-summary__item">
									<span class="wp-mcp-ai-config-summary__label"><?php esc_html_e( 'Model:', 'wp-mcp-ai' ); ?></span>
									<span class="wp-mcp-ai-config-summary__value" id="wp-mcp-ai-summary-model">—</span>
								</div>
								<div class="wp-mcp-ai-config-summary__item">
									<span class="wp-mcp-ai-config-summary__label"><?php esc_html_e( 'Tools:', 'wp-mcp-ai' ); ?></span>
									<span class="wp-mcp-ai-config-summary__value" id="wp-mcp-ai-summary-tools">—</span>
								</div>
							</div>
						</div>
					</div>

					<!-- Navigation Buttons -->
					<div class="wp-mcp-ai-build-navigation" id="wp-mcp-ai-build-navigation">
						<button type="button" class="button" id="wp-mcp-ai-build-prev" style="display: none;">
							<?php esc_html_e( '← Previous', 'wp-mcp-ai' ); ?>
						</button>
						<button type="button" class="button button-primary" id="wp-mcp-ai-build-next" disabled>
							<?php esc_html_e( 'Next →', 'wp-mcp-ai' ); ?>
						</button>
						<button type="button" class="button button-primary" id="wp-mcp-ai-start-chat-btn" style="display: none;">
							<?php esc_html_e( 'Start Building', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<!-- Chat Container -->
					<div class="wp-mcp-ai-chat-container" id="wp-mcp-ai-prompt-chat-container">
						<!-- Chat interface will be initialized here when assistant is selected -->
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
								<?php
								$tool_shortcuts = array();
								if ( class_exists( 'WP_MCP_AI_Shortcode' ) && method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
									$tool_shortcuts = WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant->ID );
								}

								// Get selected tools for this assistant.
								$selected_tools = array();
								if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
									$selected_tools = get_post_meta( $assistant->ID, WP_MCP_AI_Assistant_CPT::META_TOOLS, true );
									if ( ! is_array( $selected_tools ) ) {
										$selected_tools = array();
									}
								}
								?>
								<option 
									value="<?php echo esc_attr( $assistant->ID ); ?>"
									data-assistant-title="<?php echo esc_attr( $assistant->post_title ); ?>"
									data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $tool_shortcuts ) ); ?>"
									data-selected-tools="<?php echo esc_attr( wp_json_encode( $selected_tools ) ); ?>"
								>
									<?php echo esc_html( $assistant->post_title ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<button type="button" id="wp-mcp-ai-start-chat-btn" class="button button-primary" disabled>
							<?php esc_html_e( 'Start Chat', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<!-- Tools Grid Section -->
					<div class="wp-mcp-ai-prompt-tools-section" id="wp-mcp-ai-prompt-tools-section" style="display: none;">
						<h3><?php esc_html_e( 'Available Tools', 'wp-mcp-ai' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Select or deselect tools to customize what capabilities the assistant can use during this conversation. Changes apply immediately.', 'wp-mcp-ai' ); ?></p>
						
						<?php $this->render_prompt_tools_grid( $tools, $group_map, $group_labels ); ?>
					</div>

					<div class="wp-mcp-ai-chat-container" id="wp-mcp-ai-prompt-chat-container">
						<!-- Chat interface will be initialized here when assistant is selected -->
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the tools grid for the prompt tab.
	 *
	 * @param array $tools        Array of tool objects.
	 * @param array $group_map    Map of tool slugs to group IDs.
	 * @param array $group_labels Map of group IDs to labels.
	 */
	private function render_prompt_tools_grid( $tools, $group_map, $group_labels ) {
		if ( empty( $tools ) ) {
			echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		// Group tools by category.
		$grouped_tools = array();

		foreach ( $tools as $tool ) {
			if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
				continue;
			}

			$slug = $tool->get_slug();

			if ( '' === $slug ) {
				continue;
			}

			$group_id = isset( $group_map[ $slug ] ) ? (string) $group_map[ $slug ] : 'other';

			if ( '' === $group_id ) {
				$group_id = 'other';
			}

			if ( ! isset( $grouped_tools[ $group_id ] ) ) {
				$grouped_tools[ $group_id ] = array();
			}

			$grouped_tools[ $group_id ][] = $tool;
		}

		if ( empty( $grouped_tools ) ) {
			echo '<p>' . esc_html__( 'No tools are currently registered.', 'wp-mcp-ai' ) . '</p>';
			return;
		}

		// Order groups by labels.
		$ordered_group_ids = array();

		foreach ( $group_labels as $group_id => $label ) {
			if ( isset( $grouped_tools[ $group_id ] ) ) {
				$ordered_group_ids[] = (string) $group_id;
			}
		}

		foreach ( $grouped_tools as $group_id => $unused ) {
			if ( ! in_array( $group_id, $ordered_group_ids, true ) ) {
				$ordered_group_ids[] = (string) $group_id;
			}
		}

		?>
		<div class="wp-mcp-ai-prompt-tools" id="wp-mcp-ai-prompt-tools-grid">
			<?php foreach ( $ordered_group_ids as $group_id ) : ?>
				<?php
				if ( ! isset( $grouped_tools[ $group_id ] ) ) {
					continue;
				}

				$group_tools = $grouped_tools[ $group_id ];
				$group_label = isset( $group_labels[ $group_id ] ) ? $group_labels[ $group_id ] : ucfirst( $group_id );
				$tool_count  = count( $group_tools );
				?>
				<details class="wp-mcp-ai-prompt-tools__group">
					<summary>
						<span class="wp-mcp-ai-prompt-tools__summary-title"><?php echo esc_html( $group_label ); ?></span>
						<span class="wp-mcp-ai-prompt-tools__summary-count">
							<span class="wp-mcp-ai-prompt-tools__selected-count">0</span> / <?php echo esc_html( $tool_count ); ?>
						</span>
					</summary>
					<ul class="wp-mcp-ai-prompt-tools__list">
						<?php foreach ( $group_tools as $tool ) : ?>
							<?php
							$slug        = $tool->get_slug();
							$definition  = $tool->get_definition();
							$name        = isset( $definition['name'] ) ? $definition['name'] : $slug;
							$description = isset( $definition['description'] ) ? $definition['description'] : '';
							?>
							<li class="wp-mcp-ai-prompt-tools__item" data-tool-slug="<?php echo esc_attr( $slug ); ?>">
								<div class="wp-mcp-ai-prompt-tools__header">
									<input 
										type="checkbox" 
										class="wp-mcp-ai-prompt-tools__checkbox" 
										id="wp-mcp-ai-prompt-tool-<?php echo esc_attr( $slug ); ?>" 
										value="<?php echo esc_attr( $slug ); ?>"
									/>
									<label for="wp-mcp-ai-prompt-tool-<?php echo esc_attr( $slug ); ?>">
										<span class="wp-mcp-ai-prompt-tools__name"><?php echo esc_html( $name ); ?></span>
									</label>
								</div>
								<?php if ( $description ) : ?>
									<p class="wp-mcp-ai-prompt-tools__description"><?php echo esc_html( $description ); ?></p>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get the builder assistant ID for the Prompt tab.
	 *
	 * Looks for an assistant with the slug "assistant-builder" or uses a configured default.
	 *
	 * @return int Builder assistant ID or 0 if not found.
	 */
	private function get_builder_assistant_id() {
		// First, try to find an assistant with the slug "assistant-builder".
		$builder_assistant = get_page_by_path( 'assistant-builder', OBJECT, 'mcp_ai_assistant' );

		if ( $builder_assistant && 'publish' === $builder_assistant->post_status ) {
			return (int) $builder_assistant->ID;
		}

		// Fallback: Check plugin settings for a configured builder assistant.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['builder_assistant'] ) ) {
				$builder_id = absint( $settings['builder_assistant'] );
				$post       = get_post( $builder_id );
				if ( $post && 'mcp_ai_assistant' === $post->post_type && 'publish' === $post->post_status ) {
					return $builder_id;
				}
			}
		}

		// Final fallback: Use the default assistant if available.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['default_assistant'] ) ) {
				return absint( $settings['default_assistant'] );
			}
		}

		return 0;
	}

	/**
	 * Get profession options.
	 *
	 * Now integrates with profession CPT system.
	 * Falls back to hardcoded list for backward compatibility.
	 *
	 * @return array Profession key => label pairs.
	 */
	private function get_professions() {
		// Try to get professions from CPT system.
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$profession_service = wp_mcp_ai_get_profession_service();
			$professions        = $profession_service->get_professions_for_dropdown();

			// If we have professions from CPT, use them.
			if ( ! empty( $professions ) ) {
				return $professions;
			}
		}

		// Fallback to hardcoded list for backward compatibility.
		return array(
			'tax_advisor'              => __( 'Tax Advisor', 'wp-mcp-ai' ),
			'accountant'               => __( 'Accountant', 'wp-mcp-ai' ),
			'bookkeeper'               => __( 'Bookkeeper', 'wp-mcp-ai' ),
			'lawyer'                   => __( 'Lawyer', 'wp-mcp-ai' ),
			'legal_advisor'            => __( 'Legal Advisor', 'wp-mcp-ai' ),
			'customs_broker'           => __( 'Customs Broker', 'wp-mcp-ai' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'wp-mcp-ai' ),
			'financial_advisor'        => __( 'Financial Advisor', 'wp-mcp-ai' ),
			'business_consultant'      => __( 'Business Consultant', 'wp-mcp-ai' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'wp-mcp-ai' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'wp-mcp-ai' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'wp-mcp-ai' ),
			'hr_consultant'            => __( 'HR Consultant', 'wp-mcp-ai' ),
			'it_consultant'            => __( 'IT Consultant', 'wp-mcp-ai' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Get region options.
	 *
	 * @return array Region key => label pairs.
	 */
	private function get_regions() {
		return array(
			'united_states'        => __( 'United States', 'wp-mcp-ai' ),
			'canada'               => __( 'Canada', 'wp-mcp-ai' ),
			'united_kingdom'       => __( 'United Kingdom', 'wp-mcp-ai' ),
			'australia'            => __( 'Australia', 'wp-mcp-ai' ),
			'jamaica'              => __( 'Jamaica', 'wp-mcp-ai' ),
			'sri_lanka'            => __( 'Sri Lanka', 'wp-mcp-ai' ),
			'india'                => __( 'India', 'wp-mcp-ai' ),
			'singapore'            => __( 'Singapore', 'wp-mcp-ai' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'wp-mcp-ai' ),
			'germany'              => __( 'Germany', 'wp-mcp-ai' ),
			'france'               => __( 'France', 'wp-mcp-ai' ),
			'spain'                => __( 'Spain', 'wp-mcp-ai' ),
			'italy'                => __( 'Italy', 'wp-mcp-ai' ),
			'netherlands'          => __( 'Netherlands', 'wp-mcp-ai' ),
			'brazil'               => __( 'Brazil', 'wp-mcp-ai' ),
			'mexico'               => __( 'Mexico', 'wp-mcp-ai' ),
			'south_africa'         => __( 'South Africa', 'wp-mcp-ai' ),
			'new_zealand'          => __( 'New Zealand', 'wp-mcp-ai' ),
			'ireland'              => __( 'Ireland', 'wp-mcp-ai' ),
			'japan'                => __( 'Japan', 'wp-mcp-ai' ),
			'china'                => __( 'China', 'wp-mcp-ai' ),
			'global'               => __( 'Global', 'wp-mcp-ai' ),
		);
	}

	/**
	 * Render the Configuration tab content.
	 */
	private function render_configuration_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-configuration-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Assistant Configuration', 'wp-mcp-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the basic settings for your AI assistant.', 'wp-mcp-ai' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-format-chat"></span>
						<h3><?php esc_html_e( 'Create from Template', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new assistant using a professional template with pre-configured settings.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-add-assistant' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Use Template', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-plus-alt"></span>
						<h3><?php esc_html_e( 'Create Custom', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new custom assistant from scratch with your own configuration.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Add New', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Manage Assistants', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'View and manage all existing AI assistants in your system.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'wp-mcp-ai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Quick Statistics', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_assistant_stats(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Advanced tab content.
	 */
	private function render_advanced_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-advanced-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Advanced Settings', 'wp-mcp-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Advanced configuration options for power users.', 'wp-mcp-ai' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'Professional Templates', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Manage professional templates that define roles, tools, and knowledge bases for assistants.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Templates', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-groups"></span>
						<h3><?php esc_html_e( 'Teams', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create teams of assistants that can work together on complex tasks.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Teams', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tools & Features', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure available tools and features that assistants can use.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Tools', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-generic"></span>
						<h3><?php esc_html_e( 'AI Providers', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Gemini, etc.).', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Providers', 'wp-mcp-ai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Documentation', 'wp-mcp-ai' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL to documentation */
						esc_html__( 'For detailed documentation on building and configuring assistants, visit the %s.', 'wp-mcp-ai' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ) . '">' . esc_html__( 'Overview page', 'wp-mcp-ai' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistant statistics.
	 */
	private function render_assistant_stats() {
		$assistants_count  = wp_count_posts( 'mcp_ai_assistant' );
		$professions_count = wp_count_posts( 'mcp_ai_profession' );
		$teams_count       = wp_count_posts( 'mcp_ai_team' );

		$published_assistants  = isset( $assistants_count->publish ) ? $assistants_count->publish : 0;
		$published_professions = isset( $professions_count->publish ) ? $professions_count->publish : 0;
		$published_teams       = isset( $teams_count->publish ) ? $teams_count->publish : 0;
		?>
		<div class="wp-mcp-ai-stats-grid">
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_assistants ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Assistants', 'wp-mcp-ai' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_professions ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Professional Templates', 'wp-mcp-ai' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_teams ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Teams', 'wp-mcp-ai' ); ?></span>
			</div>
		</div>
		<?php
	}
}
