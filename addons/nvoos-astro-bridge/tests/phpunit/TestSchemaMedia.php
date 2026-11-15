<?php
/**
 * Media-origin rewriting (Proposal 135 / A6).
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;

/**
 * `Schema::media_url_from()` tests — pure string work, no attachment fixture.
 *
 * @covers \NvoosAstroBridge\Schema
 * @group nvoos-astro-bridge
 */
class TestSchemaMedia extends WP_UnitTestCase {

	/**
	 * Clean the option between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Schema::OPTION_MEDIA_ORIGIN );
		parent::tearDown();
	}

	/**
	 * The default keeps the absolute WordPress URL (static sites).
	 */
	public function test_default_is_absolute(): void {
		$uploads = wp_get_upload_dir();
		$url     = $uploads['baseurl'] . '/2026/11/photo.jpg';

		$this->assertSame( $url, Schema::media_url_from( $url ) );
	}

	/**
	 * Frontend mode rewrites an uploads URL to a same-origin /media/ path.
	 */
	public function test_frontend_mode_rewrites_uploads(): void {
		update_option( Schema::OPTION_MEDIA_ORIGIN, Schema::MEDIA_ORIGIN_FRONTEND );

		$uploads = wp_get_upload_dir();
		$url     = $uploads['baseurl'] . '/2026/11/photo.jpg';

		$this->assertSame( '/media/2026/11/photo.jpg', Schema::media_url_from( $url ) );
	}

	/**
	 * A URL outside the uploads base is never rewritten.
	 */
	public function test_frontend_mode_leaves_foreign_urls_alone(): void {
		update_option( Schema::OPTION_MEDIA_ORIGIN, Schema::MEDIA_ORIGIN_FRONTEND );

		$this->assertSame(
			'https://cdn.example.com/x.jpg',
			Schema::media_url_from( 'https://cdn.example.com/x.jpg' )
		);
	}

	/**
	 * Empty inputs stay empty.
	 */
	public function test_empty_passes_through(): void {
		$this->assertSame( '', Schema::media_url_from( '' ) );
		$this->assertSame( '', Schema::media_url_from( null ) );
		$this->assertSame( '', Schema::media_url( 0 ) );
	}
}
