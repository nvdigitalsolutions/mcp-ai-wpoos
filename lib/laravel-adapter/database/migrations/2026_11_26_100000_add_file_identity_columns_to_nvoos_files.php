<?php
/**
 * The file registry a package-only install gets must be the registry `FileStore` writes.
 *
 * `2026_01_01_000006_create_nvoos_files_table` created six columns: path, disk,
 * filename, mime_type, size_bytes and owner_id. Five more arrived later, each with
 * a slice that also shipped an app-side reader, so each landed in the app
 * skeleton's migrations rather than in this package:
 *
 *  - `tenant_id` (Proposal 149 §109) — default `default`, so existing rows are
 *    attributed without a backfill.
 *  - `visibility` (Proposal 162) — default `public`, because every row written
 *    before it existed is public.
 *  - `checksum` (Proposal 162) — nullable sha256; a migration must not read every
 *    object in storage to fill it.
 *  - `metadata` (Proposal 162) — the JSON column `findByMetadata()` documents.
 *  - `slug` (Proposal 162) — nullable, with the composite unique index that makes
 *    a slug collision a retry rather than a shared name.
 *
 * On a deployment the skeleton covers the gap. On an install that has only this
 * package — which is what the adapter's own test suite builds — nothing does:
 * `FileStore::insertRow()` writes all five columns, so the first `store()` fails on
 * an unknown column and the registry cannot record a file at all. §164.46 recorded
 * the defect and left it for its own slice; this is that slice.
 *
 * The definitions mirror the skeleton's migrations so a table built by either path
 * is the same table. Every step is guarded, which is what makes this safe on both
 * paths: where the columns already exist the migration is a no-op, and a guarded
 * add replays the same way on SQLite as it does on MySQL.
 *
 * @package Nvoos\Laravel
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

	public function up(): void {
		if ( ! Schema::hasTable( 'nvoos_files' ) ) {
			return;
		}

		// Before `slug`, because the unique index below spans both columns.
		if ( ! Schema::hasColumn( 'nvoos_files', 'tenant_id' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->string( 'tenant_id', 64 )->default( 'default' );
				$table->index( 'tenant_id' );
			} );
		}

		if ( ! Schema::hasColumn( 'nvoos_files', 'visibility' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->string( 'visibility', 16 )->default( 'public' );
				$table->index( 'visibility' );
			} );
		}

		if ( ! Schema::hasColumn( 'nvoos_files', 'checksum' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->string( 'checksum', 64 )->nullable();
				$table->index( 'checksum' );
			} );
		}

		if ( ! Schema::hasColumn( 'nvoos_files', 'metadata' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->json( 'metadata' )->nullable();
			} );
		}

		if ( ! Schema::hasColumn( 'nvoos_files', 'slug' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->string( 'slug', 160 )->nullable();
			} );
		}

		// Null slugs do not collide with each other in MySQL or SQLite, so this is
		// safe over legacy rows; the writer computes the suffix, and the index is
		// the authority when two uploads race on the same name.
		if ( ! Schema::hasIndex( 'nvoos_files', 'nvoos_files_tenant_slug_unique' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->unique( array( 'tenant_id', 'slug' ), 'nvoos_files_tenant_slug_unique' );
			} );
		}
	}

	public function down(): void {
		if ( ! Schema::hasTable( 'nvoos_files' ) ) {
			return;
		}

		// The composite index spans `tenant_id`, so it has to go before the column.
		if ( Schema::hasIndex( 'nvoos_files', 'nvoos_files_tenant_slug_unique' ) ) {
			Schema::table( 'nvoos_files', function ( Blueprint $table ): void {
				$table->dropUnique( 'nvoos_files_tenant_slug_unique' );
			} );
		}

		foreach ( array( 'slug', 'metadata', 'checksum', 'visibility', 'tenant_id' ) as $column ) {
			if ( ! Schema::hasColumn( 'nvoos_files', $column ) ) {
				continue;
			}

			Schema::table( 'nvoos_files', function ( Blueprint $table ) use ( $column ): void {
				$table->dropColumn( $column );
			} );
		}
	}
};
