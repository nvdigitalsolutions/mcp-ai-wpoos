<?php
/**
 * Replays the shared OAuth discovery corpus through the WordPress adapter.
 *
 * The corpus is the same file the core test replays, so the adapter cannot
 * drift from `Nvoos\Core\Domain\Mcp\OAuthDiscoveryCandidates` or
 * `WwwAuthenticateChallenge` without failing here.
 *
 * @package WP_MCP_AI_Pro
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

declare(strict_types=1);

/**
 * The discovery corpus parity test.
 */
class Test_MCP_OAuth_Discovery_Parity extends WP_UnitTestCase {

	/**
	 * Load the adapter and the core classes it delegates to.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_OAuth_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/mcp-apps/class-wp-mcp-ai-mcp-app-oauth-client.php';
		}
	}

	/**
	 * The shared corpus file, at the repository root.
	 */
	private static function corpus_file(): string {
		return dirname( __DIR__, 3 ) . '/tests/fixtures/mcp/oauth-discovery-cases.json';
	}

	/**
	 * The parsed corpus, or an empty array when it is absent.
	 *
	 * @return array<string, mixed>
	 */
	private static function corpus(): array {
		$file = self::corpus_file();

		if ( ! is_file( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture, not a URL.
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * One corpus section as a row list.
	 *
	 * @param string $key Corpus section.
	 * @return array<int, array<string, mixed>>
	 */
	private static function section( string $key ): array {
		$corpus = self::corpus();

		return isset( $corpus[ $key ] ) && is_array( $corpus[ $key ] ) ? $corpus[ $key ] : array();
	}

	/**
	 * Corpus rows as URL-case tuples.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string[], 4: string[], 5: string}>
	 */
	public static function url_cases(): array {
		$cases = array();

		foreach ( self::section( 'url_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['server_url'],
				(string) $case['origin'],
				(string) $case['path'],
				array_map( 'strval', (array) $case['authorization_server_metadata_urls'] ),
				array_map( 'strval', (array) $case['protected_resource_urls'] ),
				(string) $case['rest_metadata_url'],
			);
		}

		return $cases;
	}

	/**
	 * Corpus rows as authorization-server tuples.
	 *
	 * @return array<string, array{0: string, 1: string[]}>
	 */
	public static function authorization_server_cases(): array {
		$cases = array();

		foreach ( self::section( 'authorization_server_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['authorization_server'],
				array_map( 'strval', (array) $case['urls'] ),
			);
		}

		return $cases;
	}

	/**
	 * Corpus rows as challenge-header tuples.
	 *
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public static function header_cases(): array {
		$cases = array();

		foreach ( self::section( 'header_cases' ) as $case ) {
			$cases[ (string) $case['name'] ] = array(
				(string) $case['header'],
				(array) $case['params'],
			);
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present(): void {
		$this->assertNotSame( array(), self::url_cases() );
		$this->assertNotSame( array(), self::header_cases() );
	}

	/**
	 * The adapter must build the recorded URLs for a server.
	 *
	 * @param string   $server_url Recorded server URL.
	 * @param string   $origin     Expected origin.
	 * @param string   $path       Expected normalised path.
	 * @param string[] $as_urls    Expected RFC 8414 candidates.
	 * @param string[] $prm_urls   Expected RFC 9728 candidates.
	 * @param string   $rest_url   Expected REST metadata URL.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'url_cases' )]
	public function test_the_adapter_builds_the_recorded_urls(
		string $server_url,
		string $origin,
		string $path,
		array $as_urls,
		array $prm_urls,
		string $rest_url
	): void {
		$client = self::client( $server_url );

		$this->assertSame( $origin, self::call( $client, 'get_origin' ) );
		$this->assertSame( $path, self::call( $client, 'get_path' ) );
		$this->assertSame( $as_urls, self::call( $client, 'build_as_metadata_urls' ) );
		$this->assertSame( $prm_urls, self::call( $client, 'build_prm_urls' ) );
		$this->assertSame( $rest_url, self::call( $client, 'build_rest_metadata_url' ) );
	}

	/**
	 * The adapter must build the recorded authorization-server candidates.
	 *
	 * @param string   $authorization_server Recorded authorization server.
	 * @param string[] $urls                 Expected candidates.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'authorization_server_cases' )]
	public function test_the_adapter_builds_the_recorded_authorization_server_urls(
		string $authorization_server,
		array $urls
	): void {
		$client = self::client( 'https://unused.example.com/mcp' );

		$this->assertSame(
			$urls,
			self::call( $client, 'build_as_metadata_urls_for_server', array( $authorization_server ) )
		);
	}

	/**
	 * The adapter must parse the recorded challenge.
	 *
	 * @param string                $header Recorded header value.
	 * @param array<string, string> $params Expected parameters.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'header_cases' )]
	public function test_the_adapter_parses_the_recorded_challenge( string $header, array $params ): void {
		$client = self::client( 'https://unused.example.com/mcp' );

		$this->assertSame( $params, self::call( $client, 'parse_www_authenticate', array( $header ) ) );
	}

	/**
	 * The adapter must delegate the RFC 8414 and RFC 9728 validity rules.
	 */
	public function test_the_adapter_delegates_the_document_predicates(): void {
		$client = self::client( 'https://unused.example.com/mcp' );

		$this->assertTrue(
			self::call(
				$client,
				'metadata_is_valid',
				array(
					array(
						'authorization_endpoint' => 'https://a',
						'token_endpoint'         => 'https://t',
					),
				)
			)
		);

		$this->assertFalse( self::call( $client, 'metadata_is_valid', array( array( 'authorization_endpoint' => 'https://a' ) ) ) );
		$this->assertTrue( self::call( $client, 'prm_is_valid', array( array( 'authorization_servers' => array( 'https://a' ) ) ) ) );
		$this->assertFalse( self::call( $client, 'prm_is_valid', array( array() ) ) );
	}

	/**
	 * An OAuth client with a fixed server URL and nothing else.
	 *
	 * The discovery methods read only `server_url`, so an instance built
	 * without the constructor needs no credentials or transport.
	 *
	 * @param string $server_url Server URL to pin.
	 * @return WP_MCP_AI_MCP_App_OAuth_Client
	 */
	private static function client( string $server_url ): WP_MCP_AI_MCP_App_OAuth_Client {
		$reflection = new ReflectionClass( WP_MCP_AI_MCP_App_OAuth_Client::class );
		$client     = $reflection->newInstanceWithoutConstructor();
		$property   = $reflection->getProperty( 'server_url' );

		$property->setAccessible( true );
		$property->setValue( $client, $server_url );

		return $client;
	}

	/**
	 * Call a protected method from outside the class scope.
	 *
	 * @param WP_MCP_AI_MCP_App_OAuth_Client $client Subject.
	 * @param string                         $method Protected method name.
	 * @param array<int, mixed>              $args   Arguments.
	 * @return mixed
	 */
	private static function call( WP_MCP_AI_MCP_App_OAuth_Client $client, string $method, array $args = array() ) {
		$bound = Closure::bind(
			function () use ( $method, $args ) {
				return $this->{$method}( ...$args );
			},
			$client,
			WP_MCP_AI_MCP_App_OAuth_Client::class
		);

		return null === $bound ? null : $bound();
	}
}
