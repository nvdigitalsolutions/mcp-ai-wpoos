<?php

/**
 * DeepSeek provider client.
 *
 * OpenAI-compatible API at https://api.deepseek.com/v1.
 * Supports reasoning_content passthrough for DeepSeek V4 thinking models.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Provider;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class DeepSeekClient extends OpenAiCompatibleClient
{
    /**
     * Model families that reject `max_tokens` in favour of
     * `max_completion_tokens`.
     *
     * DeepSeek-V4.1-Flash (deepseek-flash) answers a request carrying
     * `max_tokens` with: "Unsupported parameter: 'max_tokens' is not
     * supported with this model. Use 'max_completion_tokens' instead."
     * The retired deepseek-v4-flash ids route to it, so the whole family
     * uses the OpenAI-style key. deepseek-v4-pro keeps `max_tokens`.
     *
     * @var array<int, string>
     */
    private const MAX_COMPLETION_TOKEN_MODELS = array(
        'deepseek-flash',
        'deepseek-v4-flash',
        'deepseek-v4.1',
    );

    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'deepseek';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.deepseek.com/v1';
    }

    /**
     * Apply DeepSeek's model-specific parameter constraints.
     *
     * Runs the shared OpenAI-compatible constraints first (o-series / gpt-5
     * handling is harmless for DeepSeek ids), then renames the token-limit
     * key for the models that reject `max_tokens`. The base client's 400
     * "Unsupported parameter" retry remains the safety net for ids newer
     * than this list.
     *
     * @param array<string, mixed> $payload Request payload.
     * @param string               $model   Resolved model id.
     * @return array<string, mixed>
     */
    protected function applyModelConstraints(array $payload, string $model): array
    {
        $payload = parent::applyModelConstraints($payload, $model);

        if (isset($payload['max_tokens']) && $this->requiresMaxCompletionTokens($model)) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        return $payload;
    }

    /**
     * Whether the model expects `max_completion_tokens` on the wire.
     *
     * @param string $model Model id.
     * @return bool
     */
    private function requiresMaxCompletionTokens(string $model): bool
    {
        $model = \strtolower($model);

        foreach (self::MAX_COMPLETION_TOKEN_MODELS as $prefix) {
            if ($model === $prefix || 0 === \strpos($model, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
