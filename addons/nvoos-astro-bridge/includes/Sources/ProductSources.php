<?php
/**
 * Product source registry.
 *
 * Resolves which backing stores serve the /products collection. Modes
 * (option `funiq_bridge_products_source`, filterable via
 * `funiq_bridge_products_source`):
 *
 *   - `native` (default) — the built-in CPTs only (pre-existing behaviour).
 *   - `woo`      — WooCommerce products only.
 *   - `both`     — merged (native first, then WooCommerce).
 *
 * A `woo`/`both` mode degrades to `native` when WooCommerce is not active,
 * so the contract never breaks on stores that deactivate Woo.
 *
 * IDs are globally unique across both sources by construction (posts and
 * terms share the same wp_posts/wp_terms ID sequences), so merged listings
 * and single-document routing need no ID namespacing.
 *
 * @package NvoosAstroBridge\Sources
 */

namespace NvoosAstroBridge\Sources;

use NvoosAstroBridge\Schema;

/**
 * Active-source resolution for product collections.
 */
final class ProductSources {

	/**
	 * Instances, keyed by source name.
	 *
	 * @var array<string, ProductSource>|null
	 */
	private static ?array $sources = null;

	/**
	 * Resolve the configured mode (option + filter, validated).
	 *
	 * @return string native|woo|both.
	 */
	public static function mode(): string {
		$option = get_option( Schema::OPTION_PRODUCT_SOURCE, 'native' );
		$mode   = (string) apply_filters( 'funiq_bridge_products_source', $option );

		if ( ! in_array( $mode, array( 'native', 'woo', 'both' ), true ) ) {
			$mode = 'native';
		}
		return $mode;
	}

	/**
	 * True when WooCommerce participates in the active mode.
	 *
	 * @return bool
	 */
	public static function is_woo_active_mode(): bool {
		return WooTaxonomy::is_woo_available() && 'native' !== self::mode();
	}

	/**
	 * The source instances (cached per request).
	 *
	 * @return array<string, ProductSource>
	 */
	private static function instances(): array {
		if ( null === self::$sources ) {
			self::$sources = array(
				Schema::SOURCE_NATIVE => new NativeProductSource(),
				Schema::SOURCE_WOO    => new WooProductSource(),
			);
		}
		return self::$sources;
	}

	/**
	 * Sources serving the products collection, native-first.
	 *
	 * `native` mode → native only. `woo` mode → Woo only (degrading to
	 * native when WooCommerce is missing). `both` → native then Woo.
	 *
	 * @return array<int, ProductSource>
	 */
	public static function active(): array {
		$instances = self::instances();
		$active    = array();

		if ( 'woo' !== self::mode() ) {
			$active[] = $instances[ Schema::SOURCE_NATIVE ];
		}

		if ( self::is_woo_active_mode() ) {
			$active[] = $instances[ Schema::SOURCE_WOO ];
		}

		// Woo mode with WooCommerce missing degrades to native.
		if ( empty( $active ) ) {
			$active[] = $instances[ Schema::SOURCE_NATIVE ];
		}

		return $active;
	}

	/**
	 * The source create operations default to (first active).
	 *
	 * @return ProductSource
	 */
	public static function primary(): ProductSource {
		$active = self::active();
		return $active[0];
	}

	/**
	 * Resolve the source that owns a product ID, or null when inactive/missing.
	 *
	 * @param int $id Product ID.
	 * @return ProductSource|null
	 */
	public static function resolve( int $id ): ?ProductSource {
		$instances = self::instances();

		foreach ( self::active() as $source ) {
			if ( Schema::SOURCE_WOO === $source->name() ) {
				if ( 'product' === get_post_type( $id ) ) {
					return $instances[ Schema::SOURCE_WOO ];
				}
			} elseif ( Schema::CPT_PRODUCT === get_post_type( $id ) ) {
				return $instances[ Schema::SOURCE_NATIVE ];
			}
		}

		return null;
	}

	/**
	 * Resolve a source by its document `source` name (create routing).
	 *
	 * @param string|null $name Source name (native|woocommerce) or null.
	 * @return ProductSource|null Null when the name is invalid/inactive.
	 */
	public static function from_name( ?string $name ): ?ProductSource {
		if ( null === $name || '' === $name ) {
			return self::primary();
		}

		$instances = self::instances();
		foreach ( self::active() as $source ) {
			if ( $source->name() === $name ) {
				return $instances[ $name ];
			}
		}
		return null;
	}
}
