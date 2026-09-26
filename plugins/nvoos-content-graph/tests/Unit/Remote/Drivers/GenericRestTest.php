<?php
declare(strict_types=1);

namespace NvoosContentGraph\Tests\Unit\Remote\Drivers;

use NvoosContentGraph\Remote\Drivers\GenericRest;
use NvoosContentGraph\Schema;
use WP_UnitTestCase;

/**
 * Unit tests for the Generic REST API driver.
 *
 * HTTP is mocked via the `pre_http_request` filter and the SSRF guard is
 * bypassed with the plugin's own allow-private-URLs filter, so the tests
 * never touch the network or DNS.
 *
 * @since 1.0.9
 */
class GenericRestTest extends WP_UnitTestCase {

	/** @var array<int,array{url:string,args:array<string,mixed>}> Captured requests. */
	private array $requests = array();

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
	 * @param callable $handler Signature: ( string $url, array $args ) => array|WP_Error.
	 * @return void
	 */
	private function mockHttp( callable $handler ): void {
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		$self = $this;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $self, $handler ) {
				$self->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				$body             = $handler( (string) $url, (array) $args );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => is_array( $body ) ? wp_json_encode( $body ) : (string) $body,
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Build a driver instance with the given config.
	 *
	 * @param array<string,mixed> $config Config (base_url defaults to https://api.test/v1/items).
	 * @return GenericRest
	 */
	private function driver( array $config ): GenericRest {
		$config = array_merge(
			array(
				'base_url' => 'https://api.test/v1/items',
				'_slug'    => 'api_test',
			),
			$config
		);
		$driver = new GenericRest();
		$driver->setConfig( $config );
		return $driver;
	}

	/**
	 * The config schema exposes the fields fetchNodes/fetchEdges actually read.
	 *
	 * @return void
	 */
	public function test_config_schema_covers_runtime_config_keys(): void {
		$schema = ( new GenericRest() )->getConfigSchema();

		foreach (
			array(
				'base_url',
				'api_token',
				'auth_query_param',
				'path_results',
				'path_id',
				'path_label',
				'path_url',
				'path_type',
				'max_items',
				'page_param',
				'page_size_param',
				'page_size',
				'edge_path',
				'edge_source_field',
				'edge_target_field',
				'edge_relation_field',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $schema, "Schema is missing {$key}." );
		}
	}

	/**
	 * fetchNodes maps the configured paths onto graph nodes.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_maps_configured_paths(): void {
		$this->mockHttp(
			static function () {
				return array(
					'data' => array(
						'items' => array(
							array(
								'id'       => 'a1',
								'name'     => 'Alpha',
								'homepage' => 'https://alpha.example/',
								'kind'     => 'widget',
							),
							array(
								'id'   => 'a2',
								'name' => 'Beta',
							),
						),
					),
				);
			}
		);

		$driver = $this->driver(
			array(
				'path_results' => 'data.items',
				'path_label'   => 'name',
				'path_id'      => 'id',
				'path_url'     => 'homepage',
				'path_type'    => 'kind',
			)
		);

		$nodes = $driver->fetchNodes();

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'remote_api_test_a1', $nodes[0]['node_id'] );
		$this->assertSame( 'Alpha', $nodes[0]['label'] );
		$this->assertSame( 'widget', $nodes[0]['type'] );
		$this->assertSame( 'https://alpha.example/', $nodes[0]['url'] );
		$this->assertSame( 'a1', $nodes[0]['external_id'] );
		$this->assertSame( 'REMOTE', $nodes[0]['provenance'] );
		$this->assertSame( 'entity', $nodes[1]['type'] ); // no kind field -> default.
	}

	/**
	 * Without a results path the whole top-level array is the item list.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_uses_whole_array_when_no_results_path(): void {
		$this->mockHttp(
			static function () {
				return array(
					array(
						'id'   => 1,
						'name' => 'One',
					),
					array(
						'id'   => 2,
						'name' => 'Two',
					),
				);
			}
		);

		$nodes = $this->driver( array() )->fetchNodes();

		$this->assertCount( 2, $nodes );
		$this->assertSame( 'One', $nodes[0]['label'] );
	}

	/**
	 * max_items caps the ingested node count.
	 *
	 * @return void
	 */
	public function test_fetch_nodes_respects_max_items(): void {
		$items = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items[] = array(
				'id'   => $i,
				'name' => 'Item ' . $i,
			);
		}
		$this->mockHttp(
			static function () use ( $items ) {
				return array( 'data' => $items );
			}
		);

		$nodes = $this->driver(
			array(
				'path_results' => 'data',
				'max_items'    => 3,
			)
		)->fetchNodes();

		$this->assertCount( 3, $nodes );
		$this->assertSame( 'Item 3', $nodes[2]['label'] );
	}

	/**
	 * Paginated endpoints are walked until an empty page (or the cap).
	 *
	 * @return void
	 */
	public function test_fetch_nodes_paginates_with_configured_params(): void {
		$this->mockHttp(
			static function ( $url ) {
				$page = 1;
				if ( preg_match( '/[?&]page=(\d+)/', $url, $m ) ) {
					$page = (int) $m[1];
				}
				if ( $page >= 3 ) {
					return array( 'items' => array() );
				}
				return array(
					'items' => array(
						array(
							'id'   => 'p' . $page . 'a',
							'name' => 'Page ' . $page . ' A',
						),
						array(
							'id'   => 'p' . $page . 'b',
							'name' => 'Page ' . $page . ' B',
						),
					),
				);
			}
		);

		$nodes = $this->driver(
			array(
				'path_results'    => 'items',
				'page_param'      => 'page',
				'page_size_param' => 'limit',
				'page_size'       => 2,
			)
		)->fetchNodes();

		$this->assertCount( 4, $nodes );
		$this->assertSame( 'remote_api_test_p2b', $nodes[3]['node_id'] );

		// Page 1 and 2 were requested with the page-size param; page 3 ended the walk.
		$pagedUrls = array_filter(
			$this->requests,
			static function ( $r ) {
				return false !== strpos( $r['url'], 'page=' );
			}
		);
		$this->assertCount( 3, $pagedUrls );
		$this->assertStringContainsString( 'page=2', array_values( $pagedUrls )[1]['url'] );
		$this->assertStringContainsString( 'limit=2', array_values( $pagedUrls )[0]['url'] );
	}

	/**
	 * fetchEdges maps the edge paths and prefixes node IDs with the slug.
	 *
	 * @return void
	 */
	public function test_fetch_edges_maps_configured_fields(): void {
		$this->mockHttp(
			static function () {
				return array(
					'data' => array(
						'edges' => array(
							array(
								'from' => 'a1',
								'to'   => 'a2',
								'rel'  => 'links_to',
							),
						),
					),
				);
			}
		);

		$edges = $this->driver(
			array(
				'edge_path'           => 'data.edges',
				'edge_source_field'   => 'from',
				'edge_target_field'   => 'to',
				'edge_relation_field' => 'rel',
			)
		)->fetchEdges();

		$this->assertCount( 1, $edges );
		$this->assertSame( 'remote_api_test_a1', $edges[0]['source_node_id'] );
		$this->assertSame( 'remote_api_test_a2', $edges[0]['target_node_id'] );
		$this->assertSame( 'LINKS_TO', $edges[0]['relation'] );
	}

	/**
	 * Without an edge path, fetchEdges is a no-op.
	 *
	 * @return void
	 */
	public function test_fetch_edges_empty_without_edge_path(): void {
		$this->assertSame( array(), $this->driver( array() )->fetchEdges() );
	}

	/**
	 * The API token is sent as a Bearer header.
	 *
	 * @return void
	 */
	public function test_auth_sends_bearer_header(): void {
		$this->mockHttp(
			static function () {
				return array( 'items' => array() );
			}
		);

		$this->driver(
			array(
				'api_token'    => 'sekret',
				'path_results' => 'items',
			)
		)->fetchNodes();

		$this->assertNotEmpty( $this->requests );
		$this->assertSame( 'Bearer sekret', $this->requests[0]['args']['headers']['Authorization'] ?? '' );
		$this->assertStringNotContainsString( 'sekret', $this->requests[0]['url'] );
	}

	/**
	 * When auth_query_param is set the token rides in the query string.
	 *
	 * @return void
	 */
	public function test_auth_uses_query_param_when_configured(): void {
		$this->mockHttp(
			static function () {
				return array( 'items' => array() );
			}
		);

		$this->driver(
			array(
				'api_token'        => 'sekret',
				'auth_query_param' => 'api_key',
				'path_results'     => 'items',
			)
		)->fetchNodes();

		$this->assertNotEmpty( $this->requests );
		$this->assertStringContainsString( 'api_key=sekret', $this->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] ?? array() );
	}

