<?php
/**
 * AI Template Custom Post Type.
 *
 * Registers `ai_platform_template` — a post type for reusable
 * templates (agent blueprints, workflow templates, prompt packs)
 * under the "NV Platform" admin menu.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\PostTypes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\PostTypes;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;

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
			'name'               => _x( 'Templates', 'post type general name', 'nvoos-content-graph-ai-platform' ),
			'singular_name'      => _x( 'Template', 'post type singular name', 'nvoos-content-graph-ai-platform' ),
			'menu_name'          => __( 'Templates', 'nvoos-content-graph-ai-platform' ),
			'add_new'            => __( 'Add Template', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'       => __( 'Add New Template', 'nvoos-content-graph-ai-platform' ),
			'edit_item'          => __( 'Edit Template', 'nvoos-content-graph-ai-platform' ),
			'new_item'           => __( 'New Template', 'nvoos-content-graph-ai-platform' ),
			'view_item'          => __( 'View Template', 'nvoos-content-graph-ai-platform' ),
			'search_items'       => __( 'Search Templates', 'nvoos-content-graph-ai-platform' ),
			'not_found'          => __( 'No templates found.', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash' => __( 'No templates found in Trash.', 'nvoos-content-graph-ai-platform' ),
			'all_items'          => __( 'All Templates', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Reusable templates — agent blueprints, workflow templates, and prompt packs.', 'nvoos-content-graph-ai-platform' ),
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
