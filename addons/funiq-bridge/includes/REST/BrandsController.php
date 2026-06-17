<?php
/**
 * Brands REST controller.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use FuniqBridge\Schema;

/**
 * Handles /funiq/v1/brands.
 */
class BrandsController extends TermController {

	/** @var string */
	protected $namespace = 'funiq/v1';

	/** @var string */
	protected $rest_base = 'brands';

	/**
	 * @return string
	 */
	protected function taxonomy(): string {
		return Schema::TAX_BRAND;
	}
}
