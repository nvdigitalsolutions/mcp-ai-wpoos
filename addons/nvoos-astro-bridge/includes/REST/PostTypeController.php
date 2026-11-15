<?php
/**
 * Abstract base for post-type-backed REST controllers (promotions, promocodes).
 *
 * Reuses the same Paginated CRUD pattern as taxonomy controllers
 * but operates on CPTs via WP_Query / wp_insert_post / wp_update_post.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Base class for CPT-based controllers.
 */
abstract class PostTypeController extends BaseController {

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	abstract protected function post_type(): string;

	/**
	 * Transform a WP_Post into the output shape.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	abstract protected function transform( \WP_Post $post ): array;

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data  Request body.
	 * @param int|null             $id    Existing post ID (null for create).
	 * @return array<string, mixed>
	 */
	abstract protected function post_args( array $data, ?int $id ): array;

	/**
	 * Save meta fields after creating/updating the post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	abstract protected function save_meta( int $post_id, array $data ): void;

	/**
	 * Status the create endpoint applies when the body carries none.
	 *
	 * Content controllers (Proposal 107) override this to `draft` so
	 * agent-originated creates never publish by accident; the CPT commerce
	 * controllers keep the historical publish-on-create behavior.
	 *
	 * @return string
	 */
	protected function default_status(): string {
		return 'publish';
	}

	/**
	 * Whether status transitions are governed (Proposal 107 G4).
	 *
	 * Governed controllers enforce the approval state machine: publishing
	 * (or unpublishing) requires `publish_posts` — a state the submitting
	 * role cannot flip — and records the approver on the publish transition.
	 *
	 * @return bool
	 */
	protected function governed_statuses(): bool {
		return false;
	}

