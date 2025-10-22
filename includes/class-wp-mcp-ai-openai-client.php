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
    const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

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
     * Perform a chat completion request.
     *
     * @param array $messages Message payload to send to OpenAI.
     * @param array $options  Additional options (model, temperature, tools, timeout).
     * @return array|WP_Error
     */
    public function create_chat_completion( array $messages, array $options = array() ) {
        $api_key = $this->get_api_key();

        if ( empty( $api_key ) ) {
            return new WP_Error( 'wp_mcp_ai_missing_api_key', __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ) );
        }

        $settings   = WP_MCP_AI_Admin_Settings::get_settings();
        $model      = ! empty( $options['model'] ) ? $options['model'] : $settings['default_model'];
        $timeout    = ! empty( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
        $timeout    = max( 5, $timeout );
        $payload    = array(
            'model'    => $model,
            'messages' => array_values( $messages ),
        );

        if ( empty( $payload['messages'] ) ) {
            return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No chat messages were provided for the request.', 'wp-mcp-ai' ) );
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
                        'type' => 'input_text',
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
            $payload['messages'] = array_merge( $system_messages, $payload['messages'] );
        }

        if ( ! empty( $options['tools'] ) ) {
            $payload['tools'] = array_values( $options['tools'] );
        }

        if ( ! empty( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
            $payload['attachments'] = array_values( $options['attachments'] );
        }

        if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
            $payload['response_format'] = $options['response_format'];
        }

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => $timeout,
        );

        WP_MCP_AI_Logger::log_event( 'openai_request', 'Sending request to OpenAI.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

        $response = wp_remote_post( self::API_ENDPOINT, $request_args );

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

        WP_MCP_AI_Logger::log_event( 'openai_response', 'OpenAI request completed.', array( 'response' => $decoded ) );

        return $decoded;
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
                            'type' => 'input_text',
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
     * Remove large message payloads when logging requests.
     *
     * @param array $payload The payload that will be logged.
     * @return array
     */
    protected function obfuscate_request_for_log( array $payload ) {
        if ( isset( $payload['messages'] ) ) {
            $trimmed_messages = array();
            foreach ( $payload['messages'] as $message ) {
                if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
                    $trimmed_segments = array();

                    foreach ( $message['content'] as $segment ) {
                        if ( ! is_array( $segment ) ) {
                            continue;
                        }

                        $segment_copy = $segment;
                        $type         = isset( $segment['type'] ) ? $segment['type'] : '';

                        if ( 'input_text' === $type && isset( $segment['text'] ) ) {
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
            $payload['messages'] = $trimmed_messages;
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
}
