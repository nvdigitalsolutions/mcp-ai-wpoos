<?php
/**
 * Local (CMS-authored) content CPT registration — Proposal 135 §D4.
 *
 * A managed forged site needs somewhere to write content that never existed in
 * the source WordPress site (a new landing page, a case study, a legal page
 * update). Proposal 135 decided that store is this CPT behind the bridge,
 * rather than a second Git-based content store: managed sites already carry
 * `/manage`, so a Keystatic-style store would duplicate the editing surface,
 * the auth model and the audit trail.
 *
 * The CPT is invisible to wp/v2 on purpose — the bridge contract
 * (`/nvoos-commerce/v1/local`) is the only consumer, which keeps one document
 * shape, one capability gate (`manage_astro`) and one cache-tag vocabulary.
 *
 * @package NvoosAstroBridge\PostTypes
 */

namespace NvoosAstroBridge\PostTypes;

use NvoosAstroBridge\Schema;

/**
 * Registers the nvoos_forge_local custom post type.
 */
class ForgeLocal {

	/**
	 * Register the CPT.
	 *
	 * `capability_type => 'post'` keeps WordPress' own map (edit_posts /
	 * publish_posts), which the controller layers on top of `manage_astro`:
	 * a contributor can draft, only a publisher can take a doc live.
	 *
	 * @return void
	 */
	public function register(): void {
		register_post_type(
			Schema::CPT_FORGE_LOCAL,
			array(
				'labels'              => array(
					'name'          => 'Local Content',
					'singular_name' => 'Local Content Item',
					'add_new'       => 'Add Item',
					'add_new_item'  => 'Add New Local Content',
					'edit_item'     => 'Edit Local Content',
					'view_item'     => 'View Local Content',
				),
				'public'              => false,
				// Hidden from wp-admin: the Astro CMS SPA is the editing surface,
				// and a second UI would fork the validation rules.
				'show_ui'             => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor', 'excerpt', 'revisions' ),
				'has_archive'         => false,
				'capability_type'     => 'post',
				'rewrite'             => false,
				'query_var'           => false,
				'exclude_from_search' => true,
			)
		);
	}
}
