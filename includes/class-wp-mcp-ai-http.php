<?php
/**
 * Helper utilities for working with WordPress HTTP responses.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides helpers for normalising WordPress HTTP transport errors.
 */
class WP_MCP_AI_HTTP {

    /**
     * Prepare a transport error, promoting WordPress timeout failures to actionable guidance.
     *
     * @param WP_Error $transport_error Raw transport error returned by WordPress.
     * @param string   $default_code    Error code to use when the error is not a timeout.
     * @param string   $default_message Fallback error message.
     * @param string   $service_label   Optional human readable service name.
     * @param array    $data            Optional error data to merge.
     *
     * @return WP_Error
     */
    public static function prepare_transport_error(
        $transport_error,
        $default_code,
        $default_message,
        $service_label = '',
        array $data = array()
    ) {
        if ( ! $transport_error instanceof WP_Error ) {
            return new WP_Error( $default_code, $default_message, $data );
        }

        $data          = is_array( $data ) ? $data : array();
        $data['error'] = $transport_error;

        if ( self::is_wordpress_timeout_error( $transport_error ) ) {
            $message = self::build_timeout_message( $service_label );

            $actions = array(
                'configure_request_timeout' => __( 'Increase the request timeout under Settings → MCP AI.', 'wp-mcp-ai' ),
                'check_server_connectivity' => __( 'Confirm your server can reach the remote service without firewall or network blocks.', 'wp-mcp-ai' ),
            );

            if ( isset( $data['actions'] ) && is_array( $data['actions'] ) ) {
                $data['actions'] = $actions + $data['actions'];
            } else {
                $data['actions'] = $actions;
            }

            if ( ! isset( $data['status'] ) ) {
                $data['status'] = 504;
            }

            return new WP_Error( 'wp_mcp_ai_wordpress_timeout', $message, $data );
        }

        return new WP_Error( $default_code, $default_message, $data );
    }

    /**
     * Determine whether the supplied error represents a WordPress transport timeout.
     *
     * @param WP_Error $error Error object returned by the HTTP API.
     *
     * @return bool
     */
    public static function is_wordpress_timeout_error( $error ) {
        if ( ! $error instanceof WP_Error ) {
            return false;
        }

        foreach ( $error->get_error_codes() as $code ) {
            if ( 'http_request_timeout' === $code ) {
                return true;
            }

            $messages = $error->get_error_messages( $code );
            foreach ( $messages as $message ) {
                if ( self::message_indicates_timeout( $message ) ) {
                    return true;
                }
            }
        }

        foreach ( $error->get_error_messages() as $message ) {
            if ( self::message_indicates_timeout( $message ) ) {
                return true;
            }
        }

        $data = $error->get_error_data();
        if ( is_array( $data ) ) {
            if ( isset( $data['timeout'] ) && $data['timeout'] ) {
                return true;
            }

            if ( isset( $data['status'] ) && 504 === (int) $data['status'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build a timeout error message tailored to the service label.
     *
     * @param string $service_label Optional human readable service name.
     *
     * @return string
     */
    protected static function build_timeout_message( $service_label ) {
        $service_label = is_string( $service_label ) ? trim( wp_strip_all_tags( $service_label ) ) : '';

        if ( '' !== $service_label ) {
            /* translators: %s: Human readable remote service label. */
            return sprintf( __( 'WordPress timed out waiting for a response from %s.', 'wp-mcp-ai' ), $service_label );
        }

        return __( 'WordPress timed out waiting for a response.', 'wp-mcp-ai' );
    }

    /**
     * Detect whether the supplied error message indicates a timeout condition.
     *
     * @param string $message Error message from WordPress.
     *
     * @return bool
     */
    protected static function message_indicates_timeout( $message ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return false;
        }

        $normalised = strtolower( $message );

        $needles = array(
            'timed out',
            'timeout',
            'time-out',
            'operation timed out',
            'request timed out',
            'curl error 28',
        );

        foreach ( $needles as $needle ) {
            if ( false !== strpos( $normalised, $needle ) ) {
                return true;
            }
        }

        return false;
    }
}

