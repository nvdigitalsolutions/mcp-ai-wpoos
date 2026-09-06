<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Sections;

use NvoosContentGraphAi\Admin\Settings\AiSection;
use NvoosContentGraphAi\Admin\Settings\SettingsValidator;

/**
 * Provider Selection section for the AI Providers tab.
 *
 * Allows enabling/disabling AI chat and selecting the default
 * AI provider and model.
 *
 * @since 1.0.0
 */
class ProviderSelection extends AiSection {

	public function get_id(): string {
		return 'ai_provider_selection';
	}

	public function get_title(): string {
		return __( 'Provider Selection', 'nvoos-content-graph-ai' );
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
				'label'       => __( 'Enable AI Chat', 'nvoos-content-graph-ai' ),
				'description' => __( 'Enable the AI chat assistant for the knowledge graph.', 'nvoos-content-graph-ai' ),
			),
			'ai_default_provider' => array(
				'type'        => 'select',
				'label'       => __( 'Default AI Provider', 'nvoos-content-graph-ai' ),
				'description' => __( 'Select the default AI provider for chat and AI tools.', 'nvoos-content-graph-ai' ),
				'options'     => array(
					'openai'       => 'OpenAI',
					'gemini'       => 'Google Gemini',
					'anthropic'    => 'Anthropic Claude',
					'ollama'       => 'Ollama (Local)',
					'deepseek'     => 'DeepSeek',
					'openrouter'   => 'OpenRouter',
					'huggingface'  => 'Hugging Face',
					'cloudflare'   => 'Cloudflare Workers AI',
					'lm_studio'    => 'LM Studio (Local)',
					'nvidia_nim'   => 'NVIDIA NIM',
					'digitalocean' => 'DigitalOcean',
					'kimi'         => 'Kimi (Moonshot)',
					'baseten'      => 'Baseten',
				),
				'default'     => 'openai',
			),
			'ai_default_model'    => array(
				'type'        => 'text',
				'label'       => __( 'Default Model', 'nvoos-content-graph-ai' ),
				'description' => __( 'Model identifier for the selected provider (e.g. gpt-4o, gemini-2.0-flash).', 'nvoos-content-graph-ai' ),
				'default'     => 'gpt-4o',
			),
		);
	}

	/**
	 * Validate the provider-selection fields.
	 *
	 * A default model is required when chat is enabled; the provider
	 * value is constrained by the select field's sanitization (parent
	 * contract), but an enum check is kept for completeness.
	 *
	 * @param array<string,mixed> $input Raw submitted values.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function validate( array $input ) {
		if ( ! empty( $input['ai_chat_enabled'] ) && empty( $input['ai_default_model'] ) ) {
			return new \WP_Error(
				'required_default_model',
				__( 'A default model is required when AI chat is enabled.', 'nvoos-content-graph-ai' )
			);
		}

		if ( ! empty( $input['ai_default_provider'] ) ) {
			$allowed = array_keys( $this->get_fields()['ai_default_provider']['options'] );
			$checked = SettingsValidator::validate_enum( $input['ai_default_provider'], $allowed );
			if ( is_wp_error( $checked ) ) {
				return new \WP_Error( 'invalid_provider', __( 'The selected AI provider is not available.', 'nvoos-content-graph-ai' ) );
			}
		}

		return $input;
	}
}
