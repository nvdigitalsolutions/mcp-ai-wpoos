<?php
/**
 * AI Project Custom Post Type.
 *
 * Registers `ai_platform_project` — a post type for AI platform
 * projects that appears under the "NV Platform" admin menu.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\PostTypes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\PostTypes;

use NvoosContentGraphAiPlatform\Admin\PlatformDashboard;

/**
 * Project CPT registration and meta management.
 */
final class ProjectCpt {

	/**
	 * Post type slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const POST_TYPE = 'ai_platform_project';

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
			'name'                  => _x( 'AI Projects', 'post type general name', 'nvoos-content-graph-ai-platform' ),
			'singular_name'         => _x( 'AI Project', 'post type singular name', 'nvoos-content-graph-ai-platform' ),
			'menu_name'             => __( 'AI Projects', 'nvoos-content-graph-ai-platform' ),
			'add_new'               => __( 'Add New', 'nvoos-content-graph-ai-platform' ),
			'add_new_item'          => __( 'Add New AI Project', 'nvoos-content-graph-ai-platform' ),
			'edit_item'             => __( 'Edit AI Project', 'nvoos-content-graph-ai-platform' ),
			'new_item'              => __( 'New AI Project', 'nvoos-content-graph-ai-platform' ),
			'view_item'             => __( 'View AI Project', 'nvoos-content-graph-ai-platform' ),
			'search_items'          => __( 'Search AI Projects', 'nvoos-content-graph-ai-platform' ),
			'not_found'             => __( 'No AI projects found.', 'nvoos-content-graph-ai-platform' ),
			'not_found_in_trash'    => __( 'No AI projects found in Trash.', 'nvoos-content-graph-ai-platform' ),
			'all_items'             => __( 'All AI Projects', 'nvoos-content-graph-ai-platform' ),
			'archives'              => __( 'AI Project Archives', 'nvoos-content-graph-ai-platform' ),
			'attributes'            => __( 'AI Project Attributes', 'nvoos-content-graph-ai-platform' ),
			'insert_into_item'      => __( 'Insert into AI project', 'nvoos-content-graph-ai-platform' ),
			'uploaded_to_this_item' => __( 'Uploaded to this AI project', 'nvoos-content-graph-ai-platform' ),
			'filter_items_list'     => __( 'Filter AI projects list', 'nvoos-content-graph-ai-platform' ),
			'items_list_navigation' => __( 'AI Projects list navigation', 'nvoos-content-graph-ai-platform' ),
			'items_list'            => __( 'AI Projects list', 'nvoos-content-graph-ai-platform' ),
			'item_published'        => __( 'AI Project published.', 'nvoos-content-graph-ai-platform' ),
			'item_updated'          => __( 'AI Project updated.', 'nvoos-content-graph-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'AI platform projects — containers for agents, resources, and workflows.', 'nvoos-content-graph-ai-platform' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => PlatformDashboard::PAGE_SLUG,
			'show_in_rest'       => true,
			'rest_base'          => 'ai-platform-projects',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'ai-project' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-portfolio',
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
