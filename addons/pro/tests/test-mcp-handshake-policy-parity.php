<?php
/**
 * Replays the shared MCP handshake corpus through the WordPress adapter.
 *
 * The corpus is the same file the core test replays, so the adapter cannot
 * drift from `Nvoos\Core\Domain\Mcp\McpHandshakePolicy` without failing here.
 * Regression guard for Proposal 090-I: the classification rules moved into
 * lib/core, and this file proves the adapter still delegates to them.
 *
 * @package WP_MCP_AI_Pro
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

declare(strict_types=1);

/**
 * The corpus parity test.
 */
class Test_MCP_Handshake_Policy_Parity extends WP_UnitTestCase {

	/**
	 * Load the adapter once.
	 *
	 * The plugin normally loads it, but a test file run in isolation has to be
	 * able to stand on its own.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Client' ) ) {
			require_once dirname( __DIR__ ) . '/includes/mcp-apps/class-wp-mcp-ai-mcp-app-client.php';
		}
	}

	/**
	 * The shared corpus file, at the repository root.
	 */
	private static function corpus_file(): string {
		return dirname( __DIR__, 3 ) . '/tests/fixtures/mcp/handshake-cases.json';
	}

	/**
	 * Corpus rows as data-provider tuples.
	 *
	 * @return array<string, array{0: int, 1: int|null, 2: string, 3: bool, 4: bool}>
	 */
	public static function corpus_cases(): array {
		$file = self::corpus_file();

		if ( ! is_file( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture, not a URL.
		$decoded = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['cases'] ) || ! is_array( $decoded['cases'] ) ) {
			return array();
		}

		$cases = array();

		foreach ( $decoded['cases'] as $case ) {
			if ( ! is_array( $case ) || ! isset( $case['name'] ) ) {
				continue;
			}

			$cases[ (string) $case['name'] ] = array(
				(int) $case['http_status'],
				isset( $case['rpc_code'] ) ? (int) $case['rpc_code'] : null,
				(string) $case['message'],
				(bool) $case['legacy_rejection'],
				(bool) $case['stateless_rejection'],
			);
		}

		return $cases;
	}

	/**
	 * A missing corpus is a broken checkout, not a pass.
	 */
	public function test_the_corpus_is_present(): void {
		$this->assertNotSame( array(), self::corpus_cases() );
	}

	/**
	 * The adapter must answer every recorded case the way the corpus says.
	 *
	 * @param int      $http_status Recorded HTTP status.
	 * @param int|null $rpc_code    Recorded JSON-RPC code.
	 * @param string   $message     Recorded error message.
	 * @param bool     $legacy      Expected legacy verdict.
	 * @param bool     $stateless   Expected stateless verdict.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'corpus_cases' )]
	public function test_the_adapter_matches_the_recorded_case(
		int $http_status,
		?int $rpc_code,
		string $message,
		bool $legacy,
		bool $stateless
	): void {
		$client = self::client();

		$data = array( 'status' => $http_status );

		if ( null !== $rpc_code ) {
			$data['rpc_code'] = $rpc_code;
		}

		$error = new WP_Error( 'wp_mcp_ai_mcp_app_probe_failed', $message, $data );

		$this->assertSame( $legacy, self::classify( 'is_legacy_rejection', $client, $error ) );
		$this->assertSame( $stateless, self::classify( 'is_stateless_rejection', $client, $error ) );
	}

	/**
	 * The adapter and the core policy must agree on the same input.
	 *
	 * Proves the rules are shared rather than duplicated: if someone pastes the
	 * old inline comparators back into the adapter, this diverges.
	 */
	public function test_the_adapter_delegates_to_the_core_policy(): void {
		$this->assertTrue(
			class_exists( 'Nvoos\\Core\\Domain\\Mcp\\McpHandshakePolicy' ),
			'lib/core must be autoloaded in the WordPress runtime.'
		);

		$client = self::client();
		$error  = new WP_Error( 'wp_mcp_ai_mcp_app_probe_failed', 'Bad Request', array( 'status' => 400 ) );

		$this->assertSame(
			\Nvoos\Core\Domain\Mcp\McpHandshakePolicy::isLegacyRejection( 400, null, 'Bad Request' ),
			self::classify( 'is_legacy_rejection', $client, $error )
		);

		$this->assertSame(
			\Nvoos\Core\Domain\Mcp\McpHandshakePolicy::isStatelessRejection( 400, null ),
			self::classify( 'is_stateless_rejection', $client, $error )
		);
	}

	/**
	 * A client with no transport or credentials.
	 *
	 * The classifiers read only the error they are given, so an instance built
	 * without the constructor is a valid subject and needs no configuration.
	 *
	 * @return WP_MCP_AI_MCP_App_Client
	 */
	private static function client(): WP_MCP_AI_MCP_App_Client {
		$reflection = new ReflectionClass( WP_MCP_AI_MCP_App_Client::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Call a protected classifier from outside the class scope.
	 *
	 * @param string                   $method Protected method name.
	 * @param WP_MCP_AI_MCP_App_Client $client Subject.
	 * @param WP_Error                 $error  Handshake error.
	 * @return bool
	 */
	private static function classify( string $method, WP_MCP_AI_MCP_App_Client $client, WP_Error $error ): bool {
		$bound = Closure::bind(
			function () use ( $method, $error ) {
				return (bool) $this->{$method}( $error );
			},
			$client,
			WP_MCP_AI_MCP_App_Client::class
		);

		return null === $bound ? false : $bound();
	}
}
