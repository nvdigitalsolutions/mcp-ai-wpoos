<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\Wikidata;
use NvoosContentGraph\Schema;
use WP_UnitTestCase;

/**
 * Unit tests for the Wikidata reconciliation driver.
 *
 * HTTP is mocked via `pre_http_request`; the SSRF guard is bypassed with
 * the plugin's own filter so tests never hit DNS or the network.
 *
 * @since 1.0.9
 */
class WikidataTest extends WP_UnitTestCase {

	/** @var array<int,string> Captured request URLs. */
	private array $urls = array();

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
	 * Install the SSRF bypass and a canned-response HTTP mock.
	 *
	 * @param callable $handler ( string $url ) => array|string body.
	 * @return void
	 */
	private function mockHttp( callable $handler ): void {
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		$self = $this;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $self, $handler ) {
				// Only mock the Wikidata endpoint — WordPress fires its own
				// update-check requests during admin_init.
				if ( false === strpos( (string) $url, 'wikidata.org' ) ) {
					return new \WP_Error( 'http_request_failed', 'Not mocked.' );
				}
				$self->urls[] = (string) $url;
				$body         = $handler( (string) $url );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a configured driver instance.
	 *
	 * @param array<string,mixed> $config Config overrides.
	 * @return Wikidata
	 */
	private function driver( array $config = array() ): Wikidata {
		$driver = new Wikidata();
		$driver->setConfig(
			array_merge(
				array(
					'_slug'          => 'wd_test',
					'language'       => 'en',
					'min_confidence' => 0.6,
				),
				$config
			)
		);
		return $driver;
	}

	/**
	 * The search term is encoded exactly once by add_query_arg() — a
	 * pre-encoded term would double-encode spaces (%2520) and never match.
	 *
	 * @return void
	 */
	public function test_reconcile_encodes_search_term_once(): void {
		$this->mockHttp(
			static function () {
				return array( 'search' => array() );
			}
		);

		$this->driver()->reconcile(
			(object) array(
				'label' => 'Acme Corp',
				'type'  => 'organization',
			)
		);

		$this->assertNotEmpty( $this->urls );
		// esc_url_raw() canonicalises add_query_arg's '+' into '%20' — either
		// way the term is encoded exactly once.
		$this->assertStringContainsString( 'search=Acme%20Corp', $this->urls[0] );
		$this->assertStringNotContainsString( '%2520', $this->urls[0] );
	}

	/**
	 * An exact label match reconciles with a high-confidence QID.
	 *
	 * @return void
	 */
	public function test_reconcile_matches_exact_label(): void {
		$this->mockHttp(
			static function () {
				return array(
					'search' => array(
						array(
							'id'          => 'Q42',
							'label'       => 'WordPress',
							'description' => 'free and open-source blogging tool',
						),
					),
				);
			}
		);

		$result = $this->driver()->reconcile(
			(object) array(
				'label' => 'WordPress',
				'type'  => 'entity',
			)
		);

		$this->assertTrue( $result['matched'] );
		$this->assertSame( 'Q42', $result['external_id'] );
		$this->assertGreaterThanOrEqual( 0.6, $result['confidence'] );
	}

	/**
	 * Matches below the configured minimum are rejected.
	 *
	 * @return void
	 */
	public function test_reconcile_rejects_below_min_confidence(): void {
		$this->mockHttp(
			static function () {
				return array(
					'search' => array(
						array(
							'id'          => 'Q1',
							'label'       => 'Something entirely different',
							'description' => '',
						),
					),
				);
			}
		);

		$result = $this->driver( array( 'min_confidence' => 0.95 ) )->reconcile(
			(object) array(
				'label' => 'WordPress',
				'type'  => 'entity',
			)
		);

		$this->assertFalse( $result['matched'] );
	}

	/**
	 * An empty search result set reconciles as unmatched.
	 *
	 * @return void
	 */
	public function test_reconcile_unmatched_on_empty_results(): void {
		$this->mockHttp(
			static function () {
				return array( 'search' => array() );
			}
		);

		$result = $this->driver()->reconcile(
			(object) array(
				'label' => 'Zzzzznope',
				'type'  => 'entity',
			)
		);

		$this->assertFalse( $result['matched'] );
		$this->assertSame( '', $result['external_id'] );
	}

	/**
	 * testConnection uses the configured language, not a hardcoded one.
	 *
	 * @return void
	 */
	public function test_test_connection_uses_configured_language(): void {
		$this->mockHttp(
			static function () {
				return array(
					'search' => array(
						array(
							'id'    => 'Q1',
							'label' => 'X',
						),
					),
				);
			}
		);

		$result = $this->driver( array( 'language' => 'de' ) )->testConnection();

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( 'language=de', $this->urls[0] );
	}

	/**
	 * testConnection reports failure when Wikidata returns no results.
	 *
	 * @return void
	 */
	public function test_test_connection_fails_on_empty_search(): void {
		$this->mockHttp(
			static function () {
				return array( 'search' => array() );
			}
		);

		$result = $this->driver()->testConnection();

		$this->assertFalse( $result['success'] );
	}
}
