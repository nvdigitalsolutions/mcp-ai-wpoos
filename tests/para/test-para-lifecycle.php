<?php
/**
 * PHPUnit tests for the PARA Lifecycle service.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * @group para
 */
class Test_PARA_Lifecycle extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_project_management' => true,
				'enable_para_organization'  => true,
			)
		);
		if ( ! class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) ) {
			require_once dirname( __DIR__, 2 ) . '/addons/pro/includes/para/class-wp-mcp-ai-para-taxonomy.php';
			require_once dirname( __DIR__, 2 ) . '/addons/pro/includes/para/class-wp-mcp-ai-para-lifecycle.php';
		}
		WP_MCP_AI_PARA_Taxonomy::register_taxonomy();
		WP_MCP_AI_PARA_Taxonomy::seed_root_terms();
	}

	public function test_completed_project_auto_archives() {
		$project_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_project' ) );
		WP_MCP_AI_PARA_Taxonomy::assign( $project_id, 'projects' );
		$this->assertSame( 'projects', WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $project_id ) );

		// Trigger lifecycle handler directly.
		update_post_meta( $project_id, '_project_status', 'completed' );
		WP_MCP_AI_PARA_Lifecycle::on_project_status_change( 0, $project_id, '_project_status', 'completed' );

		$this->assertSame( 'archives', WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $project_id ) );
	}

	public function test_archive_candidates_includes_completed_unarchived_project() {
		$project_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_project' ) );
		update_post_meta( $project_id, '_project_status', 'cancelled' );
		// Don't fire the auto-archive handler — simulate a project that slipped through.

		$candidates = WP_MCP_AI_PARA_Lifecycle::find_archive_candidates();
		$ids        = wp_list_pluck( $candidates, 'id' );
		$this->assertContains( $project_id, $ids );
	}
}
