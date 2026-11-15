<?php
/**
 * Native → WooCommerce catalog migrator (Proposal 112 — the Woo-owned
 * catalog).
 *
 * One-time, idempotent migration of `funiq_product` posts into WooCommerce
 * products. No sync layer exists and none is planned: the CMS edits Woo
 * products through WooProductSource after the migration, and the native
 * source retires one release later.
 *
 * Idempotency anchors (either alone is sufficient to re-find the shadow):
 *   - the generated SKU `NV-<native_id>` (namespaced, cannot collide with
 *     real store SKUs), looked up via wc_get_product_id_by_sku();
 *   - the Woo meta `_nvoos_bridge_migrated_from` = native_id.
 *
 * Re-running the migration therefore UPDATES the existing Woo product
 * instead of duplicating it.
 *
 * Performance (Woo core issue #27249 — bulk saves are term-count bound):
 * batches wrap in wp_defer_term_counting(true) +
 * wp_suspend_cache_invalidation(true).
 *
 * Research-backed: WC CRUD objects over raw meta writes (Woo developer
 * docs), SKU-keyed idempotent upserts (feed-import practice), Action
 * Scheduler cursor chunks (the repo's wp-action-scheduler skill), and
 * dry-run-by-default operator UX (WP-CLI/VIP best practices).
 *
 * @package NvoosAstroBridge\Woo
 */

namespace NvoosAstroBridge\Woo;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\WooTaxonomy;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Migrates the native catalog into WooCommerce.
 */
class NativeCatalogMigrator {

	/**
	 * Default products per sweep chunk.
	 */
	private const DEFAULT_BATCH = 10;

	/**
	 * Action Scheduler group for migration actions.
	 */
	private const AS_GROUP = 'nvoos-bridge-migrate';

	/**
	 * The migration state option shape.
	 */
	private const STATE_DEFAULTS = array(
		'status'      => 'idle', // idle | running | done | failed.
		'started_at'  => '',
		'finished_at' => '',
		'scanned'     => 0,
		'created'     => 0,
		'updated'     => 0,
		'failed'      => 0,
		'issues'      => array(),
	);

	/**
	 * Whether WooCommerce is really loaded (the two-part check — a stub
	 * WooCommerce class exists in test envs without the plugin).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return WooTaxonomy::is_woo_available();
	}

	/**
	 * The products-per-chunk batch size.
	 *
	 * @return int
	 */
	public function batch_size(): int {
		return (int) apply_filters( 'nvoos_bridge_migrator_batch_size', self::DEFAULT_BATCH );
	}

