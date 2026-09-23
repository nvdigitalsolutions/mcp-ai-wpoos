<?php
/**
 * Tests for MCP App chat tool exposure.
 *
 * Verifies that bridged MCP App tools — registered dynamically at chat time
 * and therefore absent from the Tools metabox selection — are appended to the
 * LLM tools payload via the wp_mcp_ai_chat_effective_tools filter seam.
 *
 * @package WP_MCP_AI
 * @since   1.9.2
 */

/**
 * Tests for the chat tool exposure wiring.
 */
class Test_MCP_App_Chat_Exposure extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated capability checks.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/class-wp-mcp-ai-mcp-app-registry.php';
		}
		if ( ! function_exists( 'wp_mcp_ai_mcp_apps_expose_tools' ) ) {
			require_once WP_MCP_AI_PATH . 'addons/pro/includes/mcp-apps/mcp-apps-init.php';
		}

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Exposure Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_chat_effective_tools' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test the base filter seam: subscribers can append dynamic tool slugs to
	 * the LLM payload even when they are not in the assistant's saved
	 * selection.
	 */
	public function test_effective_tools_filter_seam_appends_dynamic_slugs() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$all_tools = $registry->get_tools();
		$this->assertNotEmpty( $all_tools, 'The registry must have tools registered in the test environment.' );

		$tool_slugs = array();
		foreach ( $all_tools as $tool ) {
			if ( method_exists( $tool, 'get_slug' ) ) {
				$tool_slugs[] = $tool->get_slug();
			}
		}

		$this->assertGreaterThanOrEqual( 2, count( $tool_slugs ) );

		$configured_slug = $tool_slugs[0];
		$appended_slug   = $tool_slugs[1];

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$rest = new WP_MCP_AI_REST( $registry, $mock_client );

		add_filter(
			'wp_mcp_ai_chat_effective_tools',
			static function ( $slugs ) use ( $appended_slug ) {
				$slugs[] = $appended_slug;
				return $slugs;
			},
			10,
			1
		);

		$ref = new ReflectionMethod( WP_MCP_AI_REST::class, 'build_tools_payload' );
		$ref->setAccessible( true );

		$payload = $ref->invoke(
			$rest,
			array(
				'tools' => array( $configured_slug ),
			)
		);

		$this->assertIsArray( $payload );

		$names = array();
		foreach ( $payload as $entry ) {
			if ( isset( $entry['function']['name'] ) ) {
				$names[] = $entry['function']['name'];
			}
		}

		$this->assertContains( $configured_slug, $names );
		$this->assertContains( $appended_slug, $names, 'Slugs appended via the effective-tools filter must reach the payload.' );
	}

	/**
	 * Test the Pro exposure callback merges bridged MCP App slugs for the
	 * assistant into the effective tool list.
	 */
	public function test_expose_tools_merges_bridged_slugs() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $pre, $url );
				$payload = json_decode( isset( $args['body'] ) ? $args['body'] : '', true );
				$method  = is_array( $payload ) && isset( $payload['method'] ) ? $payload['method'] : '';

				$result = array( 'serverInfo' => array( 'name' => 'Elementor MCP' ) );
				if ( 'tools/list' === $method ) {
					$result = array(
						'tools' => array(
							array(
								'name'        => 'read_page',
								'description' => 'Read an Elementor page.',
							),
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'jsonrpc' => '2.0',
							'id'      => 1,
							'result'  => $result,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);

		$registry = WP_MCP_AI_MCP_App_Registry::get_instance();
		$registry->save_apps(
			$this->assistant_id,
			array(
				array(
					'label'      => 'Elementor',
					'server_url' => 'https://example.com/mcp',
					'auth_type'  => 'none',
					'enabled'    => true,
				),
			)
		);

		$effective = wp_mcp_ai_mcp_apps_expose_tools(
			array( 'get_current_date_time' ),
			array( 'ID' => $this->assistant_id )
		);

		$this->assertContains( 'get_current_date_time', $effective, 'Existing tool slugs must be preserved.' );
		$this->assertContains( 'mcp_app_elementor_read_page', $effective, 'Bridged MCP App slugs must be appended.' );
	}

	/**
	 * Test the exposure callback is a no-op without assistant context and
	 * that the filter is registered on the base seam.
	 */
	public function test_expose_tools_without_assistant_context_is_noop() {
		$this->assertSame(
			10,
			has_filter( 'wp_mcp_ai_chat_effective_tools', 'wp_mcp_ai_mcp_apps_expose_tools' ),
			'The exposure callback must be registered on the effective-tools seam.'
		);

		$slugs = array( 'get_current_date_time' );

		$result = wp_mcp_ai_mcp_apps_expose_tools( $slugs, array() );

		$this->assertSame( $slugs, $result );
	}
}
