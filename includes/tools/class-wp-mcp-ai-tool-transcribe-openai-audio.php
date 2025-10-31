<?php
/**
 * Tool that transcribes or translates audio into English text using OpenAI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-message-attachments.php';

/**
 * Provides a tool for transcribing or translating audio attachments via OpenAI.
 */
class WP_MCP_AI_Tool_Transcribe_OpenAI_Audio implements WP_MCP_AI_Tool_Interface {
    const DEFAULT_MODEL      = 'gpt-4o-mini-transcribe';
    const DEFAULT_FORMAT     = 'verbose_json';
    const MAX_AUDIO_BYTES    = 26214400; // 25MB default limit.

    /**
     * {@inheritdoc}
     */
    public function get_slug() {
        return 'transcribe_openai_audio';
    }

    /**
     * {@inheritdoc}
     */
    public function get_name() {
        return __( 'Transcribe OpenAI Audio', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_description() {
        return __( 'Converts an uploaded audio file into English text using OpenAI transcription or translation.', 'wp-mcp-ai' );
    }

    /**
     * {@inheritdoc}
     */
    public function get_parameters_schema() {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'attachment_id'   => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'WordPress attachment ID that contains the audio file.', 'wp-mcp-ai' ),
                ),
                'translate'       => array(
                    'type'        => 'boolean',
                    'description' => __( 'When true the audio will be translated into English instead of a raw transcription.', 'wp-mcp-ai' ),
                    'default'     => true,
                ),
                'model'           => array(
                    'type'        => 'string',
                    'description' => __( 'Optional OpenAI model override for the transcription request.', 'wp-mcp-ai' ),
                    'default'     => self::DEFAULT_MODEL,
                ),
                'prompt'          => array(
                    'type'        => 'string',
                    'description' => __( 'Optional prompt that provides context for the transcription.', 'wp-mcp-ai' ),
                ),
                'temperature'     => array(
                    'type'        => array( 'number', 'integer', 'string' ),
                    'description' => __( 'Optional temperature override between 0 and 1.', 'wp-mcp-ai' ),
                ),
                'timeout'         => array(
                    'type'        => array( 'integer', 'string' ),
                    'description' => __( 'Optional request timeout override in seconds.', 'wp-mcp-ai' ),
                ),
                'response_format' => array(
                    'type'        => 'string',
                    'description' => __( 'Optional OpenAI response format (json or verbose_json).', 'wp-mcp-ai' ),
                    'enum'        => array( 'json', 'verbose_json' ),
                    'default'     => self::DEFAULT_FORMAT,
                ),
                'language'        => array(
                    'type'        => 'string',
                    'description' => __( 'Optional ISO language code hint for the transcription.', 'wp-mcp-ai' ),
                ),
            ),
            'required'             => array( 'attachment_id' ),
            'additionalProperties' => false,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute( array $arguments = array(), array $context = array() ) {
        $attachment_id = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_audio_attachment', __( 'You must supply an audio attachment ID.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
        }

        $user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
        $has_token = ! empty( $context['token_authenticated'] );

        if ( ! $user_id && ! $has_token ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to transcribe audio.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
        }

        if ( $user_id > 0 && $user_id !== get_current_user_id() ) {
            wp_set_current_user( $user_id );
        }

        if ( $user_id && ! user_can( $user_id, 'read' ) ) {
            return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to transcribe audio.', 'wp-mcp-ai' ) );
        }

        if ( $user_id && is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
            return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
        }

        $audio = $this->prepare_audio_attachment( $attachment_id );

        if ( is_wp_error( $audio ) ) {
            return $audio;
        }

        $translate = true;
        if ( isset( $arguments['translate'] ) ) {
            $translate = (bool) $arguments['translate'];
        }

