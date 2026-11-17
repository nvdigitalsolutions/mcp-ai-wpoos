<?php

namespace App\Http\Controllers;

use App\Support\ProfessionCatalogue;
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
 *
 * The row mapping lives in App\Support\ProfessionCatalogue because the
 * read-only `/admin/professions` page (149 §23) reads the same rows. Two
 * mappers would drift on the first field change.
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
                $items[] = ProfessionCatalogue::summary($found['profession']);
            }
        }

        return response()->json(array(
            'professions' => $items,
            'total' => count($items),
            // The epoch every cached catalogue read is keyed by (090-g Phase 6).
            'catalogue_version' => $this->professions->catalogueVersion(),
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
                ProfessionCatalogue::summary($profession),
                array(
                    'role_description' => (string) ($profession['role_description'] ?? ''),
                    'warnings' => $profession['warnings'] ?? array(),
                    'knowledge_base' => (string) ($profession['knowledge_base'] ?? ''),
                    'recommended_tools' => $profession['default_tools'] ?? array(),
                )
            ),
        ));
    }
}
