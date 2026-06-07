<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * Provider Selection section for the AI Providers tab.
 *
 * Allows enabling/disabling AI chat and selecting the default
 * AI provider and model.
 *
 * @since 1.0.0
 */
class ProviderSelection extends Section {

	public function get_id(): string {
		return 'ai_provider_selection';
	}

	public function get_title(): string {
		return __( 'Provider Selection', 'nvoos-graphify-ai' );
	}

	public function get_tab(): string {
		return 'ai_providers';
	}

	public function get_priority(): int {
		return 10;
	}

	public function get_fields(): array {
		return array(
			'ai_chat_enabled'     => array(
				'type'        => 'checkbox',
				'label'       => __( 'Enable AI Chat', 'nvoos-graphify-ai' ),
				'description' => __( 'Enable the AI chat assistant for the knowledge graph.', 'nvoos-graphify-ai' ),
			),
			'ai_default_provider' => array(
				'type'        => 'select',
				'label'       => __( 'Default AI Provider', 'nvoos-graphify-ai' ),
				'description' => __( 'Select the default AI provider for chat and AI tools.', 'nvoos-graphify-ai' ),
				'options'     => array(
					'openai'      => 'OpenAI',
					'gemini'      => 'Google Gemini',
					'anthropic'   => 'Anthropic Claude',
					'ollama'      => 'Ollama (Local)',
					'deepseek'    => 'DeepSeek',
					'openrouter'  => 'OpenRouter',
					'huggingface' => 'Hugging Face',
					'cloudflare'  => 'Cloudflare Workers AI',
					'lm_studio'   => 'LM Studio (Local)',
					'nvidia_nim'  => 'NVIDIA NIM',
					'digitalocean' => 'DigitalOcean',
					'kimi'        => 'Kimi (Moonshot)',
					'baseten'     => 'Baseten',
				),
				'default'     => 'openai',
			),
			'ai_default_model'    => array(
				'type'        => 'text',
				'label'       => __( 'Default Model', 'nvoos-graphify-ai' ),
				'description' => __( 'Model identifier for the selected provider (e.g. gpt-4o, gemini-2.0-flash).', 'nvoos-graphify-ai' ),
				'default'     => 'gpt-4o',
			),
		);
	}
}
