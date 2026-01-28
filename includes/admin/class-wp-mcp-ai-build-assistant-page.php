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
			__( 'Build Assistant', 'mcp-ai-wpoos' ),
			__( 'Build Assistant', 'mcp-ai-wpoos' ),
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
		// Check if we're on the Build Assistant page.
		if ( ! $this->is_build_assistant_page( $hook ) ) {
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
					'creating'          => __( 'Creating assistant...', 'mcp-ai-wpoos' ),
					'createAssistant'   => __( 'Create Assistant', 'mcp-ai-wpoos' ),
					'success'           => __( 'Assistant created successfully!', 'mcp-ai-wpoos' ),
					'error'             => __( 'Error creating assistant. Please try again.', 'mcp-ai-wpoos' ),
					'required'          => __( 'This field is required.', 'mcp-ai-wpoos' ),
					'maxProfessions'    => __( 'You can select up to 3 professions.', 'mcp-ai-wpoos' ),
					'maxRegions'        => __( 'You can select up to 2 regions.', 'mcp-ai-wpoos' ),
					'emptyConversation' => __( 'Please describe what kind of assistant you want to create before clicking Build.', 'mcp-ai-wpoos' ),
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
		// Enqueue cron status styles (CSS only - JS is bundled).

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
		// The chat-bundle.js is an entry point for esbuild with ES6 imports,.

		// so we must load the bundled output (chat-bundle.min.js) which is browser-compatible.
		wp_enqueue_script(
			'wp-mcp-ai-chat',
			WP_MCP_AI_URL . 'assets/js/chat-bundle.min.js',
			array(), // No dependencies - all services are bundled together.
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
				'toolsEndpoint'       => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/tools' ) ) ),
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
			'placeholder'                   => __( 'Describe the assistant you want to create…', 'mcp-ai-wpoos' ),
			'send'                          => __( 'Send', 'mcp-ai-wpoos' ),
			'bundlingMessages'              => __( 'Preparing to send…', 'mcp-ai-wpoos' ),
			'sending'                       => __( 'Sending message…', 'mcp-ai-wpoos' ),
			'waiting'                       => __( 'Waiting for the AI builder…', 'mcp-ai-wpoos' ),
			'error'                         => __( 'Something went wrong. Please try again.', 'mcp-ai-wpoos' ),
			'missingAssistant'              => __( 'Builder assistant configuration was not found.', 'mcp-ai-wpoos' ),
			'notAuthorized'                 => __( 'You do not have permission to use the builder.', 'mcp-ai-wpoos' ),
			'toolExecuting'                 => __( 'Running tool: %s', 'mcp-ai-wpoos' ),
			'toolSuccess'                   => __( 'Tool completed successfully.', 'mcp-ai-wpoos' ),
			'toolError'                     => __( 'The tool request failed.', 'mcp-ai-wpoos' ),
			'toolQueued'                    => __( 'Tool queued. Results will appear shortly.', 'mcp-ai-wpoos' ),
			'toolPolling'                   => __( 'Tool is processing…', 'mcp-ai-wpoos' ),
			'toolTimeout'                   => __( 'Tool timed out before completing.', 'mcp-ai-wpoos' ),
			'toolFailed'                    => __( 'Tool failed: %s', 'mcp-ai-wpoos' ),
			'speechToolSuccess'             => __( 'Speech audio saved to the Media Library.', 'mcp-ai-wpoos' ),
			'imageToolSuccess'              => __( 'Image saved to the Media Library.', 'mcp-ai-wpoos' ),
			'toolShortcutLabel'             => __( 'Insert task: %s', 'mcp-ai-wpoos' ),
			'emptyMessage'                  => __( 'Enter a description before sending.', 'mcp-ai-wpoos' ),
			'attachFile'                    => __( 'Attach file', 'mcp-ai-wpoos' ),
			'transcribe'                    => __( 'Transcribe', 'mcp-ai-wpoos' ),
			'transcribeAudio'               => __( 'Transcribe audio', 'mcp-ai-wpoos' ),
			'transcribing'                  => __( 'Transcribing audio…', 'mcp-ai-wpoos' ),
			'recording'                     => __( 'Recording… tap to stop.', 'mcp-ai-wpoos' ),
			'stopRecording'                 => __( 'Stop recording', 'mcp-ai-wpoos' ),
			'recordingError'                => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'mcp-ai-wpoos' ),
			'transcriptionError'            => __( 'The transcription request failed. Please try again.', 'mcp-ai-wpoos' ),
			'transcriptionSuccess'          => __( 'Inserted transcription from "%s".', 'mcp-ai-wpoos' ),
			'transcriptionFileTooLarge'     => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'mcp-ai-wpoos' ),
			'transcribeChooseSource'        => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'mcp-ai-wpoos' ),
			'attachmentsLabel'              => __( 'Attachments', 'mcp-ai-wpoos' ),
			'removeAttachment'              => __( 'Remove', 'mcp-ai-wpoos' ),
			'uploadingFile'                 => __( 'Uploading "%s"…', 'mcp-ai-wpoos' ),
			'uploadError'                   => __( 'The file could not be uploaded. Please try again.', 'mcp-ai-wpoos' ),
			'uploadInProgress'              => __( 'Please wait for uploads to finish before sending.', 'mcp-ai-wpoos' ),
			'downloadAttachment'            => __( 'Download attachment', 'mcp-ai-wpoos' ),
			'unsupportedFileType'           => __( '"%s" is not a supported file type. Please choose a different file.', 'mcp-ai-wpoos' ),
			'unsupportedMultipleFiles'      => __( 'Some selected files are not supported. Please try different files.', 'mcp-ai-wpoos' ),
			'unsupportedFileLabel'          => __( 'This file', 'mcp-ai-wpoos' ),
			'expandTranscript'              => __( 'Expand conversation', 'mcp-ai-wpoos' ),
			'collapseTranscript'            => __( 'Collapse conversation', 'mcp-ai-wpoos' ),
			'newConversation'               => __( 'Start new conversation', 'mcp-ai-wpoos' ),
			'loadConversation'              => __( 'Load conversation', 'mcp-ai-wpoos' ),
			'jsonResponse'                  => __( 'JSON response', 'mcp-ai-wpoos' ),
			'historyToggleShow'             => __( 'Show previous conversations', 'mcp-ai-wpoos' ),
			'historyToggleHide'             => __( 'Hide previous conversations', 'mcp-ai-wpoos' ),
			'historyLoading'                => __( 'Loading conversations…', 'mcp-ai-wpoos' ),
			'historyEmpty'                  => __( 'No previous conversations yet.', 'mcp-ai-wpoos' ),
			'historyError'                  => __( 'Unable to load conversation history.', 'mcp-ai-wpoos' ),
			'historyMessageCount'           => __( '%d messages', 'mcp-ai-wpoos' ),
			'historySingleMessage'          => __( '1 message', 'mcp-ai-wpoos' ),
			'historyPreviewFallback'        => __( 'Conversation %s', 'mcp-ai-wpoos' ),
			'historySessionLoading'         => __( 'Loading conversation…', 'mcp-ai-wpoos' ),
			'historySessionError'           => __( 'Unable to load this conversation. Please try again.', 'mcp-ai-wpoos' ),
			'historyNoMessages'             => __( 'No messages were saved for this conversation.', 'mcp-ai-wpoos' ),
			'saveConversation'              => __( 'Save conversation', 'mcp-ai-wpoos' ),
			'savingConversation'            => __( 'Saving current conversation...', 'mcp-ai-wpoos' ),
			'conversationSaved'             => __( 'Conversation saved successfully.', 'mcp-ai-wpoos' ),
			'saveFailed'                    => __( 'Failed to save conversation. See console for details.', 'mcp-ai-wpoos' ),
			'saveFailedProceed'             => __( 'Failed to save conversation: ', 'mcp-ai-wpoos' ),
			'proceedAnyway'                 => __( 'Do you want to proceed anyway? Your current conversation will be lost.', 'mcp-ai-wpoos' ),
			'saveFailedKeepingConversation' => __( 'Conversation not cleared. You can try again later.', 'mcp-ai-wpoos' ),
			'noConversationToSave'          => __( 'No conversation to save. Start chatting first!', 'mcp-ai-wpoos' ),
			'saveSkipped'                   => __( 'Save not available for this conversation.', 'mcp-ai-wpoos' ),
			'confirmClearConversation'      => __( 'Start a new conversation? Your current conversation will be saved automatically.', 'mcp-ai-wpoos' ),
			'noConversationToExport'        => __( 'No conversation to export. Start chatting first!', 'mcp-ai-wpoos' ),
			'exportFormatPrompt'            => __( 'Choose export format:\n- json\n- markdown\n- text', 'mcp-ai-wpoos' ),
			'invalidExportFormat'           => __( 'Invalid format. Please choose json, markdown, or text.', 'mcp-ai-wpoos' ),
			'exportFailed'                  => __( 'Export failed: ', 'mcp-ai-wpoos' ),
			'exportSuccess'                 => __( 'Conversation exported successfully as ', 'mcp-ai-wpoos' ),
			'deleteConversation'            => __( 'Delete this conversation', 'mcp-ai-wpoos' ),
			'confirmDeleteConversation'     => __( 'Are you sure you want to delete this conversation? This action cannot be undone.', 'mcp-ai-wpoos' ),
			'veoVideoToolSuccess'           => __( 'Video generated successfully and saved to the Media Library.', 'mcp-ai-wpoos' ),
			'videoNotSupported'             => __( 'Your browser does not support video playback.', 'mcp-ai-wpoos' ),
			'downloadVideo'                 => __( 'Download video', 'mcp-ai-wpoos' ),
			'geminiImageToolSuccess'        => __( 'Gemini image saved to the Media Library.', 'mcp-ai-wpoos' ),
			'editGeminiImageToolSuccess'    => __( 'Gemini image edited and saved to the Media Library.', 'mcp-ai-wpoos' ),
			'roleLabels'                    => array(
				'assistant' => __( 'AI Builder', 'mcp-ai-wpoos' ),
				'user'      => __( 'You', 'mcp-ai-wpoos' ),
				'system'    => __( 'System', 'mcp-ai-wpoos' ),
				'tool'      => __( 'Tool', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Check if we're on the Build Assistant page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return bool True if on Build Assistant page, false otherwise.
	 */
	private function is_build_assistant_page( $hook ) {
		// Primary check: use page_hook property if available.
		if ( ! empty( $this->page_hook ) ) {
			return $hook === $this->page_hook;
		}

		// Fallback: check using get_current_screen() if available.
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && isset( $screen->id ) ) {
				// The screen ID for submenus is typically parent-page_page_menu-slug.
				// Check for exact match or if it ends with our page slug.
				return $screen->id === 'mcp_ai_assistant_page_wp-mcp-ai-build-assistant'
					|| false !== strpos( $screen->id, '_page_wp-mcp-ai-build-assistant' );
			}
		}

		// Last resort: check page query parameter (with sanitization).
		// This is a read-only check for admin page routing, not user input processing.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		return 'wp-mcp-ai-build-assistant' === $page;
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
				'title' => __( 'Manual', 'mcp-ai-wpoos' ),
				'icon'  => 'dashicons-edit',
			),
			'prompt'        => array(
				'title' => __( 'Prompt', 'mcp-ai-wpoos' ),
				'icon'  => 'dashicons-format-chat',
			),
			'configuration' => array(
				'title' => __( 'Configuration', 'mcp-ai-wpoos' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'advanced'      => array(
				'title' => __( 'Advanced', 'mcp-ai-wpoos' ),
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
			<h1><?php esc_html_e( 'Build Assistant', 'mcp-ai-wpoos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure and build custom AI assistants with advanced settings and options.', 'mcp-ai-wpoos' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Build Assistant tabs', 'mcp-ai-wpoos' ); ?>">
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
				<h2><?php esc_html_e( 'Create Assistant Manually', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Fill in the form below to create a new AI assistant with custom settings.', 'mcp-ai-wpoos' ); ?></p>

				<form id="wp-mcp-ai-create-assistant-form" class="wp-mcp-ai-assistant-form">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="assistant-title">
										<?php esc_html_e( 'Assistant Title', 'mcp-ai-wpoos' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-title" name="title" class="regular-text" required>
									<p class="description">
										<?php esc_html_e( 'E.g., "Jamaica Tax Assistant", "Sri Lanka Customs Broker - Perfumes"', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-professions">
										<?php esc_html_e( 'Professions', 'mcp-ai-wpoos' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-professions" name="professions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_professions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 3 professions. Hold Ctrl/Cmd to select multiple.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-regions">
										<?php esc_html_e( 'Regions', 'mcp-ai-wpoos' ); ?> <span class="required">*</span>
									</label>
								</th>
								<td>
									<select id="assistant-regions" name="regions[]" multiple class="regular-text" required style="height: 150px;">
										<?php foreach ( $this->get_regions() as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'Select up to 2 regions. Hold Ctrl/Cmd to select multiple.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-industry">
										<?php esc_html_e( 'Industry Focus', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-industry" name="industry_focus" class="regular-text">
									<p class="description">
										<?php esc_html_e( 'Optional: E.g., "perfumes", "technology", "restaurants"', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-attachments">
										<?php esc_html_e( 'Knowledge Files', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<input type="file" id="assistant-attachments" name="attachments[]" multiple accept=".txt,.md,.pdf,.doc,.docx">
									<p class="description">
										<?php esc_html_e( 'Optional: Upload files to include in the assistant\'s knowledge base (.txt, .md, .pdf, .doc, .docx)', 'mcp-ai-wpoos' ); ?>
									</p>
									<ul id="assistant-attachments-list" class="wp-mcp-ai-attachments-list"></ul>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-provider">
										<?php esc_html_e( 'AI Provider', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<select id="assistant-provider" name="provider" class="regular-text">
										<?php
										$available_providers = WP_MCP_AI_Admin_Settings::get_available_providers();
										$first               = true;
										foreach ( $available_providers as $provider_slug => $provider_label ) {
											?>
											<option value="<?php echo esc_attr( $provider_slug ); ?>"<?php echo $first ? ' selected' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML attribute. ?>><?php echo esc_html( $provider_label ); ?></option>
											<?php
											$first = false;
										}
										?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-model">
										<?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<input type="text" id="assistant-model" name="model" class="regular-text" value="gpt-4">
									<p class="description">
										<?php esc_html_e( 'E.g., "gpt-4", "gpt-4-turbo", "gemini-pro"', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-temperature">
										<?php esc_html_e( 'Temperature', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<input type="number" id="assistant-temperature" name="temperature" class="small-text" min="0" max="2" step="0.1" value="0.7">
									<p class="description">
										<?php esc_html_e( '0-2. Lower is more deterministic, higher is more creative.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="assistant-async">
										<input type="checkbox" id="assistant-async" name="async" value="1">
										<?php esc_html_e( 'Create in Background', 'mcp-ai-wpoos' ); ?>
									</label>
								</th>
								<td>
									<p class="description">
										<?php esc_html_e( 'For complex assistants, create asynchronously via cron. You will be notified when complete.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary" id="wp-mcp-ai-submit-create">
							<?php esc_html_e( 'Create Assistant', 'mcp-ai-wpoos' ); ?>
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
				<h2><?php esc_html_e( 'Build with AI Prompt', 'mcp-ai-wpoos' ); ?></h2>
				<div class="wp-mcp-ai-prompt-intro">
					<strong><?php esc_html_e( 'Describe your assistant', 'mcp-ai-wpoos' ); ?></strong>
					<p><?php esc_html_e( 'Tell the AI what kind of assistant you want to create. Describe its purpose, expertise, target audience, and any specific capabilities. You can also upload files to include in its knowledge base and select tools for the assistant to use. When ready, click the "Build" button to create your assistant.', 'mcp-ai-wpoos' ); ?></p>
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
							data-assistant-title="<?php esc_attr_e( 'AI Assistant Builder', 'mcp-ai-wpoos' ); ?>"
						>
							<span class="dashicons dashicons-format-chat"></span>
							<?php esc_html_e( 'Build with AI', 'mcp-ai-wpoos' ); ?>
						</button>
						<p class="description"><?php esc_html_e( 'Click to open the AI chat interface and describe your assistant.', 'mcp-ai-wpoos' ); ?></p>
					<?php else : ?>
						<div class="wp-mcp-ai-no-builder">
							<p><?php esc_html_e( 'The Assistant Builder is not configured. Please create an assistant with the slug "assistant-builder" or set one in the plugin settings.', 'mcp-ai-wpoos' ); ?></p>
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
					<h2 id="wp-mcp-ai-build-assistant-modal__title"><?php esc_html_e( 'Build with AI', 'mcp-ai-wpoos' ); ?></h2>
					<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php esc_attr_e( 'Close', 'mcp-ai-wpoos' ); ?>">
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
			'title'            => __( 'Tools Configuration', 'mcp-ai-wpoos' ),
			'description'      => __( 'Select the tools you want your assistant to be able to use.', 'mcp-ai-wpoos' ),
			'showDescriptions' => true,
			'startCollapsed'   => true,
			'showActions'      => true,
			'selectedTools'    => array(),
		);

		echo '<div class="wp-mcp-ai-prompt-tools-section">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
		include $render_file;
		echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
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
			'title'         => __( 'Knowledge Base', 'mcp-ai-wpoos' ),
			'description'   => __( 'Upload files to include in the assistant\'s knowledge base. These files will be used to provide context to the AI.', 'mcp-ai-wpoos' ),
			'allowedTypes'  => '.pdf,.txt,.md,.doc,.docx,.csv,.json',
			'maxFiles'      => 10,
			'maxFileSizeMB' => 10,
			'showPreview'   => true,
		);

		echo '<div class="wp-mcp-ai-prompt-knowledge-section">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
		include $render_file;
		echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
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
			'tax_advisor'              => __( 'Tax Advisor', 'mcp-ai-wpoos' ),
			'accountant'               => __( 'Accountant', 'mcp-ai-wpoos' ),
			'bookkeeper'               => __( 'Bookkeeper', 'mcp-ai-wpoos' ),
			'lawyer'                   => __( 'Lawyer', 'mcp-ai-wpoos' ),
			'legal_advisor'            => __( 'Legal Advisor', 'mcp-ai-wpoos' ),
			'customs_broker'           => __( 'Customs Broker', 'mcp-ai-wpoos' ),
			'import_export_specialist' => __( 'Import/Export Specialist', 'mcp-ai-wpoos' ),
			'financial_advisor'        => __( 'Financial Advisor', 'mcp-ai-wpoos' ),
			'business_consultant'      => __( 'Business Consultant', 'mcp-ai-wpoos' ),
			'real_estate_agent'        => __( 'Real Estate Agent', 'mcp-ai-wpoos' ),
			'healthcare_advisor'       => __( 'Healthcare Advisor', 'mcp-ai-wpoos' ),
			'marketing_consultant'     => __( 'Marketing Consultant', 'mcp-ai-wpoos' ),
			'hr_consultant'            => __( 'HR Consultant', 'mcp-ai-wpoos' ),
			'it_consultant'            => __( 'IT Consultant', 'mcp-ai-wpoos' ),
			'restaurant_consultant'    => __( 'Restaurant Consultant', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get region options.
	 *
	 * @return array Region key => label pairs.
	 */
	private function get_regions() {
		return array(
			'united_states'        => __( 'United States', 'mcp-ai-wpoos' ),
			'canada'               => __( 'Canada', 'mcp-ai-wpoos' ),
			'united_kingdom'       => __( 'United Kingdom', 'mcp-ai-wpoos' ),
			'australia'            => __( 'Australia', 'mcp-ai-wpoos' ),
			'jamaica'              => __( 'Jamaica', 'mcp-ai-wpoos' ),
			'sri_lanka'            => __( 'Sri Lanka', 'mcp-ai-wpoos' ),
			'india'                => __( 'India', 'mcp-ai-wpoos' ),
			'singapore'            => __( 'Singapore', 'mcp-ai-wpoos' ),
			'united_arab_emirates' => __( 'United Arab Emirates', 'mcp-ai-wpoos' ),
			'germany'              => __( 'Germany', 'mcp-ai-wpoos' ),
			'france'               => __( 'France', 'mcp-ai-wpoos' ),
			'spain'                => __( 'Spain', 'mcp-ai-wpoos' ),
			'italy'                => __( 'Italy', 'mcp-ai-wpoos' ),
			'netherlands'          => __( 'Netherlands', 'mcp-ai-wpoos' ),
			'brazil'               => __( 'Brazil', 'mcp-ai-wpoos' ),
			'mexico'               => __( 'Mexico', 'mcp-ai-wpoos' ),
			'south_africa'         => __( 'South Africa', 'mcp-ai-wpoos' ),
			'new_zealand'          => __( 'New Zealand', 'mcp-ai-wpoos' ),
			'ireland'              => __( 'Ireland', 'mcp-ai-wpoos' ),
			'japan'                => __( 'Japan', 'mcp-ai-wpoos' ),
			'china'                => __( 'China', 'mcp-ai-wpoos' ),
			'global'               => __( 'Global', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Render the Configuration tab content.
	 */
	private function render_configuration_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-configuration-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Assistant Configuration', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the basic settings for your AI assistant.', 'mcp-ai-wpoos' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-format-chat"></span>
						<h3><?php esc_html_e( 'Create from Template', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Create a new assistant using a professional template with pre-configured settings.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-add-assistant' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Use Template', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-plus-alt"></span>
						<h3><?php esc_html_e( 'Create Custom', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Create a new custom assistant from scratch with your own configuration.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Add New', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Manage Assistants', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'View and manage all existing AI assistants in your system.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Quick Statistics', 'mcp-ai-wpoos' ); ?></h2>
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
				<h2><?php esc_html_e( 'Advanced Settings', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Advanced configuration options for power users.', 'mcp-ai-wpoos' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'Professional Templates', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Manage professional templates that define roles, tools, and knowledge bases for assistants.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Templates', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-groups"></span>
						<h3><?php esc_html_e( 'Teams', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Create teams of assistants that can work together on complex tasks.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Teams', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tools & Features', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Configure available tools and features that assistants can use.', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Tools', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-generic"></span>
						<h3><?php esc_html_e( 'AI Providers', 'mcp-ai-wpoos' ); ?></h3>
						<p><?php esc_html_e( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Gemini, etc.).', 'mcp-ai-wpoos' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Providers', 'mcp-ai-wpoos' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL to documentation */
						esc_html__( 'For detailed documentation on building and configuring assistants, visit the %s.', 'mcp-ai-wpoos' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ) . '">' . esc_html__( 'Overview page', 'mcp-ai-wpoos' ) . '</a>'
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
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Assistants', 'mcp-ai-wpoos' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_professions ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Professional Templates', 'mcp-ai-wpoos' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_teams ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Teams', 'mcp-ai-wpoos' ); ?></span>
			</div>
		</div>
		<?php
	}
}
