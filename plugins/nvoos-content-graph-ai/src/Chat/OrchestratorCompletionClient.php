<?php
/**
 * Orchestrator completion client — adapts the nvoos/core ChatOrchestrator
 * to the `create_chat_completion()` contract the conversation summarizer
 * expects (the base plugin's language-model-router shape).
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

use Nvoos\Core\Application\Chat\ChatOrchestrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter exposing the orchestrator as a chat-completion client.
 *
 * @since 1.1.0
 */
class OrchestratorCompletionClient {

	/**
	 * Orchestrator.
	 *
	 * @var ChatOrchestrator
	 */
	private $chat;

	/**
	 * Constructor.
	 *
	 * @param ChatOrchestrator $chat Chat orchestrator from CoreBridge.
	 */
	public function __construct( ChatOrchestrator $chat ) {
		$this->chat = $chat;
	}

	/**
	 * Run a single chat completion and return the base plugin's result
	 * shape (`choices[0].message.content`) or a WP_Error on failure.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options (provider/model/max_tokens/…).
	 * @return array|\WP_Error Completion result or error.
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$result   = $this->chat->handleChat( $messages, array(), 0, 0, $options );
		$response = $result['response'] ?? array();

		// The orchestrator returns normalized errors as arrays carrying the
		// same `code`/`message` keys the base plugin's error envelope uses.
		if ( is_array( $response ) && isset( $response['code'], $response['message'] ) ) {
			return new \WP_Error( (string) $response['code'], (string) $response['message'] );
		}

		$content = '';
		if ( is_array( $response ) ) {
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$content = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['message']['content'] ) ) {
				$content = $response['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				$content = $response['content'];
			}
		}

		return array(
			'choices' => array(
				array(
					'message' => array( 'content' => (string) $content ),
				),
			),
		);
	}
}
