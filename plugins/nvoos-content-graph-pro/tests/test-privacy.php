<?php
/**
 * Characterization tests for the ported Pro privacy service (Wave F1).
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns
 *   `WP_MCP_AI_Pro_Privacy`; the public Privacy API surface is asserted
 *   (the imaging pair registers because the base's imaging CPT exists).
 * - Standalone matrix (base plugin absent): the ported class in
 *   `src/class-wp-mcp-ai-pro-privacy.php` is asserted in full, plus the
 *   protected helpers via the seam subclass.
 *
 * The health-wellness and imaging CPTs are F4 ports; tests register the
 * post types in-process (guarded by post_type_exists) so the exporter and
 * eraser contracts are characterized in both matrices today.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Pro privacy service tests.
 */
class Test_Pro_Privacy extends WP_UnitTestCase {

	/**
	 * Register a test CPT unless already registered (monolith base Pro owns
	 * the real registrations).
	 *
	 * @param string $slug Post type slug.
	 * @return void
	 */
	private function register_test_cpt( string $slug ): void {
		if ( post_type_exists( $slug ) ) {
			return;
		}
		register_post_type(
			$slug,
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * Create a user and authored posts of a given type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $count     Number of posts.
	 * @param array  $meta      Optional post meta (key => value).
	 * @return array{0: WP_User, 1: int[]} User and post IDs.
	 */
	private function create_authored_posts( string $post_type, int $count, array $meta = array() ): array {
		$user_id = self::factory()->user->create( array( 'user_email' => 'privacy-test@example.com' ) );
		$ids     = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id    = wp_insert_post(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_author' => $user_id,
					'post_title'  => "Record {$i}",
				)
			);
			$ids[] = $id;
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
		}
		return array( get_user_by( 'id', $user_id ), $ids );
	}

	/**
	 * init() must wire both Privacy API filters (idempotently).
	 */
	public function test_init_registers_privacy_filters(): void {
		WP_MCP_AI_Pro_Privacy::init();

		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_exporters', array( 'WP_MCP_AI_Pro_Privacy', 'register_exporters' ) ) );
		$this->assertNotFalse( has_filter( 'wp_privacy_personal_data_erasers', array( 'WP_MCP_AI_Pro_Privacy', 'register_erasers' ) ) );
	}

	/**
	 * The exporter registration must add the health-wellness pair and gate
	 * the imaging pair on the imaging CPT class.
	 */
	public function test_register_exporters(): void {
		$exporters = WP_MCP_AI_Pro_Privacy::register_exporters( array() );

		$this->assertArrayHasKey( 'wp-mcp-ai-pro-health-wellness', $exporters );
		$this->assertSame(
			'NV oOS Pro — Health & Wellness Records',
			$exporters['wp-mcp-ai-pro-health-wellness']['exporter_friendly_name']
		);
		$this->assertSame(
			array( 'WP_MCP_AI_Pro_Privacy', 'export_health_wellness' ),
			$exporters['wp-mcp-ai-pro-health-wellness']['callback']
		);

		if ( class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$this->assertArrayHasKey( 'wp-mcp-ai-pro-imaging-studies', $exporters );
		} else {
			$this->assertArrayNotHasKey( 'wp-mcp-ai-pro-imaging-studies', $exporters );
		}
	}

	/**
	 * The eraser registration mirrors the exporter shape.
	 */
	public function test_register_erasers(): void {
		$erasers = WP_MCP_AI_Pro_Privacy::register_erasers( array() );

		$this->assertArrayHasKey( 'wp-mcp-ai-pro-health-wellness', $erasers );
		$this->assertSame(
			'NV oOS Pro — Health & Wellness Records',
			$erasers['wp-mcp-ai-pro-health-wellness']['eraser_friendly_name']
		);
		$this->assertSame(
			array( 'WP_MCP_AI_Pro_Privacy', 'erase_health_wellness' ),
			$erasers['wp-mcp-ai-pro-health-wellness']['callback']
		);

		if ( class_exists( 'WP_MCP_AI_Imaging_Study_CPT' ) ) {
			$this->assertArrayHasKey( 'wp-mcp-ai-pro-imaging-studies', $erasers );
		} else {
			$this->assertArrayNotHasKey( 'wp-mcp-ai-pro-imaging-studies', $erasers );
		}
	}

