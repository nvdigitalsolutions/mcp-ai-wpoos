<?php
/**
 * LM Studio provider client (local AI).
 *
 * OpenAI-compatible API running on localhost. No API key required by default.
 * Default endpoint: http://localhost:1234/v1.
 * Supports SSE streaming natively.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class LmStudioClient extends OpenAiCompatibleClient
{
    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'lm_studio';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://localhost:1234/v1';
    }

    protected function buildAuthHeaders(string $apiKey): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if ('' !== $apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        return $headers;
    }
}
