<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraph\Admin\Section;

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
}
