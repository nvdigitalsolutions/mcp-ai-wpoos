<?php
/**
 * DeepSeek provider client.
 *
 * OpenAI-compatible API at https://api.deepseek.com/v1.
 * Supports reasoning_content passthrough for deepseek-reasoner.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class DeepSeekClient extends OpenAiCompatibleClient
{
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
}
