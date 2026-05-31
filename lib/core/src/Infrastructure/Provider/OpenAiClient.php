<?php
/**
 * OpenAI provider client — framework-agnostic implementation.
 *
 * Sends chat completion requests to the OpenAI API using injected
 * SettingsStoreInterface (for API keys), HttpClientInterface (PSR-18),
 * and ErrorFactoryInterface (for consistent error creation).
 *
 * This replaces the existing WP_MCP_AI_OpenAI_Client's direct calls to
 * get_option() and wp_remote_post(). The WordPress-specific class becomes
 * a thin wrapper that delegates to this core implementation.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class OpenAiClient extends AbstractProviderClient
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'openai';
    }

    protected function getDefaultBaseUrl(): string
    {
        return self::DEFAULT_BASE_URL;
    }

    public function chat(array $messages, array $options = []): mixed
    {
        $apiKey = $this->getApiKey();

        if ('' === $apiKey) {
            return $this->missingApiKeyError();
        }

        $model   = $this->resolveModel($options);
        $timeout = $this->getTimeout($options);
        $baseUrl = $this->getBaseUrl();

        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        // Optional parameters.
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }
        if ( ! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if ( ! empty($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['top_p'])) {
            $payload['top_p'] = (float) $options['top_p'];
        }
        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = (float) $options['frequency_penalty'];
        }
        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = (float) $options['presence_penalty'];
        }
        if ( ! empty($options['stop'])) {
            $payload['stop'] = $options['stop'];
        }
        if (isset($options['seed'])) {
            $payload['seed'] = (int) $options['seed'];
        }
        if ( ! empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }
        if ( ! empty($options['stream']) && true === $options['stream']) {
            $payload['stream'] = true;
        }

        try {
            $body = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->errors->create(
                'json_encode_failed',
                'Failed to encode chat request payload.',
                ['error' => $e->getMessage()],
            );
        }

        $headers = $this->buildAuthHeaders($apiKey);

        try {
            $request  = new \Nyholm\Psr7\Request(
                'POST',
                $baseUrl . '/chat/completions',
                $headers,
                $body,
            );
            $response = $this->http->sendRequest($request);

            $responseBody = (string) $response->getBody();
            $statusCode   = $response->getStatusCode();

            if ($statusCode >= 400) {
                return $this->parseErrorResponse($statusCode, $responseBody);
            }

            $data = \json_decode($responseBody, true);

            if ( ! is_array($data)) {
                return $this->errors->create(
                    'invalid_response',
                    'OpenAI returned an unexpected response format.',
                    ['raw' => $responseBody],
                );
            }

            return $data;

        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            return $this->errors->create(
                'http_request_failed',
                "OpenAI API request failed: {$e->getMessage()}",
                ['exception' => $e->getMessage()],
            );
        }
    }

    public function stream(array $messages, array $options = [], ?callable $onChunk = null): mixed
    {
        $options['stream'] = true;
        // Streaming uses the same endpoint but requires a streaming HTTP client.
        // For now, delegates to chat() — concrete streaming will use cURL/SSE
        // in the WordPress adapter layer.
        return $this->chat($messages, $options);
    }

    public function listModels(): mixed
    {
        $apiKey = $this->getApiKey();

        if ('' === $apiKey) {
            return $this->missingApiKeyError();
        }

        $baseUrl = $this->getBaseUrl();
        $headers = $this->buildAuthHeaders($apiKey);

        try {
            $request  = new \Nyholm\Psr7\Request(
                'GET',
                $baseUrl . '/models',
                $headers,
            );
            $response = $this->http->sendRequest($request);
            $body     = (string) $response->getBody();
            $data     = \json_decode($body, true);

            if ( ! is_array($data) || ! isset($data['data'])) {
                return [];
            }

            $models = [];
            foreach ($data['data'] as $model) {
                if (is_array($model) && isset($model['id'])) {
                    $models[] = $model['id'];
                }
            }

            \sort($models);

            return $models;

        } catch (\Exception $e) {
            return $this->errors->create(
                'list_models_failed',
                "Failed to list OpenAI models: {$e->getMessage()}",
            );
        }
    }

    // ─── Error parsing ────────────────────────────────────────────────

    /**
     * Parse an error response from the OpenAI API.
     */
    private function parseErrorResponse(int $statusCode, string $body): mixed
    {
        $data = \json_decode($body, true);

        $errorMessage = 'OpenAI API returned an error.';
        $errorCode    = "http_{$statusCode}";
        $errorData    = ['status' => $statusCode];

        if (is_array($data) && isset($data['error'])) {
            $error = $data['error'];

            if (is_array($error)) {
                $errorMessage = $error['message'] ?? $errorMessage;
                $errorCode    = $error['code'] ?? $errorCode;
                $errorData['type'] = $error['type'] ?? '';
                $errorData['param'] = $error['param'] ?? null;
            } elseif (is_string($error)) {
                $errorMessage = $error;
            }
        }

        // Rate limit specific handling.
        if (429 === $statusCode) {
            $retryAfter = 60;
            if (is_array($data) && isset($data['error']['message'])) {
                // Try to extract retry-after from message.
                if (\preg_match('/try again in (\d+(?:\.\d+)?)(ms|s)/', $data['error']['message'], $matches)) {
                    $retryAfter = 's' === $matches[2]
                        ? (int) \ceil((float) $matches[1])
                        : (int) \ceil((float) $matches[1] / 1000);
                }
            }

            return $this->errors->rateLimited(
                $errorMessage,
                \max(1, $retryAfter),
            );
        }

        return $this->errors->create($errorCode, $errorMessage, $errorData);
    }
}
