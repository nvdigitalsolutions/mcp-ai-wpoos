<?php
/**
 * Shortcode renderer for the front-end chat interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Registers the [mcp_ai_chat] shortcode and enqueues frontend assets.
 */
class WP_MCP_AI_Shortcode {
	const SHORTCODE = 'mcp_ai_chat';

	/**
	 * Script handle for the chat interface.
	 */
	const SCRIPT_HANDLE = 'wp-mcp-ai-chat';

	/**
	 * Style handle for the chat interface.
	 */
	const STYLE_HANDLE = 'wp-mcp-ai-chat';

	/**
	 * Lifetime for guest access tokens (in seconds).
	 */
	const GUEST_TOKEN_TTL = HOUR_IN_SECONDS;

	/**
	 * Prefix used for guest access transients.
	 */
	const GUEST_TOKEN_TRANSIENT_PREFIX = 'wp_mcp_ai_guest_access_';

	/**
	 * Default async tool timeout in seconds (5 minutes).
	 * Used when not configured in admin settings.
	 */
	const ASYNC_TOOL_TIMEOUT_DEFAULT = 300;

	/**
	 * Minimum async tool timeout in seconds (1 minute).
	 * Must match the 'min' value in the admin settings field.
	 */
	const ASYNC_TOOL_TIMEOUT_MIN = 60;

	/**
	 * Bootstraps hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		add_action( 'enqueue_block_assets', array( $this, 'maybe_enqueue_style_for_block_themes' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register assets used by the shortcode.
	 *
	 * Uses a bundled JavaScript file (chat-bundle.js) that combines all chat-related
	 * services into a single optimized file. This reduces HTTP requests from 9 files
	 * to just 1 file, improving page load performance.
	 *
	 * The bundle includes:
	 * - sse-service.js (Server-Sent Events)
	 * - job-event-bus.js (event coordination)
	 * - cron-status-service.js (async job status)
	 * - chat-storage-service.js (localStorage)
	 * - chat-clipboard-service.js (copy functionality)
	 * - chat-markdown-service.js (markdown rendering)
	 * - chat-ui-utilities-service.js (DOM helpers)
	 * - chat-audio-service.js (TTS/transcription)
	 * - chat.js (main chat application)
	 */
	public function register_assets() {
		// Skip script localization in Elementor editor to prevent JavaScript conflicts.
		// Styles and script registration will proceed, but localization (which can cause conflicts) is skipped.
		$is_elementor_editor = $this->is_elementor_editor_init();

		// Use bundled JavaScript file that combines all chat services.
		// The chat-bundle.js is an entry point for esbuild with ES6 imports,.
		// so we must load the bundled output (chat-bundle.min.js) which is browser-compatible.
		$script_relative            = 'assets/js/chat-bundle.min.js';
		$style_relative             = 'assets/css/chat.css';
		$cron_status_style_relative = 'assets/css/cron-status.css';

		$script_path            = WP_MCP_AI_URL . $script_relative;
		$style_path             = WP_MCP_AI_URL . $style_relative;
		$cron_status_style_path = WP_MCP_AI_URL . $cron_status_style_relative;

		$script_version            = $this->get_asset_version( $script_relative );
		$style_version             = $this->get_asset_version( $style_relative );
		$cron_status_style_version = $this->get_asset_version( $cron_status_style_relative );

		// Register cron status styles (still needed for CSS)
		wp_register_style(
			'wp-mcp-ai-cron-status',
			$cron_status_style_path,
			array(),
			$cron_status_style_version
		);

		wp_register_style(
			self::STYLE_HANDLE,
			$style_path,
			array( 'wp-mcp-ai-cron-status' ), // Depend on cron status CSS.
			$style_version
		);

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$color_css = WP_MCP_AI_Admin_Settings::get_chat_color_css();

			if ( $color_css ) {
				wp_add_inline_style( self::STYLE_HANDLE, $color_css );
			}
		}

