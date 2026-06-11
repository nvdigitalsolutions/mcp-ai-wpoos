<?php
/**
 * Product CPT registration.
 *
 * @package FuniqBridge\PostTypes
 */

namespace FuniqBridge\PostTypes;

use FuniqBridge\Schema;

/**
 * Registers the funiq_product custom post type.
 */
class Product {

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			Schema::CPT_PRODUCT,
			array(
				'labels'        => array(
					'name'          => 'Funiq Products',
					'singular_name' => 'Funiq Product',
					'add_new'       => 'Add Product',
					'add_new_item'  => 'Add New Product',
					'edit_item'     => 'Edit Product',
					'view_item'     => 'View Product',
				),
				'public'        => false,
				'show_ui'       => false,       // Hidden from default WP admin; managed via React SPA.
				'show_in_rest'  => true,         // Native REST support as fallback.
				'supports'      => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'has_archive'   => false,
				'capability_type' => 'post',
				'rewrite'       => false,
			)
		);
	}
}
