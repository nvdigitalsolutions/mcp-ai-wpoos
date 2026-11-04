<?php
/**
 * PHPUnit tests for the native→Woo catalog migrator (Proposal 112).
 *
 * Runs against real WooCommerce CRUD (the local matrix loads Woo); the
 * WP 7.1 env without Woo skips the whole class — the repo convention for
 * optional-plugin suites.
 *
 * @package NvoosAstroBridge\Tests
 */

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\WooTaxonomy;
use NvoosAstroBridge\Woo\NativeCatalogMigrator;

/**
 * Native catalog migration tests.
 *
 * @covers \NvoosAstroBridge\Woo\NativeCatalogMigrator
 * @group nvoos-astro-bridge
 */
class TestNativeCatalogMigrator extends WP_UnitTestCase {

	/**
	 * Skip when WooCommerce is not loaded (the WP 7.1 matrix env).
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! WooTaxonomy::is_woo_available() ) {
			$this->markTestSkipped( 'WooCommerce is not loaded in this environment.' );
		}
	}

	/**
	 * Drop migration state + queued sweep actions between tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( Schema::OPTION_MIGRATION_STATE );
		delete_transient( 'nvoos_bridge_migration_report' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Schema::HOOK_MIGRATE_SWEEP, array(), 'nvoos-bridge-migrate' );
		}
		parent::tearDown();
	}

	/**
	 * Build a native product post with meta + optional terms.
	 *
	 * @param array<string, mixed> $args Overrides.
	 * @return int Native product id.
	 */
	private function create_native( array $args = array() ): int {
		$defaults = array(
			'post_title'   => 'Native Lamp',
			'post_content' => 'A lamp.',
			'post_status'  => 'publish',
			'post_type'    => Schema::CPT_PRODUCT,
		);
		$id       = wp_insert_post( array_merge( $defaults, $args ) );

		update_post_meta( $id, Schema::META_PRICE, $args['price'] ?? 100 );
		if ( isset( $args['old_price'] ) ) {
			update_post_meta( $id, Schema::META_OLD_PRICE, $args['old_price'] );
		}
		update_post_meta( $id, Schema::META_WIDTH, $args['width'] ?? 10 );
		update_post_meta( $id, Schema::META_HEIGHT, $args['height'] ?? 20 );
		update_post_meta( $id, Schema::META_DEPTH, $args['depth'] ?? 30 );
		update_post_meta( $id, Schema::META_IS_FEATURED, ! empty( $args['featured'] ) );

		if ( ! empty( $args['promotion_id'] ) ) {
			update_post_meta( $id, Schema::META_PROMOTION_ID, (int) $args['promotion_id'] );
		}

		if ( ! empty( $args['category'] ) ) {
			$term = wp_insert_term( $args['category'], Schema::TAX_CATEGORY );
			wp_set_post_terms( $id, array( (int) $term['term_id'] ), Schema::TAX_CATEGORY );
		}
		if ( ! empty( $args['brand'] ) ) {
			$term = wp_insert_term( $args['brand'], Schema::TAX_BRAND );
			wp_set_post_terms( $id, array( (int) $term['term_id'] ), Schema::TAX_BRAND );
		}

		return $id;
	}

	/**
	 * The dry run computes plans without writing anything.
	 */
	public function test_dry_run_writes_nothing(): void {
		$migrator = new NativeCatalogMigrator();
		$a        = $this->create_native(
			array(
				'post_title' => 'Dry A',
				'category'   => 'Lamps',
			)
		);
		$this->create_native( array( 'post_title' => 'Dry B' ) );

		$report = $migrator->dry_run();

		$this->assertSame( 2, $report['scanned'] );
		$this->assertSame( 2, $report['create'] );
		$this->assertSame( 0, $report['update'] );
		$this->assertSame( 0, $migrator->count_migrated() );
		$this->assertSame( 0, (int) wc_get_product_id_by_sku( Schema::SKU_PREFIX_MIGRATED . $a ) );
	}

