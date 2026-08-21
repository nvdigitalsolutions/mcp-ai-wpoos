<?php
/**
 * Tests for the Pro OKF tools (roadmap Phases 7-8):
 * okf_enrich_site_content and route_knowledge_query.
 *
 * Covers registration, canonical success envelopes, capability gates, and
 * end-to-end execution through the enrichment agent and hybrid router.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Phase_7_8_Tools_Test extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_Tool_OKF_Enrich_Site_Content' ) ) {
			$this->markTestSkipped( 'Pro OKF tools are not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-78-tools-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_Tool_OKF_Enrich_Site_Content' ) ) {
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
	 * Test that both Pro OKF tools are registered in the tool registry.
	 */
	public function test_pro_okf_tools_are_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$this->assertTrue( $registry->is_tool_registered( 'okf_enrich_site_content' ) );
		$this->assertTrue( $registry->is_tool_registered( 'route_knowledge_query' ) );
	}

	/**
	 * Test the enrichment tool's declared capability.
	 */
	public function test_enrich_tool_requires_manage_options() {
		$tool = new WP_MCP_AI_Tool_OKF_Enrich_Site_Content();

		$this->assertSame( 'manage_options', $tool->get_required_capability() );
		$this->assertSame( 'okf_enrich_site_content', $tool->get_slug() );
	}

	/**
	 * Test the enrichment tool end-to-end with a canonical envelope.
	 */
	public function test_enrich_tool_execute_success() {
		wp_set_current_user( 1 ); // Administrator.

		$this->factory->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Hello World',
				'post_name'    => 'hello-world',
				'post_content' => 'The hello post content.',
			)
		);

		$tool   = new WP_MCP_AI_Tool_OKF_Enrich_Site_Content();
		$result = $tool->execute(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'post' ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'site-content', $result['bundle'] );
		$this->assertSame( 1, $result['concepts'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( array(), $result['errors'] );
	}

	/**
	 * Test the enrichment tool rejects users without manage_options.
	 */
	public function test_enrich_tool_capability_gate() {
		wp_set_current_user( 0 ); // Logged out.

		$tool   = new WP_MCP_AI_Tool_OKF_Enrich_Site_Content();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test the enrichment tool surfaces agent errors as WP_Error.
	 */
	public function test_enrich_tool_protected_bundle_error() {
		wp_set_current_user( 1 ); // Administrator.

		$tool   = new WP_MCP_AI_Tool_OKF_Enrich_Site_Content();
		$result = $tool->execute( array( 'bundle' => 'skill-knowledge' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_protected_bundle', $result->get_error_code() );
	}

	/**
	 * Test the route tool's declared capability (read for all logged-in users).
	 */
	public function test_route_tool_requires_read() {
		$tool = new WP_MCP_AI_Tool_Route_Knowledge_Query();

		$this->assertSame( 'read', $tool->get_required_capability() );
		$this->assertSame( 'route_knowledge_query', $tool->get_slug() );
	}

	/**
	 * Test the route tool end-to-end with a canonical envelope.
	 */
	public function test_route_tool_execute_success() {
		wp_set_current_user( 1 ); // Administrator.

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$manager->create_bundle( 'site-knowledge' );
		$writer = new WP_MCP_AI_OKF_Writer( $manager->resolve_bundle_root( 'site-knowledge' ) );
		$writer->write_concept(
			'policies/refunds',
			array(
				'type'        => 'Policy',
				'title'       => 'Refund Policy',
				'description' => 'Refund policy for all products.',
			),
			'# Refund Policy' . "\n\n" . 'Refunds within 30 days.'
		);

		$tool   = new WP_MCP_AI_Tool_Route_Knowledge_Query();
		$result = $tool->execute(
			array(
				'query'  => 'What is the refund policy?',
				'bundle' => 'site-knowledge',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'okf', $result['primary'] );
		$this->assertSame( 'okf', $result['plan'][0]['source'] );
		$this->assertNotEmpty( $result['results'] );
		$this->assertSame( 'policies/refunds', $result['results'][0]['concept_id'] );
	}

	/**
	 * Test the route tool requires a query.
	 */
	public function test_route_tool_requires_query() {
		wp_set_current_user( 1 ); // Administrator.

		$tool   = new WP_MCP_AI_Tool_Route_Knowledge_Query();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_params', $result->get_error_code() );
	}

	/**
	 * Test the route tool rejects logged-out users.
	 */
	public function test_route_tool_capability_gate() {
		wp_set_current_user( 0 ); // Logged out.

		$tool   = new WP_MCP_AI_Tool_Route_Knowledge_Query();
		$result = $tool->execute( array( 'query' => 'policy' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test the route tool degrades gracefully when the bundle is missing.
	 */
	public function test_route_tool_missing_bundle_note() {
		wp_set_current_user( 1 ); // Administrator.

		$tool   = new WP_MCP_AI_Tool_Route_Knowledge_Query();
		$result = $tool->execute(
			array(
				'query'  => 'What is the refund policy?',
				'bundle' => 'ghost-bundle',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'okf', $result['primary'] );
		$this->assertSame( array(), $result['results'] );
		$this->assertNotEmpty( $result['note'] );
	}

	/**
	 * Test the route tool honours a paper-primary classification (no OKF lookup).
	 */
	public function test_route_tool_paper_primary_skips_okf_lookup() {
		wp_set_current_user( 1 ); // Administrator.

		$tool   = new WP_MCP_AI_Tool_Route_Knowledge_Query();
		$result = $tool->execute(
			array(
				'query'  => 'Show me the incident history',
				'bundle' => 'ghost-bundle', // Would error if OKF lookup ran.
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'paper', $result['primary'] );
		$this->assertSame( array(), $result['results'] );
		$this->assertSame( '', $result['note'] );
	}
}
