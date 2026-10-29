<?php
/**
 * Brands REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Sources\WooTaxonomy;

/**
 * Handles /funiq/v1/brands.
 */
class BrandsController extends TermController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'brands';

	/**
	 * Get the taxonomy slug.
	 *
	 * Woo-mode serves the pa_brand attribute (or product_brand) when the
	 * store defines one; the built-in taxonomy otherwise.
	 *
	 * @return string
	 */
	protected function taxonomy(): string {
		return WooTaxonomy::brand_taxonomy();
	}
}
