<?php
/**
 * Tests for the OKF Auto-Enrichment Agent (Pro addon, roadmap Phase 7).
 *
 * Covers WP_MCP_AI_OKF_Enrichment_Agent: bundle creation on first run,
 * concept generation from published content, post-type filtering, cross-link
 * extraction, term concepts, protected-bundle rejection, idempotency, and
 * error paths. Direct unit tests only — no AJAX harness.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_OKF_Enrichment_Test extends WP_UnitTestCase {

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

		if ( ! class_exists( 'WP_MCP_AI_OKF_Enrichment_Agent' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_OKF_Enrichment_Agent (Pro) is not available in this environment.' );
		}

		$this->test_uploads_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-okf-enrichment-' . uniqid();
		mkdir( $this->test_uploads_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		wp_set_current_user( 1 ); // Administrator.
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( class_exists( 'WP_MCP_AI_OKF_Enrichment_Agent' ) ) {
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
	 * Create a published post with a deterministic slug.
	 *
	 * @param string $title   Post title.
	 * @param string $content Post content.
	 * @param string $slug    Post slug.
	 * @param string $type    Post type.
	 * @return int Post ID.
	 */
	private function make_post( $title, $content, $slug, $type = 'post' ) {
		return $this->factory->post->create(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
			)
		);
	}

	/**
	 * Count concept (.md) files under a bundle, excluding reserved index/log.
	 *
	 * @param string $bundle Bundle name.
	 * @return int Concept file count.
	 */
	private function count_concept_files( $bundle ) {
		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$reader  = new WP_MCP_AI_OKF_Reader( $manager->resolve_bundle_root( $bundle ) );

		return count( $reader->search( array() ) );
	}

	/**
	 * Test the happy path: concepts are generated into a fresh bundle with
	 * provenance frontmatter and regenerated indexes.
	 */
	public function test_enrich_happy_path() {
		$post_id = $this->make_post( 'Hello World', 'This is the hello post content.', 'hello-world' );
		$this->make_post( 'Second Post', 'Content of the second post.', 'second-post' );

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'post' ),
				'limit'      => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'site-content', $result['bundle'] );
		$this->assertSame( 2, $result['concepts'] );
		$this->assertSame( 2, $result['created'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( array(), $result['errors'] );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( 'site-content' );

		$this->assertTrue( is_dir( $root ) );
		$this->assertFileExists( $root . '/post/hello-world.md' );
		$this->assertFileExists( $root . '/post/second-post.md' );
		$this->assertFileExists( $root . '/index.md' );
		$this->assertFileExists( $root . '/post/index.md' );

		// Provenance frontmatter follows the Phase C schema.
		$reader  = new WP_MCP_AI_OKF_Reader( $root );
		$concept = $reader->get_concept( 'post/hello-world' );
		$fm      = $concept['frontmatter'];

		$this->assertSame( 'Post', $fm['type'] );
		$this->assertSame( 'Hello World', $fm['title'] );
		$this->assertSame( 'process:okf-enrichment', $fm['generated']['by'] );
		$this->assertSame( get_permalink( $post_id ), $fm['resource'] );
		$this->assertSame( 'wp-post-' . $post_id, $fm['sources'][0]['id'] );
		$this->assertStringContainsString( 'This is the hello post content.', $concept['body'] );
	}

	/**
	 * Test that re-running enrichment is idempotent — no duplicated files.
	 */
	public function test_enrich_is_idempotent() {
		$this->make_post( 'Hello World', 'The hello post content.', 'hello-world' );
		$this->make_post( 'Second Post', 'The second post content.', 'second-post' );

		$agent = new WP_MCP_AI_OKF_Enrichment_Agent();
		$args  = array(
			'bundle'     => 'site-content',
			'post_types' => array( 'post' ),
		);

		$first  = $agent->enrich( $args );
		$second = $agent->enrich( $args );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertSame( 2, $this->count_concept_files( 'site-content' ) );
	}

	/**
	 * Test that the protected skill-knowledge bundle is refused.
	 */
	public function test_enrich_rejects_protected_bundle() {
		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich( array( 'bundle' => 'skill-knowledge' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_protected_bundle', $result->get_error_code() );
	}

	/**
	 * Test that invalid bundle names are rejected by the manager.
	 */
	public function test_enrich_rejects_invalid_bundle_name() {
		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich( array( 'bundle' => 'Bad Name!' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'okf_invalid_bundle', $result->get_error_code() );
	}

	/**
	 * Test that no matching published content produces a WP_Error.
	 */
	public function test_enrich_no_posts_returns_error() {
		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'page' ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_okf_enrichment_no_posts', $result->get_error_code() );
	}

	/**
	 * Test that post-type filtering only crawls the requested types.
	 */
	public function test_enrich_respects_post_type_filter() {
		$this->make_post( 'A Post', 'Post content.', 'a-post', 'post' );
		$this->make_post( 'A Page', 'Page content.', 'a-page', 'page' );

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'page' ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['concepts'] );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( 'site-content' );

		$this->assertFileExists( $root . '/page/a-page.md' );
		$this->assertFalse( is_dir( $root . '/post' ) );
	}

	/**
	 * Test that the limit argument caps the number of crawled posts.
	 */
	public function test_enrich_respects_limit() {
		$this->make_post( 'One', 'Content one.', 'one' );
		$this->make_post( 'Two', 'Content two.', 'two' );

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'post' ),
				'limit'      => 1,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['concepts'] );
		$this->assertSame( 1, $this->count_concept_files( 'site-content' ) );
	}

	/**
	 * Test that internal links between crawled posts become cross-links.
	 */
	public function test_enrich_extracts_cross_links() {
		$target_id = $this->make_post( 'Refund Policy', 'Refund rules.', 'refund-policy' );
		$this->make_post(
			'Returns Guide',
			'See <a href="' . get_permalink( $target_id ) . '">Refund details</a> for rules.',
			'returns-guide'
		);

		$agent = new WP_MCP_AI_OKF_Enrichment_Agent();
		$agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'post' ),
			)
		);

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$reader  = new WP_MCP_AI_OKF_Reader( $manager->resolve_bundle_root( 'site-content' ) );
		$concept = $reader->get_concept( 'post/returns-guide' );

		$this->assertStringContainsString( 'Refund details', $concept['body'] );
		$this->assertStringContainsString( '(/post/refund-policy.md)', $concept['body'] );
	}

	/**
	 * Test that public taxonomy terms become term concepts when requested.
	 */
	public function test_enrich_include_terms() {
		$term_id = $this->factory->term->create(
			array(
				'taxonomy'    => 'category',
				'name'        => 'News',
				'description' => 'Latest news and updates.',
			)
		);
		$post_id = $this->factory->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'News Item',
				'post_name'    => 'news-item',
				'post_content' => 'A news item.',
			)
		);
		wp_set_post_categories( $post_id, array( $term_id ) );

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'        => 'site-content',
				'post_types'    => array( 'post' ),
				'include_terms' => true,
			)
		);

		$this->assertIsArray( $result );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( 'site-content' );

		$this->assertFileExists( $root . '/terms/category/news.md' );

		$reader  = new WP_MCP_AI_OKF_Reader( $root );
		$concept = $reader->get_concept( 'terms/category/news' );

		$this->assertSame( 'Term', $concept['frontmatter']['type'] );
		$this->assertStringContainsString( 'Latest news and updates.', $concept['frontmatter']['description'] );
	}

	/**
	 * Test that omit-content keeps the concept body minimal.
	 */
	public function test_enrich_can_omit_content() {
		$this->make_post( 'Hello World', 'Long body content that should be omitted.', 'hello-world' );

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'          => 'site-content',
				'post_types'      => array( 'post' ),
				'include_content' => false,
			)
		);

		$this->assertIsArray( $result );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$reader  = new WP_MCP_AI_OKF_Reader( $manager->resolve_bundle_root( 'site-content' ) );
		$concept = $reader->get_concept( 'post/hello-world' );

		$this->assertStringNotContainsString( 'Long body content that should be omitted.', $concept['body'] );
		$this->assertStringContainsString( '# Summary', $concept['body'] );
	}

	/**
	 * Test the enrichment description filter upgrades the deterministic excerpt.
	 */
	public function test_enrich_description_filter() {
		$this->make_post( 'Hello World', 'Deterministic excerpt source.', 'hello-world' );

		add_filter(
			'wp_mcp_ai_okf_enrichment_description',
			static function () {
				return 'AI-generated summary.';
			}
		);

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich(
			array(
				'bundle'     => 'site-content',
				'post_types' => array( 'post' ),
			)
		);

		remove_all_filters( 'wp_mcp_ai_okf_enrichment_description' );

		$this->assertIsArray( $result );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$reader  = new WP_MCP_AI_OKF_Reader( $manager->resolve_bundle_root( 'site-content' ) );
		$concept = $reader->get_concept( 'post/hello-world' );

		$this->assertSame( 'AI-generated summary.', $concept['frontmatter']['description'] );
	}
}
