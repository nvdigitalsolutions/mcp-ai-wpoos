<?php
/**
 * Mesh ported-class tests.
 *
 * Verifies the extraction port (src/Mesh/) preserves the public behaviour of
 * the base plugin's mesh networking classes (mcp-ai-wpoos/includes/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Federation\PeerCpt;
use NvoosContentGraphAiPlatform\Mesh\MeshPeerSync;
use NvoosContentGraphAiPlatform\Mesh\MeshPeerTester;

/**
 * @group mesh
 */
class Test_Platform_Mesh extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Isolate from other tests that create ai_peer posts (e.g. the
		// federation directory registration test).
		$existing = get_posts(
			array(
				'post_type'   => PeerCpt::POST_TYPE,
				'numberposts' => -1,
			)
		);
		foreach ( $existing as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}

	public function test_mesh_peer_sync_creates_and_removes_posts(): void {
		PeerCpt::register_post_type();

		$sync = new MeshPeerSync();
		$sync->sync_mesh_peers(
			array(
				array(
					'name'    => 'Peer One',
					'url'     => 'https://peer-one.example.com',
					'api_key' => 'secret',
				),
			)
		);

		$posts = get_posts(
			array(
				'post_type'   => PeerCpt::POST_TYPE,
				'numberposts' => -1,
			)
		);
		$this->assertCount( 1, $posts );
		$this->assertSame( 'Peer One', $posts[0]->post_title );
		$this->assertSame( 'mesh', get_post_meta( $posts[0]->ID, MeshPeerSync::META_CONNECTION_TYPE, true ) );
		$this->assertSame( 'https://peer-one.example.com', get_post_meta( $posts[0]->ID, PeerCpt::META_SITE_URL, true ) );
		$this->assertSame( 'unknown', get_post_meta( $posts[0]->ID, PeerCpt::META_HEALTH_STATUS, true ) );

		// Removing the peer from settings deletes the post.
		$sync->sync_mesh_peers( array() );
		$posts = get_posts(
			array(
				'post_type'   => PeerCpt::POST_TYPE,
				'numberposts' => -1,
			)
		);
		$this->assertCount( 0, $posts );
	}

	public function test_mesh_peer_sync_skips_invalid_peers(): void {
		PeerCpt::register_post_type();

		$sync = new MeshPeerSync();
		$sync->sync_mesh_peers(
			array(
				array( 'name' => 'No URL' ),
				array( 'url' => 'not-a-valid-url' ),
			)
		);

		$posts = get_posts(
			array(
				'post_type'   => PeerCpt::POST_TYPE,
				'numberposts' => -1,
			)
		);
		$this->assertCount( 0, $posts );
	}

	public function test_mesh_peer_tester_rejects_invalid_peer(): void {
		$result = MeshPeerTester::test_connection( array( 'name' => 'No URL' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_peer', $result->get_error_code() );
	}

	public function test_mesh_peer_tester_rejects_invalid_url(): void {
		// Note: esc_url_raw() prepends http:// to scheme-less strings in modern
		// WP, so a URL with a scheme but no valid host is needed to exercise
		// the invalid_url path.
		$result = MeshPeerTester::test_connection( array( 'url' => 'http://' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_mesh_peer_tester_full_flow_with_mocked_http(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, '.well-known/ai-peer' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'site_name'    => 'Peer Site',
								'capabilities' => array( 'distributed_processing' ),
							)
						),
					);
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'ok',
				);
			},
			10,
			3
		);

		$result = MeshPeerTester::test_connection(
			array(
				'url'     => 'https://peer.example.com',
				'api_key' => 'secret',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Peer Site', $result['site_name'] );

		remove_all_filters( 'pre_http_request' );
	}
}
