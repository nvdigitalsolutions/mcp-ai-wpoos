<?php
/**
 * PHPUnit tests for the Proposal 107 content write surface: the approval
 * state machine (draft-default creates, publish_posts-gated transitions,
 * approver audit), blocks/submittedBy round-trips, raw-HTML sanitization at
 * entry, revisions/restore, future scheduling, media alt, and the
 * fail-closed single-GET + status-filter gates.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * Content write-surface tests.
 *
 * @covers \NvoosAstroBridge\REST\PagesController
 * @covers \NvoosAstroBridge\REST\PostsController
 * @covers \NvoosAstroBridge\REST\PostTypeController
 * @covers \NvoosAstroBridge\REST\ContentTransform
 * @group nvoos-astro-bridge
 */
class TestContentWriteSurface extends WP_Test_REST_TestCase {

	/**
	 * Administrator — manage cap AND publish_posts (the approver).
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Contributor + manage cap, NO publish_posts — the submitting actor that
	 * can draft/pend but never flip a publication state.
	 *
	 * @var int
	 */
	private $submitter_id;

	/**
	 * Subscriber — no manage cap, no publish_posts. The deterministic
	 * non-manager gate assertions (the root bootstrap's admin cookie would
	 * otherwise restore a manage-capable session on user-0 requests).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Temp files created by media tests — removed in tearDown.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	/**
	 * Set up test state: REST routes, the two actors, the upload-action
	 * seam, and a clean slate for the pinned synthetic draft id (9902).
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		do_action( 'rest_api_init' );

		// The pinned draft id lingers across runs on the shared test DB —
		// pre-delete it so id-collision assertions stay deterministic.
		wp_delete_post( 9902, true );

		$this->admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$this->submitter_id = $this->factory->user->create( array( 'role' => 'contributor' ) );
		$submitter          = get_user_by( 'id', $this->submitter_id );
		if ( $submitter instanceof WP_User ) {
			$submitter->add_cap( Schema::CAP_MANAGE );
		}

		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// CLI test environments cannot produce real PHP uploads — switch the
		// handler to the sideload action (the TestMediaController seam).
		add_filter( 'funiq_bridge_media_upload_action', static fn() => 'wp_handle_sideload' );

		wp_set_current_user( 0 );
	}

	/**
	 * Remove temp files + the upload-action seam.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->tmp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmp_files = array();
		remove_all_filters( 'funiq_bridge_media_upload_action' );
		parent::tearDown();
	}

	/**
	 * Dispatch a JSON-body request against a route.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  REST route (without the namespace host part).
	 * @param array<string, mixed> $body   JSON body (null for no body).
	 * @param array<string, mixed> $params Query/file params.
	 * @return WP_REST_Response
	 */
	private function dispatch_json( string $method, string $route, ?array $body = null, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/nvoos-commerce/v1' . $route );
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A submitter-role create defaults to draft — agent-originated creates
	 * never publish by accident (G4), and the draft is invisible publicly
	 * (fail-closed single GET) but readable by a manager.
	 */
	public function test_create_defaults_to_draft_and_single_get_is_fail_closed(): void {
		wp_set_current_user( $this->submitter_id );

		$response = $this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'   => 'Agent Draft',
				'content' => '<p>Body</p>',
			)
		);

		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'draft', $data['status'] );
		$post_id = (int) $data['id'];

		// Public single GET: 404 — draft existence never leaks. Asserted with
		// a subscriber: user-0 dispatches would re-authenticate through the
		// bootstrap's admin cookie (the cookie-restore path is for real
		// managers, not an anonymous seam).
		wp_set_current_user( $this->subscriber_id );
		$anon = $this->dispatch_json( 'GET', '/content/posts/' . $post_id );
		$this->assertEquals( 404, $anon->get_status() );

		// Manager single GET: the doc resolves (the preview-read path).
		wp_set_current_user( $this->admin_id );
		$manager = $this->dispatch_json( 'GET', '/content/posts/' . $post_id );
		$this->assertEquals( 200, $manager->get_status() );
		$this->assertSame( 'draft', $manager->get_data()['status'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Publishing requires publish_posts — the unflippable gate (G4). The
	 * submitting role gets a typed 403; the approver publishes and the
	 * transition records the approver (G13).
	 */
	public function test_publish_gate_and_approver_audit(): void {
		// Submitter cannot publish on create.
		wp_set_current_user( $this->submitter_id );
		$denied = $this->dispatch_json(
			'POST',
			'/content/pages',
			array(
				'title'  => 'Cannot Publish',
				'status' => 'publish',
			)
		);
		$this->assertEquals( 403, $denied->get_status() );
		$this->assertSame( 'content_publish_forbidden', $denied->get_data()['code'] );

		// Submitter CAN draft, then cannot flip to publish on update.
		$draft    = $this->dispatch_json(
			'POST',
			'/content/pages',
			array(
				'title'  => 'Draft Then Gate',
				'status' => 'draft',
			)
		);
		$draft_id = (int) $draft->get_data()['id'];
		$flip     = $this->dispatch_json(
			'PATCH',
			'/content/pages/' . $draft_id,
			array( 'status' => 'publish' )
		);
		$this->assertEquals( 403, $flip->get_status() );

		// The approver publishes; approvedBy records who + when.
		wp_set_current_user( $this->admin_id );
		$published = $this->dispatch_json(
			'PATCH',
			'/content/pages/' . $draft_id,
			array( 'status' => 'publish' )
		);
		$this->assertEquals( 200, $published->get_status() );
		$this->assertSame( 'publish', $published->get_data()['status'] );
		$this->assertArrayHasKey( 'approvedBy', $published->get_data() );
		$this->assertStringContainsString( '@', (string) $published->get_data()['approvedBy'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Unpublishing a live doc is a publish-scope action too (G4) — the
	 * submitter cannot take a published page back to draft.
	 */
	public function test_unpublish_requires_publish_posts(): void {
		wp_set_current_user( $this->admin_id );
		$live    = $this->dispatch_json(
			'POST',
			'/content/pages',
			array(
				'title'  => 'Live Page',
				'status' => 'publish',
			)
		);
		$live_id = (int) $live->get_data()['id'];

		wp_set_current_user( $this->submitter_id );
		$unpublish = $this->dispatch_json(
			'PATCH',
			'/content/pages/' . $live_id,
			array( 'status' => 'draft' )
		);
		$this->assertEquals( 403, $unpublish->get_status() );
		$this->assertSame( 'content_publish_forbidden', $unpublish->get_data()['code'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Blocks and submittedBy round-trip through the doc; legacy docs (no
	 * meta) carry neither field — the additive-107 emission rule.
	 */
	public function test_blocks_and_submitter_round_trip(): void {
		wp_set_current_user( $this->submitter_id );

		$blocks = array(
			array(
				'_type' => 'stat',
				'value' => '42',
				'label' => 'widgets',
			),
			array(
				'_type'    => 'block',
				'style'    => 'normal',
				'children' => array(
					array(
						'_type' => 'span',
						'text'  => 'hi',
						'marks' => array(),
					),
				),
				'markDefs' => array(),
			),
		);

		$created = $this->dispatch_json(
			'POST',
			'/content/pages',
			array(
				'title'       => 'Blocks Page',
				'blocks'      => $blocks,
				'submittedBy' => 'test-agent',
			)
		);
		$this->assertEquals( 200, $created->get_status() );
		$page_id = (int) $created->get_data()['id'];
		$this->assertSame( 'test-agent', $created->get_data()['submittedBy'] );

		// Blocks are emitted verbatim (the storefront schema validates them).
		wp_set_current_user( $this->admin_id );
		$doc = $this->dispatch_json( 'GET', '/content/pages/' . $page_id )->get_data();
		$this->assertArrayHasKey( 'blocks', $doc );
		$this->assertSame( $blocks, $doc['blocks'] );

		// A legacy doc without the meta carries no blocks key.
		$legacy_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Legacy',
			)
		);
		$legacy    = $this->dispatch_json( 'GET', '/content/pages/' . $legacy_id )->get_data();
		$this->assertArrayNotHasKey( 'blocks', $legacy );

		wp_set_current_user( 0 );
	}

	/**
	 * The raw-HTML field is sanitized at entry (two-gate rule): scripts and
	 * inline handlers are stripped by wp_kses_post before the post is stored.
	 */
	public function test_content_is_sanitized_on_write(): void {
		wp_set_current_user( $this->submitter_id );

		$created = $this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'   => 'Sanitized',
				'content' => '<p onclick="steal()">hi</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>',
			)
		);
		$this->assertEquals( 200, $created->get_status() );
		$post_id = (int) $created->get_data()['id'];

		$content = get_post_field( 'post_content', $post_id );
		$this->assertIsString( $content );
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringNotContainsString( 'onclick', $content );
		$this->assertStringNotContainsString( 'javascript:', $content );
		$this->assertStringContainsString( '<p>hi</p>', $content );

		wp_set_current_user( 0 );
	}

	/**
	 * Revisions list + restore (G6): a fabricated revision appears in the
	 * listing, and restoring it returns the current doc (WP semantics — the
	 * restore itself is a new revision).
	 */
	public function test_revisions_list_and_restore(): void {
		wp_set_current_user( $this->admin_id );

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Revisable',
				'post_content' => 'v1',
			)
		);
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => 'v2',
			)
		);

		$listing = $this->dispatch_json( 'GET', '/content/pages/' . $page_id . '/revisions' );
		$this->assertEquals( 200, $listing->get_status() );
		$docs = $listing->get_data()['docs'];
		$this->assertNotEmpty( $docs );
		$revision_id = (int) $docs[0]['id'];

		$restore = $this->dispatch_json(
			'POST',
			'/content/pages/' . $page_id . '/restore',
			array( 'revision' => $revision_id )
		);
		$this->assertEquals( 200, $restore->get_status() );
		$this->assertSame( $page_id, $restore->get_data()['id'] );

		// Revisions are manager-gated — a subscriber (no manage cap) is denied.
		wp_set_current_user( $this->subscriber_id );
		$this->assertEquals( 403, $this->dispatch_json( 'GET', '/content/pages/' . $page_id . '/revisions' )->get_status() );

		wp_set_current_user( 0 );
	}

	/**
	 * PublishAt schedules the post (WP `future`, G8) and rides the doc.
	 * Core semantics: a future post_date always wins — wp_insert_post flips
	 * an explicit publish to future rather than publishing early.
	 */
	public function test_future_scheduling(): void {
		wp_set_current_user( $this->admin_id );

		$scheduled = $this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'     => 'Scheduled',
				'publishAt' => '2026-12-01T10:00:00',
			)
		);
		$this->assertEquals( 200, $scheduled->get_status(), wp_json_encode( $scheduled->get_data() ) );
		$this->assertSame( 'future', $scheduled->get_data()['status'] );
		$this->assertSame( '2026-12-01 10:00:00', $scheduled->get_data()['publishAt'] );

		// Core semantics: a future post_date always schedules — wp_insert_post
		// flips an explicit publish to future rather than publishing early.
		// The contradiction resolves to the SAFE side (never publish early).
		$published = $this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'     => 'Published Now',
				'status'    => 'publish',
				'publishAt' => '2026-12-01T10:00:00',
			)
		);
		$this->assertEquals( 200, $published->get_status(), wp_json_encode( $published->get_data() ) );
		$this->assertSame( 'future', $published->get_data()['status'] );

		wp_set_current_user( 0 );
	}

	/**
	 * The listing status filter is fail-closed (G5): anonymous requests are
	 * publish-only no matter what they ask for; managers can narrow to a
	 * single status or widen to any.
	 */
	public function test_listing_status_filter_is_fail_closed(): void {
		wp_set_current_user( $this->submitter_id );
		$draft    = $this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'  => 'Filter Draft',
				'status' => 'draft',
			)
		);
		$draft_id = (int) $draft->get_data()['id'];

		wp_set_current_user( $this->admin_id );
		$this->dispatch_json(
			'POST',
			'/content/posts',
			array(
				'title'  => 'Filter Live',
				'status' => 'publish',
			)
		);

		// Non-manager (subscriber): publish-only, even with status=any.
		wp_set_current_user( $this->subscriber_id );
		$anon_any = $this->dispatch_json( 'GET', '/content/posts', null, array( 'status' => 'any' ) )->get_data();
		$anon_ids = wp_list_pluck( $anon_any['docs'], 'id' );
		$this->assertNotContains( $draft_id, $anon_ids );

		// Manager: any includes drafts; draft narrows to drafts only.
		wp_set_current_user( $this->admin_id );
		$manager_any = $this->dispatch_json( 'GET', '/content/posts', null, array( 'status' => 'any' ) )->get_data();
		$this->assertContains( $draft_id, wp_list_pluck( $manager_any['docs'], 'id' ) );

		$manager_draft = $this->dispatch_json( 'GET', '/content/posts', null, array( 'status' => 'draft' ) )->get_data();
		foreach ( $manager_draft['docs'] as $doc ) {
			$this->assertSame( 'draft', $doc['status'] );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Media alt (G11): an alt string on the upload form lands in WP's
	 * canonical attachment alt meta.
	 */
	public function test_media_upload_records_alt(): void {
		wp_set_current_user( $this->admin_id );

		$tmp = tempnam( sys_get_temp_dir(), 'nvoos-media-' );
		// 1x1 transparent PNG — valid bytes so MIME detection passes.
		file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' ) );
		$this->tmp_files[] = $tmp;

		$request = new WP_REST_Request( 'POST', '/nvoos-commerce/v1/media' );
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'alt-image.png',
					'type'     => 'image/png',
					'tmp_name' => $tmp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $tmp ),
				),
			)
		);
		$request->set_param( 'alt', 'A green sofa in a bright room' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$attachment_id = (int) $response->get_data()['id'];
		$this->assertSame(
			'A green sofa in a bright room',
			(string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
		);

		wp_set_current_user( 0 );
	}
}
