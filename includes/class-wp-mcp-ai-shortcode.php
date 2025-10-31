<?php
/**
 * Shortcode renderer for the front-end chat interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
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
     * Bootstraps hooks.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_assets' ) );
        add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

        add_action( 'enqueue_block_assets', array( $this, 'maybe_enqueue_style_for_block_themes' ) );
    }

    /**
     * Register assets used by the shortcode.
     */
    public function register_assets() {
        $script_relative = 'assets/js/chat.js';
        $style_relative  = 'assets/css/chat.css';

        $script_path = WP_MCP_AI_URL . $script_relative;
        $style_path  = WP_MCP_AI_URL . $style_relative;

        $script_version = $this->get_asset_version( $script_relative );
        $style_version  = $this->get_asset_version( $style_relative );

        wp_register_style(
            self::STYLE_HANDLE,
            $style_path,
            array(),
            $style_version
        );

        if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
            $color_css = WP_MCP_AI_Admin_Settings::get_chat_color_css();

            if ( $color_css ) {
                wp_add_inline_style( self::STYLE_HANDLE, $color_css );
            }
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            $script_path,
            array(),
            $script_version,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'wpMcpAiChat',
            array(
                'restUrl' => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ),
                'uploadEndpoint' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
                'filesEndpoint'  => esc_url_raw( trailingslashit( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'strings' => array(
                    'placeholder'        => __( 'Ask something…', 'wp-mcp-ai' ),
                    'send'               => __( 'Send', 'wp-mcp-ai' ),
                    'sending'            => __( 'Sending message…', 'wp-mcp-ai' ),
                    'waiting'            => __( 'Waiting for the assistant…', 'wp-mcp-ai' ),
                    'error'              => __( 'Something went wrong. Please try again.', 'wp-mcp-ai' ),
                    'missingAssistant'   => __( 'Assistant configuration was not found.', 'wp-mcp-ai' ),
                    'notAuthorized'      => __( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ),
                    'toolExecuting'      => __( 'Running tool: %s', 'wp-mcp-ai' ),
                    'toolSuccess'        => __( 'Tool response ready.', 'wp-mcp-ai' ),
                    'toolError'          => __( 'The tool request failed.', 'wp-mcp-ai' ),
                    'toolQueued'         => __( 'Crawl queued. Results will appear shortly.', 'wp-mcp-ai' ),
                    'toolPolling'        => __( 'Crawl in progress…', 'wp-mcp-ai' ),
                    'toolTimeout'        => __( 'Crawl timed out before completing.', 'wp-mcp-ai' ),
                    'toolFailed'         => __( 'The crawl failed: %s', 'wp-mcp-ai' ),
                    'speechToolSuccess'  => __( 'Speech audio saved to the Media Library.', 'wp-mcp-ai' ),
                    'imageToolSuccess'   => __( 'Image saved to the Media Library.', 'wp-mcp-ai' ),
                    'toolPromptLabel'  => __( 'Insert prompt: %s', 'wp-mcp-ai' ),
                    'emptyMessage'       => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
                    'attachFile'         => __( 'Attach file', 'wp-mcp-ai' ),
                    'transcribe'         => __( 'Transcribe', 'wp-mcp-ai' ),
                    'transcribeAudio'    => __( 'Transcribe audio', 'wp-mcp-ai' ),
                    'transcribing'       => __( 'Transcribing audio…', 'wp-mcp-ai' ),
                    'recording'          => __( 'Recording… tap to stop.', 'wp-mcp-ai' ),
                    'stopRecording'      => __( 'Stop recording', 'wp-mcp-ai' ),
                    'recordingError'     => __( 'Could not access your microphone. Please allow access or upload an audio file instead.', 'wp-mcp-ai' ),
                    'transcriptionError' => __( 'The transcription request failed. Please try again.', 'wp-mcp-ai' ),
                    'transcriptionSuccess' => __( 'Inserted transcription from “%s”.', 'wp-mcp-ai' ),
                    'transcriptionFileTooLarge' => __( 'The selected audio file is too large. Please choose a file under 25MB.', 'wp-mcp-ai' ),
                    'transcribeChooseSource' => __( 'Press OK to record with your microphone, or Cancel to choose an audio file.', 'wp-mcp-ai' ),
                    'attachmentsLabel'   => __( 'Attachments', 'wp-mcp-ai' ),
                    'removeAttachment'   => __( 'Remove', 'wp-mcp-ai' ),
                    'uploadingFile'      => __( 'Uploading “%s”…', 'wp-mcp-ai' ),
                    'uploadError'        => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
                    'uploadInProgress'   => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
                    'downloadAttachment' => __( 'Download attachment', 'wp-mcp-ai' ),
                    'unsupportedFileType' => __( '“%s” is not a supported file type. Please choose a different file.', 'wp-mcp-ai' ),
                    'unsupportedMultipleFiles' => __( 'Some selected files are not supported. Please try different files.', 'wp-mcp-ai' ),
                    'unsupportedFileLabel' => __( 'This file', 'wp-mcp-ai' ),
                    'expandTranscript'  => __( 'Expand conversation', 'wp-mcp-ai' ),
                    'collapseTranscript' => __( 'Collapse conversation', 'wp-mcp-ai' ),
                    'jsonResponse'      => __( 'JSON response', 'wp-mcp-ai' ),
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
     * Render the chat shortcode.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content Content (unused).
     * @param string $tag     Shortcode tag.
     *
     * @return string
     */
    public function render_shortcode( $atts, $content = '', $tag = '' ) {
        $atts = shortcode_atts(
            array(
                'assistant' => '',
                'allow_guests' => 'false',
            ),
            $atts,
            $tag
        );

        $assistant_id = self::resolve_assistant_id( $atts['assistant'] );
        $allow_guests = wp_validate_boolean( $atts['allow_guests'] );

        if ( ! $assistant_id ) {
            $settings = WP_MCP_AI_Admin_Settings::get_settings();
            $assistant_id = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
        }

        if ( ! $assistant_id ) {
            return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'No assistant has been selected. Please provide an assistant attribute or configure a default.', 'wp-mcp-ai' ) . '</div>';
        }

        $assistant = get_post( $assistant_id );
        if ( ! $assistant || WP_MCP_AI_Assistant_CPT::POST_TYPE !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
            return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'The requested assistant is not available.', 'wp-mcp-ai' ) . '</div>';
        }

        $guest_token = '';
        if ( $allow_guests ) {
            $guest_token = self::generate_guest_token( $assistant_id );
        }

        $capability = wp_mcp_ai_get_required_chat_capability( $assistant_id, 'shortcode' );

        if ( $guest_token ) {
            $capability = 'public';
        }

        if ( $capability && 'public' !== $capability && ! current_user_can( $capability ) ) {
            return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ) . '</div>';
        }

        if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
            $this->register_assets();
        }

        wp_enqueue_script( self::SCRIPT_HANDLE );
        wp_enqueue_style( self::STYLE_HANDLE );

        $instance_id = wp_unique_id( 'wp-mcp-ai-chat-' );
        $textarea_id = $instance_id . '-input';

        $can_upload_attachments = current_user_can( 'upload_files' );

        $assistant_content = get_post_field( 'post_content', $assistant_id );
        if ( $assistant_content ) {
            $assistant_content = apply_filters( 'the_content', $assistant_content );
        }

        $config = array(
            'id'             => $instance_id,
            'assistantId'    => $assistant_id,
            'messagesEndpoint' => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat' ) ),
            'toolsEndpoint'    => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ),
            'filesEndpoint'    => esc_url_raw( trailingslashit( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/files' ) ) ),
            'crawl4aiTaskEndpoint' => esc_url_raw( trailingslashit( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/crawl4ai/task' ) ) ),
            'crawl4aiDefaultPollMs' => 5000,
            'requiredCapability' => $capability ? $capability : '',
            'allowGuests'        => (bool) $allow_guests,
            'canUploadAttachments' => (bool) $can_upload_attachments,
        );

        $tool_prompts = self::get_assistant_tool_prompts( $assistant_id );
        if ( ! empty( $tool_prompts ) ) {
            $config['toolPrompts'] = $tool_prompts;
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

        $inline_config = 'window.wpMcpAiChatInstances = window.wpMcpAiChatInstances || {};';
        $inline_config .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $instance_id ) . '] = ' . wp_json_encode( $config ) . ';';
        wp_add_inline_script( self::SCRIPT_HANDLE, $inline_config, 'before' );

        ob_start();
        $messages_id = $instance_id . '-messages';
        ?>
        <div class="wp-mcp-ai-chat" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-chat>
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
                <div class="wp-mcp-ai-chat__tool-prompts" role="group" aria-label="<?php echo esc_attr__( 'Assistant prompts', 'wp-mcp-ai' ); ?>" hidden></div>
                <textarea id="<?php echo esc_attr( $textarea_id ); ?>" class="wp-mcp-ai-chat__input" rows="4" placeholder="<?php echo esc_attr__( 'Ask something…', 'wp-mcp-ai' ); ?>" required></textarea>
                <div class="wp-mcp-ai-chat__attachments" hidden>
                    <div class="wp-mcp-ai-chat__attachments-header"><?php esc_html_e( 'Attachments', 'wp-mcp-ai' ); ?></div>
                    <ul class="wp-mcp-ai-chat__attachments-list"></ul>
                </div>
                <div class="wp-mcp-ai-chat__actions">
                    <input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />
                    <input type="file" class="wp-mcp-ai-chat__transcribe-input" accept="audio/*" hidden<?php echo $can_upload_attachments ? '' : ' disabled'; ?> />
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
                    <button type="submit" class="wp-mcp-ai-chat__submit">
                        <?php esc_html_e( 'Send', 'wp-mcp-ai' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
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
     * Retrieve tool prompt metadata for the supplied assistant.
     *
     * @param int $assistant_id Assistant post ID.
     * @return array[]
     */
    public static function get_assistant_tool_prompts( $assistant_id ) {
        $assistant_id = absint( $assistant_id );

        if ( ! $assistant_id ) {
            return array();
        }

        if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
            return array();
        }

        $config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
        $prompts      = array();
        $selected_tools = array();

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

        if ( ! empty( $config['tool_prompts'] ) && is_array( $config['tool_prompts'] ) ) {
            $custom_prompts = $config['tool_prompts'];

            if ( method_exists( 'WP_MCP_AI_Assistant_CPT', 'sanitize_tool_prompts_meta' ) ) {
                $custom_prompts = WP_MCP_AI_Assistant_CPT::sanitize_tool_prompts_meta( $custom_prompts );
            }

            /**
             * Filter the list of custom prompts configured for an assistant.
             *
             * @since 1.1.0
             *
             * @param array $custom_prompts Sanitized custom prompts.
             * @param int   $assistant_id     Assistant post ID.
             * @param array $config           Assistant configuration array.
             *
             * @see wp_mcp_ai_assistant_custom_tool_shortcuts For the legacy filter name.
             */
            $custom_prompts = apply_filters( 'wp_mcp_ai_assistant_custom_tool_prompts', $custom_prompts, $assistant_id, $config );

            /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
            $custom_prompts = apply_filters( 'wp_mcp_ai_assistant_custom_tool_shortcuts', $custom_prompts, $assistant_id, $config );

            if ( is_array( $custom_prompts ) ) {
                foreach ( $custom_prompts as $custom_prompt ) {
                    if ( ! is_array( $custom_prompt ) ) {
                        continue;
                    }

                    $label = isset( $custom_prompt['label'] ) ? sanitize_text_field( $custom_prompt['label'] ) : '';
                    $payload = isset( $custom_prompt['payload'] ) ? sanitize_textarea_field( $custom_prompt['payload'] ) : '';

                    if ( '' === $label || '' === $payload ) {
                        continue;
                    }

                    $tool_slug = '';
                    if ( isset( $custom_prompt['tool'] ) && is_string( $custom_prompt['tool'] ) ) {
                        $tool_slug = sanitize_key( $custom_prompt['tool'] );
                    }

                    if ( '' !== $tool_slug && ! in_array( $tool_slug, $selected_tools, true ) ) {
                        continue;
                    }

                    $entry = array(
                        'tool'    => ( '' !== $tool_slug ) ? $tool_slug : 'custom',
                        'label'   => $label,
                        'payload' => $payload,
                    );

                    if ( isset( $custom_prompt['description'] ) && is_string( $custom_prompt['description'] ) ) {
                        $entry['description'] = sanitize_textarea_field( $custom_prompt['description'] );
                    }

                    $prompts[] = $entry;
                }
            }
        }

        if ( empty( $selected_tools ) ) {
            return $prompts;
        }

        $registry = WP_MCP_AI_Tool_Registry::get_instance();

        foreach ( $selected_tools as $tool_slug ) {
            $tool = $registry->get_tool( $tool_slug );

            if ( ! $tool ) {
                continue;
            }

            $tasks = array();

            if ( $tool instanceof WP_MCP_AI_Tool_Prompts_Interface ) {
                $tasks = $tool->get_prompt_tasks();
            } elseif ( method_exists( $tool, 'get_prompt_tasks' ) ) {
                $tasks = $tool->get_prompt_tasks();
            } elseif ( method_exists( $tool, 'get_shortcut_tasks' ) ) {
                $tasks = $tool->get_shortcut_tasks();
            }

            $tasks = apply_filters( 'wp_mcp_ai_tool_prompt_tasks', $tasks, $tool, $assistant_id );
            $tasks = apply_filters( 'wp_mcp_ai_tool_prompt_tasks_' . $tool_slug, $tasks, $tool, $assistant_id );

            /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
            $tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks', $tasks, $tool, $assistant_id );
            /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
            $tasks = apply_filters( 'wp_mcp_ai_tool_shortcut_tasks_' . $tool_slug, $tasks, $tool, $assistant_id );

            if ( empty( $tasks ) || ! is_array( $tasks ) ) {
                $prompts[] = array(
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

                $label = isset( $task['label'] ) && is_string( $task['label'] ) ? sanitize_text_field( $task['label'] ) : '';
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

                $prompts[] = $entry;
            }
        }

        /**
         * Filter the combined prompt list before it is returned.
         *
         * @since 1.1.0
         *
         * @param array $prompts      Prompt definitions.
         * @param int   $assistant_id Assistant post ID.
         *
         * @see wp_mcp_ai_assistant_tool_shortcuts For the legacy filter name.
         */
        $prompts = apply_filters( 'wp_mcp_ai_assistant_tool_prompts', $prompts, $assistant_id );
        /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
        $prompts = apply_filters( 'wp_mcp_ai_assistant_tool_shortcuts', $prompts, $assistant_id );

        $prompts = array_values(
            array_filter(
                $prompts,
                static function ( $prompt ) {
                    if ( empty( $prompt ) || ! is_array( $prompt ) ) {
                        return false;
                    }

                    if ( empty( $prompt['label'] ) || empty( $prompt['payload'] ) ) {
                        return false;
                    }

                    return true;
                }
            )
        );

        $default_prompt = array(
            'tool'    => 'default',
            'label'   => sanitize_text_field( __( 'What can you do?', 'wp-mcp-ai' ) ),
            'payload' => sanitize_textarea_field( 'what are some things you can do' ),
        );

        /**
         * Filter the default prompt that is appended for every assistant.
         *
         * @since 1.0.1
         *
         * @param array $default_prompt Default prompt configuration.
         * @param int   $assistant_id     Assistant post ID.
         *
         * @see wp_mcp_ai_default_tool_shortcut For the legacy filter name.
         */
        $default_prompt = apply_filters( 'wp_mcp_ai_default_tool_prompt', $default_prompt, $assistant_id );
        /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
        $default_prompt = apply_filters( 'wp_mcp_ai_default_tool_shortcut', $default_prompt, $assistant_id );

        if ( is_array( $default_prompt ) && ! empty( $default_prompt['label'] ) && ! empty( $default_prompt['payload'] ) ) {
            $default_prompt['tool'] = isset( $default_prompt['tool'] ) && is_string( $default_prompt['tool'] )
                ? sanitize_key( $default_prompt['tool'] )
                : 'default';

            $default_prompt['label']   = sanitize_text_field( $default_prompt['label'] );
            $default_prompt['payload'] = sanitize_textarea_field( $default_prompt['payload'] );

            if ( isset( $default_prompt['description'] ) ) {
                if ( is_string( $default_prompt['description'] ) ) {
                    $default_prompt['description'] = sanitize_textarea_field( $default_prompt['description'] );
                } else {
                    unset( $default_prompt['description'] );
                }
            }

            $has_default_prompt = false;

            foreach ( $prompts as $prompt ) {
                if ( ! is_array( $prompt ) ) {
                    continue;
                }

                if ( isset( $prompt['payload'] ) && $prompt['payload'] === $default_prompt['payload'] ) {
                    $has_default_prompt = true;
                    break;
                }
            }

            if ( ! $has_default_prompt ) {
                $prompts[] = $default_prompt;
            }
        }

        if ( empty( $prompts ) ) {
            $fallback_prompt = array(
                'tool'    => 'default',
                'label'   => sanitize_text_field( __( 'What are some things you can do?', 'wp-mcp-ai' ) ),
                'payload' => sanitize_textarea_field( 'what are some things you can do' ),
            );

            /**
             * Filter the default prompt shown when an assistant has no tool prompts configured.
             *
             * @since 1.0.1
             *
             * @param array $fallback_prompt Default prompt configuration.
             * @param int   $assistant_id      Assistant post ID.
             *
             * @see wp_mcp_ai_default_tool_shortcut For the legacy filter name.
             */
            $fallback_prompt = apply_filters( 'wp_mcp_ai_default_tool_prompt', $fallback_prompt, $assistant_id );
            /** This filter is documented in includes/class-wp-mcp-ai-shortcode.php */
            $fallback_prompt = apply_filters( 'wp_mcp_ai_default_tool_shortcut', $fallback_prompt, $assistant_id );

            if ( is_array( $fallback_prompt ) && ! empty( $fallback_prompt['label'] ) && ! empty( $fallback_prompt['payload'] ) ) {
                $fallback_prompt['tool'] = isset( $fallback_prompt['tool'] ) && is_string( $fallback_prompt['tool'] )
                    ? sanitize_key( $fallback_prompt['tool'] )
                    : 'default';

                $fallback_prompt['label']   = sanitize_text_field( $fallback_prompt['label'] );
                $fallback_prompt['payload'] = sanitize_textarea_field( $fallback_prompt['payload'] );

                if ( isset( $fallback_prompt['description'] ) ) {
                    if ( is_string( $fallback_prompt['description'] ) ) {
                        $fallback_prompt['description'] = sanitize_textarea_field( $fallback_prompt['description'] );
                    } else {
                        unset( $fallback_prompt['description'] );
                    }
                }

                $prompts[] = $fallback_prompt;
            }
        }

        return $prompts;
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
