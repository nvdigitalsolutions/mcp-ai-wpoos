<?php
/**
 * Unit tests for the MCP request shapes and result envelopes.
 *
 * The wire contract is asserted on the encoded JSON, not on the PHP value: an
 * empty params map must serialise as `{}`, and only the encoder can tell you
 * whether it will.
 *
 * @package Nvoos\Core\Tests
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Mcp;

use Nvoos\Core\Domain\Mcp\McpRequestEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class McpRequestEnvelopeTest extends TestCase {

	public function testAnEmptyToolsListParamsEncodesAsAnObject(): void {
		// `array()` would encode as `[]`, which a server validating params as
		// an object rejects with a 400 far from the cause.
		$this->assertSame( '{}', (string) json_encode( McpRequestEnvelope::toolsListParams() ) );
	}

	public function testAnEmptyResourcesListParamsEncodesAsAnObject(): void {
		$this->assertSame( '{}', (string) json_encode( McpRequestEnvelope::resourcesListParams() ) );
	}

	public function testToolCallWithoutArgumentsSendsAnEmptyObject(): void {
		$this->assertSame(
			'{"name":"get_weather","arguments":{}}',
			(string) json_encode( McpRequestEnvelope::toolCallParams( 'get_weather', array() ) )
		);
	}

	public function testToolCallWithArgumentsSendsThemVerbatim(): void {
		$this->assertSame(
			'{"name":"get_weather","arguments":{"city":"Kingston"}}',
			(string) json_encode( McpRequestEnvelope::toolCallParams( 'get_weather', array( 'city' => 'Kingston' ) ) )
		);
	}

	public function testResourceReadCarriesTheUri(): void {
		// No empty-object subtlety here, so the decoded shape is the honest
		// assertion; json_encode escapes slashes by default and that is valid.
		$this->assertSame(
			array( 'uri' => 'ui://server/card' ),
			McpRequestEnvelope::resourceReadParams( 'ui://server/card' )
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function metaCases(): array {
		return array(
			'initialize is exempt'          => array( 'initialize', '', false ),
			'server discover is exempt'     => array( 'server/discover', '', false ),
			'tools list carries meta'       => array( 'tools/list', '', true ),
			'tools call carries meta'       => array( 'tools/call', '', true ),
			'resources read carries meta'   => array( 'resources/read', '', true ),
			'legacy session never carries'  => array( 'tools/list', '2025-03-26', false ),
			'legacy initialize still exempt' => array( 'initialize', '2025-03-26', false ),
		);
	}

	/**
	 * @param string $method    JSON-RPC method.
	 * @param string $negotiated Negotiated protocol version.
	 * @param bool   $expected  Expected verdict.
	 */
	#[DataProvider( 'metaCases' )]
	public function testTheMetaInjectionRule( string $method, string $negotiated, bool $expected ): void {
		$this->assertSame( $expected, McpRequestEnvelope::injectsMeta( $method, $negotiated ) );
	}

	public function testTheMetaEnvelopeCarriesVersionIdentityAndCapabilities(): void {
		$meta = McpRequestEnvelope::meta( '2026-07-28', 'NV oOS', '1.9.0' );

		$this->assertSame( '2026-07-28', $meta['_meta']['io.modelcontextprotocol/protocolVersion'] );
		$this->assertSame( 'NV oOS', $meta['_meta']['io.modelcontextprotocol/clientInfo']['name'] );
		$this->assertSame( '1.9.0', $meta['_meta']['io.modelcontextprotocol/clientInfo']['version'] );

		// Capabilities are empty today, but must still be an object on the wire.
		$this->assertSame(
			'{}',
			(string) json_encode( $meta['_meta']['io.modelcontextprotocol/clientCapabilities'] )
		);
	}

	public function testToolsAreExtractedOrFallBackToAnEmptyList(): void {
		$this->assertSame(
			array( array( 'name' => 'a' ) ),
			McpRequestEnvelope::tools( array( 'tools' => array( array( 'name' => 'a' ) ) ) )
		);

		$this->assertSame( array(), McpRequestEnvelope::tools( array() ) );
		$this->assertSame( array(), McpRequestEnvelope::tools( array( 'tools' => 'not-a-list' ) ) );
	}

	public function testResourcesAreExtractedOrFallBackToAnEmptyList(): void {
		$this->assertSame(
			array( array( 'uri' => 'ui://a' ) ),
			McpRequestEnvelope::resources( array( 'resources' => array( array( 'uri' => 'ui://a' ) ) ) )
		);

		$this->assertSame( array(), McpRequestEnvelope::resources( array() ) );
	}

	public function testTheToolsTtlIsAbsoluteAndDefaultsToZero(): void {
		$this->assertSame( 500, McpRequestEnvelope::toolsTtlMs( array( 'ttlMs' => 500 ) ) );
		$this->assertSame( 500, McpRequestEnvelope::toolsTtlMs( array( 'ttlMs' => '500' ) ) );
		$this->assertSame( 20, McpRequestEnvelope::toolsTtlMs( array( 'ttlMs' => -20 ) ) );
		$this->assertSame( 0, McpRequestEnvelope::toolsTtlMs( array( 'ttlMs' => 'not-a-number' ) ) );
		$this->assertSame( 0, McpRequestEnvelope::toolsTtlMs( array() ) );
	}
}
