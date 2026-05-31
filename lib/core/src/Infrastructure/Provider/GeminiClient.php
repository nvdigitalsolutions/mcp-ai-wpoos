<?php
/**
 * Google Gemini provider client.
 *
 * Uses the Gemini API (not OpenAI-compatible). Chat endpoint:
 * POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class GeminiClient extends AbstractProviderClient
{
    private const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'gemini';
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
        $baseUrl = $this->getBaseUrl();

        // Convert OpenAI-format messages to Gemini format.
        $contents   = $this->convertMessages($messages);
        $systemText = $this->extractSystemInstruction($messages);

        $payload = [
            'contents' => $contents,
        ];

        if ('' !== $systemText) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemText]],
            ];
        }

        if ( ! empty($options['tools'])) {
            $payload['tools'] = $this->convertToolsToGemini($options['tools']);
        }

        $generationConfig = [];
        if (isset($options['temperature'])) {
            $generationConfig['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $generationConfig['maxOutputTokens'] = (int) $options['max_tokens'];
        }
        if (isset($options['top_p'])) {
            $generationConfig['topP'] = (float) $options['top_p'];
        }
        if ([] !== $generationConfig) {
            $payload['generationConfig'] = $generationConfig;
        }

        $url = $baseUrl . '/models/' . \urlencode($model) . ':generateContent?key=' . \urlencode($apiKey);

        try {
            $body = \json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->errors->create('json_encode_failed', $e->getMessage());
        }

        try {
            $request  = new \Nyholm\Psr7\Request(
                'POST',
                $url,
                ['Content-Type' => 'application/json'],
                $body,
            );
            $response   = $this->http->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $respBody   = (string) $response->getBody();

            if ($statusCode >= 400) {
                return $this->errors->create("http_{$statusCode}", $respBody, ['status' => $statusCode]);
            }

            return $this->normalizeResponse(\json_decode($respBody, true) ?: [], $model);

        } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
            return $this->errors->create('http_request_failed', $e->getMessage());
        }
    }

    public function stream(array $messages, array $options = [], ?callable $onChunk = null): mixed
    {
        return $this->chat($messages, $options);
    }

    public function listModels(): mixed
    {
        $apiKey = $this->getApiKey();
        if ('' === $apiKey) {
            return $this->missingApiKeyError();
        }

        $url = $this->getBaseUrl() . '/models?key=' . \urlencode($apiKey);

        try {
            $request  = new \Nyholm\Psr7\Request('GET', $url);
            $response = $this->http->sendRequest($request);
            $data     = \json_decode((string) $response->getBody(), true);

            if ( ! is_array($data) || ! isset($data['models'])) {
                return [];
            }

            $models = [];
            foreach ($data['models'] as $m) {
                if (is_array($m) && isset($m['name'])) {
                    // Extract model ID from "models/gemini-pro"
                    $models[] = \str_replace('models/', '', $m['name']);
                }
            }
            \sort($models);
            return $models;
        } catch (\Exception $e) {
            return $this->errors->create('list_models_failed', $e->getMessage());
        }
    }

    // ─── Gemini-specific message conversion ──────────────────────────

    /**
     * Convert OpenAI-format messages to Gemini contents array.
     */
    private function convertMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            // Skip system messages — handled separately.
            if ('system' === $role) {
                continue;
            }

            $geminiRole = 'assistant' === $role ? 'model' : 'user';

            // Build the parts array.
            $parts = [];

            if (is_string($msg['content'] ?? null)) {
                $parts[] = ['text' => $msg['content']];
            } elseif (is_array($msg['content'] ?? null)) {
                foreach ($msg['content'] as $segment) {
                    if (isset($segment['text'])) {
                        $parts[] = ['text' => $segment['text']];
                    } elseif (isset($segment['image_url']['url'])) {
                        $parts[] = [
                            'inlineData' => [
                                'mimeType' => 'image/jpeg',
                                'data'     => \preg_replace(
                                    '#^data:image/\w+;base64,#',
                                    '',
                                    $segment['image_url']['url'],
                                ),
                            ],
                        ];
                    }
                }
            }

            if ([] === $parts) {
                continue;
            }

            // Merge consecutive messages from the same role.
            $last = \count($contents) - 1;
            if ($last >= 0 && $contents[$last]['role'] === $geminiRole) {
                $contents[$last]['parts'] = \array_merge(
                    $contents[$last]['parts'],
                    $parts,
                );
            } else {
                $contents[] = [
                    'role'  => $geminiRole,
                    'parts' => $parts,
                ];
            }
        }

        return $contents;
    }

    private function extractSystemInstruction(array $messages): string
    {
        foreach ($messages as $msg) {
            if (('system' === ($msg['role'] ?? '')) && is_string($msg['content'] ?? null)) {
                return $msg['content'];
            }
        }
        return '';
    }

    /**
     * Convert OpenAI tool definitions to Gemini function declarations.
     */
    private function convertToolsToGemini(array $tools): array
    {
        $declarations = [];

        foreach ($tools as $tool) {
            if (isset($tool['function'])) {
                $declarations[] = [
                    'name'        => $tool['function']['name'],
                    'description' => $tool['function']['description'] ?? '',
                    'parameters'  => $tool['function']['parameters'] ?? [],
                ];
            }
        }

        return [['functionDeclarations' => $declarations]];
    }

    /**
     * Normalize Gemini response to the OpenAI-compatible shape expected
     * by the agentic loop and frontend.
     */
    private function normalizeResponse(array $data, string $model): array
    {
        $text = '';

        if (isset($data['candidates'][0]['content']['parts'])) {
            foreach ($data['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        $finishReason = $data['candidates'][0]['finishReason'] ?? 'STOP';

        // Map Gemini finish reasons to OpenAI.
        $reasonMap = [
            'STOP'             => 'stop',
            'MAX_TOKENS'       => 'length',
            'SAFETY'           => 'content_filter',
            'RECITATION'       => 'content_filter',
            'MALFORMED_FUNCTION_CALL' => 'tool_calls',
        ];

        $usage = [];
        if (isset($data['usageMetadata'])) {
            $usage = [
                'prompt_tokens'     => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens'      => $data['usageMetadata']['totalTokenCount'] ?? 0,
            ];
        }

        return [
            'id'      => $data['candidates'][0]['content']['parts'][0]['text'] ?? '',
            'object'  => 'chat.completion',
            'model'   => $model,
            'choices' => [
                [
                    'index'         => 0,
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => $text,
                    ],
                    'finish_reason' => $reasonMap[$finishReason] ?? 'stop',
                ],
            ],
            'usage'   => $usage,
        ];
    }
}
