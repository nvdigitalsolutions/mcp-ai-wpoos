<?php
/**
 * OKF Auto-Enrichment Agent (Pro).
 *
 * Crawls published WordPress content (posts, pages, any registered public
 * post type, and optionally taxonomy terms) and auto-generates OKF concepts
 * with cross-links into a dedicated bundle. Deterministic by default so
 * re-runs are idempotent; descriptions can be upgraded to AI summaries via
 * the `wp_mcp_ai_okf_enrichment_description` filter.
 *
 * Inspired by Google's reference enrichment agent for BigQuery.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates OKF concepts from site content.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_OKF_Enrichment_Agent {

	const DEFAULT_BUNDLE = 'site-content';
	const MAX_ITEMS      = 200;
	const MAX_LINKS      = 20;
	const BODY_WORD_CAP  = 400;

	/**
	 * Crawl site content and generate OKF concepts.
	 *
	 * @since 1.1.62
	 *
	 * @param array $args {
	 *     Optional arguments.
	 *
	 *     @type string   $bundle         Target bundle name (default 'site-content'; created on first run).
	 *     @type string[] $post_types     Post types to crawl (default array( 'post', 'page' )).
	 *     @type int      $limit          Maximum items per content class (default 50; hard cap 200).
	 *     @type bool     $include_terms  Also generate term concepts (default false).
	 *     @type bool     $include_content Include post content in the concept body (default true).
	 * }
	 * @return array|WP_Error Summary array or WP_Error.
	 */
	public function enrich( array $args = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_okf_engine_missing',
				__( 'The OKF engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$bundle = isset( $args['bundle'] ) ? sanitize_text_field( (string) $args['bundle'] ) : self::DEFAULT_BUNDLE;
		if ( '' === $bundle ) {
			$bundle = self::DEFAULT_BUNDLE;
		}

		$post_types = array( 'post', 'page' );
		if ( isset( $args['post_types'] ) && is_array( $args['post_types'] ) ) {
			$post_types = array_values(
				array_filter(
					array_map(
						static function ( $post_type ) {
							return sanitize_key( (string) $post_type );
						},
						$args['post_types']
					)
				)
			);
		}
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$limit           = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
		$limit           = min( max( 1, $limit ), self::MAX_ITEMS );
		$include_terms   = ! empty( $args['include_terms'] );
		$include_content = ! isset( $args['include_content'] ) || ! empty( $args['include_content'] );

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();

		// Resolve or create the bundle (never a protected bundle).
		$root = $manager->resolve_bundle_root( $bundle, true );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		if ( $manager->is_protected_bundle( $bundle ) ) {
			return new WP_Error(
				'okf_protected_bundle',
				__( 'The enrichment agent cannot write to an auto-generated bundle.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! is_dir( $root ) ) {
			$created = $manager->create_bundle( $bundle );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		$writer  = new WP_MCP_AI_OKF_Writer( $root );
		$summary = array(
			'bundle'   => $bundle,
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
			'concepts' => 0,
		);

		$posts = $this->collect_posts( $post_types, $limit );
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		// Build a permalink → concept map for cross-linking.
		$url_map = array();
		foreach ( $posts as $post ) {
			$url_map[ $this->normalize_url( get_permalink( $post ) ) ] = $this->concept_id_for_post( $post );
		}

		foreach ( $posts as $post ) {
			$concept_id = $this->concept_id_for_post( $post );
			$payload    = $this->build_post_concept( $post, $include_content, $url_map );
			if ( is_wp_error( $payload ) ) {
				++$summary['skipped'];
				$summary['errors'][] = $payload->get_error_message();
				continue;
			}

			$result = $writer->write_concept( $concept_id, $payload['frontmatter'], $payload['body'] );
			if ( is_wp_error( $result ) ) {
				++$summary['skipped'];
				$summary['errors'][] = $result->get_error_message();
				continue;
			}

			++$summary['created'];
			++$summary['concepts'];
		}

		if ( $include_terms ) {
			$terms = $this->collect_terms( min( $limit, 100 ) );
			foreach ( $terms as $term ) {
				$concept_id = $this->concept_id_for_term( $term );
				$payload    = $this->build_term_concept( $term );
				$result     = $writer->write_concept( $concept_id, $payload['frontmatter'], $payload['body'] );
				if ( is_wp_error( $result ) ) {
					++$summary['skipped'];
					$summary['errors'][] = $result->get_error_message();
					continue;
				}
				++$summary['created'];
				++$summary['concepts'];
			}
		}

		// Regenerate indexes (root + one per post type directory).
		$writer->regenerate_index( '' );
		foreach ( $post_types as $post_type ) {
			$writer->regenerate_index( $post_type );
		}
		if ( $include_terms ) {
			$writer->regenerate_index( 'terms' );
		}

		$manager->append_log(
			$bundle,
			'',
			sprintf(
				/* translators: %d: number of generated concepts */
				__( 'Enrichment agent generated %d concepts from site content.', 'mcp-ai-wpoos-pro' ),
				$summary['concepts']
			),
			'Creation'
		);

		return $summary;
	}

	/**
	 * Collect published posts for the requested post types.
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $limit      Maximum posts.
	 * @return WP_Post[]|WP_Error
	 */
	private function collect_posts( array $post_types, $limit ) {
		$query = new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		if ( $query->have_posts() ) {
			return $query->posts;
		}

		return new WP_Error(
			'wp_mcp_ai_okf_enrichment_no_posts',
			__( 'No published content matched the requested post types.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Collect public taxonomy terms.
	 *
	 * @param int $limit Maximum terms.
	 * @return WP_Term[]
	 */
	private function collect_terms( $limit ) {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'names' );
		$terms      = get_terms(
			array(
				'taxonomy'   => array_values( $taxonomies ),
				'hide_empty' => true,
				'number'     => $limit,
			)
		);

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * Build the concept payload for a post.
	 *
	 * @param WP_Post $post            Post object.
	 * @param bool    $include_content Whether to include the content section.
	 * @param array   $url_map         Normalized permalink → concept ID map.
	 * @return array|WP_Error Array with 'frontmatter' and 'body' keys.
	 */
	private function build_post_concept( WP_Post $post, $include_content, array $url_map ) {
		$excerpt    = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' );
		$type_label = $this->post_type_label( $post->post_type );

		/**
		 * Filter the description of an enrichment-generated concept.
		 *
		 * AI-powered consumers may replace the deterministic excerpt with an
		 * LLM summary here.
		 *
		 * @since 1.1.62
		 *
		 * @param string  $description Deterministic description (excerpt/trimmed content).
		 * @param WP_Post $post        Source post.
		 * @param string  $concept_id  Target concept ID.
		 */
		$description = apply_filters( 'wp_mcp_ai_okf_enrichment_description', $excerpt, $post, $this->concept_id_for_post( $post ) );

		$frontmatter = array(
			'type'        => $type_label,
			'title'       => $post->post_title,
			'description' => $description,
			'resource'    => get_permalink( $post ),
			'tags'        => $this->collect_post_tags( $post ),
			'status'      => 'stable',
			'generated'   => array(
				'by' => 'process:okf-enrichment',
				'at' => get_post_modified_time( 'c', true, $post ),
			),
			'sources'     => array(
				array(
					'id'            => 'wp-post-' . $post->ID,
					'resource'      => get_permalink( $post ),
					'title'         => $post->post_title,
					'last_modified' => get_post_modified_time( 'c', true, $post ),
				),
			),
		);

		$body = '# Summary' . "\n\n" . $description . "\n";

		if ( $include_content && '' !== trim( $post->post_content ) ) {
			$content = wp_trim_words( wp_strip_all_tags( $post->post_content ), self::BODY_WORD_CAP, '…' );
			$body   .= "\n# Content\n\n" . $content . "\n";
		}

		$related = $this->extract_internal_links( $post->post_content, $url_map, $post );
		if ( ! empty( $related ) ) {
			$body .= "\n# Related\n\n";
			foreach ( $related as $link ) {
				$body .= '* [' . $link['title'] . '](' . $link['target'] . ')' . "\n";
			}
		}

		return array(
			'frontmatter' => $frontmatter,
			'body'        => trim( $body ),
		);
	}

	/**
	 * Build the concept payload for a term.
	 *
	 * @param WP_Term $term Term object.
	 * @return array Array with 'frontmatter' and 'body' keys.
	 */
	private function build_term_concept( WP_Term $term ) {
		$taxonomy = get_taxonomy( $term->taxonomy );
		$label    = $taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;

		$frontmatter = array(
			'type'        => 'Term',
			'title'       => $term->name,
			'description' => $term->description ? wp_trim_words( wp_strip_all_tags( $term->description ), 40, '…' ) : '',
			'resource'    => get_term_link( $term ),
			'tags'        => array( $term->taxonomy ),
			'status'      => 'stable',
			'generated'   => array(
				'by' => 'process:okf-enrichment',
				'at' => gmdate( 'c' ),
			),
			'sources'     => array(
				array(
					'id'       => 'wp-term-' . $term->term_id,
					'resource' => get_term_link( $term ),
					'title'    => $term->name . ' (' . $label . ')',
				),
			),
		);

		$body = '# Summary' . "\n\n" . sprintf(
			/* translators: 1: term name, 2: taxonomy label, 3: post count */
			__( '%1$s (%2$s) — %3$d published item(s).', 'mcp-ai-wpoos-pro' ),
			$term->name,
			$label,
			(int) $term->count
		);

		return array(
			'frontmatter' => $frontmatter,
			'body'        => $body,
		);
	}

	/**
	 * Extract internal links from post content that point at crawled posts.
	 *
	 * @param string  $content Raw post content.
	 * @param array   $url_map Normalized permalink → concept ID map.
	 * @param WP_Post $post   The source post (excluded from its own links).
	 * @return array<int, array{title: string, target: string}>
	 */
	private function extract_internal_links( $content, array $url_map, WP_Post $post ) {
		$related = array();
		$seen    = array();

		if ( ! preg_match_all( '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return $related;
		}

		$count = 0;
		foreach ( $matches as $match ) {
			if ( $count >= self::MAX_LINKS ) {
				break;
			}

			$target = $this->normalize_url( $match[1] );
			if ( ! isset( $url_map[ $target ] ) ) {
				continue;
			}

			$concept_target = $url_map[ $target ];
			if ( $concept_target === $this->concept_id_for_post( $post ) ) {
				continue; // Self-link.
			}
			if ( isset( $seen[ $concept_target ] ) ) {
				continue;
			}

			$seen[ $concept_target ] = true;
			$title                   = trim( wp_strip_all_tags( $match[2] ) );
			$related[]               = array(
				'title'  => '' !== $title ? $title : $concept_target,
				'target' => '/' . $concept_target . '.md', // Bundle-relative absolute (OKF v0.2 §6.1).
			);
			++$count;
		}

		return $related;
	}

	/**
	 * Concept ID for a post: post_type/post_slug.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function concept_id_for_post( WP_Post $post ) {
		$slug = sanitize_title( $post->post_name ? $post->post_name : $post->post_title );
		if ( '' === $slug ) {
			$slug = 'item-' . $post->ID;
		}

		return $post->post_type . '/' . $slug;
	}

	/**
	 * Concept ID for a term: terms/taxonomy/slug.
	 *
	 * @param WP_Term $term Term object.
	 * @return string
	 */
	private function concept_id_for_term( WP_Term $term ) {
		return 'terms/' . $term->taxonomy . '/' . $term->slug;
	}

	/**
	 * Collect the post's public-taxonomy term names as tags.
	 *
	 * @param WP_Post $post Post object.
	 * @return string[]
	 */
	private function collect_post_tags( WP_Post $post ) {
		$taxonomies = get_object_taxonomies( $post->post_type, 'names' );
		$taxonomies = array_values(
			array_filter(
				$taxonomies,
				static function ( $taxonomy ) {
					return 'post_format' !== $taxonomy;
				}
			)
		);

		$terms = wp_get_object_terms( $post->ID, $taxonomies );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$names = array();
		foreach ( $terms as $term ) {
			$names[] = $term->name;
			if ( count( $names ) >= 10 ) {
				break;
			}
		}

		return $names;
	}

	/**
	 * Human-readable post type label.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function post_type_label( $post_type ) {
		$object = get_post_type_object( $post_type );
		if ( $object && ! empty( $object->labels->singular_name ) ) {
			return $object->labels->singular_name;
		}

		return ucfirst( $post_type );
	}

	/**
	 * Normalize a URL for comparison (scheme-insensitive, no trailing slash).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$url = preg_replace( '#^https?://#i', '', trim( $url ) );
		$url = preg_replace( '/#.*$/', '', $url );

		return untrailingslashit( $url );
	}
}
