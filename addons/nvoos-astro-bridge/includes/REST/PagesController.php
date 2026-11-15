<?php
/**
 * CMS pages REST controller — the content/pages collection
 * (Proposal 106 §1.2).
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Sources\WhereClause;
use WP_Post;
use WP_REST_Request;

/**
 * Handles /funiq/v1/content/pages.
 *
 * Public listings are publish-only; writes require the manage capability
 * (the PostTypeController pattern).
 */
class PagesController extends PostTypeController {

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
	protected $rest_base = 'content/pages';

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return 'page';
	}

	/**
	 * Transform a WP_Post into the §1.2 content document.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		return ContentTransform::transform( $post, 'page', false );
	}

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		return ContentTransform::post_args( $data, $id, 'page' );
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
	 * Pages carry the shared 107 meta (blocks, SEO, submitter).
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	protected function save_meta( int $post_id, array $data ): void {
		ContentTransform::save_shared( $post_id, $data );
	}

	/**
	 * Listing args — publish-only plus the where[slug][equals] filter.
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

		return $args;
	}
}
