<?php
/**
 * MCP handshake dialect policy.
 *
 * Decides how to read a failed handshake for servers on either side of the
 * MCP 2026-07-28 change from sessionful `initialize` to stateless
 * `server/discover`.
 *
 * Framework-neutral by construction: the inputs are a status code, a JSON-RPC
 * error code and a message; there is no transport, no WordPress call and no
 * state. The WordPress adapter (`WP_MCP_AI_MCP_App_Client`) owns the request
 * and delegates the decision here, so a second runtime can answer the same
 * questions without re-deriving them.
 *
 * The two predicates encode what a re-implementation kept getting wrong:
 *
 *  - A strict 2025-era gateway answers an unknown probe method with a bare
 *    HTTP 400 and no JSON-RPC envelope, so the status alone has to count.
 *  - A "session" message is evidence of a *legacy* server, never of a stateless
 *    one, so it must not appear in the stateless test — a sessionful server
 *    complaining about its session is still a sessionful server.
 *
 * Extracted from `WP_MCP_AI_MCP_App_Client` under Proposal 090-I and relicensed
 * MIT so both runtimes can share it. Behaviour is pinned by the corpus in
 * `tests/fixtures/mcp/handshake-cases.json`, which the core and the WordPress
 * suites both replay.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Mcp;

final class McpHandshakePolicy {

	/** Stateless dialect: MCP 2026-07-28 and later. */
	public const DISCOVER = 'discover';

	/** Sessionful dialect: pre-2026-07-28. */
	public const INITIALIZE = 'initialize';

	/** JSON-RPC "method not found". */
	private const RPC_METHOD_NOT_FOUND = -32601;

	/** JSON-RPC "invalid request". */
	private const RPC_INVALID_REQUEST = -32600;

	/**
	 * Bare HTTP statuses a strict gateway returns for an unknown probe method.
	 *
	 * @var int[]
	 */
	private const GATEWAY_REJECTIONS = array( 400, 404, 405, 501 );

	/**
	 * Which handshake to attempt first.
	 *
	 * A cached legacy hint means this server already refused the stateless
	 * probe, so trying `initialize` first saves a round trip that is known to
	 * fail.
	 *
	 * @param bool $legacyHint Whether a legacy-dialect hint is cached.
	 * @return string One of the method constants.
	 */
	public static function firstAttempt( bool $legacyHint ): string {
		return $legacyHint ? self::INITIALIZE : self::DISCOVER;
	}

	/**
	 * Does a failed discovery imply a sessionful (legacy) server?
	 *
	 * @param int      $httpStatus HTTP status, 0 when the transport failed.
	 * @param int|null $rpcCode    JSON-RPC error code, null when absent.
	 * @param string   $message    Error message, in any case.
	 * @return bool
	 */
	public static function isLegacyRejection( int $httpStatus, ?int $rpcCode, string $message ): bool {
		if ( self::isUnknownHandshakeMethod( $httpStatus, $rpcCode ) ) {
			return true;
		}

		return false !== strpos( strtolower( $message ), 'session' );
	}

	/**
	 * Does a failed `initialize` imply the server dropped the legacy dialect?
	 *
	 * Deliberately ignores the message; see the class note.
	 *
	 * @param int      $httpStatus HTTP status, 0 when the transport failed.
	 * @param int|null $rpcCode    JSON-RPC error code, null when absent.
	 * @return bool
	 */
	public static function isStatelessRejection( int $httpStatus, ?int $rpcCode ): bool {
		return self::isUnknownHandshakeMethod( $httpStatus, $rpcCode );
	}

	/**
	 * A rejection that names the handshake method itself.
	 *
	 * @param int      $httpStatus HTTP status.
	 * @param int|null $rpcCode    JSON-RPC error code.
	 * @return bool
	 */
	private static function isUnknownHandshakeMethod( int $httpStatus, ?int $rpcCode ): bool {
		return in_array( $rpcCode, array( self::RPC_METHOD_NOT_FOUND, self::RPC_INVALID_REQUEST ), true )
			|| in_array( $httpStatus, self::GATEWAY_REJECTIONS, true );
	}
}