        $options = array(
            'model'           => isset( $arguments['model'] ) && '' !== $arguments['model'] ? sanitize_text_field( $arguments['model'] ) : self::DEFAULT_MODEL,
            'translate'       => $translate,
            'prompt'          => isset( $arguments['prompt'] ) ? sanitize_textarea_field( $arguments['prompt'] ) : '',
            'response_format' => isset( $arguments['response_format'] ) && '' !== $arguments['response_format'] ? strtolower( sanitize_key( $arguments['response_format'] ) ) : self::DEFAULT_FORMAT,
            'timeout'         => isset( $arguments['timeout'] ) && '' !== $arguments['timeout'] ? absint( $arguments['timeout'] ) : '',
            'language'        => isset( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : '',
            'filename'        => $audio['file_name'],
            'mime_type'       => $audio['mime_type'],
        );

        if ( isset( $arguments['temperature'] ) && '' !== $arguments['temperature'] ) {
            $options['temperature'] = $arguments['temperature'];
        }

        if ( '' === $options['model'] ) {
            $options['model'] = self::DEFAULT_MODEL;
        }

        if ( '' === $options['response_format'] ) {
            $options['response_format'] = self::DEFAULT_FORMAT;
        }

        if ( '' === $options['prompt'] ) {
            unset( $options['prompt'] );
        }

        if ( '' === $options['timeout'] ) {
            unset( $options['timeout'] );
        }

        if ( '' === $options['language'] ) {
            unset( $options['language'] );
        }

        $client = new WP_MCP_AI_OpenAI_Client();
        $result = $client->transcribe_audio( $audio['file_path'], $options );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $storage = $this->store_transcript_attachment( $result, $audio, $user_id );

        if ( is_wp_error( $storage ) ) {
            return $storage;
        }

        $payload = array(
            'attachment_id' => $attachment_id,
            'file_name'     => $audio['file_name'],
            'mime_type'     => $audio['mime_type'],
            'file_size'     => $audio['file_size'],
            'model'         => $result['model'],
            'text'          => $result['text'],
            'translated'    => ! empty( $result['translated'] ),
            'response_format' => $result['format'],
        );

        $payload['transcript_attachment_id'] = $storage['attachment_id'];
        $payload['transcript_file_name']     = $storage['file_name'];
        $payload['transcript_mime_type']     = $storage['mime_type'];
        $payload['transcript_bytes']         = $storage['bytes'];
        $payload['transcript_url']           = $storage['url'];
        $payload['transcript_title']         = $storage['title'];
        $payload['download_url']             = $storage['url'];
        $payload['url']                      = $storage['url'];
        $payload['fileName']                 = $storage['file_name'];
        $payload['mimeType']                 = $storage['mime_type'];
        $payload['bytes']                    = $storage['bytes'];
        $payload['title']                    = $storage['title'];

        if ( isset( $result['language'] ) ) {
            $payload['language'] = $result['language'];
        }

        if ( isset( $result['duration'] ) ) {
            $payload['duration'] = $result['duration'];
        }

        if ( isset( $result['segments'] ) ) {
            $payload['segments'] = $result['segments'];
        }

        return apply_filters( 'wp_mcp_ai_transcribe_openai_audio_result', $payload, $arguments, $context );
    }

    /**
     * Save the transcription text as a Media Library attachment.
     *
     * @param array $result Transcription payload from OpenAI.
     * @param array $audio  Source audio metadata.
     * @param int   $user_id Current user identifier.
     * @return array|WP_Error
     */
    protected function store_transcript_attachment( array $result, array $audio, $user_id ) {
        $document = $this->build_transcript_document( $result, $audio );

        if ( '' === $document ) {
            return new WP_Error( 'wp_mcp_ai_transcript_empty', __( 'The transcription result did not include any text to store.', 'wp-mcp-ai' ) );
        }

        $file_stem = $this->normalise_file_stem( $audio['file_name'] );
        $file_name = sprintf( '%s-transcript-%s.txt', $file_stem, gmdate( 'Ymd-His' ) );

        if ( ! function_exists( 'wp_upload_bits' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_upload_bits( $file_name, null, $document );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'wp_mcp_ai_transcript_upload_failed', __( 'Failed to save the transcription file.', 'wp-mcp-ai' ), array( 'error' => $upload['error'] ) );
        }

        $file_path = isset( $upload['file'] ) ? $upload['file'] : '';

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error( 'wp_mcp_ai_transcript_upload_failed', __( 'Failed to write the transcription file to disk.', 'wp-mcp-ai' ) );
        }

        $title = $this->generate_transcript_title( $audio['file_name'], $result );

        $attachment = array(
            'post_mime_type' => 'text/plain',
            'post_title'     => $title,
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        if ( $user_id ) {
            $attachment['post_author'] = $user_id;
        }

        $attachment_id = wp_insert_attachment( $attachment, $file_path );

        if ( is_wp_error( $attachment_id ) ) {
            $this->delete_file_safely( $file_path );

            return new WP_Error( 'wp_mcp_ai_transcript_attachment_error', __( 'Failed to register the transcription file as an attachment.', 'wp-mcp-ai' ), array( 'error' => $attachment_id ) );
        }

        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );

        if ( ! is_array( $metadata ) ) {
            $metadata = array();
        }

        if ( ! isset( $metadata['filesize'] ) && file_exists( $file_path ) ) {
            $metadata['filesize'] = filesize( $file_path );
        }

        if ( ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

        return array(
            'attachment_id' => (int) $attachment_id,
            'file'          => $file_path,
            'file_name'     => wp_basename( $file_path ),
            'url'           => isset( $upload['url'] ) ? $upload['url'] : '',
            'mime_type'     => 'text/plain',
            'bytes'         => $bytes ? (int) $bytes : 0,
            'title'         => $title,
        );
    }

