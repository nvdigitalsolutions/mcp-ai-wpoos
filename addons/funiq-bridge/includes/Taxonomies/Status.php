<?php
/**
 * Status taxonomy registration.
 *
 * @package FuniqBridge\Taxonomies
 */

namespace FuniqBridge\Taxonomies;

use FuniqBridge\Schema;

/**
 * Registers the funiq_status taxonomy.
 */
class Status {

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function register(): void {
		register_taxonomy(
			Schema::TAX_STATUS,
			Schema::CPT_PRODUCT,
			array(
				'labels'       => array(
					'name'          => 'Statuses',
					'singular_name' => 'Status',
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