	/**
	 * Unknown e-mail addresses must produce empty, done exports.
	 */
	public function test_export_health_wellness_unknown_email(): void {
		$result = WP_MCP_AI_Pro_Privacy::export_health_wellness( 'nobody@example.com' );
		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Authored health records must export with labels, dates, and
	 * non-internal meta only.  Export order is unspecified (posts created
	 * in the same second tie on `post_date`), so the assertions are
	 * order-independent.
	 */
	public function test_export_health_wellness_exports_authored_records(): void {
		$this->register_test_cpt( 'mcp_ai_member' );
		list( $user, $ids ) = $this->create_authored_posts(
			'mcp_ai_member',
			2,
			array(
				'patient_weight' => '72.5',
				'_edit_lock'     => '1234:1',
				'_wp_meta_test'  => 'internal',
			)
		);

		$result = WP_MCP_AI_Pro_Privacy::export_health_wellness( $user->user_email );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );

		$item_ids = wp_list_pluck( $result['data'], 'item_id' );
		$this->assertEqualsCanonicalizing(
			array( 'health-' . $ids[0], 'health-' . $ids[1] ),
			$item_ids
		);

		// Assert the shared field shape on the group matching the first
		// created record (either group satisfies it — order is unspecified).
		$group = ( 'health-' . $ids[0] === $result['data'][0]['item_id'] ) ? $result['data'][0] : $result['data'][1];
		$this->assertSame( 'wp-mcp-ai-pro-health-mcp_ai_member', $group['group_id'] );

		$names = wp_list_pluck( $group['data'], 'name' );
		$this->assertContains( 'Record type', $names );
		$this->assertContains( 'Title', $names );
		$this->assertContains( 'Created', $names );
		$this->assertContains( 'patient_weight', $names );
		$this->assertNotContains( '_edit_lock', $names );
		$this->assertNotContains( '_wp_meta_test', $names );

		$values = array_column( $group['data'], 'value' );
		$this->assertContains( 'Record 0', $values );
		$this->assertContains( '72.5', $values );
	}

	/**
	 * Exports must paginate at PAGE_SIZE records per page.
	 */
	public function test_export_health_wellness_paginates(): void {
		$this->register_test_cpt( 'mcp_ai_member' );
		list( $user ) = $this->create_authored_posts( 'mcp_ai_member', WP_MCP_AI_Pro_Privacy::PAGE_SIZE + 1 );

		$page_one = WP_MCP_AI_Pro_Privacy::export_health_wellness( $user->user_email, 1 );
		$this->assertCount( WP_MCP_AI_Pro_Privacy::PAGE_SIZE, $page_one['data'] );
		$this->assertFalse( $page_one['done'] );

		$page_two = WP_MCP_AI_Pro_Privacy::export_health_wellness( $user->user_email, 2 );
		$this->assertCount( 1, $page_two['data'] );
		$this->assertTrue( $page_two['done'] );
	}

