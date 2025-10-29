<?php

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-list-google-drive-files.php';

/**
 * Tests for the Google Drive listing tool.
 */
class WP_MCP_AI_Google_Drive_Tool_Test extends WP_UnitTestCase {
    /**
     * Generated service account private key.
     *
     * @var string
     */
    protected $private_key = '';

    /**
     * Generate a service account key for signing JWTs.
     */
    public function setUp(): void {
        parent::setUp();

        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            $this->fail( 'The OpenSSL extension is required for these tests.' );
        }

        $config = array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        );

        $resource = openssl_pkey_new( $config );
        $exported = '';
        openssl_pkey_export( $resource, $exported );

        if ( is_resource( $resource ) && function_exists( 'openssl_pkey_free' ) ) {
            openssl_pkey_free( $resource );
        }

        $this->private_key = $exported;
    }

    /**
     * Remove hooks after each test.
     */
    public function tearDown(): void {
        remove_all_filters( 'wp_mcp_ai_google_drive_credentials' );
        remove_all_filters( 'pre_http_request' );
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Ensure users without the manage_options capability cannot access the tool.
     */
    public function test_execute_requires_permission() {
        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool   = new WP_MCP_AI_Tool_List_Google_Drive_Files();
        $result = $tool->execute( array(), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
    }

    /**
     * Ensure a helpful error is returned when credentials are not configured.
     */
    public function test_execute_requires_credentials() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $tool   = new WP_MCP_AI_Tool_List_Google_Drive_Files();
        $result = $tool->execute( array(), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
    }

    /**
     * Ensure the tool requests an access token and returns Drive results.
     */
    public function test_execute_lists_files() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        add_filter(
            'wp_mcp_ai_google_drive_credentials',
            function () {
                return array(
                    'client_email' => 'service-account@example.iam.gserviceaccount.com',
                    'private_key'  => $this->private_key,
                    'scopes'       => array( 'https://www.googleapis.com/auth/drive.metadata.readonly' ),
                );
            }
        );

        $captured_requests = array();

        add_filter(
            'pre_http_request',
            function ( $preempt, $r, $url ) use ( &$captured_requests ) {
                if ( WP_MCP_AI_Tool_List_Google_Drive_Files::TOKEN_ENDPOINT === $url ) {
                    $captured_requests['token'] = $r;

                    return array(
                        'response' => array(
                            'code'    => 200,
                            'message' => 'OK',
                        ),
                        'body'     => wp_json_encode(
                            array(
                                'access_token' => 'test-token',
                                'token_type'   => 'Bearer',
                                'expires_in'   => 3600,
                            )
                        ),
                        'headers'  => array(),
                    );
                }

                if ( 0 === strpos( $url, WP_MCP_AI_Tool_List_Google_Drive_Files::DRIVE_FILES_ENDPOINT ) ) {
                    $captured_requests['files'] = array(
                        'request' => $r,
                        'url'     => $url,
                    );

                    return array(
                        'response' => array(
                            'code'    => 200,
                            'message' => 'OK',
                        ),
                        'body'     => wp_json_encode(
                            array(
                                'files' => array(
                                    array(
                                        'id'           => '123',
                                        'name'         => 'Document A',
                                        'mimeType'     => 'application/vnd.google-apps.document',
                                        'modifiedTime' => '2024-01-01T00:00:00Z',
                                    ),
                                ),
                                'nextPageToken' => 'next-token',
                            )
                        ),
                        'headers'  => array(),
                    );
                }

                return $preempt;
            },
            10,
            3
        );

        $tool   = new WP_MCP_AI_Tool_List_Google_Drive_Files();
        $result = $tool->execute(
            array(
                'folder_id' => 'root-folder',
                'page_size' => 5,
            ),
            array(
                'user_id' => $user_id,
            )
        );

        $this->assertNotWPError( $result );
        $this->assertArrayHasKey( 'files', $result );
        $this->assertCount( 1, $result['files'] );
        $this->assertSame( 'next-token', $result['next_page_token'] );

        $this->assertArrayHasKey( 'token', $captured_requests );
        $this->assertArrayHasKey( 'files', $captured_requests );

        $this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $captured_requests['token']['body']['grant_type'] );
        $this->assertArrayHasKey( 'assertion', $captured_requests['token']['body'] );

        $drive_request = $captured_requests['files'];
        $this->assertSame( 'Bearer test-token', $drive_request['request']['headers']['Authorization'] );

        $parsed_url = wp_parse_url( $drive_request['url'] );
        parse_str( isset( $parsed_url['query'] ) ? $parsed_url['query'] : '', $query_args );
        $this->assertSame( '5', $query_args['pageSize'] );
        $this->assertStringContainsString( "'root-folder' in parents", $query_args['q'] );
        $this->assertStringContainsString( 'trashed = false', $query_args['q'] );
    }

    /**
     * Ensure HTTP failures are surfaced as WP_Error instances.
     */
    public function test_execute_handles_http_error() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        add_filter(
            'wp_mcp_ai_google_drive_credentials',
            function () {
                return array(
                    'client_email' => 'service-account@example.iam.gserviceaccount.com',
                    'private_key'  => $this->private_key,
                );
            }
        );

        add_filter(
            'pre_http_request',
            function ( $preempt, $r, $url ) {
                if ( WP_MCP_AI_Tool_List_Google_Drive_Files::TOKEN_ENDPOINT === $url ) {
                    return array(
                        'response' => array(
                            'code'    => 200,
                            'message' => 'OK',
                        ),
                        'body'     => wp_json_encode(
                            array(
                                'access_token' => 'test-token',
                                'token_type'   => 'Bearer',
                                'expires_in'   => 3600,
                            )
                        ),
                        'headers'  => array(),
                    );
                }

                if ( 0 === strpos( $url, WP_MCP_AI_Tool_List_Google_Drive_Files::DRIVE_FILES_ENDPOINT ) ) {
                    return new WP_Error( 'http_request_failed', 'Network failure' );
                }

                return $preempt;
            },
            10,
            3
        );

        $tool   = new WP_MCP_AI_Tool_List_Google_Drive_Files();
        $result = $tool->execute( array(), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_google_drive_http_error', $result->get_error_code() );
    }
}
