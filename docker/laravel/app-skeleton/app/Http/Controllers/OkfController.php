<?php

namespace App\Http\Controllers;

use App\Support\OkfCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Okf\OkfReadException;

/**
 * OKF knowledge surface (`/api/v1/okf`).
 *
 * Port of `WP_MCP_AI_Pro_REST_Okf`, same five routes and the same **unwrapped**
 * payloads — `OkfClient` reads `bundles`, `concepts`, `results`, `skills` and
 * the concept detail straight off the top level.
 *
 *   GET /bundles                              — bundle descriptors
 *   GET /bundles/{bundle}/concepts            — summaries, filterable
 *   GET /bundles/{bundle}/concepts/{concept}  — frontmatter, body, links
 *   GET /search?q=                            — cross-bundle search
 *   GET /skills?assistant_id=                 — granted OKF concepts
 *
 * Authorization matches WordPress: any authenticated reader. The drawer is in
 * the chat toolbar for every console user, so the route lives in the
 * `auth:sanctum` group rather than behind `manage_options`.
 *
 * `/skills` is honest-empty on purpose. WordPress resolves an assistant's
 * grants through `WP_MCP_AI_OKF_Skill_Bridge`; this runtime has no grant
 * store and no `load_skill` bridge, so no assistant can hold a grant. The
 * response reports that fact (`skills: []`) instead of a 501, because the
 * drawer's empty state ("Select an assistant…" / "No skills") is the accurate
 * rendering of "there are none".
 */
class OkfController
{
    /** Matches WordPress's `MAX_CONCEPTS`. */
    private const MAX_CONCEPTS = 200;

    /** Matches WordPress's `MAX_SEARCH_RESULTS`. */
    private const MAX_SEARCH_RESULTS = 50;

    /** Frontmatter keys exposed over REST, matching WordPress's allowlist. */
    private const FRONTMATTER_ALLOWLIST = [
        'title',
        'description',
        'type',
        'tags',
        'status',
        'verified',
        'stale_after',
        'generated',
        'usage_window',
    ];

    /**
     * GET /okf/bundles.
     */
    public function bundles(): JsonResponse
    {
        return response()->json([
            'bundles' => OkfCatalogue::fromConfig()->bundles(),
        ]);
    }

    /**
     * GET /okf/bundles/{bundle}/concepts.
     */
    public function concepts(Request $request, string $bundle): JsonResponse
    {
        $catalogue = OkfCatalogue::fromConfig();

        try {
            $reader = $catalogue->readerFor($bundle);
        } catch (OkfReadException $exception) {
            return $this->failure($exception);
        }

        $criteria = [];

        foreach (['type', 'status', 'trust_tier'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && '' !== $value) {
                $criteria[$key] = $value;
            }
        }

        $includeStale = $request->query('include_stale');
        $criteria['include_stale'] = null === $includeStale ? true : filter_var($includeStale, FILTER_VALIDATE_BOOLEAN);

        $concepts = $reader->search($criteria);

        $needle = mb_strtolower(trim((string) $request->query('q', '')));

        if ('' !== $needle) {
            $concepts = array_values(array_filter(
                $concepts,
                static function (array $concept) use ($needle): bool {
                    $tags = is_array($concept['tags'] ?? null) ? implode(' ', $concept['tags']) : '';
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        (string) ($concept['concept_id'] ?? ''),
                        (string) ($concept['title'] ?? ''),
                        (string) ($concept['description'] ?? ''),
                        $tags,
                    ])));

                    return str_contains($haystack, $needle);
                }
            ));
        }

        $limit = (int) $request->query('limit', 0);
        $limit = $limit > 0 ? min($limit, self::MAX_CONCEPTS) : 100;
        $concepts = array_slice($concepts, 0, $limit);

        return response()->json([
            'bundle' => $bundle,
            'concepts' => $concepts,
            'total' => count($concepts),
        ]);
    }

    /**
     * GET /okf/bundles/{bundle}/concepts/{concept}.
     */
    public function concept(string $bundle, string $concept): JsonResponse
    {
        $catalogue = OkfCatalogue::fromConfig();

        try {
            $reader = $catalogue->readerFor($bundle);
            // The SPA encodes concept ids, so slashes arrive as %2F.
            $detail = $reader->getConcept(rawurldecode($concept));
        } catch (OkfReadException $exception) {
            return $this->failure($exception);
        }

        $frontmatter = is_array($detail['frontmatter'] ?? null) ? $detail['frontmatter'] : [];
        $body = (string) ($detail['body'] ?? '');

        return response()->json([
            'bundle' => $bundle,
            'concept_id' => (string) ($detail['concept_id'] ?? $concept),
            'frontmatter' => $this->allowlisted($frontmatter),
            'body' => $body,
            'links' => $reader->conceptLinks($body, (string) ($detail['concept_id'] ?? $concept)),
            'trust_tier' => $reader->getTrustTier($frontmatter),
            'stale' => $reader->isStale($frontmatter),
        ]);
    }

    /**
     * GET /okf/search?q= — cross-bundle concept search.
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ('' === $query) {
            return response()->json([
                'query' => '',
                'results' => [],
                'total' => 0,
            ]);
        }

        $needle = mb_strtolower($query);
        $catalogue = OkfCatalogue::fromConfig();
        $results = [];

        foreach ($catalogue->names() as $bundle) {
            try {
                $concepts = $catalogue->readerFor($bundle)->search(['include_stale' => true]);
            } catch (OkfReadException) {
                continue;
            }

            foreach ($concepts as $concept) {
                $tags = is_array($concept['tags'] ?? null) ? implode(' ', $concept['tags']) : '';
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($concept['concept_id'] ?? ''),
                    (string) ($concept['title'] ?? ''),
                    (string) ($concept['description'] ?? ''),
                    $tags,
                ])));

                if (! str_contains($haystack, $needle)) {
                    continue;
                }

                $results[] = ['bundle' => $bundle] + $concept;

                if (count($results) >= self::MAX_SEARCH_RESULTS) {
                    break 2;
                }
            }
        }

        return response()->json([
            'query' => $query,
            'results' => $results,
            'total' => count($results),
        ]);
    }

    /**
     * GET /okf/skills?assistant_id= — granted OKF concepts.
     *
     * No grant store exists on this runtime, so the list is always empty. The
     * assistant id is echoed so the client can tell "no assistant" from
     * "assistant, no grants".
     */
    public function skills(Request $request): JsonResponse
    {
        return response()->json([
            'assistant_id' => (int) $request->query('assistant_id', 0),
            'skills' => [],
        ]);
    }

    /**
     * Allowlist frontmatter keys.
     *
     * @param array<string, mixed> $frontmatter Parsed frontmatter.
     * @return array<string, mixed>
     */
    private function allowlisted(array $frontmatter): array
    {
        $out = [];

        foreach (self::FRONTMATTER_ALLOWLIST as $key) {
            if (array_key_exists($key, $frontmatter)) {
                $out[$key] = $frontmatter[$key];
            }
        }

        return $out;
    }

    /**
     * Map a reader failure onto HTTP. Traversal answers 404 so the response
     * never confirms that a path exists outside the bundle.
     */
    private function failure(OkfReadException $exception): JsonResponse
    {
        $notFound = in_array($exception->errorCode, ['okf_not_found', 'okf_path_traversal'], true);

        return response()->json([
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
        ], $notFound ? 404 : 422);
    }
}
