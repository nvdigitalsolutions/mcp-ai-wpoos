<?php
/**
 * OAuth discovery candidate URLs for a remote MCP server.
 *
 * Builds the ordered probe list a client walks when looking for authorization
 * server metadata (RFC 8414) and protected resource metadata (RFC 9728), plus
 * the validity predicates for the documents those probes return.
 *
 * Framework-neutral by construction: the only input is a URL string and the
 * only outputs are strings, arrays of strings and booleans. No transport, no
 * WordPress call, no state. The WordPress adapter
 * (`WP_MCP_AI_MCP_App_OAuth_Client`) owns the requests and the attempt log and
 * delegates the ordering here.
 *
 * The ordering is the part worth sharing. It exists because real gateways do
 * not agree: a path-scoped server serves metadata under
 * `/.well-known/.../<path>` rather than at the origin, Auth0/Okta/Cognito
 * front-ends answer only at the OIDC discovery endpoint, and a self-hosted
 * WordPress MCP server may answer only at its REST route when the `.well-known`
 * rewrite is inactive. Dropping any one of them turns "this server has no OAuth"
 * into a false negative.
 *
 * Extracted from `WP_MCP_AI_MCP_App_OAuth_Client` under Proposal 090-I and
 * relicensed MIT so both runtimes share one ordering. Pinned by the corpus in
 * `tests/fixtures/mcp/oauth-discovery-cases.json`, which the core and the
 * WordPress suites both replay.
 *
 * Inputs are absolute http(s) URLs; a malformed one yields '' or an empty list
 * rather than a partial guess.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Mcp;

final class OAuthDiscoveryCandidates {

	/** RFC 8414 authorization server metadata, origin-relative. */
	private const AS_WELL_KNOWN = '/.well-known/oauth-authorization-server';

	/** RFC 9728 protected resource metadata, origin-relative. */
	private const PRM_WELL_KNOWN = '/.well-known/oauth-protected-resource';

	/** OIDC discovery, the fallback for gateways fronting an IdP. */
	private const OIDC_WELL_KNOWN = '/.well-known/openid-configuration';

	/** Self-hosted WordPress MCP servers, when the rewrite rule is inactive. */
	private const WP_REST_METADATA = '/wp-json/mcp-ai/v1/oauth/metadata';

	/**
	 * RFC 8414 candidate URLs for an authorization server.
	 *
	 * @param string $authorizationServer Authorization server URL, or the origin itself.
	 * @return string[] Candidates in priority order, de-duplicated.
	 */
	public static function authorizationServerUrlsFor( string $authorizationServer ): array {
		// Some servers advertise the full metadata URL itself in
		// `authorization_servers`, so it is fetched directly.
		if ( false !== strpos( $authorizationServer, self::AS_WELL_KNOWN ) ) {
			return array( rtrim( $authorizationServer, '/' ) );
		}

		$parts = self::parts( $authorizationServer );

		if ( empty( $parts['host'] ) ) {
			return array();
		}

		$origin = self::origin( $authorizationServer );
		$path   = trim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );

		$urls = array( $origin . self::AS_WELL_KNOWN );

		// RFC 8414 section 3.2 path insertion.
		if ( '' !== $path ) {
			$urls[] = $origin . self::AS_WELL_KNOWN . '/' . $path;
		}

		$urls[] = $origin . self::OIDC_WELL_KNOWN;
		$urls[] = $origin . self::WP_REST_METADATA;

		return array_values( array_unique( $urls ) );
	}

	/**
	 * RFC 8414 candidate URLs for the configured MCP server.
	 *
	 * @param string $serverUrl MCP server URL.
	 * @return string[] Candidates in priority order, de-duplicated.
	 */
	public static function authorizationServerMetadataUrls( string $serverUrl ): array {
		$origin = self::origin( $serverUrl );

		if ( '' === $origin ) {
			return array();
		}

		$urls = self::authorizationServerUrlsFor( $origin );
		$path = self::path( $serverUrl );

		// RFC 8414 section 3.2 path insertion against the MCP server's own path.
		if ( '' !== $path ) {
			$urls[] = $origin . self::AS_WELL_KNOWN . $path;
		}

		$rest = self::restMetadataUrl( $serverUrl );

		if ( '' !== $rest ) {
			$urls[] = $rest;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * RFC 9728 candidate URLs for the configured MCP server.
	 *
	 * @param string $serverUrl MCP server URL.
	 * @return string[] Candidates in priority order, de-duplicated.
	 */
	public static function protectedResourceUrls( string $serverUrl ): array {
		$origin = self::origin( $serverUrl );

		if ( '' === $origin ) {
			return array();
		}

		$urls = array( $origin . self::PRM_WELL_KNOWN );
		$path = self::path( $serverUrl );

		// API gateways commonly serve the document under the resource path.
		if ( '' !== $path ) {
			$urls[] = $origin . self::PRM_WELL_KNOWN . $path;
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * The REST metadata URL a self-hosted WordPress MCP server also exposes.
	 *
	 * Rewrites `/wp-json/mcp-ai/v1/mcp` to
	 * `/wp-json/mcp-ai/v1/oauth/metadata`.
	 *
	 * @param string $serverUrl MCP server URL.
	 * @return string Empty when the URL is malformed.
	 */
	public static function restMetadataUrl( string $serverUrl ): string {
		$parts = self::parts( $serverUrl );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme     = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$port       = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path       = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$restPrefix = (string) preg_replace( '#/mcp$#', '', $path );

		return $scheme . '://' . $parts['host'] . $port . $restPrefix . '/oauth/metadata';
	}

	/**
	 * Scheme, host and optional port.
	 *
	 * @param string $url Absolute URL.
	 * @return string Empty when the URL has no host.
	 */
	public static function origin( string $url ): string {
		$parts = self::parts( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return $scheme . '://' . $parts['host'] . $port;
	}

	/**
	 * Normalised path, with a leading slash and no trailing one.
	 *
	 * @param string $url Absolute URL.
	 * @return string '' for an origin-only URL.
	 */
	public static function path( string $url ): string {
		$parts = self::parts( $url );
		$path  = trim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );

		return '' === $path ? '' : '/' . $path;
	}

	/**
	 * Whether an RFC 8414 document is usable.
	 *
	 * Section 2 requires both endpoints; `token_endpoint` is omitted only for
	 * the implicit grant, which the MCP code flow never uses.
	 *
	 * @param array<string, mixed> $document Decoded document.
	 * @return bool
	 */
	public static function isAuthorizationServerMetadata( array $document ): bool {
		return ! empty( $document['authorization_endpoint'] ) && ! empty( $document['token_endpoint'] );
	}

	/**
	 * Whether an RFC 9728 document is usable.
	 *
	 * @param array<string, mixed> $document Decoded document.
	 * @return bool
	 */
	public static function isProtectedResourceMetadata( array $document ): bool {
		return ! empty( $document['authorization_servers'] );
	}

	/**
	 * Parsed URL components, or an empty array.
	 *
	 * @param string $url Absolute URL.
	 * @return array<string, mixed>
	 */
	private static function parts( string $url ): array {
		$parts = parse_url( $url );

		return is_array( $parts ) ? $parts : array();
	}
}