    /**
     * Build the transcript document contents.
     *
     * @param array $result Transcription payload.
     * @param array $audio  Source audio metadata.
     * @return string
     */
    protected function build_transcript_document( array $result, array $audio ) {
        $sections   = array();
        $meta_parts = array();

        if ( ! empty( $audio['file_name'] ) ) {
            /* translators: %s: Original audio filename. */
            $meta_parts[] = sprintf( __( 'Source file: %s', 'wp-mcp-ai' ), $audio['file_name'] );
        }

        if ( ! empty( $result['language'] ) ) {
            /* translators: %s: Detected language code. */
            $meta_parts[] = sprintf( __( 'Language: %s', 'wp-mcp-ai' ), $result['language'] );
        }

        if ( isset( $result['duration'] ) ) {
            $duration = $this->format_duration_label( $result['duration'] );

            if ( '' !== $duration ) {
                /* translators: %s: Audio duration. */
                $meta_parts[] = sprintf( __( 'Duration: %s', 'wp-mcp-ai' ), $duration );
            }
        }

        if ( ! empty( $result['translated'] ) ) {
            $meta_parts[] = __( 'Translated to English', 'wp-mcp-ai' );
        }

        if ( ! empty( $meta_parts ) ) {
            $sections[] = implode( ' • ', array_unique( array_filter( array_map( 'trim', $meta_parts ) ) ) );
        }

        if ( ! empty( $result['text'] ) ) {
            $sections[] = trim( (string) $result['text'] );
        }

        if ( ! empty( $result['segments'] ) && is_array( $result['segments'] ) ) {
            $segment_lines = array();

            foreach ( $result['segments'] as $segment ) {
                if ( empty( $segment['text'] ) ) {
                    continue;
                }

                $line = $this->format_segment_line( $segment );

                if ( '' !== $line ) {
                    $segment_lines[] = $line;
                }
            }

            if ( ! empty( $segment_lines ) ) {
                $sections[] = implode( "\n", $segment_lines );
            }
        }

        $sections = array_filter( array_map( 'trim', $sections ) );

        return implode( "\n\n", $sections );
    }

    /**
     * Format a single transcription segment line.
     *
     * @param array $segment Segment data from OpenAI.
     * @return string
     */
    protected function format_segment_line( array $segment ) {
        $text = trim( (string) $segment['text'] );

        if ( '' === $text ) {
            return '';
        }

        $start = isset( $segment['start'] ) ? $this->format_duration_label( $segment['start'] ) : '';
        $end   = isset( $segment['end'] ) ? $this->format_duration_label( $segment['end'] ) : '';
        $label = '';

        if ( '' !== $start && '' !== $end ) {
            $label = sprintf( '%s–%s', $start, $end );
        } elseif ( '' !== $start ) {
            $label = $start;
        }

        if ( '' !== $label ) {
            return sprintf( '[%s] %s', $label, $text );
        }

        return $text;
    }

    /**
     * Format a duration into a timestamp label.
     *
     * @param float|int|string $value Duration in seconds.
     * @return string
     */
    protected function format_duration_label( $value ) {
        if ( '' === $value || null === $value ) {
            return '';
        }

        $seconds = (float) $value;

        if ( $seconds < 0 ) {
            return '';
        }

        $total_seconds = (int) round( $seconds );
        $hours         = (int) floor( $total_seconds / 3600 );
        $minutes       = (int) floor( ( $total_seconds % 3600 ) / 60 );
        $remaining     = (int) ( $total_seconds % 60 );

        if ( $hours > 0 ) {
            return sprintf( '%d:%02d:%02d', $hours, $minutes, $remaining );
        }

        return sprintf( '%d:%02d', $minutes, $remaining );
    }

    /**
     * Generate a human readable transcript title.
     *
     * @param string $file_name Source audio filename.
     * @param array  $result    Transcription payload.
     * @return string
     */
    protected function generate_transcript_title( $file_name, array $result ) {
        $file_name = sanitize_text_field( (string) $file_name );

        if ( '' === $file_name ) {
            return __( 'Audio Transcription', 'wp-mcp-ai' );
        }

        /* translators: %s: Source audio filename. */
        return sprintf( __( 'Transcription of %s', 'wp-mcp-ai' ), $file_name );
    }

    /**
     * Normalise a filename into a filesystem-safe stem.
     *
     * @param string $file_name Raw filename.
     * @return string
     */
    protected function normalise_file_stem( $file_name ) {
        $file_name = sanitize_file_name( (string) $file_name );

        if ( '' === $file_name ) {
            return 'openai-transcript';
        }

        $info = pathinfo( $file_name );
        $stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
        $stem = sanitize_title( $stem );

        if ( '' === $stem ) {
            $stem = 'openai-transcript';
        }

        return $stem;
    }

