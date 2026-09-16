<?php
/**
 * Shopify Product Normalizers Trait — shared Catalog API / UCP product normalization (ecosystem port — Wave F2, e-commerce data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/trait-wp-mcp-ai-shopify-product-normalizers.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro` (no translatable strings in the body); no
 * path constants in the file, so no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.82
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared normalizers for Shopify Catalog API and UCP catalog products.
 *
 * The normalized shapes are compatible with both the WP_MCP_AI_Tool_Product_Card
 * card renderer (title + images[] with url keys) and the TMA template JS
 * renderer (raw media/pricerange preserved). Used by shopify_products and
 * shopify_catalog so catalog results render the same image-bearing cards in
 * every connection mode.
 *
 * @since 1.1.82
 */
trait WP_MCP_AI_Shopify_Product_Normalizers {

	/**
	 * Normalize a UCP catalog product into the tool's canonical shape.
	 *
	 * UCP products carry camelCase keys with price ranges and variant
	 * prices in minor units; the output mirrors the Admin/Catalog API
	 * normalizers so chat cards and TMA renderers work unchanged.
	 *
	 * @param array $item Raw UCP product object.
	 * @return array Normalized product array.
	 */
	protected function normalize_ucp_product( array $item ) {
		$title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';

		$images = array();
		if ( isset( $item['media'] ) && is_array( $item['media'] ) ) {
			foreach ( $item['media'] as $media ) {
				$url = isset( $media['url'] ) ? $media['url'] : '';
				if ( $url ) {
					$images[] = array( 'url' => $url );
				}
			}
		}

		$price_range = array();
		if ( isset( $item['price_range'] ) && is_array( $item['price_range'] ) ) {
			if ( isset( $item['price_range']['min'] ) && is_array( $item['price_range']['min'] ) ) {
				$price_range['minVariantPrice'] = array(
					'amount'       => isset( $item['price_range']['min']['amount'] ) ? ( (float) $item['price_range']['min']['amount'] / 100 ) : 0,
					'currencyCode' => isset( $item['price_range']['min']['currency'] ) ? $item['price_range']['min']['currency'] : 'USD',
				);
			}
			if ( isset( $item['price_range']['max'] ) && is_array( $item['price_range']['max'] ) ) {
				$price_range['maxVariantPrice'] = array(
					'amount'       => isset( $item['price_range']['max']['amount'] ) ? ( (float) $item['price_range']['max']['amount'] / 100 ) : 0,
					'currencyCode' => isset( $item['price_range']['max']['currency'] ) ? $item['price_range']['max']['currency'] : 'USD',
				);
			}
		}

		$variants = array();
		if ( isset( $item['variants'] ) && is_array( $item['variants'] ) ) {
			foreach ( $item['variants'] as $variant ) {
				$variants[] = array(
					'id'           => isset( $variant['id'] ) ? $variant['id'] : '',
					'title'        => isset( $variant['title'] ) ? $variant['title'] : '',
					'price'        => isset( $variant['price'], $variant['price']['amount'] ) ? ( (float) $variant['price']['amount'] / 100 ) : 0,
					'currency'     => isset( $variant['price'], $variant['price']['currency'] ) ? $variant['price']['currency'] : 'USD',
					'available'    => isset( $variant['availability'], $variant['availability']['available'] ) ? (bool) $variant['availability']['available'] : true,
					'checkout_url' => isset( $variant['checkout_url'] ) ? $variant['checkout_url'] : '',
					'seller'       => isset( $variant['seller'], $variant['seller']['domain'] ) ? $variant['seller']['domain'] : '',
					'sku'          => isset( $variant['sku'] ) ? $variant['sku'] : '',
				);
			}
		}

		$description = '';
		if ( isset( $item['description'] ) && is_array( $item['description'] ) ) {
			$description = isset( $item['description']['plain'] )
				? $item['description']['plain']
				: ( isset( $item['description']['html'] ) ? wp_strip_all_tags( $item['description']['html'] ) : '' );
		}

		return array(
			'id'               => isset( $item['id'] ) ? sanitize_text_field( $item['id'] ) : '',
			'title'            => $title,
			'handle'           => '',
			'status'           => 'ACTIVE',
			'vendor'           => '',
			'product_type'     => '',
			'tags'             => isset( $item['tags'] ) ? $item['tags'] : array(),
			'created_at'       => '',
			'updated_at'       => '',
			'price_range'      => $price_range,
			'total_inventory'  => 0,
			'variants'         => $variants,
			'images'           => $images,
			'availableforsale' => true,
			'lookupurl'        => isset( $item['url'] ) ? $item['url'] : '',
			'displayname'      => $title,
			'description'      => $description,
			'media'            => isset( $item['media'] ) ? $item['media'] : array(),
			'pricerange'       => isset( $item['price_range'] ) ? $item['price_range'] : array(),
		);
	}

