<?php
/**
 * Simple logging utility for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper for writing structured log entries.
 */
class WP_MCP_AI_Logger {
    /**
     * Prefix that is added to every log line for easier filtering.
     */
    const PREFIX = '[WP MCP AI]';

    /**
     * Record a generic log event when logging is enabled.
     *
     * @param string $type    Event type (chat_request, tool_result, error, etc.).
     * @param string $message Human readable description of the event.
     * @param array  $context Additional context for the entry.
     */
    public static function log_event( $type, $message, $context = array() ) {
        if ( ! WP_MCP_AI_Admin_Settings::is_logging_enabled() ) {
            return;
        }

        $type         = sanitize_key( $type );
        $message      = (string) $message;
        $raw_context  = is_array( $context ) ? $context : array();
        $context      = self::sanitize_context( $raw_context );

        $entry = array(
            'timestamp' => current_time( 'mysql', true ),
            'type'      => $type,
            'message'   => $message,
            'context'   => $context,
        );

        /**
         * Allow third parties to filter the final log entry.
         *
         * Returning `false` from this filter stops the entry from being logged.
         *
         * @param array  $entry   Prepared log entry.
         * @param string $type    Event type.
         * @param string $message Log message.
         * @param array  $context Raw context array prior to sanitization.
         */
        $entry = apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $raw_context );
        if ( false === $entry ) {
            return;
        }

        $line = sprintf( '%s %s: %s', self::PREFIX, strtoupper( $entry['type'] ), $entry['message'] );
        if ( ! empty( $entry['context'] ) ) {
            $line .= ' ' . wp_json_encode( $entry['context'] );
        }

        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    /**
     * Convenience wrapper for logging errors.
     *
     * @param string $message Error message.
     * @param array  $context Optional context.
     */
    public static function log_error( $message, $context = array() ) {
        self::log_event( 'error', $message, $context );
    }

    /**
     * Log a chat request/response interaction.
     *
     * @param int   $assistant_id Assistant identifier.
     * @param array $messages     Sanitized message payload.
     * @param array $options      Request options.
     * @param array $response     Response payload (if any).
     * @param int   $user_id      Acting user ID.
     */
    public static function log_chat_interaction( $assistant_id, $messages, $options, $response, $user_id ) {
        self::log_event(
            'chat_interaction',
            'Chat request executed.',
            array(
                'assistant_id' => absint( $assistant_id ),
                'user_id'      => absint( $user_id ),
                'messages'     => self::limit_message_payload( $messages ),
                'options'      => $options,
                'response'     => $response,
            )
        );
    }

    /**
     * Log the result of a tool execution.
     *
     * @param string $tool_slug Tool slug.
     * @param array  $arguments Arguments passed to the tool.
     * @param mixed  $result    Tool result data (or WP_Error).
     * @param array  $context   Tool execution context.
     */
    public static function log_tool_execution( $tool_slug, $arguments, $result, $context = array() ) {
        $context = self::sanitize_context( $context );
        $context['tool_slug'] = sanitize_key( $tool_slug );
        $context['arguments'] = $arguments;

        if ( is_wp_error( $result ) ) {
            $context['error_code']    = $result->get_error_code();
            $context['error_message'] = $result->get_error_message();
            self::log_event( 'tool_error', 'Tool execution failed.', $context );
            return;
        }

        $context['result_preview'] = self::limit_result_payload( $result );
        self::log_event( 'tool_execution', 'Tool executed successfully.', $context );
    }

    /**
     * Remove potentially sensitive information from the context payload.
     *
     * @param array $context Raw context data.
     * @return array
     */
    protected static function sanitize_context( $context ) {
        if ( ! is_array( $context ) ) {
            return array();
        }

        unset( $context['openai_api_key'] );

        return $context;
    }

    /**
     * Truncate large message bodies before logging.
     *
     * @param array $messages Chat messages.
     * @return array
     */
    protected static function limit_message_payload( $messages ) {
        if ( ! is_array( $messages ) ) {
            return array();
        }

        $limited = array();
        foreach ( $messages as $message ) {
            if ( ! is_array( $message ) ) {
                continue;
            }

            $content = isset( $message['content'] ) ? (string) $message['content'] : '';
            $length  = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
            $slice   = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 120 ) : substr( $content, 0, 120 );

            $message['content'] = $slice . ( $length > 120 ? '…' : '' );
            $limited[]          = $message;
        }

        return $limited;
    }

    /**
     * Reduce result payload size prior to logging.
     *
     * @param mixed $result Raw tool result.
     * @return mixed
     */
    protected static function limit_result_payload( $result ) {
        if ( is_array( $result ) || is_object( $result ) ) {
            $encoded = wp_json_encode( $result );
            if ( false !== $encoded && strlen( $encoded ) > 400 ) {
                return substr( $encoded, 0, 400 ) . '…';
            }
        }

        return $result;
    }
}
