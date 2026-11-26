<?php

namespace App\Support;

use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * The one reader for the profession catalogue — Proposal 149 §23.
 *
 * Three callers need a profession row: `GET /api/v1/professions`, the team
 * catalogue's member list, and the read-only `/admin/professions` page. The row
 * shape is a published console contract (the SPA's profession selector reads
 * `slug`, `title`, `category`, `category_slug`, `tools_count`), so the mapping
 * lives here once and every caller delegates (149 R1).
 *
 * The category label map used to exist twice, in `ProfessionController` and
 * `TeamCatalogueController`. Two copies drift on the first new category, so it
 * lives here now; both controllers and the admin page read this one.
 *
 * Professions are code-owned reference data — a directory of JSON documents
 * versioned by `catalogueVersion()`. There is no write path on this runtime
 * (`save_profession` is not registered), so nothing here accepts a payload. The
 * `professions` contract row therefore declares `writer: none` and renders
 * read-only on `/admin`. A writable overlay would be a separate proposal with
 * its own storage and its own writer — not a second writer bolted onto a
 * read-only catalogue.
 */
final class ProfessionCatalogue
{
    /**
     * Category slug => label, the vocabulary the console already renders.
     *
     * @var array<string, string>
     */
    private const CATEGORY_LABELS = array(
        'advisory' => 'Advisory/Consulting',
        'creative' => 'Creative Services',
        'technical' => 'Technical',
        'healthcare' => 'Healthcare',
        'legal' => 'Legal',
        'financial' => 'Financial',
        'other' => 'Other',
    );

    /**
     * Every profession, in the repository's title order.
     *
     * The repository's `getAll()` is slug => title and already title-sorted;
     * each record is then fetched so the row carries its category and tool
     * count. A slug whose record cannot be read is skipped rather than
     * rendered as a blank row.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(ProfessionRepositoryInterface $professions): array
    {
        $rows = array();

        foreach ( array_keys($professions->getAll()) as $slug ) {
            $found = $professions->getBySlug((string) $slug);

            if ( ! empty($found['found']) ) {
                $rows[] = self::summary($found['profession']);
            }
        }

        return $rows;
    }

    /**
     * One row — the shape the profession selector and the team-catalogue
     * members share.
     *
     * Do not add, rename or reorder keys without changing the SPA's profession
     * selector and `ProfessionCatalogueTest` in the same commit: this array is
     * the published `GET /api/v1/professions` row.
     *
     * @param array<string, mixed> $profession Normalised catalogue record.
     * @return array<string, mixed>
     */
    public static function summary(array $profession): array
    {
        $slug     = (string) ( $profession['slug'] ?? '' );
        $category = (string) ( $profession['category'] ?? '' );

        return array(
            'slug'          => $slug,
            'title'         => (string) ( $profession['name'] ?? $slug ),
            'category'      => self::categoryLabel($category),
            'category_slug' => $category,
            'description'   => (string) ( $profession['description'] ?? '' ),
            'expertise'     => $profession['expertise'] ?? array(),
            'tools_count'   => count($profession['default_tools'] ?? array()),
        );
    }

    /**
     * Human label for a category slug.
     *
     * Falls back to the capitalised slug so a category added by a licensed
     * catalogue renders as itself instead of vanishing from the filter.
     */
    public static function categoryLabel(string $slug): string
    {
        return self::CATEGORY_LABELS[ $slug ] ?? ( '' === $slug ? '' : ucfirst($slug) );
    }

    /**
     * The categories actually present in a row set — slug => label, by label.
     *
     * Derived from the rows rather than the constant, so the filter can never
     * offer a category with nothing behind it. A category with an empty slug is
     * omitted: it would be an unlabelled option.
     *
     * @param list<array<string, mixed>> $rows Row set from self::rows().
     * @return array<string, string>
     */
    public static function categories(array $rows): array
    {
        $categories = array();

        foreach ( $rows as $row ) {
            $slug = (string) ( $row['category_slug'] ?? '' );

            if ( '' === $slug ) {
                continue;
            }

            $categories[ $slug ] = (string) ( $row['category'] ?? $slug );
        }

        asort($categories);

        return $categories;
    }

    /**
     * Filter rows for a search term and/or a category slug.
     *
     * Server-side on purpose, as on the tool catalogue: the page must report
     * what the runtime can actually serve, not what a client chose to keep.
     *
     * @param list<array<string, mixed>> $rows Row set from self::rows().
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, string $search = '', string $category = ''): array
    {
        $search   = strtolower(trim($search));
        $category = trim($category);

        return array_values(
            array_filter(
                $rows,
                static function (array $row) use ($search, $category): bool {
                    if ( '' !== $category && $category !== (string) ( $row['category_slug'] ?? '' ) ) {
                        return false;
                    }

                    if ( '' === $search ) {
                        return true;
                    }

                    $haystack = strtolower(
                        implode(
                            ' ',
                            array(
                                (string) ( $row['slug'] ?? '' ),
                                (string) ( $row['title'] ?? '' ),
                                (string) ( $row['category'] ?? '' ),
                                (string) ( $row['description'] ?? '' ),
                            )
                        )
                    );

                    return str_contains($haystack, $search);
                }
            )
        );
    }

    /**
     * Counts a header can show without the view walking the rows again.
     *
     * `tools` is the sum of the per-profession recommended-tool counts, not a
     * count of distinct tools: the same tool is recommended by several
     * professions and collapsing it would understate the catalogue.
     *
     * @param list<array<string, mixed>> $rows Row set from self::rows().
     * @return array{total: int, tools: int}
     */
    public static function totals(array $rows): array
    {
        $tools = 0;

        foreach ( $rows as $row ) {
            $tools += (int) ( $row['tools_count'] ?? 0 );
        }

        return array(
            'total' => count($rows),
            'tools' => $tools,
        );
    }
}