	/**
	 * Normalize a product from the Catalog API response.
	 *
	 * Catalog API field names are all lowercase (displayname, pricerange,
	 * lookupurl, availableforsale, etc.) and prices are in minor units
	 * (cents).  This method maps them to a structure compatible with both
	 * the Admin API normalizer output and the TMA template JS renderer.
	 *
	 * @param array $item Raw Catalog API product object.
	 * @return array Normalized product array.
	 */
	protected function normalize_catalog_product( array $item ) {
		$title = '';
		if ( isset( $item['displayname'] ) ) {
			$title = $item['displayname'];
		} elseif ( isset( $item['title'] ) ) {
			$title = $item['title'];
		}

		// Images / media — Catalog API uses a "media" array.
		$images = array();
		if ( isset( $item['media'] ) && is_array( $item['media'] ) ) {
			foreach ( $item['media'] as $media ) {
				$url = isset( $media['url'] ) ? $media['url'] : ( isset( $media['src'] ) ? $media['src'] : '' );
				if ( $url ) {
					$images[] = array( 'url' => $url );
				}
			}
		}

		// Price range — Catalog API uses lowercase keys; amounts in minor units (cents).
		$price_range = array();
		if ( isset( $item['pricerange'] ) && is_array( $item['pricerange'] ) ) {
			$pr = $item['pricerange'];
			if ( isset( $pr['minvariantprice'] ) && is_array( $pr['minvariantprice'] ) ) {
				$min                            = $pr['minvariantprice'];
				$price_range['minVariantPrice'] = array(
					'amount'       => isset( $min['amount'] ) ? ( (float) $min['amount'] / 100 ) : 0,
					'currencyCode' => isset( $min['currencycode'] ) ? $min['currencycode'] : 'USD',
				);
			}
			if ( isset( $pr['maxvariantprice'] ) && is_array( $pr['maxvariantprice'] ) ) {
				$max                            = $pr['maxvariantprice'];
				$price_range['maxVariantPrice'] = array(
					'amount'       => isset( $max['amount'] ) ? ( (float) $max['amount'] / 100 ) : 0,
					'currencyCode' => isset( $max['currencycode'] ) ? $max['currencycode'] : 'USD',
				);
			}
		}

		return array(
			'id'               => isset( $item['upid'] ) ? $item['upid'] : ( isset( $item['id'] ) ? $item['id'] : '' ),
			'title'            => $title,
			'handle'           => isset( $item['handle'] ) ? $item['handle'] : '',
			'status'           => isset( $item['availableforsale'] ) && $item['availableforsale'] ? 'ACTIVE' : 'UNAVAILABLE',
			'vendor'           => isset( $item['vendor'] ) ? $item['vendor'] : '',
			'product_type'     => isset( $item['producttype'] ) ? $item['producttype'] : ( isset( $item['product_type'] ) ? $item['product_type'] : '' ),
			'tags'             => isset( $item['tags'] ) ? $item['tags'] : array(),
			'created_at'       => '',
			'updated_at'       => '',
			'price_range'      => $price_range,
			'total_inventory'  => 0,
			'variants'         => array(),
			'images'           => $images,
			'availableforsale' => isset( $item['availableforsale'] ) ? $item['availableforsale'] : true,
			'lookupurl'        => isset( $item['lookupurl'] ) ? $item['lookupurl'] : '',
			'displayname'      => $title,
			// Preserve the raw media/pricerange for the TMA JS renderer.
			'media'            => isset( $item['media'] ) ? $item['media'] : array(),
			'pricerange'       => isset( $item['pricerange'] ) ? $item['pricerange'] : array(),
		);
	}
}
