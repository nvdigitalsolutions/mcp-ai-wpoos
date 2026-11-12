<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * Profession catalogue (090-g Phase 4).
 *
 * The console needs an HTTP source for the profession selector; until now the
 * catalogue was only reachable through the `list_professions` / `get_profession`
 * tools, which a page cannot call.
 *
 * Read-only by design: professions are code-owned reference data here, and
 * `save_profession` is not registered on this runtime.
 */
class ProfessionController
{
    public function __construct(private readonly ProfessionRepositoryInterface $professions)
    {
    }

    public function index(): JsonResponse
    {
        $items = array();

        // getAll() is slug => title, already title-sorted; fetch each record so
        // the list carries the category and a tool count the selector needs.
        foreach (array_keys($this->professions->getAll()) as $slug) {
            $found = $this->professions->getBySlug((string) $slug);

            if (! empty($found['found'])) {
                $items[] = $this->summary($found['profession']);
            }
        }

        return response()->json(array(
            'professions' => $items,
            'total' => count($items),
            // The concrete adapter exposes this; the interface does not, because
            // WordPress has no catalogue version.
            'catalogue_version' => method_exists($this->professions, 'catalogueVersion')
                ? $this->professions->catalogueVersion()
                : null,
        ));
    }

    public function show(string $slug): JsonResponse
    {
        $found = $this->professions->getBySlug($slug);

        if (empty($found['found'])) {
            return response()->json(array('success' => false, 'message' => 'Profession not found.'), 404);
        }

        $profession = $found['profession'];

        return response()->json(array(
            'success' => true,
            'data' => array_merge(
                $this->summary($profession),
                array(
                    'role_description' => (string) ($profession['role_description'] ?? ''),
                    'warnings' => $profession['warnings'] ?? array(),
                    'knowledge_base' => (string) ($profession['knowledge_base'] ?? ''),
                    'recommended_tools' => $profession['default_tools'] ?? array(),
                )
            ),
        ));
    }

    /**
     * The list shape — the same fields the team-catalogue members carry, so the
     * console renders a profession identically in both places.
     *
     * @param array<string, mixed> $profession Normalised catalogue record.
     * @return array<string, mixed>
     */
    private function summary(array $profession): array
    {
        $slug = (string) ($profession['slug'] ?? '');
        $category = (string) ($profession['category'] ?? '');
        $description = (string) ($profession['description'] ?? '');

        return array(
            'slug' => $slug,
            'title' => (string) ($profession['name'] ?? $slug),
            'category' => $this->categoryLabel($category),
            'category_slug' => $category,
            'description' => $description,
            'expertise' => $profession['expertise'] ?? array(),
            'tools_count' => count($profession['default_tools'] ?? array()),
        );
    }

    /**
     * Same label map the teams controller sends.
     */
    private function categoryLabel(string $slug): string
    {
        $labels = array(
            'advisory' => 'Advisory/Consulting',
            'creative' => 'Creative Services',
            'technical' => 'Technical',
            'healthcare' => 'Healthcare',
            'legal' => 'Legal',
            'financial' => 'Financial',
            'other' => 'Other',
        );

        return $labels[$slug] ?? ('' === $slug ? '' : ucfirst($slug));
    }
}
