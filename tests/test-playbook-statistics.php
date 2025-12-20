<?php
/**
 * Test Playbook Statistics.
 *
 * Tests for the playbook statistics display functionality.
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Playbook_Statistics.
 */
class Test_Playbook_Statistics extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required classes.
		if ( ! class_exists( 'WP_MCP_AI_Section_Advanced' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/sections/class-wp-mcp-ai-section-advanced.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
		}
	}

	/**
	 * Test get_playbook_statistics method exists and returns expected structure.
	 */
	public function test_get_playbook_statistics_structure() {
		$section = new WP_MCP_AI_Section_Advanced();

		// Use reflection to access private method.
		$method = new ReflectionMethod( 'WP_MCP_AI_Section_Advanced', 'get_playbook_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $section );

		// Verify return structure.
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'total_attachments', $stats );
		$this->assertArrayHasKey( 'professions_with_playbooks', $stats );
		$this->assertArrayHasKey( 'seeded', $stats );
		$this->assertArrayHasKey( 'last_sync', $stats );
	}

	/**
	 * Test statistics with no playbooks.
	 */
	public function test_statistics_with_no_playbooks() {
		// Ensure clean slate.
		$this->delete_all_playbook_attachments();

		$section = new WP_MCP_AI_Section_Advanced();
		$method  = new ReflectionMethod( 'WP_MCP_AI_Section_Advanced', 'get_playbook_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $section );

		$this->assertEquals( 0, $stats['total_attachments'] );
		$this->assertEquals( 0, $stats['professions_with_playbooks'] );
	}

	/**
	 * Test statistics with playbook attachments.
	 */
	public function test_statistics_with_playbooks() {
		// Create test profession.
		$profession_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_status' => 'publish',
				'post_title'  => 'Test Profession',
			)
		);

		// Create playbook attachment.
		$attachment_id = $this->create_test_playbook_attachment( $profession_id );

		$section = new WP_MCP_AI_Section_Advanced();
		$method  = new ReflectionMethod( 'WP_MCP_AI_Section_Advanced', 'get_playbook_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $section );

		$this->assertEquals( 1, $stats['total_attachments'] );
		$this->assertEquals( 1, $stats['professions_with_playbooks'] );

		// Clean up.
		wp_delete_post( $attachment_id, true );
		wp_delete_post( $profession_id, true );
	}

	/**
	 * Test last sync timestamp display.
	 */
	public function test_last_sync_timestamp() {
		// Set last sync timestamp.
		$timestamp = current_time( 'timestamp' ) - DAY_IN_SECONDS;
		update_option( 'wp_mcp_ai_playbooks_last_sync', $timestamp );

		$section = new WP_MCP_AI_Section_Advanced();
		$method  = new ReflectionMethod( 'WP_MCP_AI_Section_Advanced', 'get_playbook_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $section );

		$this->assertNotEmpty( $stats['last_sync'] );
		$this->assertStringContainsString( 'ago', $stats['last_sync'] );

		// Clean up.
		delete_option( 'wp_mcp_ai_playbooks_last_sync' );
	}

	/**
	 * Test seeded status.
	 */
	public function test_seeded_status() {
		// Test not seeded.
		delete_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION );

		$section = new WP_MCP_AI_Section_Advanced();
		$method  = new ReflectionMethod( 'WP_MCP_AI_Section_Advanced', 'get_playbook_statistics' );
		$method->setAccessible( true );

		$stats = $method->invoke( $section );
		$this->assertFalse( $stats['seeded'] );

		// Test seeded.
		update_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION, true );
		$stats = $method->invoke( $section );
		$this->assertTrue( $stats['seeded'] );

		// Clean up.
		delete_option( WP_MCP_AI_Profession_Playbook_Seeder::SEEDED_OPTION );
	}

	/**
	 * Helper: Create a test playbook attachment.
	 *
	 * @param int $profession_id Profession post ID.
	 * @return int Attachment ID.
	 */
	private function create_test_playbook_attachment( $profession_id ) {
		$attachment_id = $this->factory->post->create(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Test Playbook',
			)
		);

		update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_profession_id', $profession_id );
		update_post_meta( $attachment_id, '_wp_mcp_ai_playbook_hash', 'test_hash_123' );

		return $attachment_id;
	}

	/**
	 * Helper: Delete all playbook attachments.
	 */
	private function delete_all_playbook_attachments() {
		global $wpdb;

		// Get all playbook attachment IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachment_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = %s
				AND pm.meta_key = %s",
				'attachment',
				'_wp_mcp_ai_playbook_profession_id'
			)
		);

		// Delete each attachment.
		foreach ( $attachment_ids as $attachment_id ) {
			wp_delete_post( $attachment_id, true );
		}
	}
}
