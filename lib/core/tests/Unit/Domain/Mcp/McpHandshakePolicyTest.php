<?php
/**
 * Unit tests for the MCP handshake policy.
 *
 * The wire behaviour lives in the shared corpus; this file covers the parts a
 * corpus cannot express — the attempt order and the constants the adapter and
 * the Laravel runtime both branch on.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Mcp;

use Nvoos\Core\Domain\Mcp\McpHandshakePolicy;
use PHPUnit\Framework\TestCase;

final class McpHandshakePolicyTest extends TestCase {

	public function testAHintedServerIsAskedForInitializeFirst(): void {
		$this->assertSame( McpHandshakePolicy::INITIALIZE, McpHandshakePolicy::firstAttempt( true ) );
	}

	public function testAnUnhintedServerIsProbedForDiscoverFirst(): void {
		$this->assertSame( McpHandshakePolicy::DISCOVER, McpHandshakePolicy::firstAttempt( false ) );
	}

	public function testTheMethodNamesMatchTheWireMethodNames(): void {
		// The Laravel runtime will branch on these, so they double as the
		// JSON-RPC method suffixes and must not drift into prose.
		$this->assertSame( 'discover', McpHandshakePolicy::DISCOVER );
		$this->assertSame( 'initialize', McpHandshakePolicy::INITIALIZE );
	}

	public function testASessionMessageAloneIsALegacySignalButNotAStatelessOne(): void {
		// The single most expensive mistake to re-derive: treating "session"
		// as proof the server upgraded would clear a correct hint and force a
		// guaranteed-failing discover probe on every request.
		$this->assertTrue( McpHandshakePolicy::isLegacyRejection( 500, null, 'No session' ) );
		$this->assertFalse( McpHandshakePolicy::isStatelessRejection( 500, null ) );
	}

	public function testANonIntegerRpcCodeNeverMatches(): void {
		// The adapter passes null when the body carried a code of another type;
		// a loose comparison would let "0" or a string look like a match.
		$this->assertFalse( McpHandshakePolicy::isLegacyRejection( 200, null, '' ) );
		$this->assertFalse( McpHandshakePolicy::isStatelessRejection( 200, null ) );
	}

	public function testTheTransportFailureStatusIsNotADialectSignal(): void {
		$this->assertFalse( McpHandshakePolicy::isLegacyRejection( 0, null, 'cURL error 7: refused' ) );
		$this->assertFalse( McpHandshakePolicy::isStatelessRejection( 0, null ) );
	}
}
