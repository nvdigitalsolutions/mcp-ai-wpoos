<?php
/**
 * OpenAI API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides a small wrapper around OpenAI's Chat Completions HTTP endpoint.
 */
class WP_MCP_AI_OpenAI_Client {
    const CHAT_COMPLETIONS_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    const RESPONSES_ENDPOINT        = 'https://api.openai.com/v1/responses';
    const FILES_ENDPOINT            = 'https://api.openai.com/v1/files';
    const AUDIO_SPEECH_ENDPOINT     = 'https://api.openai.com/v1/audio/speech';
    const AUDIO_TRANSCRIPTIONS_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';
    const AUDIO_TRANSLATIONS_ENDPOINT   = 'https://api.openai.com/v1/audio/translations';
    const IMAGES_ENDPOINT           = 'https://api.openai.com/v1/images/generations';

    /**
     * Determine whether a given image model accepts the response_format parameter.
     *
     * @param string $model Image model identifier.
     * @return bool
     */
    public static function image_model_supports_response_format( $model ) {
        $model     = sanitize_text_field( $model );
        $supported = 'gpt-image-1' !== $model;

        /**
         * Filter whether the supplied image model supports the response_format parameter.
         *
         * @param bool   $supported Whether the response_format parameter is supported.
         * @param string $model     Model identifier.
         */
        return (bool) apply_filters( 'wp_mcp_ai_image_model_supports_response_format', $supported, $model );
    }

    /**
     * Retrieve the configured API key.
     *
     * @return string
     */
    public function get_api_key() {
        $settings = WP_MCP_AI_Admin_Settings::get_settings();

        return isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
    }

