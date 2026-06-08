<?php
/**
 * Color taxonomy registration.
 *
 * @package FuniqBridge\Taxonomies
 */

namespace FuniqBridge\Taxonomies;

use FuniqBridge\Schema;

/**
 * Registers the funiq_color taxonomy.
 */
class Color {

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function register(): void {
		register_taxonomy(
			Schema::TAX_COLOR,
			Schema::CPT_PRODUCT,
			array(
				'labels'       => array(
					'name'          => 'Colors',
					'singular_name' => 'Color',
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