    /**
     * Delete a generated file safely when an error occurs.
     *
     * @param string $file_path Absolute file path.
     */
    protected function delete_file_safely( $file_path ) {
        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return;
        }

        if ( ! function_exists( 'wp_delete_file' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        wp_delete_file( $file_path );
    }

    /**
     * Prepare an audio attachment for transcription.
     *
     * @param int $attachment_id Attachment identifier.
     * @return array|WP_Error
     */
    protected function prepare_audio_attachment( $attachment_id ) {
        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return new WP_Error(
                'wp_mcp_ai_missing_audio_attachment',
                __( 'You must supply an audio attachment ID.', 'wp-mcp-ai' ),
                array( 'status' => 400 )
            );
        }

        if ( ! WP_MCP_AI_Message_Attachments::user_can_access_attachment( $attachment_id ) ) {
            return new WP_Error(
                'wp_mcp_ai_attachment_forbidden',
                __( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ),
                array( 'status' => 403 )
            );
        }

        $post = get_post( $attachment_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return new WP_Error(
                'wp_mcp_ai_attachment_missing',
                __( 'Attachment not found.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error(
                'wp_mcp_ai_attachment_missing_file',
                __( 'The attachment file could not be located.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $file_size = @filesize( $file_path );
        if ( false === $file_size ) {
            return new WP_Error(
                'wp_mcp_ai_attachment_size_unknown',
                __( 'Could not determine attachment size.', 'wp-mcp-ai' ),
                array( 'status' => 500 )
            );
        }

        $max_bytes = apply_filters( 'wp_mcp_ai_audio_transcription_max_bytes', self::MAX_AUDIO_BYTES, $attachment_id );
        if ( $file_size > $max_bytes ) {
            /* translators: %s: maximum bytes allowed for an audio attachment. */
            return new WP_Error(
                'wp_mcp_ai_attachment_too_large',
                sprintf( __( 'Audio attachments must be smaller than %s bytes.', 'wp-mcp-ai' ), number_format_i18n( $max_bytes ) ),
                array( 'status' => 413 )
            );
        }

        $mime_type = strtolower( (string) get_post_mime_type( $attachment_id ) );
        $allowed   = $this->get_allowed_audio_mime_types( $attachment_id );

        if ( '' === $mime_type || ! in_array( $mime_type, $allowed, true ) ) {
            $detected_mime = '';
            $file_info     = wp_check_filetype_and_ext( $file_path, wp_basename( $file_path ), wp_get_mime_types() );

            if ( ! empty( $file_info['type'] ) ) {
                $detected_mime = strtolower( $file_info['type'] );
            }

            if ( '' === $detected_mime ) {
                $filetype = wp_check_filetype( wp_basename( $file_path ), wp_get_mime_types() );

                if ( $filetype && ! empty( $filetype['type'] ) ) {
                    $detected_mime = strtolower( $filetype['type'] );
                }
            }

            if ( '' !== $detected_mime && in_array( $detected_mime, $allowed, true ) ) {
                $mime_type = $detected_mime;
            }
        }

        if ( '' === $mime_type || ! in_array( $mime_type, $allowed, true ) ) {
            return new WP_Error(
                'wp_mcp_ai_attachment_unsupported_mime',
                __( 'The attachment is not a supported audio format.', 'wp-mcp-ai' ),
                array( 'status' => 415 )
            );
        }

        return array(
            'file_path' => $file_path,
            'file_size' => (int) $file_size,
            'mime_type' => strtolower( $mime_type ),
            'file_name' => wp_basename( $file_path ),
        );
    }

    /**
     * Retrieve allowed audio MIME types for transcription.
     *
     * @param int $attachment_id Attachment identifier.
     * @return array
     */
    protected function get_allowed_audio_mime_types( $attachment_id ) {
        $mimes = array(
            'audio/aac',
            'audio/flac',
            'audio/m4a',
            'audio/mp3',
            'audio/mpeg',
            'audio/ogg',
            'audio/opus',
            'audio/wav',
            'audio/x-aac',
            'audio/x-flac',
            'audio/x-m4a',
            'audio/x-mp3',
            'audio/x-mpeg',
            'audio/x-ms-wma',
            'audio/x-wav',
            'audio/webm',
            'video/mp4',
            'video/quicktime',
        );

        /**
         * Filter the list of audio MIME types permitted for transcription.
         *
         * @param array $mimes          Allowed MIME types.
         * @param int   $attachment_id  Attachment identifier.
         */
        $mimes = apply_filters( 'wp_mcp_ai_audio_transcription_allowed_mimes', $mimes, $attachment_id );

        return array_values(
            array_unique(
                array_filter(
                    array_map( 'strtolower', (array) $mimes )
                )
            )
        );
    }
}
