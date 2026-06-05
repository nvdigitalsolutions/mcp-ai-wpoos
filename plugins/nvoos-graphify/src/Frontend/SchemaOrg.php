<?php
declare(strict_types=1);

namespace NvoosGraphify\Frontend;

use NvoosGraphify\Graph\Db;
use NvoosGraphify\Settings;

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
						'name'  => esc_html( $targetNode->label ),
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

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
	}
}
