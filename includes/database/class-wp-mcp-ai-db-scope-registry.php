<?php
/**
 * Scope records — the grants the toolkit exists to introduce.
 *
 * Proposal 167 sections 167.6.3 and 167.6.4. A scope is stored separately from
 * its connection so one connection can serve several grants: a read-only analyst
 * scope and a `raw_sql` scope on the same warehouse are different records with
 * different principals, and disabling one does not disable the other.
 *
 * ## Versioning
 *
 * Every edit that changes a grant bumps `version`. The audit row records the
 * version that was in force, so an incident review can tell which allowlist saw a
 * query — a scope edited after the fact cannot rewrite history.
 *
 * ## Fail-closed normalisation
 *
 * A malformed principal is dropped, never widened to "everyone". An empty
 * principal list means nobody may use the scope, which is the safe interpretation
 * of a half-written record.
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
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\Contract\Database\ScopeRegistryInterface;

/**
 * CRUD for scope records.
 */
final class WP_MCP_AI_Db_Scope_Registry extends WP_MCP_AI_Db_Registry implements ScopeRegistryInterface {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	protected string $option_name = 'wp_mcp_ai_database_scopes';

	/**
	 * Create or replace a scope.
	 *
	 * @param array<string, mixed> $record Raw scope.
	 * @return ScopeDescriptor Saved scope.
	 * @throws DatabaseRefusal When the record is invalid.
	 */
	public function save( array $record ): ScopeDescriptor {
		$record['id'] = strtolower( trim( (string) ( $record['id'] ?? '' ) ) );

		$existing = $this->find( $record['id'] );
		// An existing record is at least v1 even when it was seeded without a
		// version — otherwise the first edit of a seeded scope would compute 0 + 1
		// and land on the same version it already had.
		$version = is_array( $existing ) ? max( 1, (int) ( $existing['version'] ?? 1 ) ) : 0;

		$next = new ScopeDescriptor(
			id: $record['id'],
			label: (string) ( $record['label'] ?? ( $record['name'] ?? $record['id'] ) ),
			connection: strtolower( trim( (string) ( $record['connection'] ?? '' ) ) ),
			schemas: $this->string_list( $record['schemas'] ?? array() ),
			tables: $this->string_list( $record['tables'] ?? array() ),
			columns: is_array( $record['columns'] ?? null ) ? $record['columns'] : array(),
			rowFilter: $this->rules( $record['row_filter'] ?? array() ),
			tiers: GrantTier::listFrom( is_array( $record['tiers'] ?? null ) ? $record['tiers'] : array() ),
			limits: is_array( $record['limits'] ?? null ) ? $record['limits'] : array(),
			principals: $this->principals( $record['principals'] ?? array() ),
			version: max( 1, $version ),
		);

		$document = $next->toArray();

		if ( is_array( $existing ) && $this->grants_changed( $existing, $document ) ) {
			$document['version'] = $version + 1;
		}

		if ( ! $this->put( $next->id, $document ) ) {
			throw DatabaseRefusal::of( RefusalCode::SCOPE_NOT_ALLOWED, 'Scope record could not be saved.' );
		}

		return ScopeDescriptor::fromArray( $document );
	}

	/**
	 * Fetch a scope by id.
	 *
	 * @param string $id Scope id.
	 */
	public function get( string $id ): ?ScopeDescriptor {
		$record = $this->find( $id );

		if ( null === $record ) {
			return null;
		}

		try {
			return ScopeDescriptor::fromArray( $record );
		} catch ( DatabaseRefusal $refusal ) {
			return null;
		}
	}

	/**
	 * Fetch a scope or refuse.
	 *
	 * @param string $id Scope id.
	 * @throws DatabaseRefusal When the scope does not exist or is invalid.
	 */
	public function get_or_refuse( string $id ): ScopeDescriptor {
		$scope = $this->get( $id );

		if ( null === $scope ) {
			throw DatabaseRefusal::of( RefusalCode::SCOPE_NOT_ALLOWED, 'Scope not found: ' . $id );
		}

		return $scope;
	}

