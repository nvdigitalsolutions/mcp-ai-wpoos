<?php

/**
 * An in-memory content store for the calendar tools.
 *
 * Type, status, include/exclude, paging and id ordering are honoured, along with
 * meta clauses using `=`, `>`, `>=`, `<`, `<=`, `BETWEEN`, `IN`/`NOT IN`,
 * `LIKE` and `EXISTS`/`NOT EXISTS`, including nested `relation` groups. `LIKE`
 * matches the JSON encoding of a value and treats `%` as a wildcard, which is
 * exactly what the portable quoted-ID pattern relies on.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

final class FakeCalendarStore implements ContentStoreInterface
{
    /** @var array<int, ContentItem> */
    public array $items = array();

    /** @var array<int, array<string, bool>> */
    public array $access = array();

    public ?CreateContentCommand $created = null;

    public ?UpdateContentCommand $updated = null;

    public ?int $deleted = null;

    /** @var list<ContentQuery> */
    public array $queries = array();

    /** @var array<string, list<string>> Taxonomy vocabularies. */
    public array $terms = array();

    private int $nextId = 5000;

    /**
     * @param array<string, mixed> $meta Stored meta.
     */
    public function seed(
        int $id,
        string $type,
        string $title,
        array $meta,
        string $content = '',
        string $status = 'publish',
        int $authorId = 1,
        array $taxonomy = array(),
    ): ContentItem {
        $stamp = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $item = new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: $status,
            type: $type,
            authorId: $authorId,
            createdAt: $stamp,
            updatedAt: $stamp,
            meta: $meta,
            taxonomy: $taxonomy,
        );

        $this->items[ $id ] = $item;

        return $item;
    }

    public function find(int $id, ?int $userId = null): ?ContentItem
    {
        return $this->items[ $id ] ?? null;
    }

    public function query(ContentQuery $query): ContentCollection
    {
        $this->queries[] = $query;

        $rows = array();

        foreach ($this->items as $item) {
            if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                continue;
            }

            if (array() !== $query->statuses && ! in_array($item->status, $query->statuses, true)) {
                continue;
            }

            if (array() !== $query->include && ! in_array($item->id, $query->include, true)) {
                continue;
            }

            if (array() !== $query->exclude && in_array($item->id, $query->exclude, true)) {
                continue;
            }

            if (array() !== $query->metaQuery && ! $this->matches($item->meta, $query->metaQuery)) {
                continue;
            }

            $rows[] = $item;
        }

        usort(
            $rows,
            static fn (ContentItem $a, ContentItem $b): int => 'ASC' === strtoupper($query->order)
                ? $a->id <=> $b->id
                : $b->id <=> $a->id
        );

        $total   = count($rows);
        $perPage = min(200, max(1, $query->perPage));
        $pages   = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new ContentCollection(
            items: array_slice($rows, ( $query->page - 1 ) * $perPage, $perPage),
            total: $total,
            page: $query->page,
            perPage: $perPage,
            totalPages: $pages,
        );
    }

    public function create(CreateContentCommand $command): ContentItem
    {
        $this->created = $command;
        $id            = $this->nextId++;
        $stamp         = new DateTimeImmutable('2026-01-02 00:00:00', new DateTimeZone('UTC'));

        $item = new ContentItem(
            id: $id,
            title: $command->title,
            content: $command->content,
            status: $command->status,
            type: $command->type,
            authorId: $command->authorId,
            createdAt: $stamp,
            updatedAt: $stamp,
            meta: $command->meta,
            taxonomy: $command->taxonomyInput,
        );

        $this->items[ $id ] = $item;

        return $item;
    }

    public function update(int $id, UpdateContentCommand $command): ContentItem
    {
        $this->updated = $command;
        $item          = $this->items[ $id ] ?? null;

        if (null === $item) {
            throw new NotFoundException('Content not found.');
        }

        if (! $this->userCanAccess($id, $command->userId, 'edit')) {
            throw new AccessDeniedException('Edit not allowed.');
        }

        $taxonomy = $item->taxonomy;

        foreach ($command->taxonomyInput as $name => $names) {
            $taxonomy[ $name ] = array_values($names);
        }

        $item = new ContentItem(
            id: $item->id,
            title: $command->title ?? $item->title,
            content: $command->content ?? $item->content,
            status: $command->status ?? $item->status,
            type: $item->type,
            authorId: $item->authorId,
            createdAt: $item->createdAt,
            updatedAt: new DateTimeImmutable('2026-01-03 00:00:00', new DateTimeZone('UTC')),
            meta: array_merge($item->meta, $command->meta),
            taxonomy: $taxonomy,
            excerpt: $item->excerpt,
            slug: $item->slug,
        );

        $this->items[ $id ] = $item;

        return $item;
    }

    public function delete(int $id, int $userId): void
    {
        if (! isset($this->items[ $id ])) {
            throw new NotFoundException('Content not found.');
        }

        if (! $this->userCanAccess($id, $userId, 'delete')) {
            throw new AccessDeniedException('Delete not allowed.');
        }

        unset($this->items[ $id ]);

        $this->deleted = $id;
    }

    public function getMeta(int $id): array
    {
        return isset($this->items[ $id ]) ? $this->items[ $id ]->meta : array();
    }

    public function getTaxonomyTerms(int $id): array
    {
        return isset($this->items[ $id ]) ? $this->items[ $id ]->taxonomy : array();
    }

    public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
    {
        $names = $this->terms[ $taxonomy ] ?? array();
        $terms = array();

        foreach (array_slice($names, 0, max(1, min(500, $limit))) as $index => $name) {
            $terms[] = array(
                'id'    => $index + 1,
                'name'  => (string) $name,
                'slug'  => strtolower((string) $name),
                'count' => 0,
            );
        }

        return $terms;
    }

    public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
    {
        return $this->access[ $id ][ $operation ] ?? true;
    }

    /**
     * @param array<string, mixed>     $meta    Row meta.
     * @param array<int|string, mixed> $clauses Clause list or group.
     */
    private function matches(array $meta, array $clauses): bool
    {
        $relation = 'or' === strtolower((string) ( $clauses['relation'] ?? 'AND' )) ? 'or' : 'and';
        $results  = array();

        foreach ($clauses as $name => $clause) {
            if ('relation' === $name || ! is_array($clause)) {
                continue;
            }

            $results[] = isset($clause['key']) ? $this->clause($meta, $clause) : $this->matches($meta, $clause);
        }

        if (array() === $results) {
            return true;
        }

        return 'or' === $relation ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * @param array<string, mixed> $meta   Row meta.
     * @param array<string, mixed> $clause One clause.
     */
    private function clause(array $meta, array $clause): bool
    {
        $key     = (string) ( $clause['key'] ?? '' );
        $compare = strtoupper((string) ( $clause['compare'] ?? '=' ));
        $exists  = array_key_exists($key, $meta) && null !== $meta[ $key ];

        if ('NOT EXISTS' === $compare) {
            return ! $exists;
        }

        if ('EXISTS' === $compare) {
            return $exists;
        }

        if (! $exists) {
            return false;
        }

        $row   = $meta[ $key ];
        $value = $clause['value'] ?? null;

        switch ($compare) {
            case 'IN':
            case 'NOT IN':
                $values = array_map('strval', is_array($value) ? $value : array($value));
                $found  = is_scalar($row) && in_array((string) $row, $values, true);

                return 'IN' === $compare ? $found : ! $found;

            case 'BETWEEN':
            case 'NOT BETWEEN':
                $bounds = is_array($value) ? array_values($value) : array();

                if (2 !== count($bounds) || ! is_scalar($row)) {
                    return false;
                }

                $inside = (string) $row >= (string) $bounds[0] && (string) $row <= (string) $bounds[1];

                return 'BETWEEN' === $compare ? $inside : ! $inside;

            case 'LIKE':
                return $this->like($row, (string) $value);

            case '=':
                return is_scalar($row) && (string) $row === (string) $value;

            case '>':
                return is_scalar($row) && (string) $row > (string) $value;

            case '>=':
                return is_scalar($row) && (string) $row >= (string) $value;

            case '<':
                return is_scalar($row) && (string) $row < (string) $value;

            case '<=':
                return is_scalar($row) && (string) $row <= (string) $value;
        }

        throw new \LogicException('Unhandled meta compare ' . $compare . '.');
    }

    /**
     * Match a `%`-wrapped pattern against the JSON encoding of a value.
     */
    private function like(mixed $row, string $pattern): bool
    {
        $subject = is_scalar($row)
            ? (string) $row
            : (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $regex   = '/^' . str_replace('%', '.*', preg_quote($pattern, '/')) . '$/s';

        return 1 === preg_match($regex, $subject);
    }
}
