<?php
/**
 * Test Assistant Admin Page
 *
 * Provides an interface for administrators to test AI assistants directly from the WordPress admin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Test_Assistant' ) ) {
	/**
	 * Test Assistant admin page handler.
	 */
	class WP_MCP_AI_Admin_Test_Assistant {

		/**
		 * Page hook suffix.
		 *
		 * @var string|false
		 */
		private $page_hook;

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Hook into admin_menu to ensure all dependencies are loaded.
			add_action( 'admin_menu', array( $this, 'register_submenu_page' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the submenu page under AI Assistants.
		 */
		public function register_submenu_page() {
			// Safety check: Ensure the Assistant CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				return;
			}

			// Safety check: Ensure POST_TYPE constant exists.
			if ( ! defined( 'WP_MCP_AI_Assistant_CPT::POST_TYPE' ) ) {
				$post_type = 'mcp_ai_assistant'; // Fallback to default.
			} else {
				$post_type = WP_MCP_AI_Assistant_CPT::POST_TYPE;
			}

			$this->page_hook = add_submenu_page(
				'edit.php?post_type=' . $post_type,
				__( 'Test Assistant', 'wp-mcp-ai' ),
				__( 'Test Assistant', 'wp-mcp-ai' ),
				'manage_options',
				'wp-mcp-ai-test-assistant',
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the test assistant page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( $hook !== $this->page_hook ) {
				return;
			}

			// Enqueue chat.js and its dependencies.
			$script_relative = 'assets/js/chat.js';
			$style_relative  = 'assets/css/chat.css';

			$script_path = WP_MCP_AI_URL . $script_relative;
			$style_path  = WP_MCP_AI_URL . $style_relative;

			$script_version = $this->get_asset_version( $script_relative );
			$style_version  = $this->get_asset_version( $style_relative );

			wp_enqueue_style(
				'wp-mcp-ai-chat',
				$style_path,
				array(),
				$style_version
			);

			wp_enqueue_script(
				'wp-mcp-ai-chat',
				$script_path,
				array(),
				$script_version,
				true
			);

			// Safety check: Ensure REST constants exist.
			$rest_namespace = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

			// Prepare file upload configuration for admin users.
			$file_config = $this->get_file_upload_config();

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

			// Enqueue test assistant specific assets.
			$test_script_relative = 'assets/js/admin-test-assistant.js';
			$test_style_relative  = 'assets/css/admin-test-assistant.css';

			wp_enqueue_style(
				'wp-mcp-ai-admin-test-assistant',
				WP_MCP_AI_URL . $test_style_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_style_relative )
			);

			wp_enqueue_script(
				'wp-mcp-ai-admin-test-assistant',
				WP_MCP_AI_URL . $test_script_relative,
				array( 'wp-mcp-ai-chat' ),
				$this->get_asset_version( $test_script_relative ),
				true
			);
		}

		/**
		 * Normalize REST URL (fallback if WP_MCP_AI_Request_Context is not available).
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
		 * Get chat interface strings for localization.
		 *
		 * @return array
		 */
		private function get_chat_strings() {
			return array(
				'placeholder'               => __( 'Ask something…', 'wp-mcp-ai' ),
				'send'                      => __( 'Send', 'wp-mcp-ai' ),
				'bundlingMessages'          => __( 'Preparing to send…', 'wp-mcp-ai' ),
				'sending'                   => __( 'Sending message…', 'wp-mcp-ai' ),
				'waiting'                   => __( 'Waiting for the assistant…', 'wp-mcp-ai' ),
				'error'                     => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
				'missingAssistant'          => __( 'Assistant configuration was not found.', 'wp-mcp-ai' ),
				'notAuthorized'             => __( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ),
				/* translators: %s: tool name being executed */
				'toolExecuting'             => __( 'Running tool: %s', 'wp-mcp-ai' ),
				'toolSuccess'               => __( 'Tool response ready.', 'wp-mcp-ai' ),
				'toolError'                 => __( 'The tool request failed.', 'wp-mcp-ai' ),
				'toolQueued'                => __( 'Crawl queued. Results will appear shortly.', 'wp-mcp-ai' ),
				'toolPolling'               => __( 'Crawl in progress…', 'wp-mcp-ai' ),
				'toolTimeout'               => __( 'Crawl timed out before completing.', 'wp-mcp-ai' ),
				/* translators: %s: crawl failure error message */
				'toolFailed'                => __( 'The crawl failed: %s', 'wp-mcp-ai' ),
				'speechToolSuccess'         => __( 'Speech audio saved to the Media Library.', 'wp-mcp-ai' ),
				'imageToolSuccess'          => __( 'Image saved to the Media Library.', 'wp-mcp-ai' ),
				/* translators: %s: task name */
				'toolShortcutLabel'         => __( 'Insert task: %s', 'wp-mcp-ai' ),
				'emptyMessage'              => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
				'attachFile'                => __( 'Attach file', 'wp-mcp-ai' ),
				'transcribe'                => __( 'Transcribe', 'wp-mcp-ai' ),
				'transcribeAudio'           => __( 'Transcribe audio', 'wp-mcp-ai' ),
				'transcribing'              => __( 'Transcribing audio…', 'wp-mcp-ai' ),
				'recording'                 => __( 'Recording… tap to stop.', 'wp-mcp-ai' ),
				'stopRecording'             => __( 'Stop recording', 'wp-mcp-ai' ),
				'recordingError'            => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'wp-mcp-ai' ),
				'transcriptionError'        => __( 'The transcription request failed. Please try again.', 'wp-mcp-ai' ),
				/* translators: %s: file name */
				'transcriptionSuccess'      => __( 'Inserted transcription from "%s".', 'wp-mcp-ai' ),
				'transcriptionFileTooLarge' => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'wp-mcp-ai' ),
				'transcribeChooseSource'    => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'wp-mcp-ai' ),
				'attachmentsLabel'          => __( 'Attachments', 'wp-mcp-ai' ),
				'removeAttachment'          => __( 'Remove', 'wp-mcp-ai' ),
				/* translators: %s: file name being uploaded */
				'uploadingFile'             => __( 'Uploading "%s"…', 'wp-mcp-ai' ),
				'uploadError'               => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
				'uploadInProgress'          => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
				'downloadAttachment'        => __( 'Download attachment', 'wp-mcp-ai' ),
				/* translators: %s: file name with unsupported type */
				'unsupportedFileType'       => __( '"%s" is not a supported file type. Please choose a different file.', 'wp-mcp-ai' ),
				'unsupportedMultipleFiles'  => __( 'Some selected files are not supported. Please try different files.', 'wp-mcp-ai' ),
				'unsupportedFileLabel'      => __( 'This file', 'wp-mcp-ai' ),
				'expandTranscript'          => __( 'Expand conversation', 'wp-mcp-ai' ),
				'collapseTranscript'        => __( 'Collapse conversation', 'wp-mcp-ai' ),
				'newConversation'           => __( 'Start new conversation', 'wp-mcp-ai' ),
				'loadConversation'          => __( 'Load conversation', 'wp-mcp-ai' ),
				'jsonResponse'              => __( 'JSON response', 'wp-mcp-ai' ),
				'historyToggleShow'         => __( 'Show previous conversations', 'wp-mcp-ai' ),
				'historyToggleHide'         => __( 'Hide previous conversations', 'wp-mcp-ai' ),
				'historyLoading'            => __( 'Loading conversations…', 'wp-mcp-ai' ),
				'historyEmpty'              => __( 'No previous conversations yet.', 'wp-mcp-ai' ),
				'historyError'              => __( 'Unable to load conversation history.', 'wp-mcp-ai' ),
				/* translators: %d: number of messages in chat history */
				'historyMessageCount'       => __( '%d messages', 'wp-mcp-ai' ),
				'historySingleMessage'      => __( '1 message', 'wp-mcp-ai' ),
				/* translators: %s: conversation identifier */
				'historyPreviewFallback'    => __( 'Conversation %s', 'wp-mcp-ai' ),
				'historySessionLoading'     => __( 'Loading conversation…', 'wp-mcp-ai' ),
				'historySessionError'       => __( 'Unable to load this conversation. Please try again.', 'wp-mcp-ai' ),
				'historyNoMessages'         => __( 'No messages were saved for this conversation.', 'wp-mcp-ai' ),
				'roleLabels'                => array(
					'assistant' => __( 'Assistant', 'wp-mcp-ai' ),
					'user'      => __( 'You', 'wp-mcp-ai' ),
					'system'    => __( 'System', 'wp-mcp-ai' ),
					'tool'      => __( 'Tool', 'wp-mcp-ai' ),
				),
			);
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
		 * Render the test assistant page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
			}

			// Safety check: Ensure the Assistant CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				?>
				<div class="wrap">
					<h1><?php echo esc_html__( 'Test AI Assistants', 'wp-mcp-ai' ); ?></h1>
					<div class="notice notice-error">
						<p><?php echo esc_html__( 'The Assistant CPT class is not loaded. Please contact support.', 'wp-mcp-ai' ); ?></p>
					</div>
				</div>
				<?php
				return;
			}

			// Get post type safely.
			$post_type = defined( 'WP_MCP_AI_Assistant_CPT::POST_TYPE' ) ? WP_MCP_AI_Assistant_CPT::POST_TYPE : 'mcp_ai_assistant';

			// Get all published assistants.
			$assistants = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			?>
			<div class="wrap">
				<h1><?php echo esc_html__( 'Test AI Assistants', 'wp-mcp-ai' ); ?></h1>
				<p><?php echo esc_html__( 'Test your AI assistants directly from the admin dashboard. Click "Test" next to any assistant to open a chat interface and validate its behavior.', 'wp-mcp-ai' ); ?></p>

				<?php if ( empty( $assistants ) ) : ?>
					<div class="notice notice-warning">
						<p>
							<?php
							printf(
								/* translators: %s: URL to create new assistant */
								esc_html__( 'No assistants found. %s to get started.', 'wp-mcp-ai' ),
								'<a href="' . esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ) . '">' . esc_html__( 'Create your first assistant', 'wp-mcp-ai' ) . '</a>'
							);
							?>
						</p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html__( 'Assistant Name', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Provider', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Model', 'wp-mcp-ai' ); ?></th>
								<th scope="col"><?php echo esc_html__( 'Tools', 'wp-mcp-ai' ); ?></th>
								<th scope="col" class="column-actions"><?php echo esc_html__( 'Actions', 'wp-mcp-ai' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $assistants as $assistant ) : ?>
								<?php
								// Safety check: Ensure method exists before calling.
								if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'get_assistant_configuration' ) ) {
									$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant->ID );
								} else {
									$config = array();
								}

								$provider       = ! empty( $config['provider'] ) ? $config['provider'] : __( 'Default', 'wp-mcp-ai' );
								$model          = ! empty( $config['model'] ) ? $config['model'] : __( 'Default', 'wp-mcp-ai' );
								$tool_count     = isset( $config['tools'] ) && is_array( $config['tools'] ) ? count( $config['tools'] ) : 0;
								$edit_url       = get_edit_post_link( $assistant->ID );
								$tool_shortcuts = $this->get_assistant_tool_shortcuts( $assistant->ID );
								?>
								<tr>
									<td>
										<strong><?php echo esc_html( $assistant->post_title ); ?></strong>
										<div class="row-actions">
											<span class="edit">
												<a href="<?php echo esc_url( $edit_url ); ?>">
													<?php echo esc_html__( 'Edit', 'wp-mcp-ai' ); ?>
												</a>
											</span>
										</div>
									</td>
									<td><?php echo esc_html( ucfirst( $provider ) ); ?></td>
									<td><code><?php echo esc_html( $model ); ?></code></td>
									<td>
										<?php
										/* translators: %d: number of tools enabled for the assistant */
										echo esc_html( sprintf( _n( '%d tool', '%d tools', $tool_count, 'wp-mcp-ai' ), $tool_count ) );
										?>
									</td>
									<td>
										<button 
											type="button" 
											class="button button-primary wp-mcp-ai-test-assistant-btn"
											data-assistant-id="<?php echo esc_attr( $assistant->ID ); ?>"
											data-assistant-title="<?php echo esc_attr( $assistant->post_title ); ?>"
											data-tool-shortcuts="<?php echo esc_attr( wp_json_encode( $tool_shortcuts ) ); ?>"
										>
											<?php echo esc_html__( 'Test', 'wp-mcp-ai' ); ?>
										</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<!-- Modal container for chat interface -->
				<div id="wp-mcp-ai-test-modal" class="wp-mcp-ai-test-modal" style="display: none;">
					<div class="wp-mcp-ai-test-modal__backdrop"></div>
					<div class="wp-mcp-ai-test-modal__panel">
						<div class="wp-mcp-ai-test-modal__header">
							<h2 id="wp-mcp-ai-test-modal__title"><?php echo esc_html__( 'Test Assistant', 'wp-mcp-ai' ); ?></h2>
							<button type="button" class="wp-mcp-ai-test-modal__close" aria-label="<?php echo esc_attr__( 'Close', 'wp-mcp-ai' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
						<div class="wp-mcp-ai-test-modal__body">
							<!-- Chat interface will be initialized here -->
							<div id="wp-mcp-ai-test-chat-container"></div>
						</div>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get file upload configuration for the test assistant.
		 *
		 * Admin users should have full file upload capabilities.
		 *
		 * @return array File upload configuration array.
		 */
		private function get_file_upload_config() {
			$config = array();

			// Admin users can upload files if they have the capability.
			if ( ! current_user_can( 'upload_files' ) ) {
				return $config;
			}

			// Check if Message Attachments class is available.
			if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
				return $config;
			}

			// Get allowed MIME types from the Message Attachments class.
			$allowed_mime_sets   = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();
			$allowed_image_mimes = isset( $allowed_mime_sets['image'] ) ? (array) $allowed_mime_sets['image'] : array();
			$allowed_file_mimes  = isset( $allowed_mime_sets['file'] ) ? (array) $allowed_mime_sets['file'] : array();

			// Get allowed extensions.
			$allowed_extensions = $this->get_allowed_extensions_for_mimes( array_merge( $allowed_image_mimes, $allowed_file_mimes ) );

			// Build file accept tokens for the input element.
			$file_accept_tokens = $this->build_file_accept_tokens( $allowed_image_mimes, $allowed_file_mimes, $allowed_extensions );

			// Add to config.
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

			return $config;
		}

		/**
		 * Get allowed file extensions for MIME types.
		 *
		 * @param array $allowed_mimes Array of allowed MIME types.
		 * @return array Array of file extensions.
		 */
		private function get_allowed_extensions_for_mimes( array $allowed_mimes ) {
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

			// Add custom MIME type extensions.
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
		 * Build file accept tokens for the file input element.
		 *
		 * @param array $image_mimes Allowed image MIME types.
		 * @param array $file_mimes  Allowed file MIME types.
		 * @param array $extensions  Allowed file extensions.
		 * @return array Array of accept tokens.
		 */
		private function build_file_accept_tokens( array $image_mimes, array $file_mimes, array $extensions ) {
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

			return array_values( array_unique( $tokens ) );
		}

		/**
		 * Get assistant tool shortcuts.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array Array of tool shortcuts.
		 */
		private function get_assistant_tool_shortcuts( $assistant_id ) {
			$assistant_id = absint( $assistant_id );

			if ( ! $assistant_id ) {
				return array();
			}

			// Safety check: Ensure class exists.
			if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
				return array();
			}

			// Use the shortcode class method if it exists.
			if ( method_exists( 'WP_MCP_AI_Shortcode', 'get_assistant_tool_shortcuts' ) ) {
				return WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts( $assistant_id );
			}

			return array();
		}
	}
}
