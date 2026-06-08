<?php
/**
 * Category taxonomy registration.
 *
 * @package FuniqBridge\Taxonomies
 */

namespace FuniqBridge\Taxonomies;

use FuniqBridge\Schema;

/**
 * Registers the funiq_category taxonomy.
 */
class Category {

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function register(): void {
		register_taxonomy(
			Schema::TAX_CATEGORY,
			Schema::CPT_PRODUCT,
			array(
				'labels'       => array(
					'name'          => 'Categories',
					'singular_name' => 'Category',
				),
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => true,
				'hierarchical' => true,
				'rewrite'      => false,
			)
		);
	}
}
