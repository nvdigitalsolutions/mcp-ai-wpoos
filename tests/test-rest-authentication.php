<?php
/**
 * Tests covering authentication paths for the MCP REST layer.
 */
class WP_MCP_AI_REST_Authentication_Test extends WP_UnitTestCase {

    /**
     * REST controller instance under test.
     *
     * @var WP_MCP_AI_REST
     */
    protected $rest_controller;

    /**
     * List of transient keys that should be deleted after each test.
     *
     * @var string[]
     */
    protected $transients_to_cleanup = array();

    /**
     * Filter callbacks that should be removed during teardown.
     *
     * @var array[]
     */
    protected $filters_to_remove = array();

    protected function setUp(): void {
        parent::setUp();

        $this->transients_to_cleanup = array();
        $this->filters_to_remove     = array();

        delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );

        if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
            remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
        }

        $registry    = WP_MCP_AI_Tool_Registry::get_instance();
        $mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
            ->disableOriginalConstructor()
            ->getMock();

        $this->rest_controller = new WP_MCP_AI_REST( $registry, $mock_client );
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
            remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
        }

        delete_option( WP_MCP_AI_Credentials::INDEX_OPTION );
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

        remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
        remove_all_filters( 'wp_mcp_ai_map_bearer_to_user_id' );
        remove_all_filters( 'wp_mcp_ai_chat_capability' );

        foreach ( $this->transients_to_cleanup as $transient_key ) {
            delete_transient( $transient_key );
        }

        $this->transients_to_cleanup = array();

        foreach ( $this->filters_to_remove as $filter ) {
            remove_filter( $filter['hook'], $filter['callback'], $filter['priority'] );
        }

        $this->filters_to_remove = array();

        parent::tearDown();
    }

    /**
     * Ensure authors with a valid nonce can access the API.
     */
    public function test_permissions_check_allows_author_with_valid_nonce() {
        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $user_id );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
    }

    /**
     * Ensure users without edit capabilities are rejected.
     */
    public function test_permissions_check_blocks_subscriber_without_capability() {
        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_insufficient_permissions', $result->get_error_code() );
    }

    /**
     * Requests without credentials should surface actionable guidance.
     */
    public function test_permissions_check_requires_credentials() {
        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
    }

    /**
     * Invalid nonces are rejected before the request reaches the MCP layer.
     */
    public function test_permissions_check_rejects_invalid_nonce() {
        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );
        wp_set_current_user( $user_id );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'X-WP-Nonce', 'invalid' );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'rest_invalid_nonce', $result->get_error_code() );
    }

    /**
     * Public chat capability should allow unauthenticated requests without a nonce.
     */
    public function test_permissions_check_allows_public_access_without_nonce() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Public Assistant',
                'post_status' => 'publish',
            )
        );

        add_filter(
            'wp_mcp_ai_chat_capability',
            static function ( $capability, $filtered_assistant_id, $context ) use ( $assistant_id ) {
                if ( 'rest' === $context && (int) $filtered_assistant_id === (int) $assistant_id ) {
                    return 'public';
                }

                return $capability;
            },
            10,
            3
        );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
    }

    /**
     * Guest tokens issued by the shortcode allow anonymous visitors to chat.
     */
    public function test_permissions_check_allows_guest_token_access() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Guest Assistant',
                'post_status' => 'publish',
            )
        );

        $token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );

        $this->assertNotEmpty( $token );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $assistant_id );
        $request->set_header( 'X-WP-MCP-AI-Guest', $token );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
    }

    /**
     * Tokens are scoped to the assistant rendered on the shortcode.
     */
    public function test_permissions_check_rejects_mismatched_guest_token() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Scoped Assistant',
                'post_status' => 'publish',
            )
        );

        $other_assistant = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Other Assistant',
                'post_status' => 'publish',
            )
        );

        $token = WP_MCP_AI_Shortcode::generate_guest_token( $assistant_id );

        $this->assertNotEmpty( $token );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_param( 'assistant_id', $other_assistant );
        $request->set_header( 'X-WP-MCP-AI-Guest', $token );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
    }

    /**
     * Stored assistant credentials grant access when supplied via bearer token.
     */
    public function test_permissions_check_accepts_valid_local_token() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Tokenised Assistant',
                'post_status' => 'publish',
            )
        );

        $issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $issuer_id );

        $issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
    }

    /**
     * Revoked credentials should not be able to authenticate future requests.
     */
    public function test_permissions_check_rejects_revoked_local_token() {
        $assistant_id = wp_insert_post(
            array(
                'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
                'post_title'  => 'Revocable Assistant',
                'post_status' => 'publish',
            )
        );

        $issuer_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $issuer_id );

        $issued = WP_MCP_AI_Credentials::issue_credential( $assistant_id, $issuer_id );

        WP_MCP_AI_Credentials::revoke_credential( $assistant_id, $issued['credential']['id'], $issuer_id );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer ' . $issued['token'] );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_revoked_token', $result->get_error_code() );
    }

    /**
     * Bearer tokens that do not resemble JWTs are rejected early.
     */
    public function test_permissions_check_rejects_invalid_bearer_token_format() {
        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer not-a-jwt' );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_invalid_bearer_token', $result->get_error_code() );
    }

    /**
     * Valid Auth0 tokens should be accepted after fetching the JWKS from the configured domain.
     */
    public function test_permissions_check_accepts_valid_auth0_bearer_token() {
        $domain   = 'example.auth0.com';
        $audience = 'https://api.example.com/';

        $this->transients_to_cleanup[] = 'wp_mcp_ai_auth0_jwks_' . md5( $domain );

        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['auth0_domain']   = $domain;
        $settings['auth0_audience'] = $audience;

        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $signed   = $this->generate_signed_auth0_token( 'https://' . $domain . '/', $audience );
        $requests = array();

        $http_filter = static function ( $preempt, $args, $url ) use ( &$requests, $signed ) {
            $requests[] = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode( array( 'keys' => array( $signed['jwk'] ) ) ),
            );
        };

        add_filter( 'pre_http_request', $http_filter, 10, 3 );

        $this->filters_to_remove[] = array(
            'hook'     => 'pre_http_request',
            'callback' => $http_filter,
            'priority' => 10,
        );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer ' . $signed['token'] );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
        $this->assertNotEmpty( $requests );
        $this->assertSame( 'https://' . $domain . '/.well-known/jwks.json', $requests[0]['url'] );
        $this->assertArrayHasKey( 'timeout', $requests[0]['args'] );
        $this->assertSame( 10, $requests[0]['args']['timeout'] );
    }

    /**
     * JWKS retrieval should be customisable and issuers without trailing slashes must validate.
     */
    public function test_permissions_check_supports_google_jwks_overrides() {
        $domain      = 'accounts.google.com';
        $audience    = 'https://api.example.com/';
        $custom_jwks = 'https://www.googleapis.com/oauth2/v3/certs';

        $this->transients_to_cleanup[] = 'wp_mcp_ai_auth0_jwks_' . md5( $domain );

        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();
        $settings['auth0_domain']   = $domain;
        $settings['auth0_audience'] = $audience;

        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

        $signed    = $this->generate_signed_auth0_token( 'https://' . $domain, $audience );
        $requests  = array();
        $filtered  = array();

        $jwks_filter = static function ( $url, $filtered_domain ) use ( &$filtered, $custom_jwks ) {
            $filtered[] = array(
                'url'    => $url,
                'domain' => $filtered_domain,
            );

            return $custom_jwks;
        };

        add_filter( 'wp_mcp_ai_auth0_jwks_url', $jwks_filter, 10, 2 );

        $this->filters_to_remove[] = array(
            'hook'     => 'wp_mcp_ai_auth0_jwks_url',
            'callback' => $jwks_filter,
            'priority' => 10,
        );

        $http_filter = static function ( $preempt, $args, $url ) use ( &$requests, $signed ) {
            $requests[] = array(
                'url'  => $url,
                'args' => $args,
            );

            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode( array( 'keys' => array( $signed['jwk'] ) ) ),
            );
        };

        add_filter( 'pre_http_request', $http_filter, 10, 3 );

        $this->filters_to_remove[] = array(
            'hook'     => 'pre_http_request',
            'callback' => $http_filter,
            'priority' => 10,
        );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer ' . $signed['token'] );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
        $this->assertNotEmpty( $filtered );
        $this->assertSame( 'https://' . $domain . '/.well-known/jwks.json', $filtered[0]['url'] );
        $this->assertSame( $domain, $filtered[0]['domain'] );
        $this->assertNotEmpty( $requests );
        $this->assertSame( $custom_jwks, $requests[0]['url'] );
    }

    /**
     * Mapping a bearer token to a WordPress user should update the current user context.
     */
    public function test_bearer_token_mapping_sets_current_user() {
        $user_id = self::factory()->user->create( array( 'role' => 'author' ) );

        wp_set_current_user( 0 );

        add_filter(
            'wp_mcp_ai_pre_validate_bearer_token',
            static function () {
                return true;
            }
        );

        add_filter(
            'wp_mcp_ai_map_bearer_to_user_id',
            static function ( $mapped, $payload, $request ) use ( $user_id ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
                return $user_id;
            },
            10,
            3
        );

        $request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
        $request->set_header( 'Authorization', 'Bearer placeholder' );

        $result = $this->rest_controller->permissions_check( $request );

        $this->assertTrue( $result );
        $this->assertSame( $user_id, get_current_user_id() );
    }

    /**
     * Generate a signed JWT and matching JWKS entry for the provided issuer and audience.
     *
     * @param string $issuer   Token issuer claim.
     * @param string $audience Token audience claim.
     * @param string $kid      Key identifier for the generated JWK.
     * @return array{
     *     token: string,
     *     jwk: array,
     * }
     */
    private function generate_signed_auth0_token( $issuer, $audience, $kid = 'test-key' ) {
        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            $this->markTestSkipped( 'OpenSSL is required to generate RSA keys for JWT validation tests.' );
        }

        $resource = openssl_pkey_new(
            array(
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            )
        );

        if ( false === $resource ) {
            $this->markTestSkipped( 'Unable to generate an RSA key pair for the JWT validation test.' );
        }

        $details = openssl_pkey_get_details( $resource );

        if ( empty( $details['rsa']['n'] ) || empty( $details['rsa']['e'] ) ) {
            $this->fail( 'Failed to extract RSA key details for the generated JWT.' );
        }

        $jwk = array(
            'kty' => 'RSA',
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'RS256',
            'n'   => $this->base64_url_encode( $details['rsa']['n'] ),
            'e'   => $this->base64_url_encode( $details['rsa']['e'] ),
        );

        $header = array(
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => $kid,
        );

        $payload = array(
            'iss' => $issuer,
            'sub' => 'auth0|123456789',
            'aud' => $audience,
            'exp' => time() + HOUR_IN_SECONDS,
            'iat' => time(),
        );

        $segments = array(
            $this->base64_url_encode( wp_json_encode( $header ) ),
            $this->base64_url_encode( wp_json_encode( $payload ) ),
        );

        $signature = '';
        $signed    = openssl_sign( implode( '.', $segments ), $signature, $resource, OPENSSL_ALGO_SHA256 );

        if ( ! $signed ) {
            $this->fail( 'Failed to sign the generated JWT with the RSA private key.' );
        }

        $segments[] = $this->base64_url_encode( $signature );

        return array(
            'token' => implode( '.', $segments ),
            'jwk'   => $jwk,
        );
    }

    /**
     * Encode binary data using base64url encoding.
     *
     * @param string $data Binary data to encode.
     * @return string
     */
    private function base64_url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
