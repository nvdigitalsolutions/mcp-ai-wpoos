<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Chat Settings section for the Chat Settings tab.
 *
 * Controls temperature (creativity), max output tokens,
 * and other chat behaviour knobs.
 *
 * @since 1.0.0
 */
class ChatSettings extends Section {

	public function get_id(): string {
		return 'ai_chat_settings';
	}

	public function get_title(): string {
		return __( 'Chat Behavior', 'nvoos-graphify-ai' );
	}

	public function get_tab(): string {
		return 'ai_chat';
	}

	public function get_priority(): int {
		return 10;
	}

	public function get_fields(): array {
		return array(
			'ai_temperature' => array(
				'type'        => 'text',
				'label'       => __( 'Temperature', 'nvoos-graphify-ai' ),
				'description' => __( 'Controls randomness (0–2). Lower = more deterministic, higher = more creative.', 'nvoos-graphify-ai' ),
				'default'     => '0.7',
			),
			'ai_max_tokens'  => array(
				'type'        => 'number',
				'label'       => __( 'Max Tokens', 'nvoos-graphify-ai' ),
				'description' => __( 'Maximum output tokens per response.', 'nvoos-graphify-ai' ),
				'min'         => 1,
				'max'         => 128000,
				'default'     => 4096,
			),
		);
	}
}
