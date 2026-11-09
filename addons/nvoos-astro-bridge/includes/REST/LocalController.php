<?php
/**
 * Local content REST controller — the `local` collection (Proposal 135 §D4).
 *
 * Serves the `nvoos_forge_local` CPT that fills the gap between "the source
 * site's content" and "content the managed site should own": new landing
 * pages, case studies, policy pages. One store, one capability, one audit
 * trail — the same rules the bridged `content/pages` + `content/posts`
 * collections already use.
 *
 * TWO CONSUMERS, ONE DOCUMENT
 *
 * The `/manage` SPA expects the Proposal 106 §1.2 content document (title /
 * content / excerpt / seoTitle / status). The forge loader expects the
 * nvoos-commerce envelope (`name` / `description` / `createdAt`). Rather than
 * fork the wire format, this controller emits the content document PLUS the
 * two envelope aliases (`name`, `description`) and an explicit `slug` — the
 * forge's `extractPayloadRaw()` maps that unchanged, and the SPA ignores the
 * extra keys. `slug` matters: envelope documents normally derive their slug
 * from the title, which is wrong for content that already has a URL.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use NvoosAstroBridge\Sources\WhereClause;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles /nvoos-commerce/v1/local.
 *
 * Reads are public for published docs; every write requires `manage_astro`,
 * and publishing additionally requires `publish_posts` (the Proposal 107
 * approval state machine, inherited from PostTypeController).
 */
class LocalController extends PostTypeController {

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
	protected $rest_base = 'local';

	/**
	 * Get the post type slug.
	 *
	 * @return string
	 */
	protected function post_type(): string {
		return Schema::CPT_FORGE_LOCAL;
	}

	/**
	 * Transform a WP_Post into the local-content document.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	protected function transform( WP_Post $post ): array {
		$doc = ContentTransform::transform( $post, 'page', false );

		// Envelope aliases — read by the forge loader's extractPayloadRaw().
		$doc['name']        = $doc['title'];
		$doc['description'] = $doc['content'];
		$doc['source']      = Schema::SOURCE_LOCAL;

		return $doc;
	}

	/**
	 * Build post insert/update args from request data.
	 *
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @return array<string, mixed>
	 */
	protected function post_args( array $data, ?int $id ): array {
		return ContentTransform::post_args( $data, $id, Schema::CPT_FORGE_LOCAL );
	}

	/**
	 * Creates default to draft — an agent or an editor must never publish by
	 * accident; going live is the separate `publish_posts` transition.
	 *
	 * @return string
	 */
	protected function default_status(): string {
		return 'draft';
	}

	/**
	 * The Proposal 107 approval state machine applies.
	 *
	 * @return bool
	 */
	protected function governed_statuses(): bool {
		return true;
	}

	/**
	 * Revisions + restore are available (the same restore semantics as pages).
	 *
	 * @return bool
	 */
	protected function supports_revisions(): bool {
		return true;
	}

	/**
	 * Blocks, SEO meta and the submitter audit trail.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	protected function save_meta( int $post_id, array $data ): void {
		ContentTransform::save_shared( $post_id, $data );
	}

	/**
	 * Listing args — publish-only publicly, plus `where[slug][equals]`.
	 *
	 * A valid `preview_token` narrows the listing to the ONE post the token
	 * names and lets that post through regardless of status, so an editor can
	 * render a draft at its real URL without a WordPress session on the
	 * public host.
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

		$preview_id = PreviewToken::verify( $request->get_param( 'preview_token' ) );
		if ( $preview_id > 0 ) {
			$args['post__in']    = array( $preview_id );
			$args['post_status'] = array( 'publish', 'draft', 'pending', 'future' );
		}

		return $args;
	}

	/**
	 * GET single — a valid preview token reads its own post even when it is
	 * not published; everything else falls through to the parent rule
	 * (published publicly, drafts only for a manager session).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$preview_id = PreviewToken::verify( $request->get_param( 'preview_token' ) );

		if ( $preview_id > 0 && $preview_id === (int) $request['id'] ) {
			$post = get_post( $preview_id );
			if ( $post && $this->post_type() === $post->post_type ) {
				return rest_ensure_response( $this->transform( $post ) );
			}
		}

		return parent::get_item( $request );
	}

	/**
	 * Register routes — the inherited CRUD surface plus the preview mint.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/preview-token',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'issue_preview_token' ),
					'permission_callback' => array( $this, 'item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * POST /local/<id>/preview-token — mint a preview link.
	 *
	 * Returns the token plus the ready-to-open URL on the PUBLIC origin: the
	 * forged site's `/api/preview` stores the token in a host-only cookie and
	 * redirects to the real page, which then reads the draft through the same
	 * contract. `manage_astro` gates it, so a preview link can only ever be
	 * created by someone who could edit the document anyway.
	 *
	 * Drafts and published docs alike can be previewed — previewing a live
	 * page is how an editor checks a pending edit before approving it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|WP_Error
	 */
	public function issue_preview_token( $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );

		if ( ! $post || $this->post_type() !== $post->post_type ) {
			return new WP_Error( 'astro_not_found', 'Item not found.', array( 'status' => 404 ) );
		}

		$issued = PreviewToken::issue( $post_id );
		$slug   = (string) $post->post_name;

		// The preview URL must point at the PUBLIC site. `public_origin()` falls
		// back to `home_url()` (the CMS) so shopper-facing URLs never break on a
		// single-origin install — but that fallback is the WRONG host for a
		// preview link (it serves no `/api/preview`). Require the operator to
		// have actually configured the origin, and return an empty URL otherwise
		// so the UI can say so instead of opening a 404 on the CMS.
		$configured = get_option( Schema::OPTION_PUBLIC_ORIGIN, '' );
		$origin     = is_string( $configured ) ? Schema::normalize_origin( $configured ) : '';

		return rest_ensure_response(
			array(
				'token'      => $issued['token'],
				'expiresAt'  => $issued['expiresAt'],
				'slug'       => $slug,
				// The operator opens this; the forged app only transports the
				// token. Empty until Settings → Domains → Public site origin is
				// set — the editor can still paste the token into a draft route,
				// and the UI tells them what is missing.
				'previewUrl' => '' === $origin ? '' : $origin . '/api/preview?token=' . rawurlencode( $issued['token'] ) . '&slug=' . rawurlencode( $slug ),
			)
		);
	}
}
