<?php
/**
 * Chat Client Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Chat_Client' ) ) {
	/**
	 * Chat Client settings section.
	 */
	class WP_MCP_AI_Section_Chat_Client extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'chat_client';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Chat Client', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'general';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 15;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure chat interface settings, behavior, and features for the frontend chat client.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/guides/user/chat/chat-client-settings.md';
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Settings subtab fields.
				'chat_theme'                      => array(
					'type'        => 'select',
					'label'       => __( 'Chat Theme', 'mcp-ai-wpoos' ),
					'description' => __( 'Select the visual theme for the chat interface.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'light' => __( 'Light', 'mcp-ai-wpoos' ),
						'dark'  => __( 'Dark', 'mcp-ai-wpoos' ),
						'auto'  => __( 'Auto (System Preference)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'light',
				),
				'chat_primary_color'              => array(
					'type'        => 'text',
					'label'       => __( 'Primary Color', 'mcp-ai-wpoos' ),
					'description' => __( 'HEX color code for primary UI elements (e.g., #0073aa). Leave empty for default.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => '#0073aa',
				),
				'chat_user_bubble_color'          => array(
					'type'        => 'text',
					'label'       => __( 'User Message Bubble Color', 'mcp-ai-wpoos' ),
					'description' => __( 'HEX color code for user message bubbles. Leave empty for default.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => '#E3F2FD',
				),
				'chat_assistant_bubble_color'     => array(
					'type'        => 'text',
					'label'       => __( 'Assistant Message Bubble Color', 'mcp-ai-wpoos' ),
					'description' => __( 'HEX color code for assistant message bubbles. Leave empty for default.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => '#F5F5F5',
				),
				'chat_border_radius'              => array(
					'type'        => 'number',
					'label'       => __( 'Border Radius (px)', 'mcp-ai-wpoos' ),
					'description' => __( 'Border radius for chat bubbles in pixels. Higher values create more rounded corners.', 'mcp-ai-wpoos' ),
					'default'     => 12,
					'placeholder' => '12',
					'min'         => 0,
					'max'         => 50,
				),
				'chat_font_size'                  => array(
					'type'        => 'number',
					'label'       => __( 'Font Size (px)', 'mcp-ai-wpoos' ),
					'description' => __( 'Base font size for chat messages in pixels. Leave empty for default (14px).', 'mcp-ai-wpoos' ),
					'default'     => 14,
					'placeholder' => '14',
					'min'         => 10,
					'max'         => 24,
				),
				'chat_show_timestamps'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Timestamps', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Display timestamps on messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Shows the time each message was sent below the message bubble.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_show_avatars'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Avatars', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Display user and assistant avatars', 'mcp-ai-wpoos' ),
					'description'    => __( 'Shows avatar images next to messages in the chat interface.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_compact_mode'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Compact Mode', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Use compact message spacing', 'mcp-ai-wpoos' ),
					'description'    => __( 'Reduces spacing between messages for a more condensed view.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'chat_colors'                     => array(
					'type'    => 'html',
					'content' => $this->get_chat_colors_html(),
				),
				// Behavior subtab fields.
				'chat_max_history_display'        => array(
					'type'        => 'number',
					'label'       => __( 'Max History Messages to Display', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of messages to display in the chat history. Older messages will be collapsed.', 'mcp-ai-wpoos' ),
					'default'     => 50,
					'placeholder' => '50',
					'min'         => 10,
					'max'         => 200,
				),
				'chat_message_delay'              => array(
					'type'        => 'number',
					'label'       => __( 'Message Animation Delay (ms)', 'mcp-ai-wpoos' ),
					'description' => __( 'Delay in milliseconds for message appearance animation. Set to 0 to disable.', 'mcp-ai-wpoos' ),
					'default'     => 300,
					'placeholder' => '300',
					'min'         => 0,
					'max'         => 2000,
				),
				'chat_enable_typing_indicator'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Typing Indicator', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Show typing indicator while assistant is responding', 'mcp-ai-wpoos' ),
					'description'    => __( 'Displays animated "..." indicator when the assistant is processing a response.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_auto_scroll'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto-Scroll', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Automatically scroll to newest messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Automatically scrolls the chat window to show the latest message when new messages arrive.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_markdown'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Markdown Rendering', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable markdown formatting in messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows rendering of markdown syntax (bold, italic, links, code blocks) in messages.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_code_highlighting'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Code Syntax Highlighting', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable syntax highlighting for code blocks', 'mcp-ai-wpoos' ),
					'description'    => __( 'Applies syntax highlighting to code blocks in messages for better readability.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_persist_history'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Persist Chat History', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Save chat history to browser localStorage', 'mcp-ai-wpoos' ),
					'description'    => __( 'Automatically saves conversation history locally so users can resume their chats. History expires after 24 hours.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_welcome_message'            => array(
					'type'        => 'textarea',
					'label'       => __( 'Welcome Message', 'mcp-ai-wpoos' ),
					'description' => __( 'Initial message displayed when chat loads. Leave empty to disable welcome message.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Hello! How can I help you today?', 'mcp-ai-wpoos' ),
					'rows'        => 3,
				),
				'chat_placeholder_text'           => array(
					'type'        => 'text',
					'label'       => __( 'Input Placeholder Text', 'mcp-ai-wpoos' ),
					'description' => __( 'Placeholder text shown in the message input field.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Type your message...', 'mcp-ai-wpoos' ),
				),
				'chat_send_button_text'           => array(
					'type'        => 'text',
					'label'       => __( 'Send Button Text', 'mcp-ai-wpoos' ),
					'description' => __( 'Text shown on the send button. Leave empty to use icon only.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Send', 'mcp-ai-wpoos' ),
				),
				'show_usage_costs'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Usage Costs', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Display token usage and estimated costs in chat interface', 'mcp-ai-wpoos' ),
					'description'    => __( 'Shows small badges with total tokens and estimated cost (in USD) after each assistant response in the frontend chat. Helps users understand API usage and costs in real-time. Phase 7: Enhanced Token Tracking with Real-Time Cost Attribution.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'show_capability_flags'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Capability Flags', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Display tool capability flags in chat interface', 'mcp-ai-wpoos' ),
					'description'    => __( 'Shows badges indicating tool capabilities (e.g., "read-only", "external-api", "write", "local-only") after messages that use tools. Helps users understand what operations tools can perform.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				// Features subtab fields.
				'chat_enable_copy_button'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Copy Button', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable copy-to-clipboard button for messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Adds a copy button to each message, allowing users to copy message content to their clipboard.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_save_button'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Save Button', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable save button for individual messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to save individual messages to their local storage for later reference.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_delete_button'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Delete Button', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable delete button for messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to delete messages from their chat history.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_speech_button'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Text-to-Speech Button', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable text-to-speech audio playback for messages', 'mcp-ai-wpoos' ),
					'description'    => __( 'Adds a speech button to messages, allowing users to listen to assistant responses. Requires OpenAI TTS tool to be enabled.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_transcribe_button'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Voice Input (Transcription)', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable voice-to-text transcription for user input', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to record voice messages that are transcribed to text. Requires OpenAI Whisper tool to be enabled.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_file_upload'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'File Upload', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable file attachment uploads in chat', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to upload files (images, documents) as attachments to their messages.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_tool_shortcuts'      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Tool Shortcuts', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable quick access tool shortcut buttons', 'mcp-ai-wpoos' ),
					'description'    => __( 'Displays quick access buttons for frequently used tools in the chat interface.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_search'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Message Search', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable search functionality for chat history', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to search through their chat history to find specific messages or topics.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_export'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Export Conversation', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable export conversation to text/PDF', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to export their conversation history to a downloadable file.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_enable_regenerate'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Regenerate Response', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable regenerate button for assistant responses', 'mcp-ai-wpoos' ),
					'description'    => __( 'Allows users to request a new response from the assistant for the same query.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'chat_allowed_file_types'         => array(
					'type'        => 'text',
					'label'       => __( 'Allowed File Types', 'mcp-ai-wpoos' ),
					'description' => __( 'Comma-separated list of allowed file extensions (e.g., jpg,png,pdf,docx). Leave empty for default allowed types.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => 'jpg,png,pdf,docx',
				),
				'chat_max_file_size_mb'           => array(
					'type'        => 'number',
					'label'       => __( 'Max File Size (MB)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum file size for uploads in megabytes. Set to 0 to use server default.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'placeholder' => '10',
					'min'         => 0,
					'max'         => 100,
				),
				// Presets subtab (no form fields, handled via custom rendering).
				'chat_preset_applied'             => array(
					'type'    => 'hidden',
					'label'   => '',
					'default' => '',
				),
				// LLM Sanitization subtab fields.
				'chat_llm_sanitize_level'         => array(
					'type'        => 'select',
					'label'       => __( 'LLM Response Sanitization Level', 'mcp-ai-wpoos' ),
					'description' => __( 'Controls how strictly LLM responses are sanitized before display in the chat client.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'none'     => __( 'None - Display raw LLM output', 'mcp-ai-wpoos' ),
						'basic'    => __( 'Basic - Strip harmful HTML/JavaScript', 'mcp-ai-wpoos' ),
						'moderate' => __( 'Moderate - Allow safe HTML, strip scripts and iframes', 'mcp-ai-wpoos' ),
						'strict'   => __( 'Strict - Convert all HTML to plain text', 'mcp-ai-wpoos' ),
					),
					'default'     => 'moderate',
				),
				'chat_llm_max_response_length'    => array(
					'type'        => 'number',
					'label'       => __( 'Max Response Length (characters)', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum number of characters to display in a single LLM response. Longer responses will be truncated. Set to 0 for unlimited.', 'mcp-ai-wpoos' ),
					'default'     => 0,
					'placeholder' => '0 (unlimited)',
					'min'         => 0,
					'max'         => 100000,
				),
				'chat_llm_show_3_results_buttons' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show 3 Result Buttons', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Display 3 alternative result action buttons in chat client', 'mcp-ai-wpoos' ),
					'description'    => __( 'When enabled, shows 3 action buttons (e.g., Refine, Alternative, Expand) for each assistant response, allowing users to request variations of the answer.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),
				'chat_llm_result_button_1_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 1 Label', 'mcp-ai-wpoos' ),
					'description' => __( 'Label for the first result action button. Default: "Refine"', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Refine', 'mcp-ai-wpoos' ),
				),
				'chat_llm_result_button_1_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 1 Prompt', 'mcp-ai-wpoos' ),
					'description' => __( 'System prompt sent to LLM when button 1 is clicked. Use {original_response} placeholder for the original message.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Please refine your previous response: {original_response}', 'mcp-ai-wpoos' ),
				),
				'chat_llm_result_button_2_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 2 Label', 'mcp-ai-wpoos' ),
					'description' => __( 'Label for the second result action button. Default: "Alternative"', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Alternative', 'mcp-ai-wpoos' ),
				),
				'chat_llm_result_button_2_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 2 Prompt', 'mcp-ai-wpoos' ),
					'description' => __( 'System prompt sent to LLM when button 2 is clicked. Use {original_response} placeholder for the original message.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Please provide an alternative approach to: {original_response}', 'mcp-ai-wpoos' ),
				),
				'chat_llm_result_button_3_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 3 Label', 'mcp-ai-wpoos' ),
					'description' => __( 'Label for the third result action button. Default: "Expand"', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Expand', 'mcp-ai-wpoos' ),
				),
				'chat_llm_result_button_3_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 3 Prompt', 'mcp-ai-wpoos' ),
					'description' => __( 'System prompt sent to LLM when button 3 is clicked. Use {original_response} placeholder for the original message.', 'mcp-ai-wpoos' ),
					'default'     => '',
					'placeholder' => __( 'Please expand on your previous response with more detail: {original_response}', 'mcp-ai-wpoos' ),
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'appearance'           => array(
					'id'     => 'appearance',
					'label'  => __( 'Appearance', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-appearance',
					'fields' => array(
						'chat_theme',
						'chat_primary_color',
						'chat_user_bubble_color',
						'chat_assistant_bubble_color',
						'chat_border_radius',
						'chat_font_size',
						'chat_show_timestamps',
						'chat_show_avatars',
						'chat_compact_mode',
						'chat_colors',
					),
				),
				'behavior-chat-client' => array(
					'id'     => 'behavior-chat-client',
					'label'  => __( 'Behavior', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-performance',
					'fields' => array(
						'chat_max_history_display',
						'chat_message_delay',
						'chat_enable_typing_indicator',
						'chat_auto_scroll',
						'chat_enable_markdown',
						'chat_enable_code_highlighting',
						'chat_persist_history',
						'chat_welcome_message',
						'chat_placeholder_text',
						'chat_send_button_text',
						'show_usage_costs',
						'show_capability_flags',
					),
				),
				'features'             => array(
					'id'     => 'features',
					'label'  => __( 'Features', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array(
						'chat_enable_copy_button',
						'chat_enable_save_button',
						'chat_enable_delete_button',
						'chat_enable_speech_button',
						'chat_enable_transcribe_button',
						'chat_enable_file_upload',
						'chat_enable_tool_shortcuts',
						'chat_enable_search',
						'chat_enable_export',
						'chat_enable_regenerate',
						'chat_allowed_file_types',
						'chat_max_file_size_mb',
					),
				),
				'llm_sanitization'     => array(
					'id'     => 'llm_sanitization',
					'label'  => __( 'LLM Sanitization', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-shield',
					'fields' => array(
						'chat_llm_sanitize_level',
						'chat_llm_max_response_length',
						'chat_llm_show_3_results_buttons',
						'chat_llm_result_button_1_label',
						'chat_llm_result_button_1_prompt',
						'chat_llm_result_button_2_label',
						'chat_llm_result_button_2_prompt',
						'chat_llm_result_button_3_label',
						'chat_llm_result_button_3_prompt',
					),
				),
				'presets'              => array(
					'id'     => 'presets',
					'label'  => __( 'Presets', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-list-view',
					'fields' => array(), // No form fields, custom rendering.
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'appearance' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'appearance';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}

			// Render presets UI if we're on the presets sub-tab.
			if ( 'presets' === $active_subtab ) {
				echo '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Close the form table.
				$this->render_presets_ui();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag, Re-open hidden table for structure.
			}
		}

		/**
		 * Render the presets UI.
		 */
		private function render_presets_ui() {
			?>
			<div class="wp-mcp-ai-chat-presets" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 20px;">
				<h3><?php esc_html_e( 'Chat Client Presets', 'mcp-ai-wpoos' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Quickly configure your chat client with pre-designed settings optimized for different use cases.', 'mcp-ai-wpoos' ); ?>
				</p>

				<div class="wp-mcp-ai-presets-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
					<!-- Minimal Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-minus" style="color: #0073aa;"></span>
							<?php esc_html_e( 'Minimal', 'mcp-ai-wpoos' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Clean, distraction-free interface with only essential features. Perfect for focused conversations.', 'mcp-ai-wpoos' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Light theme', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Copy button only', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'No file uploads', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Basic markdown', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Moderate sanitization', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset"
							data-preset="minimal" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>

					<!-- Full-Featured Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-plus" style="color: #46b450;"></span>
							<?php esc_html_e( 'Full-Featured', 'mcp-ai-wpoos' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'All features enabled for maximum functionality. Best for power users and comprehensive interactions.', 'mcp-ai-wpoos' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'All features enabled', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'File uploads allowed', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Voice input/output', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Code highlighting', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Export & search', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset"
							data-preset="full_featured" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>

					<!-- Professional Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-businessman" style="color: #826eb4;"></span>
							<?php esc_html_e( 'Professional', 'mcp-ai-wpoos' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Business-focused setup with document handling, exports, and strict sanitization for enterprise use.', 'mcp-ai-wpoos' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Light/Dark theme', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'File uploads (docs)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Export conversations', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Search enabled', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Strict sanitization', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset"
							data-preset="professional" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>

					<!-- Accessible Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-universal-access" style="color: #f56e28;"></span>
							<?php esc_html_e( 'Accessible', 'mcp-ai-wpoos' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Optimized for accessibility with larger text, high contrast, and voice features for all users.', 'mcp-ai-wpoos' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Large font size (18px)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'High contrast colors', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Voice input/output', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Timestamps & avatars', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Clear visual feedback', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset"
							data-preset="accessible" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'mcp-ai-wpoos' ); ?>
						</button>
					</div>
				</div>

				<div class="wp-mcp-ai-preset-notice" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-top: 20px; display: none;">
					<p style="margin: 0;">
						<strong><?php esc_html_e( 'Preset Applied!', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Your settings have been updated. Click "Save Changes" below to apply them.', 'mcp-ai-wpoos' ); ?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Chat client sub-tabs', 'mcp-ai-wpoos' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'general',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>"
								class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get chat colors HTML content.
		 *
		 * @return string
		 */
		private function get_chat_colors_html() {
			// Delegate to the legacy settings renderer if available.
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'get_chat_color_definitions' ) ) {
				$settings    = WP_MCP_AI_Admin_Settings::get_settings();
				$colors      = isset( $settings['chat_colors'] ) && is_array( $settings['chat_colors'] ) ? $settings['chat_colors'] : WP_MCP_AI_Admin_Settings::get_default_chat_colors();
				$definitions = WP_MCP_AI_Admin_Settings::get_chat_color_definitions();
				$groups      = WP_MCP_AI_Admin_Settings::get_chat_color_groups();

				ob_start();
				echo '<div class="wp-mcp-ai-chat-colors">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
				echo '<p class="description">' . esc_html__( 'Customize the colors used throughout the chat interface. Leave fields empty to use default values.', 'mcp-ai-wpoos' ) . '</p>';

				foreach ( $groups as $group_key => $group_label ) {
					$group_colors = array();

					foreach ( $definitions as $color_key => $definition ) {
						if ( isset( $definition['group'] ) && $group_key === $definition['group'] ) {
							$group_colors[ $color_key ] = $definition;
						}
					}

					if ( empty( $group_colors ) ) {
						continue;
					}

					echo '<fieldset class="wp-mcp-ai-chat-colors__group">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					echo '<legend>' . esc_html( $group_label ) . '</legend>';

					foreach ( $group_colors as $color_key => $definition ) {
						$input_id     = 'wp-mcp-ai-color-' . sanitize_html_class( $color_key );
						$value        = isset( $colors[ $color_key ] ) ? $colors[ $color_key ] : $definition['default'];
						$format       = isset( $definition['format'] ) ? strtolower( $definition['format'] ) : 'hex';
						$descriptions = array();

						if ( ! empty( $definition['description'] ) ) {
							$descriptions[] = $definition['description'];
						}

						if ( 'rgba' === $format ) {
							$descriptions[] = __( 'Enter a value in rgba(R, G, B, A) format.', 'mcp-ai-wpoos' );
						}

						echo '<div class="wp-mcp-ai-chat-colors__field">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
						echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $definition['label'] ) . '</label>';
						echo '<input type="text" id="' . esc_attr( $input_id ) . '" class="regular-text wp-mcp-ai-color-field" name="wp_mcp_ai_settings[chat_colors][' . esc_attr( $color_key ) . ']" value="' . esc_attr( $value ) . '" data-format="' . esc_attr( $format ) . '" data-default-color="' . esc_attr( $definition['default'] ) . '" />';

						foreach ( $descriptions as $text ) {
							echo '<p class="description">' . esc_html( $text ) . '</p>';
						}

						echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
					}

					echo '</fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
				}

				echo '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML tag.
				return ob_get_clean();
			}

			return '<div class="notice notice-warning inline"><p>' . esc_html__( 'Chat colors configuration is not available.', 'mcp-ai-wpoos' ) . '</p></div>';
		}
	}
}
