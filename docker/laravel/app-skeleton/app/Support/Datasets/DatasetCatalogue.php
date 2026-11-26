<?php

namespace App\Support\Datasets;

use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Tool\AbstractHuggingFaceTool;
use Nvoos\Core\Tool\HuggingFaceRecommendedDatasetsTool;

/**
 * The dataset browser's reader — Proposal 149 §95.
 *
 * §85.1 left `datasets` blocked because the eleven HuggingFace tools were not
 * servable, so a browser would have advertised datasets this runtime could not
 * fetch. §91 produced the evidence, ten tools became `ready` and one `pure`, and
 * the blocker moved from *classification* to *a page*. This is that page's
 * reader.
 *
 * **Derived, never copied.** The endpoint column comes from each tool's own
 * `ENDPOINT` constant — the same constant the tool passes to `apiGet()` — so an
 * endpoint change cannot leave the catalogue describing a route that no longer
 * exists. The starting-points table comes from
 * `HuggingFaceRecommendedDatasetsTool::datasets()`, the list the tool itself
 * answers from. There is no second declaration of either.
 *
 * **A catalogue, not a record set.** Nothing here is estate data: the rows are
 * the same for every estate, which is why the contract row says `writer: none`
 * and the page has no action beyond a search box.
 */
final class DatasetCatalogue
{
    /**
     * The prefix every HuggingFace dataset tool slug carries.
     *
     * A prefix rather than a hand-written list: a twelfth tool added to the
     * manifest appears here without an edit, and a tool that is withdrawn stops
     * appearing without one.
     */
    public const SLUG_PREFIX = 'huggingface_';

    /**
     * Every HuggingFace tool the registry serves, alphabetical by name.
     *
     * `readiness` is included even though every tool is servable today: a
     * readiness class can be withdrawn, and a browser that silently keeps
     * listing an unavailable tool is the §32 objection in reverse.
     *
     * @return list<array{slug: string, name: string, description: string, readiness: string, endpoint: string|null, kind: string}>
     */
    public static function rows(ToolRegistry $registry, ToolReadiness $readiness): array
    {
        $rows = array();

        foreach ($registry->all() as $tool) {
            $slug = $tool->getSlug();

            if (0 !== strpos($slug, self::SLUG_PREFIX)) {
                continue;
            }

            $class = $tool::class;
            $endpoint = defined($class.'::ENDPOINT') ? (string) constant($class.'::ENDPOINT') : null;

            $rows[] = array(
                'slug' => $slug,
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'readiness' => $readiness->classify($slug),
                'endpoint' => $endpoint,
                // A tool with no endpoint answers from a static list. §91.2
                // found that this is a property worth naming rather than an
                // accident: the recommended-datasets tool makes no request.
                'kind' => null === $endpoint ? 'static' : 'api',
            );
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Filter by a search term over slug, name, description and endpoint.
     *
     * Server-side, like the tool catalogue: the page must not depend on the
     * client to be honest about what it lists.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $search = ''): array
    {
        $search = strtolower(trim($search));

        if ('' === $search) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => false !== strpos(
                strtolower(implode(' ', array(
                    (string) $row['slug'],
                    (string) $row['name'],
                    (string) $row['description'],
                    (string) ($row['endpoint'] ?? ''),
                ))),
                $search
            )
        ));
    }

    /**
     * Totals the heading can print without walking the rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, api: int, static: int}
     */
    public static function summary(array $rows): array
    {
        $api = 0;
        $static = 0;

        foreach ($rows as $row) {
            if ('static' === $row['kind']) {
                ++$static;
            } else {
                ++$api;
            }
        }

        return array(
            'total' => count($rows),
            'api' => $api,
            'static' => $static,
        );
    }

    /**
     * The curated starting points, optionally narrowed to one category.
     *
     * Read from the tool that owns them rather than re-declared here.
     *
     * @return list<array{dataset: string, category: string, description: string, rows: int}>
     */
    public static function recommendations(string $category = ''): array
    {
        $all = HuggingFaceRecommendedDatasetsTool::datasets();

        if ('' === $category) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (array $row): bool => $category === $row['category']
        ));
    }

    /**
     * The categories the curated list covers, for a filter control.
     *
     * @return list<string>
     */
    public static function categories(): array
    {
        $categories = array();

        foreach (self::recommendations() as $row) {
            $categories[$row['category']] = true;
        }

        return array_keys($categories);
    }

    /**
     * The API base every tool calls, read from the tools' own base class.
     */
    public static function apiBase(): string
    {
        return AbstractHuggingFaceTool::API_BASE;
    }

    /**
     * Whether a **deployment-level** HuggingFace token is configured.
     *
     * Deliberately narrow. `SettingsStore::getApiKey('huggingface')` resolves the
     * *current estate's* token, and this page runs on `/ops`, where no estate is
     * pinned — reporting that value would describe the `default` estate's secret
     * as if it were platform-wide. So the page states the one fact it can know
     * honestly (an environment/config token) and names where a per-estate token
     * is configured. The value is never read, let alone rendered.
     */
    public static function deploymentTokenConfigured(): bool
    {
        $configured = config('services.huggingface.key');

        if (is_string($configured) && '' !== $configured) {
            return true;
        }

        $configured = config('oos.api_keys.huggingface');

        return is_string($configured) && '' !== $configured;
    }
}
