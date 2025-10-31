<?php
/**
 * Plugin Name: WP MCP AI OAuth Introspection
 * Description: Adds OAuth 2.0 bearer token introspection for WP MCP AI REST authentication.
 * Author: MCP AI
 * Version: 1.0.0
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_MCP_AI_OAuth_Introspection' ) ) {

    /**
     * OAuth 2.0 token introspection bridge for WP MCP AI.
     */
    final class WP_MCP_AI_OAuth_Introspection {

        /**
         * Singleton instance of the plugin loader.
         *
         * @var WP_MCP_AI_OAuth_Introspection|null
         */
        private static $instance = null;

        /**
         * Stored token payload from the most recent successful introspection.
         *
         * @var array|null
         */
        private $last_payload = null;

        /**
         * Bootstraps hooks.
         */
        private function __construct() {
            add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $this, 'pre_validate_bearer_token' ), 10, 3 );
            add_filter( 'wp_mcp_ai_map_bearer_to_user_id', array( $this, 'map_bearer_to_user_id' ), 10, 3 );
            add_action( 'admin_init', array( $this, 'register_settings' ) );
        }

        /**
         * Boots the plugin and ensures only a single instance is created.
         *
         * @return WP_MCP_AI_OAuth_Introspection
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Registers the option used to persist OAuth configuration.
         *
         * This mirrors the pattern used by other integrations in the plugin so the settings can be
         * surfaced or managed by wp-cli and other tooling.
         *
         * @return void
         */
        public function register_settings() {
            register_setting(
                'wp_mcp_ai_oauth',
                'wp_mcp_ai_oauth_settings',
                array(
                    'type'              => 'array',
                    'sanitize_callback' => array( $this, 'sanitize_settings' ),
                    'default'           => array(),
                )
            );
        }

        /**
         * Sanitises the saved settings payload.
         *
         * @param mixed $settings Raw settings payload.
         * @return array
         */
        public function sanitize_settings( $settings ) {
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings = wp_parse_args(
                $settings,
                array(
                    'introspection_endpoint' => '',
                    'client_id'              => '',
                    'client_secret'          => '',
                    'token_type_hint'        => 'access_token',
                    'expected_audience'      => '',
                    'required_scope'         => '',
                    'user_mapping'           => array(),
                )
            );

            $settings['introspection_endpoint'] = esc_url_raw( $settings['introspection_endpoint'] );
            $settings['client_id']              = sanitize_text_field( $settings['client_id'] );
            $settings['client_secret']          = sanitize_text_field( $settings['client_secret'] );
            $settings['token_type_hint']        = sanitize_text_field( $settings['token_type_hint'] );
            $settings['expected_audience']      = sanitize_text_field( $settings['expected_audience'] );
            $settings['required_scope']         = sanitize_text_field( $settings['required_scope'] );

            if ( ! is_array( $settings['user_mapping'] ) ) {
                $settings['user_mapping'] = array();
            }

            $settings['user_mapping'] = wp_parse_args(
                $settings['user_mapping'],
                array(
                    'claim'        => 'sub',
                    'default_user' => 0,
                    'map'          => array(),
                )
            );

            $settings['user_mapping']['claim']        = sanitize_key( $settings['user_mapping']['claim'] );
            $settings['user_mapping']['default_user'] = absint( $settings['user_mapping']['default_user'] );

            if ( ! is_array( $settings['user_mapping']['map'] ) ) {
                $settings['user_mapping']['map'] = array();
            }

            $sanitised_map = array();
            foreach ( $settings['user_mapping']['map'] as $claim_value => $user_id ) {
                $claim_value = sanitize_text_field( (string) $claim_value );
                $user_id     = absint( $user_id );

                if ( $claim_value && $user_id > 0 ) {
                    $sanitised_map[ $claim_value ] = $user_id;
                }
            }

            $settings['user_mapping']['map'] = $sanitised_map;

            return $settings;
        }

        /**
         * Filter callback that validates a bearer token via OAuth introspection.
         *
         * @param null|bool|WP_Error $pre     Current pre-validation result.
         * @param string             $token   Raw bearer token string extracted by the REST controller.
         * @param WP_REST_Request    $request Current request.
         * @return true|WP_Error|null
         */
        public function pre_validate_bearer_token( $pre, $token, $request ) {
            if ( null !== $pre ) {
                return $pre;
            }

            $header = '';
            if ( $request instanceof WP_REST_Request ) {
                $header = (string) $request->get_header( 'Authorization' );
            }

            if ( ! $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                $header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            }

            $parsed_token = $this->parse_bearer_header( $header );
            if ( is_wp_error( $parsed_token ) ) {
                return $parsed_token;
            }

            if ( empty( $token ) ) {
                $token = $parsed_token;
            }

            if ( $token !== $parsed_token ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_mismatched_token',
                    __( 'The Authorization header does not match the extracted bearer token.', 'wp-mcp-ai' ),
                    array( 'status' => 401 )
                );
            }

            $settings = $this->get_settings();
            if ( empty( $settings['introspection_endpoint'] ) ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_missing_endpoint',
                    __( 'OAuth introspection endpoint is not configured.', 'wp-mcp-ai' ),
                    array( 'status' => 500 )
                );
            }

            $introspection = $this->perform_introspection_request( $token, $settings );
            if ( is_wp_error( $introspection ) ) {
                return $introspection;
            }

            if ( ! empty( $settings['expected_audience'] ) && isset( $introspection['aud'] ) ) {
                $audience = (array) $introspection['aud'];
                if ( ! in_array( $settings['expected_audience'], $audience, true ) ) {
                    return new WP_Error(
                        'wp_mcp_ai_oauth_unexpected_audience',
                        __( 'The bearer token audience is not accepted.', 'wp-mcp-ai' ),
                        array( 'status' => 403 )
                    );
                }
            }

            if ( ! empty( $settings['required_scope'] ) ) {
                if ( empty( $introspection['scope'] ) ) {
                    return new WP_Error(
                        'wp_mcp_ai_oauth_missing_scope',
                        __( 'The bearer token is missing a required scope.', 'wp-mcp-ai' ),
                        array( 'status' => 403 )
                    );
                }

                $scope_field = $introspection['scope'];
                if ( is_string( $scope_field ) ) {
                    $scopes = preg_split( '/\s+/', $scope_field, -1, PREG_SPLIT_NO_EMPTY );
                    if ( false === $scopes ) {
                        $scopes = array();
                    }
                } elseif ( is_array( $scope_field ) ) {
                    $scopes = array_map( 'strval', $scope_field );
                } else {
                    $scopes = array( (string) $scope_field );
                }

                if ( ! in_array( $settings['required_scope'], $scopes, true ) ) {
                    return new WP_Error(
                        'wp_mcp_ai_oauth_missing_scope',
                        __( 'The bearer token is missing a required scope.', 'wp-mcp-ai' ),
                        array( 'status' => 403 )
                    );
                }
            }

            $this->last_payload = $introspection;

            /**
             * Fires when a bearer token has been validated by the OAuth introspection plugin.
             *
             * @param array            $introspection Introspection payload returned by the provider.
             * @param WP_REST_Request  $request       Current REST request.
             */
            do_action( 'wp_mcp_ai_authenticated_with_oauth_token', $introspection, $request );

            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                $subject = isset( $introspection['sub'] ) ? sanitize_text_field( (string) $introspection['sub'] ) : 'unknown';
                error_log( sprintf( 'WP MCP AI OAuth: validated token for subject "%s"', $subject ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            return true;
        }

        /**
         * Maps a validated bearer token to a WordPress user identifier.
         *
         * @param int|null         $user_id Previously mapped user ID.
         * @param array|null       $payload Token payload supplied by the caller when available.
         * @param WP_REST_Request  $request Current REST request.
         * @return int|WP_Error|null
         */
        public function map_bearer_to_user_id( $user_id, $payload, $request ) {
            if ( $user_id instanceof WP_Error ) {
                return $user_id;
            }

            if ( null !== $user_id ) {
                return $user_id;
            }

            if ( ! is_array( $payload ) ) {
                $payload = $this->last_payload;
            }

            if ( ! is_array( $payload ) ) {
                return $user_id;
            }

            $settings = $this->get_settings();
            $claim    = $settings['user_mapping']['claim'];

            if ( empty( $claim ) || empty( $payload[ $claim ] ) ) {
                if ( $settings['user_mapping']['default_user'] > 0 ) {
                    return $settings['user_mapping']['default_user'];
                }

                return new WP_Error(
                    'wp_mcp_ai_oauth_missing_claim',
                    sprintf(
                        /* translators: %s is the claim name that was expected in the OAuth token. */
                        __( 'The OAuth token did not include the required "%s" claim to map a user.', 'wp-mcp-ai' ),
                        esc_html( $claim )
                    ),
                    array( 'status' => 403 )
                );
            }

            $claim_value = (string) $payload[ $claim ];

            if ( isset( $settings['user_mapping']['map'][ $claim_value ] ) ) {
                $mapped_user = absint( $settings['user_mapping']['map'][ $claim_value ] );
                if ( $mapped_user > 0 ) {
                    $this->log_successful_mapping( $mapped_user, $payload, $request );
                    return $mapped_user;
                }
            }

            $maybe_user = null;
            switch ( $claim ) {
                case 'email':
                    $maybe_user = get_user_by( 'email', $claim_value );
                    break;
                case 'preferred_username':
                case 'username':
                case 'login':
                    $maybe_user = get_user_by( 'login', $claim_value );
                    break;
                case 'id':
                case 'sub':
                    if ( is_numeric( $claim_value ) ) {
                        $maybe_user = get_user_by( 'id', absint( $claim_value ) );
                    }
                    break;
            }

            if ( $maybe_user && ! is_wp_error( $maybe_user ) ) {
                $this->log_successful_mapping( $maybe_user->ID, $payload, $request );
                return (int) $maybe_user->ID;
            }

            if ( $settings['user_mapping']['default_user'] > 0 ) {
                $this->log_successful_mapping( $settings['user_mapping']['default_user'], $payload, $request );
                return $settings['user_mapping']['default_user'];
            }

            return new WP_Error(
                'wp_mcp_ai_oauth_unmapped_user',
                __( 'The OAuth token could not be mapped to a WordPress user.', 'wp-mcp-ai' ),
                array( 'status' => 403 )
            );
        }

        /**
         * Logs successful user mapping and fires an auditing action.
         *
         * @param int              $user_id  Authenticated user identifier.
         * @param array            $payload  OAuth token payload.
         * @param WP_REST_Request  $request  Current request.
         * @return void
         */
        private function log_successful_mapping( $user_id, $payload, $request ) {
            /**
             * Fires when an OAuth token has been mapped to a WordPress user.
             *
             * @param int              $user_id  Authenticated user identifier.
             * @param array            $payload  OAuth token payload.
             * @param WP_REST_Request  $request  Current request.
             */
            do_action( 'wp_mcp_ai_authenticated_with_credential', array(
                'source'        => 'oauth',
                'user_id'       => absint( $user_id ),
                'token_subject' => isset( $payload['sub'] ) ? sanitize_text_field( (string) $payload['sub'] ) : '',
            ), $request );

            if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
                $subject = isset( $payload['sub'] ) ? sanitize_text_field( (string) $payload['sub'] ) : 'unknown';
                error_log( sprintf( 'WP MCP AI OAuth: mapped subject "%s" to user #%d', $subject, absint( $user_id ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        /**
         * Returns the persisted OAuth settings with defaults.
         *
         * @return array
         */
        private function get_settings() {
            $defaults = array(
                'introspection_endpoint' => '',
                'client_id'              => '',
                'client_secret'          => '',
                'token_type_hint'        => 'access_token',
                'expected_audience'      => '',
                'required_scope'         => '',
                'user_mapping'           => array(
                    'claim'        => 'sub',
                    'default_user' => 0,
                    'map'          => array(),
                ),
            );

            $settings = get_option( 'wp_mcp_ai_oauth_settings', array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            $settings = wp_parse_args( $settings, $defaults );
            if ( ! is_array( $settings['user_mapping'] ) ) {
                $settings['user_mapping'] = $defaults['user_mapping'];
            } else {
                $settings['user_mapping'] = wp_parse_args( $settings['user_mapping'], $defaults['user_mapping'] );
            }

            return $settings;
        }

        /**
         * Parses an Authorization header into a bearer token string.
         *
         * @param string $header Authorization header value.
         * @return string|WP_Error
         */
        private function parse_bearer_header( $header ) {
            if ( empty( $header ) ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_missing_header',
                    __( 'Authorization header is missing.', 'wp-mcp-ai' ),
                    array( 'status' => 401 )
                );
            }

            if ( preg_match( '/^Bearer\s+(?P<token>.+)$/i', trim( $header ), $matches ) ) {
                return trim( $matches['token'] );
            }

            return new WP_Error(
                'wp_mcp_ai_oauth_invalid_header',
                __( 'Authorization header is not a bearer token.', 'wp-mcp-ai' ),
                array( 'status' => 401 )
            );
        }

        /**
         * Performs an OAuth introspection request.
         *
         * @param string $token    Bearer token string.
         * @param array  $settings OAuth configuration settings.
         * @return array|WP_Error
         */
        private function perform_introspection_request( $token, $settings ) {
            $body = array( 'token' => $token );
            if ( ! empty( $settings['token_type_hint'] ) ) {
                $body['token_type_hint'] = $settings['token_type_hint'];
            }

            $headers = array( 'Accept' => 'application/json' );
            if ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) {
                $headers['Authorization'] = 'Basic ' . base64_encode( rawurlencode( $settings['client_id'] ) . ':' . rawurlencode( $settings['client_secret'] ) );
            }

            $response = wp_remote_post(
                $settings['introspection_endpoint'],
                array(
                    'headers' => $headers,
                    'body'    => $body,
                    'timeout' => 15,
                )
            );

            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_http_error',
                    __( 'Unable to reach the OAuth introspection endpoint.', 'wp-mcp-ai' ),
                    array(
                        'status' => 502,
                        'error'  => $response->get_error_message(),
                    )
                );
            }

            $status = wp_remote_retrieve_response_code( $response );
            $body   = wp_remote_retrieve_body( $response );

            if ( $status < 200 || $status >= 300 ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_http_status',
                    __( 'The OAuth introspection endpoint rejected the request.', 'wp-mcp-ai' ),
                    array(
                        'status' => $status,
                        'body'   => $body,
                    )
                );
            }

            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_invalid_response',
                    __( 'The OAuth introspection response was not valid JSON.', 'wp-mcp-ai' ),
                    array( 'status' => 500 )
                );
            }

            if ( empty( $data['active'] ) ) {
                return new WP_Error(
                    'wp_mcp_ai_oauth_inactive_token',
                    __( 'The supplied bearer token is not active.', 'wp-mcp-ai' ),
                    array( 'status' => 401 )
                );
            }

            return $data;
        }
    }

    WP_MCP_AI_OAuth_Introspection::instance();
}
