<?php
/**
 * Moderate Content — checks text for policy violations using OpenAI Moderation API.
 *
 * Zero WordPress dependencies. Pure external API call.
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

class ModerateContentTool extends AbstractTool
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly SettingsStoreInterface $settings,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string { return 'moderate_content'; }
    public function getName(): string { return 'Moderate Content'; }

    public function getDescription(): string
    {
        return 'Checks text content for policy violations using the OpenAI Moderation API. Returns flagged categories and confidence scores.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'text' => [
                    'type'        => 'string',
                    'description' => 'The text content to moderate.',
                ],
                'model' => [
                    'type'        => 'string',
                    'description' => 'Moderation model. Default: omni-moderation-latest.',
                    'default'     => 'omni-moderation-latest',
                ],
            ],
            'required'             => ['text'],
            'additionalProperties' => false,
        ];
    }

    public function getRequiredCapability(): string { return 'read'; }

    public function execute(array $arguments = [], array $context = []): mixed
    {
        $text  = $this->stringParam($arguments, 'text');
        $model = $this->stringParam($arguments, 'model', 'omni-moderation-latest');

        if ('' === $text) {
            return $this->errors->validationFailed(
                'The text parameter is required.',
                ['text' => ['Text content to moderate is required.']],
            );
        }

        $apiKey = $this->settings->getApiKey('openai');

        if (null === $apiKey || '' === $apiKey) {
            return $this->errors->create(
                'missing_api_key',
                'No OpenAI API key configured. Add one in plugin settings.',
                ['status' => 400],
            );
        }

        $baseUrl = $this->settings->getApiBaseUrl('openai') ?? 'https://api.openai.com/v1';

        try {
            $body = \json_encode(['model' => $model, 'input' => $text]);
            $request = new \Nyholm\Psr7\Request(
                'POST',
                $baseUrl . '/moderations',
                [
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type'  => 'application/json',
                ],
                $body,
            );
            $response = $this->http->sendRequest($request);
            $data     = \json_decode((string) $response->getBody(), true);

            if ( ! is_array($data) || ! isset($data['results'][0])) {
                return $this->errors->create('moderation_failed', 'OpenAI Moderation returned an unexpected response.');
            }

            $result    = $data['results'][0];
            $flagged   = $result['flagged'] ?? false;
            $categories = [];

            foreach (($result['category_scores'] ?? []) as $category => $score) {
                $categories[] = [
                    'category' => \str_replace(['/', '_'], ' ', $category),
                    'score'    => \round((float) $score, 4),
                    'flagged'  => (bool) ($result['categories'][$category] ?? false),
                ];
            }

            \usort($categories, fn($a, $b) => $b['score'] <=> $a['score']);

            return $this->success(
                $flagged ? 'Content flagged by moderation.' : 'Content passed moderation.',
                [
                    'flagged'    => $flagged,
                    'categories' => $categories,
                ],
            );

        } catch (\Exception $e) {
            return $this->errors->create('moderation_failed', $e->getMessage());
        }
    }
}
