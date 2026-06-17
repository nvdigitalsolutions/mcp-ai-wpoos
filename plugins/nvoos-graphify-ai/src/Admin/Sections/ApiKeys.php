<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Admin\Sections;

use NvoosGraphify\Admin\Section;

/**
 * API Keys section for the AI Providers tab.
 *
 * Stores API keys, base URLs, account IDs, and model
 * overrides for all 13 supported AI providers.
 *
 * @since 1.0.0
 */
class ApiKeys extends Section {

	public function get_id(): string {
		return 'ai_api_keys';
	}

	public function get_title(): string {
		return __( 'API Keys & Endpoints', 'nvoos-graphify-ai' );
	}

	public function get_tab(): string {
		return 'ai_providers';
	}

	public function get_priority(): int {
		return 20;
	}

	public function get_fields(): array {
		return array(
			// ─── OpenAI ──────────────────────────────────────────
			'ai_api_key_openai'        => array(
				'type'        => 'password',
				'label'       => __( 'OpenAI API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your OpenAI API key (sk-…).', 'nvoos-graphify-ai' ),
			),

			// ─── Google Gemini ───────────────────────────────────
			'ai_api_key_gemini'        => array(
				'type'        => 'password',
				'label'       => __( 'Google Gemini API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Google AI Studio API key.', 'nvoos-graphify-ai' ),
			),

			// ─── Anthropic Claude ────────────────────────────────
			'ai_api_key_anthropic'     => array(
				'type'        => 'password',
				'label'       => __( 'Anthropic API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Anthropic API key (sk-ant-…).', 'nvoos-graphify-ai' ),
			),

			// ─── Ollama (Local) ──────────────────────────────────
			'ai_api_key_ollama'        => array(
				'type'        => 'password',
				'label'       => __( 'Ollama API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Optional. Most local Ollama setups do not require an API key.', 'nvoos-graphify-ai' ),
			),
			'ollama_base_url'          => array(
				'type'        => 'text',
				'label'       => __( 'Ollama Base URL', 'nvoos-graphify-ai' ),
				'description' => __( 'The base URL of your Ollama instance.', 'nvoos-graphify-ai' ),
				'default'     => 'http://localhost:11434',
			),
			'ollama_model'             => array(
				'type'        => 'text',
				'label'       => __( 'Ollama Model', 'nvoos-graphify-ai' ),
				'description' => __( 'Model name as known to Ollama (e.g. llama3.3, mistral).', 'nvoos-graphify-ai' ),
				'default'     => 'llama3.3',
			),

			// ─── DeepSeek ────────────────────────────────────────
			'ai_api_key_deepseek'      => array(
				'type'        => 'password',
				'label'       => __( 'DeepSeek API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your DeepSeek API key.', 'nvoos-graphify-ai' ),
			),

			// ─── OpenRouter ──────────────────────────────────────
			'ai_api_key_openrouter'    => array(
				'type'        => 'password',
				'label'       => __( 'OpenRouter API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your OpenRouter API key.', 'nvoos-graphify-ai' ),
			),

			// ─── Hugging Face ────────────────────────────────────
			'ai_api_key_huggingface'   => array(
				'type'        => 'password',
				'label'       => __( 'Hugging Face API Token', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Hugging Face API token (hf_…).', 'nvoos-graphify-ai' ),
			),
			'huggingface_endpoint_url' => array(
				'type'        => 'text',
				'label'       => __( 'Hugging Face Endpoint URL', 'nvoos-graphify-ai' ),
				'description' => __( 'The inference endpoint URL.', 'nvoos-graphify-ai' ),
				'default'     => 'https://api-inference.huggingface.co',
			),
			'huggingface_model'        => array(
				'type'        => 'text',
				'label'       => __( 'Hugging Face Model', 'nvoos-graphify-ai' ),
				'description' => __( 'Model identifier on Hugging Face.', 'nvoos-graphify-ai' ),
				'default'     => 'meta-llama/Llama-3.3-70B-Instruct',
			),

			// ─── Cloudflare Workers AI ───────────────────────────
			'ai_api_key_cloudflare'    => array(
				'type'        => 'password',
				'label'       => __( 'Cloudflare API Token', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Cloudflare API token with Workers AI access.', 'nvoos-graphify-ai' ),
			),
			'cloudflare_account_id'    => array(
				'type'        => 'text',
				'label'       => __( 'Cloudflare Account ID', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Cloudflare account identifier.', 'nvoos-graphify-ai' ),
			),
			'cloudflare_model'         => array(
				'type'        => 'text',
				'label'       => __( 'Cloudflare Model', 'nvoos-graphify-ai' ),
				'description' => __( 'Model identifier for Cloudflare Workers AI.', 'nvoos-graphify-ai' ),
				'default'     => '@cf/meta/llama-3.3-70b-instruct',
			),

			// ─── LM Studio (Local) ───────────────────────────────
			'ai_api_key_lmstudio'      => array(
				'type'        => 'password',
				'label'       => __( 'LM Studio API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Optional. Set only if you have configured an API key in LM Studio.', 'nvoos-graphify-ai' ),
			),
			'lmstudio_base_url'        => array(
				'type'        => 'text',
				'label'       => __( 'LM Studio Base URL', 'nvoos-graphify-ai' ),
				'description' => __( 'The base URL of your LM Studio server.', 'nvoos-graphify-ai' ),
				'default'     => 'http://localhost:1234/v1',
			),
			'lmstudio_model'           => array(
				'type'        => 'text',
				'label'       => __( 'LM Studio Model', 'nvoos-graphify-ai' ),
				'description' => __( 'Model identifier for LM Studio.', 'nvoos-graphify-ai' ),
				'default'     => 'local-model',
			),

			// ─── NVIDIA NIM ──────────────────────────────────────
			'ai_api_key_nvidia'        => array(
				'type'        => 'password',
				'label'       => __( 'NVIDIA NIM API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your NVIDIA NIM API key.', 'nvoos-graphify-ai' ),
			),

			// ─── DigitalOcean ────────────────────────────────────
			'ai_api_key_digitalocean'  => array(
				'type'        => 'password',
				'label'       => __( 'DigitalOcean API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your DigitalOcean API key.', 'nvoos-graphify-ai' ),
			),

			// ─── Kimi (Moonshot) ─────────────────────────────────
			'ai_api_key_kimi'          => array(
				'type'        => 'password',
				'label'       => __( 'Kimi API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Kimi (Moonshot) API key.', 'nvoos-graphify-ai' ),
			),

			// ─── Baseten ─────────────────────────────────────────
			'ai_api_key_baseten'       => array(
				'type'        => 'password',
				'label'       => __( 'Baseten API Key', 'nvoos-graphify-ai' ),
				'description' => __( 'Your Baseten API key.', 'nvoos-graphify-ai' ),
			),
		);
	}
}
