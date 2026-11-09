<?php
/**
 * Profession Repository — domain contract.
 *
 * Loads profession definitions, knowledge bases, playbooks, and
 * tool recommendations from configuration sources.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Provides profession configuration data for AI assistants.
 *
 * @since 2.0.0
 */
interface ProfessionRepositoryInterface
{
    /**
     * Get all available professions.
     *
     * @return array<string, array{name: string, slug: string, category: string, description: string, recommended_tools: array<int, string>}>
     */
    public function getAll(): array;

    /**
     * Get a single profession by slug.
     *
     * @param string $slug Profession slug.
     *
     * @return array{found: bool, profession?: array<string, mixed>}
     */
    public function getBySlug(string $slug): array;

    /**
     * Get professions filtered by category.
     *
     * @param string $category Category slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getByCategory(string $category): array;

    /**
     * Load the knowledge base for a profession.
     *
     * @param string $slug Profession slug.
     *
     * @return array{knowledge: array<string, mixed>, playbook: array<string, mixed>}
     */
    public function loadKnowledgeBase(string $slug): array;

    /**
     * Get recommended tools for a profession.
     *
     * @param string $slug Profession slug.
     *
     * @return array<int, string> Tool slugs.
     */
    public function getRecommendedTools(string $slug): array;

    /**
     * Get professions grouped by category.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getCategories(): array;
}
