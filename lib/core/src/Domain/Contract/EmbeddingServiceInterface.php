<?php
/**
 * Embedding Service — domain contract.
 *
 * Generates and manages text embedding vectors for semantic search,
 * content comparison, and AI context retrieval.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Generates embedding vectors from text.
 *
 * @since 2.0.0
 */
interface EmbeddingServiceInterface
{
    /**
     * Generate an embedding vector for a text input.
     *
     * @param string $text     Input text.
     * @param string $provider Provider slug (openai, gemini, huggingface).
     * @param string $model    Model ID for embeddings.
     *
     * @return array{success: bool, vector?: array<int, float>, dimensions?: int, error?: string}
     */
    public function embed(string $text, string $provider = 'openai', string $model = 'text-embedding-3-small'): array;

    /**
     * Generate embeddings for multiple texts in batch.
     *
     * @param array<int, string> $texts    Input texts.
     * @param string             $provider Provider slug.
     * @param string             $model    Model ID.
     *
     * @return array{success: bool, vectors?: array<int, array<int, float>>, error?: string}
     */
    public function embedBatch(array $texts, string $provider = 'openai', string $model = 'text-embedding-3-small'): array;

    /**
     * Store an embedding for later retrieval.
     *
     * @param string             $contentId Unique content identifier.
     * @param array<int, float>  $vector    Embedding vector.
     * @param array              $metadata  Associated metadata (title, type, etc.).
     */
    public function store(string $contentId, array $vector, array $metadata = []): void;

    /**
     * Find content IDs similar to a query vector.
     *
     * @param array<int, float> $queryVector Query embedding.
     * @param int               $limit       Maximum results.
     * @param float             $minScore    Minimum similarity score (0-1).
     *
     * @return array<int, array{content_id: string, score: float, metadata: array}>
     */
    public function search(array $queryVector, int $limit = 10, float $minScore = 0.7): array;

    /**
     * Remove stored embeddings for a content ID.
     *
     * @param string $contentId Content identifier.
     */
    public function delete(string $contentId): void;
}
