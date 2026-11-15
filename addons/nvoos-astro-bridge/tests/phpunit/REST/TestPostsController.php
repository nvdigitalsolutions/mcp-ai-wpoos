<?php
/**
 * PHPUnit tests for the CMS posts controller (Proposal 106 §1.2):
 * transform shape with the first WP category, draft exclusion (synthetic
 * draft id 9902), where[slug][equals] + where[category][equals], category
 * assignment on writes, and CRUD gates.
 *
 * @package NvoosAstroBridge\Tests
 */

/**
 * CMS posts REST tests.
 *
 * @covers \NvoosAstroBridge\REST\PostsController
 * @covers \NvoosAstroBridge\REST\PostTypeController
 * @covers \NvoosAstroBridge\REST\ContentTransform
 * @group nvoos-astro-bridge
 */
class TestPostsController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Category ID used by the category fixtures.
	 *
	 * @var int
	 */
	private $lookbooks_id;

	/**
	 * Set up test state: REST routes, admin, deterministic shortcode.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->admin_id     = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->lookbooks_id = $this->factory->category->create( array( 'name' => 'Lookbooks' ) );

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
	 * Create a published post with the given args.
	 *
	 * @param array<string, mixed> $args wp_insert_post args (post_type/status preset).
	 * @return int Post ID.
	 */
	private function create_post( array $args ): int {
		return wp_insert_post(
			array_merge(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * The transform emits the §1.2 document with the first WP category.
	 */
	public function test_transform_shape_with_category(): void {
		$post_id = $this->create_post(
			array(
				'post_title'    => 'Lookbook Fall',
				'post_name'     => 'lookbook-fall',
				'post_content'  => 'Season [fixture_seo] picks',
				'post_excerpt'  => 'Autumn picks',
				'post_category' => array( $this->lookbooks_id ),
			)
		);

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/posts/' . $post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertSame( $post_id, $data['id'] );
		$this->assertSame( 'lookbook-fall', $data['slug'] );
		$this->assertSame( 'post', $data['type'] );
		$this->assertSame( 'Autumn picks', $data['excerpt'] );
		$this->assertStringContainsString( 'SEO-RESULT', $data['contentRendered'] );
		$this->assertSame(
			array(
				'id'   => $this->lookbooks_id,
				'name' => 'Lookbooks',
			),
			$data['category']
		);
		$this->assertSame( 'publish', $data['status'] );
	}

	/**
	 * Posts without a category emit category null (WordPress auto-assigns
	 * the default category, so the relationship is removed to exercise the
	 * null branch of the contract).
	 */
	public function test_post_without_category(): void {
		$post_id = $this->create_post( array( 'post_title' => 'No Category' ) );
		wp_delete_object_term_relationships( $post_id, 'category' );

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/posts/' . $post_id );
		$response = rest_get_server()->dispatch( $request );

		$this->assertNull( $response->get_data()['category'] );
	}

	/**
	 * Public listings are publish-only — the synthetic draft id 9902 is
	 * excluded.
	 */
	public function test_listing_excludes_drafts(): void {
		$published = $this->create_post( array( 'post_title' => 'Live Post' ) );

		$draft_id = wp_insert_post(
			array(
				'import_id'    => 9902,
				'post_type'    => 'post',
				'post_title'   => 'Draft Post Pin',
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
					'post_type'    => 'post',
					'post_title'   => 'Draft Post Pin',
					'post_content' => 'hidden',
					'post_status'  => 'draft',
				)
			);
		}
		if ( 9902 !== $draft_id ) {
			$this->markTestSkipped( 'Post ID 9902 cannot be allocated in this environment.' );
		}

		$request  = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/posts' );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );
		$this->assertContains( $published, $ids );
		$this->assertNotContains( 9902, $ids );
	}

	/**
	 * The where[slug][equals] clause filters the listing.
	 */
	public function test_where_slug_equals(): void {
		$matching = $this->create_post(
			array(
				'post_title' => 'Match',
				'post_name'  => 'match-slug',
			)
		);
		$other    = $this->create_post(
			array(
				'post_title' => 'Other',
				'post_name'  => 'other-slug',
			)
		);

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/posts' );
		$request->set_query_params( array( 'where' => array( 'slug' => array( 'equals' => 'match-slug' ) ) ) );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );
		$this->assertContains( $matching, $ids );
		$this->assertNotContains( $other, $ids );
	}

	/**
	 * The where[category][equals] clause filters the listing by WP category.
	 */
	public function test_where_category_equals(): void {
		$in_category = $this->create_post(
			array(
				'post_title'    => 'In Lookbooks',
				'post_category' => array( $this->lookbooks_id ),
			)
		);
		$outside     = $this->create_post( array( 'post_title' => 'Outside' ) );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/content/posts' );
		$request->set_query_params(
			array( 'where' => array( 'category' => array( 'equals' => $this->lookbooks_id ) ) )
		);
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( $response->get_data()['docs'], 'id' );
		$this->assertContains( $in_category, $ids );
		$this->assertNotContains( $outside, $ids );
	}

	/**
	 * Creating a post with a category assigns it (contract shape accepted).
	 */
	public function test_create_post_with_category(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/content/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'    => 'Categorized Post',
					'content'  => 'Body',
					'category' => array( 'id' => $this->lookbooks_id ),
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( $this->lookbooks_id, $data['category']['id'] );
		$this->assertSame(
			array( $this->lookbooks_id ),
			wp_get_post_categories( $data['id'] )
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Writes require the manage capability — a logged-in user without it
	 * gets 403.
	 */
	public function test_writes_require_capability(): void {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/content/posts' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Nope' ) ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 403, $response->get_status() );

		wp_set_current_user( 0 );
	}
}
