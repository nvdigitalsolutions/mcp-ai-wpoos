<?php
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-web-search.php';

/**
 * Tests for the web search tool.
 */
class WP_MCP_AI_Web_Search_Tool_Test extends WP_UnitTestCase {
    /**
     * Ensure each test starts with a logged-out user and clean filters.
     */
    public function set_up() {
        parent::set_up();
        remove_all_filters( 'pre_http_request' );
        wp_set_current_user( 0 );
    }

    /**
     * Clean up after each test run.
     */
    public function tear_down() {
        remove_all_filters( 'pre_http_request' );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * The tool should surface a helpful pending error when the remote service
     * returns HTTP 202, signalling that the search is still being processed.
     */
    public function test_execute_returns_pending_error_when_service_accepted_request() {
        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $tool = new WP_MCP_AI_Tool_Web_Search();

        $http_stub = static function ( $preempt, $args, $url ) {
            return array(
                'response' => array(
                    'code' => 202,
                ),
                'headers'  => array(
                    'retry-after' => '7',
                ),
                'body'     => '',
            );
        };

        add_filter( 'pre_http_request', $http_stub, 10, 3 );

        $result = $tool->execute(
            array(
                'query' => 'hurricane updates',
            ),
            array(
                'user_id' => $user_id,
            )
        );

        remove_filter( 'pre_http_request', $http_stub, 10 );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_search_pending', $result->get_error_code() );
        $this->assertSame(
            'The web search results are not ready yet. Try again in a few seconds.',
            $result->get_error_message()
        );

        $data = $result->get_error_data();
        $this->assertIsArray( $data );
        $this->assertSame( 202, $data['status'] );
        $this->assertSame( '7', $data['retry_after'] );
    }
}