	/**
	 * A migration creates a Woo product with every mapped field.
	 */
	public function test_migrate_creates_woo_product_with_fields(): void {
		$migrator  = new NativeCatalogMigrator();
		$promo     = wp_insert_post(
			array(
				'post_type'   => Schema::CPT_PROMOTION,
				'post_title'  => 'Summer Sale',
				'post_status' => 'publish',
			)
		);
		$native_id = $this->create_native(
			array(
				'post_title'   => 'Halo Chair',
				'old_price'    => 150,
				'price'        => 100,
				'featured'     => true,
				'promotion_id' => $promo,
				'category'     => 'Chairs',
				'brand'        => 'NV',
			)
		);

		$result = $migrator->migrate_product( $native_id );

		$this->assertSame( 'created', $result['action'] );
		$this->assertGreaterThan( 0, $result['woo_id'] );

		$product = wc_get_product( $result['woo_id'] );
		$this->assertInstanceOf( WC_Product::class, $product );
		$this->assertSame( 'Halo Chair', $product->get_name() );
		$this->assertSame( 'A lamp.', $product->get_description() );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( '150', $product->get_regular_price() );
		$this->assertSame( '100', $product->get_sale_price() );
		$this->assertSame( '10', $product->get_width() );
		$this->assertSame( '20', $product->get_height() );
		$this->assertSame( '30', $product->get_length() );
		$this->assertTrue( $product->get_featured() );
		$this->assertSame( Schema::SKU_PREFIX_MIGRATED . $native_id, $product->get_sku() );
		$this->assertSame( (string) $native_id, $product->get_meta( Schema::META_NVOOS_MIGRATED_FROM ) );
		$this->assertSame( (string) $promo, $product->get_meta( Schema::META_NVOOS_PROMOTION_ID ) );

		// Category mapped into product_cat (term created on demand).
		$category_ids = $product->get_category_ids();
		$this->assertCount( 1, $category_ids );
		$category = get_term( (int) $category_ids[0], Schema::TAX_WOO_CATEGORY );
		$this->assertInstanceOf( WP_Term::class, $category );
		$this->assertSame( 'Chairs', $category->name );

		// Brand: no pa_brand registered → the native taxonomy keeps the term.
		$brand_terms = wp_get_post_terms( $result['woo_id'], Schema::TAX_BRAND );
		$this->assertCount( 1, $brand_terms );
		$this->assertSame( 'NV', $brand_terms[0]->name );
	}

	/**
	 * Re-running the migration updates the existing Woo product instead of
	 * duplicating it — idempotent by SKU + migrated-from marker.
	 */
	public function test_migrate_is_idempotent(): void {
		$migrator  = new NativeCatalogMigrator();
		$native_id = $this->create_native( array( 'post_title' => 'First Name' ) );

		$first = $migrator->migrate_product( $native_id );
		$this->assertSame( 'created', $first['action'] );

		wp_update_post(
			array(
				'ID'         => $native_id,
				'post_title' => 'Second Name',
			)
		);

		$second = $migrator->migrate_product( $native_id );
		$this->assertSame( 'updated', $second['action'] );
		$this->assertSame( $first['woo_id'], $second['woo_id'] );

		$product = wc_get_product( $second['woo_id'] );
		$this->assertSame( 'Second Name', $product->get_name() );
		$this->assertSame( 1, $migrator->count_migrated() );
	}

	/**
	 * A native draft migrates as a Woo draft.
	 */
	public function test_migrate_preserves_draft_status(): void {
		$migrator  = new NativeCatalogMigrator();
		$native_id = $this->create_native( array( 'post_status' => 'draft' ) );

		$result  = $migrator->migrate_product( $native_id );
		$product = wc_get_product( $result['woo_id'] );
		$this->assertSame( 'draft', $product->get_status() );
	}

	/**
	 * A missing native post is skipped, never fabricated.
	 */
	public function test_migrate_missing_native_is_skipped(): void {
		$migrator = new NativeCatalogMigrator();
		$result   = $migrator->migrate_product( 999999 );
		$this->assertSame( 'skipped', $result['action'] );
		$this->assertSame( 0, $result['woo_id'] );
	}

	/**
	 * The sweep migrates in cursor chunks and records the state — the second
	 * chunk finishes the run and marks the state done.
	 */
	public function test_sweep_migrates_all_and_records_state(): void {
		add_filter( 'nvoos_bridge_migrator_batch_size', static fn() => 2 );

		$migrator = new NativeCatalogMigrator();
		$a        = $this->create_native( array( 'post_title' => 'Sweep A' ) );
		$b        = $this->create_native( array( 'post_title' => 'Sweep B' ) );
		$c        = $this->create_native( array( 'post_title' => 'Sweep C' ) );

		$migrator->handle_sweep( 0 );

		$state = $migrator->state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 2, $state['scanned'] );
		$this->assertSame( 2, $state['created'] );

		$migrator->handle_sweep( 2 );

		$state = $migrator->state();
		$this->assertSame( 3, $state['scanned'] );
		$this->assertSame( 3, $state['created'] );

		// The final chunk finds no more products and marks the run done.
		$migrator->handle_sweep( 4 );

		$state = $migrator->state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( 3, $migrator->count_migrated() );

		$this->assertGreaterThan( 0, (int) wc_get_product_id_by_sku( Schema::SKU_PREFIX_MIGRATED . $a ) );
		$this->assertGreaterThan( 0, (int) wc_get_product_id_by_sku( Schema::SKU_PREFIX_MIGRATED . $b ) );
		$this->assertGreaterThan( 0, (int) wc_get_product_id_by_sku( Schema::SKU_PREFIX_MIGRATED . $c ) );
	}
}
