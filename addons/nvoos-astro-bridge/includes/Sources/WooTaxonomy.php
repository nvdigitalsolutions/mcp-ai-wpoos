<?php
/**
 * WooCommerce mapping helpers for the contract collections.
 *
 * Resolves which Woo taxonomies/attributes back the brand/color/category
 * collections and the stock-status pseudo-collection, so the REST
 * controllers stay thin.
 *
 * @package NvoosAstroBridge\Sources
 */

namespace NvoosAstroBridge\Sources;

use NvoosAstroBridge\Schema;

/**
 * WooCommerce-backed collection mapping.
 */
final class WooTaxonomy {

	/**
	 * Whether WooCommerce is really loaded in this environment.
	 *
	 * The function check matters: the repo's test bootstrap defines a stub
	 * `WooCommerce` class when the plugin is absent, so class_exists alone
	 * is a false positive — the CRUD API functions only exist in the real
	 * plugin.
	 *
	 * @return bool
	 */
	public static function is_woo_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * The taxonomy backing the categories collection in woo mode.
	 *
	 * @return string
	 */
	public static function category_taxonomy(): string {
		return ProductSources::is_woo_active_mode() ? Schema::TAX_WOO_CATEGORY : Schema::TAX_CATEGORY;
	}

	/**
	 * The taxonomy backing the brands collection in woo mode.
	 *
	 * Prefers the global `pa_brand` attribute, then the common
	 * `product_brand` taxonomy, then the native bridge taxonomy so the CMS
	 * keeps working on stores that define neither.
	 *
	 * @return string
	 */
	public static function brand_taxonomy(): string {
		if ( ! ProductSources::is_woo_active_mode() ) {
			return Schema::TAX_BRAND;
		}
		if ( taxonomy_exists( Schema::ATTR_WOO_BRAND ) ) {
			return Schema::ATTR_WOO_BRAND;
		}
		if ( taxonomy_exists( 'product_brand' ) ) {
			return 'product_brand';
		}
		return Schema::TAX_BRAND;
	}

	/**
	 * The taxonomy backing the colors collection in woo mode.
	 *
	 * Prefers the global `pa_color` attribute; falls back to the native
	 * bridge taxonomy when the store defines no color attribute.
	 *
	 * @return string
	 */
	public static function color_taxonomy(): string {
		if ( ! ProductSources::is_woo_active_mode() ) {
			return Schema::TAX_COLOR;
		}
		if ( taxonomy_exists( Schema::ATTR_WOO_COLOR ) ) {
			return Schema::ATTR_WOO_COLOR;
		}
		return Schema::TAX_COLOR;
	}

	/**
	 * First term of a taxonomy for a Woo product, as { id, name }.
	 *
	 * @param int    $product_id Woo product ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return array{id: int, name: string}|null
	 */
	public static function first_term( int $product_id, string $taxonomy ): ?array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}
		return array(
			'id'   => (int) $terms[0]->term_id,
			'name' => $terms[0]->name,
		);
	}

	/**
	 * Terms of a taxonomy as { id, name, hexCode } for color documents.
	 *
	 * @param int    $product_id Woo product ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return list<array{id: int, name: string, hexCode: string}>
	 */
	public static function color_terms( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( \WP_Term $t ): array {
				return array(
					'id'      => (int) $t->term_id,
					'name'    => $t->name,
					'hexCode' => get_term_meta( $t->term_id, Schema::TERM_META_HEX_CODE, true ) ?: '',
				);
			},
			$terms
		);
	}

	/**
	 * The stock-status pseudo-collection backing the statuses collection.
	 *
	 * WooCommerce has no status taxonomy — stock statuses are enumerations.
	 * The pseudo-IDs are deterministic so relationship payloads stay stable.
	 *
	 * @return list<array{id: int, name: string}>
	 */
	public static function stock_status_docs(): array {
		return array(
			array(
				'id'   => 1,
				'name' => 'In stock',
			),
			array(
				'id'   => 2,
				'name' => 'Out of stock',
			),
			array(
				'id'   => 3,
				'name' => 'On backorder',
			),
		);
	}

	/**
	 * The pseudo status doc for a Woo stock-status string.
	 *
	 * @param string $stock_status Woo stock status (instock/outofstock/onbackorder).
	 * @return array{id: int, name: string}|null
	 */
	public static function stock_status_doc( string $stock_status ): ?array {
		foreach ( self::stock_status_docs() as $doc ) {
			$map = array(
				'instock'     => 1,
				'outofstock'  => 2,
				'onbackorder' => 3,
			);
			if ( isset( $map[ $stock_status ] ) && $doc['id'] === $map[ $stock_status ] ) {
				return $doc;
			}
		}
		return null;
	}
}
