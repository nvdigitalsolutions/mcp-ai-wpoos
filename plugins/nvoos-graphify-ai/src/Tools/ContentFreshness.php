<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Chat\ChatService;

/**
 * AI-powered content freshness checker.
 */
class ContentFreshness extends AbstractAiTool {
	public function getSlug(): string { return 'ai_content_freshness'; }
	public function getName(): string { return __( 'Content Freshness Check', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Analyze content freshness and suggest updates. Identifies outdated information and recommends improvements.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'description' => 'Post ID to check.' ),
				'content' => array( 'type' => 'string', 'description' => 'Content to check (alternative).' ),
			),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$postId  = absint( $arguments['post_id'] ?? 0 );
		$content = sanitize_textarea_field( $arguments['content'] ?? '' );
		$postDate = '';

		if ( $postId ) {
			$post = get_post( $postId );
			if ( ! $post ) {
				return new \WP_Error( 'nvoos_graphify_ai', __( 'Post not found.', 'nvoos-graphify-ai' ) );
			}
			$content  = $post->post_title . "\n\n" . wp_strip_all_tags( $post->post_content );
			$postDate = $post->post_date;
		}

		if ( empty( $content ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Content is required.', 'nvoos-graphify-ai' ) );
		}

		$dateInfo = $postDate ? "Published: {$postDate}\n\n" : '';

		$messages = array(
			array( 'role' => 'system', 'content' => "Analyze this content for freshness. {$dateInfo}Identify outdated information, suggest updates, and rate freshness on a scale of 1-10. Return as JSON: {\"score\": number, \"outdated\": [string], \"suggestions\": [string]}" ),
			array( 'role' => 'user', 'content' => $content ),
		);

		$result = ChatService::process( $messages );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$resp = trim( $result['content'] ?? '' );
		$resp = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $resp );
		$analysis = json_decode( $resp, true );

		return array( 'success' => true, 'analysis' => $analysis ?: array( 'raw' => $resp ), 'post_id' => $postId );
	}
}
