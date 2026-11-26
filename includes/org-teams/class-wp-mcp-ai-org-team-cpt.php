<?php
/**
 * Org-team post type.
 *
 * Org teams are a people primitive (users with roles), not content. They are
 * stored as a non-public CPT so WordPress's post APIs provide IDs, slugs,
 * authors and revisions without a custom table; the console never sees the CPT
 * itself, only the REST contract.
 *
 * @package WP_MCP_AI
 * @since   1.2.22
 * @license GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Non-public org-team post type.
 */
class WP_MCP_AI_Org_Team_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_org_team';

	/**
	 * Membership meta key.
	 *
	 * A list of `{ user_id, role, profession_slug, invited_by, joined_at }`.
	 * Kept on the team (one writer) rather than user meta, so a membership
	 * cannot drift between two stores.
	 *
	 * @var string
	 */
	const META_MEMBERS = '_wp_mcp_ai_org_team_members';

	/**
	 * Register the post type.
	 *
	 * @since 1.2.22
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Org Teams', 'mcp-ai-wpoos' ),
					'singular_name' => __( 'Org Team', 'mcp-ai-wpoos' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'delete_with_user'    => false,
				'exclude_from_search' => true,
			)
		);
	}
}
