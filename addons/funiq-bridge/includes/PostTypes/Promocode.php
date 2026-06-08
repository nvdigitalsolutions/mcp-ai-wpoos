<?php
/**
 * Promocode CPT registration.
 *
 * @package FuniqBridge\PostTypes
 */

namespace FuniqBridge\PostTypes;

use FuniqBridge\Schema;

/**
 * Registers the funiq_promocode custom post type.
 */
class Promocode {

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			Schema::CPT_PROMOCODE,
			array(
				'labels'        => array(
					'name'          => 'Funiq Promocodes',
					'singular_name' => 'Funiq Promocode',
					'add_new'       => 'Add Promocode',
					'add_new_item'  => 'Add New Promocode',
					'edit_item'     => 'Edit Promocode',
					'view_item'     => 'View Promocode',
				),
				'public'        => false,
				'show_ui'       => false,
				'show_in_rest'  => true,
				'supports'      => array( 'title', 'revisions' ),
				'has_archive'   => false,
				'capability_type' => 'post',
				'rewrite'       => false,
			)
		);
	}
}
