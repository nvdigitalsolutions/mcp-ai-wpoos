<?php
/**
 * Simple JWT Login integration helpers for WP MCP AI.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_MCP_AI_Simple_JWT_Login_Integration' ) ) {

    /**
     * Bootstraps the Simple JWT Login integration when available.
     */
    class WP_MCP_AI_Simple_JWT_Login_Integration {
        const PLUGIN_FILE = 'simple-jwt-login/simple-jwt-login.php';

        /**
         * Singleton instance.
         *
         * @var WP_MCP_AI_Simple_JWT_Login_Integration|null
         */
        protected static $instance = null;

        /**
         * Cached user identifier from the last validated token.
         *
         * @var int|null
         */
        protected $last_validated_user_id = null;

        /**
         * Cached payload from the last validated token.
         *
         * @var array|null
         */
        protected $last_payload = null;

        /**
         * Initialise the integration singleton.
         */
        public static function init() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Register the plugins_loaded hook.
         */
        private function __construct() {
            add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ), 40 );
        }

        /**
         * Register filters when the dependency and setting are available.
         */
        public function maybe_bootstrap() {
            if ( ! $this->is_enabled() ) {
                return;
            }

            if ( ! class_exists( '\SimpleJWTLogin\Services\ValidateTokenService' ) ) {
                return;
            }

            add_filter( 'wp_mcp_ai_pre_validate_bearer_token', array( $this, 'pre_validate_bearer_token' ), 10, 3 );
            add_filter( 'wp_mcp_ai_map_bearer_to_user_id', array( $this, 'map_bearer_to_user_id' ), 10, 3 );
        }

        /**
         * Determine whether the integration should be active.
         *
         * @return bool
         */
        protected function is_enabled() {
            if ( ! $this->is_dependency_active() ) {
                return false;
            }

            $settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
            if ( ! is_array( $settings ) ) {
                $settings = array();
            }

            return ! empty( $settings['enable_simple_jwt_login'] );
        }

        /**
         * Check whether the Simple JWT Login plugin is active.
         *
         * @return bool
         */
        protected function is_dependency_active() {
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            if ( function_exists( 'is_plugin_active' ) && is_plugin_active( self::PLUGIN_FILE ) ) {
                return true;
            }

            if ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( self::PLUGIN_FILE ) ) {
                return true;
            }

            return false;
        }

        /**
         * Attempt to validate the bearer token via Simple JWT Login.
         *
         * @param null|bool|WP_Error $pre     Existing validation result.
         * @param string             $token   Raw bearer token.
         * @param WP_REST_Request    $request Current REST request.
         * @return true|WP_Error|null
         */
        public function pre_validate_bearer_token( $pre, $token, $request ) {
            if ( null !== $pre ) {
                return $pre;
            }

            if ( ! $this->is_enabled() ) {
                return $pre;
            }

            $this->last_validated_user_id = null;
            $this->last_payload           = null;

            if ( empty( $token ) ) {
                return $pre;
            }

            $result = $this->validate_simple_jwt_login_token( $token );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( true === $result ) {
                return true;
            }

            return $pre;
        }

        /**
         * Map the previously validated token to a WordPress user.
         *
         * @param int|null        $mapped   Currently mapped user identifier.
         * @param array|null      $payload  Token payload when available.
         * @param WP_REST_Request $request  Current REST request.
         * @return int|null|WP_Error
         */
        public function map_bearer_to_user_id( $mapped, $payload, $request ) {
            if ( $mapped instanceof WP_Error ) {
                return $mapped;
            }

            if ( null !== $mapped ) {
                return $mapped;
            }

            if ( ! $this->is_enabled() ) {
                return $mapped;
            }

            if ( null !== $this->last_validated_user_id ) {
                return $this->last_validated_user_id;
            }

            return $mapped;
        }

        /**
         * Validate the token using Simple JWT Login services.
         *
         * @param string $token Raw bearer token.
         * @return true|WP_Error|null
         */
        protected function validate_simple_jwt_login_token( $token ) {
            if ( ! class_exists( '\SimpleJWTLogin\Modules\SimpleJWTLoginSettings' ) || ! class_exists( '\SimpleJWTLogin\Modules\WordPressData' ) ) {
                return null;
            }

            try {
                $wordpress_data = new \SimpleJWTLogin\Modules\WordPressData();
                $settings       = new \SimpleJWTLogin\Modules\SimpleJWTLoginSettings( $wordpress_data );
            } catch ( \Exception $exception ) {
                return new WP_Error(
                    'wp_mcp_ai_simple_jwt_login_configuration',
                    __( 'Simple JWT Login could not be initialised. Check the plugin configuration and try again.', 'wp-mcp-ai' ),
                    array(
                        'status'  => 500,
                        'details' => array(
                            'message' => wp_strip_all_tags( $exception->getMessage() ),
                            'code'    => $exception->getCode(),
                        ),
                    )
                );
            }

            $server      = isset( $_SERVER ) && is_array( $_SERVER ) ? $_SERVER : array();
            $header_name = $settings->getGeneralSettings()->getRequestKeyHeader();
            $server_key  = $this->normalise_header_key( $header_name );
            $server[ $server_key ] = 'Bearer ' . $token;

            if ( empty( $server['REQUEST_METHOD'] ) ) {
                $server['REQUEST_METHOD'] = 'POST';
            }

            $service = new \SimpleJWTLogin\Services\ValidateTokenService();

            try {
                $response = $service
                    ->withSettings( $settings )
                    ->withServerHelper( new \SimpleJWTLogin\Helpers\ServerHelper( $server ) )
                    ->withRequestMethod( $server['REQUEST_METHOD'] )
                    ->withRequest( array() )
                    ->withCookies( isset( $_COOKIE ) ? (array) $_COOKIE : array() )
                    ->withSession( isset( $_SESSION ) ? (array) $_SESSION : array() )
                    ->makeAction();
            } catch ( \Exception $exception ) {
                return new WP_Error(
                    'wp_mcp_ai_simple_jwt_login_invalid_token',
                    __( 'The Simple JWT Login token could not be validated.', 'wp-mcp-ai' ),
                    array(
                        'status'  => 401,
                        'details' => array(
                            'message' => wp_strip_all_tags( $exception->getMessage() ),
                            'code'    => $exception->getCode(),
                        ),
                    )
                );
            }

            if ( $response instanceof WP_REST_Response ) {
                $data = $response->get_data();
            } else {
                $data = $response;
            }

            if ( ! is_array( $data ) || empty( $data['data']['user']['ID'] ) ) {
                return new WP_Error(
                    'wp_mcp_ai_simple_jwt_login_unexpected_response',
                    __( 'Simple JWT Login returned an unexpected response.', 'wp-mcp-ai' ),
                    array( 'status' => 401 )
                );
            }

            $this->last_validated_user_id = absint( $data['data']['user']['ID'] );

            if ( isset( $data['data']['jwt'][0]['payload'] ) && is_array( $data['data']['jwt'][0]['payload'] ) ) {
                $this->last_payload = $data['data']['jwt'][0]['payload'];
            } else {
                $this->last_payload = null;
            }

            /**
             * Fires after a Simple JWT Login token has been validated.
             *
             * @param array|null      $payload Validated token payload when available.
             * @param int             $user_id WordPress user identifier mapped from the token.
             */
            do_action( 'wp_mcp_ai_simple_jwt_login_validated', $this->last_payload, $this->last_validated_user_id );

            return true;
        }

        /**
         * Normalise the header key used by Simple JWT Login.
         *
         * @param string $header Raw header name from plugin settings.
         * @return string
         */
        protected function normalise_header_key( $header ) {
            if ( ! is_string( $header ) || '' === $header ) {
                return 'HTTP_AUTHORIZATION';
            }

            $server_key = strtoupper( str_replace( '-', '_', $header ) );
            if ( 0 !== strpos( $server_key, 'HTTP_' ) ) {
                $server_key = 'HTTP_' . $server_key;
            }

            return $server_key;
        }
    }
}
