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
        $script_path = WP_MCP_AI_URL . 'assets/js/chat.js';
        $style_path  = WP_MCP_AI_URL . 'assets/css/chat.css';

        $script_dependencies = array();

        if ( function_exists( 'wp_mcp_ai_register_deep_chat_assets' ) ) {
            $deep_chat_handle = wp_mcp_ai_register_deep_chat_assets();

            if ( $deep_chat_handle ) {
                $script_dependencies[] = $deep_chat_handle;
            }
        }

        wp_register_style(
            self::STYLE_HANDLE,
            $style_path,
            array(),
            WP_MCP_AI_VERSION
        );

        wp_register_script(
            self::SCRIPT_HANDLE,
            $script_path,
            $script_dependencies,
            WP_MCP_AI_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'wpMcpAiChat',
            array(
                'restUrl' => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE ) ),
                'uploadEndpoint' => esc_url_raw( rest_url( 'wp/v2/media' ) ),
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
                    'emptyMessage'       => __( 'Enter a message before sending.', 'wp-mcp-ai' ),
                    'attachFile'         => __( 'Attach file', 'wp-mcp-ai' ),
                    'attachmentsLabel'   => __( 'Attachments', 'wp-mcp-ai' ),
                    'removeAttachment'   => __( 'Remove', 'wp-mcp-ai' ),
                    'uploadingFile'      => __( 'Uploading “%s”…', 'wp-mcp-ai' ),
                    'uploadError'        => __( 'The file could not be uploaded. Please try again.', 'wp-mcp-ai' ),
                    'uploadInProgress'   => __( 'Please wait for uploads to finish before sending.', 'wp-mcp-ai' ),
                    'downloadAttachment' => __( 'Download attachment', 'wp-mcp-ai' ),
                    'unsupportedFileType' => __( '“%s” is not a supported file type. Please choose a different file.', 'wp-mcp-ai' ),
                    'unsupportedMultipleFiles' => __( 'Some selected files are not supported. Please try different files.', 'wp-mcp-ai' ),
                    'unsupportedFileLabel' => __( 'This file', 'wp-mcp-ai' ),
                ),
            )
        );
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

        $assistant_id = absint( $atts['assistant'] );
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

        if ( function_exists( 'wp_mcp_ai_enqueue_deep_chat_assets' ) ) {
            wp_mcp_ai_enqueue_deep_chat_assets();
        }

        wp_enqueue_script( self::SCRIPT_HANDLE );
        wp_enqueue_style( self::STYLE_HANDLE );

        $instance_id = wp_unique_id( 'wp-mcp-ai-chat-' );
        $textarea_id = $instance_id . '-input';

        $can_upload_attachments = current_user_can( 'upload_files' );

        $config = array(
            'id'             => $instance_id,
            'assistantId'    => $assistant_id,
            'messagesEndpoint' => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat' ) ),
            'toolsEndpoint'    => esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) ),
            'requiredCapability' => $capability ? $capability : '',
            'allowGuests'        => (bool) $allow_guests,
            'canUploadAttachments' => (bool) $can_upload_attachments,
        );

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
        ?>
        <div class="wp-mcp-ai-chat" id="<?php echo esc_attr( $instance_id ); ?>" data-wp-mcp-ai-chat>
            <div class="wp-mcp-ai-chat__messages" aria-live="polite"></div>
            <div class="wp-mcp-ai-chat__status" role="status" aria-live="polite" hidden></div>
            <form class="wp-mcp-ai-chat__form" data-instance-id="<?php echo esc_attr( $instance_id ); ?>">
                <label class="wp-mcp-ai-chat__label" for="<?php echo esc_attr( $textarea_id ); ?>">
                    <?php echo esc_html( get_the_title( $assistant_id ) ); ?>
                </label>
                <textarea id="<?php echo esc_attr( $textarea_id ); ?>" class="wp-mcp-ai-chat__input" rows="4" placeholder="<?php echo esc_attr__( 'Ask something…', 'wp-mcp-ai' ); ?>" required></textarea>
                <div class="wp-mcp-ai-chat__attachments" hidden>
                    <div class="wp-mcp-ai-chat__attachments-header"><?php esc_html_e( 'Attachments', 'wp-mcp-ai' ); ?></div>
                    <ul class="wp-mcp-ai-chat__attachments-list"></ul>
                </div>
                <div class="wp-mcp-ai-chat__actions">
                    <input type="file" class="wp-mcp-ai-chat__file-input" multiple hidden />
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
