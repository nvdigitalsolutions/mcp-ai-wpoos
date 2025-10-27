<?php
/**
 * Shortcode renderer for the Deep Chat interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides the [mcp_ai_deep_chat] shortcode.
 */
class WP_MCP_AI_Deep_Chat_Shortcode {
    const SHORTCODE = 'mcp_ai_deep_chat';

    /**
     * Register the shortcode hook.
     */
    public function __construct() {
        add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
    }

    /**
     * Render the Deep Chat shortcode container.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content Content (unused).
     * @param string $tag     Shortcode tag (unused).
     *
     * @return string
     */
    public function render( $atts, $content = '', $tag = '' ) {
        $atts = shortcode_atts(
            array(
                'assistant'    => '',
                'allow_guests' => 'false',
            ),
            $atts,
            self::SHORTCODE
        );

        $assistant_id = absint( $atts['assistant'] );
        $allow_guests = wp_validate_boolean( $atts['allow_guests'] );

        if ( ! $assistant_id ) {
            $settings     = WP_MCP_AI_Admin_Settings::get_settings();
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
        if ( $allow_guests && method_exists( 'WP_MCP_AI_Shortcode', 'generate_guest_token' ) ) {
            $guest_token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );
        }

        $capability = wp_mcp_ai_get_required_chat_capability( $assistant_id, 'deep-chat-shortcode' );

        if ( $guest_token ) {
            $capability = 'public';
        }

        if ( $capability && 'public' !== $capability && ! current_user_can( $capability ) ) {
            return '<div class="wp-mcp-ai-chat__notice">' . esc_html__( 'You do not have permission to chat with this assistant.', 'wp-mcp-ai' ) . '</div>';
        }

        if ( function_exists( 'wp_mcp_ai_enqueue_deep_chat_assets' ) ) {
            wp_mcp_ai_enqueue_deep_chat_assets();
        }

        $container_id      = wp_unique_id( 'wp-mcp-ai-deep-chat-' );
        $messages_endpoint = esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/chat' ) );
        $tools_endpoint    = esc_url_raw( rest_url( WP_MCP_AI_REST::REST_NAMESPACE . '/tools' ) );
        $upload_endpoint   = esc_url_raw( rest_url( 'wp/v2/media' ) );
        $rest_nonce        = wp_create_nonce( 'wp_rest' );

        $allowed_image_mimes     = array();
        $allowed_file_mimes      = array();
        $allowed_extensions      = array();
        $file_accept_tokens      = array();
        $max_attachment_bytes    = 0;
        $can_upload_attachments  = current_user_can( 'upload_files' );

        if ( $can_upload_attachments && class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
            $allowed_mime_sets = WP_MCP_AI_Message_Attachments::get_allowed_mime_types();

            $allowed_image_mimes = isset( $allowed_mime_sets['image'] ) ? (array) $allowed_mime_sets['image'] : array();
            $allowed_file_mimes  = isset( $allowed_mime_sets['file'] ) ? (array) $allowed_mime_sets['file'] : array();
            $allowed_extensions  = self::get_allowed_extensions_for_mimes( array_merge( $allowed_image_mimes, $allowed_file_mimes ) );
            $file_accept_tokens  = self::build_file_accept_tokens( $allowed_image_mimes, $allowed_file_mimes, $allowed_extensions );

            $max_attachment_bytes = (int) apply_filters( 'wp_mcp_ai_max_attachment_bytes', WP_MCP_AI_Message_Attachments::MAX_ATTACHMENT_BYTES, 0, 'chat' );
        }

        $data_attributes = array(
            'class'                    => 'wp-mcp-ai-deep-chat',
            'id'                       => $container_id,
            'data-wp-mcp-ai-deep-chat' => '1',
            'data-assistant-id'        => $assistant_id,
            'data-chat-endpoint'       => $messages_endpoint,
            'data-tools-endpoint'      => $tools_endpoint,
            'data-upload-endpoint'     => $upload_endpoint,
            'data-rest-nonce'          => $rest_nonce,
            'data-allow-guests'        => $allow_guests ? 'true' : 'false',
            'data-max-attachment-bytes' => $max_attachment_bytes,
            'data-can-upload-attachments' => $can_upload_attachments ? 'true' : 'false',
        );

        if ( $guest_token ) {
            $data_attributes['data-guest-token'] = $guest_token;
        }

        if ( ! empty( $allowed_image_mimes ) ) {
            $data_attributes['data-allowed-image-mimes'] = wp_json_encode( array_values( $allowed_image_mimes ) );
        }

        if ( ! empty( $allowed_file_mimes ) ) {
            $data_attributes['data-allowed-file-mimes'] = wp_json_encode( array_values( $allowed_file_mimes ) );
        }

        if ( ! empty( $allowed_extensions ) ) {
            $data_attributes['data-allowed-extensions'] = wp_json_encode( array_values( $allowed_extensions ) );
        }

        if ( ! empty( $file_accept_tokens ) ) {
            $data_attributes['data-file-accept'] = implode( ',', $file_accept_tokens );
        }

        $attributes_string = $this->format_attributes( $data_attributes );

        return sprintf( '<div %s></div>', $attributes_string );
    }

    /**
     * Convert an array of attributes to a string for HTML output.
     *
     * @param array $attributes Attribute map.
     * @return string
     */
    protected function format_attributes( array $attributes ) {
        $parts = array();

        foreach ( $attributes as $key => $value ) {
            if ( '' === $value && '0' !== $value ) {
                continue;
            }

            $parts[] = sprintf( '%1$s="%2$s"', esc_attr( $key ), esc_attr( (string) $value ) );
        }

        return implode( ' ', $parts );
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
