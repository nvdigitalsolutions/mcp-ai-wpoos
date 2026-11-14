<?php
/**
 * RFC 7235 WWW-Authenticate challenge parsing.
 *
 * Extracts the auth-params a 401/403 challenge carries, which is how the MCP
 * Authorization Specification transports the protected-resource pointer:
 *
 *     WWW-Authenticate: Bearer resource_metadata="https://...", scope="mcp"
 *
 * Framework-neutral: the input is a header string and the output is an array.
 *
 * Two details carry the value, and both were bugs once:
 *
 *  - A header may carry more than one challenge (`Basic realm="x", Bearer
 *    resource_metadata="..."`). Matching across the whole header lets the
 *    leading challenge corrupt the parameter extraction, so the header is split
 *    on commas *outside* quoted strings first, per RFC 7235 section 2.1.
 *  - Only quoted parameters are collected, per section 2.2. A token value is
 *    not a URL, and accepting one would hand the caller a probe target it
 *    cannot fetch.
 *
 * Extracted from `WP_MCP_AI_MCP_App_OAuth_Client` under Proposal 090-I and
 * relicensed MIT so both runtimes share one parser.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Mcp;

final class WwwAuthenticateChallenge {

	/**
	 * Parse every challenge into one parameter map.
	 *
	 * First occurrence of a key wins, so a later challenge cannot overwrite a
	 * pointer the earlier one supplied.
	 *
	 * @param string $header Raw WWW-Authenticate value.
	 * @return array<string, string> Lowercased parameter names to values.
	 */
	public static function parse( string $header ): array {
		$params = array();

		foreach ( self::split( $header ) as $challenge ) {
			$paramString = $challenge;

			// Strip the auth-scheme token when present (e.g. "Bearer ...").
			if ( preg_match( '/^([a-zA-Z0-9._~+\/-]+)\s+(.+)$/', $challenge, $schemeMatch ) ) {
				$paramString = $schemeMatch[2];
			}

			if ( preg_match_all( '/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*"([^"]*)"/', $paramString, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$key = strtolower( $match[1] );

					if ( ! isset( $params[ $key ] ) ) {
						$params[ $key ] = $match[2];
					}
				}
			}
		}

		return $params;
	}

	/**
	 * Split a header into individual challenges.
	 *
	 * Commas inside quoted strings are data, not separators.
	 *
	 * @param string $header Raw WWW-Authenticate value.
	 * @return string[] Non-empty trimmed challenges.
	 */
	public static function split( string $header ): array {
		$challenges = array();
		$current    = '';
		$inQuotes   = false;
		$length     = strlen( $header );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $header[ $i ];

			if ( '"' === $char ) {
				$inQuotes = ! $inQuotes;
			}

			if ( ',' === $char && ! $inQuotes ) {
				$challenges[] = trim( $current );
				$current      = '';
				continue;
			}

			$current .= $char;
		}

		$challenges[] = trim( $current );

		return array_values( array_filter( $challenges ) );
	}
}
