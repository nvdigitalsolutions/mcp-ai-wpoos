<?php
/**
 * MCP JSON-RPC request shapes and result envelopes.
 *
 * Holds the three wire details a second runtime must not re-derive:
 *
 *  1. Which requests carry the 2026-07-28 `_meta` envelope, and which must not.
 *  2. The `params` shape per method — in particular that an empty argument map
 *     must encode as `{}`, not `[]`. A server that validates `params` as an
 *     object rejects the array form, and the failure is a 400 far from the
 *     cause.
 *  3. Where the payload sits in a result, and the SEP-2549 freshness bound that
 *     rides alongside it.
 *
 * Framework-neutral: the inputs are strings and arrays, the outputs are arrays,
 * `stdClass` where the wire requires an object, and ints. No transport and no
 * WordPress call. The client owns the request; this owns its shape.
 *
 * Extracted from `WP_MCP_AI_MCP_App_Client` under Proposal 090-I and relicensed
 * MIT so both runtimes share one wire contract.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Mcp;

final class McpRequestEnvelope {

	/**
	 * Methods that must never carry `_meta`.
	 *
	 * `initialize` negotiates the dialect the envelope belongs to, and
	 * `server/discover` is the stateless probe that replaces it; a 2025-era
	 * server that has never heard of `_meta` is the exact case these two target.
	 *
	 * @var string[]
	 */
	private const META_EXEMPT = array( 'initialize', 'server/discover' );

	/**
	 * Whether this request carries the 2026-07-28 `_meta` envelope.
	 *
	 * SEP-2575 requires protocol version, client identity and client
	 * capabilities on every request. Two exclusions: the two handshake methods
	 * above, and any session that negotiated a legacy protocol version, where
	 * the construct does not exist.
	 *
	 * @param string $method                     JSON-RPC method name.
	 * @param string $negotiatedProtocolVersion  '' before a handshake, or the version the session settled on.
	 * @return bool
	 */
	public static function injectsMeta( string $method, string $negotiatedProtocolVersion ): bool {
		return ! in_array( $method, self::META_EXEMPT, true ) && '' === $negotiatedProtocolVersion;
	}

	/**
	 * The `_meta` envelope to merge into request params.
	 *
	 * @param string $protocolVersion Negotiated protocol version.
	 * @param string $clientName      Client identity reported to the server.
	 * @param string $clientVersion   Client version reported to the server.
	 * @return array<string, mixed>
	 */
	public static function meta( string $protocolVersion, string $clientName, string $clientVersion ): array {
		return array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion'    => $protocolVersion,
				'io.modelcontextprotocol/clientInfo'         => array(
					'name'    => $clientName,
					'version' => $clientVersion,
				),
				'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			),
		);
	}

	/**
	 * Params for `tools/list`.
	 *
	 * An object, not an empty array: `[]` encodes as a JSON array and servers
	 * that validate the params type reject it.
	 *
	 * @return \stdClass
	 */
	public static function toolsListParams(): \stdClass {
		return new \stdClass();
	}

	/**
	 * Params for `tools/call`.
	 *
	 * Empty arguments must encode as `{}` for the same reason as above.
	 *
	 * @param string               $name      Tool name.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public static function toolCallParams( string $name, array $arguments ): array {
		return array(
			'name'      => $name,
			'arguments' => array() !== $arguments ? $arguments : new \stdClass(),
		);
	}

	/**
	 * Params for `resources/list`.
	 *
	 * @return \stdClass
	 */
	public static function resourcesListParams(): \stdClass {
		return new \stdClass();
	}

	/**
	 * Params for `resources/read`.
	 *
	 * @param string $uri Resource URI, for example `ui://server/resource`.
	 * @return array<string, string>
	 */
	public static function resourceReadParams( string $uri ): array {
		return array( 'uri' => $uri );
	}

	/**
	 * The tool definitions in a `tools/list` result.
	 *
	 * @param array<string, mixed> $result Decoded JSON-RPC result.
	 * @return array<int, mixed>
	 */
	public static function tools( array $result ): array {
		return isset( $result['tools'] ) && is_array( $result['tools'] ) ? $result['tools'] : array();
	}

	/**
	 * The server-declared tools/list cache bound, in milliseconds.
	 *
	 * SEP-2549: the registry caps its transient TTL at this value so a server
	 * that changes its catalogue is re-polled on time. 0 means none declared.
	 *
	 * @param array<string, mixed> $result Decoded JSON-RPC result.
	 * @return int
	 */
	public static function toolsTtlMs( array $result ): int {
		return isset( $result['ttlMs'] ) ? abs( (int) $result['ttlMs'] ) : 0;
	}

	/**
	 * The resource definitions in a `resources/list` result.
	 *
	 * @param array<string, mixed> $result Decoded JSON-RPC result.
	 * @return array<int, mixed>
	 */
	public static function resources( array $result ): array {
		return isset( $result['resources'] ) && is_array( $result['resources'] ) ? $result['resources'] : array();
	}
}