    /**
     * Upload a file to the OpenAI Files API.
     *
     * @param string $file_path Absolute file path on disk.
     * @param array  $args      Optional arguments (purpose, filename, mime_type, timeout).
     * @return array|WP_Error
     */
    public function upload_file( $file_path, array $args = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error(
                'wp_mcp_ai_file_upload_missing_file',
                __( 'The file to upload could not be located.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $purpose  = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
        $filename = isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '';
        $mime_type = isset( $args['mime_type'] ) ? sanitize_mime_type( $args['mime_type'] ) : '';

        if ( '' === $purpose ) {
            $purpose = 'assistants';
        }

        if ( '' === $filename ) {
            $filename = wp_basename( $file_path );
        }

        if ( '' === $mime_type ) {
            $mime_type = 'application/octet-stream';
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout  = max( 5, $timeout );

        $file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

        if ( false === $file_contents ) {
            WP_MCP_AI_Logger::log_error(
                'OpenAI file upload failed to read file.',
                array(
                    'file_path' => $file_path,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_file_upload_read_failed',
                __( 'The file to upload could not be read.', 'wp-mcp-ai' )
            );
        }

        $boundary = 'wp-mcp-ai-' . wp_generate_password( 24, false, false );

        $request_headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
        );

        $request_body = $this->build_multipart_body(
            array( 'purpose' => $purpose ),
            array(
                'name'         => 'file',
                'filename'     => $filename,
                'content_type' => $mime_type,
                'contents'     => $file_contents,
            ),
            $boundary
        );

        WP_MCP_AI_Logger::log_event(
            'openai_file_upload',
            'Uploading file to OpenAI.',
            array(
                'purpose'  => $purpose,
                'filename' => $filename,
            )
        );

        $request_args = array(
            'method'  => 'POST',
            'headers' => $request_headers,
            'body'    => $request_body,
            'timeout' => $timeout,
        );

        $response = $this->dispatch_http_request( self::FILES_ENDPOINT, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI file upload failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_file_upload_http_error',
                __( 'The OpenAI file upload failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body     = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $body, true );
        $json_err = json_last_error();

        if ( JSON_ERROR_NONE !== $json_err ) {
            WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI file upload response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_file_upload_invalid_response', __( 'OpenAI returned malformed JSON for the file upload.', 'wp-mcp-ai' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI file upload returned an error.', array( 'code' => $code, 'body' => $decoded ) );

            $message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file upload failed.', 'wp-mcp-ai' );

            return new WP_Error(
                'wp_mcp_ai_file_upload_error',
                $message,
                array(
                    'status'   => $code,
                    'response' => $decoded,
                )
            );
        }

        WP_MCP_AI_Logger::log_event(
            'openai_file_uploaded',
            'OpenAI file upload completed.',
            array(
                'file_id'  => isset( $decoded['id'] ) ? $decoded['id'] : '',
                'purpose'  => $purpose,
                'filename' => $filename,
            )
        );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Delete a file from the OpenAI Files API.
     *
     * @param string $file_id OpenAI file identifier.
     * @return array|WP_Error
     */
    public function delete_file( $file_id ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $file_id = sanitize_text_field( (string) $file_id );

        if ( '' === $file_id ) {
            return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'wp-mcp-ai' ) );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $timeout  = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 0;
        $timeout  = max( 5, $timeout );

        $endpoint = trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id );

        WP_MCP_AI_Logger::log_event(
            'openai_file_delete',
            'Deleting OpenAI file.',
            array( 'file_id' => $file_id )
        );

        $request_args = array(
            'method'  => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'timeout' => $timeout,
        );

        $response = $this->dispatch_http_request( $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error(
                'OpenAI file deletion failed.',
                array(
                    'file_id' => $file_id,
                    'error'   => $response->get_error_message(),
                )
            );

            return new WP_Error(
                'wp_mcp_ai_file_delete_http_error',
                __( 'The OpenAI file deletion request failed.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $body    = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $body, true );

        if ( JSON_ERROR_NONE !== json_last_error() ) {
            WP_MCP_AI_Logger::log_error(
                'Failed to decode OpenAI file deletion response.',
                array(
                    'file_id' => $file_id,
                    'body'    => $body,
                )
            );

            return new WP_Error( 'wp_mcp_ai_file_delete_invalid_response', __( 'OpenAI returned malformed JSON for the file deletion.', 'wp-mcp-ai' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            WP_MCP_AI_Logger::log_error(
                'OpenAI file deletion returned an error.',
                array(
                    'file_id' => $file_id,
                    'code'    => $code,
                    'body'    => $decoded,
                )
            );

            $message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file deletion failed.', 'wp-mcp-ai' );

            return new WP_Error(
                'wp_mcp_ai_file_delete_error',
                $message,
                array(
                    'status'   => $code,
                    'response' => $decoded,
                )
            );
        }

        WP_MCP_AI_Logger::log_event(
            'openai_file_deleted',
            'OpenAI file deletion completed.',
            array( 'file_id' => $file_id )
        );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Dispatch an HTTP request while honouring preemptive short-circuit filters.
     *
     * @param string $url  Target URL.
     * @param array  $args Request arguments.
     * @return array|WP_Error
     */
    protected function dispatch_http_request( $url, array $args ) {
        $url  = esc_url_raw( $url );
        $args = apply_filters( 'http_request_args', $args, $url );

        $preempt = $this->maybe_preempt_http_request( $url, $args );
        if ( null !== $preempt ) {
            return $preempt;
        }

        $method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'POST';

        if ( 'POST' === $method ) {
            return wp_remote_post( $url, $args );
        }

        return wp_remote_request( $url, $args );
    }

    /**
     * Build a multipart/form-data request body for a file upload.
     *
     * @param array  $fields   Associative array of scalar form fields.
     * @param array  $file     File payload arguments (name, filename, content_type, contents).
     * @param string $boundary Multipart boundary token.
     * @return string
     */
    protected function build_multipart_body( array $fields, array $file, $boundary ) {
        $eol      = "\r\n";
        $boundary = (string) $boundary;
        $body     = '';

        foreach ( $fields as $name => $value ) {
            $name  = (string) $name;
            $value = (string) $value;

            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
            $body .= $value . $eol;
        }

        if ( isset( $file['contents'] ) && '' !== $file['contents'] ) {
            $field_name   = isset( $file['name'] ) ? (string) $file['name'] : 'file';
            $filename     = isset( $file['filename'] ) ? (string) $file['filename'] : 'file';
            $content_type = isset( $file['content_type'] ) && '' !== $file['content_type'] ? (string) $file['content_type'] : 'application/octet-stream';
            $contents     = (string) $file['contents'];

            $body .= '--' . $boundary . $eol;
            $body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $filename . '"' . $eol;
            $body .= 'Content-Type: ' . $content_type . $eol . $eol;
            $body .= $contents . $eol;
        }

        $body .= '--' . $boundary . '--' . $eol;

        return $body;
    }

    /**
     * Execute the `pre_http_request` filters and return the first short-circuit response.
     *
     * @param string $url  Target URL.
     * @param array  $args Prepared request arguments.
     * @return array|WP_Error|null
     */
    protected function maybe_preempt_http_request( $url, array $args ) {
        if ( ! isset( $GLOBALS['wp_filter']['pre_http_request'] ) ) {
            return null;
        }

        $hook = $GLOBALS['wp_filter']['pre_http_request'];
        if ( ! $hook instanceof WP_Hook ) {
            return null;
        }

        $pre = false;

        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $callback ) {
                $accepted_args = isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1;
                $params        = array();

                if ( $accepted_args >= 1 ) {
                    $params[] = $pre;
                }

                if ( $accepted_args >= 2 ) {
                    $params[] = $args;
                }

                if ( $accepted_args >= 3 ) {
                    $params[] = $url;
                }

                $result = call_user_func_array( $callback['function'], $params );

                if ( false !== $result ) {
                    return $result;
                }

                $pre = $result;
            }
        }

        return null;
    }

    /**
     * Generate an image using the OpenAI Images API.
     *
     * @param string $prompt  Text prompt describing the desired image.
     * @param array  $options Optional overrides (model, size, quality, style, background, format, timeout).
     * @return array|WP_Error Array containing the image payload and metadata or WP_Error on failure.
     */
    public function generate_image( $prompt, array $options = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $prompt = sanitize_textarea_field( $prompt );

        if ( '' === $prompt ) {
            return new WP_Error(
                'wp_mcp_ai_missing_image_prompt',
                __( 'A text prompt must be supplied to generate an image.', 'wp-mcp-ai' ),
                array( 'status' => 400 )
            );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();

        $default_model      = isset( $settings['openai_image_model'] ) && '' !== $settings['openai_image_model'] ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1';
        $default_size       = isset( $settings['openai_image_size'] ) && '' !== $settings['openai_image_size'] ? sanitize_text_field( $settings['openai_image_size'] ) : '1024x1024';
        $default_quality    = isset( $settings['openai_image_quality'] ) && '' !== $settings['openai_image_quality'] ? sanitize_key( $settings['openai_image_quality'] ) : 'standard';
        $default_background = isset( $settings['openai_image_background'] ) ? sanitize_key( $settings['openai_image_background'] ) : '';
        $default_response_format = isset( $settings['openai_image_response_format'] ) && '' !== $settings['openai_image_response_format'] ? sanitize_key( $settings['openai_image_response_format'] ) : 'b64_json';

        if ( ! in_array( $default_response_format, array( 'b64_json', 'url' ), true ) ) {
            $default_response_format = 'b64_json';
        }

        $model     = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
        $size      = isset( $options['size'] ) && '' !== $options['size'] ? sanitize_text_field( $options['size'] ) : $default_size;
        $quality   = isset( $options['quality'] ) && '' !== $options['quality'] ? sanitize_key( $options['quality'] ) : $default_quality;
        $background = isset( $options['background'] ) && '' !== $options['background'] ? sanitize_key( $options['background'] ) : $default_background;
        $requested_format = isset( $options['format'] ) && '' !== $options['format'] ? sanitize_key( $options['format'] ) : 'png';
        $response_format = isset( $options['response_format'] ) && '' !== $options['response_format'] ? sanitize_key( $options['response_format'] ) : $default_response_format;

        $model_supports_response_format = self::image_model_supports_response_format( $model );

        if ( ! in_array( $response_format, array( 'b64_json', 'url' ), true ) ) {
            $response_format = $default_response_format;
        }

        if ( ! $model_supports_response_format ) {
            $response_format = 'b64_json';
        }

        $timeout   = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout   = max( 5, $timeout );

        $payload = array(
            'model'   => $model,
            'prompt'  => $prompt,
            'size'    => $size,
            'quality' => $quality,
            'n'       => 1,
        );

        if ( '' !== $background ) {
            $payload['background'] = $background;
        }

        if ( $model_supports_response_format && '' !== $response_format ) {
            $payload['response_format'] = $response_format;
        }

        /**
         * Allow third parties to filter the OpenAI image payload prior to dispatch.
         *
         * @param array $payload Prepared request payload.
         * @param array $options Original method options.
         */
        $payload = apply_filters( 'wp_mcp_ai_openai_image_payload', $payload, $options );

        $encoded_payload = wp_json_encode( $payload );
        if ( false === $encoded_payload ) {
            return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenAI request payload.', 'wp-mcp-ai' ) );
        }

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => $timeout,
            'body'    => $encoded_payload,
        );

        WP_MCP_AI_Logger::log_event(
            'openai_image_request',
            'Sending image generation request to OpenAI.',
            array(
                'model'     => $model,
                'size'      => $size,
                'quality'   => $quality,
                'background'=> $background,
                'requested_format' => $requested_format,
                'response_format'  => $response_format,
            )
        );

        $response = wp_remote_post( self::IMAGES_ENDPOINT, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI image request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_http_error',
                __( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $status_code  = wp_remote_retrieve_response_code( $response );
        $body         = wp_remote_retrieve_body( $response );
        $headers      = wp_remote_retrieve_headers( $response );
        $content_type = $this->extract_content_type( $headers );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $decoded    = json_decode( $body, true );
            $json_error = json_last_error();

            if ( JSON_ERROR_NONE === $json_error && isset( $decoded['error']['message'] ) ) {
                $message = $decoded['error']['message'];
            } else {
                $message = sprintf( __( 'The OpenAI image request failed with status %d.', 'wp-mcp-ai' ), $status_code );
            }

            WP_MCP_AI_Logger::log_error(
                'OpenAI image request returned an error.',
                array(
                    'code'        => $status_code,
                    'message'     => $message,
                    'body'        => JSON_ERROR_NONE === $json_error ? $decoded : $body,
                    'contentType' => $content_type,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_image_error',
                $message,
                array(
                    'status'   => $status_code,
                    'response' => JSON_ERROR_NONE === $json_error ? $decoded : array( 'body' => $body ),
                )
            );
        }

        $is_json_response  = $this->is_json_content_type( $content_type );
        $decoded           = array();
        $image_data        = '';
        $response_mime     = '';
        $response_format   = '';
        $response_created  = 0;
        $response_revision = '';

        if ( $is_json_response || '' === $content_type ) {
            $decoded    = json_decode( $body, true );
            $json_error = json_last_error();

            if ( JSON_ERROR_NONE !== $json_error ) {
                // Not JSON, fall through to binary detection below.
                $decoded = array();
            }
        }

        if ( ! empty( $decoded ) ) {
            if ( empty( $decoded['data'] ) || empty( $decoded['data'][0] ) || ! is_array( $decoded['data'][0] ) ) {
                WP_MCP_AI_Logger::log_error( 'OpenAI image response missing payload data.', array( 'response' => $decoded ) );

                return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
            }

            $image_response = $decoded['data'][0];

            if ( isset( $image_response['b64_json'] ) && '' !== $image_response['b64_json'] ) {
                $image_data = base64_decode( $image_response['b64_json'], true );

                if ( false === $image_data ) {
                    WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI image payload.', array( 'response' => $decoded ) );

                    return new WP_Error( 'wp_mcp_ai_image_decode_error', __( 'OpenAI returned an invalid image payload.', 'wp-mcp-ai' ) );
                }

                $response_format = $this->detect_format_from_binary( $image_data );
                if ( '' === $response_format ) {
                    $response_format = 'png';
                }
                $response_mime = $this->normalise_image_mime_type( $response_format );
            } elseif ( isset( $image_response['url'] ) && '' !== $image_response['url'] ) {
                $image_url = esc_url_raw( $image_response['url'] );

                if ( '' === $image_url ) {
                    WP_MCP_AI_Logger::log_error( 'OpenAI image response returned an invalid URL.', array( 'url' => $image_response['url'] ) );

                    return new WP_Error( 'wp_mcp_ai_image_invalid_url', __( 'OpenAI returned an invalid image URL.', 'wp-mcp-ai' ) );
                }

                $downloaded_image = $this->download_image_from_url( $image_url, $timeout );

                if ( is_wp_error( $downloaded_image ) ) {
                    return $downloaded_image;
                }

                $image_data      = $downloaded_image['body'];
                $content_type    = $downloaded_image['content_type'];
                $response_format = $this->detect_format_from_mime_type( $content_type );

                if ( '' === $response_format ) {
                    $response_format = $this->detect_format_from_binary( $image_data );
                }

                if ( '' === $response_format ) {
                    $response_format = 'png';
                }

                $response_mime = '' !== $content_type ? $content_type : $this->normalise_image_mime_type( $response_format );
            } else {
                WP_MCP_AI_Logger::log_error( 'OpenAI image response missing supported payload keys.', array( 'response' => $decoded ) );

                return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
            }

            if ( '' === $image_data ) {
                WP_MCP_AI_Logger::log_error( 'OpenAI image response contained no data.', array( 'response' => $decoded ) );

                return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
            }

            $response_created  = isset( $decoded['created'] ) ? intval( $decoded['created'] ) : 0;
            $response_revision = isset( $image_response['revised_prompt'] ) ? (string) $image_response['revised_prompt'] : '';
        } elseif ( $this->is_image_content_type( $content_type ) || 'application/octet-stream' === $content_type ) {
            $image_data = $body;

            if ( '' === $image_data ) {
                WP_MCP_AI_Logger::log_error( 'OpenAI image response contained no data.', array( 'contentType' => $content_type ) );

                return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
            }

            $response_format = $this->detect_format_from_mime_type( $content_type );

            if ( '' === $response_format ) {
                $response_format = $this->detect_format_from_binary( $image_data );
            }

            if ( '' === $response_format ) {
                $response_format = 'png';
            }

            $response_mime = '' !== $content_type ? $content_type : $this->normalise_image_mime_type( $response_format );
        } else {
            WP_MCP_AI_Logger::log_error(
                'Failed to decode OpenAI image response.',
                array(
                    'body'        => $body,
                    'contentType' => $content_type,
                )
            );

            return new WP_Error( 'wp_mcp_ai_image_invalid_response', __( 'OpenAI returned an unexpected image response format.', 'wp-mcp-ai' ) );
        }

        $result = array(
            'image'          => $image_data,
            'format'         => $response_format,
            'mime_type'      => $response_mime,
            'model'          => $model,
            'prompt'         => $prompt,
            'size'           => $size,
            'quality'        => $quality,
            'background'     => $background,
            'created'        => $response_created,
            'revised_prompt' => $response_revision,
        );

        WP_MCP_AI_Logger::log_event(
            'openai_image_response',
            'OpenAI image generation completed.',
            array(
                'model'      => $model,
                'size'       => $size,
                'quality'    => $quality,
                'background' => $background,
                'format'     => $response_format,
            )
        );

        return $result;
    }

    /**
     * Download an image from a remote URL.
     *
     * @param string $url     Image URL.
     * @param int    $timeout Request timeout.
     * @return array|WP_Error Array containing body and content_type on success.
     */
    protected function download_image_from_url( $url, $timeout ) {
        $request_args = array(
            'timeout' => max( 5, absint( $timeout ) ),
        );

        $response = wp_remote_get( $url, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error(
                'Failed to download image from OpenAI URL.',
                array(
                    'url'   => $url,
                    'error' => $response->get_error_message(),
                )
            );

            return new WP_Error(
                'wp_mcp_ai_image_download_http_error',
                __( 'The generated image could not be downloaded from OpenAI.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $headers     = wp_remote_retrieve_headers( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            WP_MCP_AI_Logger::log_error(
                'OpenAI image download returned an error.',
                array(
                    'url'    => $url,
                    'code'   => $status_code,
                    'body'   => $body,
                    'header' => $headers,
                )
            );

            return new WP_Error(
                'wp_mcp_ai_image_download_error',
                sprintf( __( 'OpenAI returned an HTTP %d while downloading the generated image.', 'wp-mcp-ai' ), $status_code ),
                array(
                    'status'   => $status_code,
                    'response' => $body,
                )
            );
        }

        if ( '' === $body ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI image download returned empty content.', array( 'url' => $url ) );

            return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'wp-mcp-ai' ) );
        }

        return array(
            'body'         => $body,
            'content_type' => $this->extract_content_type( $headers ),
        );
    }

    /**
     * Normalise the image MIME type based on the requested format.
     *
     * @param string $format Requested format.
     * @return string
     */
    protected function normalise_image_mime_type( $format ) {
        $format = sanitize_key( $format );

        switch ( $format ) {
            case 'jpeg':
            case 'jpg':
                return 'image/jpeg';
            case 'webp':
                return 'image/webp';
            case 'png':
            default:
                return 'image/png';
        }
    }

    /**
     * Extract the content type header from an HTTP response.
     *
     * @param array|ArrayAccess $headers Response headers.
     * @return string
     */
    protected function extract_content_type( $headers ) {
        if ( empty( $headers ) ) {
            return '';
        }

        $content_type = '';

        if ( is_array( $headers ) ) {
            $content_type = isset( $headers['content-type'] ) ? $headers['content-type'] : '';
        } elseif ( is_object( $headers ) && $headers instanceof ArrayAccess ) {
            if ( $headers->offsetExists( 'content-type' ) ) {
                $content_type = $headers->offsetGet( 'content-type' );
            }
        } elseif ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
            $all_headers = $headers->getAll();
            if ( isset( $all_headers['content-type'] ) ) {
                $content_type = $all_headers['content-type'];
            }
        }

        if ( is_array( $content_type ) ) {
            $content_type = reset( $content_type );
        }

        $content_type = explode( ';', (string) $content_type );
        $content_type = trim( strtolower( $content_type[0] ) );

        return $content_type;
    }

    /**
     * Determine whether the provided content type represents JSON.
     *
     * @param string $content_type Header value.
     * @return bool
     */
    protected function is_json_content_type( $content_type ) {
        if ( '' === $content_type ) {
            return false;
        }

        return false !== strpos( $content_type, 'application/json' ) || false !== strpos( $content_type, 'text/json' );
    }

    /**
     * Determine whether the provided content type represents an image payload.
     *
     * @param string $content_type Header value.
     * @return bool
     */
    protected function is_image_content_type( $content_type ) {
        if ( '' === $content_type ) {
            return false;
        }

        return 0 === strpos( $content_type, 'image/' );
    }

    /**
     * Attempt to detect an image format from a MIME type.
     *
     * @param string $mime_type MIME type string.
     * @return string
     */
    protected function detect_format_from_mime_type( $mime_type ) {
        $mime_type = strtolower( (string) $mime_type );

        switch ( $mime_type ) {
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpeg';
            case 'image/webp':
                return 'webp';
            case 'image/png':
                return 'png';
            default:
                return '';
        }
    }

    /**
     * Attempt to detect an image format from raw binary data.
     *
     * @param string $image_data Raw image bytes.
     * @return string
     */
    protected function detect_format_from_binary( $image_data ) {
        if ( '' === $image_data ) {
            return '';
        }

        if ( function_exists( 'getimagesizefromstring' ) ) {
            $details = @getimagesizefromstring( $image_data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( is_array( $details ) && ! empty( $details['mime'] ) ) {
                $format = $this->detect_format_from_mime_type( $details['mime'] );

                if ( '' !== $format ) {
                    return $format;
                }
            }
        }

        if ( function_exists( 'finfo_buffer' ) && defined( 'FILEINFO_MIME_TYPE' ) && class_exists( 'finfo' ) ) {
            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $mime  = $finfo->buffer( $image_data );

            if ( $mime ) {
                $format = $this->detect_format_from_mime_type( $mime );

                if ( '' !== $format ) {
                    return $format;
                }
            }
        }

        $signature = substr( $image_data, 0, 12 );

        if ( 0 === strncmp( $signature, "\x89PNG", 4 ) ) {
            return 'png';
        }

        if ( 0 === strncmp( $signature, "\xFF\xD8\xFF", 3 ) ) {
            return 'jpeg';
        }

        if ( 0 === strncmp( $signature, 'RIFF', 4 ) && 0 === strncmp( substr( $signature, 8, 4 ), 'WEBP', 4 ) ) {
            return 'webp';
        }

        return '';
    }

    /**
     * Generate speech audio from text using the OpenAI Text-to-Speech API.
     *
     * @param string $input   Text that should be converted to speech.
     * @param array  $options Optional overrides (model, voice, format, speed, timeout).
     * @return array|WP_Error Array containing the audio payload and metadata or WP_Error on failure.
     */
    public function generate_speech( $input, array $options = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $input = sanitize_textarea_field( $input );

        if ( '' === $input ) {
            return new WP_Error(
                'wp_mcp_ai_missing_speech_input',
                __( 'A text prompt must be supplied to generate speech.', 'wp-mcp-ai' ),
                array( 'status' => 400 )
            );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();

        $default_model  = ! empty( $settings['openai_speech_model'] ) ? sanitize_text_field( $settings['openai_speech_model'] ) : 'gpt-4o-mini-tts';
        $default_voice  = isset( $settings['openai_speech_voice'] ) ? sanitize_key( $settings['openai_speech_voice'] ) : 'alloy';
        $default_format = isset( $settings['openai_speech_format'] ) ? sanitize_key( $settings['openai_speech_format'] ) : 'mp3';

        if ( '' === $default_voice ) {
            $default_voice = 'alloy';
        }

        if ( '' === $default_format ) {
            $default_format = 'mp3';
        }

        if ( '' === $default_model ) {
            $default_model = 'gpt-4o-mini-tts';
        }

        $model   = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
        $voice   = isset( $options['voice'] ) && '' !== $options['voice'] ? sanitize_key( $options['voice'] ) : $default_voice;
        $format  = isset( $options['format'] ) && '' !== $options['format'] ? sanitize_key( $options['format'] ) : $default_format;
        $timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout = max( 5, $timeout );

        $payload = array(
            'model'  => $model,
            'input'  => $input,
            'voice'  => $voice,
            'format' => $format,
        );

        if ( isset( $options['speed'] ) && '' !== $options['speed'] ) {
            $speed = floatval( $options['speed'] );
            $speed = max( 0.25, min( 4, $speed ) );
            $payload['speed'] = $speed;
        } else {
            $speed = null;
        }

        /**
         * Allow third parties to filter the OpenAI speech payload prior to dispatch.
         *
         * @param array $payload Prepared request payload.
         * @param array $options Original method options.
         */
        $payload = apply_filters( 'wp_mcp_ai_openai_speech_payload', $payload, $options );

        $encoded_payload = wp_json_encode( $payload );
        if ( false === $encoded_payload ) {
            return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenAI request payload.', 'wp-mcp-ai' ) );
        }

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => $timeout,
            'body'    => $encoded_payload,
        );

        WP_MCP_AI_Logger::log_event(
            'openai_tts_request',
            'Sending text-to-speech request to OpenAI.',
            array(
                'model'  => $model,
                'voice'  => $voice,
                'format' => $format,
                'speed'  => $speed,
            )
        );

        $response = wp_remote_post( self::AUDIO_SPEECH_ENDPOINT, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI text-to-speech request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_http_error',
                __( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $decoded = json_decode( $body, true );
            $error   = json_last_error();

            if ( JSON_ERROR_NONE === $error && isset( $decoded['error']['message'] ) ) {
                $message = $decoded['error']['message'];
            } else {
                $message = __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );
            }

            WP_MCP_AI_Logger::log_error(
                'OpenAI text-to-speech request returned an error.',
                array(
                    'status'   => $status_code,
                    'response' => JSON_ERROR_NONE === $error ? $decoded : $body,
                )
            );

            return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $status_code ) );
        }

        if ( '' === $body ) {
            return new WP_Error( 'wp_mcp_ai_empty_audio', __( 'OpenAI returned an empty audio response.', 'wp-mcp-ai' ) );
        }

        $headers = wp_remote_retrieve_headers( $response );
        $type    = isset( $headers['content-type'] ) ? sanitize_text_field( $headers['content-type'] ) : '';

        return array(
            'audio'        => $body,
            'format'       => $format,
            'model'        => $model,
            'voice'        => $voice,
            'speed'        => $speed,
            'content_type' => $type,
        );
    }

    /**
     * Transcribe or translate an audio file using the OpenAI Audio API.
     *
     * @param string $file_path Absolute path to the audio file on disk.
     * @param array  $options   Optional overrides (model, translate, prompt, temperature, response_format, timeout, language, filename, mime_type).
     * @return array|WP_Error   Array containing the transcription payload or WP_Error on failure.
     */
    public function transcribe_audio( $file_path, array $options = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $file_path = (string) $file_path;

        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return new WP_Error(
                'wp_mcp_ai_transcription_missing_file',
                __( 'The audio file to transcribe could not be located.', 'wp-mcp-ai' ),
                array( 'status' => 404 )
            );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();

        $model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'gpt-4o-mini-transcribe';
        if ( '' === $model ) {
            $model = 'gpt-4o-mini-transcribe';
        }

        $translate = false;

        if ( isset( $options['translate'] ) ) {
            $translate = (bool) $options['translate'];
        }

        if ( isset( $options['task'] ) ) {
            $task = strtolower( sanitize_text_field( $options['task'] ) );

            if ( 'translate' === $task ) {
                $translate = true;
            } elseif ( 'transcribe' === $task ) {
                $translate = false;
            }
        }

        $timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout = max( 5, $timeout );

        $response_format = isset( $options['response_format'] ) && '' !== $options['response_format'] ? strtolower( sanitize_key( $options['response_format'] ) ) : 'verbose_json';
        $allowed_formats = array( 'json', 'verbose_json' );

        if ( ! in_array( $response_format, $allowed_formats, true ) ) {
            $response_format = 'verbose_json';
        }

        $fields = array(
            'model'           => $model,
            'response_format' => $response_format,
        );

        if ( isset( $options['prompt'] ) && '' !== $options['prompt'] ) {
            $fields['prompt'] = sanitize_textarea_field( $options['prompt'] );
        }

        if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
            $temperature = floatval( $options['temperature'] );
            $temperature = max( 0, min( 1, $temperature ) );
            $fields['temperature'] = $temperature;
        }

        if ( ! $translate && isset( $options['language'] ) && '' !== $options['language'] ) {
            $fields['language'] = sanitize_text_field( $options['language'] );
        }

        $filename = isset( $options['filename'] ) && '' !== $options['filename'] ? sanitize_file_name( $options['filename'] ) : wp_basename( $file_path );

        $mime_type = isset( $options['mime_type'] ) && '' !== $options['mime_type'] ? sanitize_mime_type( $options['mime_type'] ) : '';

        if ( '' === $mime_type ) {
            $filetype = wp_check_filetype( $filename );
            if ( $filetype && ! empty( $filetype['type'] ) ) {
                $mime_type = $filetype['type'];
            }
        }

        if ( '' === $mime_type ) {
            $mime_type = 'application/octet-stream';
        }

        $file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

        if ( false === $file_contents ) {
            WP_MCP_AI_Logger::log_error(
                'OpenAI audio transcription failed to read file.',
                array( 'file_path' => $file_path )
            );

            return new WP_Error(
                'wp_mcp_ai_transcription_read_failed',
                __( 'The audio file could not be read from disk.', 'wp-mcp-ai' )
            );
        }

        $boundary = 'wp-mcp-ai-' . wp_generate_password( 24, false, false );

        $request_body = $this->build_multipart_body(
            $fields,
            array(
                'name'         => 'file',
                'filename'     => $filename,
                'content_type' => $mime_type,
                'contents'     => $file_contents,
            ),
            $boundary
        );

        $request_args = array(
            'method'  => 'POST',
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'timeout' => $timeout,
            'body'    => $request_body,
        );

        $endpoint = $translate ? self::AUDIO_TRANSLATIONS_ENDPOINT : self::AUDIO_TRANSCRIPTIONS_ENDPOINT;

        WP_MCP_AI_Logger::log_event(
            'openai_audio_transcription_request',
            'Sending audio transcription request to OpenAI.',
            array(
                'model'           => $model,
                'translate'       => $translate,
                'response_format' => $response_format,
                'filename'        => $filename,
            )
        );

        $response = $this->dispatch_http_request( $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI audio transcription request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_http_error',
                __( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $decoded = json_decode( $body, true );
            $error   = json_last_error();

            if ( JSON_ERROR_NONE === $error && isset( $decoded['error']['message'] ) ) {
                $message = $decoded['error']['message'];
            } else {
                $message = __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );
            }

            WP_MCP_AI_Logger::log_error(
                'OpenAI audio transcription request returned an error.',
                array(
                    'status'   => $status_code,
                    'response' => JSON_ERROR_NONE === $error ? $decoded : $body,
                )
            );

            return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $status_code ) );
        }

        $decoded = json_decode( $body, true );
        $error   = json_last_error();

        if ( JSON_ERROR_NONE !== $error || ! is_array( $decoded ) ) {
            WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI audio transcription response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_transcription_invalid_response', __( 'OpenAI returned malformed JSON for the audio transcription.', 'wp-mcp-ai' ) );
        }

        $text = isset( $decoded['text'] ) ? (string) $decoded['text'] : '';

        if ( '' === $text ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI audio transcription response did not include text.', array( 'response' => $decoded ) );

            return new WP_Error( 'wp_mcp_ai_transcription_empty_text', __( 'OpenAI did not return any transcription text.', 'wp-mcp-ai' ) );
        }

        $result = array(
            'text'        => $text,
            'model'       => $model,
            'translated'  => $translate,
            'format'      => $response_format,
        );

        if ( isset( $decoded['language'] ) ) {
            $result['language'] = (string) $decoded['language'];
        }

        if ( isset( $decoded['duration'] ) && '' !== $decoded['duration'] ) {
            $result['duration'] = floatval( $decoded['duration'] );
        }

        if ( isset( $decoded['segments'] ) && is_array( $decoded['segments'] ) ) {
            $result['segments'] = $decoded['segments'];
        }

        return $result;
    }

    /**
     * Perform a chat completion request.
     *
     * @param array $messages Message payload to send to OpenAI.
     * @param array $options  Additional options (model, temperature, tools, timeout).
     * @return array|WP_Error
     */
    public function create_chat_completion( array $messages, array $options = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_api_key',
                __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'configure_openai_api_key' => __( 'Add an OpenAI API key in the WP MCP AI settings.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        $settings    = WP_MCP_AI_Admin_Settings::get_settings();
        $model       = ! empty( $options['model'] ) ? $options['model'] : $settings['default_model'];
        $timeout     = ! empty( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout     = max( 5, $timeout );
        $attachments = $this->extract_attachments_from_options( $options );
        $payload     = array(
            'model' => $model,
        );

        if ( ! empty( $attachments ) ) {
            $options['attachments'] = $attachments;
        }

        $should_use_responses_api = $this->should_use_responses_api( $messages, $options );

        if ( ! empty( $attachments ) && ! $should_use_responses_api ) {
            $should_use_responses_api = true;
        }

        $chat_messages = $this->normalise_messages_for_payload( $messages );
        $attachment_lookup = array();

        if ( $should_use_responses_api ) {
            $attachment_lookup = $this->index_attachments_by_id( $attachments );
            $payload['input'] = $this->prepare_responses_input( $messages, $chat_messages, $attachments );
        } else {
            $payload['messages'] = $chat_messages;
        }

        $message_key = $should_use_responses_api ? 'input' : 'messages';

        if ( empty( $payload[ $message_key ] ) ) {
            return new WP_Error(
                'wp_mcp_ai_missing_messages',
                __( 'No chat messages were provided for the request.', 'wp-mcp-ai' ),
                array(
                    'status'  => 400,
                    'actions' => array(
                        'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'wp-mcp-ai' ),
                    ),
                )
            );
        }

        if ( isset( $options['temperature'] ) && null !== $options['temperature'] && '' !== $options['temperature'] ) {
            $temperature            = floatval( $options['temperature'] );
            $payload['temperature'] = max( 0, min( 2, $temperature ) );
        }

        $system_messages = array();

        if ( ! empty( $options['system_prompt'] ) ) {
            $system_messages[] = array(
                'role'    => 'system',
                'content' => array(
                    array(
                        'type' => 'text',
                        'text' => (string) $options['system_prompt'],
                    ),
                ),
            );
        }

        $memory_messages = $this->build_memory_messages_from_options( $options );

        if ( ! empty( $memory_messages ) ) {
            $system_messages = array_merge( $system_messages, $memory_messages );
        }

        if ( ! empty( $system_messages ) ) {
            if ( $should_use_responses_api ) {
                foreach ( $system_messages as &$system_message ) {
                    if ( ! isset( $system_message['content'] ) ) {
                        continue;
                    }

                    if ( is_array( $system_message['content'] ) ) {
                        $system_message['content'] = $this->normalise_responses_content_segments( $system_message['content'], $attachment_lookup, isset( $system_message['role'] ) ? $system_message['role'] : 'system' );
                    } else {
                        $system_message['content'] = $this->normalise_responses_content_segments(
                            array(
                                array(
                                    'type' => 'text',
                                    'text' => (string) $system_message['content'],
                                ),
                            ),
                            $attachment_lookup,
                            isset( $system_message['role'] ) ? $system_message['role'] : 'system'
                        );
                    }
                }
                unset( $system_message );
            }

            $payload[ $message_key ] = array_merge( $system_messages, $payload[ $message_key ] );
        }

        if ( ! empty( $options['tools'] ) ) {
            $payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
        }

        if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
            $payload['response_format'] = $options['response_format'];
        }

        $request_headers = array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        );

        if ( $should_use_responses_api ) {
            $request_headers['OpenAI-Beta'] = 'responses=v1';
        }

        $request_args = array(
            'headers' => $request_headers,
            'body'    => wp_json_encode( $payload ),
            'timeout' => $timeout,
        );

        WP_MCP_AI_Logger::log_event( 'openai_request', 'Sending request to OpenAI.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

        $endpoint = $should_use_responses_api ? self::RESPONSES_ENDPOINT : self::CHAT_COMPLETIONS_ENDPOINT;
        $response = wp_remote_post( $endpoint, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI request failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_http_error',
                __( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body     = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $body, true );
        $json_err = json_last_error();

        if ( JSON_ERROR_NONE !== $json_err ) {
            WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The OpenAI API returned malformed JSON.', 'wp-mcp-ai' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI returned an error response.', array( 'code' => $code, 'body' => $decoded ) );

            $message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );

            return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $code, 'body' => $decoded ) );
        }

        if ( $should_use_responses_api ) {
            $decoded = $this->convert_responses_result_to_chat_completion( $decoded );
        }

        if ( is_array( $decoded ) ) {
            if ( ! isset( $decoded['provider'] ) ) {
                $decoded['provider'] = 'openai';
            }

            if ( ! isset( $decoded['model'] ) && ! empty( $model ) ) {
                $decoded['model'] = $model;
            }
        }

        WP_MCP_AI_Logger::log_event( 'openai_response', 'OpenAI request completed.', array( 'response' => $decoded ) );

        return $decoded;
    }

    /**
     * Prepare chat messages for the OpenAI Chat Completions payload.
     *
     * The REST layer represents text-only messages as arrays of segments so
     * attachments and tool calls can be normalised consistently. Older OpenAI
     * models (for example, gpt-3.5-turbo) only accept plain strings for the
     * `content` field which causes those requests to fail. To remain compatible
     * we collapse text-only segment arrays back into strings while preserving
     * multimodal payloads that rely on structured segments.
     *
     * @param array $messages Sanitised chat messages.
     * @return array
     */
    protected function normalise_messages_for_payload( array $messages ) {
        $normalised = array();

        foreach ( $messages as $message ) {
            if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
                $normalised[] = $message;
                continue;
            }

            $segments = array_values( $message['content'] );

            if ( empty( $segments ) ) {
                $message['content'] = '';
                $normalised[]       = $message;
                continue;
            }

            $all_text   = true;
            $text_parts = array();

            foreach ( $segments as $segment ) {
                if ( ! is_array( $segment ) ) {
                    $all_text = false;
                    break;
                }

                $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

                if ( 'text' !== $type ) {
                    $all_text = false;
                    break;
                }

                $text_parts[] = isset( $segment['text'] ) ? (string) $segment['text'] : '';
            }

            if ( $all_text ) {
                $text_parts         = array_filter( $text_parts, static function ( $part ) {
                    return '' !== trim( $part );
                } );
                $message['content'] = implode( "\n\n", $text_parts );
            } else {
                $message['content'] = $segments;
            }

            $normalised[] = $message;
        }

        return $normalised;
    }

