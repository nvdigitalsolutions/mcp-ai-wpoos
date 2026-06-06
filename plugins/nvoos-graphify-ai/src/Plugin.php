<?php
declare(strict_types=1);

namespace NvoosGraphifyAi;

/**
 * Plugin bootstrap — wires the addon into WordPress and the parent plugin.
 *
 * Replaces direct ProviderRegistry / ChatService management with
 * CoreBridge, which delegates provider routing, tool execution, and
 * chat orchestration to the framework-agnostic nvoos/core engine.
 *
 * @since 1.0.0
 */
final class Plugin {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		// Bootstrap core services (providers, tools, orchestrator).
		CoreBridge::instance();

		add_filter( 'nvoos_graphify/default_settings', array( $this, 'addDefaultSettings' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				if ( class_exists( 'NvoosGraphifyAi\Rest\ChatController' ) ) {
					( new \NvoosGraphifyAi\Rest\ChatController() )->registerRoutes();
				}
			}
		);

		// Register the async chat continuation hook.
		add_action(
			'nvoos_graphify_ai/continue_chat',
			array( $this, 'handleContinueChat' ),
			10,
			2
		);
	}

	public function addDefaultSettings( array $defaults ): array {
		return array_merge(
			$defaults,
			array(
				'ai_default_provider'      => 'openai',
				'ai_default_model'         => 'gpt-4o',
				'ai_chat_enabled'          => true,
				'ai_temperature'           => 0.7,
				'ai_max_tokens'            => 4096,
				'ai_api_key_openai'        => '',
				'ai_api_key_gemini'        => '',
				'ai_api_key_ollama'        => '',
				'ollama_base_url'          => 'http://localhost:11434',
				'ollama_model'             => 'llama3.3',
				'ai_api_key_anthropic'     => '',
				'ai_api_key_deepseek'      => '',
				'ai_api_key_openrouter'    => '',
				'ai_api_key_huggingface'   => '',
				'huggingface_endpoint_url' => 'https://api-inference.huggingface.co',
				'huggingface_model'        => 'meta-llama/Llama-3.3-70B-Instruct',
				'ai_api_key_cloudflare'    => '',
				'cloudflare_account_id'    => '',
				'cloudflare_model'         => '@cf/meta/llama-3.3-70b-instruct',
				'ai_api_key_lmstudio'      => '',
				'lmstudio_base_url'        => 'http://localhost:1234/v1',
				'lmstudio_model'           => 'local-model',
				'ai_api_key_nvidia'        => '',
				'ai_api_key_digitalocean'  => '',
				'ai_api_key_kimi'          => '',
				'ai_api_key_baseten'       => '',
			)
		);
	}

	/**
	 * Handle async chat continuation via Action Scheduler.
	 *
	 * @param array  $messages Conversation history.
	 * @param string $provider Provider slug.
	 */
	public function handleContinueChat( array $messages, string $provider = '' ): void {
		$bridge  = CoreBridge::instance();
		$options = array();

		if ( '' !== $provider ) {
			$options['provider'] = $provider;
		}

		$bridge->chat->handleChat(
			$messages,
			array(),           // assistantConfig
			0,                 // userId
			0,                 // assistantId
			$options,
		);
	}

	private function __clone() {}
}
