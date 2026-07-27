<?php
declare(strict_types=1);
namespace Nvoos\WordPress\Adapter;
use Nvoos\Core\Domain\Contract\ChatContinuationInterface;

final class ChatContinuation implements ChatContinuationInterface
{
    private $store;
    private $dispatcher;

    public function __construct() {
        if (\class_exists('WP_MCP_AI_Chat_Continuation_Store')) {
            $this->store = new \WP_MCP_AI_Chat_Continuation_Store();
        }
        if (\class_exists('WP_MCP_AI_Chat_Continuation_Dispatcher')) {
            $this->dispatcher = true; // static class, just track availability
        }
    }

    public function save(string $jobId, string $sessionId, array $conversationState, array $metadata = []): array {
        if (!$this->store || !\method_exists($this->store, 'save_snapshot')) {
            return ['success' => false, 'continuation_id' => $jobId, 'error' => 'Store unavailable'];
        }
        try {
            $result = $this->store->save_snapshot($jobId, $sessionId, $conversationState, $metadata);
            if (\is_wp_error($result)) {
                return ['success' => false, 'continuation_id' => $jobId, 'error' => $result->get_error_message()];
            }
            return ['success' => true, 'continuation_id' => $jobId];
        } catch (\Throwable $e) {
            return ['success' => false, 'continuation_id' => $jobId, 'error' => $e->getMessage()];
        }
    }

    public function load(string $continuationId): array {
        if (!$this->store || !\method_exists($this->store, 'load_snapshot')) {
            return ['found' => false];
        }
        try {
            $result = $this->store->load_snapshot($continuationId);
            if ($result === false || $result === null) { return ['found' => false]; }
            return ['found' => true, 'state' => $result['state'] ?? [], 'metadata' => $result['metadata'] ?? []];
        } catch (\Throwable $e) {
            return ['found' => false];
        }
    }

    public function complete(string $continuationId, array $result = []): array {
        if (!$this->store || !\method_exists($this->store, 'mark_completed')) {
            return ['success' => false, 'error' => 'Store unavailable'];
        }
        try {
            $r = $this->store->mark_completed($continuationId, $result);
            if (\is_wp_error($r)) { return ['success' => false, 'error' => $r->get_error_message()]; }
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function listBySession(string $sessionId): array {
        if (!$this->store || !\method_exists($this->store, 'list_by_session')) {
            return [];
        }
        try {
            $result = $this->store->list_by_session($sessionId);
            return \is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function delete(string $continuationId): void {
        if ($this->store && \method_exists($this->store, 'delete_snapshot')) {
            try { $this->store->delete_snapshot($continuationId); } catch (\Throwable $e) {}
        }
    }

    public function getCounts(string $sessionId = ''): array {
        if (!$this->store || !\method_exists($this->store, 'get_counts')) {
            return ['total' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0];
        }
        try {
            $result = $this->store->get_counts($sessionId);
            return \is_array($result) ? $result : ['total' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0];
        } catch (\Throwable $e) {
            return ['total' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0];
        }
    }
}
