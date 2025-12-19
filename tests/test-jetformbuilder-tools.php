<?php
/**
 * Tests covering JetFormBuilder tool wrappers.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php';

class WP_MCP_AI_JetFormBuilder_Tools_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used during testing.
	 *
	 * @var int
	 */
	protected $user_id;

	protected function setUp(): void {
		parent::setUp();

		$this->user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );

		add_filter( 'wp_mcp_ai_jetformbuilder_is_available', '__return_true' );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mcp_ai_jetformbuilder_is_available', '__return_true' );
		parent::tearDown();
	}

	/**
	 * Listing forms should enforce capability checks.
	 */
	public function test_forms_tool_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$tool    = new WP_MCP_AI_Tool_Get_JetFormBuilder_Forms();

		$result = $tool->execute( array(), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Forms should be normalised into concise summaries.
	 */
	public function test_forms_tool_returns_sanitized_payload() {
		$tool     = new WP_MCP_AI_Tool_Get_JetFormBuilder_Forms();
		$captured = null;

		$http_filter = function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $parsed_args,
				'url'  => $url,
			);

			return array(
				'headers'  => array( 'X-WP-Total' => 12 ),
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						array(
							'id'           => 23,
							'title'        => array( 'rendered' => 'Contact Form' ),
							'slug'         => 'contact-form',
							'status'       => 'publish',
							'modified_gmt' => '2024-01-02 03:04:05',
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$result = $tool->execute(
			array(
				'search'    => '  <b>Contact</b>  ',
				'limit'     => 3,
				'status'    => 'publish',
				'transport' => 'http',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertIsArray( $captured );
		$parsed_url = wp_parse_url( $captured['url'] );
		$this->assertSame( 'GET', strtoupper( $captured['args']['method'] ) );

		$query_args = array();
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_args );
		}

		$this->assertSame( 3, (int) $query_args['per_page'] );
		$this->assertSame( 'Contact', $query_args['search'] );
		$this->assertSame( 'publish', $query_args['status'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'http', $result['transport'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 1, $result['forms'] );
		$this->assertSame( 12, $result['total'] );

		$form = $result['forms'][0];
		$this->assertSame( 23, $form['id'] );
		$this->assertSame( 'Contact Form', $form['label'] );
		$this->assertSame( 'contact-form', $form['slug'] );
		$this->assertSame( 'publish', $form['status'] );
		$this->assertSame( mysql2date( DATE_W3C, '2024-01-02 03:04:05', false ), $form['updated_at'] );
		$this->assertSame( '[jet_form_builder id="23"]', $form['shortcode'] );
	}

	/**
	 * Submissions require a form identifier.
	 */
	public function test_submissions_tool_requires_form_id() {
		$tool = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();

		$result = $tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_form_id', $result->get_error_code() );
	}

	/**
	 * Submissions should enforce capability checks.
	 */
	public function test_submissions_tool_requires_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$tool    = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();

		$result = $tool->execute( array( 'form_id' => '42' ), array( 'user_id' => $user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Submissions should be normalised with field snapshots.
	 */
	public function test_submissions_tool_returns_records() {
		$tool     = new WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions();
		$captured = null;

		$http_filter = function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
			$captured = array(
				'args' => $parsed_args,
				'url'  => $url,
			);

			return array(
				'headers'  => array( 'X-WP-Total' => 27 ),
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						array(
							'id'         => 78,
							'status'     => 'viewed',
							'created_at' => '2024-05-01 10:00:00',
							'fields'     => array(
								array(
									'name'  => 'email',
									'label' => 'Email Address',
									'value' => 'user@example.com',
								),
								array(
									'name'  => 'message',
									'value' => "Hello\nWorld",
								),
							),
						),
					)
				),
			);
		};

		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$result = $tool->execute(
			array(
				'form_id'   => 45,
				'limit'     => 1,
				'status'    => 'viewed',
				'transport' => 'http',
			),
			array( 'user_id' => $this->user_id )
		);

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertIsArray( $captured );
		$parsed_url = wp_parse_url( $captured['url'] );

		$query_args = array();
		if ( isset( $parsed_url['query'] ) ) {
			parse_str( $parsed_url['query'], $query_args );
		}

		$route_path     = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$rest_route     = isset( $query_args['rest_route'] ) ? $query_args['rest_route'] : '';
		$expected_route = '/jet-form-builder/v1/forms/45/records/';

		$path_matches = '' !== $route_path && substr( $route_path, -strlen( $expected_route ) ) === $expected_route;

		$this->assertTrue( $path_matches || $expected_route === $rest_route );

		$this->assertSame( 1, (int) $query_args['per_page'] );
		$this->assertSame( 'viewed', $query_args['status'] );

		$this->assertIsArray( $result );
		$this->assertSame( 'http', $result['transport'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '45', $result['form_id'] );
		$this->assertCount( 1, $result['submissions'] );
		$this->assertSame( 27, $result['total'] );

		$submission = $result['submissions'][0];
		$this->assertSame( 78, $submission['id'] );
		$this->assertSame( 'viewed', $submission['status'] );
		$this->assertSame( mysql2date( DATE_W3C, '2024-05-01 10:00:00', false ), $submission['created_at'] );
		$this->assertCount( 2, $submission['fields'] );
		$this->assertSame( 'email', $submission['fields'][0]['name'] );
		$this->assertSame( 'Email Address', $submission['fields'][0]['label'] );
		$this->assertSame( 'user@example.com', $submission['fields'][0]['value'] );
		$this->assertSame( 'message', $submission['fields'][1]['name'] );
		$this->assertSame( 'Message', $submission['fields'][1]['label'] );
		$this->assertSame( 'Hello World', $submission['fields'][1]['value'] );
	}
}
