<?php
/**
 * Tests for the OKF → Skill Bridge (Pro addon).
 *
 * Covers WP_MCP_AI_OKF_Skill_Bridge resolution (allow-list, lifecycle,
 * trust gating, traversal safety), the Base load_skill tool integration,
 * and the assistant grant metabox save path.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Skill_Bridge_Test extends WP_UnitTestCase {

	/**
	 * Temporary uploads root directory for testing.
	 *
	 * @var string
	 */
	private $test_uploads_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_OKF_Skill_Bridge' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_OKF_Skill_Bridge (Pro) is not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-bridge-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		wp_set_current_user( 1 ); // Administrator.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_OKF_Skill_Bridge' ) ) {
			remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
			$this->recursive_rmdir( $this->test_uploads_dir );
		}

		parent::tearDown();
	}

	/**
	 * Filter upload dir to use a temp directory for tests.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = $this->test_uploads_dir;
		return $upload_dir;
	}

	/**
	 * Recursively remove a directory tree.
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file_info ) {
			if ( $file_info->isDir() ) {
				rmdir( $file_info->getPathname() );
			} else {
				unlink( $file_info->getPathname() );
			}
		}

		rmdir( $dir );
	}

	/**
	 * Create a site-knowledge bundle with a stable concept and a draft one.
	 *
	 * @return WP_MCP_AI_OKF_Bundle_Manager
	 */
	private function seed_bundle() {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$manager->create_bundle( 'site-knowledge' );

		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'site-knowledge' ) );
		$writer->write_concept(
			'policies/refunds',
			array(
				'type'        => 'Policy',
				'title'       => 'Refunds',
				'description' => 'Refund policy.',
				'verified'    => array( array( 'by' => 'human:admin', 'at' => gmdate( 'c' ) ) ),
			),
			'# Policy' . "\n\n" . 'Refunds within 30 days.'
		);
		$writer->write_concept(
			'policies/drafts',
			array(
				'type'   => 'Policy',
				'title'  => 'Draft Policy',
				'status' => 'draft',
			),
			'# Draft' . "\n\n" . 'Work in progress.'
		);
		$writer->write_concept(
			'policies/unverified',
			array(
				'type'  => 'Policy',
				'title' => 'Unverified Policy',
			),
			'# Unverified' . "\n\n" . 'No verification yet.'
		);

		return $manager;
	}

	/**
	 * Create an assistant post and return its ID.
	 *
	 * @param array $grants Grants to store.
	 * @return int Assistant post ID.
	 */
	private function create_assistant( array $grants = array() ) {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Bridge Test Assistant',
			)
		);

		if ( ! empty( $grants ) ) {
			update_post_meta( $post_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, $grants );
		}

		return $post_id;
	}

	/**
	 * Test that non-OKF-shaped names defer to the skill registry.
	 */
	public function test_bridge_defers_non_okf_names() {
		$this->assertNull( WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'some-skill', 0 ) );
		$this->assertNull( WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'no-colon-here', 7 ) );
	}

	/**
	 * Test that OKF concepts require an assistant context.
	 */
	public function test_bridge_requires_assistant_context() {
		$result = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/refunds', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_okf_concept_no_assistant', $result->get_error_code() );
	}

	/**
	 * Test that un-granted concepts are rejected (fail-closed).
	 */
	public function test_bridge_enforces_grants() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant();

		$result = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/refunds', $assistant_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_okf_concept_not_assigned', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test that a granted, stable concept resolves to a skill-shaped array.
	 */
	public function test_bridge_loads_granted_concept() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant( array( 'site-knowledge:policies/refunds' ) );

		$result = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/refunds', $assistant_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'site-knowledge:policies/refunds', $result['name'] );
		$this->assertSame( 'Refund policy.', $result['description'] );
		$this->assertSame( 'okf', $result['source'] );
		$this->assertStringContainsString( 'human-reviewed', $result['instructions'] );
		$this->assertStringContainsString( 'Refunds within 30 days.', $result['instructions'] );
	}

	/**
	 * Test that draft concepts are never loadable.
	 */
	public function test_bridge_rejects_draft_concepts() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant( array( 'site-knowledge:policies/drafts' ) );

		$result = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/drafts', $assistant_id );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_okf_concept_draft', $result->get_error_code() );
	}

	/**
	 * Test that missing bundles and concepts produce errors, and traversal
	 * attempts never escape the knowledge root.
	 */
	public function test_bridge_missing_and_traversal() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant(
			array(
				'ghost-bundle:policies/refunds',
				'site-knowledge:../escaped',
			)
		);

		$missing_bundle = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'ghost-bundle:policies/refunds', $assistant_id );
		$this->assertWPError( $missing_bundle );
		$this->assertSame( 'okf_bundle_not_found', $missing_bundle->get_error_code() );

		$traversal = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:../escaped', $assistant_id );
		$this->assertWPError( $traversal );
	}

	/**
	 * Test the optional minimum trust-tier gate.
	 */
	public function test_bridge_min_trust_gate() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant( array( 'site-knowledge:policies/unverified' ) );

		add_filter( 'wp_mcp_ai_okf_skill_bridge_min_trust', static function () {
			return 'human-reviewed';
		} );

		$blocked = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/unverified', $assistant_id );

		remove_all_filters( 'wp_mcp_ai_okf_skill_bridge_min_trust' );

		$this->assertWPError( $blocked );
		$this->assertSame( 'wp_mcp_ai_okf_concept_untrusted', $blocked->get_error_code() );

		// Without the gate the same concept loads.
		$loaded = WP_MCP_AI_OKF_Skill_Bridge::resolve( null, 'site-knowledge:policies/unverified', $assistant_id );
		$this->assertIsArray( $loaded );
		$this->assertStringContainsString( 'unverified', $loaded['instructions'] );
	}

	/**
	 * Test the Base load_skill tool end-to-end with the bridge.
	 */
	public function test_load_skill_tool_integration() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant( array( 'site-knowledge:policies/refunds' ) );

		$tool = new WP_MCP_AI_Tool_Load_Skill();

		$result = $tool->execute(
			array( 'name' => 'site-knowledge:policies/refunds' ),
			array( 'assistant_id' => $assistant_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'site-knowledge:policies/refunds', $result['name'] );
		$this->assertStringContainsString( 'Refunds within 30 days.', $result['instructions'] );

		// A non-granted concept is rejected through the tool as well.
		$rejected = $tool->execute(
			array( 'name' => 'site-knowledge:policies/drafts' ),
			array( 'assistant_id' => $assistant_id )
		);
		$this->assertWPError( $rejected );
		$this->assertSame( 'wp_mcp_ai_okf_concept_not_assigned', $rejected->get_error_code() );

		// A plain installed skill still resolves through the registry path.
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->load_skills( true );
		if ( $registry->get_skill( 'code-reviewer' ) ) {
			$skills_assistant = $this->create_assistant( array( 'code-reviewer' ) );
			$plain            = $tool->execute(
				array( 'name' => 'code-reviewer' ),
				array( 'assistant_id' => $skills_assistant )
			);
			$this->assertIsArray( $plain );
			$this->assertStringContainsString( 'code-reviewer', $plain['name'] );
		}
	}

	/**
	 * Test that the metabox save path keeps only valid grants.
	 */
	public function test_metabox_save_sanitizes_grants() {
		$this->seed_bundle();
		$assistant_id = $this->create_assistant();

		$_POST['wp_mcp_ai_okf_concepts_nonce'] = wp_create_nonce( WP_MCP_AI_OKF_Concepts_Metabox::NONCE_ACTION );
		$_POST['wp_mcp_ai_okf_grants']         = array(
			'site-knowledge:policies/refunds',
			'ghost-bundle:policies/refunds', // Bundle does not exist → dropped.
			'not-a-reference',               // No colon → dropped.
			'site-knowledge:policies/refunds', // Duplicate → deduped.
		);

		WP_MCP_AI_OKF_Concepts_Metabox::save( $assistant_id, get_post( $assistant_id ) );

		$stored = get_post_meta( $assistant_id, WP_MCP_AI_OKF_Skill_Bridge::META_GRANTS, true );

		$this->assertSame( array( 'site-knowledge:policies/refunds' ), $stored );
	}
}
