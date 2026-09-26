<?php
/**
 * Tool: seo_bulk_update_metadata — Updates SEO metadata across multiple posts.
 *
 * Port of mcp-wordpress wp_seo_bulk_update_metadata tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Bulk Update Metadata — applies SEO field changes to many posts at once.
 *
 * Updates titles, descriptions (excerpts), focus keywords, and canonical URLs
 * across up to 50 posts, writing plugin-specific fields to the active SEO
 * plugin's meta keys (Rank Math, Yoast, AIOSEO) with a neutral fallback key.
 *
 * Defaults to a dry run for safety.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Bulk_Update_Metadata implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_bulk_update_metadata';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Bulk Metadata Updater', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates SEO metadata (title, description, focus keyword, canonical URL) across up to 50 posts, writing focus keyword and canonical fields to the active SEO plugin\'s meta keys (Rank Math, Yoast, AIOSEO). Defaults to a dry run for safety — pass dry_run=false to write.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Applying the same SEO change to many posts at once, e.g. setting a focus keyword or canonical URL across a category of posts.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'One-off edits; use seo_meta_optimizer or get_post/save_post for single posts. This tool does not rewrite post content.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_meta_optimizer', 'get_rankmath_seo', 'get_recent_posts', 'save_post' ),
			'notes'           => __( 'The default is a dry run (dry_run=true) — nothing is written until you opt in. Focus keyword and canonical values go to the active SEO plugin\'s meta key, or a neutral _wp_mcp_ai_* key when no plugin is detected.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_ids' => array(
					'type'        => 'array',
					'description' => __( 'Post IDs to update (maximum 50).', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'integer' ),
					'minItems'    => 1,
					'maxItems'    => 50,
				),
				'updates'  => array(
					'type'                 => 'object',
					'description'          => __( 'SEO fields to update. Only provided fields are applied.', 'mcp-ai-wpoos' ),
					'properties'           => array(
						'title'         => array(
							'type'        => 'string',
							'description' => __( 'New post title.', 'mcp-ai-wpoos' ),
						),
						'description'   => array(
							'type'        => 'string',
							'description' => __( 'New description (stored as the post excerpt).', 'mcp-ai-wpoos' ),
						),
						'focus_keyword' => array(
							'type'        => 'string',
							'description' => __( 'Focus keyword written to the active SEO plugin meta key.', 'mcp-ai-wpoos' ),
						),
						'canonical'     => array(
							'type'        => 'string',
							'description' => __( 'Canonical URL written to the active SEO plugin meta key.', 'mcp-ai-wpoos' ),
							'format'      => 'uri',
						),
					),
					'additionalProperties' => false,
				),
				'dry_run'  => array(
					'type'        => 'boolean',
					'description' => __( 'When true (the default), only report what WOULD change without writing anything. This is the default for safety.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'post_ids', 'updates' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',               // Creates or modifies data.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
			'state-changing',      // Modifies database state.
			'reversible',          // Changes can be undone via revisions/re-edits.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update post metadata.', 'mcp-ai-wpoos' ) );
		}

		$post_ids = isset( $arguments['post_ids'] ) && is_array( $arguments['post_ids'] ) ? $arguments['post_ids'] : array();
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_post_ids', __( 'At least one post ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( count( $post_ids ) > 50 ) {
			return new WP_Error( 'wp_mcp_ai_too_many_posts', __( 'A maximum of 50 posts can be updated per call.', 'mcp-ai-wpoos' ) );
		}

		$updates = isset( $arguments['updates'] ) && is_array( $arguments['updates'] ) ? $arguments['updates'] : array();

		$new_title       = isset( $updates['title'] ) ? sanitize_text_field( $updates['title'] ) : '';
		$new_description = isset( $updates['description'] ) ? sanitize_textarea_field( $updates['description'] ) : '';
		$focus_keyword   = isset( $updates['focus_keyword'] ) ? sanitize_text_field( $updates['focus_keyword'] ) : '';
		$canonical       = isset( $updates['canonical'] ) ? esc_url_raw( $updates['canonical'] ) : '';

		if ( '' === $new_title && '' === $new_description && '' === $focus_keyword && '' === $canonical ) {
			return new WP_Error( 'wp_mcp_ai_missing_updates', __( 'At least one update field (title, description, focus_keyword, or canonical) is required.', 'mcp-ai-wpoos' ) );
		}

		$dry_run = isset( $arguments['dry_run'] ) ? filter_var( $arguments['dry_run'], FILTER_VALIDATE_BOOLEAN ) : true;

		$results       = array();
		$processed     = 0;
		$updated_count = 0;
		$with_changes  = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post ) {
				$results[] = array(
					'id'      => $post_id,
					'status'  => 'skipped_not_found',
					'changes' => array(),
				);
				continue;
			}

			if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
				$results[] = array(
					'id'      => $post_id,
					'status'  => 'skipped_forbidden',
					'changes' => array(),
				);
				continue;
			}

			$focus_keyword_key = $this->get_focus_keyword_meta_key( $post_id );
			$canonical_key     = $this->get_canonical_meta_key( $post_id );

			$changes = array();
			if ( '' !== $new_title && $new_title !== $post->post_title ) {
				$changes[] = 'title';
			}
			if ( '' !== $new_description && $new_description !== $post->post_excerpt ) {
				$changes[] = 'description';
			}
			if ( '' !== $focus_keyword && get_post_meta( $post_id, $focus_keyword_key, true ) !== $focus_keyword ) {
				$changes[] = 'focus_keyword';
			}
			if ( '' !== $canonical && get_post_meta( $post_id, $canonical_key, true ) !== $canonical ) {
				$changes[] = 'canonical';
			}

			if ( ! empty( $changes ) ) {
				++$with_changes;
			}

			++$processed;

			if ( $dry_run ) {
				$results[] = array(
					'id'      => $post_id,
					'status'  => 'dry_run',
					'changes' => $changes,
				);
				continue;
			}

			if ( in_array( 'title', $changes, true ) || in_array( 'description', $changes, true ) ) {
				$post_args = array( 'ID' => $post_id );
				if ( in_array( 'title', $changes, true ) ) {
					$post_args['post_title'] = $new_title;
				}
				if ( in_array( 'description', $changes, true ) ) {
					$post_args['post_excerpt'] = $new_description;
				}
				$update = wp_update_post( $post_args, true );
				if ( is_wp_error( $update ) ) {
					$results[] = array(
						'id'      => $post_id,
						'status'  => 'error',
						'changes' => $changes,
						'error'   => $update->get_error_message(),
					);
					continue;
				}
			}

			if ( in_array( 'focus_keyword', $changes, true ) ) {
				update_post_meta( $post_id, $focus_keyword_key, $focus_keyword );
			}
			if ( in_array( 'canonical', $changes, true ) ) {
				update_post_meta( $post_id, $canonical_key, $canonical );
			}

			++$updated_count;
			$results[] = array(
				'id'      => $post_id,
				'status'  => 'updated',
				'changes' => $changes,
			);
		}

		if ( 0 === $processed ) {
			return new WP_Error( 'wp_mcp_ai_no_posts_updated', __( 'None of the provided posts could be processed; they were not found or you lack permission.', 'mcp-ai-wpoos' ) );
		}

		if ( $dry_run ) {
			$message = sprintf(
				/* translators: 1: number of posts processed, 2: number of posts with pending changes */
				__( 'Dry run: %1$d post(s) processed and %2$d have pending SEO metadata changes. No data was written.', 'mcp-ai-wpoos' ),
				$processed,
				$with_changes
			);
		} else {
			$message = sprintf(
				/* translators: 1: number of posts processed, 2: number of posts updated */
				__( 'SEO metadata updated for %2$d of %1$d post(s).', 'mcp-ai-wpoos' ),
				$processed,
				$updated_count
			);
		}

		return array(
			'message' => $message,
			'updated' => $results,
			'dry_run' => $dry_run,
		);
	}

	/**
	 * Resolves the focus keyword meta key for a post.
	 *
	 * Prefers the active SEO plugin's key and falls back to a neutral key.
	 *
	 * @since 1.1.87
	 *
	 * @param int $post_id Post ID.
	 * @return string Meta key.
	 */
	private function get_focus_keyword_meta_key( $post_id ) {
		$existing = get_post_meta( $post_id, 'rank_math_focus_keyword', true );

		if ( '' !== $existing || function_exists( 'rank_math' ) ) {
			return 'rank_math_focus_keyword';
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_focuskw';
		}

		if ( function_exists( 'aioseo' ) ) {
			return '_aioseo_keywords';
		}

		return '_wp_mcp_ai_focus_keyword';
	}

	/**
	 * Resolves the canonical URL meta key for a post.
	 *
	 * Prefers the active SEO plugin's key and falls back to a neutral key.
	 *
	 * @since 1.1.87
	 *
	 * @param int $post_id Post ID.
	 * @return string Meta key.
	 */
	private function get_canonical_meta_key( $post_id ) {
		$existing = get_post_meta( $post_id, 'rank_math_canonical_url', true );

		if ( '' !== $existing || function_exists( 'rank_math' ) ) {
			return 'rank_math_canonical_url';
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			return '_yoast_wpseo_canonical';
		}

		if ( function_exists( 'aioseo' ) ) {
			return '_aioseo_canonical_url';
		}

		return '_wp_mcp_ai_canonical_url';
	}
}
