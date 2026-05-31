<?php
/**
 * Ollama provider client (local AI).
 *
 * OpenAI-compatible API running on localhost. No API key required.
 * Default endpoint: http://localhost:11434/v1.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class OllamaClient extends OpenAiCompatibleClient
{
    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'ollama';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://localhost:11434/v1';
    }

    /**
     * Ollama does not require an API key — it runs locally.
     * Override to skip the missing-key check.
     */
    protected function getApiKey(): string
    {
        // Ollama may use no auth or a bearer token. Return whatever is configured.
        return parent::getApiKey(); // Returns empty string if not set.
    }

    /**
     * Override: Ollama's auth header uses the configured token, but if none
     * is configured, omit the Authorization header entirely.
     */
    protected function buildAuthHeaders(string $apiKey): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ('' !== $apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        return $headers;
    }
}
