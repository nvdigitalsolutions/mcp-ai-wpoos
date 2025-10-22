<?php
/**
 * Tests covering JetEngine CRUD dispatch via the MCP integration layer.
 */

if ( ! class_exists( 'Jet_Engine' ) ) {
    class Jet_Engine {}
}

if ( ! function_exists( 'jet_engine' ) ) {
    function jet_engine() {
        return new Jet_Engine();
    }
}

class WP_MCP_AI_JetEngine_Tool_Handlers_Test extends WP_UnitTestCase {

    /**
     * Track whether the mock routes have already been registered.
     *
     * @var bool
     */
    protected static $routes_registered = false;

    /**
     * Administrator user ID leveraged for requests.
     *
     * @var int
     */
    protected $user_id;

    protected function setUp(): void {
        parent::setUp();

        $this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->user_id );

        $this->register_mock_routes();
    }

    /**
     * Register mock JetEngine routes so dispatch() can exercise CRUD flows.
     */
    protected function register_mock_routes() {
        if ( self::$routes_registered ) {
            return;
        }

        $server = rest_get_server();
        if ( ! $server ) {
            return;
        }

        register_rest_route(
            'jet-engine/v2',
            '/add-item/',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => function ( WP_REST_Request $request ) {
                    return new WP_REST_Response(
                        array(
                            'operation' => 'add_item',
                            'body'      => $request->get_body_params(),
                            'user_id'   => get_current_user_id(),
                        ),
                        201
                    );
                },
            )
        );

        register_rest_route(
            'jet-engine/v2',
            '/get-item/(?P<id>[^/]+)/',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => '__return_true',
                'callback'            => function ( WP_REST_Request $request ) {
                    return new WP_REST_Response(
                        array(
                            'operation' => 'get_item',
                            'id'        => $request['id'],
                            'params'    => array(
                                'instance' => $request->get_param( 'instance' ),
                                'query'    => $request->get_param( 'query' ),
                            ),
                        ),
                        200
                    );
                },
            )
        );

        register_rest_route(
            'jet-engine/v2',
            '/edit-item/(?P<id>[^/]+)/',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback'            => function ( WP_REST_Request $request ) {
                    return new WP_REST_Response(
                        array(
                            'operation' => 'edit_item',
                            'id'        => $request['id'],
                            'body'      => $request->get_body_params(),
                            'user_id'   => get_current_user_id(),
                        ),
                        202
                    );
                },
            )
        );

        register_rest_route(
            'jet-engine/v2',
            '/delete-item/(?P<id>[^/]+)/',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => '__return_true',
                'callback'            => function ( WP_REST_Request $request ) {
                    return new WP_REST_Response(
                        array(
                            'operation' => 'delete_item',
                            'id'        => $request['id'],
                            'params'    => array(
                                'instance' => $request->get_param( 'instance' ),
                            ),
                        ),
                        200
                    );
                },
            )
        );

        do_action( 'rest_api_init', $server );
        self::$routes_registered = true;
    }

    /**
     * Create operations should forward sanitized payloads and return a rest transport response.
     */
    public function test_dispatch_add_item_executes_create_flow() {
        $result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
            'add_item',
            array(
                'params' => array(
                    'instance' => 'library',
                    'payload'  => array(
                        'title'       => '  <b>First Book</b>  ',
                        'description' => "Line one\nLine two",
                    ),
                ),
            ),
            array( 'user_id' => $this->user_id )
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'rest', $result['transport'] );
        $this->assertSame( 201, $result['status'] );
        $this->assertSame( $this->user_id, $result['data']['user_id'] );
        $this->assertSame( 'First Book', $result['data']['body']['payload']['title'] );
        $this->assertSame( 'library', $result['data']['body']['instance'] );
    }

    /**
     * Read operations should respect the requested identifier and instance context.
     */
    public function test_dispatch_get_item_executes_read_flow() {
        $result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
            'get_item',
            array(
                'id'     => ' item-42 ',
                'params' => array(
                    'instance' => 'library',
                    'query'    => array( 'search' => '  Magic  ' ),
                ),
            ),
            array( 'user_id' => $this->user_id )
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'rest', $result['transport'] );
        $this->assertSame( 200, $result['status'] );
        $this->assertSame( 'item-42', $result['data']['id'] );
        $this->assertSame( 'library', $result['data']['params']['instance'] );
        $this->assertSame( 'Magic', $result['data']['params']['query']['search'] );
    }

    /**
     * Update operations should propagate sanitized identifiers and payloads.
     */
    public function test_dispatch_edit_item_executes_update_flow() {
        $result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
            'edit_item',
            array(
                'id'     => '42',
                'params' => array(
                    'instance' => 'library',
                    'payload'  => array(
                        'title' => ' Updated <script>alert(1)</script> Title ',
                    ),
                ),
            ),
            array( 'user_id' => $this->user_id )
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'rest', $result['transport'] );
        $this->assertSame( 202, $result['status'] );
        $this->assertSame( '42', $result['data']['id'] );
        $this->assertSame( 'library', $result['data']['body']['instance'] );
        $this->assertSame( 'Updated alert(1) Title', $result['data']['body']['payload']['title'] );
    }

    /**
     * Delete operations should return a normalised success payload with the targeted identifier.
     */
    public function test_dispatch_delete_item_executes_delete_flow() {
        $result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
            'delete_item',
            array(
                'id'     => ' 99 ',
                'params' => array(
                    'instance' => 'library',
                ),
            ),
            array( 'user_id' => $this->user_id )
        );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 'rest', $result['transport'] );
        $this->assertSame( 200, $result['status'] );
        $this->assertSame( '99', $result['data']['id'] );
        $this->assertSame( 'library', $result['data']['params']['instance'] );
    }
}
