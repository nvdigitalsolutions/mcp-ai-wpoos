<?php
/**
 * A package-only install builds the file registry the writer actually writes.
 *
 * The adapter's Testbench app loads only this package's migrations, so it is the
 * package-only install in miniature. `FileStore` writes `tenant_id`, `visibility`,
 * `slug`, `checksum` and `metadata`; until the identity migration existed, none of
 * them were created here, so the first `store()` died on an unknown column.
 *
 * These two assertions are the schema and the round trip that proves it is gone.
 *
 * @package Nvoos\Laravel\Tests
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nvoos\Laravel\Adapter\FileStore;

final class FileRegistrySchemaTest extends TestCase {

	use RefreshDatabase;

	public function test_the_package_migrations_build_every_column_the_writer_uses(): void {
		foreach ( array( 'tenant_id', 'visibility', 'slug', 'checksum', 'metadata' ) as $column ) {
			$this->assertTrue(
				Schema::hasColumn( 'nvoos_files', $column ),
				"nvoos_files.{$column} is written by FileStore but is not created by this package's migrations.",
			);
		}

		$this->assertTrue(
			Schema::hasIndex( 'nvoos_files', 'nvoos_files_tenant_slug_unique' ),
			'The composite slug index is the backstop for two uploads racing on one name.',
		);
	}

	public function test_a_package_only_install_can_store_and_read_a_file(): void {
		Storage::fake( 'local' );

		$source = tempnam( sys_get_temp_dir(), 'nvoos-registry-' );
		$this->assertIsString( $source );
		file_put_contents( $source, "A file the package's own schema had to accept.\n" );

		$store = new FileStore( 'local', 'local', 0, array(), array( 'default' => 1048576 ) );

		try {
			$stored = $store->store( $source, 'package-only.txt', 'text/plain', 1 );
		} finally {
			@unlink( $source );
		}

		$this->assertGreaterThan( 0, $stored->id );
		$this->assertSame( 'package-only.txt', $stored->filename );
		$this->assertSame( 'public', $stored->visibility );
		$this->assertNotSame( '', $stored->slug );

		$found = $store->getMetadata( $stored->id );

		$this->assertNotNull( $found );
		$this->assertSame( $stored->id, $found->id );
		$this->assertSame( 'package-only.txt', $found->filename );
		$this->assertSame( 'public', $found->visibility );
		$this->assertSame( $stored->slug, $found->slug );
	}
}
