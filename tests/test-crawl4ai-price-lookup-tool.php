<?php
/**
 * Tests for the Crawl4AI price lookup tool.
 */
class WP_MCP_AI_Crawl4AI_Price_Lookup_Tool_Test extends WP_UnitTestCase {

    /**
     * Reset the current user after each test.
     */
    public function tearDown(): void {
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Ensure the tool reports an error when Crawl4AI is not configured.
     */
    public function test_execute_requires_crawl4ai_configuration() {
        delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
        $result = $tool->execute( array( 'product' => 'paper towels' ), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_crawl4ai_unavailable', $result->get_error_code() );
    }

    /**
     * Ensure the tool respects capability requirements.
     */
    public function test_execute_requires_manage_options() {
        $this->configure_crawl4ai_settings();

        $user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
        $result = $tool->execute( array( 'product' => 'paper towels' ), array( 'user_id' => $user_id ) );

        $this->assertWPError( $result );
        $this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
    }

    /**
     * Ensure the tool queries Crawl4AI and extracts pricing information.
     */
    public function test_execute_extracts_prices_from_responses() {
        $this->configure_crawl4ai_settings();

        $user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        $responses = array(
            array(
                'body'     => wp_json_encode(
                    array(
                        'results' => array(
                            array(
                                'title'   => "BJ's Club Value Pack",
                                'url'     => 'https://www.bjs.com/product/value-pack',
                                'snippet' => 'Save more with this pack for just $12.99 today.',
                            ),
                        ),
                    )
                ),
                'headers'  => array(),
                'response' => array( 'code' => 200 ),
            ),
            array(
                'body'     => wp_json_encode(
                    array(
                        'results' => array(
                            array(
                                'title'   => "Sam's Club Mega Bundle",
                                'url'     => 'https://www.samsclub.com/p/mega-bundle',
                                'snippet' => 'Mega savings available now at $15.49.',
                            ),
                        ),
                    )
                ),
                'headers'  => array(),
                'response' => array( 'code' => 200 ),
            ),
            array(
                'body'     => wp_json_encode(
                    array(
                        'results' => array(
                            array(
                                'title'   => 'Costco Bulk Essentials',
                                'url'     => 'https://www.costco.com/product/bulk-essentials',
                                'snippet' => 'Members get it for $17.99 with same-day delivery.',
                            ),
                        ),
                    )
                ),
                'headers'  => array(),
                'response' => array( 'code' => 200 ),
            ),
        );

        $requests = array();

        $callback = function( $preempt, $args, $url ) use ( &$responses, &$requests ) {
            if ( empty( $responses ) ) {
                return $preempt;
            }

            $requests[] = array(
                'url'  => $url,
                'body' => isset( $args['body'] ) ? $args['body'] : '',
            );

            return array_shift( $responses );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $tool   = new WP_MCP_AI_Tool_Crawl4AI_Price_Lookup();
        $result = $tool->execute(
            array(
                'product'     => 'paper towels',
                'max_results' => 3,
            ),
            array( 'user_id' => $user_id )
        );

        remove_filter( 'pre_http_request', $callback, 10 );

        $this->assertIsArray( $result );
        $this->assertSame( 'paper towels', $result['product'] );
        $this->assertSame( 3, $result['metadata']['max_results'] );
        $this->assertCount( 3, $result['brands'] );

        $this->assertSame( 'success', $result['brands'][0]['status'] );
        $this->assertSame( 12.99, $result['brands'][0]['price'] );
        $this->assertSame( 'https://www.bjs.com/product/value-pack', $result['brands'][0]['source']['url'] );

        $this->assertSame( 'success', $result['brands'][1]['status'] );
        $this->assertSame( 15.49, $result['brands'][1]['price'] );

        $this->assertSame( 'success', $result['brands'][2]['status'] );
        $this->assertSame( 17.99, $result['brands'][2]['price'] );

        $this->assertCount( 3, $requests );
        $this->assertStringEndsWith( '/web_search', $requests[0]['url'] );
        $this->assertStringContainsString( 'site:bjs.com', $requests[0]['body'] );
        $this->assertStringContainsString( 'site:samsclub.com', $requests[1]['body'] );
        $this->assertStringContainsString( 'site:costco.com', $requests[2]['body'] );
    }

    /**
     * Helper to configure Crawl4AI settings for the tests.
     */
    protected function configure_crawl4ai_settings() {
        $settings = WP_MCP_AI_Admin_Settings::get_default_settings();

        $settings['crawl4ai_base_url'] = 'https://api.example.com';
        $settings['crawl4ai_api_key']  = 'test-token';
        $settings['request_timeout']   = 5;

        update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
    }
}
