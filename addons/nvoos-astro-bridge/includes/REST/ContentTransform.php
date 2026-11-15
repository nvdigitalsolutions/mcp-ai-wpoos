<?php
/**
 * Shared document shaping for the CMS content controllers
 * (content/pages + content/posts — Proposal 106 §1.2).
 *
 * Both controllers emit the exact §1.2 document shape and parse the same
 * `where` clause; the logic lives here so the two controllers stay thin and
 * the contract has one implementation.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

/**
 * Static transform + query helpers for the content collections.
 */
final class ContentTransform {

	/**
	 * Transform a WP_Post into the §1.2 content document.
	 *
	 * `contentRendered` runs `the_content` (the WPGraphQL RENDERED
	 * precedent — shortcodes/embeds execute) and falls back to the raw
	 * content when the render is empty. SEO meta is read directly from post
	 * meta (Rank Math → Yoast → title/excerpt fallback) so it never depends
	 * on a SEO plugin's REST toggle.
	 *
	 * @param \WP_Post $post          Post object.
	 * @param string   $type          Document type (page|post).
	 * @param bool     $with_category Attach the first WP category (posts only).
	 * @return array<string, mixed>
	 */
	public static function transform( \WP_Post $post, string $type, bool $with_category ): array {
		$raw      = (string) $post->post_content;
		$rendered = apply_filters( 'the_content', $raw );
		if ( '' === trim( (string) $rendered ) ) {
			$rendered = $raw;
		}

		$excerpt = get_the_excerpt( $post );
		if ( '' === $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $raw ), 55 );
		}

		$featured = get_the_post_thumbnail_url( $post, 'large' );

		$category = null;
		if ( $with_category ) {
			$terms = get_the_category( $post->ID );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$category = array(
					'id'   => (int) $terms[0]->term_id,
					'name' => (string) $terms[0]->name,
				);
			}
		}

		// Proposal 107: blocks are the agent-authored structured-content
		// layer (Portable Text); submittedBy/approvedBy are the actor-audit
		// trail (the approval state the submitter cannot flip).
		$blocks = get_post_meta( $post->ID, \NvoosAstroBridge\Schema::META_CONTENT_BLOCKS, true );
		if ( is_string( $blocks ) && '' !== $blocks ) {
			// Legacy storage shape (the 107 first cut wrote a JSON string) —
			// decode to the array form and fall back to empty on garbage.
			$decoded = json_decode( $blocks, true );
			$blocks  = is_array( $decoded ) ? $decoded : array();
		}
		if ( is_array( $blocks ) ) {
			$blocks = array_values( $blocks );
		} else {
			$blocks = array();
		}

		$submitted_by = get_post_meta( $post->ID, \NvoosAstroBridge\Schema::META_SUBMITTED_BY, true );
		$approved_by  = get_post_meta( $post->ID, \NvoosAstroBridge\Schema::META_APPROVED_BY, true );

		$doc = array(
			'id'              => (int) $post->ID,
			'slug'            => (string) $post->post_name,
			'title'           => (string) $post->post_title,
			'excerpt'         => (string) $excerpt,
			'content'         => $raw,
			'contentRendered' => (string) $rendered,
			'type'            => $type,
			'featuredImage'   => $featured ? $featured : null,
			'seoTitle'        => self::first_non_empty(
				get_post_meta( $post->ID, 'rank_math_title', true ),
				get_post_meta( $post->ID, '_yoast_wpseo_title', true ),
				$post->post_title
			),
			'seoDescription'  => self::first_non_empty(
				get_post_meta( $post->ID, 'rank_math_description', true ),
				get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
				$excerpt
			),
			'category'        => $category,
			'createdAt'       => (string) $post->post_date,
			'updatedAt'       => (string) $post->post_modified,
			'status'          => (string) $post->post_status,
			'publishAt'       => 'future' === $post->post_status ? (string) $post->post_date : null,
		);

		// Additive 107 fields — absent unless set, so legacy consumers and
		// goldens stay byte-identical for docs that predate the slice.
		if ( array() !== $blocks ) {
			$doc['blocks'] = $blocks;
		}
		if ( is_string( $submitted_by ) && '' !== $submitted_by ) {
			$doc['submittedBy'] = $submitted_by;
		}
		if ( is_string( $approved_by ) && '' !== $approved_by ) {
			$doc['approvedBy'] = $approved_by;
		}

		return $doc;
	}

	/**
	 * Build post insert/update args from the request body (title, content,
	 * excerpt, status) — the two-gate rule: sanitize at entry.
	 *
	 * @param array<string, mixed> $data Request body.
	 * @param int|null             $id   Post ID (null for create).
	 * @param string               $type Post type (page|post).
	 * @return array<string, mixed>
	 */
	public static function post_args( array $data, ?int $id, string $type ): array {
		$args = array(
			'post_type' => $type,
		);

		if ( null !== $id ) {
			$args['ID'] = $id;
		}

		if ( isset( $data['title'] ) ) {
			$args['post_title'] = sanitize_text_field( $data['title'] );
		}
		if ( isset( $data['content'] ) ) {
			$args['post_content'] = wp_kses_post( $data['content'] );
		}
		if ( isset( $data['excerpt'] ) ) {
			$args['post_excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'publish', 'draft', 'pending', 'future' ), true ) ) {
			$args['post_status'] = $data['status'];
		}

		// Proposal 107 (G8): publishAt schedules the post (WP `future`). The
		// date rides post_date; an explicit publish status wins over the
		// scheduled flip.
		if ( isset( $data['publishAt'] ) && is_string( $data['publishAt'] ) && '' !== $data['publishAt'] ) {
			$parsed = rest_parse_date( sanitize_text_field( $data['publishAt'] ) );
			// WP 6.9 rest_parse_date() returns a Unix timestamp; older core
			// returns the 'Y-m-d H:i:s' string. Normalize to the string form
			// wp_insert_post/wp_checkdate expect.
			if ( is_int( $parsed ) && $parsed > 0 ) {
				$parsed = gmdate( 'Y-m-d H:i:s', $parsed );
			}
			if ( is_string( $parsed ) && '' !== $parsed ) {
				$args['post_date']     = $parsed;
				$args['post_date_gmt'] = get_gmt_from_date( $parsed );
				if ( ! isset( $args['post_status'] ) || 'draft' === $args['post_status'] || 'pending' === $args['post_status'] ) {
					$args['post_status'] = 'future';
				}
			}
		}

		return $args;
	}

	/**
	 * Save the shared 107 fields (blocks, SEO meta, submitter) — the
	 * two-gate rule: validate and sanitize at entry.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $data    Request body.
	 * @return void
	 */
	public static function save_shared( int $post_id, array $data ): void {
		if ( isset( $data['blocks'] ) ) {
			$blocks = $data['blocks'];
			if ( is_array( $blocks ) ) {
				// Blocks are an allowlisted Portable Text subset — validated
				// by the storefront schema; the bridge stores them as data,
				// never renders them. Stored natively (WP serializes arrays)
				// so the read path returns the array form.
				update_post_meta( $post_id, \NvoosAstroBridge\Schema::META_CONTENT_BLOCKS, array_values( $blocks ) );
			} else {
				delete_post_meta( $post_id, \NvoosAstroBridge\Schema::META_CONTENT_BLOCKS );
			}
		}

		// SEO write-through (G7): Rank Math first, then Yoast — the reverse
		// of the read-side mapping.
		if ( isset( $data['seoTitle'] ) ) {
			$title = sanitize_text_field( $data['seoTitle'] );
			update_post_meta( $post_id, 'rank_math_title', $title );
			update_post_meta( $post_id, '_yoast_wpseo_title', $title );
		}
		if ( isset( $data['seoDescription'] ) ) {
			$desc = sanitize_text_field( $data['seoDescription'] );
			update_post_meta( $post_id, 'rank_math_description', $desc );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
		}

		if ( isset( $data['submittedBy'] ) && is_string( $data['submittedBy'] ) && '' !== $data['submittedBy'] ) {
			update_post_meta( $post_id, \NvoosAstroBridge\Schema::META_SUBMITTED_BY, sanitize_text_field( $data['submittedBy'] ) );
		}
	}

	/**
	 * Normalize a raw `where` request param into the WhereClause shape.
	 *
	 * Accepts the bracket-expanded query array the storefront + SPA clients
	 * emit, or a JSON-encoded string (the ProductsController convention).
	 *
	 * @param mixed $raw Raw where param.
	 * @return array<string, array<string, mixed>>
	 */
	public static function where( $raw ): array {
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}
		return \NvoosAstroBridge\Sources\WhereClause::normalize( $raw );
	}

	/**
	 * First non-empty string among the candidates ('' when none qualify).
	 *
	 * @param mixed ...$candidates Candidate values.
	 * @return string
	 */
	private static function first_non_empty( ...$candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}
		return '';
	}

	/** Private — static utility only. */
	private function __construct() {}
}
