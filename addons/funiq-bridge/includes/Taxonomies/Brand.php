<?php
/**
 * Brand taxonomy registration.
 *
 * @package FuniqBridge\Taxonomies
 */

namespace FuniqBridge\Taxonomies;

use FuniqBridge\Schema;

/**
 * Registers the funiq_brand taxonomy.
 */
class Brand {

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function register(): void {
		register_taxonomy(
			Schema::TAX_BRAND,
			Schema::CPT_PRODUCT,
			array(
				'labels'       => array(
					'name'          => 'Brands',
					'singular_name' => 'Brand',
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite'      => false,
			)
		);
	}
}
