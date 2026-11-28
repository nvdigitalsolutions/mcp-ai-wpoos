<?php
/**
 * CustomerDirectory adapter: lookup, search, pagination and ordering.
 *
 * Proposal 164 section 164.184. The adapter reads the host users table, which
 * the app skeleton owns rather than this package, so the suite creates it here:
 * an adapter test should prove the query contract against a real database, not
 * against the abstract schema the host happens to ship.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\CustomerDirectoryInterface;
use Nvoos\Core\Domain\Entity\CustomerCollection;
use Nvoos\Core\Domain\Entity\CustomerQuery;
use Nvoos\Laravel\Adapter\CustomerDirectory;

final class CustomerDirectoryTest extends TestCase {

	use RefreshDatabase;

	protected function setUp(): void {
		parent::setUp();

		if ( ! Schema::hasTable( 'users' ) ) {
			Schema::create( 'users', function ( Blueprint $table ): void {
				$table->id();
				$table->string( 'name' );
				$table->string( 'email' )->unique();
				$table->string( 'role', 32 )->default( 'subscriber' );
				$table->string( 'password' )->default( '' );
				$table->timestamps();
			} );

			return;
		}

		/*
		 * Testbench ships a `users` table without the role column, exactly as a
		 * host database predating the app skeleton's migration would. Add it the
		 * same way that migration does, guarding on the column rather than on the
		 * table.
		 */
		if ( ! Schema::hasColumn( 'users', 'role' ) ) {
			Schema::table( 'users', function ( Blueprint $table ): void {
				$table->string( 'role', 32 )->default( 'subscriber' );
			} );
		}

		// Testbench seeds an account of its own; a fixture set should be the whole
		// set, or a count assertion is really an assertion about the framework.
		DB::table( 'users' )->delete();
	}

	private function directory(): CustomerDirectoryInterface {
		return $this->app->make( CustomerDirectoryInterface::class );
	}

	/**
	 * Insert one account.
	 */
	private function account( string $name, string $email, string $role = 'customer' ): int {
		return (int) DB::table( 'users' )->insertGetId(
			array(
				'name'       => $name,
				'email'      => $email,
				'role'       => $role,
				'created_at' => '2024-01-01 00:00:00',
				'updated_at' => '2024-01-01 00:00:00',
			)
		);
	}

	/**
	 * @return list<int>
	 */
	private function ids( CustomerCollection $collection ): array {
		return array_map(
			static fn ( $customer ): int => $customer->id,
			$collection->items
		);
	}

	public function test_the_container_binds_the_contract_to_this_adapter(): void {
		$this->assertInstanceOf( CustomerDirectory::class, $this->directory() );
	}

	public function test_find_returns_the_account_and_null_when_missing(): void {
		$id = $this->account( 'Ada Lovelace', 'ada@example.test' );

		$found = $this->directory()->find( $id );

		$this->assertNotNull( $found );
		$this->assertSame( 'Ada Lovelace', $found->name );
		$this->assertSame( 'ada@example.test', $found->email );
		$this->assertSame( 'customer', $found->role );
		$this->assertSame( '2024-01-01', $found->registeredAt?->format( 'Y-m-d' ) );

		$this->assertNull( $this->directory()->find( $id + 99 ) );
	}

	public function test_query_reports_the_total_across_pages(): void {
		$this->account( 'Ada Lovelace', 'ada@example.test' );
		$this->account( 'Grace Hopper', 'grace@example.test' );
		$this->account( 'Alan Turing', 'alan@example.test' );

		$collection = $this->directory()->query( new CustomerQuery( perPage: 2 ) );

		$this->assertSame( 3, $collection->total );
		$this->assertSame( 2, $collection->totalPages );
		$this->assertCount( 2, $collection->items );
	}

	public function test_search_matches_the_name_and_the_email(): void {
		$ada = $this->account( 'Ada Lovelace', 'ada@example.test' );
		$this->account( 'Grace Hopper', 'grace@example.test' );

		$this->assertSame( array( $ada ), $this->ids( $this->directory()->query( new CustomerQuery( search: 'lovelace' ) ) ) );
		$this->assertSame( array( $ada ), $this->ids( $this->directory()->query( new CustomerQuery( search: 'ada@' ) ) ) );
	}

	public function test_search_escapes_wildcards(): void {
		$this->account( 'Ada Lovelace', 'ada@example.test' );
		$this->account( '100% Cotton', 'cotton@example.test' );

		$all  = $this->directory()->query( new CustomerQuery() )->total;
		$wild = $this->directory()->query( new CustomerQuery( search: '%' ) )->total;

		/*
		 * The term is escaped for LIKE, so a percent sign is a percent sign rather
		 * than a wildcard. SQLite has no default LIKE escape character while MySQL
		 * and Postgres use a backslash, so the exact match count differs between
		 * drivers; the property that has to hold everywhere is that a caller cannot
		 * turn one character into the whole table.
		 */
		$this->assertLessThan( $all, $wild, 'A wildcard search must not match every account.' );
	}

	public function test_the_role_filter_is_exact(): void {
		$customer = $this->account( 'Ada Lovelace', 'ada@example.test', 'customer' );
		$this->account( 'Root', 'root@example.test', 'administrator' );

		$this->assertSame(
			array( $customer ),
			$this->ids( $this->directory()->query( new CustomerQuery( role: 'customer' ) ) )
		);
	}

	public function test_ordering_ties_break_on_the_id(): void {
		$second = $this->account( 'Same Name', 'second@example.test' );
		$first = $this->account( 'Same Name', 'first@example.test' );

		$this->assertSame(
			array( min( $first, $second ), max( $first, $second ) ),
			$this->ids( $this->directory()->query( new CustomerQuery() ) )
		);
	}
}
