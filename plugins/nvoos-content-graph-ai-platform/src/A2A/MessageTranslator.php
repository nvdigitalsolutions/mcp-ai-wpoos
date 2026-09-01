<?php
/**
 * A2A Message Translator.
 *
 * Translates between A2A protocol message formats and the internal
 * chat message format used by the chat pipeline.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-message-translator.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bidirectional message translator between A2A and chat formats.
 */
class MessageTranslator {

	/**
	 * Convert an A2A SendMessageRequest to chat messages.
	 *
	 * @param array $a2a_request The A2A SendMessageRequest data.
	 * @return array {
	 *     @type array  $messages   Array of chat messages.
	 *     @type string $context_id The context ID from the request.
	 *     @type string $task_id    The task ID from the request (if continuing).
	 * }
	 */
	public static function a2a_to_chat( $a2a_request ) {
		$a2a_message = isset( $a2a_request['message'] ) ? $a2a_request['message'] : array();
		$context_id  = isset( $a2a_message['contextId'] ) ? sanitize_text_field( $a2a_message['contextId'] ) : '';
		$task_id     = isset( $a2a_message['taskId'] ) ? sanitize_text_field( $a2a_message['taskId'] ) : '';

		// Map A2A role to chat role.
		$a2a_role = isset( $a2a_message['role'] ) ? strtolower( $a2a_message['role'] ) : 'user';
		$role     = self::map_a2a_role_to_chat( $a2a_role );

		// Extract text content from A2A Parts.
		$parts   = isset( $a2a_message['parts'] ) ? $a2a_message['parts'] : array();
		$content = self::extract_text_from_parts( $parts );

		$chat_messages = array(
			array(
				'role'    => $role,
				'content' => $content,
			),
		);

		return array(
			'messages'   => $chat_messages,
			'context_id' => $context_id,
			'task_id'    => $task_id,
		);
	}

	/**
	 * Convert a chat response to an A2A Message object.
	 *
	 * @param string $content    The response content text.
	 * @param string $context_id The A2A context ID.
	 * @param array  $metadata   Optional metadata to include.
	 * @return array A2A Message object.
	 */
	public static function chat_to_a2a_message( $content, $context_id = '', $metadata = array() ) {
		$message = array(
			'kind'      => 'message',
			'messageId' => wp_generate_uuid4(),
			'role'      => 'agent',
			'parts'     => array(
				array(
					'kind' => 'text',
					'text' => $content,
				),
			),
		);

		if ( ! empty( $context_id ) ) {
			$message['contextId'] = $context_id;
		}

		if ( ! empty( $metadata ) ) {
			$message['metadata'] = $metadata;
		}

		return $message;
	}

	/**
	 * Convert a chat response to an A2A Artifact.
	 *
	 * @param string $content     The artifact content.
	 * @param string $name        The artifact name.
	 * @param string $description The artifact description.
	 * @param string $mime_type   The content MIME type.
	 * @return array A2A Artifact object.
	 */
	public static function chat_to_a2a_artifact( $content, $name = '', $description = '', $mime_type = 'text/plain' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future use.
		$artifact = array(
			'artifactId' => wp_generate_uuid4(),
			'parts'      => array(
				array(
					'kind' => 'text',
					'text' => $content,
				),
			),
		);

		if ( ! empty( $name ) ) {
			$artifact['name'] = $name;
		}

		if ( ! empty( $description ) ) {
			$artifact['description'] = $description;
		}

		return $artifact;
	}

	/**
	 * Convert a tool call result to an A2A Artifact.
	 *
	 * @param array  $tool_result The tool execution result.
	 * @param string $tool_slug   The tool slug.
	 * @return array A2A Artifact object.
	 */
	public static function tool_result_to_artifact( $tool_result, $tool_slug = '' ) {
		$content = '';

		if ( is_string( $tool_result ) ) {
			$content = $tool_result;
		} elseif ( is_array( $tool_result ) ) {
			$content = wp_json_encode( $tool_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		return self::chat_to_a2a_artifact(
			$content,
			$tool_slug ? sprintf( 'Tool Result: %s', $tool_slug ) : 'Tool Result',
			'',
			is_array( $tool_result ) ? 'application/json' : 'text/plain'
		);
	}

	/**
	 * Build an A2A status update event.
	 *
	 * @param string     $task_id    The task ID.
	 * @param string     $context_id The context ID.
	 * @param string     $state      The new state.
	 * @param array|null $message    Optional status message.
	 * @param bool       $is_final   Whether this is the final event.
	 * @return array TaskStatusUpdateEvent object.
	 */
	public static function build_status_update( $task_id, $context_id, $state, $message = null, $is_final = false ) {
		$event = array(
			'kind'      => 'status-update',
			'taskId'    => $task_id,
			'contextId' => $context_id,
			'status'    => array(
				'state'     => $state,
				'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			'final'     => $is_final,
		);

		if ( $message ) {
			$event['status']['message'] = $message;
		}

		return $event;
	}

	/**
	 * Build an A2A artifact update event.
	 *
	 * @param string $task_id    The task ID.
	 * @param string $context_id The context ID.
	 * @param array  $artifact   The artifact data.
	 * @return array TaskArtifactUpdateEvent object.
	 */
	public static function build_artifact_update( $task_id, $context_id, $artifact ) {
		return array(
			'kind'      => 'artifact-update',
			'taskId'    => $task_id,
			'contextId' => $context_id,
			'artifact'  => $artifact,
		);
	}

	/**
	 * Extract text content from A2A Parts array.
	 *
	 * @param array $parts Array of A2A Part objects.
	 * @return string Combined text content.
	 */
	protected static function extract_text_from_parts( $parts ) {
		$texts = array();

		foreach ( $parts as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			$kind = isset( $part['kind'] ) ? $part['kind'] : '';

			switch ( $kind ) {
				case 'text':
					if ( isset( $part['text'] ) ) {
						$texts[] = sanitize_textarea_field( $part['text'] );
					}
					break;

				case 'data':
					// Structured data — include as JSON in the text.
					if ( isset( $part['data'] ) ) {
						$texts[] = wp_json_encode( $part['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					}
					break;

				case 'file':
					// File references — note them in text.
					if ( isset( $part['file']['name'] ) ) {
						$texts[] = sprintf( '[File: %s]', sanitize_text_field( $part['file']['name'] ) );
					}
					break;
			}
		}

		return implode( "\n\n", $texts );
	}

	/**
	 * Map A2A role to chat role.
	 *
	 * @param string $a2a_role The A2A role (user, agent).
	 * @return string The chat role.
	 */
	protected static function map_a2a_role_to_chat( $a2a_role ) {
		$map = array(
			'user'       => 'user',
			'role_user'  => 'user',
			'agent'      => 'assistant',
			'role_agent' => 'assistant',
		);

		return isset( $map[ $a2a_role ] ) ? $map[ $a2a_role ] : 'user';
	}

	/**
	 * Map chat role to A2A role.
	 *
	 * @param string $chat_role The chat role.
	 * @return string The A2A role.
	 */
	public static function map_chat_role_to_a2a( $chat_role ) {
		$map = array(
			'user'      => 'user',
			'assistant' => 'agent',
			'system'    => 'agent',
		);

		return isset( $map[ $chat_role ] ) ? $map[ $chat_role ] : 'user';
	}
}
