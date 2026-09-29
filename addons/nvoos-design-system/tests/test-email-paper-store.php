<?php
/**
 * Test: Email Paper Store Bridge.
 *
 * @package NV_oOS_Design_System
 */

/**
 * Tests for the Paper Store template bridge (mirror + import round trip).
 *
 * Points the Paper Store root at a per-test temp directory and skips when the
 * Paper Store subsystem is not loaded in the current environment.
 *
 * @covers NV_oOS_Design_System_Email_Paper_Store
 */
class Test_Email_Paper_Store extends WP_UnitTestCase {

	/**
	 * Per-test Paper Store root directory.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Template post ID used by the tests.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Set up a temp Paper Store root and a published template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) ) {
			$this->markTestSkipped( 'Paper Store is not loaded in this environment.' );
		}

		$this->root = sys_get_temp_dir() . '/nds-paper-test-' . uniqid();

		add_filter(
			'wp_mcp_ai_paper_store_root',
			function () {
				return $this->root;
			}
		);

		WP_MCP_AI_Paper_Store_Manager::get_instance()->reset();

		NV_oOS_Design_System_Email_Template_CPT::register();

		$this->template_id = NV_oOS_Design_System_Email_Template_CPT::upsert(
			'ps-test-template',
			'PS Test Template',
			'<!-- nds-email-wrapper:ps-test --><!DOCTYPE html><html lang="en"><body><h1>Hi</h1></body></html>',
			'custom',
			'full',
			array(),
			'publish'
		);
	}

	/**
	 * Clean up the temp root and template post.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Paper_Store_Manager' ) && $this->root ) {
			WP_MCP_AI_Paper_Store_Manager::get_instance()->reset();

			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Test cleanup of a temp directory.
			$wp_filesystem->delete( $this->root, true );
		}

		if ( $this->template_id ) {
			wp_delete_post( $this->template_id, true );
		}

		parent::tearDown();
	}

	/**
	 * Mirroring creates (and updates) a Paper Store record.
	 *
	 * @return void
	 */
	public function test_mirror_creates_record() {
		$result = NV_oOS_Design_System_Email_Paper_Store::mirror( $this->template_id );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'email-templates', $result['collection'] );
		$this->assertSame( 'nds-ps-test-template', $result['record_id'] );

		$repo = WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository( 'email-templates' );
		$this->assertTrue( $repo->exists( 'nds-ps-test-template' ) );

		$record = $repo->find( 'nds-ps-test-template' );
		$this->assertSame( 'PS Test Template', $record['title'] );
		$this->assertNotEmpty( $record['body']['html'] );
		$this->assertContains( 'email-template', $record['tags'] );
	}

	/**
	 * Mirroring a non-template post ID is rejected.
	 *
	 * @return void
	 */
	public function test_mirror_rejects_non_template() {
		$post_id = self::factory()->post->create();

		$result = NV_oOS_Design_System_Email_Paper_Store::mirror( $post_id );

		$this->assertWPError( $result );
	}

	/**
	 * Import materialises a Paper Store record as a draft template.
	 *
	 * @return void
	 */
	public function test_import_record_round_trip() {
		NV_oOS_Design_System_Email_Paper_Store::mirror( $this->template_id );

		$result = NV_oOS_Design_System_Email_Paper_Store::import_record( 'email-templates', 'nds-ps-test-template' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'ps-ps-test-template', $result['slug'] );

		$post = NV_oOS_Design_System_Email_Template_CPT::get_by_slug( 'ps-ps-test-template' );
		$this->assertNotNull( $post );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertStringContainsString( '<!-- nds-email-wrapper:ps-test -->', $post->post_content );
	}

	/**
	 * Importing an unknown record ID fails with a WP_Error.
	 *
	 * @return void
	 */
	public function test_import_unknown_record_fails() {
		$result = NV_oOS_Design_System_Email_Paper_Store::import_record( 'email-templates', 'nds-does-not-exist' );

		$this->assertWPError( $result );
	}

	/**
	 * Custom collections are honoured.
	 *
	 * @return void
	 */
	public function test_mirror_custom_collection() {
		$result = NV_oOS_Design_System_Email_Paper_Store::mirror( $this->template_id, 'agency-templates' );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'agency-templates', $result['collection'] );

		$repo = WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository( 'agency-templates' );
		$this->assertTrue( $repo->exists( 'nds-ps-test-template' ) );
	}
}
