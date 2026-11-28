<?php
/**
 * Native provider — the toolkit's own connection records.
 *
 * Proposal 167. A native connection is authored on the database settings page and
 * stored by {@see WP_MCP_AI_Db_Connection_Registry}. This provider makes it
 * visible through the same interface as a Remote Sites record, so `db_select`
 * does not care which store the endpoint came from.
 *
 * Visibility is scope-derived: a principal sees a connection because a scope binds
 * it, never because the connection exists.
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
 * Native database connections.
 */
final class WP_MCP_AI_Native_Connection_Provider implements WP_MCP_AI_Connection_Provider_Interface {

	/**
	 * Connection registry.
	 *
	 * @var WP_MCP_AI_Db_Connection_Registry
	 */
	private WP_MCP_AI_Db_Connection_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Db_Connection_Registry $registry Registry.
	 */
	public function __construct( WP_MCP_AI_Db_Connection_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'native';
	}

	/**
	 * {@inheritDoc}
	 */
	public function prefix(): string {
		return 'db';
	}

	/**
	 * Connections this principal may use, redacted.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @return list<ConnectionDescriptor>
	 */
	public function list_connections( PrincipalRef $principal ): array {
		return $this->registry->visible_to( $principal );
	}

	/**
	 * Whether this provider owns an id.
	 *
	 * @param string $id Namespaced or bare id.
	 */
	public function supports( string $id ): bool {
		$id = strtolower( trim( $id ) );

		if ( str_starts_with( $id, 'db:' ) ) {
			return true;
		}

		if ( str_contains( $id, ':' ) ) {
			return false;
		}

		return null !== $this->registry->get( $id );
	}

	/**
	 * Resolve an id to a descriptor.
	 *
	 * @param string       $id        Namespaced or bare id.
	 * @param PrincipalRef $principal Principal.
	 * @throws DatabaseRefusal When the id is unknown or not visible.
	 */
	public function resolve( string $id, PrincipalRef $principal ): ConnectionDescriptor {
		$bare = str_starts_with( strtolower( $id ), 'db:' ) ? substr( $id, 3 ) : $id;

		foreach ( $this->list_connections( $principal ) as $connection ) {
			if ( strtolower( trim( $bare ) ) === $connection->id ) {
				return $connection;
			}
		}

		// Distinguish "does not exist" from "exists but is not yours" only in the
		// audit detail: the caller gets one code either way, so a principal cannot
		// probe for connection ids.
		if ( null !== $this->registry->get( $bare ) ) {
			throw DatabaseRefusal::of(
				RefusalCode::SCOPE_NOT_ALLOWED,
				'Connection ' . $bare . ' is not bound to this principal.'
			);
		}

		throw DatabaseRefusal::of( RefusalCode::UNKNOWN_CONNECTION, 'Connection not found: ' . $bare );
	}
}
