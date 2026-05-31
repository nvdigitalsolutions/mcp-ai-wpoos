<?php
/**
 * HuggingFace Inference provider client.
 *
 * OpenAI-compatible API via HuggingFace's text-generation-inference endpoint.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Infrastructure\Provider;

class HuggingFaceClient extends OpenAiCompatibleClient
{
    public function __construct(
        SettingsStoreInterface $settings,
        HttpClientInterface $http,
        ErrorFactoryInterface $errors,
    ) {
        parent::__construct($settings, $http, $errors);
        $this->providerSlug = 'huggingface';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api-inference.huggingface.co/v1';
    }
}
