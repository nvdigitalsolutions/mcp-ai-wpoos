<?php
/**
 * PHPUnit tests for the CMS pages controller (Proposal 106 §1.2):
 * transform shape (incl. the_content shortcode execution), draft
 * exclusion (synthetic draft id 9902), where[slug][equals], CRUD gates,
 * and the contentRendered fallback.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * CMS pages REST tests.
 *
 * @covers \NvoosAstroBridge\REST\PagesController
 * @covers \NvoosAstroBridge\REST\PostTypeController
 * @covers \NvoosAstroBridge\REST\ContentTransform
 * @group nvoos-astro-bridge
 */
class TestPagesController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up test state: REST routes, admin, deterministic shortcode.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Deterministic shortcode — the_content must execute it (plan §7).
		add_shortcode(
			'fixture_seo',
			static function (): string {
				return 'SEO-RESULT';
			}
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Drop the fixture shortcode between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_shortcode( 'fixture_seo' );
		parent::tearDown();
	}

	/**
	 * Create a published page with the given args.
	 *
	 * @param array<string, mixed> $args wp_insert_post args (post_type/status preset).
	 * @return int Page ID.
	 */
	private function create_page( array $args ): int {
		return wp_insert_post(
			array_merge(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * The transform emits the exact §1.2 document shape.
	 */
	public function test_transform_shape(): void {
		$page_id = $this->create_page(
			array(
				'post_title'   => 'About Us',
				'post_name'    => 'about',
				'post_content' => 'Welcome [fixture_seo] here',
				'post_excerpt' => 'Short blurb',
			)
		);
		update_post_meta( $page_id, 'rank_math_title', 'Rank About Title' );
		update_post_meta( $page_id, 'rank_math_description', 'Rank About Description' );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages/' . $page_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( $page_id, $data['id'] );
		$this->assertSame( 'about', $data['slug'] );
		$this->assertSame( 'About Us', $data['title'] );
		$this->assertSame( 'Short blurb', $data['excerpt'] );
		$this->assertSame( 'Welcome [fixture_seo] here', $data['content'] );
		$this->assertStringContainsString( 'SEO-RESULT', $data['contentRendered'] );
		$this->assertStringContainsString( '<p>', $data['contentRendered'] );
		$this->assertSame( 'page', $data['type'] );
		$this->assertNull( $data['featuredImage'] );
		$this->assertSame( 'Rank About Title', $data['seoTitle'] );
		$this->assertSame( 'Rank About Description', $data['seoDescription'] );
		$this->assertNull( $data['category'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['createdAt'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['updatedAt'] );
		$this->assertSame( 'publish', $data['status'] );
	}

	/**
	 * SEO meta falls back Yoast → title/excerpt when Rank Math is absent.
	 */
	public function test_seo_meta_fallbacks(): void {
		$yoast_page = $this->create_page(
			array(
				'post_title'   => 'Yoast Page',
				'post_content' => 'Body',
				'post_excerpt' => 'Yoast excerpt',
			)
		);
		update_post_meta( $yoast_page, '_yoast_wpseo_title', 'Yoast Title' );
		update_post_meta( $yoast_page, '_yoast_wpseo_metadesc', 'Yoast Desc' );

		$bare_page = $this->create_page(
			array(
				'post_title'   => 'Bare Page',
				'post_content' => 'Bare body text',
			)
		);

		$yoast_doc = rest_get_server()
			->dispatch( new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages/' . $yoast_page ) )
			->get_data();
		$this->assertSame( 'Yoast Title', $yoast_doc['seoTitle'] );
		$this->assertSame( 'Yoast Desc', $yoast_doc['seoDescription'] );

		$bare_doc = rest_get_server()
			->dispatch( new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages/' . $bare_page ) )
			->get_data();
		$this->assertSame( 'Bare Page', $bare_doc['seoTitle'] );
		$this->assertNotSame( '', $bare_doc['seoDescription'] );
	}

	/**
	 * The contentRendered fallback returns the raw content when the render
	 * is empty.
	 */
	public function test_content_rendered_falls_back_to_raw(): void {
		$page_id = $this->create_page(
			array(
				'post_title'   => 'Empty Page',
				'post_content' => '',
			)
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages/' . $page_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( '', $response->get_data()['content'] );
		$this->assertSame( '', $response->get_data()['contentRendered'] );
	}

	/**
	 * Public listings are publish-only — the synthetic draft id 9902 is
	 * excluded (the fixture-server pin).
	 */
	public function test_listing_excludes_drafts(): void {
		$published = $this->create_page(
			array(
				'post_title' => 'Live Page',
				'post_name'  => 'live-page',
			)
		);

		$draft_id = wp_insert_post(
			array(
				'import_id'    => 9902,
				'post_type'    => 'page',
				'post_title'   => 'Draft Pin',
				'post_content' => 'hidden',
				'post_status'  => 'draft',
			)
		);
		if ( 9902 !== $draft_id ) {
			// The synthetic pin may already exist from a previous run —
			// clear it and recreate so the pin stays deterministic.
			wp_delete_post( 9902, true );
			$draft_id = wp_insert_post(
				array(
					'import_id'    => 9902,
					'post_type'    => 'page',
					'post_title'   => 'Draft Pin',
					'post_content' => 'hidden',
					'post_status'  => 'draft',
				)
			);
		}
		if ( 9902 !== $draft_id ) {
			$this->markTestSkipped( 'Post ID 9902 cannot be allocated in this environment.' );
		}

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );

		$this->assertContains( $published, $ids );
		$this->assertNotContains( 9902, $ids );
	}

	/**
	 * The where[slug][equals] clause filters the listing.
	 */
	public function test_where_slug_equals(): void {
		$about = $this->create_page(
			array(
				'post_title' => 'About',
				'post_name'  => 'about',
			)
		);
		$terms = $this->create_page(
			array(
				'post_title' => 'Terms',
				'post_name'  => 'terms',
			)
		);

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages' );
		$request->set_query_params( array( 'where' => array( 'slug' => array( 'equals' => 'about' ) ) ) );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );
		$this->assertContains( $about, $ids );
		$this->assertNotContains( $terms, $ids );

		// Non-matching slug → empty listing.
		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/pages' );
		$request->set_query_params( array( 'where' => array( 'slug' => array( 'equals' => 'nope' ) ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( array(), $response->get_data()['docs'] );
	}

	/**
	 * CRUD: create (draft default — Proposal 107 G4), publish via the
	 * explicit transition, update, delete.
	 */
	public function test_crud_lifecycle(): void {
		wp_set_current_user( $this->admin_id );

		// Create — defaults to draft (107 G4: no accidental publishes).
		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/content/pages' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'   => 'New Page',
					'content' => '<p>Hello</p>',
					'excerpt' => 'Hi',
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'New Page', $data['title'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertSame( 'Hi', $data['excerpt'] );

		// Update — the approver (publish_posts) flips it live.
		$request = new WP_REST_Request( 'PUT', '/nvoos-commerce/v1/content/pages/' . $data['id'] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'  => 'Renamed',
					'status' => 'publish',
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( 'Renamed', $response->get_data()['title'] );
		$this->assertSame( 'publish', $response->get_data()['status'] );

		// Delete.
		$request  = new WP_REST_Request( 'DELETE', '/nvoos-commerce/v1/content/pages/' . $data['id'] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 204, $response->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * Writes require the manage capability — a logged-in user without it
	 * gets 403 (the root bootstrap's admin cookie would otherwise restore
	 * a manage-capable session through the cookie-restore path).
	 */
	public function test_writes_require_capability(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/content/pages' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Nope' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_set_current_user( 0 );
	}
}
