<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

/**
 * Read-only port of WordPress's `mcp-ai/v1/teams` (090-g Phase 4).
 *
 * A team is a named collection of professions — the multi-agent collaboration
 * surface the shared console's TeamsPage renders. On WordPress that is the
 * `mcp_ai_team` CPT with profession post IDs in meta; here it is a
 * `mcp_ai_team` content record with profession catalogue slugs in meta
 * (reference data has no post IDs).
 *
 * The response shapes are copied field-for-field from
 * WP_MCP_AI_REST_Teams_Controller so the page cannot tell which runtime
 * answered. Deliberate divergences:
 *  - `members[].id` is omitted: a catalogue profession has no post ID. The
 *    SPA already falls back to the array index for its React key.
 *  - `members[].slug` is added (additive), so a client can deep-link.
 */
class TeamCatalogueController
{
    public function __construct(
        private readonly ContentStoreInterface $content,
        private readonly ProfessionRepositoryInterface $professions,
    ) {
    }

    public function index(): JsonResponse
    {
        $teams = $this->content->query(new ContentQuery(
            types: ['mcp_ai_team'],
            statuses: ['publish'],
            orderBy: 'title',
            order: 'ASC',
            perPage: 100,
        ));

        return response()->json([
            'success' => true,
            'teams' => array_map(
                fn (ContentItem $team): array => [
                    'id' => $team->id,
                    'title' => $team->title,
                    'member_count' => count($this->memberSlugs($team)),
                ],
                $teams->items,
            ),
        ]);
    }

    public function members(string $team): JsonResponse
    {
        $item = $this->content->find((int) $team);

        if (null === $item || 'mcp_ai_team' !== $item->type) {
            return response()->json(['success' => false, 'message' => 'Team not found.'], 404);
        }

        $members = array();

        foreach ($this->memberSlugs($item) as $slug) {
            $found = $this->professions->getBySlug($slug);

            if (empty($found['found'])) {
                continue;
            }

            $profession = $found['profession'];
            $description = (string) ($profession['description'] ?? '');

            $members[] = array(
                'slug' => (string) $profession['slug'],
                'title' => (string) $profession['name'],
                'category' => $this->categoryLabel((string) $profession['category']),
                'category_slug' => (string) $profession['category'],
                'excerpt' => $description,
                'description' => $description,
                'expertise' => $profession['expertise'] ?? array(),
                'tools_count' => count($profession['default_tools'] ?? array()),
            );
        }

        $driverId = (int) $item->getMetaValue('_wp_mcp_ai_team_driver_assistant', 0);
        // The console feature map, not the settings flag: the catalogue is
        // readable here, but the orchestration engine is not ported, so
        // advertising "multi-agent enabled" would be a lie.
        $multiAgent = (bool) config('oos.features.multi_agent_teams', false);

        return response()->json(
            array(
                'team_id' => $item->id,
                'team_title' => $item->title,
                'driver_assistant_id' => $driverId,
                'has_driver_assistant' => $driverId > 0,
                'members' => $members,
                'count' => count($members),
                'orchestration_mode' => (string) $item->getMetaValue('_wp_mcp_ai_team_orchestration_mode', 'sequential'),
                'result_aggregation' => (string) $item->getMetaValue('_wp_mcp_ai_team_result_aggregation', 'consensus'),
                'multi_agent_enabled' => $multiAgent,
                'supports_unified_mode' => $multiAgent && count($members) > 1,
            ),
            200,
        );
    }

    /**
     * @return string[]
     */
    private function memberSlugs(ContentItem $team): array
    {
        $value = $team->getMetaValue('_wp_mcp_ai_team_members', array());

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : array();
        }

        if (! is_array($value)) {
            return array();
        }

        return array_values(array_filter(
            array_map(static fn ($slug): string => is_string($slug) ? trim($slug) : '', $value),
            static fn (string $slug): bool => '' !== $slug,
        ));
    }

    /**
     * Same label map the WordPress controller sends, so the console renders
     * identical text.
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
