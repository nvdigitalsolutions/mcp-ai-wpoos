<?php

namespace App\Spike;

use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;

/**
 * Deterministic streaming provider for the P3 pass criterion.
 *
 * It has no network dependency and no API key. The chat orchestrator
 * still exercises the real provider router, streaming callback, SSE
 * framing, and cost accumulation paths.
 */
class SpikeStreamingProvider extends AbstractProviderClient
{
    protected string $providerSlug = 'spike';

    public function chat(array $messages, array $options = []): mixed
    {
        return $this->completion();
    }

    public function stream(array $messages, array $options = [], ?callable $onChunk = null): mixed
    {
        $text = 'Hello from the NV oOS Laravel adapter.';

        foreach (preg_split('/(\s+)/', $text) ?: [] as $piece) {
            if (null !== $onChunk) {
                $onChunk($piece);
            }

            usleep(5000);
        }

        return $this->completion($text);
    }

    public function listModels(): mixed
    {
        return ['spike-1'];
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'http://127.0.0.1';
    }

    /**
     * @return array<string, mixed>
     */
    private function completion(string $text = 'Hello from the NV oOS Laravel adapter.'): array
    {
        return [
            'model' => 'spike-1',
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $text,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 8,
                'total_tokens' => 18,
            ],
        ];
    }
}
