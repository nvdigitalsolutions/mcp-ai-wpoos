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
            $payload['temperature'] = floatval( $options['temperature'] );
        }

        if ( ! empty( $options['system_prompt'] ) ) {
            array_unshift(
                $payload['messages'],
                array(
                    'role'    => 'system',
                    'content' => (string) $options['system_prompt'],
                )
            );
        }

        if ( ! empty( $options['tools'] ) ) {
            $payload['tools'] = array_values( $options['tools'] );
        }

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => $timeout,
        );

        WP_MCP_AI_Admin_Settings::log( 'Sending request to OpenAI.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

        $response = wp_remote_post( self::API_ENDPOINT, $request_args );

        if ( is_wp_error( $response ) ) {
            WP_MCP_AI_Admin_Settings::log( 'OpenAI request failed.', array( 'error' => $response->get_error_message() ) );

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
            WP_MCP_AI_Admin_Settings::log( 'Failed to decode OpenAI response.', array( 'body' => $body ) );

            return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The OpenAI API returned malformed JSON.', 'wp-mcp-ai' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            WP_MCP_AI_Admin_Settings::log( 'OpenAI returned an error response.', array( 'code' => $code, 'body' => $decoded ) );

            $message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );

            return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $code, 'body' => $decoded ) );
        }

        WP_MCP_AI_Admin_Settings::log( 'OpenAI request completed.', array( 'response' => $decoded ) );

        return $decoded;
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
                if ( isset( $message['content'] ) ) {
                    $content = (string) $message['content'];
                    $length  = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
                    $slice   = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
                    $message['content'] = $slice . ( $length > 200 ? '…' : '' );
                }
                $trimmed_messages[] = $message;
            }
            $payload['messages'] = $trimmed_messages;
        }

        return $payload;
    }
}
