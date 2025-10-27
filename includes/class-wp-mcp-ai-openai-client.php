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
            $purpose = 'responses';
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

        if ( function_exists( 'curl_file_create' ) ) {
            $file_field = curl_file_create( $file_path, $mime_type, $filename );
        } elseif ( class_exists( 'CURLFile' ) ) {
            $file_field = new CURLFile( $file_path, $mime_type, $filename );
        } else {
            $file_field = '@' . $file_path; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
        }

        $request_headers = array(
            'Authorization' => 'Bearer ' . $api_key,
        );

        $request_body = array(
            'purpose' => $purpose,
            'file'    => $file_field,
        );

        WP_MCP_AI_Logger::log_event(
            'openai_file_upload',
            'Uploading file to OpenAI.',
            array(
                'purpose'  => $purpose,
                'filename' => $filename,
            )
        );

        $response = wp_remote_post(
            self::FILES_ENDPOINT,
            array(
                'headers' => $request_headers,
                'body'    => $request_body,
                'timeout' => $timeout,
            )
        );

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
     * @param string $file_id File identifier returned by OpenAI.
     * @param array  $args    Optional arguments (timeout).
     * @return array|WP_Error
     */
    public function delete_file( $file_id, array $args = array() ) {
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

        $file_id = sanitize_text_field( $file_id );

        if ( '' === $file_id ) {
            return new WP_Error(
                'wp_mcp_ai_file_delete_missing_id',
                __( 'An OpenAI file identifier must be provided.', 'wp-mcp-ai' ),
                array( 'status' => 400 )
            );
        }

        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        $timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout  = max( 5, $timeout );

        $request_headers = array(
            'Authorization' => 'Bearer ' . $api_key,
        );

        $request_url = self::FILES_ENDPOINT . '/' . rawurlencode( $file_id );

        WP_MCP_AI_Logger::log_event(
            'openai_file_delete',
            'Deleting file from OpenAI.',
            array( 'file_id' => $file_id )
        );

        $response = wp_remote_request(
            $request_url,
            array(
                'method'  => 'DELETE',
                'headers' => $request_headers,
                'timeout' => $timeout,
            )
        );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI file delete failed.', array( 'error' => $response->get_error_message() ) );

            return new WP_Error(
                'wp_mcp_ai_file_delete_http_error',
                __( 'The OpenAI file delete request failed to complete.', 'wp-mcp-ai' ),
                array( 'error' => $response )
            );
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body     = wp_remote_retrieve_body( $response );
        $decoded  = json_decode( $body, true );
        $json_err = json_last_error();

        if ( JSON_ERROR_NONE !== $json_err ) {
            WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI file delete response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_file_delete_invalid_response', __( 'OpenAI returned malformed JSON for the file delete request.', 'wp-mcp-ai' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            WP_MCP_AI_Logger::log_error( 'OpenAI file delete returned an error.', array( 'code' => $code, 'body' => $decoded ) );

            $message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file delete request failed.', 'wp-mcp-ai' );

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
            'OpenAI file delete completed.',
            array( 'file_id' => $file_id )
        );

        return is_array( $decoded ) ? $decoded : array();
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

                        if ( 'input_file' === $type && isset( $segment['file_id'] ) ) {
                            $segment_copy = array(
                                'type'    => 'input_file',
                                'file_id' => $segment['file_id'],
                            );

                            if ( isset( $segment['display_name'] ) ) {
                                $segment_copy['display_name'] = $segment['display_name'];
                            }

                            if ( isset( $segment['filename'] ) ) {
                                $segment_copy['filename'] = $segment['filename'];
                            }
                        } elseif ( 'input_file' === $type && isset( $segment['file_data'] ) ) {
                            $segment_copy = array(
                                'type' => 'input_file',
                            );

                            if ( isset( $segment['display_name'] ) ) {
                                $segment_copy['display_name'] = $segment['display_name'];
                            }

                            if ( isset( $segment['filename'] ) ) {
                                $segment_copy['filename'] = $segment['filename'];
                            }

                            if ( is_array( $segment['file_data'] ) ) {
                                $segment_copy['file_data'] = '[redacted]';
                            } elseif ( is_string( $segment['file_data'] ) ) {
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
            $data       = isset( $attachment['data'] ) ? (string) $attachment['data'] : '';
            $mime_type  = isset( $attachment['mime_type'] ) && '' !== $attachment['mime_type'] ? (string) $attachment['mime_type'] : 'application/octet-stream';

            if ( '' !== $data ) {
                $segment['image_url'] = 'data:' . $mime_type . ';base64,' . $data;
                unset( $segment['image_file'] );
                unset( $segment['file_id'] );
            } else {
                if ( ! isset( $segment['image_file'] ) || ! is_array( $segment['image_file'] ) ) {
                    $segment['image_file'] = array();
                }

                $segment['image_file']['file_id'] = $file_id;
                unset( $segment['file_id'] );
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

            if ( isset( $attachment['openai_file'] ) && is_array( $attachment['openai_file'] ) ) {
                $segment['file'] = $attachment['openai_file'];
            }

            if ( empty( $segment['filename'] ) && ! empty( $attachment['filename'] ) ) {
                $segment['filename'] = $attachment['filename'];
            }
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
            $choices[] = array(
                'index'         => 0,
                'message'       => array(
                    'role'    => 'assistant',
                    'content' => (string) $response['output_text'],
                ),
                'finish_reason' => 'stop',
            );
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

            foreach ( $value as $item ) {
                if ( is_string( $item ) || is_numeric( $item ) ) {
                    $scalars[] = (string) $item;
                }
            }

            if ( ! empty( $scalars ) ) {
                return implode( ' ', $scalars );
            }
        }

        return '';
    }
}
