<?php
declare(strict_types=1);

namespace NvoosContentGraph\Frontend;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Settings;
use function absint;
use function array_slice;
use function esc_html;
use function esc_url;
use function get_the_ID;
use function in_the_loop;
use function is_main_query;
use function is_singular;
use function max;
use function min;

/**
 * Related content widget.
 *
 * Appends top-N graph-neighbor posts to singular content
 * based on knowledge graph proximity.
 *
 * @since 1.0.0
 */
class RelatedContent {

	/**
	 * Register the `the_content` filter.
	 *
	 * @return void
	 */
	public function register(): void {
		$allSettings = Settings::all();
		if ( ! empty( $allSettings['related_content'] ) ) {
			add_filter( 'the_content', array( $this, 'append' ) );
		}
	}

	/**
	 * Append top-N graph-neighbor posts to singular content.
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public function append( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$postId = get_the_ID();
		if ( ! $postId ) {
			return $content;
		}

		// Only append to the main queried post — not to synthetic
		// `apply_filters( 'the_content', … )` calls made inside the
		// main loop by shortcodes, blocks, or other plugins.
		$queriedId = get_queried_object_id();
		if ( $postId !== $queriedId ) {
			return $content;
		}

		$node = Db::getNodeByPostId( $postId );
		if ( ! $node ) {
			return $content;
		}

		$allSettings = Settings::all();
		$maxRelated  = max( 1, min( 10, absint( $allSettings['max_related'] ?? 5 ) ) );

		$neighborIds = Db::getNeighborIds( $node->node_id );
		if ( empty( $neighborIds ) ) {
			return $content;
		}

		$neighbors = array_slice( $neighborIds, 0, $maxRelated );
		$postNodes = array();

		foreach ( $neighbors as $nid ) {
			$n = Db::getNode( $nid );
			if ( $n && $n->post_id && $n->url ) {
				$postNodes[] = $n;
			}
		}

		if ( empty( $postNodes ) ) {
			return $content;
		}

		$widget  = '<div class="nvoos-content-graph-related">';
		$widget .= '<h3>' . esc_html__( 'Related Content', 'nvoos-content-graph' ) . '</h3><ul>';
		foreach ( $postNodes as $n ) {
			$widget .= '<li><a href="' . esc_url( $n->url ) . '">' . esc_html( $n->label ) . '</a></li>';
		}
		$widget .= '</ul></div>';

		// Remove the filter so that nested `apply_filters( 'the_content', … )`
		// calls (e.g. from shortcodes rendered inside this same content)
		// cannot leak the widget into other page sections.
		remove_filter( 'the_content', array( $this, 'append' ) );

		return $content . $widget;
	}
}
