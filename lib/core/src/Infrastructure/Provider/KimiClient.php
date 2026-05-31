<?php
/**
 * Kimi (Moonshot AI) provider client.
 *
 * OpenAI-compatible API at https://api.moonshot.cn/v1.
 * Supports kimi-k2.6 (256K context), kimi-k2-thinking (CoT).
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class KimiClient extends OpenAiCompatibleClient
{
    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'kimi';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.moonshot.cn/v1';
    }
}
