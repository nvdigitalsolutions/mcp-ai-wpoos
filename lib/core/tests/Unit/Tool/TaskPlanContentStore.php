<?php

/**
 * In-memory content store for the task-plan and template tests.
 *
 * Evaluates the queries the eight tools build: type and status filters, the
 * category meta clause, search, page/per-page and the orderBy values the store
 * contract allows. It also enforces the edit rule the real adapter delegates to
 * a Gate — author or an explicit editor — so the permission paths are exercised
 * rather than mocked.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

final class TaskPlanContentStore implements ContentStoreInterface
{
    /** @var array<int, ContentItem> */
    public array $items = array();

    /** @var array<int, true> Users allowed to edit any item. */
    public array $editors = array();

    private int $nextId = 1;

    public function create(CreateContentCommand $command): ContentItem
    {
        $now  = new \DateTimeImmutable();
        $item = new ContentItem(
            id: $this->nextId++,
            title: $command->title,
            content: $command->content,
            status: $command->status,
            type: $command->type,
            authorId: $command->authorId,
            createdAt: $now,
            updatedAt: $now,
            meta: $command->meta,
            taxonomy: $command->taxonomyInput,
            excerpt: $command->excerpt,
        );

        $this->items[ $item->id ] = $item;

        return $item;
    }

    public function find(int $id, ?int $userId = null): ?ContentItem
    {
        $item = $this->items[ $id ] ?? null;

        if (null === $item) {
            return null;
        }

        if (null !== $userId && ! $this->userCanAccess($id, $userId, 'read')) {
            return null;
        }

        return $item;
    }

    public function query(ContentQuery $query): ContentCollection
    {
        $matching = array();

        foreach ($this->items as $item) {
            if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                continue;
            }

            if (array() !== $query->statuses && ! in_array($item->status, $query->statuses, true)) {
                continue;
            }

            if (null !== $query->authorId && $item->authorId !== $query->authorId) {
                continue;
            }

            if (! $this->matchesMeta($item, $query->metaQuery)) {
                continue;
            }

            if (null !== $query->search && '' !== $query->search) {
                $haystack = strtolower($item->title . ' ' . $item->content);

                if (! str_contains($haystack, strtolower($query->search))) {
                    continue;
                }
            }

            $matching[] = $item;
        }

        usort(
            $matching,
            static function (ContentItem $left, ContentItem $right) use ($query): int {
                $comparison = match ($query->orderBy) {
                    'title'    => strcasecmp($left->title, $right->title),
                    'modified' => $left->updatedAt->getTimestamp() <=> $right->updatedAt->getTimestamp(),
                    'id'       => $left->id <=> $right->id,
                    default    => $left->createdAt->getTimestamp() <=> $right->createdAt->getTimestamp(),
                };

                return 'ASC' === strtoupper($query->order) ? $comparison : -$comparison;
            }
        );

        $total   = count($matching);
        $perPage = max(1, min($query->perPage, 200));
        $page    = max(1, $query->page);
        $items   = array_slice($matching, ( $page - 1 ) * $perPage, $perPage);

        return new ContentCollection(
            $items,
            $total,
            $page,
            $perPage,
            (int) ceil($total / $perPage)
        );
    }

    public function update(int $id, UpdateContentCommand $command): ContentItem
    {
        $item = $this->items[ $id ] ?? null;

        if (null === $item) {
            throw new NotFoundException('Not found.', 'content', $id);
        }

        if (! $this->userCanAccess($id, $command->userId, 'edit')) {
            throw new AccessDeniedException('Denied.', $command->userId, 'edit_content', $id);
        }

        $updated = new ContentItem(
            id: $item->id,
            title: $command->title ?? $item->title,
            content: $command->content ?? $item->content,
            status: $command->status ?? $item->status,
            type: $item->type,
            authorId: $item->authorId,
            createdAt: $item->createdAt,
            updatedAt: new \DateTimeImmutable(),
            meta: array_merge($item->meta, $command->meta),
            taxonomy: array() !== $command->taxonomyInput ? $command->taxonomyInput : $item->taxonomy,
            excerpt: $command->excerpt ?? $item->excerpt,
        );

        $this->items[ $id ] = $updated;

        return $updated;
    }

    public function delete(int $id, int $userId): void
    {
        if (! isset($this->items[ $id ])) {
            throw new NotFoundException('Not found.', 'content', $id);
        }

        if (! $this->userCanAccess($id, $userId, 'delete')) {
            throw new AccessDeniedException('Denied.', $userId, 'delete_content', $id);
        }

        unset($this->items[ $id ]);
    }

    public function getMeta(int $id): array
    {
        return $this->items[ $id ]->meta ?? array();
    }

    public function getTaxonomyTerms(int $id): array
    {
        return $this->items[ $id ]->taxonomy ?? array();
    }

    public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
    {
        return array();
    }

    public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
    {
        $item = $this->items[ $id ] ?? null;

        if (null === $item || $userId <= 0) {
            return false;
        }

        if ('read' === $operation) {
            return true;
        }

        return $item->authorId === $userId || isset($this->editors[ $userId ]);
    }

    /**
     * The subset of WP_Meta_Query the tools use: one exact-match clause per key.
     *
     * @param array<int|string, mixed> $metaQuery Clauses.
     */
    private function matchesMeta(ContentItem $item, array $metaQuery): bool
    {
        foreach ($metaQuery as $clause) {
            if (! is_array($clause) || ! isset($clause['key'])) {
                continue;
            }

            $key    = (string) $clause['key'];
            $value  = $clause['value'] ?? null;
            $actual = $item->meta[ $key ] ?? null;

            if ((string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
