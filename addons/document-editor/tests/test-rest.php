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
		// The credential stub (if the base plugin is absent) is declared at file
		// load time below; reset its mutable state for a clean run each test.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) && property_exists( 'WP_MCP_AI_Credentials', 'tokens' ) ) {
			WP_MCP_AI_Credentials::$tokens     = array();
			WP_MCP_AI_Credentials::$valid      = true;
			WP_MCP_AI_Credentials::$created_by = 1;
		}
	}

	/**
	 * Test that a validated credential maps to its issuing user's edit_posts cap.
	 */
	public function test_edit_permission_granted_for_credential_owner_with_edit_posts() {
		if ( ! property_exists( 'WP_MCP_AI_Credentials', 'created_by' ) ) {
			$this->markTestSkipped( 'Base plugin credential class present; stub not in use.' );
		}

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		WP_MCP_AI_Credentials::$created_by = $editor_id;
		WP_MCP_AI_Credentials::$valid      = true;
		wp_set_current_user( 0 ); // No logged-in cookie user.

		$request = new WP_REST_Request( 'GET', '/nvoos-document-editor/v1/documents' );
		$request->set_header( 'Authorization', 'Bearer ' . WP_MCP_AI_Credentials::seed_token() );

		$this->assertTrue( NV_oOS_Document_Editor_REST::edit_permission( $request ) );
	}

	/**
	 * Test that a credential owner lacking edit_posts is denied.
	 */
	public function test_edit_permission_denied_for_credential_owner_without_edit_posts() {
		if ( ! property_exists( 'WP_MCP_AI_Credentials', 'created_by' ) ) {
			$this->markTestSkipped( 'Base plugin credential class present; stub not in use.' );
		}

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		WP_MCP_AI_Credentials::$created_by = $subscriber_id;
		WP_MCP_AI_Credentials::$valid      = true;
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/nvoos-document-editor/v1/documents' );
		$request->set_header( 'Authorization', 'Bearer ' . WP_MCP_AI_Credentials::seed_token() );

		$result = NV_oOS_Document_Editor_REST::edit_permission( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
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

if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
	/**
	 * Test-only stub of the base plugin's credential API.
	 *
	 * The document-editor addon accepts assistant credentials issued by the
	 * base plugin (`WP_MCP_AI_Credentials`), guarded by `class_exists()` so the
	 * integration stays optional. The addon's own test environment may not load
	 * the base plugin; this stub supplies the two static methods the controller
	 * calls, with a configurable issuing user so tests can exercise the
	 * capability-mapping behaviour.
	 */
	class WP_MCP_AI_Credentials {

		/**
		 * In-test token table (identifier => secret).
		 *
		 * @var array<string,string>
		 */
		public static $tokens = array();

		/**
		 * Whether validate_token() should succeed.
		 *
		 * @var bool
		 */
		public static $valid = true;

		/**
		 * User ID reported as the credential issuer.
		 *
		 * @var int
		 */
		public static $created_by = 1;

		/**
		 * Seed a valid token.
		 *
		 * @return string Full token (cred_identifier.SECRET).
		 */
		public static function seed_token() {
			$identifier                  = 'cred_' . strtolower( wp_generate_password( 8, false, false ) );
			$secret                      = wp_generate_password( 32, false, false );
			self::$tokens[ $identifier ] = $secret;
			return $identifier . '.' . $secret;
		}

		/**
		 * Check the base plugin's credential format.
		 *
		 * @param string $token Token candidate.
		 * @return bool
		 */
		public static function is_token_format( $token ) {
			return is_string( $token ) && 1 === preg_match( '/^cred_[a-z0-9]+\..+$/', $token );
		}

		/**
		 * Validate a token against the seed config.
		 *
		 * @param string $token Raw token.
		 * @return array|WP_Error Credential metadata when valid.
		 */
		public static function validate_token( $token ) {
			if ( ! self::$valid ) {
				return new WP_Error( 'wp_mcp_ai_invalid_token', 'The provided credential token is invalid.', array( 'status' => 401 ) );
			}
			return array(
				'assistant_id'  => 1,
				'credential_id' => 'cred_test',
				'created_by'    => self::$created_by,
			);
		}
	}
}
