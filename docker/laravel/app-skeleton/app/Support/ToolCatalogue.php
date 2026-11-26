<?php

namespace App\Support;

use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;

/**
 * The one reader for the tool catalogue — Proposal 149 §22.
 *
 * Two presentations read it: the console's `GET /api/v1/tools` and the read-only
 * `/admin/tools` page. Two readers would drift on the row shape, and the console
 * row shape is a published contract (the SPA's `ToolsClient` and `ToolPicker`
 * read it), so the mapping lives here once and both callers delegate (149 R1).
 *
 * The catalogue is NOT estate data and is NOT reduced per surface. An earlier
 * contract note proposed projecting the console down to "the assistant's enabled
 * tools plus presets"; that would break `ToolPicker`, which exists to let an
 * administrator enable a tool the assistant does not have yet. `tools` is a
 * reference catalogue — read-only everywhere, identical on both surfaces —
 * rather than a tenant-owned record set.
 */
final class ToolCatalogue
{
    /**
     * Every registered tool, in registry order.
     *
     * The shape is the console contract: `slug`, `name`, `description`,
     * `required_capability`, `readiness`, `annotations`. Do not add, rename or
     * reorder keys without changing `ToolsClient` and its tests in the same
     * commit.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(ToolRegistry $registry, ToolReadiness $readiness): array
    {
        return array_map(
            static function (ToolInterface $tool) use ($readiness): array {
                $slug = $tool->getSlug();

                return array(
                    'slug' => $slug,
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'required_capability' => $tool->getRequiredCapability(),
                    // 090-M: readiness is what the client needs to tell a tool
                    // that works from one that is merely present in the package.
                    'readiness' => $readiness->classify($slug),
                    'annotations' => $readiness->annotations($slug),
                );
            },
            array_values($registry->all()),
        );
    }

    /**
     * Counts a header or an empty state can show without walking the rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, servable: int}
     */
    public static function summary(array $rows): array
    {
        $servable = 0;

        foreach ($rows as $row) {
            if (ToolReadiness::isServableClass((string) $row['readiness'])) {
                ++$servable;
            }
        }

        return array(
            'total' => count($rows),
            'servable' => $servable,
        );
    }

    /**
     * The closed readiness vocabulary, for a filter control.
     *
     * @return array<string, string>
     */
    public static function readinessOptions(): array
    {
        return array(
            ToolReadiness::READY => 'Ready',
            ToolReadiness::PURE => 'Pure',
            ToolReadiness::DELEGATED => 'Delegated',
            ToolReadiness::UNVERIFIED => 'Unverified',
            ToolReadiness::QUARANTINED => 'Quarantined',
            ToolReadiness::UNAVAILABLE => 'Unavailable',
        );
    }

    /**
     * Filter rows for a search term and/or a readiness class.
     *
     * Server-side on purpose: the catalogue is a few hundred rows and the page
     * must not depend on client-side filtering to be honest about what is
     * servable.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $search = '', string $readiness = ''): array
    {
        $search = strtolower(trim($search));
        $readiness = trim($readiness);

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($search, $readiness): bool {
                if ('' !== $readiness && $readiness !== (string) $row['readiness']) {
                    return false;
                }

                if ('' === $search) {
                    return true;
                }

                $haystack = strtolower(implode(' ', array(
                    (string) $row['slug'],
                    (string) $row['name'],
                    (string) $row['description'],
                )));

                return str_contains($haystack, $search);
            }
        ));
    }
}
