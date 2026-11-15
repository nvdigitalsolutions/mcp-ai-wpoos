<?php
/**
 * PHPUnit tests for the local content controller (Proposal 135 §D4 / Phase 5):
 * CPT registration, the dual-shape document (content doc + envelope aliases),
 * draft-by-default creates, the publish gate, and the preview-token seam.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\REST\BaseController;
use NvoosAstroBridge\REST\PreviewToken;
use NvoosAstroBridge\Schema;

/**
 * Local content REST tests.
 *
 * @covers \NvoosAstroBridge\REST\LocalController
 * @covers \NvoosAstroBridge\REST\PreviewToken
 * @covers \NvoosAstroBridge\PostTypes\ForgeLocal
 * @group nvoos-astro-bridge
 */
class TestLocalController extends WP_Test_REST_TestCase {

	/**
	 * Admin user ID used by authenticated-path assertions.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * The boot cookie this class removes for anonymous-path assertions.
	 *
	 * The root bootstrap logs in an administrator, so the contract's
	 * cookie-restore helper (AuthSupport) resurrects that session inside
	 * `get_item()` — a "public" draft read would answer 200 instead of 404.
	 * Removing the cookie makes the unauthenticated paths genuinely
	 * unauthenticated; tearDown() puts it back for the suites after this one.
	 *
	 * @var string|null
	 */
	private $boot_cookie;

	/**
	 * Set up: REST routes, an administrator, no ambient session.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		$this->admin_id    = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->boot_cookie = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? (string) $_COOKIE[ LOGGED_IN_COOKIE ] : null;

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		wp_set_current_user( 0 );
	}

	/**
	 * Restore the process-wide boot cookie.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null !== $this->boot_cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $this->boot_cookie;
		}
		parent::tearDown();
	}

	/**
	 * Create a local content item.
	 *
	 * @param array<string, mixed> $args wp_insert_post args.
	 * @return int Post ID.
	 */
	private function create_local( array $args ): int {
		return wp_insert_post(
			array_merge(
				array(
					'post_type'   => Schema::CPT_FORGE_LOCAL,
					'post_status' => 'publish',
				),
				$args
			)
		);
	}

