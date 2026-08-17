<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Chat\ChatService;

/**
 * Auto-categorize content using AI.
 */
class CategorizeContent extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_categorize_content'; }
	public function getName(): string {
		return __( 'Categorize Content', 'nvoos-content-graph-ai' ); }
	public function getDescription(): string {
		return __( 'Auto-categorize WordPress content using AI. Assigns categories and tags based on content analysis.', 'nvoos-content-graph-ai' );
	}
	public function getCapabilityFlags(): array {
		return array( 'external-api', 'modifies-state' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post ID to categorize.',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'Content to categorize (alternative to post_id).',
				),
			),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$postId  = absint( $arguments['post_id'] ?? 0 );
		$content = sanitize_textarea_field( $arguments['content'] ?? '' );

		if ( $postId ) {
			$post = get_post( $postId );
			if ( ! $post ) {
				return new \WP_Error( 'nvoos_content_graph_ai', __( 'Post not found.', 'nvoos-content-graph-ai' ) );
			}
			$content = $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content );
		}

		if ( empty( $content ) ) {
			return new \WP_Error( 'nvoos_content_graph_ai', __( 'Content is required.', 'nvoos-content-graph-ai' ) );
		}

		// Get existing categories for context.
		$categories = get_categories(
			array(
				'hide_empty' => false,
				'number'     => 20,
			)
		);
		$catList    = implode( ', ', wp_list_pluck( $categories, 'name' ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => "Analyze this content and suggest WordPress categories and tags. Available categories: {$catList}. Return ONLY a JSON object with 'categories' (array of matching category names) and 'tags' (array of 5-10 relevant tag strings).",
			),
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$resp    = trim( $result['content'] ?? '' );
		$resp    = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $resp );
		$data    = json_decode( $resp, true );
		$applied = array(
			'categories' => array(),
			'tags'       => array(),
		);

		if ( is_array( $data ) && $postId ) {
			if ( ! empty( $data['categories'] ) ) {
				$catIds = array();
				foreach ( $data['categories'] as $catName ) {
					$term = term_exists( $catName, 'category' );
					if ( ! $term ) {
						$term = wp_insert_term( $catName, 'category' );
					}
					if ( ! is_wp_error( $term ) ) {
						$catIds[] = is_array( $term ) ? $term['term_id'] : $term;
					}
				}
				if ( ! empty( $catIds ) ) {
					wp_set_post_categories( $postId, $catIds, true );
					$applied['categories'] = $data['categories'];
				}
			}

			if ( ! empty( $data['tags'] ) ) {
				$applied['tags'] = $data['tags'];
				wp_set_post_tags( $postId, $data['tags'], true );
			}
		}

		return array(
			'success' => true,
			'applied' => $applied,
			'post_id' => $postId,
		);
	}
}
