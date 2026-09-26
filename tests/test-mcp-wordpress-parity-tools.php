<?php
/**
 * Tests for the mcp-wordpress parity tools (ported from docdyhr/mcp-wordpress).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Covers the 30 native tools ported from the mcp-wordpress MCP server:
 * comment CRUD, user CRUD, revisions/terms/media, site settings, application
 * passwords, and the SEO toolkit.
 */
class Test_MCP_WordPress_Parity_Tools extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// Application-password tests run without SSL; make them available.
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
	}

	/**
	 * Clean up test environment.
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_is_application_passwords_available' );
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * All 30 ported tools are registered in the registry map.
	 */
	public function test_all_ported_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$slugs = array(
			'list_comments',
			'get_comment',
			'create_comment',
			'update_comment',
			'delete_comment',
			'list_users',
			'create_user',
			'update_user',
			'delete_user',
			'get_post_revisions',
			'get_term',
			'delete_term',
			'get_media',
			'upload_media',
			'update_media',
			'delete_media',
			'get_site_settings',
			'update_site_settings',
			'list_application_passwords',
			'create_application_password',
			'delete_application_password',
			'seo_analyze_content',
			'seo_generate_schema',
			'seo_validate_schema',
			'seo_bulk_update_metadata',
			'seo_site_audit',
			'seo_test_integration',
			'seo_get_live_data',
			'seo_track_serp',
			'seo_keyword_research',
		);

		foreach ( $slugs as $slug ) {
			$this->assertTrue( $registry->is_tool_registered( $slug ), "Tool {$slug} should be registered." );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Comment tools.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The list_comments tool returns comments for an admin and forbids subscribers.
	 */
	public function test_list_comments() {
		$post_id = self::factory()->post->create();
		self::factory()->comment->create_post_comments( $post_id, 3 );

		$tool   = new WP_MCP_AI_Tool_List_Comments();
		$result = $tool->execute( array( 'post_id' => $post_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'comments', $result );
		$this->assertCount( 3, $result['comments'] );

		$denied = $tool->execute( array( 'post_id' => $post_id ), array( 'user_id' => $this->subscriber_id ) );
		$this->assertWPError( $denied );
		$this->assertSame( 'wp_mcp_ai_forbidden', $denied->get_error_code() );
	}

	/**
	 * The get_comment tool retrieves a single comment.
	 */
	public function test_get_comment() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$tool   = new WP_MCP_AI_Tool_Get_Comment();
		$result = $tool->execute( array( 'comment_id' => $comment_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertSame( $comment_id, $result['comment_id'] );

		$missing = $tool->execute( array( 'comment_id' => 999999 ), array( 'user_id' => $this->admin_id ) );
		$this->assertWPError( $missing );
		$this->assertSame( 'wp_mcp_ai_comment_not_found', $missing->get_error_code() );
	}

	/**
	 * The create_comment tool creates a comment on a post.
	 */
	public function test_create_comment() {
		$post_id = self::factory()->post->create();

		$tool   = new WP_MCP_AI_Tool_Create_Comment();
		$result = $tool->execute(
			array(
				'post_id' => $post_id,
				'content' => 'A thoughtful comment.',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertArrayHasKey( 'comment_id', $result );
		$this->assertGreaterThan( 0, $result['comment_id'] );

		$missing_post = $tool->execute(
			array(
				'post_id' => 999999,
				'content' => 'Nope.',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $missing_post );
	}

	/**
	 * The update_comment tool approves a pending comment via status.
	 */
	public function test_update_comment_status() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => 0,
			)
		);

		$tool   = new WP_MCP_AI_Tool_Update_Comment();
		$result = $tool->execute(
			array(
				'comment_id' => $comment_id,
				'status'     => 'approve',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertSame( 'approve', $result['status'] );
		$this->assertSame( '1', get_comment( $comment_id )->comment_approved );
	}

	/**
	 * The delete_comment tool moves a comment to trash and supports permanent deletion.
	 */
	public function test_delete_comment() {
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );

		$tool = new WP_MCP_AI_Tool_Delete_Comment();

		$trashed = $tool->execute( array( 'comment_id' => $comment_id ), array( 'user_id' => $this->admin_id ) );
		$this->assertFalse( $trashed['force'] );

		$forced = $tool->execute(
			array(
				'comment_id' => $comment_id,
				'force'      => true,
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $forced['force'] );
		$this->assertNull( get_comment( $comment_id ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * User tools.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The list_users tool returns users and forbids subscribers.
	 */
	public function test_list_users() {
		$tool   = new WP_MCP_AI_Tool_List_Users();
		$result = $tool->execute( array( 'roles' => array( 'administrator' ) ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'users', $result );
		$this->assertNotEmpty( $result['users'] );

		$denied = $tool->execute( array(), array( 'user_id' => $this->subscriber_id ) );
		$this->assertWPError( $denied );
		$this->assertSame( 'wp_mcp_ai_forbidden', $denied->get_error_code() );
	}

	/**
	 * The create_user tool creates a user with a role and rejects duplicates.
	 */
	public function test_create_user() {
		$tool   = new WP_MCP_AI_Tool_Create_User();
		$result = $tool->execute(
			array(
				'username' => 'parity_new_user',
				'email'    => 'parity@example.com',
				'password' => 'A-Str0ng-Pass!',
				'roles'    => array( 'editor' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertArrayHasKey( 'user_id', $result );
		$user = get_userdata( $result['user_id'] );
		$this->assertContains( 'editor', $user->roles );

		$duplicate = $tool->execute(
			array(
				'username' => 'parity_new_user',
				'email'    => 'other@example.com',
				'password' => 'A-Str0ng-Pass!',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $duplicate );
		$this->assertSame( 'wp_mcp_ai_username_exists', $duplicate->get_error_code() );
	}

	/**
	 * The update_user tool allows self-edits and admin edits.
	 */
	public function test_update_user() {
		$tool = new WP_MCP_AI_Tool_Update_User();

		$self_edit = $tool->execute(
			array(
				'user_id'      => $this->subscriber_id,
				'display_name' => 'Subscriber Prime',
			),
			array( 'user_id' => $this->subscriber_id )
		);
		$this->assertSame( $this->subscriber_id, $self_edit['user_id'] );
		$this->assertSame( 'Subscriber Prime', get_userdata( $this->subscriber_id )->display_name );
	}

	/**
	 * The delete_user tool deletes a user and blocks self-deletion.
	 */
	public function test_delete_user() {
		$tool = new WP_MCP_AI_Tool_Delete_User();

		$self = $tool->execute( array( 'user_id' => $this->admin_id ), array( 'user_id' => $this->admin_id ) );
		$this->assertWPError( $self );
		$this->assertSame( 'wp_mcp_ai_cannot_delete_self', $self->get_error_code() );

		$victim = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$result = $tool->execute( array( 'user_id' => $victim ), array( 'user_id' => $this->admin_id ) );
		$this->assertSame( $victim, $result['user_id'] );
		$this->assertFalse( get_userdata( $victim ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Revisions / terms / media.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The get_post_revisions tool lists revisions for a post.
	 */
	public function test_get_post_revisions() {
		$post_id = self::factory()->post->create( array( 'post_content' => 'First draft.' ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Second draft.',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Get_Post_Revisions();
		$result = $tool->execute( array( 'post_id' => $post_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'revisions', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	/**
	 * The get_term tool retrieves a term and delete_term removes it.
	 */
	public function test_get_and_delete_term() {
		$term_id = self::factory()->category->create( array( 'name' => 'Parity Category' ) );

		$get_tool = new WP_MCP_AI_Tool_Get_Term();
		$fetched  = $get_tool->execute(
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'category',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertSame( $term_id, $fetched['term_id'] );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Term();
		$deleted     = $delete_tool->execute(
			array(
				'term_id'  => $term_id,
				'taxonomy' => 'category',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $deleted['deleted'] );
		$this->assertNull( get_term( $term_id, 'category' ) );
	}

	/**
	 * The get_media tool returns attachment data.
	 */
	public function test_get_media() {
		$attachment_id = self::factory()->attachment->create_object(
			$this->build_tiny_image(),
			0,
			array(
				'post_mime_type' => 'image/png',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Get_Media();
		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertSame( $attachment_id, $result['attachment_id'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	/**
	 * The upload_media tool uploads base64 content and rejects non-uploads paths.
	 */
	public function test_upload_media_base64_and_path_guard() {
		$tool = new WP_MCP_AI_Tool_Upload_Media();

		$png_base64 = base64_encode( $this->build_tiny_image() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture.

		$result = $tool->execute(
			array(
				'base64'    => $png_base64,
				'filename'  => 'parity-upload.png',
				'mime_type' => 'image/png',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertSame( 'image/png', get_post( $result['attachment_id'] )->post_mime_type );

		// A path outside the uploads directory must be rejected. Write a
		// deterministic fixture file in the system temp dir (the WP core
		// directory has no guaranteed files across test environments).
		$outside_file = sys_get_temp_dir() . '/wp-mcp-ai-outside-uploads.txt';
		file_put_contents( $outside_file, 'outside uploads' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$outside = $tool->execute(
			array( 'file_path' => $outside_file ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $outside );
		$this->assertSame( 'wp_mcp_ai_path_outside_uploads', $outside->get_error_code() );

		unlink( $outside_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
	}

	/**
	 * The update_media tool writes alt text and delete_media removes the attachment.
	 */
	public function test_update_and_delete_media() {
		$attachment_id = self::factory()->attachment->create_object(
			$this->build_tiny_image(),
			0,
			array(
				'post_mime_type' => 'image/png',
			)
		);

		$update_tool = new WP_MCP_AI_Tool_Update_Media();
		$updated     = $update_tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'alt_text'      => 'A tiny test image',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertContains( 'alt_text', $updated['updated'] );
		$this->assertSame( 'A tiny test image', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Media();
		$deleted     = $delete_tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'force'         => true,
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $deleted['force'] );
		$this->assertNull( get_post( $attachment_id ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Site settings.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The get_site_settings tool returns settings for admins only.
	 */
	public function test_get_site_settings() {
		$tool = new WP_MCP_AI_Tool_Get_Site_Settings();

		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );
		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'title', $result['settings'] );

		$denied = $tool->execute( array(), array( 'user_id' => $this->subscriber_id ) );
		$this->assertWPError( $denied );
	}

	/**
	 * The update_site_settings tool validates before writing.
	 */
	public function test_update_site_settings() {
		// Elementor (loaded as an optional test plugin) syncs the site name
		// into its kit on blogname updates and throws when no kit exists —
		// isolate the core option hooks for this test.
		remove_all_actions( 'update_option_blogname' );
		remove_all_actions( 'update_option_blogdescription' );

		$tool = new WP_MCP_AI_Tool_Update_Site_Settings();

		$result = $tool->execute( array( 'title' => 'Parity Site Title' ), array( 'user_id' => $this->admin_id ) );
		$this->assertContains( 'blogname', $result['updated'] );
		$this->assertSame( 'Parity Site Title', get_option( 'blogname' ) );

		$bad_timezone = $tool->execute( array( 'timezone' => 'Mars/Olympus_Mons' ), array( 'user_id' => $this->admin_id ) );
		$this->assertWPError( $bad_timezone );
		$this->assertSame( 'wp_mcp_ai_invalid_timezone', $bad_timezone->get_error_code() );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Application passwords.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The create_application_password tool returns the one-time password once.
	 */
	public function test_create_application_password() {
		$tool   = new WP_MCP_AI_Tool_Create_Application_Password();
		$result = $tool->execute( array( 'name' => 'parity-test-app' ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'password', $result );
		$this->assertNotEmpty( $result['password'] );

		// Sensitive field declaration keeps the password out of logs.
		$this->assertContains( 'password', $tool->get_sensitive_result_fields() );
	}

	/**
	 * The list tool + delete round-trip for application passwords.
	 */
	public function test_list_and_delete_application_password() {
		$created = WP_Application_Passwords::create_new_application_password(
			$this->admin_id,
			array( 'name' => 'parity-roundtrip' )
		);

		$list_tool = new WP_MCP_AI_Tool_List_Application_Passwords();
		$listed    = $list_tool->execute( array( 'user_id' => $this->admin_id ), array( 'user_id' => $this->admin_id ) );
		$this->assertNotEmpty( $listed['passwords'] );

		$delete_tool = new WP_MCP_AI_Tool_Delete_Application_Password();
		$revoked     = $delete_tool->execute(
			array(
				'user_id' => $this->admin_id,
				'uuid'    => $created[1]['uuid'],
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $revoked['revoked'] );
	}

	/*
	 * ---------------------------------------------------------------------
	 * SEO toolkit.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The seo_analyze_content tool computes readability and keyword metrics.
	 */
	public function test_seo_analyze_content() {
		$content = 'This is the first sentence of a sample post. This is the second sentence, ' .
			'about WordPress SEO, WordPress plugins, and WordPress themes. A third sentence follows. ' .
			'And a fourth, a fifth, and a sixth. One more: WordPress!';
		$post_id = self::factory()->post->create( array( 'post_content' => $content ) );

		$tool   = new WP_MCP_AI_Tool_SEO_Analyze_Content();
		$result = $tool->execute(
			array(
				'post_id'        => $post_id,
				'analysis_type'  => 'full',
				'focus_keywords' => array( 'WordPress' ),
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertArrayHasKey( 'analysis', $result );
		$this->assertGreaterThan( 0, $result['scores']['word_count'] );
		$this->assertArrayHasKey( 'keywords', $result['analysis'] );
	}

	/**
	 * The seo_generate_schema tool produces JSON-LD and rejects FAQPage without questions.
	 */
	public function test_seo_generate_schema() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'A schema-able post',
				'post_content' => 'Body text here.',
			)
		);

		$tool   = new WP_MCP_AI_Tool_SEO_Generate_Schema();
		$result = $tool->execute(
			array(
				'post_id'     => $post_id,
				'schema_type' => 'Article',
			),
			array( 'user_id' => $this->admin_id )
		);

		$this->assertArrayHasKey( 'schema', $result );
		$this->assertSame( 'Article', $result['schema']['@type'] );
		$this->assertArrayHasKey( 'schema_json', $result );

		$faq_missing = $tool->execute(
			array(
				'post_id'     => $post_id,
				'schema_type' => 'FAQPage',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $faq_missing );
	}

	/**
	 * The seo_validate_schema tool validates a schema array.
	 */
	public function test_seo_validate_schema() {
		$tool = new WP_MCP_AI_Tool_SEO_Validate_Schema();

		$valid = $tool->execute(
			array(
				'schema'      => array(
					'@context'      => 'https://schema.org',
					'@type'         => 'Article',
					'headline'      => 'A headline',
					'datePublished' => '2026-09-25',
				),
				'schema_type' => 'Article',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $valid['valid'] );

		$invalid = $tool->execute(
			array(
				'schema'      => array( '@type' => 'Article' ),
				'schema_type' => 'Article',
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertFalse( $invalid['valid'] );
		$this->assertNotEmpty( $invalid['errors'] );
	}

	/**
	 * The seo_bulk_update_metadata tool honours dry_run and writes on commit.
	 */
	public function test_seo_bulk_update_metadata() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original Title' ) );

		$tool = new WP_MCP_AI_Tool_SEO_Bulk_Update_Metadata();

		$dry = $tool->execute(
			array(
				'post_ids' => array( $post_id ),
				'updates'  => array( 'title' => 'New Title' ),
				'dry_run'  => true,
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertTrue( $dry['dry_run'] );
		$this->assertSame( 'Original Title', get_post( $post_id )->post_title );

		$committed = $tool->execute(
			array(
				'post_ids' => array( $post_id ),
				'updates'  => array( 'title' => 'New Title' ),
				'dry_run'  => false,
			),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertSame( 'New Title', get_post( $post_id )->post_title );
	}

	/**
	 * The seo_site_audit tool audits the site's published posts.
	 */
	public function test_seo_site_audit() {
		self::factory()->post->create( array( 'post_title' => 'Audited Post One' ) );
		self::factory()->post->create( array( 'post_title' => 'Audited Post Two' ) );

		$tool   = new WP_MCP_AI_Tool_SEO_Site_Audit();
		$result = $tool->execute( array( 'max_pages' => 10 ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'summary', $result );
		$this->assertGreaterThanOrEqual( 2, $result['pages_audited'] );
	}

	/**
	 * The seo_test_integration tool returns the plugin detection payload.
	 */
	public function test_seo_test_integration() {
		$tool   = new WP_MCP_AI_Tool_SEO_Test_Integration();
		$result = $tool->execute( array(), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'plugins', $result );
	}

	/**
	 * The seo_get_live_data tool falls back to core title/excerpt without an SEO plugin.
	 */
	public function test_seo_get_live_data() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Live Data Post',
				'post_excerpt' => 'An excerpt for meta.',
			)
		);

		$tool   = new WP_MCP_AI_Tool_SEO_Get_Live_Data();
		$result = $tool->execute( array( 'post_id' => $post_id ), array( 'user_id' => $this->admin_id ) );

		$this->assertArrayHasKey( 'seo_data', $result );
	}

	/**
	 * The seo_track_serp tool parses a mocked Brave response into positions.
	 */
	public function test_seo_track_serp() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'brave_search_api_key' => 'test_api_key_123' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$http_stub = static function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.search.brave.com' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'web' => array(
								'results' => array(
									array(
										'url'         => 'https://competitor.example.com/a',
										'title'       => 'Competitor A',
										'description' => 'Rival content.',
									),
									array(
										'url'         => 'https://example.org/target-page',
										'title'       => 'Our Target',
										'description' => 'Our content.',
									),
								),
							),
						)
					),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_SEO_Track_Serp();
		$result = $tool->execute(
			array(
				'keywords' => array( 'parity keyword' ),
				'url'      => 'https://example.org/target-page',
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertArrayHasKey( 'results', $result );
		$this->assertSame( 2, $result['results'][0]['position'] );
	}

	/**
	 * The seo_keyword_research tool extracts keywords from a mocked Brave response.
	 */
	public function test_seo_keyword_research() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'brave_search_api_key' => 'test_api_key_123' )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$http_stub = static function ( $preempt, $args, $url ) {
			if ( strpos( $url, 'api.search.brave.com' ) !== false ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'web' => array(
								'results' => array(
									array(
										'url'         => 'https://a.example.com/1',
										'title'       => 'WordPress performance tuning guide',
										'description' => 'How to tune WordPress performance tuning for speed.',
									),
									array(
										'url'         => 'https://b.example.com/2',
										'title'       => 'Performance tuning basics',
										'description' => 'Learn performance tuning fundamentals.',
									),
								),
							),
						)
					),
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $http_stub, 10, 3 );

		$tool   = new WP_MCP_AI_Tool_SEO_Keyword_Research();
		$result = $tool->execute(
			array(
				'seed_keyword'      => 'performance tuning',
				'include_questions' => false,
			),
			array( 'user_id' => $this->admin_id )
		);

		remove_filter( 'pre_http_request', $http_stub, 10 );

		$this->assertArrayHasKey( 'keywords', $result );
		$this->assertNotEmpty( $result['keywords'] );
	}

	/**
	 * SERP and keyword tools require a Brave API key.
	 */
	public function test_seo_network_tools_require_api_key() {
		update_option( 'wp_mcp_ai_settings', array() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$serp = ( new WP_MCP_AI_Tool_SEO_Track_Serp() )->execute(
			array( 'keywords' => array( 'parity' ) ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $serp );
		$this->assertSame( 'wp_mcp_ai_search_missing_api_key', $serp->get_error_code() );

		$research = ( new WP_MCP_AI_Tool_SEO_Keyword_Research() )->execute(
			array( 'seed_keyword' => 'parity' ),
			array( 'user_id' => $this->admin_id )
		);
		$this->assertWPError( $research );
		$this->assertSame( 'wp_mcp_ai_search_missing_api_key', $research->get_error_code() );
	}

	/**
	 * Builds the smallest valid PNG bytes for media tests.
	 *
	 * @return string
	 */
	private function build_tiny_image() {
		// 1x1 transparent PNG.
		$bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.

		return (string) $bytes;
	}
}
