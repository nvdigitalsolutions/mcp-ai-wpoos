<?php
/**
 * Hardening regression tests for the Media Toolkit.
 *
 * Covers the failure classes found in the 2026-10-04 media toolkit audit:
 * nested tool calls treating WP_Error results as arrays (fatal
 * "Cannot use object of type WP_Error as array"), non-canonical
 * `array( 'success' => false, ... )` envelopes, unvalidated year_month
 * path input, and unvalidated enum arguments.
 *
 * @package WP_MCP_AI
 */

require_once __DIR__ . '/class-wp-mcp-ai-media-toolkit-stub.php';

/**
 * Test Media Toolkit hardening regressions.
 */
class Test_Media_Toolkit_Hardening extends WP_UnitTestCase {
	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user;

	/**
	 * Test attachment IDs.
	 *
	 * @var array
	 */
	private $test_attachments = array();

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user );

		// Enable media toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_media_toolkit' => true,
			)
		);

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Media_Template_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-template-cpt.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Media_Collection_CPT' ) ) {
			require_once dirname( __DIR__ ) . '/includes/class-wp-mcp-ai-media-collection-cpt.php';
		}

		// Register CPTs.
		WP_MCP_AI_Media_Template_CPT::register_post_type();
		WP_MCP_AI_Media_Template_CPT::register_taxonomy();
		WP_MCP_AI_Media_Collection_CPT::register_post_type();
		WP_MCP_AI_Media_Collection_CPT::register_taxonomy();

		// Load tool classes.
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-process-collection.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-apply-collection-template.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-scan-orphaned-media.php';
		require_once dirname( __DIR__ ) . '/includes/tools/media/class-wp-mcp-ai-tool-cleanup-orphaned-media.php';
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		foreach ( $this->test_attachments as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->unregister_tool( 'apply_media_template' );
		$registry->unregister_tool( 'process_collection' );

		WP_MCP_AI_Media_Toolkit_Stub_Tool::$slug   = '';
		WP_MCP_AI_Media_Toolkit_Stub_Tool::$result = array();

		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Create a test image attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_attachment() {
		$attachment_id = $this->factory->attachment->create_upload_object(
			dirname( __DIR__, 3 ) . '/tests/fixtures/sample-image.png'
		);

		$this->assertNotWPError( $attachment_id );
		$this->test_attachments[] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Create a published media collection post with the given items and templates.
	 *
	 * @param array $items     Attachment IDs.
	 * @param array $templates Template IDs.
	 * @return int Collection ID.
	 */
	private function create_collection( $items = array(), $templates = array() ) {
		$collection_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_media_coll',
				'post_title'  => 'Test Collection',
				'post_status' => 'publish',
			)
		);

		if ( ! empty( $items ) ) {
			update_post_meta( $collection_id, '_mcp_ai_collection_items', $items );
		}
		if ( ! empty( $templates ) ) {
			update_post_meta( $collection_id, '_mcp_ai_collection_templates', $templates );
		}

		return $collection_id;
	}

	/**
	 * Test process_collection survives apply_media_template returning a WP_Error.
	 */
	public function test_process_collection_survives_apply_tool_wp_error() {
		$attachment_id = $this->create_test_attachment();
		$collection_id = $this->create_collection( array( $attachment_id ), array( 1 ) );

		WP_MCP_AI_Media_Toolkit_Stub_Tool::$slug   = 'apply_media_template';
		WP_MCP_AI_Media_Toolkit_Stub_Tool::$result = new WP_Error( 'wp_mcp_ai_stub_apply_error', 'Apply failed.' );
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Media_Toolkit_Stub_Tool() );

		$tool   = new WP_MCP_AI_Tool_Process_Collection();
		$result = $tool->execute(
			array( 'collection_id' => $collection_id ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 0, $result['statistics']['success_count'] );
		$this->assertEquals( 1, $result['statistics']['error_count'] );
		$this->assertFalse( $result['results'][0]['success'] );
		$this->assertEquals( 'Apply failed.', $result['results'][0]['error'] );
	}

	/**
	 * Test process_collection propagates apply_media_template success output.
	 */
	public function test_process_collection_counts_apply_tool_success() {
		$attachment_id = $this->create_test_attachment();
		$collection_id = $this->create_collection( array( $attachment_id ), array( 1 ) );

		WP_MCP_AI_Media_Toolkit_Stub_Tool::$slug   = 'apply_media_template';
		WP_MCP_AI_Media_Toolkit_Stub_Tool::$result = array(
			'success'       => true,
			'attachment_id' => 555,
			'url'           => 'http://example.org/out.png',
		);
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Media_Toolkit_Stub_Tool() );

		$tool   = new WP_MCP_AI_Tool_Process_Collection();
		$result = $tool->execute(
			array( 'collection_id' => $collection_id ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 1, $result['statistics']['success_count'] );
		$this->assertEquals( 0, $result['statistics']['error_count'] );
		$this->assertTrue( $result['results'][0]['success'] );
		$this->assertEquals( 555, $result['results'][0]['output_id'] );
		$this->assertEquals( 'http://example.org/out.png', $result['results'][0]['output_url'] );
	}

	/**
	 * Test apply_collection_template handles process_collection returning a WP_Error.
	 */
	public function test_apply_collection_template_handles_process_tool_wp_error() {
		$collection_id = $this->create_collection();
		$template_id   = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_media_tpl',
				'post_title'  => 'Test Template',
				'post_status' => 'publish',
			)
		);

		WP_MCP_AI_Media_Toolkit_Stub_Tool::$slug   = 'process_collection';
		WP_MCP_AI_Media_Toolkit_Stub_Tool::$result = new WP_Error( 'wp_mcp_ai_stub_process_error', 'Process failed.' );
		WP_MCP_AI_Tool_Registry::get_instance()->register_tool( new WP_MCP_AI_Media_Toolkit_Stub_Tool() );

		$tool   = new WP_MCP_AI_Tool_Apply_Collection_Template();
		$result = $tool->execute(
			array(
				'collection_id' => $collection_id,
				'template_ids'  => array( $template_id ),
				'process'       => true,
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'warning', $result );
		$this->assertStringContainsString( 'Process failed.', $result['warning'] );
		$this->assertArrayNotHasKey( 'processing', $result );

		$assigned = get_post_meta( $collection_id, '_mcp_ai_collection_templates', true );
		$this->assertEquals( array( $template_id ), $assigned );
	}

	/**
	 * Test apply_collection_template validation failures are WP_Error objects.
	 */
	public function test_apply_collection_template_validation_error_is_wp_error() {
		$tool   = new WP_MCP_AI_Tool_Apply_Collection_Template();
		$result = $tool->execute(
			array(
				'collection_id' => 999999,
				'template_ids'  => array( 1 ),
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
	}

	/**
	 * Test scan_orphaned_media rejects unknown scan_type values.
	 */
	public function test_scan_orphaned_media_rejects_invalid_scan_type() {
		$tool   = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result = $tool->execute(
			array( 'scan_type' => 'bogus' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
	}

	/**
	 * Test scan_orphaned_media rejects year_month values that could traverse.
	 */
	public function test_scan_orphaned_media_rejects_traversal_year_month() {
		$tool = new WP_MCP_AI_Tool_Scan_Orphaned_Media();

		foreach ( array( '../plugins', '..', '../../wp-config', '2024-01', '2024/1', '2024/013' ) as $bad_value ) {
			$result = $tool->execute(
				array(
					'scan_type'  => 'unregistered',
					'year_month' => $bad_value,
				),
				array( 'user_id' => $this->admin_user )
			);

			$this->assertWPError( $result, 'Expected year_month "' . $bad_value . '" to be rejected.' );
		}
	}

	/**
	 * Test scan_orphaned_media accepts a well-formed year_month.
	 */
	public function test_scan_orphaned_media_accepts_valid_year_month() {
		$tool   = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result = $tool->execute(
			array(
				'scan_type'  => 'unreferenced',
				'year_month' => '2024/01',
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 'unreferenced', $result['scan_type'] );
	}

	/**
	 * Test scan_orphaned_media flags only unreferenced attachments.
	 */
	public function test_scan_orphaned_media_detects_unreferenced_only() {
		$referenced_id   = $this->create_test_attachment();
		$unreferenced_id = $this->create_test_attachment();

		$file = get_attached_file( $referenced_id );
		wp_insert_post(
			array(
				'post_title'   => 'Uses image',
				'post_content' => 'Check out the image ' . basename( $file ) . ' here.',
				'post_status'  => 'publish',
			)
		);

		$tool   = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result = $tool->execute(
			array( 'scan_type' => 'unreferenced' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );

		$ids = wp_list_pluck( $result['unreferenced']['items'], 'id' );
		$this->assertContains( $unreferenced_id, $ids );
		$this->assertNotContains( $referenced_id, $ids );
	}

	/**
	 * Test cleanup_orphaned_media rejects unknown cleanup_type values.
	 */
	public function test_cleanup_orphaned_media_rejects_invalid_cleanup_type() {
		$tool   = new WP_MCP_AI_Tool_Cleanup_Orphaned_Media();
		$result = $tool->execute(
			array( 'cleanup_type' => 'bogus' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
	}

	/**
	 * Test cleanup_orphaned_media ids mode requires attachment_ids.
	 */
	public function test_cleanup_orphaned_media_ids_requires_attachment_ids() {
		$tool   = new WP_MCP_AI_Tool_Cleanup_Orphaned_Media();
		$result = $tool->execute(
			array( 'cleanup_type' => 'ids' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertWPError( $result );
	}

	/**
	 * Test cleanup_orphaned_media defaults to a non-destructive dry run.
	 */
	public function test_cleanup_orphaned_media_dry_run_default_safe() {
		$this->create_test_attachment();

		$tool   = new WP_MCP_AI_Tool_Cleanup_Orphaned_Media();
		$result = $tool->execute(
			array( 'cleanup_type' => 'missing_files' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertEquals( 0, $result['deleted']['count_attachments'] );
	}

	/**
	 * Test scan_orphaned_media treats postmeta references as referenced.
	 */
	public function test_scan_orphaned_media_respects_postmeta_references() {
		$gallery_id      = $this->create_test_attachment();
		$serialized_id   = $this->create_test_attachment();
		$url_id          = $this->create_test_attachment();
		$unreferenced_id = $this->create_test_attachment();

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Meta host',
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $post_id, '_product_image_gallery', $gallery_id . ',' . 99991 );
		update_post_meta( $post_id, 'custom_image_field', array( $serialized_id ) );
		update_post_meta( $post_id, 'custom_url_field', wp_get_attachment_url( $url_id ) );

		$tool   = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result = $tool->execute(
			array( 'scan_type' => 'unreferenced' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );

		$ids = wp_list_pluck( $result['unreferenced']['items'], 'id' );
		$this->assertNotContains( $gallery_id, $ids );
		$this->assertNotContains( $serialized_id, $ids );
		$this->assertNotContains( $url_id, $ids );
		$this->assertContains( $unreferenced_id, $ids );
	}

	/**
	 * Test scan_orphaned_media respects well-known option references (site icon).
	 */
	public function test_scan_orphaned_media_respects_site_icon_option() {
		$icon_id         = $this->create_test_attachment();
		$unreferenced_id = $this->create_test_attachment();

		update_option( 'site_icon', $icon_id );

		$tool   = new WP_MCP_AI_Tool_Scan_Orphaned_Media();
		$result = $tool->execute(
			array( 'scan_type' => 'unreferenced' ),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );

		$ids = wp_list_pluck( $result['unreferenced']['items'], 'id' );
		$this->assertNotContains( $icon_id, $ids );
		$this->assertContains( $unreferenced_id, $ids );

		delete_option( 'site_icon' );
	}

	/**
	 * Test cleanup keeps meta-referenced attachments with delete_unreferenced enabled.
	 */
	public function test_cleanup_keeps_postmeta_referenced_attachments() {
		$referenced_id   = $this->create_test_attachment();
		$unreferenced_id = $this->create_test_attachment();

		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Gallery host',
				'post_content' => '',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $post_id, '_product_image_gallery', $referenced_id );

		$tool   = new WP_MCP_AI_Tool_Cleanup_Orphaned_Media();
		$result = $tool->execute(
			array(
				'cleanup_type'        => 'unreferenced',
				'dry_run'             => true,
				'delete_unreferenced' => true,
			),
			array( 'user_id' => $this->admin_user )
		);

		$this->assertTrue( $result['success'] );

		$listed = wp_list_pluck( $result['deleted']['attachments'], 'id' );
		$this->assertNotContains( $referenced_id, $listed );
		$this->assertContains( $unreferenced_id, $listed );
	}

	/**
	 * Test the admin bulk action survives process_collection returning a WP_Error.
	 */
	public function test_bulk_action_survives_process_collection_wp_error() {
		$collection_id = $this->create_collection(); // No items → process_collection errors.

		$redirect = WP_MCP_AI_Media_Collection_CPT::handle_bulk_actions(
			'http://example.org/wp-admin/edit.php?post_type=mcp_ai_media_coll',
			'process_collections',
			array( $collection_id )
		);

		$this->assertStringContainsString( 'processed_collections=0', $redirect );
		$this->assertStringContainsString( 'processing_errors=1', $redirect );
	}
}
