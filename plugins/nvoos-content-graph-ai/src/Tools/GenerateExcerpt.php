<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Chat\ChatService;

/**
 * Generate a post excerpt using AI.
 */
class GenerateExcerpt extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_generate_post_excerpt'; }
	public function getName(): string {
		return __( 'Generate Post Excerpt', 'nvoos-content-graph-ai' ); }
	public function getDescription(): string {
		return __( 'Generate a compelling excerpt for a WordPress post using AI.', 'nvoos-content-graph-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'        => 'string',
					'description' => 'The post content to summarize.',
				),
				'post_id' => array(
					'type'        => 'integer',
					'description' => 'Post ID to attach excerpt to.',
				),
			),
			'required'   => array( 'content' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$content = sanitize_textarea_field( $arguments['content'] ?? '' );
		$postId  = absint( $arguments['post_id'] ?? 0 );
		if ( empty( $content ) ) {
			return new \WP_Error( 'nvoos_content_graph_ai', __( 'Content is required.', 'nvoos-content-graph-ai' ) );
		}

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Generate a concise excerpt (1-2 sentences) for the following content.',
			),
			array(
				'role'    => 'user',
				'content' => $content,
			),
		);
		$result   = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$excerpt = trim( $result['content'] ?? '' );
		if ( $postId && ! empty( $excerpt ) ) {
			wp_update_post(
				array(
					'ID'           => $postId,
					'post_excerpt' => $excerpt,
				)
			);
		}

		return array(
			'success' => true,
			'excerpt' => $excerpt,
			'post_id' => $postId,
		);
	}
}
