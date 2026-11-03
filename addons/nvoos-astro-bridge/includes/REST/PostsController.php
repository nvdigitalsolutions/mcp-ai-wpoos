<?php
/**
 * CMS posts REST controller — the content/posts collection
 * (Proposal 106 §1.2).
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Sources\WhereClause;
use WP_Post;
use WP_REST_Request;

/**
 * Handles /funiq/v1/content/posts.
 *
 * Public listings are publish-only; writes require the manage capability
 * (the PostTypeController pattern). Posts additionally support the
 * where[category][equals] filter and category assignment on writes.
 */
class PostsController extends PostTypeController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'content/posts';

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return 'post';
	}

	/**
	 * Transform a WP_Post into the §1.2 content document (with the first
	 * WP category attached).
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		return ContentTransform::transform( $post, 'post', true );
	}

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		return ContentTransform::post_args( $data, $id, 'post' );
	}

	/**
	 * Proposal 107 (G4): agent-originated creates default to draft —
	 * publishing is a separate, capability-gated transition.
	 *
	 * @return string
	 */
	protected function default_status(): string {
		return 'draft';
	}

	/**
	 * Proposal 107 (G4): the approval state machine applies.
	 *
	 * @return bool
	 */
	protected function governed_statuses(): bool {
		return true;
	}

	/**
	 * Proposal 107 (G6): revision listing + restore.
	 *
	 * @return bool
	 */
	protected function supports_revisions(): bool {
		return true;
	}

	/**
	 * Assign the WP category after creating/updating the post.
	 *
	 * Accepts either the contract shape (`{ id, name }`) or a bare category
	 * ID — the same input the admin SPA relationship fields produce. The
	 * shared 107 meta (blocks, SEO, submitter) is saved first.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	protected function save_meta( int $post_id, array $data ): void {
		ContentTransform::save_shared( $post_id, $data );

		if ( ! isset( $data['category'] ) ) {
			return;
		}

		$category = $data['category'];
		if ( is_array( $category ) ) {
			$category = $category['id'] ?? null;
		}
		if ( null === $category || '' === $category ) {
			return;
		}

		wp_set_post_categories( $post_id, array( absint( $category ) ) );
	}

	/**
	 * Listing args — publish-only plus where[slug][equals] and
	 * where[category][equals] filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $page    1-based page.
	 * @param int             $limit   Docs per page.
	 * @return array<string, mixed>
	 */
	protected function listing_args( WP_REST_Request $request, int $page, int $limit ): array {
		$args  = parent::listing_args( $request, $page, $limit );
		$where = ContentTransform::where( $request->get_param( 'where' ) );

		if ( WhereClause::has( $where, 'slug', 'equals' ) ) {
			$args['name'] = sanitize_title( (string) WhereClause::value( $where, 'slug', 'equals' ) );
		}
		if ( WhereClause::has( $where, 'category', 'equals' ) ) {
			$args['category__in'] = array( absint( WhereClause::value( $where, 'category', 'equals' ) ) );
		}

		return $args;
	}
}
