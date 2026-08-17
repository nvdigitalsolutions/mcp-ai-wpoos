<?php
declare(strict_types=1);

namespace NvoosContentGraph\Frontend;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Settings;

/**
 * Schema.org JSON-LD injection for SEO.
 *
 * Injects structured data into the page head using graph
 * relationships: taxonomy terms as `about` and internal
 * links as `relatedLink`.
 *
 * @since 1.0.0
 */
class SchemaOrg {

	/**
	 * Register the `wp_head` hook.
	 *
	 * @return void
	 */
	public function register(): void {
		$allSettings = Settings::all();
		if ( ! empty( $allSettings['schema_injection'] ) ) {
			add_action( 'wp_head', array( $this, 'inject' ) );
		}
	}

	/**
	 * Inject Schema.org JSON-LD for the current singular view.
	 *
	 * @return void
	 */
	public function inject(): void {
		if ( ! is_singular() ) {
			return;
		}

		$postId = get_the_ID();
		if ( ! $postId ) {
			return;
		}

		$node = Db::getNodeByPostId( $postId );
		if ( ! $node ) {
			return;
		}

		$edges = Db::getEdgesForNode( $node->node_id );

		$about        = array();
		$relatedLinks = array();

		foreach ( $edges as $edge ) {
			if ( in_array( $edge->relation, array( 'CATEGORIZED_BY', 'TAGGED_WITH' ), true ) ) {
				$targetNode = Db::getNode( $edge->target_node_id );
				if ( $targetNode ) {
					$about[] = array(
						'@type' => 'Thing',
						'name'  => wp_strip_all_tags( $targetNode->label ),
						'url'   => esc_url( $targetNode->url ),
					);
				}
			}

			if ( 'LINKS_TO' === $edge->relation && $edge->source_node_id === $node->node_id ) {
				$targetNode = Db::getNode( $edge->target_node_id );
				if ( $targetNode && $targetNode->url ) {
					$relatedLinks[] = esc_url( $targetNode->url );
				}
			}
		}

		if ( empty( $about ) && empty( $relatedLinks ) ) {
			return;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'url'      => esc_url( get_permalink( $postId ) ),
		);

		if ( $about ) {
			$schema['about'] = $about;
		}
		if ( $relatedLinks ) {
			$schema['relatedLink'] = $relatedLinks;
		}

		// Encode with JSON_HEX_TAG et al. so that no raw '<' or '>' can appear in
		// the payload and terminate the script element early (prevents XSS via
		// a crafted '</script>' sequence inside a node label or URL).
		$jsonLd = wp_json_encode(
			$schema,
			JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() with JSON_HEX_TAG output; contains no '<', '>', or '&' characters.
		echo '<script type="application/ld+json">' . $jsonLd . "</script>\n";
	}
}