	/**
	 * Count native products (any status — drafts migrate too).
	 *
	 * @return int
	 */
	public function count_native(): int {
		$query = new WP_Query(
			array(
				'post_type'      => Schema::CPT_PRODUCT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Count migrated Woo products (the migrated-from marker).
	 *
	 * @return int
	 */
	public function count_migrated(): int {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Schema::META_NVOOS_MIGRATED_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery -- Indexed lookup by the migration marker.
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Native product IDs, cursor-paged by date (stable order across chunks).
	 *
	 * @param int $offset Number of products to skip.
	 * @param int $limit  Maximum ids to return.
	 * @return array<int, int>
	 */
	public function native_ids( int $offset, int $limit ): array {
		$query = new WP_Query(
			array(
				'post_type'      => Schema::CPT_PRODUCT,
				'post_status'    => 'any',
				'posts_per_page' => max( 1, $limit ),
				'offset'         => max( 0, $offset ),
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Dry-run the whole catalog: compute every mapping WITHOUT writing
	 * anything — report creates/updates, term creations and issues.
	 *
	 * @param int $limit  Cap the scanned set (-1 = everything).
	 * @param int $offset Start offset.
	 * @return array<string, mixed>
	 */
	public function dry_run( int $limit = -1, int $offset = 0 ): array {
		if ( ! $this->is_available() ) {
			return $this->unavailable_report();
		}

		$report = array(
			'total'         => $this->count_native(),
			'scanned'       => 0,
			'create'        => 0,
			'update'        => 0,
			'failed'        => 0,
			'terms_created' => 0,
			'issues'        => array(),
		);

		$cursor  = $offset;
		$scanned = 0;
		while ( true ) {
			$ids = $this->native_ids( $cursor, $this->batch_size() );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$plan = $this->plan_product( (int) $id );
				if ( null === $plan ) {
					++$report['failed'];
					continue;
				}
				++$report['scanned'];
				if ( 'create' === $plan['action'] ) {
					++$report['create'];
				} else {
					++$report['update'];
				}
				$report['terms_created'] += count( $plan['terms_to_create'] );
				foreach ( $plan['issues'] as $issue ) {
					$report['issues'][] = $issue;
				}
				++$scanned;
				if ( $limit > 0 && $scanned >= $limit ) {
					break 2;
				}
			}
			$cursor += $this->batch_size();
		}

		return $report;
	}

	/**
	 * Compute the migration plan for one native product — pure reads, no
	 * writes. Shared by dry_run() and migrate_product().
	 *
	 * @param int $native_id Native product id.
	 * @return array<string, mixed>|null Null when the product is missing.
	 */
	private function plan_product( int $native_id ): ?array {
		$post = get_post( $native_id );
		if ( ! $post instanceof WP_Post || Schema::CPT_PRODUCT !== $post->post_type ) {
			return null;
		}

		$fields = $this->read_native_fields( $post );

		$woo_id = $this->find_woo_id( $native_id );
		$action = $woo_id > 0 ? 'update' : 'create';

		$issues          = array();
		$terms_to_create = array();
		$fields          = $this->map_field_terms( $fields, $issues, $terms_to_create );

		foreach ( $issues as &$issue ) {
			$issue['nativeId'] = $native_id;
		}
		unset( $issue );

		return array(
			'action'          => $action,
			'woo_id'          => $woo_id,
			'fields'          => $fields,
			'issues'          => $issues,
			'terms_to_create' => $terms_to_create,
		);
	}

	/**
	 * Map the native term fields onto their Woo targets, resolving each
	 * source term to a target-term id (or queueing a creation in $to_create
	 * when the target term does not exist yet).
	 *
	 * @param array<string, mixed>             $fields    Native field bundle.
	 * @param array<int, array<string, mixed>> $issues    Issue sink (by reference).
	 * @param array<int, array<string, mixed>> $to_create Term-creation queue (by reference).
	 * @return array<string, mixed> The field bundle with the resolved ids.
	 */
	private function map_field_terms( array $fields, array &$issues, array &$to_create ): array {
		// Category → product_cat.
		if ( $fields['category'] instanceof \WP_Term ) {
			$fields['category_id'] = $this->resolve_term( $fields['category'], Schema::TAX_WOO_CATEGORY, false, $issues, $to_create );
		} else {
			$fields['category_id'] = 0;
		}

		// Brand → pa_brand (or the native fallback taxonomy).
		if ( $fields['brand'] instanceof \WP_Term ) {
			$fields['brand_tax'] = WooTaxonomy::brand_taxonomy();
			$fields['brand_id']  = $this->resolve_term( $fields['brand'], $fields['brand_tax'], false, $issues, $to_create );
		} else {
			$fields['brand_tax'] = '';
			$fields['brand_id']  = 0;
		}

		// Colors → pa_color (or the native fallback taxonomy).
		$fields['color_tax'] = WooTaxonomy::color_taxonomy();
		$fields['color_ids'] = array();
		foreach ( $fields['colors'] as $color_term ) {
			$mapped = $this->resolve_term( $color_term, $fields['color_tax'], false, $issues, $to_create );
			if ( $mapped > 0 ) {
				$fields['color_ids'][] = $mapped;
			}
		}

		return $fields;
	}

	/**
	 * Migrate (create or update) one native product into Woo.
	 *
	 * @param int $native_id Native product id.
	 * @return array{action: string, woo_id: int, issues: array<int, array<string, mixed>>}|WP_Error
	 */
	public function migrate_product( int $native_id ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'astro_woo_unavailable',
				'WooCommerce is not active.',
				array( 'status' => 501 )
			);
		}

		$plan = $this->plan_product( $native_id );
		if ( null === $plan ) {
			return array(
				'action' => 'skipped',
				'woo_id' => 0,
				'issues' => array(
					array(
						'nativeId' => $native_id,
						'message'  => 'Native product not found.',
					),
				),
			);
		}

		$fields = $plan['fields'];
		$issues = $plan['issues'];

		// Materialize the planned term creations BEFORE the product save —
		// the category/brand/color ids must be real at save time. Saving a
		// product with an empty category set makes WordPress assign the
		// default "Uncategorized" product_cat, so a deferred append would
		// leave the product in BOTH terms (the default and its real one).
		foreach ( $plan['terms_to_create'] as $term_spec ) {
			$created = wp_insert_term( $term_spec['name'], $term_spec['taxonomy'], array( 'slug' => $term_spec['slug'] ) );
			if ( is_wp_error( $created ) ) {
				$issues[] = array(
					'nativeId' => $native_id,
					'message'  => sprintf( 'Could not create term "%s" in %s: %s', $term_spec['name'], $term_spec['taxonomy'], $created->get_error_message() ),
				);
				continue;
			}
			if ( ! empty( $term_spec['hexCode'] ) ) {
				update_term_meta( (int) $created['term_id'], Schema::TERM_META_HEX_CODE, $term_spec['hexCode'] );
			}
		}

		// Second resolution pass — the planned terms now exist, so the ids
		// resolve and flow into the product setters below.
		$resolve_issues = array();
		$resolve_queue  = array();
		$fields         = $this->map_field_terms( $fields, $resolve_issues, $resolve_queue );
		foreach ( $resolve_issues as $issue ) {
			$issue['nativeId'] = $native_id;
			$issues[]          = $issue;
		}

		$product = $plan['woo_id'] > 0 ? wc_get_product( $plan['woo_id'] ) : null;
		if ( ! $product instanceof \WC_Product ) {
			$product = new \WC_Product_Simple();
		}

		$is_new = $product->get_id() <= 0;

		$product->set_name( $fields['name'] );
		$product->set_description( $fields['description'] );
		$product->set_status( 'publish' === $fields['status'] ? 'publish' : 'draft' );
		$product->set_sku( Schema::SKU_PREFIX_MIGRATED . $native_id );

		// Price mapping — identical to WooProductSource::apply_fields: the
		// contract price is the selling price, oldPrice the anchor.
		$price = (float) $fields['price'];
		$old   = (float) ( $fields['oldPrice'] ?? 0 );
		if ( $old > $price ) {
			$product->set_regular_price( (string) $old );
			$product->set_sale_price( (string) $price );
		} else {
			$product->set_regular_price( (string) $price );
			$product->set_sale_price( '' );
		}

		foreach ( array(
			'width'  => 'set_width',
			'height' => 'set_height',
			'depth'  => 'set_length',
		) as $field => $setter ) {
			if ( is_numeric( $fields[ $field ] ) ) {
				$product->{$setter}( (string) $fields[ $field ] );
			}
		}

		$product->set_featured( (bool) $fields['isFeatured'] );
		$product->set_image_id( $fields['image_id'] );
		$product->set_gallery_image_ids( $fields['gallery_ids'] );
		$product->set_category_ids( $fields['category_id'] > 0 ? array( $fields['category_id'] ) : array() );

		if ( $is_new ) {
			$product->set_reviews_allowed( true );
			$product->set_manage_stock( false );
			$product->set_stock_status( 'instock' );
			$product->set_catalog_visibility( 'visible' );
		}

		$promo_id = (int) $fields['promotionId'];
		if ( $promo_id > 0 ) {
			$product->update_meta_data( Schema::META_NVOOS_PROMOTION_ID, (string) $promo_id );
		} else {
			$product->delete_meta_data( Schema::META_NVOOS_PROMOTION_ID );
		}
		$product->update_meta_data( Schema::META_NVOOS_MIGRATED_FROM, (string) $native_id );

		$woo_id = $product->save();
		if ( ! $woo_id ) {
			$issues[] = array(
				'nativeId' => $native_id,
				'message'  => 'WooCommerce product save failed.',
			);
			return array(
				'action' => 'failed',
				'woo_id' => 0,
				'issues' => $issues,
			);
		}

		// Brand/color terms are non-default taxonomies — assignment after
		// save is safe and follows the WooProductSource write-path pattern.
		if ( $fields['brand_id'] > 0 ) {
			wp_set_object_terms( $woo_id, array( $fields['brand_id'] ), $fields['brand_tax'] );
		}
		if ( ! empty( $fields['color_ids'] ) ) {
			wp_set_object_terms( $woo_id, $fields['color_ids'], $fields['color_tax'] );
		}

		return array(
			'action' => $is_new ? 'created' : 'updated',
			'woo_id' => (int) $woo_id,
			'issues' => $issues,
		);
	}

	/**
	 * Enqueue the Action Scheduler sweep (the Settings-page "Run migration"
	 * path — the web request only queues work).
	 *
	 * @return bool True when queued or already running.
	 */
	public function enqueue_sweep(): bool {
		if ( ! $this->is_available() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		$state = $this->state();
		if ( 'running' === $state['status'] ) {
			return true; // Already sweeping — do not double-queue.
		}

		$this->update_state(
			array(
				'status'      => 'running',
				'started_at'  => current_time( 'mysql', true ),
				'finished_at' => '',
				'scanned'     => 0,
				'created'     => 0,
				'updated'     => 0,
				'failed'      => 0,
				'issues'      => array(),
			)
		);

		as_enqueue_async_action(
			Schema::HOOK_MIGRATE_SWEEP,
			array( 'offset' => 0 ),
			self::AS_GROUP
		);
		return true;
	}

	/**
	 * One sweep chunk (the AS callback): migrate a batch, advance the cursor.
	 *
	 * @param int $offset Products to skip.
	 * @return void
	 */
	public function handle_sweep( int $offset ): void {
		if ( ! $this->is_available() ) {
			$this->update_state( array( 'status' => 'failed' ) );
			return;
		}

		// Chunks may run without the enqueue entry point (manual Action
		// Scheduler runs) — mark the sweep running so the Settings page and
		// CLI show real progress.
		if ( 'running' !== $this->state()['status'] ) {
			$this->update_state(
				array(
					'status'     => 'running',
					'started_at' => current_time( 'mysql', true ),
				)
			);
		}

		$batch = $this->batch_size();
		$ids   = $this->native_ids( $offset, $batch );

		if ( empty( $ids ) ) {
			$this->update_state(
				array(
					'status'      => 'done',
					'finished_at' => current_time( 'mysql', true ),
				)
			);
			return;
		}

		// Woo bulk-save performance: term recount + cache invalidation are
		// the two dominant costs per save (woocommerce/woocommerce#27249).
		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$state = $this->state();
		foreach ( $ids as $id ) {
			$result = $this->migrate_product( (int) $id );
			if ( is_wp_error( $result ) ) {
				++$state['failed'];
				$state['issues'][] = array(
					'nativeId' => (int) $id,
					'message'  => $result->get_error_message(),
				);
				continue;
			}
			++$state['scanned'];
			if ( 'created' === $result['action'] ) {
				++$state['created'];
			} elseif ( 'updated' === $result['action'] ) {
				++$state['updated'];
			} else {
				++$state['failed'];
			}
			foreach ( $result['issues'] as $issue ) {
				$state['issues'][] = $issue;
			}
			// Keep the option bounded — retain the most recent issues only.
			if ( count( $state['issues'] ) > 50 ) {
				$state['issues'] = array_slice( $state['issues'], -50 );
			}
		}
		$this->update_state( $state );

		wp_defer_term_counting( false );
		wp_suspend_cache_invalidation( false );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				Schema::HOOK_MIGRATE_SWEEP,
				array( 'offset' => $offset + $batch ),
				self::AS_GROUP
			);
		}
	}

	/**
	 * The stored migration state (counters + status for the UI/CLI).
	 *
	 * @return array<string, mixed>
	 */
	public function state(): array {
		$state = get_option( Schema::OPTION_MIGRATION_STATE, array() );
		return is_array( $state ) ? array_merge( self::STATE_DEFAULTS, $state ) : self::STATE_DEFAULTS;
	}

	/**
	 * Persist (merge) the migration state.
	 *
	 * @param array<string, mixed> $patch State fields to update.
	 * @return void
	 */
	private function update_state( array $patch ): void {
		$merged = array_merge( $this->state(), $patch );
		update_option( Schema::OPTION_MIGRATION_STATE, $merged, false );
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/**
	 * Read every native field the migration maps into Woo.
	 *
	 * @param WP_Post $post Native product post.
	 * @return array<string, mixed>
	 */
	private function read_native_fields( WP_Post $post ): array {
		$category_terms = wp_get_post_terms( $post->ID, Schema::TAX_CATEGORY );
		$brand_terms    = wp_get_post_terms( $post->ID, Schema::TAX_BRAND );
		$color_terms    = wp_get_post_terms( $post->ID, Schema::TAX_COLOR );
		$gallery        = get_post_meta( $post->ID, Schema::META_GALLERY, true );

		return array(
			'name'        => $post->post_title,
			'description' => $post->post_content,
			'status'      => $post->post_status,
			'price'       => (float) get_post_meta( $post->ID, Schema::META_PRICE, true ),
			'oldPrice'    => $this->nullable_float( get_post_meta( $post->ID, Schema::META_OLD_PRICE, true ) ),
			'width'       => (float) get_post_meta( $post->ID, Schema::META_WIDTH, true ),
			'height'      => (float) get_post_meta( $post->ID, Schema::META_HEIGHT, true ),
			'depth'       => (float) get_post_meta( $post->ID, Schema::META_DEPTH, true ),
			'isFeatured'  => (bool) get_post_meta( $post->ID, Schema::META_IS_FEATURED, true ),
			'promotionId' => (int) get_post_meta( $post->ID, Schema::META_PROMOTION_ID, true ),
			'image_id'    => (int) get_post_thumbnail_id( $post->ID ),
			'gallery_ids' => array_values( array_filter( array_map( 'intval', (array) $gallery ) ) ),
			'category'    => is_array( $category_terms ) && ! empty( $category_terms ) ? $category_terms[0] : null,
			'brand'       => is_array( $brand_terms ) && ! empty( $brand_terms ) ? $brand_terms[0] : null,
			'colors'      => is_array( $color_terms ) ? $color_terms : array(),
		);
	}

	/**
	 * Find the Woo product a native product already migrated into.
	 *
	 * @param int $native_id Native product id.
	 * @return int Woo product id, 0 when not yet migrated.
	 */
	private function find_woo_id( int $native_id ): int {
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = (int) wc_get_product_id_by_sku( Schema::SKU_PREFIX_MIGRATED . $native_id );
			if ( $by_sku > 0 ) {
				return $by_sku;
			}
		}

		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => Schema::META_NVOOS_MIGRATED_FROM, // phpcs:ignore WordPress.DB.SlowDBQuery -- Exact-value lookup by the migration marker.
				'meta_value'     => (string) $native_id, // phpcs:ignore WordPress.DB.SlowDBQuery -- Exact-value lookup by the migration marker.
			)
		);
		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Resolve a source term into the target taxonomy by slug.
	 *
	 * When $create is false (dry-run) a missing term is recorded as an issue
	 * and queued in $terms_to_create; when true the caller materializes the
	 * queued creations afterwards (see migrate_product).
	 *
	 * @param \WP_Term                         $term       Source term.
	 * @param string                           $taxonomy   Target taxonomy.
	 * @param bool                             $create     Whether to create missing terms.
	 * @param array<int, array<string, mixed>> $issues     Issue sink (by reference).
	 * @param array<int, array<string, mixed>> $to_create  Term-creation queue (by reference).
	 * @return int Target term id, 0 when unmapped.
	 */
	private function resolve_term( \WP_Term $term, string $taxonomy, bool $create, array &$issues, array &$to_create ): int {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			$issues[] = array(
				'message' => sprintf( 'Term "%s" unmapped — taxonomy %s is not registered.', $term->name, $taxonomy ),
			);
			return 0;
		}

		// The native fallback taxonomies ARE the native taxonomies — the
		// term id is already valid there, no mapping needed.
		if ( Schema::TAX_CATEGORY === $taxonomy || Schema::TAX_BRAND === $taxonomy || Schema::TAX_COLOR === $taxonomy ) {
			return (int) $term->term_id;
		}

		$exists = term_exists( $term->slug, $taxonomy );
		if ( $exists ) {
			return is_array( $exists ) ? (int) $exists['term_id'] : (int) $exists;
		}

		$to_create[] = array(
			'name'     => $term->name,
			'slug'     => $term->slug,
			'taxonomy' => $taxonomy,
			'hexCode'  => Schema::ATTR_WOO_COLOR === $taxonomy ? (string) get_term_meta( $term->term_id, Schema::TERM_META_HEX_CODE, true ) : '',
		);

		if ( ! $create ) {
			$issues[] = array(
				'message' => sprintf( 'Term "%s" will be created in %s.', $term->name, $taxonomy ),
			);
		}

		return 0;
	}

	/**
	 * The typed unavailable report.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable_report(): array {
		return array(
			'total'         => 0,
			'scanned'       => 0,
			'create'        => 0,
			'update'        => 0,
			'failed'        => 0,
			'terms_created' => 0,
			'issues'        => array(
				array(
					'nativeId' => 0,
					'message'  => 'WooCommerce is not active.',
				),
			),
		);
	}

	/**
	 * Cast a raw meta value to float, returning null for empty values.
	 *
	 * @param mixed $value Raw meta value.
	 * @return float|null
	 */
	private function nullable_float( $value ): ?float {
		return ( '' !== $value && null !== $value ) ? (float) $value : null;
	}
}
