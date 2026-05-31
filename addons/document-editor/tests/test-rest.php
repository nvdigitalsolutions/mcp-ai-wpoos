<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Document_Editor
 */
class Test_Document_Editor_REST extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_DOCUMENT_EDITOR_VERSION' ) ) {
			define( 'NVOOS_DOCUMENT_EDITOR_VERSION', '0.1.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-document-editor-rest.php';
		// Ensure the post type exists for CRUD tests.
		if ( ! post_type_exists( NV_oOS_Document_Editor_REST::POST_TYPE ) ) {
			register_post_type(
				NV_oOS_Document_Editor_REST::POST_TYPE,
				array(
					'public'          => false,
					'supports'        => array( 'title', 'editor' ),
					'capability_type' => 'post',
					'map_meta_cap'    => true,
				)
			);
		}
	}

	/**
	 * Test that health endpoint requires manage_options capability.
	 */
	public function test_health_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Document_Editor_REST::admin_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that edit permission is denied for subscriber role.
	 */
	public function test_edit_permission_denied_for_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Document_Editor_REST::edit_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that edit permission is granted for editor role.
	 */
	public function test_edit_permission_granted_for_editor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( NV_oOS_Document_Editor_REST::edit_permission() );
	}

	/**
	 * Test that a document can be created and retrieved.
	 */
	public function test_create_and_get_document() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$request = new WP_REST_Request( 'POST', '/nvoos-document-editor/v1/documents' );
		$request->set_param( 'title', 'Test Doc' );
		$request->set_param( 'content', '<p>Hello world</p>' );

		$response = NV_oOS_Document_Editor_REST::create_document( $request );
		$this->assertNotInstanceOf( 'WP_Error', $response );
		$data = $response->get_data();
		$this->assertSame( 'Test Doc', $data['title'] );
		$id = $data['id'];

		// Get it back.
		$get = new WP_REST_Request( 'GET', '/nvoos-document-editor/v1/documents/' . $id );
		$get->set_param( 'id', $id );
		$got = NV_oOS_Document_Editor_REST::get_document( $get );
		$this->assertSame( 'Test Doc', $got->get_data()['title'] );
	}

	/**
	 * Test that a document can be deleted.
	 */
	public function test_delete_document() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$post_id = wp_insert_post(
			array(
				'post_type'    => NV_oOS_Document_Editor_REST::POST_TYPE,
				'post_status'  => 'publish',
				'post_title'   => 'To Delete',
				'post_content' => '',
			)
		);

		$del = new WP_REST_Request( 'DELETE', '/nvoos-document-editor/v1/documents/' . $post_id );
		$del->set_param( 'id', $post_id );
		$result = NV_oOS_Document_Editor_REST::delete_document( $del );
		$data   = $result->get_data();
		$this->assertTrue( $data['deleted'] );
	}

	/**
	 * Test that getting a nonexistent document returns a 404 error.
	 */
	public function test_get_nonexistent_document_returns_404() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$get = new WP_REST_Request( 'GET', '/nvoos-document-editor/v1/documents/99999' );
		$get->set_param( 'id', 99999 );
		$result = NV_oOS_Document_Editor_REST::get_document( $get );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
