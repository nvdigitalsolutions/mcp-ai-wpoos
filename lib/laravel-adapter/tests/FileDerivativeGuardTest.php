<?php
/**
 * The derivative writer refuses a relationship it cannot stand behind.
 *
 * A wrong link is worse than a missing one, because a missing link is merely
 * missed while a wrong link is trusted — an inventory would report the poster as
 * present for a video it has nothing to do with. So the three refusals are
 * pinned here, before any row is touched: they are the whole safety argument.
 *
 * These are guard-only assertions, so they need no database and no disk, the same
 * shape as `FileUploadAllowlistTest`.
 *
 * @package Nvoos\Laravel\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Laravel\Adapter\FileStore;
use PHPUnit\Framework\TestCase;

final class FileDerivativeGuardTest extends TestCase {

	/**
	 * Every argument is supplied so the constructor never reaches `config()`,
	 * which needs a booted application.
	 */
	private function store(): FileStore {
		return new FileStore( 'public', 'local', 0, array(), array( 'default' => 1048576 ) );
	}

	public function test_the_adapter_advertises_the_capability(): void {
		$this->assertInstanceOf( FileDerivativeStoreInterface::class, $this->store() );
	}

	public function test_non_positive_ids_are_refused(): void {
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'must be positive' );

		$this->store()->recordDerivative( 0, 7, FileDerivativeStoreInterface::ROLE_POSTER );
	}

	public function test_a_file_cannot_derive_from_itself(): void {
		$this->expectException( ValidationException::class );
		$this->expectExceptionMessage( 'cannot derive from itself' );

		$this->store()->recordDerivative( 5, 5, FileDerivativeStoreInterface::ROLE_POSTER );
	}

	/**
	 * The tempting spellings — `thumbnail`, `thumb`, `caption_file` — are refused
	 * so the inventory can never be defeated by a synonym.
	 */
	public function test_a_role_outside_the_vocabulary_is_refused(): void {
		foreach ( array( 'thumbnail', 'thumb', 'POSTER', '' ) as $role ) {
			try {
				$this->store()->recordDerivative( 5, 7, $role );
				$this->fail( 'The role "' . $role . '" should have been refused.' );
			} catch ( ValidationException $error ) {
				$this->assertStringContainsString( 'Unknown derivative role', $error->getMessage(), $role );
			}
		}
	}
}
