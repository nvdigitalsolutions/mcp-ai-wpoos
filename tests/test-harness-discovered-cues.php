<?php
/**
 * Tests for discovered cue registration in the Prompt Cue Library.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Discovered cues tests.
 *
 * @since 1.9.0
 */
class Test_Harness_Discovered_Cues extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		// Ensure clean state.
		delete_option( 'wp_mcp_ai_discovered_cues' );
	}

	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_discovered_cues' );
		parent::tearDown();
	}

	public function test_register_discovered_cue_stores_metadata() {
		$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();

		$registered = $library->register_discovered_cue(
			array(
				'slug'           => 'test_discovered_cue',
				'label'          => 'Test Discovered Cue',
				'description'    => 'A cue discovered during harness search.',
				'template'       => 'Verify your work against the database before answering.',
				'task_classes'   => array( 'qa' ),
				'discovered_for' => 'qa',
				'search_run_id'  => 'search_run_42',
				'score_delta'    => 0.12,
				'status'         => 'candidate',
			)
		);

		$this->assertTrue( $registered );

		// Verify it's in the cue registry.
		$cue = $library->get( 'test_discovered_cue' );
		$this->assertNotNull( $cue );
		$this->assertSame( 'Test Discovered Cue', $cue['label'] );

		// Clean up — unregister the test cue so it doesn't pollute other tests.
		$library->unregister( 'test_discovered_cue' );
	}

	public function test_get_discovered_cues_returns_cues_with_metadata() {
		$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();

		$library->register_discovered_cue(
			array(
				'slug'           => 'discovered_cue_a',
				'label'          => 'Cue A',
				'description'    => 'First discovered cue.',
				'template'       => 'Do thing A first.',
				'task_classes'   => array( 'qa' ),
				'discovered_for' => 'qa',
				'search_run_id'  => 'run_1',
				'score_delta'    => 0.10,
				'status'         => 'candidate',
			)
		);

		$library->register_discovered_cue(
			array(
				'slug'           => 'discovered_cue_b',
				'label'          => 'Cue B',
				'description'    => 'Second discovered cue.',
				'template'       => 'Do thing B second.',
				'task_classes'   => array( 'code' ),
				'discovered_for' => 'code',
				'search_run_id'  => 'run_2',
				'score_delta'    => 0.05,
				'status'         => 'accepted',
			)
		);

		// Get all discovered cues.
		$all = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues();
		$this->assertCount( 2, $all );

		// Filter by status.
		$candidates = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues( 'candidate' );
		$this->assertCount( 1, $candidates );
		$this->assertSame( 'discovered_cue_a', $candidates[0]['slug'] );

		$accepted = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues( 'accepted' );
		$this->assertCount( 1, $accepted );
		$this->assertSame( 'discovered_cue_b', $accepted[0]['slug'] );

		// Clean up.
		$library->unregister( 'discovered_cue_a' );
		$library->unregister( 'discovered_cue_b' );
	}

	public function test_update_discovered_cue_status_changes_status() {
		$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();

		$library->register_discovered_cue(
			array(
				'slug'           => 'status_test_cue',
				'label'          => 'Status Test',
				'description'    => 'Testing status transitions.',
				'template'       => 'Check status before proceeding.',
				'task_classes'   => array( 'general' ),
				'discovered_for' => 'general',
				'search_run_id'  => 'run_3',
				'score_delta'    => 0.08,
				'status'         => 'candidate',
			)
		);

		// Update to accepted.
		$updated = WP_MCP_AI_Prompt_Cue_Library::update_discovered_cue_status( 'status_test_cue', 'accepted' );
		$this->assertTrue( $updated );

		$cues  = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues( 'accepted' );
		$found = false;
		foreach ( $cues as $cue ) {
			if ( 'status_test_cue' === $cue['slug'] ) {
				$found = true;
				$this->assertSame( 'accepted', $cue['status'] );
			}
		}
		$this->assertTrue( $found );

		// Deprecate.
		WP_MCP_AI_Prompt_Cue_Library::update_discovered_cue_status( 'status_test_cue', 'deprecated' );

		$deprecated = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues( 'deprecated' );
		$this->assertCount( 1, $deprecated );

		// Clean up.
		$library->unregister( 'status_test_cue' );
	}

	public function test_update_nonexistent_cue_returns_false() {
		$result = WP_MCP_AI_Prompt_Cue_Library::update_discovered_cue_status( 'nonexistent', 'accepted' );
		$this->assertFalse( $result );
	}

	public function test_discovered_cue_includes_provenance() {
		$library = WP_MCP_AI_Prompt_Cue_Library::get_instance();

		$slug = 'provenance_test_cue_' . wp_rand( 1000, 9999 );

		$library->register_discovered_cue(
			array(
				'slug'           => $slug,
				'label'          => 'Provenance Test',
				'description'    => 'Testing provenance metadata.',
				'template'       => 'Track where this came from.',
				'task_classes'   => array( 'research' ),
				'discovered_for' => 'research',
				'search_run_id'  => 'run_prov_99',
				'score_delta'    => 0.15,
				'status'         => 'candidate',
			)
		);

		$all   = WP_MCP_AI_Prompt_Cue_Library::get_discovered_cues();
		$found = false;
		foreach ( $all as $cue ) {
			if ( $slug === $cue['slug'] ) {
				$found = true;
				$this->assertSame( 'research', $cue['discovered_for'] );
				$this->assertSame( 'run_prov_99', $cue['search_run_id'] );
				$this->assertSame( 0.15, $cue['score_delta'] );
				$this->assertArrayHasKey( 'discovered_at', $cue );
			}
		}
		$this->assertTrue( $found );

		// Clean up.
		$library->unregister( $slug );
	}
}
