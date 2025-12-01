<?php
/**
 * Tests covering JetFormBuilder REST dispatch via the MCP integration layer.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_JetFormBuilder_Tool_Handlers_Test extends WP_UnitTestCase {

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

		add_filter( 'wp_mcp_ai_jetformbuilder_is_available', '__return_true' );

		$this->register_mock_routes();
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mcp_ai_jetformbuilder_is_available', '__return_true' );
		self::$routes_registered = false;
		parent::tearDown();
	}

	/**
	 * Register mock JetFormBuilder routes so dispatch() can exercise REST flows.
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
	 * Register the JetFormBuilder mock routes used during testing.
	 *
	 * @param WP_REST_Server|null $server REST server instance.
	 */
	public function register_mock_rest_routes( ?WP_REST_Server $server = null ) {
		register_rest_route(
			'jet-form-builder/v1',
			'/forms/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						array(
							'operation' => 'list_forms',
							'query'     => $request->get_params(),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'jet-form-builder/v1',
			'/forms/(?P<id>[^/]+)/fields/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						array(
							'operation' => 'get_form_fields',
							'form_id'   => $request['id'],
							'query'     => $request->get_params(),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'jet-form-builder/v1',
			'/forms/(?P<id>[^/]+)/records/',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						array(
							'operation' => 'fetch_submissions',
							'form_id'   => $request['id'],
							'query'     => $request->get_params(),
						),
						200
					);
				},
			)
		);

		register_rest_route(
			'jet-form-builder/v1',
			'/forms/(?P<id>[^/]+)/submit/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => function ( WP_REST_Request $request ) {
					return new WP_REST_Response(
						array(
							'operation' => 'create_submission',
							'form_id'   => $request['id'],
							'payload'   => $request->get_body_params(),
							'user_id'   => get_current_user_id(),
						),
						201
					);
				},
			)
		);
	}

	/**
	 * List operations should return a rest transport response with sanitized parameters.
	 */
	public function test_dispatch_list_forms_executes_read_flow() {
		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'list_forms',
			array(
				'params' => array(
					'search' => '  Contact  ',
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'rest', $result['transport'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'Contact', $result['data']['query']['search'] );
	}

	/**
	 * Operations requiring a form identifier should fail early when not provided.
	 */
	public function test_dispatch_get_form_fields_requires_identifier() {
		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'get_form_fields',
			array(),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_jetformbuilder_missing_id', $result->get_error_code() );
	}

	/**
	 * Fetching form fields should sanitize identifiers and query parameters.
	 */
	public function test_dispatch_get_form_fields_executes_read_flow() {
		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'get_form_fields',
			array(
				'id'     => ' form-22 ',
				'params' => array(
					'search' => '<b>Name</b>',
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'rest', $result['transport'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 'form-22', $result['data']['form_id'] );
		$this->assertSame( 'Name', $result['data']['query']['search'] );
	}

	/**
	 * Create submission operations should forward sanitized payloads and set the acting user.
	 */
	public function test_dispatch_create_submission_executes_create_flow() {
		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'create_submission',
			array(
				'id'     => ' 77 ',
				'params' => array(
					'fields' => array(
						'first_name' => '<i>Jane</i>',
						'notes'      => "Line one\nLine two",
					),
				),
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'rest', $result['transport'] );
		$this->assertSame( 201, $result['status'] );
		$this->assertSame( '77', $result['data']['form_id'] );
		$this->assertSame( $this->user_id, $result['data']['user_id'] );
		$this->assertSame( 'Jane', $result['data']['payload']['fields']['first_name'] );
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
						'operation' => 'list_forms',
					)
				),
				'response' => array(
					'code' => 200,
				),
			);
		};

		add_filter( 'pre_http_request', $http_interceptor, 10, 3 );

		$result = WP_MCP_AI_JetFormBuilder_Tool_Handlers::dispatch(
			'list_forms',
			array(
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
		$this->assertArrayHasKey( WP_MCP_AI_JetFormBuilder_Tool_Handlers::PROXY_HEADER, $captured_args['headers'] );

		$proxy_token = $captured_args['headers'][ WP_MCP_AI_JetFormBuilder_Tool_Handlers::PROXY_HEADER ];
		$this->assertNotEmpty( $proxy_token );

		$reflection = new ReflectionClass( WP_MCP_AI_JetFormBuilder_Tool_Handlers::class );
		$method     = $reflection->getMethod( 'get_proxy_transient_key' );
		$method->setAccessible( true );
		$transient_key = $method->invoke( null, $proxy_token );

		$payload = get_transient( $transient_key );
		$this->assertIsArray( $payload );
		$this->assertSame( $this->user_id, $payload['user_id'] );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/jet-form-builder/v1/forms/' );
		$request->set_header( WP_MCP_AI_JetFormBuilder_Tool_Handlers::PROXY_HEADER, $proxy_token );

		$server = rest_get_server();
		apply_filters( 'rest_authentication_errors', null, $request, $server );

		$this->assertSame( $this->user_id, get_current_user_id() );
		$this->assertFalse( get_transient( $transient_key ) );

		wp_set_current_user( $this->user_id );
	}
}
