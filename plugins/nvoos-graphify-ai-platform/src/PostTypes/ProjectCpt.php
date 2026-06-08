<?php
/**
 * AI Project Custom Post Type.
 *
 * Registers `ai_platform_project` — a post type for AI platform
 * projects that appears under the "NV Platform" admin menu.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\PostTypes
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\PostTypes;

use NvoosGraphifyAiPlatform\Admin\PlatformDashboard;

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
			'name'                  => _x( 'AI Projects', 'post type general name', 'nvoos-graphify-ai-platform' ),
			'singular_name'         => _x( 'AI Project', 'post type singular name', 'nvoos-graphify-ai-platform' ),
			'menu_name'             => __( 'AI Projects', 'nvoos-graphify-ai-platform' ),
			'add_new'               => __( 'Add New', 'nvoos-graphify-ai-platform' ),
			'add_new_item'          => __( 'Add New AI Project', 'nvoos-graphify-ai-platform' ),
			'edit_item'             => __( 'Edit AI Project', 'nvoos-graphify-ai-platform' ),
			'new_item'              => __( 'New AI Project', 'nvoos-graphify-ai-platform' ),
			'view_item'             => __( 'View AI Project', 'nvoos-graphify-ai-platform' ),
			'search_items'          => __( 'Search AI Projects', 'nvoos-graphify-ai-platform' ),
			'not_found'             => __( 'No AI projects found.', 'nvoos-graphify-ai-platform' ),
			'not_found_in_trash'    => __( 'No AI projects found in Trash.', 'nvoos-graphify-ai-platform' ),
			'all_items'             => __( 'All AI Projects', 'nvoos-graphify-ai-platform' ),
			'archives'              => __( 'AI Project Archives', 'nvoos-graphify-ai-platform' ),
			'attributes'            => __( 'AI Project Attributes', 'nvoos-graphify-ai-platform' ),
			'insert_into_item'      => __( 'Insert into AI project', 'nvoos-graphify-ai-platform' ),
			'uploaded_to_this_item' => __( 'Uploaded to this AI project', 'nvoos-graphify-ai-platform' ),
			'filter_items_list'     => __( 'Filter AI projects list', 'nvoos-graphify-ai-platform' ),
			'items_list_navigation' => __( 'AI Projects list navigation', 'nvoos-graphify-ai-platform' ),
			'items_list'            => __( 'AI Projects list', 'nvoos-graphify-ai-platform' ),
			'item_published'        => __( 'AI Project published.', 'nvoos-graphify-ai-platform' ),
			'item_updated'          => __( 'AI Project updated.', 'nvoos-graphify-ai-platform' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'AI platform projects — containers for agents, resources, and workflows.', 'nvoos-graphify-ai-platform' ),
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
