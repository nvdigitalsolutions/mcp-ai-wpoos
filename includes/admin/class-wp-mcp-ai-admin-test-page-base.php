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

		// Check if any assistant on this page uses embedded provider.
		// If so, we need to enqueue embedded LLM scripts before the chat scripts.
		$this->enqueue_embedded_provider_if_needed();

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
	 * Check if any assistant uses embedded provider and enqueue scripts if needed.
	 * This is required for admin test pages where assistants are loaded dynamically.
	 */
	protected function enqueue_embedded_provider_if_needed() {
		// Skip if Pro is not available (embedded provider is Pro-only).
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if Assistant CPT class is available.
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return;
		}

		// Get all published assistants to check if any use embedded provider.
		$assistants = get_posts(
			array(
				'post_type'      => $this->get_post_type(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids', // Only need IDs for efficiency.
			)
		);

		if ( empty( $assistants ) ) {
			return;
		}

		// Check if any assistant uses embedded provider.
		$needs_embedded = false;
		foreach ( $assistants as $assistant_id ) {
			$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			if ( isset( $config['provider'] ) && 'embedded' === $config['provider'] ) {
				$needs_embedded = true;
				break;
			}
		}

		// If no assistant uses embedded provider, we're done.
		if ( ! $needs_embedded ) {
			return;
		}

		// Enqueue embedded LLM client scripts.
		$embedded_script_path    = WP_MCP_AI_URL . 'assets/js/embedded-llm-client.js';
		$embedded_script_version = $this->get_asset_version( 'assets/js/embedded-llm-client.js' );
		$webllm_loader_path      = WP_MCP_AI_URL . 'assets/js/webllm-loader.js';
		$webllm_loader_version   = $this->get_asset_version( 'assets/js/webllm-loader.js' );

		// Register WebLLM loader script.
		if ( ! wp_script_is( 'webllm-loader', 'registered' ) ) {
			wp_register_script(
				'webllm-loader',
				$webllm_loader_path,
				array(),
				$webllm_loader_version,
				true
			);
		}

		// Register embedded LLM client.
		if ( ! wp_script_is( 'wp-mcp-ai-embedded-llm-client', 'registered' ) ) {
			wp_register_script(
				'wp-mcp-ai-embedded-llm-client',
				$embedded_script_path,
				array( 'webllm-loader' ),
				$embedded_script_version,
				true
			);
		}

		// Enqueue the scripts.
		wp_enqueue_script( 'webllm-loader' );
		wp_enqueue_script( 'wp-mcp-ai-embedded-llm-client' );
	}

	/**
	 * Enqueue chat interface assets.
	 * Shared across all test pages.
	 *
	 * Uses the bundled chat-bundle.js which combines all chat-related services
	 * into a single optimized file, reducing HTTP requests from 9 files to 1 file.
	 */
	protected function enqueue_chat_assets() {
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

		// Enqueue cron status styles (CSS only - JS is bundled).

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

		// Register bundled chat script (includes all services in a single file).

		wp_enqueue_script(
			'wp-mcp-ai-chat',
			$script_path,
			array(), // No dependencies - all services are bundled together.
			$script_version,
			true
		);

		// Safety check: Ensure REST constants exist.
		$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

		// Use the shortcode helper method for consistent async tool timeout calculation.
		// Default to 5 minutes (300000ms) if shortcode class is not available.
		$async_timeout_ms = class_exists( 'WP_MCP_AI_Shortcode' )
			? WP_MCP_AI_Shortcode::get_async_tool_timeout_ms()
			: 300000;

		// Get plugin settings for cost display and capability flags configuration.
		$settings         = WP_MCP_AI_Admin_Settings::get_settings();
		$show_usage_costs = isset( $settings['show_usage_costs'] ) ? (bool) $settings['show_usage_costs'] : false;

		// Allow filtering of cost display setting.
		$show_usage_costs = apply_filters( 'wp_mcp_ai_show_usage_costs', $show_usage_costs, get_current_user_id() );

		// Get capability flags display setting.
		$show_capability_flags = isset( $settings['show_capability_flags'] ) ? (bool) $settings['show_capability_flags'] : false;

		// Allow filtering of capability flags display setting.
		$show_capability_flags = apply_filters( 'wp_mcp_ai_show_capability_flags', $show_capability_flags, get_current_user_id() );

		wp_localize_script(
			'wp-mcp-ai-chat',
			'wpMcpAiChat',
			array(
				'restUrl'             => esc_url_raw( trailingslashit( $this->normalise_rest_url( rest_url( $rest_namespace ) ) ) ),
				'uploadEndpoint'      => esc_url_raw( $this->normalise_rest_url( rest_url( 'wp/v2/media' ) ) ),
				'filesEndpoint'       => esc_url_raw( trailingslashit( $this->normalise_rest_url( rest_url( $rest_namespace . '/files' ) ) ) ),
				'toolsEndpoint'       => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/tools' ) ) ),
				'transcriptsEndpoint' => esc_url_raw( $this->normalise_rest_url( rest_url( $rest_namespace . '/chat-transcripts' ) ) ),
				'historyPerPage'      => 20,
				'currentUserId'       => get_current_user_id(),
				'nonce'               => wp_create_nonce( 'wp_rest' ),
				'showUsageCosts'      => $show_usage_costs,
				'showCapabilityFlags' => $show_capability_flags,
				'asyncToolTimeout'    => $async_timeout_ms,
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
			'placeholder'                   => __( 'Ask something…', 'mcp-ai-wpoos' ),
			'send'                          => __( 'Send', 'mcp-ai-wpoos' ),
			'bundlingMessages'              => __( 'Preparing to send…', 'mcp-ai-wpoos' ),
			'sending'                       => __( 'Sending message…', 'mcp-ai-wpoos' ),
			'waiting'                       => __( 'Waiting for the assistant…', 'mcp-ai-wpoos' ),
			'error'                         => __( 'Something went wrong. Please try again.', 'mcp-ai-wpoos' ),
			'missingAssistant'              => __( 'Configuration was not found.', 'mcp-ai-wpoos' ),
			'notAuthorized'                 => __( 'You do not have permission to chat.', 'mcp-ai-wpoos' ),
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
			'emptyMessage'                  => __( 'Enter a message before sending.', 'mcp-ai-wpoos' ),
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
				'assistant' => __( 'Assistant', 'mcp-ai-wpoos' ),
				'user'      => __( 'You', 'mcp-ai-wpoos' ),
				'system'    => __( 'System', 'mcp-ai-wpoos' ),
				'tool'      => __( 'Tool', 'mcp-ai-wpoos' ),
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
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}
		return true;
	}
}
