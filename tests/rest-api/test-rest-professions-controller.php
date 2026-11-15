<?php
/**
 * Tests the WordPress profession catalogue REST surface (090-p W1).
 *
 * The console's `professions` endpoint was blank on WordPress before this
 * route existed; the Laravel controller is the shape reference. These tests
 * pin the list/show envelopes, the admin gate, and the 404.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @since     1.2.22
 */
class WP_MCP_AI_REST_Professions_Controller_Test extends WP_UnitTestCase {

	/**
	 * Profession post created for the case.
	 *
	 * @var int
	 */
	private $profession_id = 0;

	/**
	 * Create one profession and reset the repository cache.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->profession_id = wp_insert_post(
			array(
				'post_type'    => WP_MCP_AI_Profession_CPT::POST_TYPE,
				'post_title'   => 'Test Solicitor',
				'post_name'    => 'test-solicitor',
				'post_status'  => 'publish',
				'post_content' => 'Handles conveyancing and probate.',
			)
		);

		$this->assertNotWPError( $this->profession_id );

		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_CATEGORY, 'legal' );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_EXPERTISE, array( 'Conveyancing', 'Probate' ) );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_DEFAULT_TOOLS, array( 'get_post', 'search_content' ) );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_ROLE_DESCRIPTION, 'A solicitor.' );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_WARNINGS, array( 'Not legal advice.' ) );
		update_post_meta( $this->profession_id, WP_MCP_AI_Profession_CPT::META_KNOWLEDGE_BASE, 'Legal KB.' );

		wp_cache_flush();
	}

	/**
	 * Anonymous sessions are refused.
	 */
	public function test_anonymous_is_forbidden() {
		wp_set_current_user( 0 );

		$result = ( new WP_MCP_AI_REST_Professions_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Editors cannot read the admin catalogue.
	 */
	public function test_editor_is_forbidden() {
		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$result = ( new WP_MCP_AI_REST_Professions_Controller() )->permissions_check();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Administrators can read it.
	 */
	public function test_administrator_is_allowed() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( ( new WP_MCP_AI_REST_Professions_Controller() )->permissions_check() );
	}

	/**
	 * The list mirrors the Laravel `ProfessionController@index` shape.
	 */
	public function test_index_returns_the_console_shape() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = ( new WP_MCP_AI_REST_Professions_Controller() )->index();
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'professions', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'catalogue_version', $data );
		$this->assertIsArray( $data['professions'] );
		$this->assertSame( count( $data['professions'] ), $data['total'] );
		$this->assertGreaterThan( 0, $data['catalogue_version'] );

		$row = null;
		foreach ( $data['professions'] as $candidate ) {
			if ( 'test-solicitor' === $candidate['slug'] ) {
				$row = $candidate;
				break;
			}
		}

		$this->assertNotNull( $row, 'The seeded profession is missing from the list.' );
		$this->assertSame( 'Test Solicitor', $row['title'] );
		$this->assertSame( 'Legal', $row['category'] );
		$this->assertSame( 'legal', $row['category_slug'] );
		$this->assertSame( 'Handles conveyancing and probate.', $row['description'] );
		$this->assertSame( array( 'Conveyancing', 'Probate' ), $row['expertise'] );
		$this->assertSame( 2, $row['tools_count'] );
	}

	/**
	 * The detail response carries the assistant-creation fields.
	 */
	public function test_show_returns_the_creation_detail() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/professions/test-solicitor' );
		$request->set_param( 'slug', 'test-solicitor' );

		$response = ( new WP_MCP_AI_REST_Professions_Controller() )->show( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'test-solicitor', $data['data']['slug'] );
		$this->assertSame( 'A solicitor.', $data['data']['role_description'] );
		$this->assertSame( array( 'Not legal advice.' ), $data['data']['warnings'] );
		$this->assertSame( 'Legal KB.', $data['data']['knowledge_base'] );
		$this->assertSame( array( 'get_post', 'search_content' ), $data['data']['recommended_tools'] );
	}

	/**
	 * A missing slug is a typed 404, not an empty success.
	 */
	public function test_show_missing_slug_is_404() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/professions/no-such-profession' );
		$request->set_param( 'slug', 'no-such-profession' );

		$result = ( new WP_MCP_AI_REST_Professions_Controller() )->show( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'profession_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * The routes are registered under mcp-ai/v1 — the manifest key depends on
	 * the literal path.
	 */
	public function test_routes_are_registered() {
		$GLOBALS['wp_rest_server'] = null;
		$routes                    = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/professions', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/professions/(?P<slug>[a-z0-9_-]+)', $routes );
	}
}
