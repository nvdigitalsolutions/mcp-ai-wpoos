<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\RssSitemap;
use NvoosContentGraph\Schema;
use WP_UnitTestCase;

/**
 * Unit tests for the RSS / Atom / Sitemap driver.
 *
 * HTTP is mocked via `pre_http_request`; the SSRF guard is bypassed with
 * the plugin's own filter so tests never hit DNS or the network.
 *
 * @since 1.0.9
 */
class RssSitemapTest extends WP_UnitTestCase {

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( Schema::FILTER_ALLOW_PRIVATE_URLS );
		parent::tearDown();
	}

	/**
	 * Install the SSRF bypass and a fixed-body HTTP mock.
	 *
	 * @param string $body Response body.
	 * @param int    $status HTTP status.
	 * @return void
	 */
	private function mockHttp( string $body, int $status = 200 ): void {
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		add_filter(
			'pre_http_request',
			static function () use ( $body, $status ) {
				return array(
					'response' => array(
						'code'    => $status,
						'message' => 'OK',
					),
					'body'     => $body,
				);
			}
		);
	}

	/**
	 * Build a configured driver instance.
	 *
	 * @param array<string,mixed> $config Config overrides.
	 * @return RssSitemap
	 */
	private function driver( array $config = array() ): RssSitemap {
		$driver = new RssSitemap();
		$driver->setConfig(
			array_merge(
				array(
					'_slug'     => 'feed_test',
					'feed_url'  => 'https://feeds.test/rss.xml',
					'max_items' => 100,
				),
				$config
			)
		);
		return $driver;
	}

	/**
	 * RSS 2.0 items are parsed into nodes with title/guid/link.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_parses_rss(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<rss version="2.0"><channel><title>Test</title>' .
			'<item><title>First post</title><link>https://site.test/first</link><guid>g1</guid><description>Hello</description></item>' .
			'<item><title>Second post</title><link>https://site.test/second</link><guid>g2</guid></item>' .
			'</channel></rss>';
		$this->mockHttp( $xml );

		$nodes = $this->driver()->fetchNodes();

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'First post', $nodes[0]['label'] );
		$this->assertSame( 'article', $nodes[0]['type'] );
		$this->assertSame( 'https://site.test/first', $nodes[0]['url'] );
		$this->assertSame( 'g1', $nodes[0]['external_id'] );
	}

	/**
	 * max_items caps RSS ingestion.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_respects_max_items(): void {
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title>';
		for ( $i = 1; $i <= 5; $i++ ) {
			$xml .= '<item><title>Post ' . $i . '</title><link>https://site.test/' . $i . '</link></item>';
		}
		$xml .= '</channel></rss>';
		$this->mockHttp( $xml );

		$nodes = $this->driver( array( 'max_items' => 2 ) )->fetchNodes();

		$this->assertCount( 2, $nodes );
	}

	/**
	 * Atom 1.0 entries are parsed, preferring non-self alternate links.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_parses_atom(): void {
		$xml = '<?xml version="1.0" encoding="utf-8"?>' .
			'<feed xmlns="http://www.w3.org/2005/Atom"><title>T</title>' .
			'<entry><title>Atom entry</title><id>tag:test,2026:1</id>' .
			'<link rel="self" href="https://site.test/feed"/><link rel="alternate" href="https://site.test/entry"/></entry>' .
			'</feed>';
		$this->mockHttp( $xml );

		$nodes = $this->driver()->fetchNodes();

		$this->assertCount( 1, $nodes );
		$this->assertSame( 'Atom entry', $nodes[0]['label'] );
		$this->assertSame( 'https://site.test/entry', $nodes[0]['url'] );
		$this->assertSame( 'tag:test,2026:1', $nodes[0]['external_id'] );
	}

	/**
	 * XML sitemap URLs become nodes labeled by location.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_parses_sitemap(): void {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' .
			'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' .
			'<url><loc>https://site.test/page-a</loc></url>' .
			'<url><loc>https://site.test/page-b</loc></url>' .
			'</urlset>';
		$this->mockHttp( $xml );

		$nodes = $this->driver()->fetchNodes();

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'https://site.test/page-a', $nodes[0]['label'] );
		$this->assertSame( 'page', $nodes[0]['type'] );
	}

	/**
	 * The configured feed_type override wins over root-element sniffing.
	 *
	 * @return void
	 */
	public function test_feed_type_override_wins_over_auto_detect(): void {
		$atom = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">' .
			'<entry><title>Atom entry</title><id>tag:test,2026:1</id></entry></feed>';
		$this->mockHttp( $atom );

		// Forced RSS parsing of an Atom document finds no channel -> no nodes.
		$nodes = $this->driver( array( 'feed_type' => 'rss' ) )->fetchNodes();

		$this->assertSame( array(), $nodes );
	}

	/**
	 * testConnection rejects a non-XML body.
	 *
	 * @return void
	 */
	public function test_test_connection_fails_on_non_xml(): void {
		$this->mockHttp( 'this is not xml' );

		$result = $this->driver()->testConnection();

		$this->assertFalse( $result['success'] );
	}

	/**
	 * testConnection reports success for a valid feed.
	 *
	 * @return void
	 */
	public function test_test_connection_succeeds_on_valid_feed(): void {
		$this->mockHttp( '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title></channel></rss>' );

		$result = $this->driver()->testConnection();

		$this->assertTrue( $result['success'] );
	}

	/**
	 * The schema exposes the feed_type override the parser already reads.
	 *
	 * @return void
	 */
	public function test_schema_exposes_feed_type(): void {
		$schema = ( new RssSitemap() )->getConfigSchema();

		$this->assertArrayHasKey( 'feed_type', $schema );
		$this->assertSame( 'select', $schema['feed_type']['type'] );
		$this->assertArrayHasKey( 'sitemap', $schema['feed_type']['options'] );
	}
}