	/**
	 * testConnection reports the found item count and validates the path.
	 *
	 * @return void
	 */
	public function test_test_connection_reports_counts_and_edges(): void {
		$this->mockHttp(
			static function () {
				return array(
					'data' => array(
						'items' => array(
							array(
								'id'   => 1,
								'name' => 'One',
							),
							array(
								'id'   => 2,
								'name' => 'Two',
							),
						),
						'edges' => array(
							array(
								'source' => 1,
								'target' => 2,
							),
						),
					),
				);
			}
		);

		$result = $this->driver(
			array(
				'path_results' => 'data.items',
				'edge_path'    => 'data.edges',
			)
		)->testConnection();

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString( '2', $result['message'] );
		$this->assertStringContainsString( '1', $result['message'] );
	}

	/**
	 * testConnection rejects a results path that does not resolve.
	 *
	 * @return void
	 */
	public function test_test_connection_fails_when_path_missing(): void {
		$this->mockHttp(
			static function () {
				return array( 'data' => array( 'items' => array() ) );
			}
		);

		$result = $this->driver( array( 'path_results' => 'data.missing' ) )->testConnection();

		$this->assertFalse( $result['success'] );
	}

	/**
	 * testConnection fails when the endpoint does not return JSON.
	 *
	 * @return void
	 */
	public function test_test_connection_fails_on_non_json_body(): void {
		$this->mockHttp(
			static function () {
				return '<html>not json</html>';
			}
		);

		$result = $this->driver( array() )->testConnection();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'JSON', $result['message'] );
	}

	/**
	 * A non-2xx response yields an empty node set, never a partial parse.
	 * (4xx — not retried by the client, keeping the test fast.)
	 *
	 * @return void
	 */
	public function test_fetch_nodes_empty_on_http_error(): void {
		remove_all_filters( 'pre_http_request' );
		add_filter( Schema::FILTER_ALLOW_PRIVATE_URLS, '__return_true' );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'body'     => '{"items":[]}',
				);
			}
		);

		$this->assertSame( array(), $this->driver( array( 'path_results' => 'items' ) )->fetchNodes() );
	}
}
