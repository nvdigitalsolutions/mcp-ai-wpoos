<?php
/**
 * WordPress Adapter — Embedding Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\EmbeddingServiceInterface;

/**
 * WordPress adapter for embedding operations.
 *
 * Delegates to provider-specific embedding implementations and the
 * ContentEmbeddingStore for persistence.
 *
 * @since 2.0.0
 */
final class EmbeddingService implements EmbeddingServiceInterface
{
    public function embed(string $text, string $provider = 'openai', string $model = 'text-embedding-3-small'): array
    {
        if ($text === '') {
            return ['success' => false, 'error' => 'Empty text'];
        }

        // Use the content embedding store if available.
        if (\class_exists('WP_MCP_AI_Content_Embedding_Service')) {
            try {
                $service = \WP_MCP_AI_Content_Embedding_Service::get_instance();

                if (\method_exists($service, 'generate_embedding')) {
                    /** @var array|object $result */
                    $result = $service->generate_embedding($text, $provider, $model);

                    if (\is_wp_error($result)) {
                        return ['success' => false, 'error' => $result->get_error_message()];
                    }

                    if (\is_array($result) && isset($result['vector'])) {
                        return [
                            'success'    => true,
                            'vector'     => $result['vector'],
                            'dimensions' => \count($result['vector']),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return ['success' => false, 'error' => 'Embedding service unavailable'];
    }

    public function embedBatch(array $texts, string $provider = 'openai', string $model = 'text-embedding-3-small'): array
    {
        $vectors = [];

        foreach ($texts as $i => $text) {
            $result = $this->embed($text, $provider, $model);

            if (!$result['success']) {
                return ['success' => false, 'error' => "Batch failed at index {$i}: " . ($result['error'] ?? 'unknown')];
            }

            $vectors[$i] = $result['vector'] ?? [];
        }

        return ['success' => true, 'vectors' => $vectors];
    }

    public function store(string $contentId, array $vector, array $metadata = []): void
    {
        if (!\class_exists('WP_MCP_AI_Content_Embedding_Store')) {
            return;
        }

        try {
            $store = new \WP_MCP_AI_Content_Embedding_Store();

            if (\method_exists($store, 'store_embedding')) {
                $store->store_embedding($contentId, $vector, $metadata);
            }
        } catch (\Throwable $e) {
            // Silently fail — storage is best-effort.
        }
    }

    public function search(array $queryVector, int $limit = 10, float $minScore = 0.7): array
    {
        if (!\class_exists('WP_MCP_AI_Content_Embedding_Store')) {
            return [];
        }

        try {
            $store = new \WP_MCP_AI_Content_Embedding_Store();

            if (\method_exists($store, 'search_similar')) {
                /** @var array<int, array> $results */
                $results = $store->search_similar($queryVector, $limit, $minScore);

                return \is_array($results) ? $results : [];
            }
        } catch (\Throwable $e) {
            // Return empty on failure.
        }

        return [];
    }

    public function delete(string $contentId): void
    {
        if (!\class_exists('WP_MCP_AI_Content_Embedding_Store')) {
            return;
        }

        try {
            $store = new \WP_MCP_AI_Content_Embedding_Store();

            if (\method_exists($store, 'delete_embedding')) {
                $store->delete_embedding($contentId);
            }
        } catch (\Throwable $e) {
            // Silently fail.
        }
    }
}
