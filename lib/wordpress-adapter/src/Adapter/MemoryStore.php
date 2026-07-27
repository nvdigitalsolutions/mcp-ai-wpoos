<?php
/**
 * WordPress Adapter — Memory Store.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\MemoryStoreInterface;

/**
 * WordPress adapter for agent memory storage.
 *
 * Bridges to the legacy memory pipeline (MemoryCaptureService,
 * AgentContextManager, MemoryManager, etc.).
 *
 * @since 2.0.0
 */
final class MemoryStore implements MemoryStoreInterface
{
    public function store(array $record): array
    {
        if (!\class_exists('WP_MCP_AI_Memory_Capture_Service')) {
            return ['success' => false, 'error' => 'Memory capture service unavailable'];
        }

        try {
            $service = \WP_MCP_AI_Memory_Capture_Service::get_instance();

            /** @var array|object $result */
            $result = $service->store($record);

            if (\is_wp_error($result)) {
                return ['success' => false, 'error' => $result->get_error_message()];
            }

            return \array_merge(
                ['success' => true],
                \is_array($result) ? $result : []
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function get(string $memoryId): array
    {
        if (!\class_exists('WP_MCP_AI_Agent_Context_Manager')) {
            return ['found' => false];
        }

        try {
            $manager = new \WP_MCP_AI_Agent_Context_Manager();

            if (\method_exists($manager, 'get_context')) {
                /** @var array|false $record */
                $record = $manager->get_context($memoryId);

                if ($record === false || $record === null) {
                    return ['found' => false];
                }

                return ['found' => true, 'record' => \is_array($record) ? $record : []];
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return ['found' => false];
    }

    public function update(string $memoryId, array $patch): array
    {
        if (!\class_exists('WP_MCP_AI_Memory_Manager')) {
            return ['success' => false, 'error' => 'Memory manager unavailable'];
        }

        try {
            $manager = \WP_MCP_AI_Memory_Manager::get_instance();

            if (\method_exists($manager, 'update_memory')) {
                /** @var bool|object $result */
                $result = $manager->update_memory($memoryId, $patch);

                if (\is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }

                return ['success' => true];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => false, 'error' => 'Update not supported'];
    }

    public function delete(string $memoryId): array
    {
        if (!\class_exists('WP_MCP_AI_Memory_Manager')) {
            return ['success' => false, 'error' => 'Memory manager unavailable'];
        }

        try {
            $manager = \WP_MCP_AI_Memory_Manager::get_instance();

            if (\method_exists($manager, 'remove')) {
                /** @var bool|object $result */
                $result = $manager->remove($memoryId);

                if (\is_wp_error($result)) {
                    return ['success' => false, 'error' => $result->get_error_message()];
                }

                return ['success' => true];
            }
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => false, 'error' => 'Delete not supported'];
    }

    public function search(string $query, array $filters = [], int $limit = 10): array
    {
        if (!\class_exists('WP_MCP_AI_Memory_RRF_Fusion_Service')) {
            return [];
        }

        try {
            $service = new \WP_MCP_AI_Memory_RRF_Fusion_Service();

            if (\method_exists($service, 'search')) {
                /** @var array<int, array> $results */
                $results = $service->search($query, $filters, $limit);

                return \is_array($results) ? $results : [];
            }
        } catch (\Throwable $e) {
            // Return empty.
        }

        return [];
    }

    public function listByAgent(string $agentId, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if (!\class_exists('WP_MCP_AI_Agent_Context_Manager')) {
            return ['memories' => [], 'total' => 0];
        }

        try {
            $manager = new \WP_MCP_AI_Agent_Context_Manager();

            if (\method_exists($manager, 'list_contexts')) {
                /** @var array $result */
                $result = $manager->list_contexts($agentId, $filters, $limit, $offset);

                return \is_array($result)
                    ? $result
                    : ['memories' => [], 'total' => 0];
            }
        } catch (\Throwable $e) {
            // Fall through.
        }

        return ['memories' => [], 'total' => 0];
    }
}
