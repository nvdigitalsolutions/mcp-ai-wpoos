<?php
/**
 * PHPUnit tests for the PARA taxonomy & assignment helpers.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * @group para
 */
class Test_PARA_Taxonomy extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Enable PARA via settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_project_management' => true,
				'enable_para_organization'  => true,
			)
		);
		if ( ! class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) ) {
			require_once dirname( __DIR__, 2 ) . '/addons/pro/includes/para/class-wp-mcp-ai-para-taxonomy.php';
			require_once dirname( __DIR__, 2 ) . '/addons/pro/includes/para/class-wp-mcp-ai-para-area-cpt.php';
		}
		// Re-register to ensure taxonomy exists in the test env.
		WP_MCP_AI_PARA_Taxonomy::register_taxonomy();
		WP_MCP_AI_PARA_Taxonomy::seed_root_terms();
		WP_MCP_AI_PARA_Area_CPT::register();
	}

	public function test_root_terms_are_seeded() {
		$this->assertTrue( taxonomy_exists( WP_MCP_AI_PARA_Taxonomy::TAXONOMY ) );
		foreach ( WP_MCP_AI_PARA_Taxonomy::ROOTS as $slug ) {
			$this->assertNotFalse( get_term_by( 'slug', $slug, WP_MCP_AI_PARA_Taxonomy::TAXONOMY ), "Root term {$slug} should exist." );
		}
	}

	public function test_assign_returns_error_for_invalid_post() {
		$result = WP_MCP_AI_PARA_Taxonomy::assign( 999999, 'projects' );
		$this->assertWPError( $result );
	}

	public function test_assign_returns_error_for_unknown_term() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_area' ) );
		$result  = WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'not-a-bucket' );
		$this->assertWPError( $result );
	}

	public function test_assign_succeeds_for_valid_root() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_area' ) );
		$result  = WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'areas', 'unit test' );
		$this->assertTrue( $result );
		$this->assertSame( 'areas', WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $post_id ) );
	}

	public function test_archived_event_fires_on_move_to_archives() {
		$post_id = self::factory()->post->create( array( 'post_type' => 'mcp_ai_area' ) );
		WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'projects' );

		$fired = array();
		$cb    = function ( $pid, $reason ) use ( &$fired ) {
			$fired[] = array( $pid, $reason );
		};
		add_action( 'wp_mcp_ai_para_archived', $cb, 10, 2 );
		WP_MCP_AI_PARA_Taxonomy::assign( $post_id, 'archives', 'test reason' );
		remove_action( 'wp_mcp_ai_para_archived', $cb, 10 );

		$this->assertCount( 1, $fired );
		$this->assertSame( $post_id, $fired[0][0] );
		$this->assertSame( 'test reason', $fired[0][1] );
	}

	public function test_root_term_cannot_be_deleted() {
		$root = get_term_by( 'slug', 'projects', WP_MCP_AI_PARA_Taxonomy::TAXONOMY );
		$this->assertNotFalse( $root );

		// pre_delete_term hook calls wp_die; verify by simulating the protect callback directly.
		$this->expectException( \WPDieException::class );
		WP_MCP_AI_PARA_Taxonomy::protect_root_terms( $root->term_id, WP_MCP_AI_PARA_Taxonomy::TAXONOMY );
	}
}