	/**
	 * Whether the controller exposes the revisions/restore routes.
	 *
	 * @return bool
	 */
	protected function supports_revisions(): bool {
		return false;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = $this->rest_base;

		register_rest_route(
			$this->namespace,
			'/' . $base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $base . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
				),
			)
		);

		// Proposal 107 (G6): revision listing + restore for governed content.
		if ( $this->supports_revisions() ) {
			register_rest_route(
				$this->namespace,
				'/' . $base . '/(?P<id>[\d]+)/revisions',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'list_revisions' ),
						'permission_callback' => array( $this, 'item_permissions_check' ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/' . $base . '/(?P<id>[\d]+)/restore',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'restore_revision' ),
						'permission_callback' => array( $this, 'item_permissions_check' ),
						'args'                => array(
							'revision' => array(
								'required'          => true,
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
							),
						),
					),
				)
			);
		}
	}

	/**
	 * Enforce the Proposal 107 approval state machine on a requested status.
	 *
	 * Publishing, scheduling and unpublishing require `publish_posts` on
	 * governed controllers — the unflippable gate for agent-originated
	 * content. Draft/pending round-trips stay open to the manage cap alone.
	 *
	 * @param int|null             $post_id      Existing post ID (null for create).
	 * @param array<string, mixed> $args         Prepared post args.
	 * @param array<string, mixed> $data         Raw request body.
	 * @return WP_Error|null
	 */
	protected function status_gate( ?int $post_id, array $args, array $data ): ?WP_Error {
		if ( ! $this->governed_statuses() ) {
			return null;
		}

		$status = isset( $args['post_status'] ) ? $args['post_status'] : ( null === $post_id ? $this->default_status() : null );
		if ( null === $status ) {
			return null;
		}

		$publishing = in_array( $status, array( 'publish', 'future' ), true );

		// Unpublishing a live doc is a publish-scope action too.
		$unpublishing = false;
		if ( null !== $post_id && ! $publishing ) {
			$post         = get_post( $post_id );
			$unpublishing = ( $post && 'publish' === $post->post_status );
		}

		if ( ( $publishing || $unpublishing ) && ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error(
				'content_publish_forbidden',
				'Publishing requires the publish_posts capability.',
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Record the approver on a publish transition (governed controllers).
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $args    Prepared post args.
	 * @return void
	 */
	protected function record_approval( int $post_id, array $args ): void {
		if ( ! $this->governed_statuses() || ! isset( $args['post_status'] ) || 'publish' !== $args['post_status'] ) {
			return;
		}

		$user = wp_get_current_user();
		$name = '' !== $user->display_name ? $user->display_name : $user->user_login;
		update_post_meta(
			$post_id,
			\NvoosAstroBridge\Schema::META_APPROVED_BY,
			sanitize_text_field( $name . ' @ ' . gmdate( 'c' ) )
		);
	}

	/**
	 * GET collection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$page  = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$limit = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );

		$query = new WP_Query( $this->listing_args( $request, $page, $limit ) );

		$docs = array();
		foreach ( $query->posts as $post ) {
			$docs[] = $this->transform( $post );
		}

		return rest_ensure_response(
			$this->paginatedResponse( $docs, (int) $query->found_posts, $page, $limit )
		);
	}

	/**
	 * Build the WP_Query args for the public listing.
	 *
	 * Publish-only, date desc — the contract's public listing semantics.
	 * Governed controllers (Proposal 107) additionally honour a `status`
	 * query param (draft|pending|future|any) for manager sessions only —
	 * non-managers always get publish-only (fail-closed, no leak).
	 * Child controllers override this to add `where` handling (simple
	 * WP_Query args, not the source layer) — Proposal 106 §3.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $page    1-based page.
	 * @param int             $limit   Docs per page.
	 * @return array<string, mixed>
	 */
	protected function listing_args( WP_REST_Request $request, int $page, int $limit ): array {
		$post_status = 'publish';
		if ( $this->governed_statuses() ) {
			$requested = (string) $request->get_param( 'status' );
			if ( '' !== $requested && 'publish' !== $requested ) {
				AuthSupport::maybe_restore_user_from_logged_in_cookie();
				if ( current_user_can( \NvoosAstroBridge\Schema::CAP_MANAGE ) ) {
					$post_status = 'any' === $requested
						? array( 'publish', 'draft', 'pending', 'future' )
						: $requested;
				}
			}
		}

		return array(
			'post_type'      => $this->post_type(),
			'post_status'    => $post_status,
			'paged'          => $page,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
	}

	/**
	 * POST create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$data = $request->get_json_params();
		$args = $this->post_args( $data, null );

		if ( ! isset( $args['post_status'] ) ) {
			$args['post_status'] = $this->default_status();
		}

		$gate = $this->status_gate( null, $args, $data );
		if ( null !== $gate ) {
			return $gate;
		}

		$id = wp_insert_post( $args, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->save_meta( $id, $data );
		$this->record_approval( $id, $args );

		return $this->single_response( $id );
	}

	/**
	 * GET single.
	 *
	 * Governed content is publish-only publicly (Proposal 107 §3.2): a
	 * non-published doc 404s unless the request carries a manager session
	 * (the storefront preview flow forwards the visitor's cookies — the
	 * fail-closed half of the preview token exchange). 404 rather than 403
	 * so draft existence never leaks on the public surface.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		if ( $this->governed_statuses() && 'publish' !== $post->post_status ) {
			AuthSupport::maybe_restore_user_from_logged_in_cookie();
			if ( ! current_user_can( \NvoosAstroBridge\Schema::CAP_MANAGE ) ) {
				return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
			}
		}

		return rest_ensure_response( $this->transform( $post ) );
	}

	/**
	 * PUT update.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$data = $request->get_json_params();
		$args = $this->post_args( $data, $post_id );

		$gate = $this->status_gate( $post_id, $args, $data );
		if ( null !== $gate ) {
			return $gate;
		}

		wp_update_post( $args, true );
		$this->save_meta( $post_id, $data );
		$this->record_approval( $post_id, $args );

		return $this->single_response( $post_id );
	}

	/**
	 * DELETE.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$result = wp_delete_post( $post_id, true );
		if ( ! $result ) {
			return new WP_Error( 'astro_delete_failed', 'Failed to delete.', array( 'status' => 500 ) );
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * GET revisions — the WP revision list in the envelope shape
	 * (id, author, date) for the content_restore tool (Proposal 107 G6).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_revisions( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$revisions = wp_get_post_revisions( $post_id );
		if ( ! is_array( $revisions ) ) {
			$revisions = array();
		}

		$docs = array();
		foreach ( $revisions as $revision ) {
			$docs[] = array(
				'id'     => (int) $revision->ID,
				'date'   => (string) $revision->post_date,
				'author' => (string) get_the_author_meta( 'display_name', (int) $revision->post_author ),
			);
		}

		return rest_ensure_response(
			array(
				'docs'      => $docs,
				'totalDocs' => count( $docs ),
			)
		);
	}

	/**
	 * POST restore — restore a specific revision (WP semantics: the restore
	 * itself creates a new revision, so nothing is ever lost).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_revision( $request ) {
		$post_id     = (int) $request['id'];
		$revision_id = (int) $request['revision'];
		$post        = get_post( $post_id );
		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type || (int) $revision->post_parent !== $post_id ) {
			return new WP_Error( 'astro_revision_not_found', 'Revision not found.', array( 'status' => 404 ) );
		}

		$restored = wp_restore_post_revision( $revision_id );
		if ( is_wp_error( $restored ) ) {
			return new WP_Error( 'astro_restore_failed', $restored->get_error_message(), array( 'status' => 500 ) );
		}

		return $this->single_response( $post_id );
	}

	/**
	 * Build a single-item REST response.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function single_response( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->transform( $post ) );
	}
}