    /**
     * Build additional system messages from memory documents.
     *
     * @param array $options Chat request options.
     * @return array
     */
    protected function build_memory_messages_from_options( array $options ) {
        if ( empty( $options['memory_documents'] ) || ! is_array( $options['memory_documents'] ) ) {
            return array();
        }

        $messages = array();

        foreach ( $options['memory_documents'] as $document ) {
            if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
                continue;
            }

            $title      = isset( $document['title'] ) && '' !== $document['title'] ? $document['title'] : __( 'Document', 'wp-mcp-ai' );
            $chunks     = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );
            $parts      = count( $chunks );
            $part_index = 0;

            foreach ( $chunks as $chunk ) {
                $part_index++;

                $label = $title;

                if ( $parts > 1 ) {
                    /* translators: %1$s: document title, %2$d: chunk number. */
                    $label = sprintf( __( '%1$s (Part %2$d)', 'wp-mcp-ai' ), $title, $part_index );
                }

                $messages[] = array(
                    'role'    => 'system',
                    'content' => array(
                        array(
                            'type' => 'text',
                            /* translators: %1$s: document title, %2$s: extracted text snippet. */
                            'text' => sprintf( __( 'Reference document "%1$s": %2$s', 'wp-mcp-ai' ), $label, $chunk ),
                        ),
                    ),
                );
            }
        }

        return $messages;
    }

    /**
     * Normalise tool definitions to satisfy the OpenAI payload schema.
     *
     * @param array $tools Tool definitions sourced from the REST layer.
     * @return array
     */
    protected function normalise_tools_for_payload( $tools ) {
        if ( $tools instanceof \Traversable ) {
            $tools = iterator_to_array( $tools );
        }

        if ( is_object( $tools ) ) {
            $tools = (array) $tools;
        }

        if ( ! is_array( $tools ) ) {
            return array();
        }

        $normalised = array();

        foreach ( $tools as $tool ) {
            if ( $tool instanceof \Traversable ) {
                $tool = iterator_to_array( $tool );
            }

            if ( is_object( $tool ) ) {
                $tool = (array) $tool;
            }

            if ( ! is_array( $tool ) || empty( $tool ) ) {
                continue;
            }

            $type = isset( $tool['type'] ) ? sanitize_key( $tool['type'] ) : '';

            if ( 'function' === $type ) {
                if ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) {
                    if ( isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
                        $tool['name'] = (string) $tool['function']['name'];
                    }
                }
            }

            if ( ! isset( $tool['name'] ) || '' === $tool['name'] ) {
                if ( isset( $tool['function'] ) && is_array( $tool['function'] ) && isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
                    $tool['name'] = (string) $tool['function']['name'];
                } elseif ( isset( $tool['slug'] ) && '' !== $tool['slug'] ) {
                    $tool['name'] = (string) $tool['slug'];
                } elseif ( isset( $tool['id'] ) && '' !== $tool['id'] ) {
                    $tool['name'] = (string) $tool['id'];
                }
            }

            if ( ! isset( $tool['name'] ) || '' === trim( (string) $tool['name'] ) ) {
                continue;
            }

            $tool['name'] = (string) $tool['name'];

            $normalised[] = $tool;
        }

        return array_values( $normalised );
    }

    /**
     * Remove large message payloads when logging requests.
     *
     * @param array $payload The payload that will be logged.
     * @return array
     */
    protected function obfuscate_request_for_log( array $payload ) {
        $message_key = null;

        if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
            $message_key = 'messages';
        } elseif ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
            $message_key = 'input';
        }

        if ( null !== $message_key ) {
            $trimmed_messages = array();
            foreach ( $payload[ $message_key ] as $message ) {
                if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
                    $trimmed_segments = array();

                    foreach ( $message['content'] as $segment ) {
                        if ( ! is_array( $segment ) ) {
                            continue;
                        }

                        $segment_copy = $segment;
                        $type         = isset( $segment['type'] ) ? $segment['type'] : '';

                        if ( in_array( $type, array( 'text', 'input_text', 'output_text' ), true ) && isset( $segment['text'] ) ) {
                            $content = (string) $segment['text'];
                            $length  = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
                            $slice   = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
                            $segment_copy['text'] = $slice . ( $length > 200 ? '…' : '' );
                        }

                        if ( 'input_image' === $type && isset( $segment['image_url']['url'] ) ) {
                            $segment_copy['image_url']['url'] = esc_url_raw( $segment['image_url']['url'] );
                        }

                        if ( 'input_image' === $type && isset( $segment['image_file']['file_id'] ) ) {
                            $segment_copy['image_file'] = array( 'file_id' => $segment['image_file']['file_id'] );
                        }

                        if ( 'input_file' === $type ) {
                            $segment_copy = array( 'type' => 'input_file' );

                            if ( isset( $segment['file_id'] ) ) {
                                $segment_copy['file_id'] = $segment['file_id'];
                            }

                            if ( isset( $segment['file'] ) && is_array( $segment['file'] ) ) {
                                $file_entry = array();

                                if ( isset( $segment['file']['id'] ) ) {
                                    $file_entry['id'] = $segment['file']['id'];
                                } elseif ( isset( $segment['file']['file_id'] ) ) {
                                    $file_entry['id'] = $segment['file']['file_id'];
                                }

                                if ( ! empty( $file_entry ) ) {
                                    $segment_copy['file'] = $file_entry;
                                }
                            }

                            if ( isset( $segment['display_name'] ) ) {
                                $segment_copy['display_name'] = $segment['display_name'];
                            }

                            if ( isset( $segment['filename'] ) ) {
                                $segment_copy['filename'] = $segment['filename'];
                            }

                            if ( isset( $segment['file_data'] ) ) {
                                $segment_copy['file_data'] = '[redacted]';
                            }
                        }

                        $trimmed_segments[] = $segment_copy;
                    }

                    $message['content'] = $trimmed_segments;
                } elseif ( isset( $message['content'] ) ) {
                    $content = (string) $message['content'];
                    $length  = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
                    $slice   = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
                    $message['content'] = $slice . ( $length > 200 ? '…' : '' );
                }

                $trimmed_messages[] = $message;
            }
            $payload[ $message_key ] = $trimmed_messages;
        }

        if ( isset( $payload['attachments'] ) && is_array( $payload['attachments'] ) ) {
            $scrubbed = array();

            foreach ( $payload['attachments'] as $attachment ) {
                if ( ! is_array( $attachment ) ) {
                    continue;
                }

                if ( isset( $attachment['data'] ) ) {
                    $attachment['data'] = '[redacted]';
                }

                $scrubbed[] = $attachment;
            }

            $payload['attachments'] = $scrubbed;
        }

        return $payload;
    }

    /**
     * Extract attachments from the options payload in a consistent array format.
     *
     * @param array $options Prepared request options.
     * @return array
     */
    protected function extract_attachments_from_options( array $options ) {
        if ( empty( $options['attachments'] ) ) {
            return array();
        }

        $attachments = $options['attachments'];

        if ( $attachments instanceof \Traversable ) {
            $attachments = iterator_to_array( $attachments );
        }

        if ( is_object( $attachments ) ) {
            $attachments = (array) $attachments;
        }

        if ( ! is_array( $attachments ) ) {
            return array();
        }

        $normalised = array();

        foreach ( $attachments as $attachment ) {
            if ( $attachment instanceof \Traversable ) {
                $attachment = iterator_to_array( $attachment );
            }

            if ( is_object( $attachment ) ) {
                $attachment = (array) $attachment;
            }

            if ( ! is_array( $attachment ) || empty( $attachment ) ) {
                continue;
            }

            $normalised[] = $attachment;
        }

        return array_values( $normalised );
    }

    /**
     * Determine whether the OpenAI Responses API should be used for the request.
     *
     * @param array $messages Sanitized chat messages.
     * @param array $options  Prepared request options.
     * @return bool
     */
    protected function should_use_responses_api( array $messages, array $options ) {
        if ( ! empty( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
            return true;
        }

        foreach ( $messages as $message ) {
            if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
                continue;
            }

            foreach ( $message['content'] as $segment ) {
                if ( ! is_array( $segment ) ) {
                    continue;
                }

                $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

                if ( isset( $segment['file_id'] ) ) {
                    return true;
                }

                if ( isset( $segment['image_file']['file_id'] ) ) {
                    return true;
                }

                if ( strpos( $type, 'input_' ) === 0 && ( isset( $segment['file_id'] ) || isset( $segment['image_file'] ) ) ) {
                    return true;
                }

                if ( 'input_file' === $type ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Prepare the payload used when calling the Responses API.
     *
     * @param array $original_messages Original chat messages.
     * @param array $normalised_messages Messages after normalisation.
     * @return array
     */
    protected function prepare_responses_input( array $original_messages, array $normalised_messages, array $attachments = array() ) {
        $prepared = array();
        $attachment_lookup = $this->index_attachments_by_id( $attachments );

        foreach ( $normalised_messages as $index => $message ) {
            $entry = $message;
            $role  = isset( $entry['role'] ) ? $entry['role'] : '';

            $original_content = isset( $original_messages[ $index ]['content'] ) ? $original_messages[ $index ]['content'] : null;

            if ( isset( $entry['content'] ) && ! is_array( $entry['content'] ) ) {
                $content_string = (string) $entry['content'];
                $entry['content'] = array(
                    array(
                        'type' => 'text',
                        'text' => $content_string,
                    ),
                );
            } elseif ( isset( $entry['content'] ) && is_array( $entry['content'] ) ) {
                $entry['content'] = array_values( $entry['content'] );
            } elseif ( is_array( $original_content ) ) {
                $entry['content'] = array_values( $original_content );
            } else {
                $entry['content'] = array();
            }

            if ( isset( $entry['content'] ) && is_array( $entry['content'] ) ) {
                $entry['content'] = $this->normalise_responses_content_segments( $entry['content'], $attachment_lookup, $role );
            }

            $prepared[] = $entry;
        }

        return $prepared;
    }

    /**
     * Normalise content segments for the Responses API payload.
     *
     * The REST layer stores text-only segments using the generic `text` type so
     * they remain compatible with the Chat Completions API. The Responses API
     * expects those entries to be labelled as `input_text`, so we convert them
     * here while preserving multimodal payloads (input_file, input_image, etc.).
     *
     * @param array  $segments    Content segments for a single message.
     * @param array  $attachments Attachment lookup keyed by file identifier.
     * @param string $role        Message role used to determine the text segment mode.
     * @return array
     */
    protected function normalise_responses_content_segments( array $segments, array $attachments = array(), $role = '' ) {
        $normalised   = array();
        $role_key     = sanitize_key( $role );
        $output_roles = array( 'assistant', 'tool', 'function' );

        foreach ( $segments as $segment ) {
            if ( $segment instanceof \Traversable ) {
                $segment = iterator_to_array( $segment );
            }

            if ( is_object( $segment ) ) {
                $segment = (array) $segment;
            }

            if ( ! is_array( $segment ) ) {
                $segment = array(
                    'type' => 'text',
                    'text' => is_scalar( $segment ) ? (string) $segment : '',
                );
            }

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

            if ( isset( $segment['display_name'] ) ) {
                unset( $segment['display_name'] );
            }

            $is_output_role = in_array( $role_key, $output_roles, true );

            if ( '' === $type || 'text' === $type || 'input_text' === $type || 'output_text' === $type ) {
                $segment['type'] = $is_output_role ? 'output_text' : 'input_text';

                if ( isset( $segment['content'] ) && ! isset( $segment['text'] ) ) {
                    $segment['text'] = is_scalar( $segment['content'] ) ? (string) $segment['content'] : '';
                    unset( $segment['content'] );
                }

                if ( ! isset( $segment['text'] ) ) {
                    $segment['text'] = '';
                }
            } elseif ( 'input_image' === $type ) {
                $segment = $this->populate_responses_image_segment( $segment, $attachments );
            } elseif ( 'input_file' === $type ) {
                $segment = $this->populate_responses_file_segment( $segment, $attachments );
            }

            if ( isset( $segment['mode'] ) ) {
                unset( $segment['mode'] );
            }

            $normalised[] = $segment;
        }

        return $normalised;
    }

    /**
     * Build a lookup of attachments keyed by their generated identifier.
     *
     * @param array $attachments Attachment payloads.
     * @return array
     */
    protected function index_attachments_by_id( array $attachments ) {
        $indexed = array();

        foreach ( $attachments as $attachment ) {
            if ( ! is_array( $attachment ) ) {
                continue;
            }

            $id = '';

            if ( isset( $attachment['id'] ) ) {
                $id = (string) $attachment['id'];
            } elseif ( isset( $attachment['file_id'] ) ) {
                $id = (string) $attachment['file_id'];
            }

            if ( '' === $id ) {
                continue;
            }

            $indexed[ $id ] = $attachment;
        }

        return $indexed;
    }

    /**
     * Hydrate an image segment with inline attachment data when available.
     *
     * @param array $segment     Segment definition.
     * @param array $attachments Attachment lookup keyed by file identifier.
     * @return array
     */
    protected function populate_responses_image_segment( array $segment, array $attachments ) {
        $file_id = '';

        if ( isset( $segment['image_file']['file_id'] ) ) {
            $file_id = (string) $segment['image_file']['file_id'];
        } elseif ( isset( $segment['file_id'] ) ) {
            $file_id = (string) $segment['file_id'];
        }

        if ( $file_id && isset( $attachments[ $file_id ] ) ) {
            $attachment = $attachments[ $file_id ];

            $openai_file_id = $file_id;

            if ( isset( $attachment['openai_file'] ) ) {
                if ( is_array( $attachment['openai_file'] ) && isset( $attachment['openai_file']['id'] ) ) {
                    $openai_file_id = (string) $attachment['openai_file']['id'];
                } elseif ( is_string( $attachment['openai_file'] ) && '' !== $attachment['openai_file'] ) {
                    $openai_file_id = (string) $attachment['openai_file'];
                }
            } elseif ( isset( $attachment['file_id'] ) && '' !== $attachment['file_id'] ) {
                $openai_file_id = (string) $attachment['file_id'];
            }

            if ( ! isset( $segment['image_file'] ) || ! is_array( $segment['image_file'] ) ) {
                $segment['image_file'] = array();
            }

            $segment['image_file']['file_id'] = $openai_file_id;
            $segment['file_id']              = $openai_file_id;

            if ( isset( $segment['image_url'] ) ) {
                unset( $segment['image_url'] );
            }

            if ( empty( $segment['caption'] ) && ! empty( $attachment['caption'] ) ) {
                $segment['caption'] = $attachment['caption'];
            }
        } elseif ( isset( $segment['image_url']['url'] ) ) {
            $segment['image_url'] = (string) $segment['image_url']['url'];
        }

        if ( empty( $segment['detail'] ) ) {
            $segment['detail'] = 'auto';
        }

        return $segment;
    }

    /**
     * Ensure a file segment references an uploaded OpenAI file identifier.
     *
     * @param array $segment     Segment definition.
     * @param array $attachments Attachment lookup keyed by file identifier.
     * @return array
     */
    protected function populate_responses_file_segment( array $segment, array $attachments ) {
        $file_id = isset( $segment['file_id'] ) ? (string) $segment['file_id'] : '';

        if ( '' === $file_id && isset( $segment['file']['id'] ) ) {
            $file_id = (string) $segment['file']['id'];
            $segment['file_id'] = $file_id;
        }

        if ( isset( $segment['file_data'] ) ) {
            unset( $segment['file_data'] );
        }

        if ( '' === $file_id ) {
            return $segment;
        }

        if ( isset( $attachments[ $file_id ] ) ) {
            $attachment = $attachments[ $file_id ];

            $openai_file_id = $file_id;

            if ( isset( $attachment['openai_file'] ) ) {
                if ( is_array( $attachment['openai_file'] ) && isset( $attachment['openai_file']['id'] ) ) {
                    $openai_file_id = (string) $attachment['openai_file']['id'];
                } elseif ( is_string( $attachment['openai_file'] ) ) {
                    $openai_file_id = (string) $attachment['openai_file'];
                }
            } elseif ( isset( $attachment['file_id'] ) ) {
                $openai_file_id = (string) $attachment['file_id'];
            }

            $segment['file_id'] = $openai_file_id;

            if ( empty( $segment['filename'] ) && ! empty( $attachment['filename'] ) ) {
                $segment['filename'] = $attachment['filename'];
            }
        }

        if ( isset( $segment['file'] ) ) {
            unset( $segment['file'] );
        }

        if ( isset( $segment['file_id'] ) && '' !== $segment['file_id'] && isset( $segment['filename'] ) ) {
            unset( $segment['filename'] );
        }

        return $segment;
    }

    /**
     * Convert a Responses API result into a shape that matches chat completions.
     *
     * @param array $response Raw Responses API payload.
     * @return array
     */
    protected function convert_responses_result_to_chat_completion( array $response ) {
        if ( isset( $response['response'] ) && is_array( $response['response'] ) ) {
            $nested_response = $this->convert_responses_result_to_chat_completion( $response['response'] );

            $response['response'] = $nested_response;

            if ( ! isset( $response['choices'] ) && isset( $nested_response['choices'] ) ) {
                $response['choices'] = $nested_response['choices'];
            }
        }

        if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
            $normalised = array();

            foreach ( $response['choices'] as $index => $choice ) {
                if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
                    if ( isset( $choice['message']['content'] ) && is_array( $choice['message']['content'] ) ) {
                        $choice['message']['content'] = $this->extract_text_from_response_content( $choice['message']['content'] );
                    }

                    if ( ! isset( $choice['index'] ) ) {
                        $choice['index'] = $index;
                    }

                    $normalised[] = $choice;
                    continue;
                }

                $content = isset( $choice['content'] ) ? $choice['content'] : array();
                $role    = isset( $choice['role'] ) ? sanitize_key( $choice['role'] ) : 'assistant';

                $normalised_choice = $choice;
                $normalised_choice['message'] = array(
                    'role'    => $role,
                    'content' => $this->extract_text_from_response_content( $content ),
                );

                if ( isset( $normalised_choice['role'] ) ) {
                    unset( $normalised_choice['role'] );
                }

                if ( isset( $normalised_choice['content'] ) ) {
                    unset( $normalised_choice['content'] );
                }

                if ( ! isset( $normalised_choice['index'] ) ) {
                    $normalised_choice['index'] = $index;
                }

                $normalised[] = $normalised_choice;
            }

            $response['choices'] = $normalised;

            return $response;
        }

        $choices = array();

        if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
            foreach ( $response['output'] as $index => $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }

                $content_payload = array();

                if ( isset( $item['content'] ) ) {
                    $content_payload = $item['content'];
                } elseif ( isset( $item['text'] ) ) {
                    $content_payload = $item['text'];
                }

                $choices[] = array(
                    'index'         => $index,
                    'message'       => array(
                        'role'    => isset( $item['role'] ) ? sanitize_key( $item['role'] ) : 'assistant',
                        'content' => $this->extract_text_from_response_content( $content_payload ),
                    ),
                    'finish_reason' => isset( $item['finish_reason'] ) ? $item['finish_reason'] : 'stop',
                );
            }
        }

        if ( empty( $choices ) && isset( $response['output_text'] ) ) {
            $output_text = $response['output_text'];

            if ( is_array( $output_text ) ) {
                $output_text = array_filter(
                    array_map( static function ( $item ) {
                        if ( is_string( $item ) || is_numeric( $item ) ) {
                            return (string) $item;
                        }

                        return '';
                    }, $output_text ),
                    static function ( $part ) {
                        return '' !== trim( $part );
                    }
                );

                $output_text = implode( "\n\n", $output_text );
            }

            $output_text = is_string( $output_text ) || is_numeric( $output_text ) ? (string) $output_text : '';

            if ( '' !== $output_text ) {
                $choices[] = array(
                    'index'         => 0,
                    'message'       => array(
                        'role'    => 'assistant',
                        'content' => $output_text,
                    ),
                    'finish_reason' => 'stop',
                );
            }
        }

        if ( empty( $choices ) ) {
            return $response;
        }

        $response['choices'] = $choices;

        return $response;
    }

    /**
     * Extract a plain text representation from a Responses API content payload.
     *
     * @param mixed $content Content payload from the Responses API.
     * @return string
     */
    protected function extract_text_from_response_content( $content ) {
        if ( is_string( $content ) ) {
            return $content;
        }

        if ( ! is_array( $content ) ) {
            return '';
        }

        $text_segments = array();

        foreach ( $content as $segment ) {
            if ( is_string( $segment ) ) {
                $text_segments[] = $segment;
                continue;
            }

            if ( ! is_array( $segment ) ) {
                continue;
            }

            $type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

            if ( isset( $segment['text'] ) && in_array( $type, array( 'output_text', 'text', 'input_text' ), true ) ) {
                $text_segments[] = $this->normalise_responses_text_value( $segment['text'] );
                continue;
            }

            if ( isset( $segment['content'] ) && is_string( $segment['content'] ) ) {
                $text_segments[] = $segment['content'];
            }
        }

        $text_segments = array_filter( $text_segments, static function ( $part ) {
            return '' !== trim( $part );
        } );

        return implode( "\n\n", $text_segments );
    }

    /**
     * Normalise a Responses API text value into a scalar string.
     *
     * @param mixed $value Raw text payload from the Responses API.
     * @return string
     */
    protected function normalise_responses_text_value( $value ) {
        if ( is_string( $value ) || is_numeric( $value ) ) {
            return (string) $value;
        }

        if ( is_array( $value ) ) {
            if ( isset( $value['value'] ) && is_string( $value['value'] ) ) {
                return $value['value'];
            }

            if ( isset( $value['text'] ) && is_string( $value['text'] ) ) {
                return $value['text'];
            }

            $scalars = array();

            foreach ( $value as $key => $item ) {
                if ( is_string( $key ) && in_array( $key, array( 'type', 'annotations', 'mode', 'status', 'finish_reason', 'id' ), true ) ) {
                    continue;
                }

                $normalised_item = $this->normalise_responses_text_value( $item );

                if ( '' !== $normalised_item ) {
                    $scalars[] = $normalised_item;
                }
            }

            if ( ! empty( $scalars ) ) {
                return implode( "\n\n", $scalars );
            }
        }

        return '';
    }
}
