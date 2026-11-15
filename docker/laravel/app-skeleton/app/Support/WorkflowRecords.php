<?php

namespace App\Support;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Exception\NotFoundException;

/**
 * Workflow records — the storage contract shared by the workflow and run
 * controllers.
 *
 * A workflow is an `mcp_ai_workflow` content record carrying **WordPress's**
 * meta keys, so a database copied from a WordPress install lists unchanged.
 * WordPress writes the graph and tags as JSON strings in meta; this runtime
 * writes native arrays. Readers therefore tolerate both forms, writers use
 * the native one (090-h §7).
 */
final class WorkflowRecords
{
    public const TYPE = 'mcp_ai_workflow';

    public const META_GRAPH = '_wp_mcp_ai_workflow_graph';

    public const META_VERSION = '_wp_mcp_ai_workflow_version';

    public const META_TAGS = '_wp_mcp_ai_workflow_tags';

    public const DEFAULT_VERSION = '1.0.0';

    /**
     * Resolve a workflow record, refusing other content types.
     */
    public static function find(ContentStoreInterface $content, int $id): ?ContentItem
    {
        try {
            $item = $content->find($id);
        } catch (NotFoundException) {
            return null;
        }

        return $item instanceof ContentItem && self::TYPE === $item->type ? $item : null;
    }

    /**
     * Summary row, matching WordPress's `get_items()` payload.
     *
     * @return array{id: int, title: string, version: string, tags: list<string>, modified: string}
     */
    public static function summarise(ContentItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'version' => self::version($item),
            'tags' => self::readTags($item),
            'modified' => $item->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public static function version(ContentItem $item): string
    {
        $value = $item->meta[self::META_VERSION] ?? '';

        return is_string($value) && '' !== $value ? $value : self::DEFAULT_VERSION;
    }

    /**
     * Bump the patch component, mirroring `WP_MCP_AI_Workflow_CPT::bump_version()`.
     */
    public static function bump(string $current): string
    {
        $parts = explode('.', $current);

        $major = isset($parts[0]) ? (int) $parts[0] : 1;
        $minor = isset($parts[1]) ? (int) $parts[1] : 0;
        $patch = isset($parts[2]) ? (int) $parts[2] : 0;

        return $major.'.'.$minor.'.'.(++$patch);
    }

    /**
     * Graph from meta, tolerating WordPress's JSON-string serialisation.
     *
     * @return array{nodes: list<mixed>, edges: list<mixed>}
     */
    public static function readGraph(ContentItem $item): array
    {
        $raw = self::decode($item->meta[self::META_GRAPH] ?? []);

        if (! is_array($raw)) {
            $raw = [];
        }

        return [
            'nodes' => array_values((array) ($raw['nodes'] ?? [])),
            'edges' => array_values((array) ($raw['edges'] ?? [])),
        ];
    }

    /**
     * Tags from meta, tolerating WordPress's JSON-string serialisation.
     *
     * @return list<string>
     */
    public static function readTags(ContentItem $item): array
    {
        $raw = self::decode($item->meta[self::META_TAGS] ?? []);

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_map('strval', $raw));
    }

    /**
     * Decode a meta value that may be a JSON string.
     */
    private static function decode(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