	/**
	 * Scopes bound to a principal.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @param string|null  $connection Optional connection filter.
	 * @return list<ScopeDescriptor>
	 */
	public function for_principal( PrincipalRef $principal, ?string $connection = null ): array {
		$found      = array();
		$connection = null === $connection ? null : strtolower( trim( $connection ) );

		foreach ( $this->all() as $record ) {
			try {
				$scope = ScopeDescriptor::fromArray( $record );
			} catch ( DatabaseRefusal $refusal ) {
				continue;
			}

			if ( ! $scope->allowsPrincipal( $principal ) ) {
				continue;
			}

			if ( null !== $connection && $scope->connection !== $connection ) {
				continue;
			}

			$found[] = $scope;
		}

		usort( $found, static fn ( ScopeDescriptor $a, ScopeDescriptor $b ): int => strcmp( $a->id, $b->id ) );

		return $found;
	}

	/**
	 * Resolve the scope a request means.
	 *
	 * A request may name a scope explicitly, or omit it and get the first scope the
	 * principal holds for that connection at the required tier. Naming a scope the
	 * principal does not hold is a refusal, never a fallback: silently downgrading
	 * to a different grant would be the exact confusion the separation prevents.
	 *
	 * @param PrincipalRef $principal  Principal.
	 * @param string       $connection Connection id.
	 * @param string|null  $scope_id   Explicit scope id, or null.
	 * @param GrantTier    $tier       Tier the caller needs.
	 * @throws DatabaseRefusal When no scope satisfies the request.
	 */
	public function resolve_for(
		PrincipalRef $principal,
		string $connection,
		?string $scope_id,
		GrantTier $tier
	): ScopeDescriptor {
		if ( null !== $scope_id && '' !== trim( $scope_id ) ) {
			$scope = $this->get_or_refuse( $scope_id );

			if ( strtolower( trim( $connection ) ) !== $scope->connection ) {
				throw DatabaseRefusal::of(
					RefusalCode::SCOPE_NOT_ALLOWED,
					'Scope ' . $scope->id . ' does not grant connection ' . $connection . '.'
				);
			}

			if ( ! $scope->allowsPrincipal( $principal ) ) {
				throw DatabaseRefusal::of(
					RefusalCode::SCOPE_NOT_ALLOWED,
					'Principal is not bound to scope ' . $scope->id . '.'
				);
			}

			if ( ! $scope->hasTier( $tier ) ) {
				throw DatabaseRefusal::of( RefusalCode::TIER_REQUIRED, 'Scope ' . $scope->id . ' lacks tier ' . $tier->value . '.' );
			}

			return $scope;
		}

		foreach ( $this->for_principal( $principal, $connection ) as $scope ) {
			if ( $scope->hasTier( $tier ) ) {
				return $scope;
			}
		}

		throw DatabaseRefusal::of(
			RefusalCode::SCOPE_NOT_ALLOWED,
			'No scope binds this principal to ' . $connection . ' at tier ' . $tier->value . '.'
		);
	}

	/**
	 * Normalise a string list.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private function string_list( mixed $value ): array {
		$list = array();

		foreach ( (array) $value as $entry ) {
			if ( is_string( $entry ) && '' !== trim( $entry ) ) {
				$list[] = strtolower( trim( $entry ) );
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Normalise principal strings, dropping malformed entries.
	 *
	 * @param mixed $value Raw value.
	 * @return list<PrincipalRef>
	 */
	private function principals( mixed $value ): array {
		$list = array();

		foreach ( (array) $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$principal = PrincipalRef::tryFromString( $entry );

			if ( null !== $principal ) {
				$list[] = $principal;
			}
		}

		return $list;
	}

	/**
	 * Normalise row-filter rules.
	 *
	 * @param mixed $value Raw value.
	 * @return list<\Nvoos\Database\Domain\ValueObject\Database\RowFilterRule>
	 */
	private function rules( mixed $value ): array {
		$list = array();

		foreach ( (array) $value as $rule ) {
			if ( is_array( $rule ) ) {
				$list[] = \Nvoos\Database\Domain\ValueObject\Database\RowFilterRule::fromArray( $rule );
			}
		}

		return $list;
	}

	/**
	 * Whether a save changes anything that grants access.
	 *
	 * Comparing the normalised documents ignores `version` itself and any
	 * formatting difference the normaliser removed, so re-saving an unchanged form
	 * does not inflate the version.
	 *
	 * @param array<string, mixed> $before Stored record.
	 * @param array<string, mixed> $after  New document.
	 */
	private function grants_changed( array $before, array $after ): bool {
		unset( $before['version'], $after['version'] );

		return $before !== $after;
	}
}
