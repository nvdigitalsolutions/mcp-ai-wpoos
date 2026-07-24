<?php
/**
 * Profession Repository — domain contract.
 *
 * Loads profession definitions, knowledge bases, playbooks, and
 * tool recommendations from configuration sources.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
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
     * @return array<string, array{name: string, slug: string, category: string, description: string, recommended_tools: array}>
     */
    public function getAll(): array;

    /**
     * Get a single profession by slug.
     *
     * @param string $slug Profession slug.
     *
     * @return array{found: bool, profession?: array}
     */
    public function getBySlug(string $slug): array;

    /**
     * Get professions filtered by category.
     *
     * @param string $category Category slug.
     *
     * @return array<string, array>
     */
    public function getByCategory(string $category): array;

    /**
     * Load the knowledge base for a profession.
     *
     * @param string $slug Profession slug.
     *
     * @return array{knowledge: array, playbook: array}
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
     * @return array<string, array<int, array>>
     */
    public function getCategories(): array;
}
