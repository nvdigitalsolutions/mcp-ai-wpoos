<?php
/**
 * AI Template Custom Post Type.
 *
 * Registers `ai_platform_template` — a post type for reusable
 * templates (agent blueprints, workflow templates, prompt packs)
 * under the "NV Platform" admin menu.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\PostTypes
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\PostTypes;

use NvoosGraphifyAiPlatform\Admin\PlatformDashboard;

/**
 * Template CPT registration.
 */
final class TemplateCpt {

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POST_TYPE = 'ai_platform_template';

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerPostType' ) );
	}

	/**
	 * Register the post type on init.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerPostType(): void {
		$labels = array(
			'name'               => _x( 'Templates', 'post type general name', 'nvoos-graphify-ai-platform' ),
			'singular_name'      => _x( 'Template', 'post type singular name', 'nvoos-graphify-ai-platform' ),
			'menu_name'          => __( 'Templates', 'nvoos-graphify-ai-platform' ),
			'add_new'            => __( 'Add Template', 'nvoos-graphify-ai-platform' ),
			'add_new_item'       => __( 'Add New Template', 'nvoos-graphify-ai-platform' ),
			'edit_item'          => __( 'Edit Template', 'nvoos-graphify-ai-platform' ),
			'new_item'           => __( 'New Template', 'nvoos-graphify-ai-platform' ),
			'view_item'          => __( 'View Template', 'nvoos-graphify-ai-platform' ),
			'search_items'       => __( 'Search Templates', 'nvoos-graphify-ai-platform' ),
			'not_found'          => __( 'No templates found.', 'nvoos-graphify-ai-platform' ),
			'not_found_in_trash' => __( 'No templates found in Trash.', 'nvoos-graphify-ai-platform' ),
			'all_items'          => __( 'All Templates', 'nvoos-graphify-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Reusable templates — agent blueprints, workflow templates, and prompt packs.', 'nvoos-graphify-ai-platform' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => PlatformDashboard::PAGE_SLUG,
			'show_in_rest'       => true,
			'rest_base'          => 'ai-platform-templates',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'ai-template' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-editor-table',
			'supports'           => array(
				'title',
				'editor',
				'thumbnail',
				'custom-fields',
				'revisions',
			),
			'show_in_nav_menus'  => false,
			'delete_with_user'   => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}
}
