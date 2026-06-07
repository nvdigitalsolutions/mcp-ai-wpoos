<?php
/**
 * Ollama provider client (local AI).
 *
 * OpenAI-compatible API running on localhost. No API key required.
 * Default endpoint: http://localhost:11434/v1.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class OllamaClient extends OpenAiCompatibleClient {

	public function __construct(
		SettingsStoreInterface $settings,
		HttpClientInterface $http,
		ErrorFactoryInterface $errors,
	) {
		parent::__construct( $settings, $http, $errors );
		$this->providerSlug = 'ollama';
	}

	protected function getDefaultBaseUrl(): string {
		return 'http://localhost:11434/v1';
	}

	/**
	 * Ollama runs locally — never require an API key.
	 */
	protected function requiresApiKey(): bool {
		return false;
	}

	/**
	 * Ollama may optionally use a bearer token. Return whatever is configured,
	 * but never require one.
	 */
	protected function getApiKey(): string {
		return parent::getApiKey();
	}

	/**
	 * Override: omit Authorization header when no token is configured.
	 */
	protected function buildAuthHeaders( string $apiKey ): array {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( '' !== $apiKey ) {
			$headers['Authorization'] = "Bearer {$apiKey}";
		}

		return $headers;
	}
}