	/**
	 * Erasure must hard-delete every authored health record.
	 */
	public function test_erase_health_wellness_hard_deletes(): void {
		$this->register_test_cpt( 'mcp_ai_member' );
		list( $user, $ids ) = $this->create_authored_posts( 'mcp_ai_member', 2 );

		$result = WP_MCP_AI_Pro_Privacy::erase_health_wellness( $user->user_email );

		$this->assertSame( 2, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		foreach ( $ids as $id ) {
			$this->assertNull( get_post( $id ) );
		}
	}

	/**
	 * Unknown e-mail addresses must produce a clean no-op erasure.
	 */
	public function test_erase_health_wellness_unknown_email(): void {
		$result = WP_MCP_AI_Pro_Privacy::erase_health_wellness( 'nobody@example.com' );
		$this->assertSame( 0, $result['items_removed'] );
		$this->assertSame( 0, $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Imaging exports must carry the seven-field de-identified metadata shape.
	 */
	public function test_export_imaging_studies(): void {
		$this->register_test_cpt( 'mcp_ai_imaging_study' );
		list( $user, $ids ) = $this->create_authored_posts(
			'mcp_ai_imaging_study',
			1,
			array(
				'_imaging_study_instance_uid' => '1.2.840.10008.1.1',
				'_imaging_patient_id'         => 'PID-1',
				'_imaging_modality'           => 'MR',
				'_imaging_study_date'         => '2026-01-01',
				'_imaging_study_description'  => 'Brain scan',
				'_imaging_status'             => 'complete',
			)
		);

		$result = WP_MCP_AI_Pro_Privacy::export_imaging_studies( $user->user_email );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );

		$group = $result['data'][0];
		$this->assertSame( 'wp-mcp-ai-pro-imaging-studies', $group['group_id'] );
		$this->assertSame( 'imaging-' . $ids[0], $group['item_id'] );
		$this->assertCount( 7, $group['data'] );
		$this->assertSame( 'Study Instance UID', $group['data'][0]['name'] );
		$this->assertSame( '1.2.840.10008.1.1', $group['data'][0]['value'] );
		$this->assertSame( 'MR', $group['data'][2]['value'] );
	}

	/**
	 * Erasing an imaging study must delete the on-disk DICOM directory first
	 * and then hard-delete the CPT record.
	 */
	public function test_erase_imaging_studies_deletes_files_and_post(): void {
		$this->register_test_cpt( 'mcp_ai_imaging_study' );

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$storage = $upload_dir['basedir'] . '/nvoos-privacy-test-' . wp_generate_uuid4();
		wp_mkdir_p( $storage . '/nested' );
		file_put_contents( $storage . '/nested/pixel.bin', 'dicom-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture only.

		list( $user, $ids ) = $this->create_authored_posts(
			'mcp_ai_imaging_study',
			1,
			array( '_imaging_storage_path' => $storage )
		);

		$result = WP_MCP_AI_Pro_Privacy::erase_imaging_studies( $user->user_email );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertNull( get_post( $ids[0] ) );
		$this->assertFalse( is_dir( $storage ) );
	}

	/**
	 * A storage path outside the uploads directory must never be touched
	 * (path traversal guard); the CPT record is still erased.
	 */
	public function test_erase_imaging_studies_skips_path_outside_uploads(): void {
		$this->register_test_cpt( 'mcp_ai_imaging_study' );

		$outside = sys_get_temp_dir() . '/nvoos-privacy-outside-' . wp_generate_uuid4();
		wp_mkdir_p( $outside );

		list( $user ) = $this->create_authored_posts(
			'mcp_ai_imaging_study',
			1,
			array( '_imaging_storage_path' => $outside )
		);

		$result = WP_MCP_AI_Pro_Privacy::erase_imaging_studies( $user->user_email );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( 'invalid storage path', $result['messages'][0] );
		$this->assertTrue( is_dir( $outside ) );

		rmdir( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
	}

	/**
	 * A symlink inside a study storage directory must be removed without
	 * being followed — the linked target must survive the erase intact.
	 */
	public function test_erase_imaging_studies_does_not_follow_symlinks(): void {
		$this->register_test_cpt( 'mcp_ai_imaging_study' );

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$storage = $upload_dir['basedir'] . '/nvoos-privacy-symlink-' . wp_generate_uuid4();
		wp_mkdir_p( $storage . '/nested' );
		file_put_contents( $storage . '/nested/pixel.bin', 'dicom-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture only.

		$target_dir = sys_get_temp_dir() . '/nvoos-privacy-target-' . wp_generate_uuid4();
		wp_mkdir_p( $target_dir );
		file_put_contents( $target_dir . '/victim.txt', 'must-survive' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture only.

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test fixture only.
		if ( ! @symlink( $target_dir, $storage . '/nested/evil' ) ) {
			unlink( $target_dir . '/victim.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			rmdir( $target_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			unlink( $storage . '/nested/pixel.bin' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			rmdir( $storage . '/nested' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $storage ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			$this->markTestSkipped( 'Symlink creation unavailable in this environment.' );
		}

		list( $user, $ids ) = $this->create_authored_posts(
			'mcp_ai_imaging_study',
			1,
			array( '_imaging_storage_path' => $storage )
		);

		$result = WP_MCP_AI_Pro_Privacy::erase_imaging_studies( $user->user_email );

		$this->assertSame( 1, $result['items_removed'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertNull( get_post( $ids[0] ) );
		$this->assertFalse( is_dir( $storage ) );

		// The symlink target must be fully intact.
		$this->assertFileExists( $target_dir . '/victim.txt' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion only.
		$this->assertSame( 'must-survive', file_get_contents( $target_dir . '/victim.txt' ) );

		unlink( $target_dir . '/victim.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		rmdir( $target_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
	}

	/**
	 * Standalone only: the health CPT map must carry the six F4 slugs.
	 */
	public function test_health_cpt_map_via_seam(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base privacy class has private helpers.' );
		}

		require_once __DIR__ . '/helpers/test-pro-privacy-seam.php';

		$map = \NvoosContentGraphPro\Tests\Test_Pro_Privacy_Seam::health_map();
		$this->assertSame(
			array( 'mcp_ai_member', 'mcp_ai_policy', 'mcp_ai_med_record', 'mcp_ai_checkup', 'mcp_ai_prescription', 'mcp_ai_allergy' ),
			array_keys( $map )
		);
		foreach ( $map as $label ) {
			$this->assertNotEmpty( $label );
		}
	}

	/**
	 * Standalone only: recursive deletion must remove nested trees and
	 * tolerate non-string inputs.
	 */
	public function test_delete_directory_recursively_via_seam(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base privacy class has private helpers.' );
		}

		require_once __DIR__ . '/helpers/test-pro-privacy-seam.php';

		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$tree = $upload_dir['basedir'] . '/nvoos-privacy-tree-' . wp_generate_uuid4();
		wp_mkdir_p( $tree . '/a/b' );
		file_put_contents( $tree . '/a/b/leaf.txt', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture only.

		\NvoosContentGraphPro\Tests\Test_Pro_Privacy_Seam::delete_dir( $tree );
		$this->assertFalse( is_dir( $tree ) );

		// Non-string and missing inputs must return silently.
		\NvoosContentGraphPro\Tests\Test_Pro_Privacy_Seam::delete_dir( 123 );
		\NvoosContentGraphPro\Tests\Test_Pro_Privacy_Seam::delete_dir( $tree . '-missing' );
		$this->assertTrue( true );
	}
}
