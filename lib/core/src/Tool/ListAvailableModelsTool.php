<?php
/**
 * List Available Models — lists AI models available from a provider.
 *
 * Calls provider APIs to enumerate models. Zero WordPress dependencies.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Tool;

use Oos\Core\Domain\Contract\ErrorFactoryInterface;
use Oos\Core\Domain\Contract\SettingsStoreInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;

class ListAvailableModelsTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string { return 'list_available_models'; }
    public function getName(): string { return 'List Available Models'; }

    public function getDescription(): string
    {
        return 'Lists available AI models from a specified provider API.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'provider' => [
                    'type'        => 'string',
                    'description' => 'Provider slug (openai, gemini, anthropic, deepseek, openrouter). Default: openai.',
                    'default'     => 'openai',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredCapability(): string { return 'read'; }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $provider = $this->stringParam($arguments, 'provider', 'openai');
        $apiKey   = $this->settings->getApiKey($provider);
        $baseUrl  = $this->settings->getApiBaseUrl($provider);

        if (null === $apiKey || '' === $apiKey) {
            return $this->errors->create(
                'missing_api_key',
                "No API key configured for provider '{$provider}'. Configure one in plugin settings.",
                ['status' => 400],
            );
        }

        $defaults = [
            'openai'     => 'https://api.openai.com/v1',
            'gemini'     => 'https://generativelanguage.googleapis.com/v1beta',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'deepseek'   => 'https://api.deepseek.com/v1',
            'kimi'       => 'https://api.moonshot.cn/v1',
        ];

        $baseUrl = $baseUrl ?? ($defaults[$provider] ?? '');

        if ('' === $baseUrl) {
            return $this->errors->create('unknown_provider', "Unknown or unsupported provider: {$provider}");
        }

        try {
            $request = new \Nyholm\Psr7\Request(
                'GET',
                $baseUrl . '/models',
                ['Authorization' => "Bearer {$apiKey}"],
            );
            $response = $this->http->sendRequest($request);
            $data     = \json_decode((string) $response->getBody(), true);

            if ( ! is_array($data) || ! isset($data['data'])) {
                return $this->emptyResult("No models found for provider '{$provider}'.");
            }

            $models = [];
            foreach ($data['data'] as $m) {
                if (is_array($m) && isset($m['id'])) {
                    $models[] = $m['id'];
                }
            }
            \sort($models);

            return $this->collection(
                "Found " . \count($models) . " models from {$provider}.",
                \array_map(fn($id) => ['id' => $id], $models),
                \count($models),
            );

        } catch (\Exception $e) {
            return $this->errors->create('list_models_failed', $e->getMessage());
        }
    }
}
