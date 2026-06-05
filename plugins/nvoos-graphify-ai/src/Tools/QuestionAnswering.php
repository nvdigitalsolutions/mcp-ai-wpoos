<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Tools;

use NvoosGraphifyAi\Plugin;
use NvoosGraphifyAi\Chat\ChatService;

/**
 * AI-powered text completion / question answering tool.
 */
class QuestionAnswering extends AbstractAiTool {
	public function getSlug(): string {
		return 'ai_question_answering'; }
	public function getName(): string {
		return __( 'AI Question Answering', 'nvoos-graphify-ai' ); }
	public function getDescription(): string {
		return __( 'Answer questions using AI. Provide context and a question to get an AI-generated answer.', 'nvoos-graphify-ai' );
	}
	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'question' => array(
					'type'        => 'string',
					'description' => 'The question to answer.',
				),
				'context'  => array(
					'type'        => 'string',
					'description' => 'Optional context to ground the answer.',
				),
			),
			'required'   => array( 'question' ),
		);
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$question = sanitize_text_field( $arguments['question'] ?? '' );
		$ctx      = sanitize_textarea_field( $arguments['context'] ?? '' );
		if ( empty( $question ) ) {
			return new \WP_Error( 'nvoos_graphify_ai', __( 'Question is required.', 'nvoos-graphify-ai' ) );
		}

		$prompt = $question;
		if ( ! empty( $ctx ) ) {
			$prompt = "Context:\n{$ctx}\n\nQuestion: {$question}";
		}

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $prompt,
			),
		);
		$result   = ChatService::process( $messages );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'answer'  => $result['content'] ?? '',
			'model'   => $result['model'] ?? '',
		);
	}
}
