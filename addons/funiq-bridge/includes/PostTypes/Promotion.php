<?php
/**
 * Promotion CPT registration.
 *
 * @package FuniqBridge\PostTypes
 */

namespace FuniqBridge\PostTypes;

use FuniqBridge\Schema;

/**
 * Registers the funiq_promotion custom post type.
 */
class Promotion {

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			Schema::CPT_PROMOTION,
			array(
				'labels'        => array(
					'name'          => 'Funiq Promotions',
					'singular_name' => 'Funiq Promotion',
					'add_new'       => 'Add Promotion',
					'add_new_item'  => 'Add New Promotion',
					'edit_item'     => 'Edit Promotion',
					'view_item'     => 'View Promotion',
				),
				'public'        => false,
				'show_ui'       => false,
				'show_in_rest'  => true,
				'supports'      => array( 'title', 'editor', 'revisions' ),
				'has_archive'   => false,
				'capability_type' => 'post',
				'rewrite'       => false,
			)
		);
	}
}
