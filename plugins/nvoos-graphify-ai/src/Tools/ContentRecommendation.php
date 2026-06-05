<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Chat\ChatService;

/**
 * AI-powered content recommendation engine.
 */
class ContentRecommendation extends AbstractAiTool {
	public function getSlug(): string { return 'ai_content_recommendation'; }
	public function getName(): string { return __( 'Content Recommendation', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Get AI-powered content recommendations. Analyzes your content and suggests related topics, gaps, and opportunities.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'topic'   => array( 'type' => 'string', 'description' => 'Topic to get recommendations for.' ),
				'post_id' => array( 'type' => 'integer', 'description' => 'Post ID to analyze.' ),
				'count'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5 ),
			),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$topic  = sanitize_text_field( $arguments['topic'] ?? '' );
		$postId = absint( $arguments['post_id'] ?? 0 );
		$count  = absint( $arguments['count'] ?? 5 );

		if ( $postId ) {
			$post = get_post( $postId );
			if ( $post ) {
				$topic = $post->post_title . ': ' . wp_strip_all_tags( $post->post_content );
			}
		}

		if ( empty( $topic ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Topic or post_id is required.', 'nvoos-graphify-ai' ) );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => "You are a content strategist. Based on the given content, suggest {$count} specific related content topics. Return ONLY a JSON array of strings." ),
			array( 'role' => 'user', 'content' => $topic ),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$content = trim( $result['content'] ?? '' );
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $content );
		$topics  = json_decode( $content, true );

		return array(
			'success'  => true,
			'topics'   => is_array( $topics ) ? $topics : array(),
			'analyzed' => $topic,
		);
	}
}