	/**
	 * Dispatch a GET against the local collection.
	 *
	 * @param string               $path   Path under the namespace.
	 * @param array<string, mixed> $params Query params.
	 * @return WP_REST_Response
	 */
	private function get( string $path, array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1' . $path );
		if ( array() !== $params ) {
			$request->set_query_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The CPT is private and invisible to wp/v2 — the bridge is the only door.
	 */
	public function test_cpt_is_registered_private(): void {
		$object = get_post_type_object( Schema::CPT_FORGE_LOCAL );

		$this->assertNotNull( $object );
		$this->assertFalse( $object->public );
		$this->assertFalse( $object->show_in_rest );
		$this->assertFalse( $object->has_archive );
	}

	/**
	 * The document carries the content shape AND the forge envelope aliases.
	 */
	public function test_document_shape(): void {
		$id = $this->create_local(
			array(
				'post_title'   => 'Case Study',
				'post_name'    => 'case-study',
				'post_content' => '<p>Body</p>',
				'post_excerpt' => 'Blurb',
			)
		);
		update_post_meta( $id, 'rank_math_title', 'SEO Case Study' );

		$data = $this->get( '/local/' . $id )->get_data();

		// The /manage (Proposal 106 §1.2) shape.
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'case-study', $data['slug'] );
		$this->assertSame( 'Case Study', $data['title'] );
		$this->assertSame( 'Blurb', $data['excerpt'] );
		$this->assertSame( '<p>Body</p>', $data['content'] );
		$this->assertSame( 'SEO Case Study', $data['seoTitle'] );
		$this->assertSame( 'publish', $data['status'] );

		// The forge envelope aliases + the source marker.
		$this->assertSame( 'Case Study', $data['name'] );
		$this->assertSame( '<p>Body</p>', $data['description'] );
		$this->assertSame( 'local', $data['source'] );
	}

	/**
	 * Public listings are publish-only.
	 */
	public function test_listing_excludes_drafts(): void {
		$live  = $this->create_local(
			array(
				'post_title' => 'Live',
				'post_name'  => 'live',
			)
		);
		$draft = $this->create_local(
			array(
				'post_title'  => 'Hidden',
				'post_name'   => 'hidden',
				'post_status' => 'draft',
			)
		);

		$ids = wp_list_pluck( $this->get( '/local' )->get_data()['docs'], 'id' );

		$this->assertContains( $live, $ids );
		$this->assertNotContains( $draft, $ids );
	}

	/**
	 * `where[slug][equals]` filters the listing (the loader's slug lookup).
	 */
	public function test_where_slug_equals(): void {
		$wanted = $this->create_local(
			array(
				'post_title' => 'Wanted',
				'post_name'  => 'wanted',
			)
		);
		$this->create_local(
			array(
				'post_title' => 'Other',
				'post_name'  => 'other',
			)
		);

		$docs = $this->get(
			'/local',
			array( 'where' => array( 'slug' => array( 'equals' => 'wanted' ) ) )
		)->get_data()['docs'];

		$this->assertCount( 1, $docs );
		$this->assertSame( $wanted, $docs[0]['id'] );
	}

	/**
	 * CRUD: create is a draft, publishing is the explicit transition, delete.
	 */
	public function test_crud_lifecycle(): void {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/local' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'   => 'Draft First',
					'content' => '<p>Hi</p>',
					'excerpt' => 'Short',
				)
			)
		);
		$created = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $created->get_status() );
		$this->assertSame( 'draft', $created->get_data()['status'] );
		$id = (int) $created->get_data()['id'];

		$request = new WP_REST_Request( 'PUT', '/nvoos-commerce/v1/local/' . $id );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'status' => 'publish' ) ) );
		$published = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $published->get_status() );
		$this->assertSame( 'publish', $published->get_data()['status'] );
		$this->assertArrayHasKey( 'approvedBy', $published->get_data() );

		$request = new WP_REST_Request( 'DELETE', '/nvoos-commerce/v1/local/' . $id );
		$this->assertEquals( 204, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * Writes (and therefore ever publishing) require `manage_astro`.
	 */
	public function test_writes_require_capability(): void {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/local' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'title' => 'Nope' ) ) );

		$this->assertEquals( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * A preview token unlocks exactly its own draft, anonymously.
	 */
	public function test_preview_token_reads_draft(): void {
		$draft = $this->create_local(
			array(
				'post_title'  => 'Unpublished',
				'post_name'   => 'unpublished',
				'post_status' => 'draft',
			)
		);

		// Without a token a draft does not exist publicly (404, no existence leak).
		$this->assertEquals( 404, $this->get( '/local/' . $draft )->get_status() );

		$issued = PreviewToken::issue( $draft );
		$doc    = $this->get( '/local/' . $draft, array( 'preview_token' => $issued['token'] ) );

		$this->assertEquals( 200, $doc->get_status() );
		$this->assertSame( 'draft', $doc->get_data()['status'] );
		$this->assertSame( 'Unpublished', $doc->get_data()['title'] );
	}

	/**
	 * A token is scoped to one post: it cannot be re-pointed at another id.
	 */
	public function test_preview_token_is_post_scoped(): void {
		$first  = $this->create_local(
			array(
				'post_title'  => 'First',
				'post_name'   => 'first',
				'post_status' => 'draft',
			)
		);
		$second = $this->create_local(
			array(
				'post_title'  => 'Second',
				'post_name'   => 'second',
				'post_status' => 'draft',
			)
		);

		$token = PreviewToken::issue( $first )['token'];

		$this->assertEquals( 404, $this->get( '/local/' . $second, array( 'preview_token' => $token ) )->get_status() );
	}

	/**
	 * The listing honours the token too — the draft-aware route resolves a
	 * draft by its slug (how the forged page renders a preview at a real URL).
	 */
	public function test_preview_listing_returns_the_draft_at_its_slug(): void {
		$draft = $this->create_local(
			array(
				'post_title'  => 'Behind The Scenes',
				'post_name'   => 'behind-the-scenes',
				'post_status' => 'draft',
			)
		);

		$token = PreviewToken::issue( $draft )['token'];

		$docs = $this->get(
			'/local',
			array(
				'preview_token' => $token,
				'where'         => array( 'slug' => array( 'equals' => 'behind-the-scenes' ) ),
			)
		)->get_data()['docs'];

		$this->assertCount( 1, $docs );
		$this->assertSame( $draft, $docs[0]['id'] );

		// The same slug WITHOUT the token stays invisible.
		$public = $this->get(
			'/local',
			array( 'where' => array( 'slug' => array( 'equals' => 'behind-the-scenes' ) ) )
		)->get_data()['docs'];

		$this->assertSame( array(), $public );
	}

	/**
	 * Garbage, tampered and expired tokens are all rejected.
	 */
	public function test_invalid_tokens_are_rejected(): void {
		$draft = $this->create_local(
			array(
				'post_title'  => 'Guarded',
				'post_name'   => 'guarded',
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 0, PreviewToken::verify( '' ) );
		$this->assertSame( 0, PreviewToken::verify( 'not-a-token' ) );
		$this->assertSame( 0, PreviewToken::verify( array( 'nope' ) ) );

		$valid    = PreviewToken::issue( $draft )['token'];
		$tampered = substr( $valid, 0, -2 ) . ( 'aa' === substr( $valid, -2 ) ? 'bb' : 'aa' );
		$this->assertSame( 0, PreviewToken::verify( $tampered ) );

		// An expiry in the past is a hard stop (1s floor, then wait it out).
		$expiring = PreviewToken::issue( $draft, 1 )['token'];
		$this->assertSame( $draft, PreviewToken::verify( $expiring ) );
		sleep( 2 );
		$this->assertSame( 0, PreviewToken::verify( $expiring ) );
	}

	/**
	 * Minting a token is itself a `manage_astro` action.
	 */
	public function test_preview_token_mint_requires_capability(): void {
		$draft = $this->create_local(
			array(
				'post_title'  => 'Mint Me',
				'post_name'   => 'mint-me',
				'post_status' => 'draft',
			)
		);

		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/local/' . $draft . '/preview-token' );
		$this->assertEquals( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * The mint returns a token, its expiry, and the ready-to-open public URL.
	 */
	public function test_preview_token_mint_returns_a_public_url(): void {
		update_option( Schema::OPTION_PUBLIC_ORIGIN, 'https://client.example' );

		$draft = $this->create_local(
			array(
				'post_title'  => 'Preview Me',
				'post_name'   => 'preview-me',
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/local/' . $draft . '/preview-token' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertNotEmpty( $data['token'] );
		$this->assertSame( 'preview-me', $data['slug'] );
		$this->assertStringStartsWith( 'https://client.example/api/preview?token=', $data['previewUrl'] );
		$this->assertStringContainsString( 'slug=preview-me', $data['previewUrl'] );

		wp_set_current_user( 0 );
		delete_option( Schema::OPTION_PUBLIC_ORIGIN );
	}

	/**
	 * Without a configured public origin there is no preview URL — the CMS host
	 * serves no `/api/preview`, so fabricating one would open a 404.
	 */
	public function test_preview_token_mint_without_a_public_origin(): void {
		delete_option( Schema::OPTION_PUBLIC_ORIGIN );

		$draft = $this->create_local(
			array(
				'post_title'  => 'No Origin',
				'post_name'   => 'no-origin',
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $this->admin_id );

		$request  = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/local/' . $draft . '/preview-token' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNotSame( '', $response->get_data()['token'] );
		$this->assertSame( '', $response->get_data()['previewUrl'] );

		wp_set_current_user( 0 );
	}

	/**
	 * A preview response is NEVER cacheable — a cached draft would be served
	 * to every visitor (the B1 filter must answer no).
	 */
	public function test_preview_responses_are_not_cached(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/nvoos-commerce/v1/local/123' );
		$request->set_route( '/nvoos-commerce/v1/local/(?P<id>\d+)' );
		$request->set_param( 'id', 123 );
		$request->set_param( 'preview_token', 'whatever-token' );

		$response = BaseController::set_cache_headers_on_public_gets(
			rest_ensure_response( array( 'id' => 123 ) ),
			rest_get_server(),
			$request
		);

		$headers = $response->get_headers();
		$this->assertArrayNotHasKey( 'Cache-Control', $headers );
		$this->assertArrayNotHasKey( 'Cache-Tag', $headers );
	}
}
