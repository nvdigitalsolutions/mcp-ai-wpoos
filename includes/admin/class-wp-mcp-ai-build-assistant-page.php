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

		// Enqueue chat assets for the Prompt tab modal.
		$this->enqueue_chat_assets();

		wp_enqueue_style(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-build-assistant.css',
			array( 'wp-mcp-ai-chat' ),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-build-assistant.js',
			array( 'jquery', 'wp-mcp-ai-chat' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Enqueue blocks assets for the Prompt tab's enhanced components.
		wp_enqueue_style(
			'wp-mcp-ai-assistant-builder-blocks',
			WP_MCP_AI_URL . 'assets/css/blocks/assistant-builder-blocks.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-assistant-builder-frontend',
			WP_MCP_AI_URL . 'assets/js/blocks/assistant-builder-blocks-frontend.js',
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
	 * Enqueue chat interface assets.
	 * Provides chat functionality for the Build with AI modal.
	 *
	 * Uses the bundled chat-bundle.js which combines all chat-related services
	 * into a single optimized file, reducing HTTP requests from 9 files to 1 file.
	 */
	private function enqueue_chat_assets() {
		// Enqueue cron status styles (CSS only - JS is bundled)
		wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			WP_MCP_AI_URL . 'assets/css/cron-status.css',
			array(),
			$this->get_asset_version( 'assets/css/cron-status.css' )
		);

		wp_enqueue_style(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/css/chat.css',
			array( 'wp-mcp-ai-cron-status' ),
			$this->get_asset_version( 'assets/css/chat.css' )
		);

		// Register bundled chat script (includes all services in a single file).
		// The chat-bundle.js is an entry point for esbuild with ES6 imports,
		// so we must load the bundled output (chat-bundle.min.js) which is browser-compatible.
		wp_enqueue_script(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/js/chat-bundle.min.js',
			array(), // No dependencies - all services are bundled together
			$this->get_asset_version( 'assets/js/chat-bundle.min.js' ),
			true
		);

		// Safety check: Ensure REST constants exist.
		$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

		// Use the shortcode helper method for consistent async tool timeout calculation.
		$async_timeout_ms = class_exists( 'WP_MCP_AI_Shortcode' )
			? WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

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
				'asyncToolTimeout'    => $async_timeout_ms,
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
	 * Get chat interface strings for localization.
	 *
	 * @return array
	 */
	private function get_chat_strings() {
		return array(
			'placeholder'                   => __( 'Describe the assistant you want to create…', 'wp-mcp-ai' ),
			'send'                          => __( 'Send', 'wp-mcp-ai' ),
			'bundlingMessages'              => __( 'Preparing to send…', 'wp-mcp-ai' ),
			'sending'                       => __( 'Sending message…', 'wp-mcp-ai' ),
			'waiting'                       => __( 'Waiting for the AI builder…', 'wp-mcp-ai' ),
			'error'                         => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
			'missingAssistant'              => __( 'Builder assistant configuration was not found.', 'wp-mcp-ai' ),
			'notAuthorized'                 => __( 'You do not have permission to use the builder.', 'wp-mcp-ai' ),
			'toolExecuting'                 => __( 'Running tool: %s', 'wp-mcp-ai' ),
			'toolSuccess'                   => __( 'Tool completed successfully.', 'wp-mcp-ai' ),
			'toolError'                     => __( 'The tool request failed.', 'wp-mcp-ai' ),
			'toolQueued'                    => __( 'Tool queued. Results will appear shortly.', 'wp-mcp-ai' ),
			'toolPolling'                   => __( 'Tool is processing…', 'wp-mcp-ai' ),
			'toolTimeout'                   => __( 'Tool timed out before completing.', 'wp-mcp-ai' ),
			'toolFailed'                    => __( 'Tool failed: %s', 'wp-mcp-ai' ),
			'speechToolSuccess'             => __( 'Speech audio saved to the Media Library.', 'wp-mcp-ai' ),
			'imageToolSuccess'              => __( 'Image saved to the Media Library.', 'wp-mcp-ai' ),
			'toolShortcutLabel'             => __( 'Insert task: %s', 'wp-mcp-ai' ),
			'emptyMessage'                  => __( 'Enter a description before sending.', 'wp-mcp-ai' ),
			'attachFile'                    => __( 'Attach file', 'wp-mcp-ai' ),
			'transcribe'                    => __( 'Transcribe', 'wp-mcp-ai' ),
			'transcribeAudio'               => __( 'Transcribe audio', 'wp-mcp-ai' ),
			'transcribing'                  => __( 'Transcribing audio…', 'wp-mcp-ai' ),
			'recording'                     => __( 'Recording… tap to stop.', 'wp-mcp-ai' ),
			'stopRecording'                 => __( 'Stop recording', 'wp-mcp-ai' ),
			'recordingError'                => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'wp-mcp-ai' ),
			'transcriptionError'            => __( 'The transcription request failed. Please try again.', 'wp-mcp-ai' ),
			'transcriptionSuccess'          => __( 'Inserted transcription from "%s".', 'wp-mcp-ai' ),
			'transcriptionFileTooLarge'     => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'wp-mcp-ai' ),
			'transcribeChooseSource'        => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'wp-mcp-ai' ),
			'attachmentsLabel'              => __( 'Attachments', 'wp-mcp-ai' ),
			'removeAttachment'              => __( 'Remove', 'wp-mcp-ai' ),
			'uploadingFile'                 => __( 'Uploading "%s"…', 'wp-mcp-ai' ),
			'uploadError'                   => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
			'uploadInProgress'              => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
			'downloadAttachment'            => __( 'Download attachment', 'wp-mcp-ai' ),
			'unsupportedFileType'           => __( '"%s" is not a supported file type. Please choose a different file.', 'wp-mcp-ai' ),
			'unsupportedMultipleFiles'      => __( 'Some selected files are not supported. Please try different files.', 'wp-mcp-ai' ),
			'unsupportedFileLabel'          => __( 'This file', 'wp-mcp-ai' ),
			'expandTranscript'              => __( 'Expand conversation', 'wp-mcp-ai' ),
			'collapseTranscript'            => __( 'Collapse conversation', 'wp-mcp-ai' ),
			'newConversation'               => __( 'Start new conversation', 'wp-mcp-ai' ),
			'loadConversation'              => __( 'Load conversation', 'wp-mcp-ai' ),
			'jsonResponse'                  => __( 'JSON response', 'wp-mcp-ai' ),
			'historyToggleShow'             => __( 'Show previous conversations', 'wp-mcp-ai' ),
			'historyToggleHide'             => __( 'Hide previous conversations', 'wp-mcp-ai' ),
			'historyLoading'                => __( 'Loading conversations…', 'wp-mcp-ai' ),
			'historyEmpty'                  => __( 'No previous conversations yet.', 'wp-mcp-ai' ),
			'historyError'                  => __( 'Unable to load conversation history.', 'wp-mcp-ai' ),
			'historyMessageCount'           => __( '%d messages', 'wp-mcp-ai' ),
			'historySingleMessage'          => __( '1 message', 'wp-mcp-ai' ),
			'historyPreviewFallback'        => __( 'Conversation %s', 'wp-mcp-ai' ),
			'historySessionLoading'         => __( 'Loading conversation…', 'wp-mcp-ai' ),
			'historySessionError'           => __( 'Unable to load this conversation. Please try again.', 'wp-mcp-ai' ),
			'historyNoMessages'             => __( 'No messages were saved for this conversation.', 'wp-mcp-ai' ),
			'saveConversation'              => __( 'Save conversation', 'wp-mcp-ai' ),
			'savingConversation'            => __( 'Saving current conversation...', 'wp-mcp-ai' ),
			'conversationSaved'             => __( 'Conversation saved successfully.', 'wp-mcp-ai' ),
			'saveFailed'                    => __( 'Failed to save conversation. See console for details.', 'wp-mcp-ai' ),
			'saveFailedProceed'             => __( 'Failed to save conversation: ', 'wp-mcp-ai' ),
			'proceedAnyway'                 => __( 'Do you want to proceed anyway? Your current conversation will be lost.', 'wp-mcp-ai' ),
			'saveFailedKeepingConversation' => __( 'Conversation not cleared. You can try again later.', 'wp-mcp-ai' ),
			'noConversationToSave'          => __( 'No conversation to save. Start chatting first!', 'wp-mcp-ai' ),
			'saveSkipped'                   => __( 'Save not available for this conversation.', 'wp-mcp-ai' ),
			'confirmClearConversation'      => __( 'Start a new conversation? Your current conversation will be saved automatically.', 'wp-mcp-ai' ),
			'noConversationToExport'        => __( 'No conversation to export. Start chatting first!', 'wp-mcp-ai' ),
			'exportFormatPrompt'            => __( 'Choose export format:\n- json\n- markdown\n- text', 'wp-mcp-ai' ),
			'invalidExportFormat'           => __( 'Invalid format. Please choose json, markdown, or text.', 'wp-mcp-ai' ),
			'exportFailed'                  => __( 'Export failed: ', 'wp-mcp-ai' ),
			'exportSuccess'                 => __( 'Conversation exported successfully as ', 'wp-mcp-ai' ),
			'deleteConversation'            => __( 'Delete this conversation', 'wp-mcp-ai' ),
			'confirmDeleteConversation'     => __( 'Are you sure you want to delete this conversation? This action cannot be undone.', 'wp-mcp-ai' ),
			'veoVideoToolSuccess'           => __( 'Video generated successfully and saved to the Media Library.', 'wp-mcp-ai' ),
			'videoNotSupported'             => __( 'Your browser does not support video playback.', 'wp-mcp-ai' ),
			'downloadVideo'                 => __( 'Download video', 'wp-mcp-ai' ),
			'geminiImageToolSuccess'        => __( 'Gemini image saved to the Media Library.', 'wp-mcp-ai' ),
			'editGeminiImageToolSuccess'    => __( 'Gemini image edited and saved to the Media Library.', 'wp-mcp-ai' ),
			'roleLabels'                    => array(
				'assistant' => __( 'AI Builder', 'wp-mcp-ai' ),
				'user'      => __( 'You', 'wp-mcp-ai' ),
				'system'    => __( 'System', 'wp-mcp-ai' ),
				'tool'      => __( 'Tool', 'wp-mcp-ai' ),
			),
		);
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
		$builder_assistant_id = $this->get_builder_assistant_id();
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-prompt-tab wp-mcp-ai-admin-blocks">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Build with AI Prompt', 'wp-mcp-ai' ); ?></h2>
				<div class="wp-mcp-ai-prompt-intro">
					<strong><?php esc_html_e( 'Describe your assistant', 'wp-mcp-ai' ); ?></strong>
					<p><?php esc_html_e( 'Tell the AI what kind of assistant you want to create. Describe its purpose, expertise, target audience, and any specific capabilities. You can also upload files to include in its knowledge base and select tools for the assistant to use. When ready, click the "Build" button to create your assistant.', 'wp-mcp-ai' ); ?></p>
				</div>

				<?php
				// Render the Tools Grid component.
				$this->render_tools_grid_component();
				?>

				<?php
				// Render the Knowledge Base component.
				$this->render_knowledge_base_component();
				?>

				<div class="wp-mcp-ai-prompt-action">
					<?php if ( $builder_assistant_id ) : ?>
						<button
							type="button"
							class="button button-primary button-hero wp-mcp-ai-build-with-ai-btn"
							data-assistant-id="<?php echo esc_attr( $builder_assistant_id ); ?>"
							data-assistant-title="<?php esc_attr_e( 'AI Assistant Builder', 'wp-mcp-ai' ); ?>"
						>
							<span class="dashicons dashicons-format-chat"></span>
							<?php esc_html_e( 'Build with AI', 'wp-mcp-ai' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Click to open the AI chat interface and describe your assistant.', 'wp-mcp-ai' ); ?></p>
					<?php else : ?>
						<div class="wp-mcp-ai-no-builder">
							<p><?php esc_html_e( 'The Assistant Builder is not configured. Please create an assistant with the slug "assistant-builder" or set one in the plugin settings.', 'wp-mcp-ai' ); ?></p>
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
					<h2 id="wp-mcp-ai-build-assistant-modal__title"><?php esc_html_e( 'Build with AI', 'wp-mcp-ai' ); ?></h2>
					<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wp-mcp-ai' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="wp-mcp-ai-test-modal__body">
					<!-- Chat interface will be initialized here -->
					<div id="wp-mcp-ai-build-assistant-chat-container"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Tools Grid component for the Prompt tab.
	 *
	 * This includes the tools-grid block render template for use in the admin context.
	 */
	private function render_tools_grid_component() {
		$render_file = WP_MCP_AI_PATH . 'includes/blocks/tools-grid/render.php';

		// Verify the render file exists before including.
		if ( ! file_exists( $render_file ) ) {
			return;
		}

		// Set up attributes for the tools grid render.
		$attributes = array(
			'title'            => __( 'Tools Configuration', 'wp-mcp-ai' ),
			'description'      => __( 'Select the tools you want your assistant to be able to use.', 'wp-mcp-ai' ),
			'showDescriptions' => true,
			'startCollapsed'   => true,
			'showActions'      => true,
			'selectedTools'    => array(),
		);

		echo '<div class="wp-mcp-ai-prompt-tools-section">';
		include $render_file;
		echo '</div>';
	}

	/**
	 * Render the Knowledge Base component for the Prompt tab.
	 *
	 * This includes the knowledge-base block render template for use in the admin context.
	 */
	private function render_knowledge_base_component() {
		// Check if user can upload files.
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$render_file = WP_MCP_AI_PATH . 'includes/blocks/knowledge-base/render.php';

		// Verify the render file exists before including.
		if ( ! file_exists( $render_file ) ) {
			return;
		}

		// Set up attributes for the knowledge base render.
		$attributes = array(
			'title'         => __( 'Knowledge Base', 'wp-mcp-ai' ),
			'description'   => __( 'Upload files to include in the assistant\'s knowledge base. These files will be used to provide context to the AI.', 'wp-mcp-ai' ),
			'allowedTypes'  => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
			'maxFiles'      => 10,
			'maxFileSizeMB' => 10,
			'showPreview'   => true,
		);

		echo '<div class="wp-mcp-ai-prompt-knowledge-section">';
		include $render_file;
		echo '</div>';
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
