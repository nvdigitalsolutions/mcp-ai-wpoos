<?php

/**
 * Read access to customer accounts, over the host users table.
 *
 * Proposal 164 section 164.184. The host this runtime ships with keeps accounts
 * in `users` with `name`, `email`, `role` and `created_at`; the `role` column is
 * part of the app skeleton (it carries the WordPress role vocabulary), so an
 * adapter over a host without it would need that migration first.
 *
 * Nothing here reads orders. Spend and order counts live behind
 * `OrderStoreInterface`, because they need a currency and this is a lookup.
 *
 * @package Nvoos\Laravel
 * @since   1.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\CustomerDirectoryInterface;
use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\CustomerCollection;
use Nvoos\Core\Domain\Entity\CustomerQuery;

class CustomerDirectory implements CustomerDirectoryInterface {

	/**
	 * One account by id.
	 */
	public function find( int $id ): ?Customer {
		$row = DB::table( 'users' )
			->select( array( 'id', 'name', 'email', 'role', 'created_at' ) )
			->where( 'id', $id )
			->first();

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * One account by email, case-insensitively.
	 */
	public function findByEmail( string $email ): ?Customer {
		$row = DB::table( 'users' )
			->select( array( 'id', 'name', 'email', 'role', 'created_at' ) )
			->whereRaw( 'LOWER(email) = ?', array( strtolower( $email ) ) )
			->first();

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * One page of matching accounts.
	 */
	public function query( CustomerQuery $query ): CustomerCollection {
		$builder = DB::table( 'users' )
			->select( array( 'id', 'name', 'email', 'role', 'created_at' ) );

		if ( '' !== $query->search ) {
			// The wildcards in a caller's term are escaped, so a search for a
			// literal percent sign does not become a match-everything query.
			$term = '%' . addcslashes( $query->search, '%_' . chr( 92 ) ) . '%';

			$builder->where(
				static function ( $scoped ) use ( $term ): void {
					$scoped->where( 'name', 'like', $term )
						->orWhere( 'email', 'like', $term );
				}
			);
		}

		if ( null !== $query->role ) {
			$builder->where( 'role', $query->role );
		}

		$total = (int) ( clone $builder )->count();

		$rows = $builder
			->orderBy( $query->orderBy, $query->order )
			->orderBy( 'id' )
			->offset( $query->getOffset() )
			->limit( $query->perPage )
			->get();

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return new CustomerCollection(
			$items,
			$total,
			$query->page,
			$query->perPage,
			(int) ceil( $total / $query->perPage )
		);
	}

	/**
	 * A users row as a domain customer.
	 *
	 * @param object $row Row from the users table.
	 */
	private function hydrate( object $row ): Customer {
		return new Customer(
			(int) $row->id,
			(string) $row->name,
			(string) $row->email,
			null === $row->role ? null : (string) $row->role,
			null === $row->created_at ? null : new \DateTimeImmutable( (string) $row->created_at ),
		);
	}
}