		// Register bundled chat script (includes all services in a single file)
		// This replaces the previous 8 separate script registrations with 1 bundled file.
		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array(), // No dependencies - all services are bundled together
			$script_version,
			true
		);

		// Skip localization in Elementor editor to prevent JavaScript conflicts.
		if ( $is_elementor_editor ) {
			return;
		}

		// Get plugin settings for cost display configuration.
		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$show_usage_costs = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;

		// Allow filtering of cost display setting.
		$show_usage_costs = apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, get_current_user_id() );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ),
				'uploadEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'asyncToolTimeout'    => self::get_async_tool_timeout_ms( $settings ),
				'strings'             => array(
					'placeholder'                   => __( 'Ask something…', 'wp-mcp-ai' ),
					'send'                          => __( 'Send', 'wp-mcp-ai' ),
					'build'                         => __( 'Build', 'wp-mcp-ai' ),
					'building'                      => __( 'Building assistant…', 'wp-mcp-ai' ),
					'buildSuccess'                  => __( 'Assistant created successfully!', 'wp-mcp-ai' ),
					'buildError'                    => __( 'Failed to create assistant. Please try again.', 'wp-mcp-ai' ),
					'bundlingMessages'              => __( 'Preparing to send…', 'wp-mcp-ai' ),
					'sending'                       => __( 'Sending message…', 'wp-mcp-ai' ),
					'waiting'                       => __( 'Waiting for the assistant…', 'wp-mcp-ai' ),
					'error'                         => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
					'missingAssistant'              => __( 'Assistant configuration was not found.', 'wp-mcp-ai' ),
					'notAuthorized'                 => __( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ),
					/* translators: %s: tool name being executed */
																'toolExecuting' => __( 'Running tool: %s', 'wp-mcp-ai' ),
					'toolSuccess'                   => __( 'Tool completed successfully.', 'wp-mcp-ai' ),
					'toolError'                     => __( 'The tool request failed.', 'wp-mcp-ai' ),
					'toolQueued'                    => __( 'Tool queued. Results will appear shortly.', 'wp-mcp-ai' ),
					'toolPolling'                   => __( 'Tool is processing…', 'wp-mcp-ai' ),
					'toolTimeout'                   => __( 'Tool timed out before completing.', 'wp-mcp-ai' ),
					/* translators: %s: tool failure error message */
					'toolFailed'                    => __( 'Tool failed: %s', 'wp-mcp-ai' ),
					'speechToolSuccess'             => __( 'Speech audio saved to the Media Library.', 'wp-mcp-ai' ),
					'imageToolSuccess'              => __( 'Image saved to the Media Library.', 'wp-mcp-ai' ),
					/* translators: %s: task name */
																'toolShortcutLabel' => __( 'Insert task: %s', 'wp-mcp-ai' ),
					'emptyMessage'                  => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
					'attachFile'                    => __( 'Attach file', 'wp-mcp-ai' ),
					'transcribe'                    => __( 'Transcribe', 'wp-mcp-ai' ),
					'transcribeAudio'               => __( 'Transcribe audio', 'wp-mcp-ai' ),
					'transcribing'                  => __( 'Transcribing audio…', 'wp-mcp-ai' ),
					'recording'                     => __( 'Recording… tap to stop.', 'wp-mcp-ai' ),
					'stopRecording'                 => __( 'Stop recording', 'wp-mcp-ai' ),
					'recordingError'                => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'wp-mcp-ai' ),
					'transcriptionError'            => __( 'The transcription request failed. Please try again.', 'wp-mcp-ai' ),
					/* translators: %s: file name */
																'transcriptionSuccess' => __( 'Inserted transcription from “%s”.', 'wp-mcp-ai' ),
					'transcriptionFileTooLarge'     => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'wp-mcp-ai' ),
					'transcribeChooseSource'        => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'wp-mcp-ai' ),
					'attachmentsLabel'              => __( 'Attachments', 'wp-mcp-ai' ),
					'removeAttachment'              => __( 'Remove', 'wp-mcp-ai' ),
					/* translators: %s: file name being uploaded */
																'uploadingFile' => __( 'Uploading “%s”…', 'wp-mcp-ai' ),
					'uploadError'                   => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
					'uploadInProgress'              => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
					'downloadAttachment'            => __( 'Download attachment', 'wp-mcp-ai' ),
					/* translators: %s: file name with unsupported type */
																'unsupportedFileType' => __( '“%s” is not a supported file type. Please choose a different file.', 'wp-mcp-ai' ),
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
					/* translators: %d: number of messages in chat history */
																'historyMessageCount' => __( '%d messages', 'wp-mcp-ai' ),
					'historySingleMessage'          => __( '1 message', 'wp-mcp-ai' ),
					/* translators: %s: conversation identifier */
					'historyPreviewFallback'        => __( 'Conversation %s', 'wp-mcp-ai' ),
					'historySessionLoading'         => __( 'Loading conversation…', 'wp-mcp-ai' ),
					'historySessionError'           => __( 'Unable to load this conversation. Please try again.', 'wp-mcp-ai' ),
					'historyNoMessages'             => __( 'No messages were saved for this conversation.', 'wp-mcp-ai' ),
					'savingPost'                    => __( 'Saving post…', 'wp-mcp-ai' ),
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
					'videoGenerating'               => __( 'Video generation started. Your video will be available within approximately 5 minutes.', 'wp-mcp-ai' ),
					'videoPending'                  => __( 'Pending • ~5 min', 'wp-mcp-ai' ),
					'geminiImageToolSuccess'        => __( 'Gemini image saved to the Media Library.', 'wp-mcp-ai' ),
					'editGeminiImageToolSuccess'    => __( 'Gemini image edited and saved to the Media Library.', 'wp-mcp-ai' ),
					'tokensLabel'                   => __( 'Tokens', 'wp-mcp-ai' ),
					'costLabel'                     => __( 'Cost', 'wp-mcp-ai' ),
					'estimatedCostLabel'            => __( 'Est. Cost', 'wp-mcp-ai' ),
					/* translators: %d: number of tokens used */
					'tokensUsed'                    => __( '%d tokens', 'wp-mcp-ai' ),
					/* translators: %d: total tokens, %d: input tokens, %d: output tokens */
					'tokensBreakdown'               => __( '%1$d total (%2$d in / %3$d out)', 'wp-mcp-ai' ),
					/* translators: %s: cost in USD */
					'costAmount'                    => __( '$%s', 'wp-mcp-ai' ),
					'roleLabels'                    => array(
						'assistant' => __( 'Assistant', 'wp-mcp-ai' ),
						'user'      => __( 'You', 'wp-mcp-ai' ),
						'system'    => __( 'System', 'wp-mcp-ai' ),
						'tool'      => __( 'Tool', 'wp-mcp-ai' ),
					),
				),
			)
		);
	}

	/**
	 * Determine the version string for an asset, using the file modification time when available.
	 *
	 * Falls back to the plugin version when the asset does not exist on disk.
	 *
	 * @param string $relative_path Asset path relative to the plugin root.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
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
	 * Ensure block themes and the Site Editor receive the base styles when editing.
	 */
	public function maybe_enqueue_style_for_block_themes() {
		if ( is_admin() && wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::STYLE_HANDLE );
		}
	}

	/**
	 * Check if currently in Elementor editor mode.
	 *
	 * @return bool True if in Elementor editor mode, false otherwise.
	 */
	protected function is_elementor_editor() {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$elementor = \Elementor\Plugin::instance();
			if ( $elementor && $elementor->editor && $elementor->editor->is_edit_mode() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if currently in Elementor editor context during early initialization.
	 *
	 * This method checks for Elementor editor context when Elementor may not be fully loaded yet.
	 * Use during init or earlier hooks when Elementor\Plugin may not be available.
	 *
	 * @return bool True if in Elementor editor mode, false otherwise.
	 */
	protected function is_elementor_editor_init() {
		// Check via GET parameter (Elementor uses 'action=elementor' for editor).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor handles its own nonce verification.
		if ( isset( $_GET['action'] ) && 'elementor' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			return true;
		}

		// Fallback to full check if Elementor is loaded.
		return $this->is_elementor_editor();
	}

	/**
	 * Render the editor preview notice for Elementor editor.
	 *
	 * @return string HTML for the editor preview notice.
	 */
	protected function render_editor_notice() {
		ob_start();
		?>
		<div class="wp-mcp-ai-chat__editor-notice">
			<strong><?php esc_html_e( 'Editor Preview:', 'wp-mcp-ai' ); ?></strong>
			<?php esc_html_e( 'This is a preview of the chat widget. Full functionality will be available on the published page.', 'wp-mcp-ai' ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the chat shortcode.
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Content (unused).
	 * @param string $tag     Shortcode tag.
	 *
	 * @return string
	 */
	/**
	 * Render the shortcode output.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string       $content Shortcode content.
	 * @param string       $tag     Shortcode tag name.
	 * @return string Rendered HTML output.
	 */
	public function render_shortcode( $atts, $content = '', $tag = '' ) {
		try {
			$atts = shortcode_atts(
				array(
					'assistant'             => '',
					'allow_guests'          => 'false',
					'save_transcript'       => 'true',
					'enable_streaming'      => 'true',
					'allow_sensitive_tools' => 'false',
				),
				$atts,
				$tag
			);

			$assistant_id          = self::resolve_assistant_id( $atts['assistant'] );
			$allow_guests          = wp_validate_boolean( $atts['allow_guests'] );
			$save_transcript       = wp_validate_boolean( $atts['save_transcript'] );
			$enable_streaming      = wp_validate_boolean( $atts['enable_streaming'] );
			$allow_sensitive_tools = wp_validate_boolean( $atts['allow_sensitive_tools'] );

			// Fetch settings once for use throughout this method.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( ! $assistant_id ) {
				$assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
			}

			if ( ! $assistant_id ) {
				WP_MCP_AI_Logger::log_warning(
					'Shortcode rendered without valid assistant ID',
					array(
						'attributes' => $atts,
						'context'    => 'shortcode_rendering',
					)
				);
				return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'No assistant has been selected. Please provide an assistant attribute or configure a default.', 'wp-mcp-ai' ) . '</div>';
			}

			$assistant = get_post( $assistant_id );
			if ( ! $assistant || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
				WP_MCP_AI_Logger::log_error(
					'Shortcode attempted to render unavailable assistant',
					array(
						'assistant_id'     => $assistant_id,
						'assistant_exists' => (bool) $assistant,
						'post_type'        => $assistant ? $assistant->post_type : null,
						'post_status'      => $assistant ? $assistant->post_status : null,
						'attributes'       => $atts,
					)
				);
				return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'The requested assistant is not available.', 'wp-mcp-ai' ) . '</div>';
			}

			$guest_token = '';
			if ( $allow_guests ) {
				$guest_token = self::generate_guest_token( $assistant_id );
			}

			// Use the effective capability (per-assistant or global).
			$capability = function_exists( 'wp_mcp_ai_get_effective_chat_capability' )
				? wp_mcp_ai_get_effective_chat_capability( $assistant_id, 'shortcode' )
				: wp_mcp_ai_get_required_chat_capability( $assistant_id, 'shortcode' );

			if ( $guest_token ) {
				$capability = 'public';
			}

			if ( $capability && 'public' !== $capability && ! current_user_can( $capability ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Shortcode access denied due to insufficient capability',
					array(
						'assistant_id'        => $assistant_id,
						'required_capability' => $capability,
						'user_id'             => get_current_user_id(),
						'user_capabilities'   => wp_get_current_user()->allcaps ?? array(),
					)
				);
				return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ) . '</div>';
			}

			// Render the actual widget in Elementor editor for better preview.
			// The WP_DEBUG fix in the main plugin class ensures debug output.
			// won't break the editor when WP_DEBUG is enabled.
			$is_elementor_editor = $this->is_elementor_editor();

			if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
				$this->register_assets();
			}

			wp_enqueue_script( self::SCRIPT_HANDLE );
			wp_enqueue_style( self::STYLE_HANDLE );

			$instance_id = wp_unique_id( 'wp-mcp-ai-chat-' );
			$textarea_id = $instance_id . '-input';
			$session_key = wp_generate_uuid4();

			if ( ! $session_key ) {
				$session_key = wp_unique_id( 'wp-mcp-ai-session-' );
			}

			$session_key = sanitize_key( $session_key );

			$can_upload_attachments = current_user_can( 'upload_files' );

			$assistant_content = get_post_field( 'post_content', $assistant_id );
			if ( $assistant_content ) {
				$assistant_content = apply_filters( 'the_content', $assistant_content );
			}

			$config = array(
				'id'                    => $instance_id,
				'assistantId'           => $assistant_id,
				'userId'                => get_current_user_id(),
				'restUrl'               => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ),
				'messagesEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-client' ) ) ),
				'toolsEndpoint'         => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'filesEndpoint'         => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'transcriptsEndpoint'   => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'crawl4aiTaskEndpoint'  => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/crawl4ai/task' ) ) ) ),
				'crawl4aiDefaultPollMs' => 5000,
				'requiredCapability'    => $capability ? $capability : '',
				'allowGuests'           => (bool) $allow_guests,
				'canUploadAttachments'  => (bool) $can_upload_attachments,
				'saveTranscript'        => (bool) $save_transcript,
				'enableStreaming'       => (bool) $enable_streaming,
				'allowSensitiveTools'   => (bool) $allow_sensitive_tools,
				'sessionKey'            => $session_key,
				'historyPerPage'        => 20,
				'restNonce'             => wp_create_nonce( 'wp_rest' ),
			);

			// Add async tool timeout using helper method (reuses $settings already fetched).
			$config['asyncToolTimeout'] = self::get_async_tool_timeout_ms( $settings );

			$tool_shortcuts = self::get_assistant_tool_shortcuts( $assistant_id );
			if ( ! empty( $tool_shortcuts ) ) {
				$config['toolShortcuts'] = $tool_shortcuts;
			}

			if ( $can_upload_attachments && class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
				$allowed_mime_sets   = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
				$allowed_image_mimes = isset( $allowed_mime_sets['image'] ) ? (array) $allowed_mime_sets['image'] : array();
				$allowed_file_mimes  = isset( $allowed_mime_sets['file'] ) ? (array) $allowed_mime_sets['file'] : array();
				$allowed_extensions  = self::get_allowed_extensions_for_mimes( array_merge( $allowed_image_mimes, $allowed_file_mimes ) );
				$file_accept_tokens  = self::build_file_accept_tokens( $allowed_image_mimes, $allowed_file_mimes, $allowed_extensions );

				if ( ! empty( $allowed_image_mimes ) ) {
					$config['allowedImageMimes'] = array_values( $allowed_image_mimes );
				}

				if ( ! empty( $allowed_file_mimes ) ) {
					$config['allowedFileMimes'] = array_values( $allowed_file_mimes );
				}

				if ( ! empty( $allowed_extensions ) ) {
					$config['allowedExtensions'] = array_values( $allowed_extensions );
				}

				if ( ! empty( $file_accept_tokens ) ) {
					$config['fileAccept'] = implode( ',', $file_accept_tokens );
				}
			}

			if ( $guest_token ) {
				$config['guestToken'] = $guest_token;
			}

			$inline_config  = 'window.wpMcpAiChatInstances = window.wpMcpAiChatInstances || {};';
			$inline_config .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $instance_id ) . '] = ' . wp_json_encode( $config ) . ';';
			wp_add_inline_script( self::SCRIPT_HANDLE, $inline_config, 'before' );

			ob_start();
			$messages_id = $instance_id . '-messages';
			?>
		<div class="wp-mcp-ai-chat" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-chat>
			<?php
			if ( $is_elementor_editor ) {
				echo $this->render_editor_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<div class="wp-mcp-ai-chat__assistant">
				<label class="wp-mcp-ai-chat__label" for="<?php echo esc_attr( $textarea_id ); ?>">
					<?php echo esc_html( get_the_title( $assistant_id ) ); ?>
				</label>
				<?php if ( $assistant_content ) : ?>
					<div class="wp-mcp-ai-chat__assistant-content">
						<?php echo $assistant_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="wp-mcp-ai-chat__transcript-controls">
				<button
					type="button"
					class="wp-mcp-ai-chat__transcript-toggle"
					aria-expanded="false"
					aria-label="<?php echo esc_attr__( 'Expand conversation', 'wp-mcp-ai' ); ?>"
				>
					<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />
					</svg>
					<span class="screen-reader-text"><?php esc_html_e( 'Expand conversation', 'wp-mcp-ai' ); ?></span>
				</button>
			</div>
			<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>
			<form class="wp-mcp-ai-chat__form" data-instance-id="<?php echo esc_attr( $instance_id ); ?>">
				<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>
				<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>
					<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false" aria-controls="<?php echo esc_attr( $instance_id . '-tool-shortcuts' ); ?>">
						<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text"><?php esc_html_e( 'Quick Tasks', 'wp-mcp-ai' ); ?></span>
						<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />
						</svg>
					</button>
					<div id="<?php echo esc_attr( $instance_id . '-tool-shortcuts' ); ?>" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" aria-label="<?php echo esc_attr__( 'Assistant tool tasks', 'wp-mcp-ai' ); ?>" hidden></div>
				</div>
				<textarea id="<?php echo esc_attr( $textarea_id ); ?>" class="wp-mcp-ai-chat__input" rows="4" placeholder="<?php echo esc_attr__( 'Ask something…', 'wp-mcp-ai' ); ?>" required></textarea>
				<div class="wp-mcp-ai-chat__attachments" hidden>
					<div class="wp-mcp-ai-chat__attachments-header"><?php esc_html_e( 'Attachments', 'wp-mcp-ai' ); ?></div>
					<ul class="wp-mcp-ai-chat__attachments-list"></ul>
				</div>
				<div class="wp-mcp-ai-chat__actions">
					<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />
					<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden<?php echo $can_upload_attachments ? '' : ' disabled'; ?> />
					<button type="button" class="wp-mcp-ai-chat__voice-chat" aria-label="<?php echo esc_attr__( 'Voice chat', 'wp-mcp-ai' ); ?>"<?php echo $can_upload_attachments ? '' : ' disabled hidden'; ?>>
						<svg class="wp-mcp-ai-chat__voice-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>
							<circle cx="12" cy="12" r="1.5" fill="currentColor"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Voice chat', 'wp-mcp-ai' ); ?></span>
					</button>
					<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="<?php echo esc_attr__( 'Transcribe audio', 'wp-mcp-ai' ); ?>"<?php echo $can_upload_attachments ? '' : ' disabled hidden'; ?>>
						<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>
							<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Transcribe audio', 'wp-mcp-ai' ); ?></span>
					</button>
					<button type="button" class="wp-mcp-ai-chat__attach">
						<?php esc_html_e( 'Attach file', 'wp-mcp-ai' ); ?>
					</button>
					<button type="button" class="wp-mcp-ai-chat__build" hidden>
						<?php esc_html_e( 'Build', 'wp-mcp-ai' ); ?>
					</button>
					<button type="submit" class="wp-mcp-ai-chat__submit">
						<?php esc_html_e( 'Send', 'wp-mcp-ai' ); ?>
					</button>
				</div>
			</form>
			<div class="wp-mcp-ai-chat__controls">
				<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>
				<div class="wp-mcp-ai-chat__cron-status" role="status" aria-live="polite" aria-atomic="true" hidden>
					<span class="wp-mcp-ai-chat__cron-status-label"><?php esc_html_e( 'Jobs:', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-chat__cron-status-pending" title="<?php esc_attr_e( 'Pending jobs', 'wp-mcp-ai' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
					<span class="wp-mcp-ai-chat__cron-status-completed" title="<?php esc_attr_e( 'Completed jobs', 'wp-mcp-ai' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
				</div>
				<div class="wp-mcp-ai-chat__control-buttons">
					<button
						type="button"
						class="wp-mcp-ai-chat__save"
						aria-label="<?php echo esc_attr__( 'Save conversation', 'wp-mcp-ai' ); ?>"
						title="<?php echo esc_attr__( 'Save conversation', 'wp-mcp-ai' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z" />
							<path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Save conversation', 'wp-mcp-ai' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__export"
						aria-label="<?php echo esc_attr__( 'Export conversation', 'wp-mcp-ai' ); ?>"
						title="<?php echo esc_attr__( 'Export conversation', 'wp-mcp-ai' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z" />
							<path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z" />
							<path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Export conversation', 'wp-mcp-ai' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__history-toggle"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $instance_id ); ?>-history"
						aria-label="<?php echo esc_attr__( 'Show previous conversations', 'wp-mcp-ai' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z" />
							<path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Show previous conversations', 'wp-mcp-ai' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__new-chat"
						aria-label="<?php echo esc_attr__( 'Start new conversation', 'wp-mcp-ai' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Start new conversation', 'wp-mcp-ai' ); ?></span>
					</button>
				</div>
			</div>
			<section class="wp-mcp-ai-chat__history" id="<?php echo esc_attr( $instance_id ); ?>-history" hidden aria-label="<?php esc_attr_e( 'Previous conversations', 'wp-mcp-ai' ); ?>">
				<div class="wp-mcp-ai-chat__history-header">
					<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="<?php echo esc_attr__( 'Refresh conversation history', 'wp-mcp-ai' ); ?>" title="<?php echo esc_attr__( 'Refresh conversation history', 'wp-mcp-ai' ); ?>">
						<svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Refresh conversation history', 'wp-mcp-ai' ); ?></span>
					</button>
				</div>
				<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>
				<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>
				<button type="button" class="wp-mcp-ai-chat__history-load-more" hidden>
					<?php esc_html_e( 'Load More', 'wp-mcp-ai' ); ?>
				</button>
			</section>
		</div>
			<?php
			return ob_get_clean();

		} catch ( Exception $e ) {
			// Catch any unexpected errors during shortcode rendering.
			WP_MCP_AI_Logger::log_critical(
				'Unexpected error rendering chat shortcode',
				array(
					'exception_message' => $e->getMessage(),
					'exception_code'    => $e->getCode(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
					'attributes'        => $atts,
				)
			);

			// Return user-friendly error message.
			return '<div class="wp-mcp-ai-chat__notice wp-mcp-ai-chat__error">' .
				esc_html__( 'Unable to load the chat interface. Please try refreshing the page or contact support if the problem persists.', 'wp-mcp-ai' ) .
				'</div>';
		}
	}

	/**
	 * Resolve the assistant identifier provided via shortcode attributes.
	 *
	 * Accepts numeric IDs or assistant slugs and gracefully falls back when
	 * the supplied value cannot be resolved.
	 *
	 * @param mixed $assistant Assistant shortcode attribute value.
	 * @return int Assistant post ID when available, otherwise 0.
	 */
	public static function resolve_assistant_id( $assistant ) {
		$assistant = is_scalar( $assistant ) ? trim( (string) $assistant ) : '';

		if ( '' === $assistant ) {
			return 0;
		}

		$maybe_id = absint( $assistant );
		if ( $maybe_id ) {
			$assistant_post = get_post( $maybe_id );
			if ( $assistant_post && WP_MCP_AI_Assistant_CPT::POST_TYPE === $assistant_post->post_type ) {
				return $maybe_id;
			}
		}

		$slug_candidates = array( $assistant );

		if ( function_exists( 'sanitize_title' ) ) {
			$sanitized = sanitize_title( $assistant );
			if ( $sanitized && $sanitized !== $assistant ) {
				$slug_candidates[] = $sanitized;
			}
		}

		foreach ( array_unique( $slug_candidates ) as $slug ) {
			$assistant_post = get_page_by_path( $slug, OBJECT, WP_MCP_AI_Assistant_CPT::POST_TYPE );
			if ( $assistant_post ) {
				return (int) $assistant_post->ID;
			}
		}

		return 0;
	}

	/**
	 * Generate a guest access token for the given assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string Guest access token or empty string on failure.
	 */
	public static function generate_guest_token( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return '';
		}

		$token = wp_generate_password( 32, false, false );

		if ( ! $token ) {
			return '';
		}

		$record = array(
			'assistant_id' => $assistant_id,
			'created'      => time(),
		);

		$saved = set_transient( self::build_guest_token_key( $token ), $record, self::GUEST_TOKEN_TTL );

		if ( ! $saved ) {
			return '';
		}

		return $token;
	}

	/**
	 * Validate a guest token and ensure it is scoped to the requested assistant.
	 *
	 * @param string $token        Guest access token supplied by the client.
	 * @param int    $assistant_id Assistant post ID provided in the request.
	 * @return int|false Assistant ID associated with the token when valid, false otherwise.
	 */
	public static function validate_guest_token( $token, $assistant_id = 0 ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return false;
		}

		$data = get_transient( self::build_guest_token_key( $token ) );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		$stored_assistant = isset( $data['assistant_id'] ) ? absint( $data['assistant_id'] ) : 0;

		if ( $assistant_id && $stored_assistant && $assistant_id !== $stored_assistant ) {
			return false;
		}

		set_transient( self::build_guest_token_key( $token ), $data, self::GUEST_TOKEN_TTL );

		return $stored_assistant;
	}

	/**
	 * Build the transient key used to persist guest access tokens.
	 *
	 * @param string $token Guest access token.
	 * @return string
	 */
	protected static function build_guest_token_key( $token ) {
		return self::GUEST_TOKEN_TRANSIENT_PREFIX . md5( $token );
	}

	/**
	 * Get async tool timeout in milliseconds from settings.
	 *
	 * This helper ensures consistent timeout calculation across all contexts
	 * (shortcode, admin test pages, etc.).
	 *
	 * @param array|null $settings Optional pre-fetched settings array.
	 * @return int Timeout in milliseconds (default 300000ms / 5 minutes).
	 */
	public static function get_async_tool_timeout_ms( $settings = null ) {
		if ( null === $settings ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
		}

		$async_timeout_seconds = isset( $settings['async_tool_timeout'] ) ? absint( $settings['async_tool_timeout'] ) : self::ASYNC_TOOL_TIMEOUT_DEFAULT;
		return max( self::ASYNC_TOOL_TIMEOUT_MIN, $async_timeout_seconds ) * 1000;
	}

	/**
	 * Retrieve tool shortcut metadata for the supplied assistant.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array[]
	 */
	public static function get_assistant_tool_shortcuts( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$config             = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$shortcuts          = array();
		$selected_tools     = array();
		$prebuilt_overrides = array();

		if ( ! empty( $config['tools'] ) && is_array( $config['tools'] ) ) {
			foreach ( $config['tools'] as $tool_slug ) {
				$tool_slug = sanitize_key( $tool_slug );

				if ( '' === $tool_slug ) {
					continue;
				}

				$selected_tools[] = $tool_slug;
			}

			$selected_tools = array_values( array_unique( $selected_tools ) );
		}

		if ( ! empty( $config['tool_shortcuts'] ) && is_array( $config['tool_shortcuts'] ) ) {
			$custom_shortcuts = $config['tool_shortcuts'];

			if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'sanitize_tool_shortcuts_meta' ) ) {
				$custom_shortcuts = WP_MCP_AI_Assistant_CPT::sanitize_tool_shortcuts_meta( $custom_shortcuts );
			}

			/**
			 * Filter the list of custom prompt shortcuts configured for an assistant.
			 *
			 * @since 1.1.0
			 *
			 * @param array $custom_shortcuts Sanitized custom shortcuts.
			 * @param int   $assistant_id     Assistant post ID.
			 * @param array $config           Assistant configuration array.
			 */
			$custom_shortcuts = apply_filters( 'wp_mcp_ai_assistant_custom_tool_shortcuts', $custom_shortcuts, $assistant_id, $config );

			if ( is_array( $custom_shortcuts ) ) {
				foreach ( $custom_shortcuts as $custom_shortcut ) {
					if ( ! is_array( $custom_shortcut ) ) {
						continue;
					}

					$label   = isset( $custom_shortcut['label'] ) ? sanitize_text_field( $custom_shortcut['label'] ) : '';
					$payload = isset( $custom_shortcut['payload'] ) ? sanitize_textarea_field( $custom_shortcut['payload'] ) : '';

					if ( '' === $label || '' === $payload ) {
						continue;
					}

					$tool_slug = '';
					if ( isset( $custom_shortcut['tool'] ) && is_string( $custom_shortcut['tool'] ) ) {
						$tool_slug = sanitize_key( $custom_shortcut['tool'] );
					}

					if ( '' !== $tool_slug && ! in_array( $tool_slug, $selected_tools, true ) ) {
						continue;
					}

					$entry = array(
						'tool'    => ( '' !== $tool_slug ) ? $tool_slug : 'custom',
						'label'   => $label,
						'payload' => $payload,
					);

					if ( isset( $custom_shortcut['description'] ) && is_string( $custom_shortcut['description'] ) ) {
						$entry['description'] = sanitize_textarea_field( $custom_shortcut['description'] );
					}

					$shortcuts[] = $entry;
				}
			}
		}

		if ( ! empty( $config['tool_prebuilt_shortcuts'] ) && is_array( $config['tool_prebuilt_shortcuts'] ) ) {
			$prebuilt_overrides = $config['tool_prebuilt_shortcuts'];

			if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'sanitize_prebuilt_tool_shortcuts_meta' ) ) {
				$prebuilt_overrides = WP_MCP_AI_Assistant_CPT::sanitize_prebuilt_tool_shortcuts_meta( $prebuilt_overrides );
			}
		}

		if ( ! empty( $config['disable_prebuilt_shortcuts'] ) ) {
			return $shortcuts;
		}

		if ( empty( $selected_tools ) ) {
			return $shortcuts;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $selected_tools as $tool_slug ) {
			$tool = $registry->get_tool( $tool_slug );

			if ( ! $tool ) {
				continue;
			}

			if ( array_key_exists( $tool_slug, $prebuilt_overrides ) ) {
				$override_settings  = $prebuilt_overrides[ $tool_slug ];
				$override_shortcuts = array();

				if ( isset( $override_settings['shortcuts'] ) && is_array( $override_settings['shortcuts'] ) ) {
					$override_shortcuts = $override_settings['shortcuts'];
				}

				if ( ! empty( $override_shortcuts ) ) {
					foreach ( $override_shortcuts as $override_shortcut ) {
						if ( ! is_array( $override_shortcut ) ) {
							continue;
						}

						$label   = isset( $override_shortcut['label'] ) ? sanitize_text_field( $override_shortcut['label'] ) : '';
						$payload = isset( $override_shortcut['payload'] ) ? sanitize_textarea_field( $override_shortcut['payload'] ) : '';

						if ( '' === $label || '' === $payload ) {
							continue;
						}

						$entry = array(
							'tool'    => $tool_slug,
							'label'   => $label,
							'payload' => $payload,
						);

						if ( isset( $override_shortcut['description'] ) && is_string( $override_shortcut['description'] ) ) {
							$entry['description'] = sanitize_textarea_field( $override_shortcut['description'] );
						}

						$shortcuts[] = $entry;
					}
				}

				continue;
			}

			$tasks         = array();
			$skip_fallback = false;

			if ( $tool instanceof WP_MCP_AI_Tool_Shortcuts_Interface ) {
				$tasks = $tool->get_shortcut_tasks();
			} elseif ( method_exists( $tool, 'get_shortcut_tasks' ) ) {
				$tasks = $tool->get_shortcut_tasks();
			}

			if ( null === $tasks ) {
				$skip_fallback = true;
			}

			$tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks', $tasks, $tool, $assistant_id );
			$tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks_' . $tool_slug, $tasks, $tool, $assistant_id );

			if ( null === $tasks ) {
				continue;
			}

			$should_register_fallback = ! $skip_fallback;

			if ( $tool instanceof WP_MCP_AI_Tool_Fallback_Shortcut_Interface ) {
				$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
			} elseif ( method_exists( $tool, 'should_register_fallback_shortcut' ) ) {
				$should_register_fallback = (bool) $tool->should_register_fallback_shortcut( $assistant_id );
			}

			/**
			 * Filter whether a fallback shortcut should be automatically appended for the tool.
			 *
			 * @since 1.0.1
			 *
			 * @param bool                     $should_register_fallback Whether the fallback shortcut should be added.
			 * @param WP_MCP_AI_Tool_Interface $tool                     Tool instance currently being processed.
			 * @param int                      $assistant_id             Assistant post ID.
			 * @param mixed                    $tasks                    Shortcut tasks supplied by the tool.
			 */
			$should_register_fallback = apply_filters(
				'wp_mcp_ai_tool_should_register_fallback_shortcut',
				$should_register_fallback,
				$tool,
				$assistant_id,
				$tasks
			);

			if ( empty( $tasks ) || ! is_array( $tasks ) ) {
				if ( ! $should_register_fallback ) {
					continue;
				}

				$shortcuts[] = array(
					'tool'    => $tool->get_slug(),
					'label'   => $tool->get_slug(),
					'payload' => $tool->get_slug(),
				);
				continue;
			}

			foreach ( $tasks as $task ) {
				if ( ! is_array( $task ) ) {
					continue;
				}

				$label   = isset( $task['label'] ) && is_string( $task['label'] ) ? sanitize_text_field( $task['label'] ) : '';
				$payload = isset( $task['payload'] ) && is_string( $task['payload'] ) ? sanitize_textarea_field( $task['payload'] ) : '';

				if ( '' === $label && '' === $payload ) {
					continue;
				}

				if ( '' === $label ) {
					$label = $tool->get_slug();
				}

				if ( '' === $payload ) {
					$payload = $tool->get_slug();
				}

				$entry = array(
					'tool'    => $tool->get_slug(),
					'label'   => $label,
					'payload' => $payload,
				);

				if ( isset( $task['description'] ) && is_string( $task['description'] ) ) {
					$entry['description'] = sanitize_textarea_field( $task['description'] );
				}

				$shortcuts[] = $entry;
			}
		}

		$shortcuts = apply_filters( 'wp_mcp_ai_assistant_tool_shortcuts', $shortcuts, $assistant_id );

		$shortcuts = array_values(
			array_filter(
				$shortcuts,
				static function ( $shortcut ) {
					if ( empty( $shortcut ) || ! is_array( $shortcut ) ) {
						return false;
					}

					if ( empty( $shortcut['label'] ) || empty( $shortcut['payload'] ) ) {
						return false;
					}

					return true;
				}
			)
		);

		$default_shortcut = array(
			'tool'    => 'default',
			'label'   => sanitize_text_field( __( 'What can you do?', 'wp-mcp-ai' ) ),
			'payload' => sanitize_textarea_field( 'what are some things you can do' ),
		);

		/**
		 * Filter the default shortcut that is appended for every assistant.
		 *
		 * @since 1.0.1
		 *
		 * @param array $default_shortcut Default shortcut configuration.
		 * @param int   $assistant_id     Assistant post ID.
		 */
		$default_shortcut = apply_filters( 'wp_mcp_ai_default_tool_shortcut', $default_shortcut, $assistant_id );

		if ( is_array( $default_shortcut ) && ! empty( $default_shortcut['label'] ) && ! empty( $default_shortcut['payload'] ) ) {
			$default_shortcut['tool'] = isset( $default_shortcut['tool'] ) && is_string( $default_shortcut['tool'] )
				? sanitize_key( $default_shortcut['tool'] )
				: 'default';

			$default_shortcut['label']   = sanitize_text_field( $default_shortcut['label'] );
			$default_shortcut['payload'] = sanitize_textarea_field( $default_shortcut['payload'] );

			if ( isset( $default_shortcut['description'] ) ) {
				if ( is_string( $default_shortcut['description'] ) ) {
					$default_shortcut['description'] = sanitize_textarea_field( $default_shortcut['description'] );
				} else {
					unset( $default_shortcut['description'] );
				}
			}

			$has_default_shortcut = false;

			foreach ( $shortcuts as $shortcut ) {
				if ( ! is_array( $shortcut ) ) {
					continue;
				}

				if ( isset( $shortcut['payload'] ) && $shortcut['payload'] === $default_shortcut['payload'] ) {
					$has_default_shortcut = true;
					break;
				}
			}

			if ( ! $has_default_shortcut ) {
				$shortcuts[] = $default_shortcut;
			}
		}

		if ( empty( $shortcuts ) ) {
			$fallback_shortcut = array(
				'tool'    => 'default',
				'label'   => sanitize_text_field( __( 'What are some things you can do?', 'wp-mcp-ai' ) ),
				'payload' => sanitize_textarea_field( 'what are some things you can do' ),
			);

			/**
			 * Filter the default shortcut shown when an assistant has no tool shortcuts configured.
			 *
			 * @since 1.0.1
			 *
			 * @param array $fallback_shortcut Default shortcut configuration.
			 * @param int   $assistant_id      Assistant post ID.
			 */
			$fallback_shortcut = apply_filters( 'wp_mcp_ai_default_tool_shortcut', $fallback_shortcut, $assistant_id );

			if ( is_array( $fallback_shortcut ) && ! empty( $fallback_shortcut['label'] ) && ! empty( $fallback_shortcut['payload'] ) ) {
				$fallback_shortcut['tool'] = isset( $fallback_shortcut['tool'] ) && is_string( $fallback_shortcut['tool'] )
					? sanitize_key( $fallback_shortcut['tool'] )
					: 'default';

				$fallback_shortcut['label']   = sanitize_text_field( $fallback_shortcut['label'] );
				$fallback_shortcut['payload'] = sanitize_textarea_field( $fallback_shortcut['payload'] );

				if ( isset( $fallback_shortcut['description'] ) ) {
					if ( is_string( $fallback_shortcut['description'] ) ) {
						$fallback_shortcut['description'] = sanitize_textarea_field( $fallback_shortcut['description'] );
					} else {
						unset( $fallback_shortcut['description'] );
					}
				}

				$shortcuts[] = $fallback_shortcut;
			}
		}

		return $shortcuts;
	}

	/**
	 * Collect unique file extensions that correspond to the supplied MIME types.
	 *
	 * @param array $allowed_mimes List of MIME types.
	 * @return array
	 */
	protected static function get_allowed_extensions_for_mimes( array $allowed_mimes ) {
		if ( empty( $allowed_mimes ) ) {
			return array();
		}

		$allowed_mimes = array_values(
			array_unique(
				array_filter(
					array_map( 'strtolower', $allowed_mimes )
				)
			)
		);

		if ( empty( $allowed_mimes ) ) {
			return array();
		}

		$extensions = array();
		$mime_map   = wp_get_mime_types();

		foreach ( $mime_map as $exts => $mime ) {
			$mime = strtolower( $mime );

			if ( ! in_array( $mime, $allowed_mimes, true ) ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $exts ) );

			foreach ( $parts as $extension ) {
				if ( '' === $extension ) {
					continue;
				}

				$extensions[] = strtolower( $extension );
			}
		}

		$custom_mime_extensions = array(
			'application/x-ndjson' => array( 'ndjson', 'jsonl' ),
			'application/jsonl'    => array( 'jsonl' ),
		);

		foreach ( $custom_mime_extensions as $mime => $custom_extensions ) {
			if ( ! in_array( $mime, $allowed_mimes, true ) ) {
				continue;
			}

			foreach ( $custom_extensions as $extension ) {
				$extension = strtolower( (string) $extension );

				if ( '' === $extension ) {
					continue;
				}

				$extensions[] = $extension;
			}
		}

		return array_values( array_unique( $extensions ) );
	}

	/**
	 * Build the tokens used for the file input accept attribute.
	 *
	 * @param array $image_mimes    Allowed image MIME types.
	 * @param array $file_mimes     Allowed file MIME types.
	 * @param array $extensions     Allowed file extensions (without dots).
	 * @return array
	 */
	protected static function build_file_accept_tokens( array $image_mimes, array $file_mimes, array $extensions ) {
		$tokens = array();

		foreach ( array_merge( $image_mimes, $file_mimes ) as $mime ) {
			$mime = strtolower( (string) $mime );

			if ( '' !== $mime ) {
				$tokens[] = $mime;
			}
		}

		foreach ( $extensions as $extension ) {
			$extension = strtolower( (string) $extension );

			if ( '' === $extension ) {
				continue;
			}

			$extension = ltrim( $extension, '.' );

			if ( '' !== $extension ) {
				$tokens[] = '.' . $extension;
			}
		}

		return array_values(
			array_unique(
				array_filter( $tokens )
			)
		);
	}
}
