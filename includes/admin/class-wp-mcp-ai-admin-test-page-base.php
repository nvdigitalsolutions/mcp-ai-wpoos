<?php
/**
 * Base class for Admin Test Pages
 *
 * Provides shared functionality for test pages (Assistant, Profession, Team).
 * Follows SoC by centralizing common logic and reducing code duplication.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for admin test pages.
 */
abstract class WP_MCP_AI_Admin_Test_Page_Base {

	/**
	 * Page hook suffix.
	 *
	 * @var string|false
	 */
	protected $page_hook;

	/**
	 * Get the post type for this test page.
	 *
	 * @return string
	 */
	abstract protected function get_post_type();

	/**
	 * Get the page slug.
	 *
	 * @return string
	 */
	abstract protected function get_page_slug();

	/**
	 * Get the page title.
	 *
	 * @return string
	 */
	abstract protected function get_page_title();

	/**
	 * Get the menu title.
	 *
	 * @return string
	 */
	abstract protected function get_menu_title();

	/**
	 * Render the page content.
	 */
	abstract public function render_page();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public function register_submenu_page() {
		$post_type = $this->get_post_type();

		$this->page_hook = add_submenu_page(
			'edit.php?post_type=' . $post_type,
			$this->get_page_title(),
			$this->get_menu_title(),
			'manage_options',
			$this->get_page_slug(),
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the test page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		// Enqueue shared chat assets.
		$this->enqueue_chat_assets();

		// Enqueue page-specific assets.
		$this->enqueue_page_assets();
	}

	/**
	 * Enqueue page-specific assets.
	 * Override in child classes to add page-specific JS/CSS.
	 */
	protected function enqueue_page_assets() {
		// Override in child classes if needed.
	}

	/**
	 * Enqueue chat interface assets.
	 * Shared across all test pages.
	 */
	protected function enqueue_chat_assets() {
		$script_relative             = 'assets/js/chat.js';
		$style_relative              = 'assets/css/chat.css';
		$cron_status_script_relative = 'assets/js/cron-status-service.js';
		$cron_status_style_relative  = 'assets/css/cron-status.css';

		$script_path             = WP_MCP_AI_URL . $script_relative;
		$style_path              = WP_MCP_AI_URL . $style_relative;
		$cron_status_script_path = WP_MCP_AI_URL . $cron_status_script_relative;
		$cron_status_style_path  = WP_MCP_AI_URL . $cron_status_style_relative;

		$script_version             = $this->get_asset_version( $script_relative );
		$style_version              = $this->get_asset_version( $style_relative );
		$cron_status_script_version = $this->get_asset_version( $cron_status_script_relative );
		$cron_status_style_version  = $this->get_asset_version( $cron_status_style_relative );

		// Enqueue cron status service first.
		wp_enqueue_script(
			'wp-mcp-ai-cron-status',
			$cron_status_script_path,
			array(),
			$cron_status_script_version,
			true
		);

		wp_enqueue_style(
			'wp-mcp-ai-cron-status',
			$cron_status_style_path,
			array(),
			$cron_status_style_version
		);

		wp_enqueue_style(
			'wp-mcp-ai-chat',
			$style_path,
			array( 'wp-mcp-ai-cron-status' ),
			$style_version
		);

		wp_enqueue_script(
			'wp-mcp-ai-chat',
			$script_path,
			array( 'wp-mcp-ai-cron-status' ),
			$script_version,
			true
		);

		// Safety check: Ensure REST constants exist.
		$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

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
				'strings'             => $this->get_chat_strings(),
			)
		);
	}

	/**
	 * Get chat interface strings for localization.
	 * Can be overridden in child classes for customization.
	 *
	 * @return array
	 */
	protected function get_chat_strings() {
		return array(
			'placeholder'                   => __( 'Ask something…', 'wp-mcp-ai' ),
			'send'                          => __( 'Send', 'wp-mcp-ai' ),
			'bundlingMessages'              => __( 'Preparing to send…', 'wp-mcp-ai' ),
			'sending'                       => __( 'Sending message…', 'wp-mcp-ai' ),
			'waiting'                       => __( 'Waiting for the assistant…', 'wp-mcp-ai' ),
			'error'                         => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
			'missingAssistant'              => __( 'Configuration was not found.', 'wp-mcp-ai' ),
			'notAuthorized'                 => __( 'You do not have permission to chat.', 'wp-mcp-ai' ),
			'toolExecuting'                 => __( 'Running tool: %s', 'wp-mcp-ai' ),
			'toolSuccess'                   => __( 'Tool response ready.', 'wp-mcp-ai' ),
			'toolError'                     => __( 'The tool request failed.', 'wp-mcp-ai' ),
			'toolQueued'                    => __( 'Tool queued. Results will appear shortly.', 'wp-mcp-ai' ),
			'toolPolling'                   => __( 'Tool is processing…', 'wp-mcp-ai' ),
			'toolTimeout'                   => __( 'Tool timed out before completing.', 'wp-mcp-ai' ),
			'toolFailed'                    => __( 'Tool failed: %s', 'wp-mcp-ai' ),
			'speechToolSuccess'             => __( 'Speech audio saved to the Media Library.', 'wp-mcp-ai' ),
			'imageToolSuccess'              => __( 'Image saved to the Media Library.', 'wp-mcp-ai' ),
			'toolShortcutLabel'             => __( 'Insert task: %s', 'wp-mcp-ai' ),
			'emptyMessage'                  => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
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
			'confirmClearConversation'      => __( 'Clear current conversation and start new? Use the Save button first if you want to keep this conversation.', 'wp-mcp-ai' ),
			'noConversationToExport'        => __( 'No conversation to export. Start chatting first!', 'wp-mcp-ai' ),
			'exportFormatPrompt'            => __( 'Choose export format:\n- json\n- markdown\n- text', 'wp-mcp-ai' ),
			'invalidExportFormat'           => __( 'Invalid format. Please choose json, markdown, or text.', 'wp-mcp-ai' ),
			'exportFailed'                  => __( 'Export failed: ', 'wp-mcp-ai' ),
			'exportSuccess'                 => __( 'Conversation exported successfully as ', 'wp-mcp-ai' ),
			'deleteConversation'            => __( 'Delete this conversation', 'wp-mcp-ai' ),
			'confirmDeleteConversation'     => __( 'Are you sure you want to delete this conversation? This action cannot be undone.', 'wp-mcp-ai' ),
			'finishReasonLength'            => __( 'Response stopped: Maximum length reached', 'wp-mcp-ai' ),
			'finishReasonMaxTokens'         => __( 'Response stopped: Token limit reached', 'wp-mcp-ai' ),
			'finishReasonContentFilter'     => __( 'Response stopped: Content filtered', 'wp-mcp-ai' ),
			'finishReasonToolCalls'         => __( 'Response stopped: Tool execution required', 'wp-mcp-ai' ),
			'finishReasonFunctionCall'      => __( 'Response stopped: Function call required', 'wp-mcp-ai' ),
			'finishReasonRecitation'        => __( 'Response stopped: Recitation detected', 'wp-mcp-ai' ),
			'finishReasonSafety'            => __( 'Response stopped: Safety filter triggered', 'wp-mcp-ai' ),
			'finishReasonError'             => __( 'Response stopped: Error occurred', 'wp-mcp-ai' ),
			'finishReasonOther'             => __( 'Response stopped: %s', 'wp-mcp-ai' ),
			'roleLabels'                    => array(
				'assistant' => __( 'Assistant', 'wp-mcp-ai' ),
				'user'      => __( 'You', 'wp-mcp-ai' ),
				'system'    => __( 'System', 'wp-mcp-ai' ),
				'tool'      => __( 'Tool', 'wp-mcp-ai' ),
			),
		);
	}

	/**
	 * Normalize REST URL.
	 *
	 * @param string $url REST URL to normalize.
	 * @return string Normalized URL.
	 */
	protected function normalise_rest_url( $url ) {
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
	 * Check if current user has permission to view this page.
	 *
	 * @return bool
	 */
	protected function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
		}
		return true;
	}
}
