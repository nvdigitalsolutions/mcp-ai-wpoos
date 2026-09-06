<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraphAi\Admin\Settings\AiSection;
use NvoosContentGraphAi\Admin\Settings\SettingsValidator;

/**
 * Chat Settings section for the Chat Settings tab.
 *
 * Controls temperature (creativity), max output tokens,
 * and other chat behaviour knobs.
 *
 * @since 1.0.0
 */
class ChatSettings extends AiSection {

	public function get_id(): string {
		return 'ai_chat_settings';
	}

	public function get_title(): string {
		return __( 'Chat Behavior', 'nvoos-content-graph-ai' );
	}

	public function get_tab(): string {
		return 'ai_chat';
	}

	public function get_priority(): int {
		return 10;
	}

	public function get_fields(): array {
		return array(
			'ai_temperature'   => array(
				'type'        => 'text',
				'label'       => __( 'Temperature', 'nvoos-content-graph-ai' ),
				'description' => __( 'Controls randomness (0–2). Lower = more deterministic, higher = more creative.', 'nvoos-content-graph-ai' ),
				'default'     => '0.7',
			),
			'ai_max_tokens'    => array(
				'type'        => 'number',
				'label'       => __( 'Max Tokens', 'nvoos-content-graph-ai' ),
				'description' => __( 'Maximum output tokens per response.', 'nvoos-content-graph-ai' ),
				'min'         => 1,
				'max'         => 128000,
				'default'     => 4096,
			),
			'ai_system_prompt' => array(
				'type'        => 'textarea',
				'label'       => __( 'System Prompt', 'nvoos-content-graph-ai' ),
				'description' => __( 'Prepended to every chat request in the Chat Tester. Leave empty to send no system prompt.', 'nvoos-content-graph-ai' ),
				'rows'        => 8,
				'default'     => 'You are a helpful assistant for the NV oOS Content Graph on this WordPress site. Answer questions about the site content and its knowledge graph accurately and concisely. When tools for querying the graph are provided, use them to ground your answers in real data instead of guessing. Cite nodes, posts, or relationships when relevant. If you do not know something or the data is unavailable, say so plainly. Format answers with Markdown.',
			),
		);
	}

	/**
	 * Validate the chat behaviour fields (temperature range, token range).
	 *
	 * @param array<string,mixed> $input Raw submitted values.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( array $input ) {
		if ( isset( $input['ai_temperature'] ) && '' !== (string) $input['ai_temperature'] ) {
			$checked = SettingsValidator::validate_number( $input['ai_temperature'], 0, 2 );
			if ( is_wp_error( $checked ) ) {
				return new \WP_Error( 'invalid_temperature', __( 'Temperature must be a number between 0 and 2.', 'nvoos-content-graph-ai' ) );
			}
		}

		if ( isset( $input['ai_max_tokens'] ) && '' !== (string) $input['ai_max_tokens'] ) {
			$checked = SettingsValidator::validate_number( $input['ai_max_tokens'], 1, 128000 );
			if ( is_wp_error( $checked ) ) {
				return new \WP_Error( 'invalid_max_tokens', __( 'Max Tokens must be a number between 1 and 128000.', 'nvoos-content-graph-ai' ) );
			}
		}

		return $input;
	}
}
