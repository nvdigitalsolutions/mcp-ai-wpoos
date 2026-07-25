<?php
/**
 * Model Catalog — domain contract.
 *
 * Discover, list, and validate AI models across providers.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Model catalog for AI provider model discovery and validation.
 *
 * @since 2.0.0
 */
interface ModelCatalogInterface
{
    /**
     * Get available models for a specific provider.
     *
     * @param string $provider Provider slug (openai, anthropic, gemini, etc.).
     * @param array  $args     Optional filters (capability_flags, etc.).
     *
     * @return array<string, string> Model ID => display name pairs.
     */
    public function getModelsForProvider(string $provider, array $args = []): array;

    /**
     * Get all models across all enabled providers.
     *
     * @return array<string, array{provider: string, name: string, capabilities: array}>
     */
    public function getAllModels(): array;

    /**
     * Check whether a specific model ID exists and is available.
     *
     * @param string $modelId Model identifier.
     *
     * @return bool
     */
    public function modelExists(string $modelId): bool;

    /**
     * Get the maximum context token limit for a model.
     *
     * @param string $modelId Model identifier.
     *
     * @return int Token limit, or 0 if unknown.
     */
    public function getModelTokenLimit(string $modelId): int;

    /**
     * Run discovery against providers to find new/changed/sunset models.
     *
     * @param array $providers Provider slugs to query (empty = all enabled).
     *
     * @return array{additions: array, sunsets: array, price_changes: array, errors: array, status: string}
     */
    public function discover(array $providers = []): array;
}
