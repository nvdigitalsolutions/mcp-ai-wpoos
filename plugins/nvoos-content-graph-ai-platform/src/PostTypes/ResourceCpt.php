<?php
/**
 * AI Resource Custom Post Type.
 *
 * Registers `ai_platform_resource` — a post type for platform
 * resources (datasets, models, API connections, etc.) under the
 * "NV Platform" admin menu.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\PostTypes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\PostTypes;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;

/**
 * Resource CPT registration.
 */
final class ResourceCpt {

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POST_TYPE = 'ai_platform_resource';

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
			'name'               => _x( 'Resources', 'post type general name', 'nvoos-content-graph-ai-platform' ),
			'singular_name'      => _x( 'Resource', 'post type singular name', 'nvoos-content-graph-ai-platform' ),
			'menu_name'          => __( 'Resources', 'nvoos-content-graph-ai-platform' ),
			'add_new'            => __( 'Add Resource', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'       => __( 'Add New Resource', 'nvoos-content-graph-ai-platform' ),
			'edit_item'          => __( 'Edit Resource', 'nvoos-content-graph-ai-platform' ),
			'new_item'           => __( 'New Resource', 'nvoos-content-graph-ai-platform' ),
			'view_item'          => __( 'View Resource', 'nvoos-content-graph-ai-platform' ),
			'search_items'       => __( 'Search Resources', 'nvoos-content-graph-ai-platform' ),
			'not_found'          => __( 'No resources found.', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash' => __( 'No resources found in Trash.', 'nvoos-content-graph-ai-platform' ),
			'all_items'          => __( 'All Resources', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Platform resources — datasets, models, API connections, and tool configurations.', 'nvoos-content-graph-ai-platform' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => PlatformDashboard::PAGE_SLUG,
			'show_in_rest'       => true,
			'rest_base'          => 'ai-platform-resources',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'ai-resource' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-database',
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
