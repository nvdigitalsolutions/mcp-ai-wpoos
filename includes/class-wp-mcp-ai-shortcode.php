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

		// Register cron status styles (still needed for CSS).

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

		// Register embedded LLM client scripts (always register, enqueue conditionally).
		// This prevents conflicts when multiple widgets with different providers are on the same page.
		if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
			$embedded_script_path    = WP_MCP_AI_URL . 'assets/js/embedded-llm-client.js';
			$embedded_script_version = $this->get_asset_version( 'assets/js/embedded-llm-client.js' );
			$webllm_loader_path      = WP_MCP_AI_URL . 'assets/js/webllm-loader.js';
			$webllm_loader_version   = $this->get_asset_version( 'assets/js/webllm-loader.js' );

			// Register WebLLM loader (loads WebLLM library dynamically).
			if ( ! wp_script_is( 'webllm-loader', 'registered' ) ) {
				wp_register_script(
					'webllm-loader',
					$webllm_loader_path,
					array(),
					$webllm_loader_version,
					true
				);
			}

			// Register embedded LLM client (depends on WebLLM loader).
			if ( ! wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ) ) {
				wp_register_script(
					'wp-mcp-ai-embedded-llm-client',
					$embedded_script_path,
					array( 'webllm-loader' ),
					$embedded_script_version,
					true
				);
			}

			// Register enhanced WebLLM scripts for tool calling and knowledge support.
			$tool_adapter_path        = WP_MCP_AI_URL . 'assets/js/webllm-tool-adapter.min.js';
			$tool_adapter_version     = $this->get_asset_version( 'assets/js/webllm-tool-adapter.min.js' );
			$function_calling_path    = WP_MCP_AI_URL . 'assets/js/webllm-function-calling-client.min.js';
			$function_calling_version = $this->get_asset_version( 'assets/js/webllm-function-calling-client.min.js' );

			if ( ! wp_script_is( 'wp-mcp-ai-webllm-tool-adapter', 'registered' ) ) {
				wp_register_script(
					'wp-mcp-ai-webllm-tool-adapter',
					$tool_adapter_path,
					array(),
					$tool_adapter_version,
					true
				);
			}

			if ( ! wp_script_is( 'wp-mcp-ai-webllm-function-calling', 'registered' ) ) {
				wp_register_script(
					'wp-mcp-ai-webllm-function-calling',
					$function_calling_path,
					array( 'wp-mcp-ai-embedded-llm-client', 'wp-mcp-ai-webllm-tool-adapter' ),
					$function_calling_version,
					true
				);
			}
		}

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$color_css = WP_MCP_AI_Admin_Settings::get_chat_color_css();

			if ( $color_css ) {
				wp_add_inline_style( self::STYLE_HANDLE, $color_css );
			}
		}

		// Register bundled chat script (includes all services in a single file).

		// This replaces the previous 8 separate script registrations with 1 bundled file.

		wp_register_script(
			self::SCRIPT_HANDLE,
			$script_path,
			array(), // No dependencies - all services are bundled together.
			$script_version,
			true
		);

		// Skip localization in Elementor editor to prevent JavaScript conflicts.
		if ( $is_elementor_editor ) {
			// Provide minimal localization for Elementor editor to support voice chat and file uploads.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			wp_localize_script(
				self::SCRIPT_HANDLE,
				'wpMcpAiChat',
				array(
					'restUrl'             => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
					'uploadEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
					'prepareEndpoint'     => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/attachments/prepare' ) ) ),
					'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
					'toolsEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
					'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
					'historyPerPage'      => 20,
					'maxHistoryMessages'  => isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8,
					'currentUserId'       => get_current_user_id(),
					'nonce'               => wp_create_nonce( 'wp_rest' ),
					'showUsageCosts'      => false,
					'showCapabilityFlags' => false,
					'asyncToolTimeout'    => self::get_async_tool_timeout_ms( $settings ),
					'isElementorEditor'   => true,
					'strings'             => array(
						'placeholder' => __( 'Ask something…', 'mcp-ai-wpoos' ),
					),
				)
			);
			return;
		}

		// Get plugin settings for cost display configuration.
		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$show_usage_costs = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;

		// Allow filtering of cost display setting.
		$show_usage_costs = apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, get_current_user_id() );

		// Get capability flags display setting.
		$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;

		// Allow filtering of capability flags display setting.
		$show_capability_flags = apply_filters( 'wp_mcp_ai_show_capability_flags', $show_capability_flags, get_current_user_id() );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
				'uploadEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'     => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/attachments/prepare' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'maxHistoryMessages'  => isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'showCapabilityFlags' => $show_capability_flags,
				'asyncToolTimeout'    => self::get_async_tool_timeout_ms( $settings ),
				'vadEnabled'          => isset( $settings['enable_voice_activity_detection'] ) ? (bool) $settings['enable_voice_activity_detection'] : true,
				'vadSilenceThreshold' => isset( $settings['vad_silence_threshold'] ) ? absint( $settings['vad_silence_threshold'] ) : 700,
				'vadMinSpeech'        => isset( $settings['vad_min_speech_duration'] ) ? absint( $settings['vad_min_speech_duration'] ) : 300,
				'vadAudioThreshold'   => isset( $settings['vad_audio_threshold'] ) ? floatval( $settings['vad_audio_threshold'] ) : -50,
				'strings'             => $this->get_strings(),
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
			<strong><?php esc_html_e( 'Editor Preview:', 'mcp-ai-wpoos' ); ?></strong>
			<?php esc_html_e( 'This is a preview of the chat widget. Full functionality will be available on the published page.', 'mcp-ai-wpoos' ); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Check if embedded provider is available for the assistant.
	 *
	 * @param string $provider The provider to check.
	 * @return bool True if embedded provider is available, false otherwise.
	 */
	protected function is_embedded_provider_available( $provider ) {
		return 'embedded' === $provider && defined( 'WP_MCP_AI_PRO_VERSION' );
	}

	/**
	 * Get localized strings for the chat interface.
	 *
	 * Provides all user-facing text strings for the JavaScript chat widget,
	 * including UI labels, status messages, error messages, and role labels.
	 * Strings are translatable via WordPress i18n functions.
	 *
	 * @return array Array of localized strings for JavaScript.
	 */
	protected function get_strings() {
		$strings = array(
			'placeholder'                   => __( 'Ask something…', 'mcp-ai-wpoos' ),
			'send'                          => __( 'Send', 'mcp-ai-wpoos' ),
			'build'                         => __( 'Build', 'mcp-ai-wpoos' ),
			'building'                      => __( 'Building assistant…', 'mcp-ai-wpoos' ),
			'buildSuccess'                  => __( 'Assistant created successfully!', 'mcp-ai-wpoos' ),
			'buildError'                    => __( 'Failed to create assistant. Please try again.', 'mcp-ai-wpoos' ),
			'bundlingMessages'              => __( 'Preparing to send…', 'mcp-ai-wpoos' ),
			'sending'                       => __( 'Sending message…', 'mcp-ai-wpoos' ),
			'waiting'                       => __( 'Waiting for the assistant…', 'mcp-ai-wpoos' ),
			'error'                         => __( 'Something went wrong. Please try again.', 'mcp-ai-wpoos' ),
			'missingAssistant'              => __( 'Assistant configuration was not found.', 'mcp-ai-wpoos' ),
			'notAuthorized'                 => __( 'You do not have permission to chat with this assistant.', 'mcp-ai-wpoos' ),
			/* translators: %s: tool name being executed */
			'toolExecuting'                 => __( 'Running tool: %s', 'mcp-ai-wpoos' ),
			'toolSuccess'                   => __( 'Tool completed successfully.', 'mcp-ai-wpoos' ),
			'toolError'                     => __( 'The tool request failed.', 'mcp-ai-wpoos' ),
			'toolQueued'                    => __( 'Tool queued. Results will appear shortly.', 'mcp-ai-wpoos' ),
			'toolPolling'                   => __( 'Tool is processing…', 'mcp-ai-wpoos' ),
			'toolTimeout'                   => __( 'Tool timed out before completing.', 'mcp-ai-wpoos' ),
			/* translators: %s: tool failure error message */
			'toolFailed'                    => __( 'Tool failed: %s', 'mcp-ai-wpoos' ),
			'speechToolSuccess'             => __( 'Speech audio saved to the Media Library.', 'mcp-ai-wpoos' ),
			'imageToolSuccess'              => __( 'Image saved to the Media Library.', 'mcp-ai-wpoos' ),
			/* translators: %s: task name */
			'toolShortcutLabel'             => __( 'Insert task: %s', 'mcp-ai-wpoos' ),
			'emptyMessage'                  => __( 'Enter a message before sending.', 'mcp-ai-wpoos' ),
			'attachFile'                    => __( 'Attach file', 'mcp-ai-wpoos' ),
			'transcribe'                    => __( 'Transcribe', 'mcp-ai-wpoos' ),
			'transcribeAudio'               => __( 'Transcribe audio', 'mcp-ai-wpoos' ),
			'transcribing'                  => __( 'Transcribing audio…', 'mcp-ai-wpoos' ),
			'recording'                     => __( 'Recording… tap to stop.', 'mcp-ai-wpoos' ),
			'stopRecording'                 => __( 'Stop recording', 'mcp-ai-wpoos' ),
			'recordingError'                => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'mcp-ai-wpoos' ),
			'transcriptionError'            => __( 'The transcription request failed. Please try again.', 'mcp-ai-wpoos' ),
			/* translators: %s: file name */
			'transcriptionSuccess'          => __( 'Inserted transcription from "%s".', 'mcp-ai-wpoos' ),
			'transcriptionFileTooLarge'     => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'mcp-ai-wpoos' ),
			'transcribeChooseSource'        => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'mcp-ai-wpoos' ),
			'transcriptionEndpointNotFound' => __( 'Transcription service is temporarily unavailable. Please try again later.', 'mcp-ai-wpoos' ),
			'transcriptionNotConfigured'    => __( 'Transcription is not properly configured. Please contact support.', 'mcp-ai-wpoos' ),
			'translate'                     => __( 'Translate', 'mcp-ai-wpoos' ),
			'translateAudio'                => __( 'Translate audio', 'mcp-ai-wpoos' ),
			'translating'                   => __( 'Translating audio…', 'mcp-ai-wpoos' ),
			'translationError'              => __( 'The translation request failed. Please try again.', 'mcp-ai-wpoos' ),
			/* translators: %s: file name */
			'translationSuccess'            => __( 'Inserted translation from "%s".', 'mcp-ai-wpoos' ),
			'translationFileTooLarge'       => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'mcp-ai-wpoos' ),
			'translateChooseSource'         => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'mcp-ai-wpoos' ),
			'translationEndpointNotFound'   => __( 'Translation service is temporarily unavailable. Please try again later.', 'mcp-ai-wpoos' ),
			'translationNotConfigured'      => __( 'Translation is not properly configured. Please contact support.', 'mcp-ai-wpoos' ),
			'voiceChatError'                => __( 'Voice chat failed. Please try again or type your message.', 'mcp-ai-wpoos' ),
			'voiceChatEndpointNotFound'     => __( 'Voice chat service is temporarily unavailable. Please type your message instead.', 'mcp-ai-wpoos' ),
			'voiceChatNotConfigured'        => __( 'Voice chat is not properly configured. Please type your message instead.', 'mcp-ai-wpoos' ),
			'voiceChatProcessing'           => __( 'Processing your voice message…', 'mcp-ai-wpoos' ),
			'voiceChatNoData'               => __( 'No audio was recorded.', 'mcp-ai-wpoos' ),
			'voiceChatFileTooLarge'         => __( 'The recorded audio is too large. Please try a shorter message.', 'mcp-ai-wpoos' ),
			'voiceChatPermissionDenied'     => __( 'Microphone access was denied.', 'mcp-ai-wpoos' ),
			'voiceChatRecording'            => __( 'Recording… speak now or tap to stop and send. (Hands-free: pauses auto-send)', 'mcp-ai-wpoos' ),
			'voiceChatSending'              => __( 'Sending your message…', 'mcp-ai-wpoos' ),
			'attachmentsLabel'              => __( 'Attachments', 'mcp-ai-wpoos' ),
			'removeAttachment'              => __( 'Remove', 'mcp-ai-wpoos' ),
			/* translators: %s: file name being uploaded */
			'uploadingFile'                 => __( 'Uploading "%s"…', 'mcp-ai-wpoos' ),
			/* translators: %s: file name being processed */
			'processingFile'                => __( 'Processing "%s"…', 'mcp-ai-wpoos' ),
			'uploadError'                   => __( 'The file could not be uploaded. Please try again.', 'mcp-ai-wpoos' ),
			'uploadInProgress'              => __( 'Please wait for uploads to finish before sending.', 'mcp-ai-wpoos' ),
			'downloadAttachment'            => __( 'Download attachment', 'mcp-ai-wpoos' ),
			/* translators: %s: file name with unsupported type */
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
			/* translators: %d: number of messages in chat history */
			'historyMessageCount'           => __( '%d messages', 'mcp-ai-wpoos' ),
			'historySingleMessage'          => __( '1 message', 'mcp-ai-wpoos' ),
			/* translators: %s: conversation identifier */
			'historyPreviewFallback'        => __( 'Conversation %s', 'mcp-ai-wpoos' ),
			'historySessionLoading'         => __( 'Loading conversation…', 'mcp-ai-wpoos' ),
			'historySessionError'           => __( 'Unable to load this conversation. Please try again.', 'mcp-ai-wpoos' ),
			'historyNoMessages'             => __( 'No messages were saved for this conversation.', 'mcp-ai-wpoos' ),
			'savingPost'                    => __( 'Saving post…', 'mcp-ai-wpoos' ),
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
			'videoGenerating'               => __( 'Video generation started. Your video will be available within approximately 5 minutes.', 'mcp-ai-wpoos' ),
			'videoPending'                  => __( 'Pending • ~5 min', 'mcp-ai-wpoos' ),
			'geminiImageToolSuccess'        => __( 'Gemini image saved to the Media Library.', 'mcp-ai-wpoos' ),
			'editGeminiImageToolSuccess'    => __( 'Gemini image edited and saved to the Media Library.', 'mcp-ai-wpoos' ),
			'tokensLabel'                   => __( 'Tokens', 'mcp-ai-wpoos' ),
			'costLabel'                     => __( 'Cost', 'mcp-ai-wpoos' ),
			'estimatedCostLabel'            => __( 'Est. Cost', 'mcp-ai-wpoos' ),
			/* translators: %d: number of tokens used */
			'tokensUsed'                    => __( '%d tokens', 'mcp-ai-wpoos' ),
			/* translators: %d: total tokens, %d: input tokens, %d: output tokens */
			'tokensBreakdown'               => __( '%1$d total (%2$d in / %3$d out)', 'mcp-ai-wpoos' ),
			/* translators: %s: cost in USD */
			'costAmount'                    => __( '$%s', 'mcp-ai-wpoos' ),
			/* translators: %s: tool name that is processing */
			'toolProcessing'                => __( '%s is temporarily processing your request. The assistant will continue using available information.', 'mcp-ai-wpoos' ),
			/* translators: %s: tool name that is temporarily unavailable */
			'toolTemporarilyUnavailable'    => __( '%s temporarily unavailable', 'mcp-ai-wpoos' ),
			// Embedded LLM client messages.
			'embeddedClientMissing'         => __( 'Embedded LLM client not loaded. Please refresh the page.', 'mcp-ai-wpoos' ),
			'embeddedClientInvalid'         => __( 'Embedded LLM client is not properly initialized. Please refresh the page and clear your browser cache.', 'mcp-ai-wpoos' ),
			'embeddedClientInitializing'    => __( 'Initializing embedded AI client...', 'mcp-ai-wpoos' ),
			'embeddedClientInitError'       => __( 'Failed to initialize embedded AI client: ', 'mcp-ai-wpoos' ),
			'roleLabels'                    => array(
				'assistant' => __( 'Assistant', 'mcp-ai-wpoos' ),
				'user'      => __( 'You', 'mcp-ai-wpoos' ),
				'system'    => __( 'System', 'mcp-ai-wpoos' ),
				'tool'      => __( 'Tool', 'mcp-ai-wpoos' ),
			),
		);

		/**
		 * Filter the localized strings for the chat interface.
		 *
		 * Allows developers and themes to customize the chat interface text,
		 * enabling white-label customization, brand-specific messaging, and
		 * contextual modifications without modifying core plugin code.
		 *
		 * @since 1.0.0
		 *
		 * @param array $strings Array of localized strings with keys matching
		 *                       JavaScript properties (e.g., 'placeholder', 'send', 'error').
		 *
		 * @example
		 * ```php
		 * add_filter( 'wp_mcp_ai_chat_strings', function( $strings ) {
		 *     $strings['placeholder'] = 'How can we assist you today?';
		 *     $strings['error'] = 'Something went wrong. Please contact support.';
		 *     return $strings;
		 * });
		 * ```
		 */
		return apply_filters( 'wp_mcp_ai_chat_strings', $strings );
	}

	/**
	 * Apply script localization to the chat script handle.
	 * This method centralizes the localization logic to avoid duplication.
	 *
	 * @param array $settings Plugin settings array.
	 * @return void
	 */
	protected function apply_script_localization( $settings ) {
		$show_usage_costs      = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;
		$show_usage_costs      = apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, get_current_user_id() );
		$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;
		$show_capability_flags = apply_filters( 'wp_mcp_ai_show_capability_flags', $show_capability_flags, get_current_user_id() );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
				'uploadEndpoint'      => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'     => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/attachments/prepare' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'maxHistoryMessages'  => isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'showCapabilityFlags' => $show_capability_flags,
				'asyncToolTimeout'    => self::get_async_tool_timeout_ms( $settings ),
				'vadEnabled'          => isset( $settings['enable_voice_activity_detection'] ) ? (bool) $settings['enable_voice_activity_detection'] : true,
				'vadSilenceThreshold' => isset( $settings['vad_silence_threshold'] ) ? absint( $settings['vad_silence_threshold'] ) : 700,
				'vadMinSpeech'        => isset( $settings['vad_min_speech_duration'] ) ? absint( $settings['vad_min_speech_duration'] ) : 300,
				'vadAudioThreshold'   => isset( $settings['vad_audio_threshold'] ) ? floatval( $settings['vad_audio_threshold'] ) : -50,
				'strings'             => $this->get_strings(),
			)
		);
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
					'profession'            => '',
					'team'                  => '', // Team ID for multi-agent coordination.
					'allow_guests'          => 'false',
					'save_transcript'       => 'true',
					'enable_streaming'      => 'true',
					'allow_sensitive_tools' => 'false',
					'template'              => 'classic',
					'cpt_actions'           => '', // JSON-encoded array of CPT action buttons.
					'additional_tools'      => '', // Comma-separated list of tool slugs to make available regardless of assistant config.
				),
				$atts,
				$tag
			);

			$assistant_id          = self::resolve_assistant_id( $atts['assistant'] );
			$allow_guests          = wp_validate_boolean( $atts['allow_guests'] );
			$save_transcript       = wp_validate_boolean( $atts['save_transcript'] );
			$enable_streaming      = wp_validate_boolean( $atts['enable_streaming'] );
			$allow_sensitive_tools = wp_validate_boolean( $atts['allow_sensitive_tools'] );
			$template              = sanitize_key( $atts['template'] );

			// Validate template value - default to 'classic' if invalid.
			$allowed_templates = array( 'classic', 'speech-bubbles', 'compact', 'sidebar' );
			if ( ! in_array( $template, $allowed_templates, true ) ) {
				$template = 'classic';
			}

			// Fetch settings once for use throughout this method.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Check if this is a profession test request.
			$is_profession_test = is_string( $assistant_id ) && 0 === strpos( $assistant_id, 'profession_' );

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
				return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'No assistant has been selected. Please provide an assistant attribute or configure a default.', 'mcp-ai-wpoos' ) . '</div>';
			}

			// For profession tests, validate the profession and get associated assistant for permissions.
			if ( $is_profession_test ) {
				$profession_id = absint( str_replace( 'profession_', '', $assistant_id ) );
				$profession    = get_post( $profession_id );

				if ( ! $profession || 'mcp_ai_profession' !== $profession->post_type ) {
					WP_MCP_AI_Logger::log_error(
						'Shortcode attempted to render unavailable profession',
						array(
							'assistant_id'      => $assistant_id,
							'profession_id'     => $profession_id,
							'profession_exists' => (bool) $profession,
							'post_type'         => $profession ? $profession->post_type : null,
							'attributes'        => $atts,
						)
					);
					return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'The requested profession is not available.', 'mcp-ai-wpoos' ) . '</div>';
				}

				// For permissions check, use the profession's associated assistant or default assistant.
				$permissions_assistant_id = get_post_meta( $profession_id, '_wp_mcp_ai_profession_associated_assistant', true );
				$permissions_assistant_id = absint( $permissions_assistant_id );
				if ( ! $permissions_assistant_id ) {
					$permissions_assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
				}
				$assistant = $permissions_assistant_id ? get_post( $permissions_assistant_id ) : null;
			} else {
				$permissions_assistant_id = absint( $assistant_id );
				$assistant                = get_post( $assistant_id );
			}

			// Handle team parameter for multi-agent coordination.
			$team_id               = ! empty( $atts['team'] ) ? absint( $atts['team'] ) : 0;
			$team_data             = null;
			$team_members          = array();
			$orchestration_mode    = '';
			$result_aggregation    = '';
			$multi_agent_enabled   = false;
			$supports_unified_mode = false;

			if ( $team_id > 0 ) {
				$team_post = get_post( $team_id );
				if ( $team_post && 'mcp_ai_team' === $team_post->post_type ) {
					// Team exists - check if multi-agent is enabled.
					$multi_agent_enabled = WP_MCP_AI_Settings_Registry::get_setting( 'enable_multi_agent_teams', true );

					if ( $multi_agent_enabled ) {
						// Load team configuration.
						$team_members = get_post_meta( $team_id, '_wp_mcp_ai_team_members', true );
						if ( ! is_array( $team_members ) ) {
							$team_members = array();
						}

						$orchestration_mode = get_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', true );
						$result_aggregation = get_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', true );

						$orchestration_mode = $orchestration_mode ? $orchestration_mode : 'sequential';
						$result_aggregation = $result_aggregation ? $result_aggregation : 'consensus';

						$supports_unified_mode = count( $team_members ) > 1;

						$team_data = array(
							'id'                    => $team_id,
							'title'                 => $team_post->post_title,
							'members'               => $team_members,
							'orchestration_mode'    => $orchestration_mode,
							'result_aggregation'    => $result_aggregation,
							'multi_agent_enabled'   => $multi_agent_enabled,
							'supports_unified_mode' => $supports_unified_mode,
						);

						WP_MCP_AI_Logger::log_event(
							'shortcode_team_detected',
							'Team chat interface initialized',
							array(
								'team_id'               => $team_id,
								'member_count'          => count( $team_members ),
								'orchestration_mode'    => $orchestration_mode,
								'supports_unified_mode' => $supports_unified_mode,
							)
						);
					}
				}
			}

			// Validate assistant for permissions (not required for profession tests with no associated assistant).
			if ( ! $is_profession_test || $permissions_assistant_id ) {
				if ( ! $assistant || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
					WP_MCP_AI_Logger::log_error(
						'Shortcode attempted to render unavailable assistant',
						array(
							'assistant_id'             => $assistant_id,
							'permissions_assistant_id' => isset( $permissions_assistant_id ) ? $permissions_assistant_id : null,
							'is_profession_test'       => $is_profession_test,
							'assistant_exists'         => (bool) $assistant,
							'post_type'                => $assistant ? $assistant->post_type : null,
							'post_status'              => $assistant ? $assistant->post_status : null,
							'attributes'               => $atts,
						)
					);
					return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'The requested assistant is not available.', 'mcp-ai-wpoos' ) . '</div>';
				}
			}

			$guest_token = '';
			if ( $allow_guests ) {
				// Use permissions_assistant_id for guest token if available, otherwise assistant_id.
				$guest_token_assistant_id = isset( $permissions_assistant_id ) ? $permissions_assistant_id : $assistant_id;
				$guest_token              = self::generate_guest_token( $guest_token_assistant_id );
			}

			// Use the effective capability (per-assistant or global).
			// For profession tests, use the permissions_assistant_id for capability check.
			$capability_assistant_id = isset( $permissions_assistant_id ) ? $permissions_assistant_id : $assistant_id;
			$capability              = function_exists( 'wp_mcp_ai_get_effective_chat_capability' )
				? wp_mcp_ai_get_effective_chat_capability( $capability_assistant_id, 'shortcode' )
				: wp_mcp_ai_get_required_chat_capability( $capability_assistant_id, 'shortcode' );

			if ( $guest_token ) {
				$capability = 'public';
			}

			if ( $capability && 'public' !== $capability && ! current_user_can( $capability ) ) {
				$current_user      = wp_get_current_user();
				$user_capabilities = ( $current_user && isset( $current_user->allcaps ) ) ? $current_user->allcaps : array();

				WP_MCP_AI_Logger::log_warning(
					'Shortcode access denied due to insufficient capability',
					array(
						'assistant_id'        => $assistant_id,
						'required_capability' => $capability,
						'user_id'             => get_current_user_id(),
						'user_capabilities'   => $user_capabilities,
					)
				);
				return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'You do not have permission to chat with this assistant.', 'mcp-ai-wpoos' ) . '</div>';
			}

			// Render the actual widget in Elementor editor for better preview.
			// The WP_DEBUG fix in the main plugin class ensures debug output.

			// won't break the editor when WP_DEBUG is enabled.
			$is_elementor_editor = $this->is_elementor_editor();

			// Get assistant provider and model for client-side execution (embedded provider).
			// This must be done BEFORE enqueuing chat scripts to ensure correct dependency order.
			// For profession tests, use the permissions_assistant_id to get the associated assistant's provider.
			// If permissions_assistant_id is not set, fall back to assistant_id (though this is rare).
			$assistant_provider            = '';
			$assistant_model               = '';
			$assistant_config_for_provider = array(); // Initialize to empty array to prevent undefined variable errors.
			if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				// Determine which assistant ID to use for provider check.
				if ( $is_profession_test && isset( $permissions_assistant_id ) ) {
					// For profession tests, check the associated assistant's provider.
					$provider_check_id = $permissions_assistant_id;
				} else {
					// For regular assistants or profession tests without associated assistant.
					$provider_check_id = $assistant_id;
				}

				$assistant_config_for_provider = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( absint( $provider_check_id ) );
				$assistant_provider            = isset( $assistant_config_for_provider['provider'] ) ? sanitize_key( $assistant_config_for_provider['provider'] ) : '';
				$assistant_model               = isset( $assistant_config_for_provider['model'] ) ? sanitize_text_field( $assistant_config_for_provider['model'] ) : '';
			}

			// Enqueue embedded LLM client scripts if this assistant uses embedded provider.
			// Scripts are already registered in register_assets(), just enqueue them here.
			// Multiple widgets can coexist - each checks state.config.provider in JavaScript.
			$needs_embedded_provider = $this->is_embedded_provider_available( $assistant_provider );

			// Parse additional_tools from the shortcode attribute early so it can be used for:
			// 1. Embedded provider tool definition resolution (added to $tool_slugs_to_include below).
			// 2. Enhanced WebLLM script enqueueing ($has_tools flag below).
			// 3. Tool shortcuts ($additional_tools_for_shortcuts variable below).
			// The parsed slugs are stored in $config['additionalTools'] for the server-side (OpenAI) path,
			// and also resolved to full OpenAI function definitions for the embedded path.
			$additional_tools = array();
			if ( ! empty( $atts['additional_tools'] ) ) {
				$additional_tools_raw = sanitize_text_field( $atts['additional_tools'] );
				$additional_tools     = array_map( 'trim', explode( ',', $additional_tools_raw ) );
				$additional_tools     = array_values( array_filter( array_map( 'sanitize_key', $additional_tools ) ) );
			}

			// Check if assistant has tools, system prompt, or knowledge (used in multiple places).
			// Also consider the profession shortcode attribute: if a profession is specified, a professional
			// role prompt will be built and sent as the system prompt, so the enhanced embedded scripts
			// must be enqueued even when the assistant itself has no system_prompt configured.
			// Also consider additional_tools: if any are specified, treat them as "has tools" so the
			// enhanced WebLLM scripts (tool adapter, function calling client) are enqueued.
			$has_tools         = ( ! empty( $assistant_config_for_provider['tools'] ) && is_array( $assistant_config_for_provider['tools'] ) ) || ! empty( $additional_tools );
			$has_system_prompt = ! empty( $assistant_config_for_provider['system_prompt'] ) || ! empty( $atts['profession'] );
			$has_knowledge     = ! empty( $assistant_config_for_provider['memory_files'] ) || ! empty( $assistant_config_for_provider['vector_store_id'] );

			if ( $needs_embedded_provider && ! $is_elementor_editor ) {
				// Enqueue embedded provider scripts.
				// WordPress ensures these are only loaded once even if called multiple times.
				wp_enqueue_script( 'webllm-loader' );
				wp_enqueue_script( 'wp-mcp-ai-embedded-llm-client' );

				// Enqueue enhanced WebLLM scripts if assistant has tools or knowledge.
				// This ensures the embedded client can use tool calling and maintains assistant knowledge.

				if ( $has_tools || $has_system_prompt || $has_knowledge ) {
					// Enqueue tool adapter and function calling client for enhanced capabilities.
					wp_enqueue_script( 'wp-mcp-ai-webllm-tool-adapter' );
					wp_enqueue_script( 'wp-mcp-ai-webllm-function-calling' );
				}
			}

			// Enqueue chat script (always with same dependencies - no conditional changes).
			// This prevents conflicts when multiple widgets with different providers are on the same page.
			wp_enqueue_script( self::SCRIPT_HANDLE );
			wp_enqueue_style( self::STYLE_HANDLE );

			// Enqueue slash commands integration if available.
			if ( wp_script_is( 'mcp-ai-slash-commands', 'registered' ) ) {
				wp_enqueue_script( 'mcp-ai-slash-commands' );
			}

			$instance_id = wp_unique_id( 'wp-mcp-ai-chat-' );
			$textarea_id = $instance_id . '-input';
			$session_key = wp_generate_uuid4();

			if ( ! $session_key ) {
				$session_key = wp_unique_id( 'wp-mcp-ai-session-' );
			}

			$session_key = sanitize_key( $session_key );

			// Allow attachments (including voice chat and transcription) if:
			// 1. User has upload_files capability, OR
			// 2. Guest access is enabled for this widget
			// This ensures that if users have access to the widget, they have access to its built-in tools.
			$can_upload_attachments = current_user_can( 'upload_files' ) || $allow_guests;

			// Get assistant content if available (not applicable for profession tests without associated assistant).
			$assistant_content = '';
			if ( ! $is_profession_test && $assistant ) {
				$assistant_content = get_post_field( 'post_content', $assistant->ID );
				if ( $assistant_content ) {
					$assistant_content = apply_filters( 'the_content', $assistant_content );
				}
			}

			// Get assistant tools for sidebar template display.
			$assistant_tools = array();
			if ( 'sidebar' === $template && ! $is_profession_test && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				$config_for_tools = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( absint( $assistant_id ) );
				if ( ! empty( $config_for_tools['tools'] ) && is_array( $config_for_tools['tools'] ) ) {
					$assistant_tools = array_values( array_filter( array_map( 'sanitize_key', $config_for_tools['tools'] ) ) );
				}
			}

			// Handle profession attribute to build professional role prompt.
			$professional_prompt  = '';
			$profession_data      = null;
			$profession_mem_files = array();
			if ( ! empty( $atts['profession'] ) ) {
				$profession_id = absint( $atts['profession'] );
				if ( $profession_id > 0 ) {
					$profession_post = get_post( $profession_id );
					if ( $profession_post && 'mcp_ai_profession' === $profession_post->post_type ) {
						$profession_data = $profession_post;
						// Build profession prompt similar to build_prompt_from_primary_roles.
						if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'build_prompt_from_primary_roles' ) ) {
							$professional_prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( array( $profession_id ) );
						}
						// Collect profession memory files so they can be merged into the embedded client config.
						$fetched = get_post_meta( $profession_id, '_wp_mcp_ai_profession_memory_files', true );
						if ( is_array( $fetched ) && ! empty( $fetched ) ) {
							$profession_mem_files = $fetched;
						}
					}
				}
			} elseif ( $is_profession_test && ! empty( $profession_id ) ) {
				// For profession tests (assistant="profession_XXX"), build the profession's prompt so the
				// embedded LLM client receives the correct professional role prompt.
				// $profession is guaranteed valid here — invalid professions return early above.
				$profession_data = $profession;
				if ( class_exists( 'WP_MCP_AI_Assistant_CPT' ) && method_exists( 'WP_MCP_AI_Assistant_CPT', 'build_prompt_from_primary_roles' ) ) {
					$professional_prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( array( $profession_id ) );
				}
				// Collect profession memory files so they can be merged into the embedded client config.
				$fetched = get_post_meta( $profession_id, '_wp_mcp_ai_profession_memory_files', true );
				if ( is_array( $fetched ) && ! empty( $fetched ) ) {
					$profession_mem_files = $fetched;
				}
			}

			$config = array(
				'id'                     => $instance_id,
				'assistantId'            => $assistant_id, // This preserves "profession_XXX" format for profession tests.
				'embeddedAssistantId'    => absint( $permissions_assistant_id ),
				'userId'                 => get_current_user_id(),
				'restUrl'                => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ) ) ),
				'uploadEndpoint'         => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'prepareEndpoint'        => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/attachments/prepare' ) ) ),
				'messagesEndpoint'       => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-client' ) ) ),
				'toolsEndpoint'          => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ) ),
				'filesEndpoint'          => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ) ),
				'transcriptsEndpoint'    => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat-transcripts' ) ) ),
				'embeddedConfigEndpoint' => esc_url_raw( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/embedded-client-config' ) ) ),
				'crawl4aiTaskEndpoint'   => esc_url_raw( trailingslashit( WP_MCP_AI_Request_Context::normalise_rest_url( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/crawl4ai/task' ) ) ) ),
				'crawl4aiDefaultPollMs'  => 5000,
				'requiredCapability'     => $capability ? $capability : '',
				'allowGuests'            => (bool) $allow_guests,
				'canUploadAttachments'   => (bool) $can_upload_attachments,
				'saveTranscript'         => (bool) $save_transcript,
				'enableStreaming'        => (bool) $enable_streaming,
				'allowSensitiveTools'    => (bool) $allow_sensitive_tools,
				'sessionKey'             => $session_key,
				'historyPerPage'         => 20,
				'maxHistoryMessages'     => isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 8,
				'restNonce'              => wp_create_nonce( 'wp_rest' ),
			);

			// Add async tool timeout using helper method (reuses $settings already fetched).
			$config['asyncToolTimeout'] = self::get_async_tool_timeout_ms( $settings );

			// Add provider and model for client-side execution (embedded provider).
			// Also include system_prompt and temperature for embedded provider to use assistant defaults.
			if ( ! empty( $assistant_provider ) ) {
				$config['provider'] = $assistant_provider;
			}
			if ( ! empty( $assistant_model ) ) {
				$config['model'] = $assistant_model;
			}

			// Add assistant defaults (system_prompt, temperature) for client-side execution.
			// This ensures embedded providers have access to the same defaults as server-side providers.
			if ( ! empty( $assistant_config_for_provider['system_prompt'] ) ) {
				$config['systemPrompt'] = $assistant_config_for_provider['system_prompt'];
			}
			if ( isset( $assistant_config_for_provider['temperature'] ) && '' !== $assistant_config_for_provider['temperature'] ) {
				$config['temperature'] = floatval( $assistant_config_for_provider['temperature'] );
			}

			// Add base knowledge (memory files and vector store) for embedded provider.
			// This enables the embedded client to access the same knowledge base as server-side providers.
			// Merge assistant memory files with any profession memory files so both are available.
			$combined_memory_files = array();
			if ( ! empty( $assistant_config_for_provider['memory_files'] ) && is_array( $assistant_config_for_provider['memory_files'] ) ) {
				$combined_memory_files = $assistant_config_for_provider['memory_files'];
			}
			if ( ! empty( $profession_mem_files ) ) {
				$combined_memory_files = array_values( array_unique( array_merge( $combined_memory_files, $profession_mem_files ) ) );
			}
			if ( ! empty( $combined_memory_files ) ) {
				$config['memoryFiles'] = $combined_memory_files;
			}
			if ( ! empty( $assistant_config_for_provider['vector_store_id'] ) ) {
				$config['vectorStoreId'] = $assistant_config_for_provider['vector_store_id'];
			}

			// Add tool definitions for embedded provider (Phase 1: Tool Support Implementation).
			// This enables client-side LLM to know which tools are available and call them.
			// Tools will be executed server-side via the existing orchestration layer.
			$tool_slugs_to_include = array();

			// Start with assistant's configured tools.
			if ( ! empty( $assistant_config_for_provider['tools'] ) && is_array( $assistant_config_for_provider['tools'] ) ) {
				$tool_slugs_to_include = $assistant_config_for_provider['tools'];
			}

			// Merge additional_tools from the shortcode attribute so the embedded client receives their
			// full OpenAI function definitions.  The server-side (OpenAI) path gets these as slugs via
			// $config['additionalTools'] and resolves them on every request, but the embedded client
			// needs the resolved definitions up-front in $config['tools'].
			foreach ( $additional_tools as $additional_slug ) {
				if ( ! in_array( $additional_slug, $tool_slugs_to_include, true ) ) {
					$tool_slugs_to_include[] = $additional_slug;
				}
			}

			// Automatically add semantic_content_search if assistant has knowledge files (RAG pattern).
			// This enables embedded client to retrieve knowledge content server-side when needed.
			if ( $has_knowledge && ! in_array( 'semantic_content_search', $tool_slugs_to_include, true ) ) {
				$tool_slugs_to_include[] = 'semantic_content_search';

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						sprintf(
							'[WP_MCP_AI] Auto-adding semantic_content_search tool for assistant %d (has knowledge files)',
							$assistant_id
						)
					);
				}
			}

			// Build tool definitions for embedded client.
			if ( ! empty( $tool_slugs_to_include ) ) {
				$tool_definitions = array();

				if ( class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
					$registry = WP_MCP_AI_Tool_Registry::get_instance();

					foreach ( $tool_slugs_to_include as $tool_slug ) {
						// Use registry's get_tool_definition() which relies on the required tool
						// interface methods (get_slug, get_description, get_parameters_schema).
						// This works for ALL registered tools, unlike get_definition() which is
						// an optional method not part of WP_MCP_AI_Tool_Interface.
						$tool_definition = $registry->get_tool_definition( $tool_slug );
						if ( $tool_definition && is_array( $tool_definition ) ) {
							// Wrap in OpenAI function-calling format expected by the embedded LLM client.
							// get_tool_definition() always returns name, description, and parameters
							// via the required WP_MCP_AI_Tool_Interface methods.
							$tool_definitions[] = array(
								'type'     => 'function',
								'function' => array(
									'name'        => $tool_definition['name'],
									'description' => $tool_definition['description'],
									'parameters'  => $tool_definition['parameters'],
								),
							);
						}
					}
				}

				if ( ! empty( $tool_definitions ) ) {
					$config['tools'] = $tool_definitions;

					// Log tool definitions being passed to embedded provider.
					if ( class_exists( 'WP_MCP_AI_Logger' ) && 'embedded' === $assistant_provider ) {
						WP_MCP_AI_Logger::log_event(
							'embedded_tools_config',
							'Embedded provider: Tool definitions passed to client',
							array(
								'assistant_id'      => $assistant_id,
								'tool_count'        => count( $tool_definitions ),
								'has_knowledge'     => $has_knowledge,
								'auto_added_search' => $has_knowledge && in_array( 'semantic_content_search', $tool_slugs_to_include, true ),
								'tool_names'        => array_map(
									function ( $def ) {
										return isset( $def['function']['name'] ) ? $def['function']['name'] : 'unknown';
									},
									$tool_definitions
								),
							)
						);
					}
				}
			}

			// Add team information if team is configured.
			if ( ! empty( $team_data ) ) {
				$config['teamData'] = $team_data;
			}

			// Get tool shortcuts - for profession tests, get them from the profession's associated assistant if available.
			$shortcuts_assistant_id = $is_profession_test && isset( $permissions_assistant_id ) ? $permissions_assistant_id : $assistant_id;
			// Pass additional_tools so shortcuts can be generated for them too.
			$additional_tools_for_shortcuts = ! empty( $additional_tools ) ? $additional_tools : array();
			$tool_shortcuts                 = self::get_assistant_tool_shortcuts( $shortcuts_assistant_id, $additional_tools_for_shortcuts );
			if ( ! empty( $tool_shortcuts ) ) {
				$config['toolShortcuts'] = $tool_shortcuts;
			}

			// Parse CPT action buttons if provided.
			$cpt_actions = array();
			if ( ! empty( $atts['cpt_actions'] ) ) {
				// Try to decode as base64 first (new format to avoid shortcode bracket conflicts).
				$decoded_json = base64_decode( $atts['cpt_actions'], true );
				if ( false !== $decoded_json ) {
					$decoded_actions = json_decode( $decoded_json, true );
					if ( is_array( $decoded_actions ) ) {
						$cpt_actions = $decoded_actions;
					}
				}

				// Fallback to direct JSON decode for backwards compatibility.
				if ( empty( $cpt_actions ) ) {
					$decoded_actions = json_decode( $atts['cpt_actions'], true );
					if ( is_array( $decoded_actions ) ) {
						$cpt_actions = $decoded_actions;
					}
				}
			}
			if ( ! empty( $cpt_actions ) ) {
				$config['cptActions'] = $cpt_actions;
			}

			// Store additional_tools as slugs in config for the server-side (OpenAI) path.
			// These tools will be available regardless of the assistant's configured tools.
			// Note: For the embedded provider, these are already resolved and merged into
			// $config['tools'] above via $tool_slugs_to_include.
			if ( ! empty( $additional_tools ) ) {
				$config['additionalTools'] = $additional_tools;
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

			// Add professional role prompt if provided via profession attribute.
			if ( ! empty( $professional_prompt ) ) {
				// For server-side providers: keep professionalPrompt as a separate config field so
				// the JS can pass it to the server in the chat payload for server-side system-prompt
				// assembly and for the professional-selector feature.
				// For embedded providers: pre-combine with the assistant's own system prompt so the
				// embedded client always has a populated systemPrompt from the initial page render —
				// no extra server round-trip is needed.  We intentionally omit professionalPrompt
				// from the embedded config to prevent JS from double-combining professional content
				// that is already included in systemPrompt.
				if ( 'embedded' !== $assistant_provider ) {
					$config['professionalPrompt'] = $professional_prompt;
				} elseif ( ! empty( $config['systemPrompt'] ) ) {
					// Merge professional role first, then assistant instructions — the same separator
					// and ordering used by the server-side REST handler (class-wp-mcp-ai-rest.php).
					$config['systemPrompt'] = $professional_prompt . "\n\n---\n\n# Additional Instructions\n\n" . $config['systemPrompt'];
				} else {
					$config['systemPrompt'] = $professional_prompt;
				}
			}

			// Include profession info for display purposes.
			if ( $profession_data ) {
				$config['professionId']   = $profession_data->ID;
				$config['professionName'] = $profession_data->post_title;
			}

			// Store config in a global for AJAX access (used by professional selector).
			if ( ! isset( $GLOBALS['wp_mcp_ai_chat_configs'] ) ) {
				$GLOBALS['wp_mcp_ai_chat_configs'] = array();
			}
			$GLOBALS['wp_mcp_ai_chat_configs'][ $instance_id ] = $config;

			// Log for debugging PM assistant issues.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					sprintf(
						'[WP_MCP_AI] Shortcode stored config for instance: %s (assistant_id: %s)',
						$instance_id,
						isset( $config['assistantId'] ) ? $config['assistantId'] : 'N/A'
					)
				);
			}

			$inline_config  = 'window.wpMcpAiChatInstances = window.wpMcpAiChatInstances || {};';
			$inline_config .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $instance_id ) . '] = ' . wp_json_encode( $config ) . ';';
			wp_add_inline_script( self::SCRIPT_HANDLE, $inline_config, 'before' );

			ob_start();
			$messages_id = $instance_id . '-messages';

			// Build container classes based on template.
			$container_classes = array( 'wp-mcp-ai-chat' );
			if ( 'classic' !== $template ) {
				$container_classes[] = 'wp-mcp-ai-chat--template-' . $template;
			}
			?>
		<div class="<?php echo esc_attr( implode( ' ', $container_classes ) ); ?>" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-chat data-template="<?php echo esc_attr( $template ); ?>">
			<?php
			if ( $is_elementor_editor ) {
				echo $this->render_editor_notice(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<div class="wp-mcp-ai-chat__assistant">
				<label class="wp-mcp-ai-chat__label" for="<?php echo esc_attr( $textarea_id ); ?>">
					<?php
					// For profession tests, display the profession title.
					// For regular assistants, display the assistant title.
					if ( $is_profession_test && isset( $profession ) && $profession ) {
						echo esc_html( get_the_title( $profession->ID ) );
					} else {
						echo esc_html( get_the_title( $assistant_id ) );
					}
					?>
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
					aria-label="<?php echo esc_attr__( 'Expand conversation', 'mcp-ai-wpoos' ); ?>"
				>
					<svg class="wp-mcp-ai-chat__transcript-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
						<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />
					</svg>
					<span class="screen-reader-text"><?php esc_html_e( 'Expand conversation', 'mcp-ai-wpoos' ); ?></span>
				</button>
			</div>
			<div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>
			<form class="wp-mcp-ai-chat__form" data-instance-id="<?php echo esc_attr( $instance_id ); ?>">
				<div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>
				<div class="wp-mcp-ai-chat__tool-shortcuts-wrapper" hidden>
					<button type="button" class="wp-mcp-ai-chat__tool-shortcuts-toggle wp-mcp-ai-chat__tool-shortcuts-toggle--collapsed" aria-expanded="false" aria-controls="<?php echo esc_attr( $instance_id . '-tool-shortcuts' ); ?>">
						<span class="wp-mcp-ai-chat__tool-shortcuts-toggle-text"><?php esc_html_e( 'Quick Tasks', 'mcp-ai-wpoos' ); ?></span>
						<svg class="wp-mcp-ai-chat__tool-shortcuts-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 15.5a1 1 0 0 1-.7-.29l-5-5a1 1 0 0 1 1.4-1.42L12 13.09l4.3-4.3a1 1 0 0 1 1.4 1.42l-5 5a1 1 0 0 1-.7.29z" />
						</svg>
					</button>
					<div id="<?php echo esc_attr( $instance_id . '-tool-shortcuts' ); ?>" class="wp-mcp-ai-chat__tool-shortcuts wp-mcp-ai-chat__tool-shortcuts--collapsed" role="group" aria-label="<?php echo esc_attr__( 'Assistant tool tasks', 'mcp-ai-wpoos' ); ?>" hidden></div>
				</div>
				<textarea id="<?php echo esc_attr( $textarea_id ); ?>" class="wp-mcp-ai-chat__input" rows="4" placeholder="<?php echo esc_attr__( 'Ask something…', 'mcp-ai-wpoos' ); ?>" required></textarea>
				<div class="wp-mcp-ai-chat__attachments" hidden>
					<div class="wp-mcp-ai-chat__attachments-header"><?php esc_html_e( 'Attachments', 'mcp-ai-wpoos' ); ?></div>
					<ul class="wp-mcp-ai-chat__attachments-list"></ul>
				</div>
				<div class="wp-mcp-ai-chat__actions">
					<input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />
					<input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden<?php echo esc_attr( $can_upload_attachments ? '' : ' disabled' ); ?> />
					<input type="file" class="wp-mcp-ai-chat__translate-input" accept="audio/*" hidden<?php echo esc_attr( $can_upload_attachments ? '' : ' disabled' ); ?> />
					<button type="button" class="wp-mcp-ai-chat__translate" aria-label="<?php echo esc_attr__( 'Translate audio', 'mcp-ai-wpoos' ); ?>"<?php echo $can_upload_attachments ? '' : ' disabled hidden'; ?>>
						<svg class="wp-mcp-ai-chat__translate-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12.87 15.07l-2.54-2.51.03-.03A17.52 17.52 0 0 0 14.07 6H17V4h-7V2H8v2H1v2h11.17C11.5 7.92 10.44 9.75 9 11.35 8.07 10.32 7.3 9.19 6.69 8h-2c.73 1.63 1.73 3.17 2.98 4.56l-5.09 5.02L4 19l5-5 3.11 3.11.76-2.04M18.5 10h-2L12 22h2l1.12-3h4.75L21 22h2l-4.5-12m-2.62 7l1.62-4.33L19.12 17h-3.24z"></path>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Translate audio', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button type="button" class="wp-mcp-ai-chat__voice-chat" aria-label="<?php echo esc_attr__( 'Voice chat', 'mcp-ai-wpoos' ); ?>"<?php echo esc_attr( $can_upload_attachments ? '' : ' disabled hidden' ); ?>>
						<svg class="wp-mcp-ai-chat__voice-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>
							<circle cx="12" cy="12" r="1.5" fill="currentColor"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Voice chat', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button type="button" class="wp-mcp-ai-chat__transcribe" aria-label="<?php echo esc_attr__( 'Transcribe audio', 'mcp-ai-wpoos' ); ?>"<?php echo $can_upload_attachments ? '' : ' disabled hidden'; ?>>
						<svg class="wp-mcp-ai-chat__transcribe-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 14 0h-2z"></path>
							<path d="M12 16a7 7 0 0 0 6.93-6H17a5 5 0 0 1-10 0H5.07A7 7 0 0 0 12 16zm-1 2.05V21h2v-2.95A9 9 0 0 0 20.95 11H19a7 7 0 0 1-14 0H3.05A9 9 0 0 0 11 18.05z"></path>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Transcribe audio', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button type="button" class="wp-mcp-ai-chat__attach">
						<?php esc_html_e( 'Attach file', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="button" class="wp-mcp-ai-chat__build" hidden>
						<?php esc_html_e( 'Build', 'mcp-ai-wpoos' ); ?>
					</button>
					<button type="submit" class="wp-mcp-ai-chat__submit">
						<?php esc_html_e( 'Send', 'mcp-ai-wpoos' ); ?>
					</button>
				</div>
			</form>
			<div class="wp-mcp-ai-chat__controls">
				<div class="wp-mcp-ai-chat__quota-monitor" role="status" aria-live="polite" aria-atomic="true"></div>
				<div class="wp-mcp-ai-chat__cron-status" role="status" aria-live="polite" aria-atomic="true" hidden>
					<span class="wp-mcp-ai-chat__cron-status-label"><?php esc_html_e( 'Jobs:', 'mcp-ai-wpoos' ); ?></span>
					<span class="wp-mcp-ai-chat__cron-status-active" title="<?php esc_attr_e( 'Active jobs', 'mcp-ai-wpoos' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
					<span class="wp-mcp-ai-chat__cron-status-pending" title="<?php esc_attr_e( 'Pending jobs', 'mcp-ai-wpoos' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
					<span class="wp-mcp-ai-chat__cron-status-completed" title="<?php esc_attr_e( 'Completed jobs', 'mcp-ai-wpoos' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
					<span class="wp-mcp-ai-chat__cron-status-failed" title="<?php esc_attr_e( 'Failed jobs', 'mcp-ai-wpoos' ); ?>">
						<span class="wp-mcp-ai-chat__cron-status-count">0</span>
					</span>
					<span class="wp-mcp-ai-chat__cron-status-health" title="<?php esc_attr_e( 'System health', 'mcp-ai-wpoos' ); ?>" data-status="unknown">
						<span class="wp-mcp-ai-chat__cron-status-icon">●</span>
					</span>
				</div>
				<div class="wp-mcp-ai-chat__control-buttons">
					<button
						type="button"
						class="wp-mcp-ai-chat__save"
						aria-label="<?php echo esc_attr__( 'Save conversation', 'mcp-ai-wpoos' ); ?>"
						title="<?php echo esc_attr__( 'Save conversation', 'mcp-ai-wpoos' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__save-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2zM5 5v14h14V9h-4V5H5z" />
							<path d="M7 5h6v3H7V5zm5 9a2 2 0 11-4 0 2 2 0 014 0z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Save conversation', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__export"
						aria-label="<?php echo esc_attr__( 'Export conversation', 'mcp-ai-wpoos' ); ?>"
						title="<?php echo esc_attr__( 'Export conversation', 'mcp-ai-wpoos' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__export-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 16a1 1 0 01-1-1V5a1 1 0 012 0v10a1 1 0 01-1 1z" />
							<path d="M12 16a1 1 0 01-.707-.293l-4-4a1 1 0 011.414-1.414L12 13.586l3.293-3.293a1 1 0 011.414 1.414l-4 4A1 1 0 0112 16z" />
							<path d="M5 19a1 1 0 010-2h14a1 1 0 010 2H5z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Export conversation', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__history-toggle"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $instance_id ); ?>-history"
						aria-label="<?php echo esc_attr__( 'Show previous conversations', 'mcp-ai-wpoos' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__history-toggle-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M6 5.5a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h10a1 1 0 110 2H7a1 1 0 01-1-1zm0 6a1 1 0 011-1h7a1 1 0 010 2H7a1 1 0 01-1-1z" />
							<path d="M5 9a1 1 0 012 0 1 1 0 11-2 0zm0 6a1 1 0 012 0 1 1 0 11-2 0zm0-12a1 1 0 012 0 1 1 0 11-2 0z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Show previous conversations', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<button
						type="button"
						class="wp-mcp-ai-chat__new-chat"
						aria-label="<?php echo esc_attr__( 'Start new conversation', 'mcp-ai-wpoos' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__new-chat-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M12 4a1 1 0 011 1v6h6a1 1 0 110 2h-6v6a1 1 0 11-2 0v-6H5a1 1 0 110-2h6V5a1 1 0 011-1z" />
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Start new conversation', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<?php if ( ! empty( $team_data ) && $supports_unified_mode ) : ?>
					<button
						type="button"
						class="wp-mcp-ai-chat__team-mode-toggle"
						data-mode="individual"
						aria-label="<?php echo esc_attr__( 'Switch to unified team mode', 'mcp-ai-wpoos' ); ?>"
						title="<?php echo esc_attr__( 'Switch between unified team and individual member modes', 'mcp-ai-wpoos' ); ?>"
					>
						<svg class="wp-mcp-ai-chat__team-mode-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<!-- Switch/Shuffle icon - clearly indicates mode toggling -->
							<path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Team mode toggle', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<?php endif; ?>
					<?php if ( ! empty( $cpt_actions ) ) : ?>
					<div class="wp-mcp-ai-chat__cpt-actions" role="group" aria-label="<?php echo esc_attr__( 'Post type actions', 'mcp-ai-wpoos' ); ?>"></div>
					<?php endif; ?>
				</div>
			</div>
			<section class="wp-mcp-ai-chat__history" id="<?php echo esc_attr( $instance_id ); ?>-history" hidden aria-label="<?php esc_attr_e( 'Previous conversations', 'mcp-ai-wpoos' ); ?>">
				<div class="wp-mcp-ai-chat__history-header">
					<button type="button" class="wp-mcp-ai-chat__history-refresh" aria-label="<?php echo esc_attr__( 'Refresh conversation history', 'mcp-ai-wpoos' ); ?>" title="<?php echo esc_attr__( 'Refresh conversation history', 'mcp-ai-wpoos' ); ?>">
						<svg class="wp-mcp-ai-chat__history-refresh-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
							<path d="M4 12a8 8 0 018-8V3c-1.105 0-2.165.21-3.13.594l1.42 1.42A6.004 6.004 0 0112 5a7 7 0 110 14 7 7 0 01-6.93-6H3a8 8 0 008 8 8 8 0 000-16V3l-3 3 3 3v-1.078z"/>
						</svg>
						<span class="screen-reader-text"><?php esc_html_e( 'Refresh conversation history', 'mcp-ai-wpoos' ); ?></span>
					</button>
				</div>
				<div class="wp-mcp-ai-chat__history-status" role="status" aria-live="polite" hidden></div>
				<?php if ( 'sidebar' === $template && ! empty( $assistant_tools ) ) : ?>
					<div class="wp-mcp-ai-chat__tools-list">
						<h3 class="wp-mcp-ai-chat__tools-list-header"><?php esc_html_e( 'Available Tools', 'mcp-ai-wpoos' ); ?></h3>
						<ul class="wp-mcp-ai-chat__tools-items">
							<?php foreach ( $assistant_tools as $tool_slug ) : ?>
								<li class="wp-mcp-ai-chat__tools-item">
									<span class="wp-mcp-ai-chat__tools-item-icon">
										<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
											<path d="M10 2a1 1 0 011 1v4.586l3.707-3.707a1 1 0 111.414 1.414L12.414 9H17a1 1 0 110 2h-4.586l3.707 3.707a1 1 0 01-1.414 1.414L11 12.414V17a1 1 0 11-2 0v-4.586l-3.707 3.707a1 1 0 01-1.414-1.414L7.586 11H3a1 1 0 110-2h4.586L3.879 5.293a1 1 0 011.414-1.414L9 7.586V3a1 1 0 011-1z"/>
										</svg>
									</span>
									<span class="wp-mcp-ai-chat__tools-item-name"><?php echo esc_html( str_replace( '_', ' ', $tool_slug ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<ul class="wp-mcp-ai-chat__history-list" role="list"></ul>
				<button type="button" class="wp-mcp-ai-chat__history-load-more" hidden>
					<?php esc_html_e( 'Load More', 'mcp-ai-wpoos' ); ?>
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
				esc_html__( 'Unable to load the chat interface. Please try refreshing the page or contact support if the problem persists.', 'mcp-ai-wpoos' ) .
				'</div>';
		}
	}

	/**
	 * Resolve the assistant identifier provided via shortcode attributes.
	 *
	 * Accepts numeric IDs, assistant slugs, or profession test identifiers (profession_XXX).
	 * Gracefully falls back when the supplied value cannot be resolved.
	 *
	 * @param mixed $assistant Assistant shortcode attribute value.
	 * @return int|string|false Assistant post ID when available, profession test identifier (profession_XXX) for profession testing, or 0 if not found.
	 */
	public static function resolve_assistant_id( $assistant ) {
		$assistant = is_scalar( $assistant ) ? trim( (string) $assistant ) : '';

		if ( '' === $assistant ) {
			return 0;
		}

		// Check if this is a profession test request (profession_XXX format).
		if ( is_string( $assistant ) && 0 === strpos( $assistant, 'profession_' ) ) {
			$profession_id = absint( str_replace( 'profession_', '', $assistant ) );
			if ( $profession_id ) {
				// Verify it's actually a profession post.
				$profession_post = get_post( $profession_id );
				if ( $profession_post && 'mcp_ai_profession' === $profession_post->post_type ) {
					// Return the full profession identifier to preserve it through the flow.
					return 'profession_' . $profession_id;
				}
			}
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
	 * @param int   $assistant_id    Assistant post ID.
	 * @param array $additional_tools Optional array of additional tool slugs to include shortcuts for.
	 * @return array[]
	 */
	public static function get_assistant_tool_shortcuts( $assistant_id, $additional_tools = array() ) {
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

		// Merge in additional tools from shortcode parameter.
		if ( ! empty( $additional_tools ) && is_array( $additional_tools ) ) {
			foreach ( $additional_tools as $tool_slug ) {
				$tool_slug = sanitize_key( $tool_slug );

				if ( '' === $tool_slug ) {
					continue;
				}

				// Only add if not already in the list.
				if ( ! in_array( $tool_slug, $selected_tools, true ) ) {
					$selected_tools[] = $tool_slug;
				}
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

					// Skip hidden shortcuts.
					if ( isset( $custom_shortcut['hidden'] ) && $custom_shortcut['hidden'] ) {
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
			// Even with prebuilt shortcuts disabled, ensure fallback shortcut if no custom shortcuts.
			if ( empty( $shortcuts ) ) {
				$fallback_shortcut = array(
					'tool'    => 'default',
					'label'   => sanitize_text_field( __( 'What can you do?', 'mcp-ai-wpoos' ) ),
					'payload' => sanitize_textarea_field( 'Can you tell me what you can do?' ),
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

		if ( empty( $selected_tools ) ) {
			// Even with no tools selected, ensure fallback shortcut if no custom shortcuts.
			if ( empty( $shortcuts ) ) {
				$fallback_shortcut = array(
					'tool'    => 'default',
					'label'   => sanitize_text_field( __( 'What can you do?', 'mcp-ai-wpoos' ) ),
					'payload' => sanitize_textarea_field( 'Can you tell me what you can do?' ),
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

						// Skip hidden shortcuts.
						if ( isset( $override_shortcut['hidden'] ) && $override_shortcut['hidden'] ) {
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
			'label'   => sanitize_text_field( __( 'What can you do?', 'mcp-ai-wpoos' ) ),
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
				'label'   => sanitize_text_field( __( 'What are some things you can do?', 'mcp-ai-wpoos' ) ),
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
