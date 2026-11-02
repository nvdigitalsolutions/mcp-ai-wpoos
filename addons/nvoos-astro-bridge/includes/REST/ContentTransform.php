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

		return array(
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
		);
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
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'publish', 'draft' ), true ) ) {
			$args['post_status'] = $data['status'];
		}

		return $args;
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
