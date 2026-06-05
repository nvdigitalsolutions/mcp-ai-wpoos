<?php
declare(strict_types=1);

namespace NvoosGraphifyAi;

use NvoosGraphifyAi\Chat\ChatService;

final class Plugin {

	private static ?self $instance = null;
	private ProviderRegistry $providerRegistry;

	private function __construct() {
		$this->providerRegistry = new ProviderRegistry();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_filter( 'nvoos_graphify/default_settings', array( $this, 'addDefaultSettings' ) );
		$this->registerBuiltinProviders();
		add_action( 'nvoos_graphify/register_tools', array( $this, 'registerAiTools' ) );

		add_action( 'rest_api_init', static function (): void {
			if ( class_exists( 'NvoosGraphifyAi\Rest\ChatController' ) ) {
				( new \NvoosGraphifyAi\Rest\ChatController() )->registerRoutes();
			}
		} );

		if ( class_exists( 'NvoosGraphifyAi\Chat\ChatService' ) ) {
			add_action( 'nvoos_graphify_ai/continue_chat', array( ChatService::class, 'continueChat' ), 10, 2 );
		}
	}

	public function addDefaultSettings( array $defaults ): array {
		return array_merge( $defaults, array(
			'ai_default_provider'    => 'openai',
			'ai_default_model'       => 'gpt-4o',
			'ai_chat_enabled'        => true,
			'ai_temperature'         => 0.7,
			'ai_max_tokens'          => 4096,
			'ai_api_key_openai'      => '',
			'ai_api_key_gemini'      => '',
			'ai_api_key_ollama'      => '',
			'ollama_base_url'        => 'http://localhost:11434',
			'ollama_model'           => 'llama3.3',
			'ai_api_key_anthropic'   => '',
			'ai_api_key_deepseek'    => '',
			'ai_api_key_openrouter'  => '',
			'ai_api_key_huggingface' => '',
			'huggingface_endpoint_url' => 'https://api-inference.huggingface.co',
			'huggingface_model'      => 'meta-llama/Llama-3.3-70B-Instruct',
			'ai_api_key_cloudflare'  => '',
			'cloudflare_account_id'  => '',
			'cloudflare_model'       => '@cf/meta/llama-3.3-70b-instruct',
			'ai_api_key_lmstudio'    => '',
			'lmstudio_base_url'      => 'http://localhost:1234/v1',
			'lmstudio_model'         => 'local-model',
			'ai_api_key_nvidia'      => '',
			'ai_api_key_digitalocean'=> '',
			'ai_api_key_kimi'        => '',
			'ai_api_key_baseten'     => '',
		) );
	}

	private function registerBuiltinProviders(): void {
		$providers = array(
			'OpenAi\OpenAiProvider',
			'Gemini\GeminiProvider',
			'Ollama\OllamaProvider',
			'Anthropic\AnthropicProvider',
			'DeepSeek\DeepSeekProvider',
			'OpenRouter\OpenRouterProvider',
			'HuggingFace\HuggingFaceProvider',
			'Cloudflare\CloudflareProvider',
			'LMStudio\LMStudioProvider',
			'Nvidia\NvidiaProvider',
			'DigitalOcean\DigitalOceanProvider',
			'Kimi\KimiProvider',
			'Baseten\BasetenProvider',
		);

		foreach ( $providers as $rel ) {
			$cls = __NAMESPACE__ . '\Providers\\' . $rel;
			if ( class_exists( $cls ) ) {
				$this->providerRegistry->register( new $cls() );
			}
		}
	}

	public function registerAiTools( \NvoosGraphify\ToolRegistry $registry ): void {
		$tools = array(
			'SummarizeText',
			'TranslateText',
			'AnalyzeSentiment',
			'ExtractEntities',
			'QuestionAnswering',
			'GenerateExcerpt',
			'GenerateImageAltText',
			'AnalyzeImage',
			'CategorizeContent',
			'ContentRecommendation',
			'ContentFreshness',
			'SemanticSearch',
			'CreateTextEmbeddings',
		);

		foreach ( $tools as $name ) {
			$cls = 'NvoosGraphifyAi\Tools\\' . $name;
			if ( class_exists( $cls ) ) {
				$registry->register( new $cls() );
			}
		}
	}

	public function getProviderRegistry(): ProviderRegistry {
		return $this->providerRegistry;
	}

	private function __clone() {}
}
