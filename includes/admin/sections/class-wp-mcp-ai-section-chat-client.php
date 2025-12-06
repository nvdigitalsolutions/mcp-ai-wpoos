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
			return __( 'Chat Client', 'wp-mcp-ai' );
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
			return __( 'Configure chat interface settings, behavior, and features for the frontend chat client.', 'wp-mcp-ai' );
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
					'label'       => __( 'Chat Theme', 'wp-mcp-ai' ),
					'description' => __( 'Select the visual theme for the chat interface.', 'wp-mcp-ai' ),
					'options'     => array(
						'light' => __( 'Light', 'wp-mcp-ai' ),
						'dark'  => __( 'Dark', 'wp-mcp-ai' ),
						'auto'  => __( 'Auto (System Preference)', 'wp-mcp-ai' ),
					),
					'default'     => 'light',
				),
				'chat_primary_color'              => array(
					'type'        => 'text',
					'label'       => __( 'Primary Color', 'wp-mcp-ai' ),
					'description' => __( 'HEX color code for primary UI elements (e.g., #0073aa). Leave empty for default.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '#0073aa',
				),
				'chat_user_bubble_color'          => array(
					'type'        => 'text',
					'label'       => __( 'User Message Bubble Color', 'wp-mcp-ai' ),
					'description' => __( 'HEX color code for user message bubbles. Leave empty for default.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '#E3F2FD',
				),
				'chat_assistant_bubble_color'     => array(
					'type'        => 'text',
					'label'       => __( 'Assistant Message Bubble Color', 'wp-mcp-ai' ),
					'description' => __( 'HEX color code for assistant message bubbles. Leave empty for default.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '#F5F5F5',
				),
				'chat_border_radius'              => array(
					'type'        => 'number',
					'label'       => __( 'Border Radius (px)', 'wp-mcp-ai' ),
					'description' => __( 'Border radius for chat bubbles in pixels. Higher values create more rounded corners.', 'wp-mcp-ai' ),
					'default'     => 12,
					'placeholder' => '12',
					'min'         => 0,
					'max'         => 50,
				),
				'chat_font_size'                  => array(
					'type'        => 'number',
					'label'       => __( 'Font Size (px)', 'wp-mcp-ai' ),
					'description' => __( 'Base font size for chat messages in pixels. Leave empty for default (14px).', 'wp-mcp-ai' ),
					'default'     => 14,
					'placeholder' => '14',
					'min'         => 10,
					'max'         => 24,
				),
				'chat_show_timestamps'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Timestamps', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Display timestamps on messages', 'wp-mcp-ai' ),
					'description'    => __( 'Shows the time each message was sent below the message bubble.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_show_avatars'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show Avatars', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Display user and assistant avatars', 'wp-mcp-ai' ),
					'description'    => __( 'Shows avatar images next to messages in the chat interface.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_compact_mode'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Compact Mode', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Use compact message spacing', 'wp-mcp-ai' ),
					'description'    => __( 'Reduces spacing between messages for a more condensed view.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				// Behavior subtab fields.
				'chat_max_history_display'        => array(
					'type'        => 'number',
					'label'       => __( 'Max History Messages to Display', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of messages to display in the chat history. Older messages will be collapsed.', 'wp-mcp-ai' ),
					'default'     => 50,
					'placeholder' => '50',
					'min'         => 10,
					'max'         => 200,
				),
				'chat_message_delay'              => array(
					'type'        => 'number',
					'label'       => __( 'Message Animation Delay (ms)', 'wp-mcp-ai' ),
					'description' => __( 'Delay in milliseconds for message appearance animation. Set to 0 to disable.', 'wp-mcp-ai' ),
					'default'     => 300,
					'placeholder' => '300',
					'min'         => 0,
					'max'         => 2000,
				),
				'chat_enable_typing_indicator'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Typing Indicator', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Show typing indicator while assistant is responding', 'wp-mcp-ai' ),
					'description'    => __( 'Displays animated "..." indicator when the assistant is processing a response.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_auto_scroll'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Auto-Scroll', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically scroll to newest messages', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically scrolls the chat window to show the latest message when new messages arrive.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_markdown'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Markdown Rendering', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable markdown formatting in messages', 'wp-mcp-ai' ),
					'description'    => __( 'Allows rendering of markdown syntax (bold, italic, links, code blocks) in messages.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_code_highlighting'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Code Syntax Highlighting', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable syntax highlighting for code blocks', 'wp-mcp-ai' ),
					'description'    => __( 'Applies syntax highlighting to code blocks in messages for better readability.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_persist_history'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Persist Chat History', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Save chat history to browser localStorage', 'wp-mcp-ai' ),
					'description'    => __( 'Automatically saves conversation history locally so users can resume their chats. History expires after 24 hours.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_welcome_message'            => array(
					'type'        => 'textarea',
					'label'       => __( 'Welcome Message', 'wp-mcp-ai' ),
					'description' => __( 'Initial message displayed when chat loads. Leave empty to disable welcome message.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Hello! How can I help you today?', 'wp-mcp-ai' ),
					'rows'        => 3,
				),
				'chat_placeholder_text'           => array(
					'type'        => 'text',
					'label'       => __( 'Input Placeholder Text', 'wp-mcp-ai' ),
					'description' => __( 'Placeholder text shown in the message input field.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Type your message...', 'wp-mcp-ai' ),
				),
				'chat_send_button_text'           => array(
					'type'        => 'text',
					'label'       => __( 'Send Button Text', 'wp-mcp-ai' ),
					'description' => __( 'Text shown on the send button. Leave empty to use icon only.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Send', 'wp-mcp-ai' ),
				),
				// Features subtab fields.
				'chat_enable_copy_button'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Copy Button', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable copy-to-clipboard button for messages', 'wp-mcp-ai' ),
					'description'    => __( 'Adds a copy button to each message, allowing users to copy message content to their clipboard.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_save_button'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'Save Button', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable save button for individual messages', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to save individual messages to their local storage for later reference.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_delete_button'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Delete Button', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable delete button for messages', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to delete messages from their chat history.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_speech_button'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Text-to-Speech Button', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable text-to-speech audio playback for messages', 'wp-mcp-ai' ),
					'description'    => __( 'Adds a speech button to messages, allowing users to listen to assistant responses. Requires OpenAI TTS tool to be enabled.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_transcribe_button'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Voice Input (Transcription)', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable voice-to-text transcription for user input', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to record voice messages that are transcribed to text. Requires OpenAI Whisper tool to be enabled.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_file_upload'         => array(
					'type'           => 'checkbox',
					'label'          => __( 'File Upload', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable file attachment uploads in chat', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to upload files (images, documents) as attachments to their messages.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_tool_shortcuts'      => array(
					'type'           => 'checkbox',
					'label'          => __( 'Tool Shortcuts', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable quick access tool shortcut buttons', 'wp-mcp-ai' ),
					'description'    => __( 'Displays quick access buttons for frequently used tools in the chat interface.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_search'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Message Search', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable search functionality for chat history', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to search through their chat history to find specific messages or topics.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_export'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Export Conversation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable export conversation to text/PDF', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to export their conversation history to a downloadable file.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_enable_regenerate'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Regenerate Response', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable regenerate button for assistant responses', 'wp-mcp-ai' ),
					'description'    => __( 'Allows users to request a new response from the assistant for the same query.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'chat_allowed_file_types'         => array(
					'type'        => 'text',
					'label'       => __( 'Allowed File Types', 'wp-mcp-ai' ),
					'description' => __( 'Comma-separated list of allowed file extensions (e.g., jpg,png,pdf,docx). Leave empty for default allowed types.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'jpg,png,pdf,docx',
				),
				'chat_max_file_size_mb'           => array(
					'type'        => 'number',
					'label'       => __( 'Max File Size (MB)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum file size for uploads in megabytes. Set to 0 to use server default.', 'wp-mcp-ai' ),
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
					'label'       => __( 'LLM Response Sanitization Level', 'wp-mcp-ai' ),
					'description' => __( 'Controls how strictly LLM responses are sanitized before display in the chat client.', 'wp-mcp-ai' ),
					'options'     => array(
						'none'     => __( 'None - Display raw LLM output', 'wp-mcp-ai' ),
						'basic'    => __( 'Basic - Strip harmful HTML/JavaScript', 'wp-mcp-ai' ),
						'moderate' => __( 'Moderate - Allow safe HTML, strip scripts and iframes', 'wp-mcp-ai' ),
						'strict'   => __( 'Strict - Convert all HTML to plain text', 'wp-mcp-ai' ),
					),
					'default'     => 'moderate',
				),
				'chat_llm_max_response_length'    => array(
					'type'        => 'number',
					'label'       => __( 'Max Response Length (characters)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of characters to display in a single LLM response. Longer responses will be truncated. Set to 0 for unlimited.', 'wp-mcp-ai' ),
					'default'     => 0,
					'placeholder' => '0 (unlimited)',
					'min'         => 0,
					'max'         => 100000,
				),
				'chat_llm_show_3_results_buttons' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Show 3 Result Buttons', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Display 3 alternative result action buttons in chat client', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, shows 3 action buttons (e.g., Refine, Alternative, Expand) for each assistant response, allowing users to request variations of the answer.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'chat_llm_result_button_1_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 1 Label', 'wp-mcp-ai' ),
					'description' => __( 'Label for the first result action button. Default: "Refine"', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Refine', 'wp-mcp-ai' ),
				),
				'chat_llm_result_button_1_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 1 Prompt', 'wp-mcp-ai' ),
					'description' => __( 'System prompt sent to LLM when button 1 is clicked. Use {original_response} placeholder for the original message.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Please refine your previous response: {original_response}', 'wp-mcp-ai' ),
				),
				'chat_llm_result_button_2_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 2 Label', 'wp-mcp-ai' ),
					'description' => __( 'Label for the second result action button. Default: "Alternative"', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Alternative', 'wp-mcp-ai' ),
				),
				'chat_llm_result_button_2_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 2 Prompt', 'wp-mcp-ai' ),
					'description' => __( 'System prompt sent to LLM when button 2 is clicked. Use {original_response} placeholder for the original message.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Please provide an alternative approach to: {original_response}', 'wp-mcp-ai' ),
				),
				'chat_llm_result_button_3_label'  => array(
					'type'        => 'text',
					'label'       => __( 'Result Button 3 Label', 'wp-mcp-ai' ),
					'description' => __( 'Label for the third result action button. Default: "Expand"', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Expand', 'wp-mcp-ai' ),
				),
				'chat_llm_result_button_3_prompt' => array(
					'type'        => 'textarea',
					'label'       => __( 'Result Button 3 Prompt', 'wp-mcp-ai' ),
					'description' => __( 'System prompt sent to LLM when button 3 is clicked. Use {original_response} placeholder for the original message.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Please expand on your previous response with more detail: {original_response}', 'wp-mcp-ai' ),
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
					'label'  => __( 'Appearance', 'wp-mcp-ai' ),
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
					),
				),
				'behavior-chat-client' => array(
					'id'     => 'behavior-chat-client',
					'label'  => __( 'Behavior', 'wp-mcp-ai' ),
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
					),
				),
				'features'             => array(
					'id'     => 'features',
					'label'  => __( 'Features', 'wp-mcp-ai' ),
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
					'label'  => __( 'LLM Sanitization', 'wp-mcp-ai' ),
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
					'label'  => __( 'Presets', 'wp-mcp-ai' ),
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
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
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
				echo '</table>'; // Close the form table.
				$this->render_presets_ui();
				echo '<table class="form-table" role="presentation" style="display:none;">'; // Re-open hidden table for structure.
			}
		}

		/**
		 * Render the presets UI.
		 */
		private function render_presets_ui() {
			?>
			<div class="wp-mcp-ai-chat-presets" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 20px;">
				<h3><?php esc_html_e( 'Chat Client Presets', 'wp-mcp-ai' ); ?></h3>
				<p class="description">
					<?php esc_html_e( 'Quickly configure your chat client with pre-designed settings optimized for different use cases.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-presets-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; margin-top: 20px;">
					<!-- Minimal Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-minus" style="color: #0073aa;"></span>
							<?php esc_html_e( 'Minimal', 'wp-mcp-ai' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Clean, distraction-free interface with only essential features. Perfect for focused conversations.', 'wp-mcp-ai' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Light theme', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Copy button only', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'No file uploads', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Basic markdown', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Moderate sanitization', 'wp-mcp-ai' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset" 
							data-preset="minimal" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<!-- Full-Featured Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-plus" style="color: #46b450;"></span>
							<?php esc_html_e( 'Full-Featured', 'wp-mcp-ai' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'All features enabled for maximum functionality. Best for power users and comprehensive interactions.', 'wp-mcp-ai' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'All features enabled', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'File uploads allowed', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Voice input/output', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Code highlighting', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Export & search', 'wp-mcp-ai' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset" 
							data-preset="full_featured" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<!-- Professional Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-businessman" style="color: #826eb4;"></span>
							<?php esc_html_e( 'Professional', 'wp-mcp-ai' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Business-focused setup with document handling, exports, and strict sanitization for enterprise use.', 'wp-mcp-ai' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Light/Dark theme', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'File uploads (docs)', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Export conversations', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Search enabled', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Strict sanitization', 'wp-mcp-ai' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset" 
							data-preset="professional" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'wp-mcp-ai' ); ?>
						</button>
					</div>

					<!-- Accessible Preset -->
					<div class="preset-card" style="border: 1px solid #ddd; border-radius: 4px; padding: 15px;">
						<h4 style="margin-top: 0;">
							<span class="dashicons dashicons-universal-access" style="color: #f56e28;"></span>
							<?php esc_html_e( 'Accessible', 'wp-mcp-ai' ); ?>
						</h4>
						<p class="description">
							<?php esc_html_e( 'Optimized for accessibility with larger text, high contrast, and voice features for all users.', 'wp-mcp-ai' ); ?>
						</p>
						<ul style="list-style: disc; margin-left: 20px; font-size: 13px;">
							<li><?php esc_html_e( 'Large font size (18px)', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'High contrast colors', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Voice input/output', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Timestamps & avatars', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Clear visual feedback', 'wp-mcp-ai' ); ?></li>
						</ul>
						<button type="button" class="button button-secondary wp-mcp-ai-apply-preset" 
							data-preset="accessible" style="margin-top: 10px; width: 100%;">
							<?php esc_html_e( 'Apply Preset', 'wp-mcp-ai' ); ?>
						</button>
					</div>
				</div>

				<div class="wp-mcp-ai-preset-notice" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-top: 20px; display: none;">
					<p style="margin: 0;">
						<strong><?php esc_html_e( 'Preset Applied!', 'wp-mcp-ai' ); ?></strong>
						<?php esc_html_e( 'Your settings have been updated. Click "Save Changes" below to apply them.', 'wp-mcp-ai' ); ?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Chat client sub-tabs', 'wp-mcp-ai' ); ?>">
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
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
