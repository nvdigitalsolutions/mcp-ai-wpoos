<?php
/**
 * Replays the shared MCP handshake corpus against the core policy.
 *
 * The fixtures are the same file the WordPress-side test uses, so the policy
 * and its adapter are held to one contract. No WordPress bootstrap: this is
 * plain PHP reading JSON.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Mcp;

use Nvoos\Core\Domain\Mcp\McpHandshakePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpHandshakeCorpusTest extends TestCase {

	/**
	 * Repository-root corpus file.
	 *
	 * The corpus sits outside the package because the WordPress test replays
	 * it too. In the split `nvoos/core` repository it is absent, which
	 * testTheCorpusIsPresent() reports explicitly rather than passing quietly.
	 */
	private static function corpusFile(): string {
		return dirname( __DIR__, 6 ) . '/tests/fixtures/mcp/handshake-cases.json';
	}

	/**
	 * @return array<string, array{0: int, 1: int|null, 2: string, 3: bool, 4: bool}>
	 */
	public static function corpusCases(): array {
		$file = self::corpusFile();

		if ( ! is_file( $file ) ) {
			return array();
		}

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
	public function testTheCorpusIsPresent(): void {
		$this->assertNotSame(
			array(),
			self::corpusCases(),
			'The shared MCP handshake corpus is missing or unreadable.'
		);
	}

	/**
	 * @param int      $httpStatus Recorded HTTP status.
	 * @param int|null $rpcCode    Recorded JSON-RPC code.
	 * @param string   $message    Recorded error message.
	 * @param bool     $legacy     Expected legacy verdict.
	 * @param bool     $stateless  Expected stateless verdict.
	 */
	#[DataProvider( 'corpusCases' )]
	public function testItClassifiesTheRecordedCase(
		int $httpStatus,
		?int $rpcCode,
		string $message,
		bool $legacy,
		bool $stateless
	): void {
		$this->assertSame(
			$legacy,
			McpHandshakePolicy::isLegacyRejection( $httpStatus, $rpcCode, $message )
		);

		$this->assertSame(
			$stateless,
			McpHandshakePolicy::isStatelessRejection( $httpStatus, $rpcCode )
		);
	}
}
