<?php
/**
 * One id space across two connection stores.
 *
 * Proposal 167 decisions D11 and D12. Ids are namespaced (`db:` for the native
 * database records, `rs:` for Remote Sites) and a bare id is treated as native.
 * The resolver never guesses between providers: an id that matches no provider is
 * refused, and an ambiguous bare id that exists in both stores resolves to native
 * with the namespaced form documented as the way to be explicit.
 *
 * ## Fail closed
 *
 * Registration of a provider is explicit and happens in the Pro toolkit
 * initialiser. A provider that is not registered cannot resolve anything, and the
 * Remote Sites provider is never constructed on a site without the Pro add-on —
 * there is no class to load, so no fallback is possible.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;

/**
 * Provider registry and id resolution.
 */
final class WP_MCP_AI_Connection_Resolver {

	/**
	 * Registered providers keyed by id.
	 *
	 * @var array<string, WP_MCP_AI_Connection_Provider_Interface>
	 */
	private array $providers = array();

	/**
	 * Register a provider.
	 *
	 * @param WP_MCP_AI_Connection_Provider_Interface $provider Provider.
	 */
	public function register( WP_MCP_AI_Connection_Provider_Interface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	/**
	 * Registered providers.
	 *
	 * @return array<string, WP_MCP_AI_Connection_Provider_Interface>
	 */
	public function providers(): array {
		return $this->providers;
	}

	/**
	 * Merge every provider's view for a principal.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @return list<ConnectionDescriptor>
	 */
	public function list_all( PrincipalRef $principal ): array {
		$merged = array();

		foreach ( $this->providers as $provider ) {
			foreach ( $provider->list_connections( $principal ) as $connection ) {
				$merged[ $connection->id ] = $connection;
			}
		}

		ksort( $merged );

		return array_values( $merged );
	}

	/**
	 * Resolve a namespaced or bare id.
	 *
	 * @param string       $id        Connection id.
	 * @param PrincipalRef $principal Principal.
	 * @throws DatabaseRefusal When the id is malformed, unknown, or not visible.
	 */
	public function resolve( string $id, PrincipalRef $principal ): ConnectionDescriptor {
		$id = strtolower( trim( $id ) );

		if ( '' === $id ) {
			throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Empty connection id.' );
		}

		$parts = explode( ':', $id, 2 );

		if ( 2 === count( $parts ) ) {
			$provider = $this->provider_by_prefix( $parts[0] );

			if ( null === $provider ) {
				throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Unknown connection provider: ' . $parts[0] );
			}

			return $provider->resolve( $id, $principal );
		}

		foreach ( $this->providers as $provider ) {
			if ( $provider->supports( $id ) ) {
				return $provider->resolve( $id, $principal );
			}
		}

		throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Connection not found: ' . $id );
	}

	/**
	 * Find a provider by prefix.
	 *
	 * @param string $prefix Prefix without the colon.
	 */
	private function provider_by_prefix( string $prefix ): ?WP_MCP_AI_Connection_Provider_Interface {
		foreach ( $this->providers as $provider ) {
			if ( $provider->prefix() === $prefix ) {
				return $provider;
			}
		}

		return null;
	}
}
