<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\Sparql;
use NvoosContentGraph\Schema;
use WP_UnitTestCase;

/**
 * Unit tests for the SPARQL endpoint driver.
 *
 * HTTP is mocked via `pre_http_request`; the SSRF guard is bypassed with
 * the plugin's own filter so tests never hit DNS or the network.
 *
 * @since 1.0.9
 */
class SparqlTest extends WP_UnitTestCase {

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
				// Only mock the fixture endpoint — WordPress fires its own
				// update-check requests during admin_init.
				if ( false === strpos( (string) $url, 'query.test' ) ) {
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
	 * @return Sparql
	 */
	private function driver( array $config = array() ): Sparql {
		$driver = new Sparql();
		$driver->setConfig(
			array_merge(
				array(
					'_slug'    => 'sp_test',
					'endpoint' => 'https://query.test/sparql',
					'query'    => 'SELECT ?id ?label WHERE { ?id ?label ?x }',
				),
				$config
			)
		);
		return $driver;
	}

	/**
	 * The query parameter must be encoded exactly once. A pre-encoded
	 * query would double-encode (%20 -> %2520) and break the SPARQL.
	 *
	 * @return void
	 */
	public function test_query_is_not_double_encoded(): void {
		$this->mockHttp(
			static function () {
				return array( 'results' => array( 'bindings' => array() ) );
			}
		);

		$this->driver()->fetchNodes();

		$this->assertNotEmpty( $this->urls );
		$this->assertStringContainsString( 'format=json', $this->urls[0] );
		$this->assertStringNotContainsString( '%2520', $this->urls[0] );
	}

	/**
	 * fetchNodes maps SPARQL JSON bindings onto graph nodes.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_parses_bindings(): void {
		$this->mockHttp(
			static function () {
				return array(
					'head'    => array( 'vars' => array( 'id', 'label', 'type', 'url' ) ),
					'results' => array(
						'bindings' => array(
							array(
								'id'    => array(
									'type'  => 'uri',
									'value' => 'http://test/one',
								),
								'label' => array(
									'type'  => 'literal',
									'value' => 'One',
								),
								'type'  => array(
									'type'  => 'literal',
									'value' => 'concept',
								),
								'url'   => array(
									'type'  => 'uri',
									'value' => 'https://one.example/',
								),
							),
							array(
								'id'    => array(
									'type'  => 'uri',
									'value' => 'http://test/two',
								),
								'label' => array(
									'type'  => 'literal',
									'value' => 'Two',
								),
							),
						),
					),
				);
			}
		);

		$nodes = $this->driver()->fetchNodes();

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'One', $nodes[0]['label'] );
		$this->assertSame( 'concept', $nodes[0]['type'] );
		$this->assertSame( 'https://one.example/', $nodes[0]['url'] );
		$this->assertStringStartsWith( 'remote_sp_test_', $nodes[0]['node_id'] );
		$this->assertSame( 'entity', $nodes[1]['type'] ); // fallback type.
	}

	/**
	 * fetchEdges maps ?source ?target ?relation bindings.
	 *
	 * @return void
	 */
	public function test_fetch_edges_parses_bindings(): void {
		$this->mockHttp(
			static function () {
				return array(
					'results' => array(
						'bindings' => array(
							array(
								'source'   => array(
									'type'  => 'uri',
									'value' => 'http://test/one',
								),
								'target'   => array(
									'type'  => 'uri',
									'value' => 'http://test/two',
								),
								'relation' => array(
									'type'  => 'literal',
									'value' => 'related_to',
								),
							),
						),
					),
				);
			}
		);

		$edges = $this->driver()->fetchEdges();

		$this->assertCount( 1, $edges );
		$this->assertSame( 'RELATED_TO', $edges[0]['relation'] );
		$this->assertStringStartsWith( 'remote_sp_test_', $edges[0]['source_node_id'] );
	}

	/**
	 * testConnection appends LIMIT 1 only when the query has none.
	 *
	 * @return void
	 */
	public function test_test_connection_appends_limit_once(): void {
		$this->mockHttp(
			static function () {
				return array( 'results' => array( 'bindings' => array() ) );
			}
		);

		$result = $this->driver()->testConnection();

		$this->assertTrue( $result['success'] );
		// " LIMIT 1" appended to the user query, %20-encoded by esc_url_raw().
		$this->assertMatchesRegularExpression( '/LIMIT%201(?:&|$)/', $this->urls[0] );
	}

	/**
	 * A query that already carries a LIMIT is left untouched.
	 *
	 * @return void
	 */
	public function test_test_connection_does_not_double_limit(): void {
		$this->mockHttp(
			static function () {
				return array( 'results' => array( 'bindings' => array() ) );
			}
		);

		$this->driver( array( 'query' => 'SELECT ?s WHERE { ?s ?p ?o } LIMIT 10' ) )->testConnection();

		$this->assertNotEmpty( $this->urls );
		$this->assertStringNotContainsString( 'LIMIT%2010%20LIMIT', $this->urls[0] );
		$this->assertStringContainsString( 'LIMIT%2010', $this->urls[0] );
	}

	/**
	 * testConnection fails when the endpoint returns non-JSON.
	 *
	 * @return void
	 */
	public function test_test_connection_fails_on_non_json(): void {
		$this->mockHttp(
			static function () {
				return '<html>error</html>';
			}
		);

		$result = $this->driver()->testConnection();

		$this->assertFalse( $result['success'] );
	}

	/**
	 * fetchNodes returns an empty set on HTTP errors (no partial import).
	 *
	 * @return void
	 */
	public function test_fetch_nodes_empty_on_http_error(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false === strpos( (string) $url, 'query.test' ) ) {
					return new \WP_Error( 'http_request_failed', 'Not mocked.' );
				}
				return array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => '',
				);
			},
			10,
			3
		);

		$this->assertSame( array(), $this->driver()->fetchNodes() );
	}
}
