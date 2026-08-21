<?php
/**
 * Build Agent Page — tabbed interface for building AI agents.
 *
 * Extracted from `includes/admin/class-wp-mcp-ai-build-assistant-page.php`.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents\Admin;

use NvoosContentGraphAiPlatform\Agents\Agents;

/**
 * Handles the Build Agent page in admin with Manual, Prompt,
 * Configuration, and Advanced tabs.
 */
final class BuildAgentPage {

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
	}

	/**
	 * Register the admin page.
	 *
	 * @return void
	 */
	public function registerPage(): void {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . Agents::POST_TYPE,
			__( 'Build Agent', 'nvoos-content-graph-ai-platform' ),
			__( 'Build Agent', 'nvoos-content-graph-ai-platform' ),
			'edit_posts',
			'nvoos-content-graph-ai-platform-build-agent',
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
		if ( ! $this->isBuildAgentPage( $hook ) ) {
			return;
		}

		$this->enqueueChatAssets();

		wp_enqueue_style(
			'nvoos-content-graph-ai-platform-agents-build',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/css/agents-build.css',
			array( 'wp-mcp-ai-chat' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION
		);

		wp_enqueue_script(
			'nvoos-content-graph-ai-platform-agents-build',
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/js/agents-build.js',
			array( 'jquery', 'wp-mcp-ai-chat' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);

		wp_enqueue_style(
			'nvoos-content-graph-ai-platform-agents-build-blocks',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'nvoos-content-graph-ai-platform-agents-build-blocks',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'nvoos-content-graph-ai-platform-agents-build',
			'nvoosContentGraphPlatformBuildAgent',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'nvoos_content_graph_platform_create_agent' ),
				'strings'     => array(
					'creating'          => __( 'Creating agent...', 'nvoos-content-graph-ai-platform' ),
					'createAgent'       => __( 'Create Agent', 'nvoos-content-graph-ai-platform' ),
					'success'           => __( 'Agent created successfully!', 'nvoos-content-graph-ai-platform' ),
					'error'             => __( 'Error creating agent. Please try again.', 'nvoos-content-graph-ai-platform' ),
					'required'          => __( 'This field is required.', 'nvoos-content-graph-ai-platform' ),
					'maxProfessions'    => __( 'You can select up to 3 professions.', 'nvoos-content-graph-ai-platform' ),
					'maxRegions'        => __( 'You can select up to 2 regions.', 'nvoos-content-graph-ai-platform' ),
					'emptyConversation' => __( 'Please describe what kind of agent you want to create before clicking Build.', 'nvoos-content-graph-ai-platform' ),
				),
				'professions' => $this->getProfessions(),
				'regions'     => $this->getRegions(),
			)
		);
	}

	/**
	 * Enqueue chat interface assets from the base plugin.
	 *
	 * @return void
	 */
	private function enqueueChatAssets(): void {
		wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			WP_MCP_AI_URL . 'assets/css/cron-status.css',
			array(),
			$this->getAssetVersion( 'assets/css/cron-status.css' )
		);

		wp_enqueue_style(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/css/chat.css',
			array( 'wp-mcp-ai-cron-status' ),
			$this->getAssetVersion( 'assets/css/chat.css' )
		);

		wp_enqueue_script(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/js/chat-bundle.min.js',
			array(),
			$this->getAssetVersion( 'assets/js/chat-bundle.min.js' ),
			true
		);

		$rest_namespace   = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? \WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';
		$async_timeout_ms = class_exists( 'WP_MCP_AI_Shortcode' )
			? \WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

		wp_localize_script(
			'wp-mcp-ai-chat',
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace ) ) ),
				'uploadEndpoint'      => esc_url_raw( $this->normaliseRestUrl( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'     => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/attachments/prepare' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( $this->normaliseRestUrl( rest_url( $rest_namespace . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( $this->normaliseRestUrl( rest_url( $rest_namespace . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'asyncToolTimeout'    => $async_timeout_ms,
				'strings'             => $this->getChatStrings(),
			)
		);
	}

	/**
	 * Normalize REST URL using base plugin helper if available.
	 *
	 * @param string $url REST URL to normalize.
	 * @return string
	 */
	private function normaliseRestUrl( string $url ): string {
		if ( class_exists( 'WP_MCP_AI_Request_Context' ) && method_exists( 'WP_MCP_AI_Request_Context', 'normalise_rest_url' ) ) {
			return \WP_MCP_AI_Request_Context::normalise_rest_url( $url );
		}
		return $url;
	}

	/**
	 * Get asset version based on file modification time.
	 *
	 * @param string $relative_path Asset path relative to plugin root.
	 * @return string
	 */
	private function getAssetVersion( string $relative_path ): string {
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
	 * Get chat interface strings for localization.
	 *
	 * @return array<string, mixed>
	 */
	private function getChatStrings(): array {
		return array(
			'placeholder'               => __( 'Describe the agent you want to create…', 'nvoos-content-graph-ai-platform' ),
			'send'                      => __( 'Send', 'nvoos-content-graph-ai-platform' ),
			'bundlingMessages'          => __( 'Preparing to send…', 'nvoos-content-graph-ai-platform' ),
			'sending'                   => __( 'Sending message…', 'nvoos-content-graph-ai-platform' ),
			'waiting'                   => __( 'Waiting for the AI builder…', 'nvoos-content-graph-ai-platform' ),
			'error'                     => __( 'Something went wrong. Please try again.', 'nvoos-content-graph-ai-platform' ),
			'missingAssistant'          => __( 'Builder agent configuration was not found.', 'nvoos-content-graph-ai-platform' ),
			'notAuthorized'             => __( 'You do not have permission to use the builder.', 'nvoos-content-graph-ai-platform' ),
			/* translators: %s: tool name */
			'toolExecuting'             => __( 'Running tool: %s', 'nvoos-content-graph-ai-platform' ),
			'toolSuccess'               => __( 'Tool completed successfully.', 'nvoos-content-graph-ai-platform' ),
			'toolError'                 => __( 'The tool request failed.', 'nvoos-content-graph-ai-platform' ),
			'toolQueued'                => __( 'Tool queued. Results will appear shortly.', 'nvoos-content-graph-ai-platform' ),
			'toolPolling'               => __( 'Tool is processing…', 'nvoos-content-graph-ai-platform' ),
			'toolTimeout'               => __( 'Tool timed out before completing.', 'nvoos-content-graph-ai-platform' ),
			/* translators: %s: error message */
			'toolFailed'                => __( 'Tool failed: %s', 'nvoos-content-graph-ai-platform' ),
			'emptyMessage'              => __( 'Enter a description before sending.', 'nvoos-content-graph-ai-platform' ),
			'attachFile'                => __( 'Attach file', 'nvoos-content-graph-ai-platform' ),
			'transcribe'                => __( 'Transcribe', 'nvoos-content-graph-ai-platform' ),
			'transcribing'              => __( 'Transcribing audio…', 'nvoos-content-graph-ai-platform' ),
			'recording'                 => __( 'Recording… tap to stop.', 'nvoos-content-graph-ai-platform' ),
			'recordingError'            => __( 'Could not access your microphone.', 'nvoos-content-graph-ai-platform' ),
			'transcriptionError'        => __( 'The transcription request failed.', 'nvoos-content-graph-ai-platform' ),
			'transcriptionFileTooLarge' => __( 'The selected audio file is too large.', 'nvoos-content-graph-ai-platform' ),
			'uploadingFile'             => __( 'Uploading…', 'nvoos-content-graph-ai-platform' ),
			'uploadError'               => __( 'The file could not be uploaded.', 'nvoos-content-graph-ai-platform' ),
			'uploadInProgress'          => __( 'Please wait for uploads to finish.', 'nvoos-content-graph-ai-platform' ),
			'unsupportedFileType'       => __( 'Unsupported file type.', 'nvoos-content-graph-ai-platform' ),
			'newConversation'           => __( 'Start new conversation', 'nvoos-content-graph-ai-platform' ),
			'loadConversation'          => __( 'Load conversation', 'nvoos-content-graph-ai-platform' ),
			'historyLoading'            => __( 'Loading conversations…', 'nvoos-content-graph-ai-platform' ),
			'historyEmpty'              => __( 'No previous conversations yet.', 'nvoos-content-graph-ai-platform' ),
			'historyError'              => __( 'Unable to load conversation history.', 'nvoos-content-graph-ai-platform' ),
			'historySessionError'       => __( 'Unable to load this conversation.', 'nvoos-content-graph-ai-platform' ),
			'deleteConversation'        => __( 'Delete this conversation', 'nvoos-content-graph-ai-platform' ),
			'confirmDeleteConversation' => __( 'Are you sure you want to delete this conversation?', 'nvoos-content-graph-ai-platform' ),
			'roleLabels'                => array(
				'assistant' => __( 'AI Builder', 'nvoos-content-graph-ai-platform' ),
				'user'      => __( 'You', 'nvoos-content-graph-ai-platform' ),
				'system'    => __( 'System', 'nvoos-content-graph-ai-platform' ),
				'tool'      => __( 'Tool', 'nvoos-content-graph-ai-platform' ),
			),
		);
	}

	/**
	 * Check if we are on the Build Agent page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool
	 */
	private function isBuildAgentPage( string $hook ): bool {
		if ( ! empty( $this->page_hook ) ) {
			return $hook === $this->page_hook;
		}

		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) ) {
				return false !== strpos( $screen->id, '_page_nvoos-content-graph-ai-platform-build-agent' );
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return 'nvoos-content-graph-ai-platform-build-agent' === $page;
	}

	/**
	 * Get the currently active tab.
	 *
	 * @return string
	 */
	private function getActiveTab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'manual';

		$valid_tabs = array( 'manual', 'prompt', 'configuration', 'advanced' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'manual';
		}

		return $tab;
	}

	/**
	 * Get tab definitions.
	 *
	 * @return array<string, array{title: string, icon: string}>
	 */
	private function getTabs(): array {
		return array(
			'manual'        => array(
				'title' => __( 'Manual', 'nvoos-content-graph-ai-platform' ),
				'icon'  => 'dashicons-edit',
			),
			'prompt'        => array(
				'title' => __( 'Prompt', 'nvoos-content-graph-ai-platform' ),
				'icon'  => 'dashicons-format-chat',
			),
			'configuration' => array(
				'title' => __( 'Configuration', 'nvoos-content-graph-ai-platform' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'advanced'      => array(
				'title' => __( 'Advanced', 'nvoos-content-graph-ai-platform' ),
				'icon'  => 'dashicons-admin-generic',
			),
		);
	}

	/**
	 * Render the page content.
	 *
	 * @return void
	 */
	public function renderPage(): void {
		$active_tab = $this->getActiveTab();
		$tabs       = $this->getTabs();

		?>
		<div class="wrap wp-mcp-ai-build-assistant-page">
			<h1><?php esc_html_e( 'Build Agent', 'nvoos-content-graph-ai-platform' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure and build custom AI agents with advanced settings and options.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Build Agent tabs', 'nvoos-content-graph-ai-platform' ); ?>">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'post_type' => Agents::POST_TYPE,
							'page'      => 'nvoos-content-graph-ai-platform-build-agent',
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
				switch ( $active_tab ) {
					case 'manual':
						$this->renderManualTab();
						break;
					case 'prompt':
						$this->renderPromptTab();
						break;
					case 'configuration':
						$this->renderConfigurationTab();
						break;
					case 'advanced':
						$this->renderAdvancedTab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Manual tab content.
	 *
	 * @return void
	 */
	private function renderManualTab(): void {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-manual-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Create Agent Manually', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Fill in the form below to create a new AI agent with custom settings.', 'nvoos-content-graph-ai-platform' ); ?></p>

				<form id="wp-mcp-ai-create-assistant-form" class="wp-mcp-ai-assistant-form">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="assistant-title">
										<?php esc_html_e( 'Agent Title', 'nvoos-content-graph-ai-platform' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-title" name="title" class="regular-text" required>
									<p class="description">
										<?php esc_html_e( 'E.g., "Jamaica Tax Agent", "Sri Lanka Customs Broker"', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-professions">
										<?php esc_html_e( 'Professions', 'nvoos-content-graph-ai-platform' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-professions" name="professions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->getProfessions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 3 professions. Hold Ctrl/Cmd to select multiple.', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-regions">
										<?php esc_html_e( 'Regions', 'nvoos-content-graph-ai-platform' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-regions" name="regions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->getRegions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 2 regions. Hold Ctrl/Cmd to select multiple.', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-industry">
										<?php esc_html_e( 'Industry Focus', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-industry" name="industry_focus" class="regular-text">
									<p class="description">
										<?php esc_html_e( 'Optional: E.g., "perfumes", "technology", "restaurants"', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-attachments">
										<?php esc_html_e( 'Knowledge Files', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<input type="file" id="assistant-attachments" name="attachments[]" multiple accept=".txt,.md,.pdf,.doc,.docx">
									<p class="description">
										<?php esc_html_e( 'Optional: Upload files to include in the agent\'s knowledge base (.txt, .md, .pdf, .doc, .docx)', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
									<ul id="assistant-attachments-list" class="wp-mcp-ai-attachments-list"></ul>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-provider">
										<?php esc_html_e( 'AI Provider', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<select id="assistant-provider" name="provider" class="regular-text">
										<?php
										if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
											$available_providers = \WP_MCP_AI_Admin_Settings::get_available_providers();
											$first               = true;
											foreach ( $available_providers as $provider_slug => $provider_label ) {
												$selected = $first ? ' selected' : '';
												?>
												<option value="<?php echo esc_attr( $provider_slug ); ?>"<?php echo esc_attr( $selected ); ?>><?php echo esc_html( $provider_label ); ?></option>
												<?php
												$first = false;
											}
										}
										?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-model">
										<?php esc_html_e( 'Model', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-model" name="model" class="regular-text" value="gpt-4">
									<p class="description">
										<?php esc_html_e( 'E.g., "gpt-4", "gpt-4-turbo", "gemini-pro"', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-temperature">
										<?php esc_html_e( 'Temperature', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="assistant-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" value="0.7">
									<p class="description">
										<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative.', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-async">
										<input type="checkbox" id="assistant-async" name="async" value="1">
										<?php esc_html_e( 'Create in Background', 'nvoos-content-graph-ai-platform' ); ?>
									</label>
								</th>
								<td>
									<p class="description">
										<?php esc_html_e( 'For complex agents, create asynchronously via cron. You will be notified when complete.', 'nvoos-content-graph-ai-platform' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary" id="wp-mcp-ai-submit-create">
							<?php esc_html_e( 'Create Agent', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Prompt tab content.
	 *
	 * @return void
	 */
	private function renderPromptTab(): void {
		$builder_agent_id = $this->getBuilderAgentId();
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-prompt-tab wp-mcp-ai-admin-blocks">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Build with AI Prompt', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<div class="wp-mcp-ai-prompt-intro">
					<strong><?php esc_html_e( 'Describe your agent', 'nvoos-content-graph-ai-platform' ); ?></strong>
					<p><?php esc_html_e( 'Tell the AI what kind of agent you want to create. Describe its purpose, expertise, target audience, and any specific capabilities. You can also upload files to include in its knowledge base and select tools for the agent to use. When ready, click the "Build" button to create your agent.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>

				<?php $this->renderToolsGridComponent(); ?>
				<?php $this->renderKnowledgeBaseComponent(); ?>

				<div class="wp-mcp-ai-prompt-action">
					<?php if ( $builder_agent_id ) : ?>
						<button
							type="button"
							class="button button-primary button-hero wp-mcp-ai-build-with-ai-btn"
							data-assistant-id="<?php echo esc_attr( (string) $builder_agent_id ); ?>"
							data-assistant-title="<?php esc_attr_e( 'AI Agent Builder', 'nvoos-content-graph-ai-platform' ); ?>"
						>
							<span class="dashicons dashicons-format-chat"></span>
							<?php esc_html_e( 'Build with AI', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Click to open the AI chat interface and describe your agent.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<?php else : ?>
						<div class="wp-mcp-ai-no-builder">
							<p><?php esc_html_e( 'The Agent Builder is not configured. Please create an agent with the slug "assistant-builder" or set one in the plugin settings.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- Modal container for Build with AI chat interface -->
		<div id="wp-mcp-ai-build-assistant-modal" class="wp-mcp-ai-test-modal" style="display: none;">
			<div class="wp-mcp-ai-test-modal__backdrop"></div>
			<div class="wp-mcp-ai-test-modal__panel">
				<div class="wp-mcp-ai-test-modal__header">
					<h2><?php esc_html_e( 'Build with AI', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'nvoos-content-graph-ai-platform' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="wp-mcp-ai-test-modal__body">
					<div id="wp-mcp-ai-build-assistant-chat-container"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Tools Grid component for the Prompt tab.
	 *
	 * @return void
	 */
	private function renderToolsGridComponent(): void {
		$render_file = WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';
		if ( ! file_exists( $render_file ) ) {
			return;
		}

		$attributes = array(
			'title'            => __( 'Tools Configuration', 'nvoos-content-graph-ai-platform' ),
			'description'      => __( 'Select the tools you want your agent to be able to use.', 'nvoos-content-graph-ai-platform' ),
			'showDescriptions' => true,
			'startCollapsed'   => true,
			'showActions'      => true,
			'selectedTools'    => array(),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="wp-mcp-ai-prompt-tools-section">';
		include $render_file;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Render the Knowledge Base component for the Prompt tab.
	 *
	 * @return void
	 */
	private function renderKnowledgeBaseComponent(): void {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$render_file = WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';
		if ( ! file_exists( $render_file ) ) {
			return;
		}

		$attributes = array(
			'title'         => __( 'Knowledge Base', 'nvoos-content-graph-ai-platform' ),
			'description'   => __( 'Upload files to include in the agent\'s knowledge base.', 'nvoos-content-graph-ai-platform' ),
			'allowedTypes'  => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
			'maxFiles'      => 10,
			'maxFileSizeMB' => 10,
			'showPreview'   => true,
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="wp-mcp-ai-prompt-knowledge-section">';
		include $render_file;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Get the builder agent ID for the Prompt tab.
	 *
	 * @return int
	 */
	private function getBuilderAgentId(): int {
		$builder = get_page_by_path( 'assistant-builder', OBJECT, Agents::POST_TYPE );
		if ( $builder && 'publish' === $builder->post_status ) {
			return (int) $builder->ID;
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['builder_assistant'] ) ) {
				$builder_id = absint( $settings['builder_assistant'] );
				$post       = get_post( $builder_id );
				if ( $post && Agents::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
					return $builder_id;
				}
			}
			if ( ! empty( $settings['default_assistant'] ) ) {
				return absint( $settings['default_assistant'] );
			}
		}

		return 0;
	}

	/**
	 * Get profession options.
	 *
	 * @return array<string, string>
	 */
	private function getProfessions(): array {
		if ( function_exists( 'wp_mcp_ai_get_profession_service' ) ) {
			$service     = wp_mcp_ai_get_profession_service();
			$professions = $service->get_professions_for_dropdown();
			if ( ! empty( $professions ) ) {
				return $professions;
			}
		}

		return array(
			'tax_advisor'              => __( 'Tax Advisor', 'nvoos-content-graph-ai-platform' ),
			'accountant'               => __( 'Accountant', 'nvoos-content-graph-ai-platform' ),
			'bookkeeper'               => __( 'Bookkeeper', 'nvoos-content-graph-ai-platform' ),
			'lawyer'                   => __( 'Lawyer', 'nvoos-content-graph-ai-platform' ),
			'legal_advisor'            => __( 'Legal Advisor', 'nvoos-content-graph-ai-platform' ),
			'customs_broker'           => __( 'Customs Broker', 'nvoos-content-graph-ai-platform' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'nvoos-content-graph-ai-platform' ),
			'financial_advisor'        => __( 'Financial Advisor', 'nvoos-content-graph-ai-platform' ),
			'business_consultant'      => __( 'Business Consultant', 'nvoos-content-graph-ai-platform' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'nvoos-content-graph-ai-platform' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'nvoos-content-graph-ai-platform' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'nvoos-content-graph-ai-platform' ),
			'hr_consultant'            => __( 'HR Consultant', 'nvoos-content-graph-ai-platform' ),
			'it_consultant'            => __( 'IT Consultant', 'nvoos-content-graph-ai-platform' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Get region options.
	 *
	 * @return array<string, string>
	 */
	private function getRegions(): array {
		return array(
			'united_states'        => __( 'United States', 'nvoos-content-graph-ai-platform' ),
			'canada'               => __( 'Canada', 'nvoos-content-graph-ai-platform' ),
			'united_kingdom'       => __( 'United Kingdom', 'nvoos-content-graph-ai-platform' ),
			'australia'            => __( 'Australia', 'nvoos-content-graph-ai-platform' ),
			'jamaica'              => __( 'Jamaica', 'nvoos-content-graph-ai-platform' ),
			'sri_lanka'            => __( 'Sri Lanka', 'nvoos-content-graph-ai-platform' ),
			'india'                => __( 'India', 'nvoos-content-graph-ai-platform' ),
			'singapore'            => __( 'Singapore', 'nvoos-content-graph-ai-platform' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'nvoos-content-graph-ai-platform' ),
			'germany'              => __( 'Germany', 'nvoos-content-graph-ai-platform' ),
			'france'               => __( 'France', 'nvoos-content-graph-ai-platform' ),
			'spain'                => __( 'Spain', 'nvoos-content-graph-ai-platform' ),
			'italy'                => __( 'Italy', 'nvoos-content-graph-ai-platform' ),
			'netherlands'          => __( 'Netherlands', 'nvoos-content-graph-ai-platform' ),
			'brazil'               => __( 'Brazil', 'nvoos-content-graph-ai-platform' ),
			'mexico'               => __( 'Mexico', 'nvoos-content-graph-ai-platform' ),
			'south_africa'         => __( 'South Africa', 'nvoos-content-graph-ai-platform' ),
			'new_zealand'          => __( 'New Zealand', 'nvoos-content-graph-ai-platform' ),
			'ireland'              => __( 'Ireland', 'nvoos-content-graph-ai-platform' ),
			'japan'                => __( 'Japan', 'nvoos-content-graph-ai-platform' ),
			'china'                => __( 'China', 'nvoos-content-graph-ai-platform' ),
			'global'               => __( 'Global', 'nvoos-content-graph-ai-platform' ),
		);
	}

	/**
	 * Render the Configuration tab content.
	 *
	 * @return void
	 */
	private function renderConfigurationTab(): void {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-configuration-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Agent Configuration', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the basic settings for your AI agent.', 'nvoos-content-graph-ai-platform' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-format-chat"></span>
						<h3><?php esc_html_e( 'Create from Template', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Create a new agent using a professional template with pre-configured settings.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Agents::POST_TYPE . '&page=nvoos-content-graph-ai-platform-add-agent' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Use Template', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-plus-alt"></span>
						<h3><?php esc_html_e( 'Create Custom', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Create a new custom agent from scratch with your own configuration.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Add New', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Manage Agents', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'View and manage all existing AI agents in your system.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Agents::POST_TYPE ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Quick Statistics', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<?php $this->renderStats(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Advanced tab content.
	 *
	 * @return void
	 */
	private function renderAdvancedTab(): void {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-advanced-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Advanced Settings', 'nvoos-content-graph-ai-platform' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Advanced configuration options for power users.', 'nvoos-content-graph-ai-platform' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'Professional Templates', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Manage professional templates that define roles, tools, and knowledge bases for agents.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Templates', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-groups"></span>
						<h3><?php esc_html_e( 'Teams', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Create teams of agents that can work together on complex tasks.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Teams', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tools & Features', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Configure available tools and features that agents can use.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Tools', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-generic"></span>
						<h3><?php esc_html_e( 'AI Providers', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p><?php esc_html_e( 'Configure API keys and settings for AI providers.', 'nvoos-content-graph-ai-platform' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Providers', 'nvoos-content-graph-ai-platform' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render agent statistics.
	 *
	 * @return void
	 */
	private function renderStats(): void {
		$assistants_count  = wp_count_posts( Agents::POST_TYPE );
		$professions_count = wp_count_posts( 'mcp_ai_profession' );
		$teams_count       = wp_count_posts( 'mcp_ai_team' );

		$published_assistants  = isset( $assistants_count->publish ) ? $assistants_count->publish : 0;
		$published_professions = isset( $professions_count->publish ) ? $professions_count->publish : 0;
		$published_teams       = isset( $teams_count->publish ) ? $teams_count->publish : 0;
		?>
		<div class="wp-mcp-ai-stats-grid">
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( (string) $published_assistants ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Agents', 'nvoos-content-graph-ai-platform' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( (string) $published_professions ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Professional Templates', 'nvoos-content-graph-ai-platform' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( (string) $published_teams ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Teams', 'nvoos-content-graph-ai-platform' ); ?></span>
			</div>
		</div>
		<?php
	}
}
