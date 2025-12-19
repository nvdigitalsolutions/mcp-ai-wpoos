<?php
/**
 * Tests covering JetEngine CRUD dispatch via the MCP integration layer.
 *
 * @package WP_MCP_AI
 */

if ( ! class_exists( 'Jet_Engine' ) ) {
	class Jet_Engine {
		public $api;
	}
}

$wp_mcp_ai_mock_jet_engine = null;

if ( ! function_exists( 'jet_engine' ) ) {
	function jet_engine() {
		global $wp_mcp_ai_mock_jet_engine;

		if ( null === $wp_mcp_ai_mock_jet_engine ) {
			$wp_mcp_ai_mock_jet_engine = new Jet_Engine();
		}

		return $wp_mcp_ai_mock_jet_engine;
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

	/**
	 * Reference to the reusable JetEngine stub instance.
	 *
	 * @var Jet_Engine
	 */
	protected $jet_engine;

	protected function setUp(): void {
		parent::setUp();

		global $wp_mcp_ai_mock_jet_engine;
		$wp_mcp_ai_mock_jet_engine = new Jet_Engine();
		$this->jet_engine          = $wp_mcp_ai_mock_jet_engine;

		$reflection = new ReflectionProperty( WP_MCP_AI_JetEngine_Tool_Handlers::class, 'operations' );
		$reflection->setAccessible( true );
		$reflection->setValue( null, null );

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		$this->register_mock_routes();
	}

	protected function tearDown(): void {
		self::$routes_registered = false;
		global $wp_mcp_ai_mock_jet_engine;
		$wp_mcp_ai_mock_jet_engine = null;
		parent::tearDown();
	}

	public function test_dynamic_operations_are_discovered_from_jetengine_api() {
		$endpoint = new class() {
			public function get_name() {
				return 'add-content-type';
			}

			public function get_method() {
				return 'POST';
			}

			public function get_query_params() {
				return '';
			}
		};

		$this->jet_engine->api = new class( $endpoint ) {
			protected $endpoint;

			public function __construct( $endpoint ) {
				$this->endpoint = $endpoint;
			}

			public function get_endpoints() {
				return array( $this->endpoint );
			}
		};

		$operations = WP_MCP_AI_JetEngine_Tool_Handlers::get_supported_operations();

		$this->assertContains( 'add-content-type', $operations );

		$config = WP_MCP_AI_JetEngine_Tool_Handlers::get_operation_config( 'add-content-type' );
		$this->assertSame( 'POST', $config['method'] );
		$this->assertSame( 'body', $config['args_location'] );
		$this->assertSame( 'add-content-type/', $config['route'] );
	}

	/**
	 * Register mock JetEngine routes so dispatch() can exercise CRUD flows.
	 */
	protected function register_mock_routes() {
		if ( self::$routes_registered ) {
			return;
		}

		if ( did_action( 'rest_api_init' ) ) {
			$this->register_mock_rest_routes();
		} else {
			add_action( 'rest_api_init', array( $this, 'register_mock_rest_routes' ), 5 );
			$server = rest_get_server();

			if ( did_action( 'rest_api_init' ) ) {
				remove_action( 'rest_api_init', array( $this, 'register_mock_rest_routes' ), 5 );
			} else {
				do_action( 'rest_api_init', $server instanceof WP_REST_Server ? $server : null );
				remove_action( 'rest_api_init', array( $this, 'register_mock_rest_routes' ), 5 );
			}
		}

		self::$routes_registered = true;
	}

	/**
	 * Register the JetEngine mock routes used during testing.
	 *
	 * @param WP_REST_Server|null $server REST server instance.
	 */
	public function register_mock_rest_routes( ?WP_REST_Server $server = null ) {
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

	/**
	 * Remote fallbacks should authenticate with a proxy token when cookies are unavailable.
	 */
	public function test_dispatch_remote_uses_proxy_token_for_authentication() {
		$captured_args = null;

		$http_interceptor = function ( $preempt, $parsed_args, $url ) use ( &$captured_args ) {
			$captured_args = $parsed_args;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'operation' => 'get_items',
						'instance'  => 'library',
					)
				),
				'response' => array(
					'code' => 200,
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
			'get_items',
			array(
				'params'    => array(
					'instance' => 'library',
				),
				'transport' => 'http',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$this->assertNotNull( $captured_args );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'http', $result['transport'] );
		$this->assertSame( 200, $result['status'] );

		$this->assertArrayHasKey( 'headers', $captured_args );
		$this->assertArrayHasKey( WP_MCP_AI_JetEngine_Tool_Handlers::PROXY_HEADER, $captured_args['headers'] );

		$proxy_token = $captured_args['headers'][ WP_MCP_AI_JetEngine_Tool_Handlers::PROXY_HEADER ];
		$this->assertNotEmpty( $proxy_token );

		$reflection = new ReflectionClass( WP_MCP_AI_JetEngine_Tool_Handlers::class );
		$method     = $reflection->getMethod( 'get_proxy_transient_key' );
		$method->setAccessible( true );
		$transient_key = $method->invoke( null, $proxy_token );

		$payload = get_transient( $transient_key );
		$this->assertIsArray( $payload );
		$this->assertSame( $this->user_id, $payload['user_id'] );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/jet-engine/v2/get-items/' );
		$request->set_header( WP_MCP_AI_JetEngine_Tool_Handlers::PROXY_HEADER, $proxy_token );

		$server = rest_get_server();
		apply_filters( 'rest_authentication_errors', null, $request, $server );

		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertFalse( get_transient( $transient_key ) );

		wp_set_current_user( $this->user_id );
	}

	/**
	 * Forwarded hosts from untrusted clients must be ignored to avoid SSRF.
	 */
	public function test_dispatch_remote_ignores_untrusted_forwarded_host() {
		$previous_siteurl = get_option( 'siteurl' );
		$previous_home    = get_option( 'home' );

		update_option( 'siteurl', 'http://127.0.0.1' );
		update_option( 'home', 'http://127.0.0.1' );

		$original_server = $_SERVER;

		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';
		$_SERVER['HTTP_HOST']             = 'example.test';
		$_SERVER['HTTP_X_FORWARDED_HOST'] = 'attacker.invalid';

		$captured_url = null;

		$http_interceptor = function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
			$captured_url = $url;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array() ),
				'response' => array(
					'code' => 200,
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
			'get_items',
			array(
				'params'    => array(
					'instance' => 'library',
				),
				'transport' => 'http',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$_SERVER = $original_server;

		update_option( 'siteurl', $previous_siteurl );
		update_option( 'home', $previous_home );

		$this->assertNotNull( $captured_url );
		$parsed = wp_parse_url( $captured_url );
		$this->assertSame( '127.0.0.1', $parsed['host'] );
	}

	/**
	 * Trusted proxy requests may override the host when the backend is bound to loopback.
	 */
	public function test_dispatch_remote_honours_trusted_forwarded_host() {
		$previous_siteurl = get_option( 'siteurl' );
		$previous_home    = get_option( 'home' );

		update_option( 'siteurl', 'http://127.0.0.1' );
		update_option( 'home', 'http://127.0.0.1' );

		$original_server = $_SERVER;

		$_SERVER['REMOTE_ADDR']           = '127.0.0.1';
		$_SERVER['HTTP_HOST']             = 'example.test';
		$_SERVER['HTTP_X_FORWARDED_HOST'] = 'example.test';

		$captured_url = null;

		$http_interceptor = function ( $preempt, $parsed_args, $url ) use ( &$captured_url ) {
			$captured_url = $url;

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array() ),
				'response' => array(
					'code' => 200,
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		WP_MCP_AI_JetEngine_Tool_Handlers::dispatch(
			'get_items',
			array(
				'params'    => array(
					'instance' => 'library',
				),
				'transport' => 'http',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_interceptor, 10 );

		$_SERVER = $original_server;

		update_option( 'siteurl', $previous_siteurl );
		update_option( 'home', $previous_home );

		$this->assertNotNull( $captured_url );
		$parsed = wp_parse_url( $captured_url );
		$this->assertSame( 'example.test', $parsed['host'] );
	}
}
