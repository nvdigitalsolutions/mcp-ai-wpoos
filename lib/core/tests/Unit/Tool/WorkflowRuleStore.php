<?php

/**
 * An in-memory content store for the workflow-rule tests.
 *
 * Proposal 164 section 164.159. Unlike the read-only stores in the sibling
 * regulatory tests, this one implements writes, because the workflow tools
 * create rules and audit entries and the assertions are about what survived —
 * a deletion in particular, whose audit entry has to outlive the rule it names.
 *
 * Meta is merged on update, matching `UpdateContentCommand`, and queries answer
 * type and equality clauses so `collectContent()` paging is exercised rather
 * than bypassed.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;

final class WorkflowRuleStore implements ContentStoreInterface
{
    /** @var array<int, ContentItem> */
    private array $items = array();

    private int $nextId = 1;

    public function add(ContentItem $item): void
    {
        $this->items[ $item->id ] = $item;
        $this->nextId            = max($this->nextId, $item->id + 1);
    }

    /**
     * Every stored item of a type, oldest id first.
     *
     * @return list<ContentItem>
     */
    public function byType(string $type): array
    {
        $matched = array();

        foreach ($this->items as $item) {
            if ($type === $item->type) {
                $matched[] = $item;
            }
        }

        usort($matched, static fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id);

        return $matched;
    }

    public function find(int $id, ?int $userId = null): ?ContentItem
    {
        return $this->items[ $id ] ?? null;
    }

    public function query(ContentQuery $query): ContentCollection
    {
        $matched = array();

        foreach ($this->items as $item) {
            if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                continue;
            }

            if (! $this->matchesMeta($item, $query->metaQuery)) {
                continue;
            }

            $matched[] = $item;
        }

        usort($matched, static fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id);

        $perPage = $query->perPage > 0 ? $query->perPage : 20;
        $offset  = ( max(1, $query->page) - 1 ) * $perPage;
        $page    = array_slice($matched, $offset, $perPage);

        return new ContentCollection(
            $page,
            count($matched),
            max(1, $query->page),
            $perPage,
            (int) ceil(count($matched) / $perPage)
        );
    }

    public function create(CreateContentCommand $command): ContentItem
    {
        $id = $this->nextId++;

        $item = new ContentItem(
            id: $id,
            title: $command->title,
            content: $command->content,
            status: $command->status,
            type: $command->type,
            authorId: $command->authorId,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            meta: $command->meta,
            taxonomy: $command->taxonomyInput,
            excerpt: $command->excerpt,
        );

        $this->add($item);

        return $item;
    }

    public function update(int $id, UpdateContentCommand $command): ContentItem
    {
        $current = $this->items[ $id ] ?? null;

        if (null === $current) {
            throw new \RuntimeException('No such item: ' . $id);
        }

        $item = new ContentItem(
            id: $current->id,
            title: $command->title ?? $current->title,
            content: $command->content ?? $current->content,
            status: $command->status ?? $current->status,
            type: $current->type,
            authorId: $current->authorId,
            createdAt: $current->createdAt,
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: array_merge($current->meta, $command->meta),
            taxonomy: $command->taxonomyInput,
            excerpt: $command->excerpt ?? $current->excerpt,
            slug: $current->slug,
        );

        $this->items[ $id ] = $item;

        return $item;
    }

    public function delete(int $id, int $userId): void
    {
        if (! isset($this->items[ $id ])) {
            throw new \RuntimeException('No such item: ' . $id);
        }

        unset($this->items[ $id ]);
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
        return array();
    }

    public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
    {
        return isset($this->items[ $id ]);
    }

    /**
     * @param array<int|string, mixed> $clauses
     */
    private function matchesMeta(ContentItem $item, array $clauses): bool
    {
        if (array() === $clauses) {
            return true;
        }

        $relation = 'OR' === strtoupper((string) ( $clauses['relation'] ?? 'AND' )) ? 'OR' : 'AND';

        foreach ($clauses as $name => $clause) {
            if ('relation' === $name || ! is_array($clause)) {
                continue;
            }

            $pass = $this->matchesClause($item, $clause);

            if ('OR' === $relation && $pass) {
                return true;
            }

            if ('AND' === $relation && ! $pass) {
                return false;
            }
        }

        return 'OR' !== $relation;
    }

    /**
     * @param array<string, mixed> $clause
     */
    private function matchesClause(ContentItem $item, array $clause): bool
    {
        if (isset($clause['relation'])) {
            return $this->matchesMeta($item, $clause);
        }

        $key     = (string) ( $clause['key'] ?? '' );
        $compare = strtoupper((string) ( $clause['compare'] ?? '=' ));
        $value   = $clause['value'] ?? null;
        $stored  = $item->meta[ $key ] ?? null;

        if ('NOT EXISTS' === $compare) {
            return null === $stored;
        }

        if ('EXISTS' === $compare) {
            return null !== $stored;
        }

        return is_scalar($stored) && (string) $stored === (string) $value;
    }
}
