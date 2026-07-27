<?php
/**
 * WordPress adapter: TranscriptStoreInterface implementation.
 *
 * Persists chat transcripts to WordPress options (lightweight) with
 * optional JetEngine CCT fallback for permanent storage.
 *
 * @package Nvoos\WordPress
 * @since   2.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

class TranscriptStore implements TranscriptStoreInterface
{
    private const OPTION_KEY = 'wp_mcp_ai_transcripts';
    private const MAX_TRANSCRIPTS = 500;

    public function save(array $transcript): array
    {
        $transcriptId = 'tx_' . \wp_generate_uuid4();
        $transcript['id']        = $transcriptId;
        $transcript['created_at'] = \time();

        // Try JetEngine CCT first for permanent storage.
        if ($this->hasJetEngine()) {
            $cctId = $this->saveToCct($transcript);
            if ($cctId) {
                $transcript['cct_id'] = $cctId;
            }
        }

        // Always save to options as fallback.
        $this->saveToOptions($transcriptId, $transcript);

        return [
            'success'       => true,
            'transcript_id' => $transcriptId,
        ];
    }

    public function get(string $transcriptId): array
    {
        // Try options first (fastest).
        $transcripts = $this->allFromOptions();
        if (isset($transcripts[$transcriptId])) {
            return ['found' => true, 'transcript' => $transcripts[$transcriptId]];
        }

        // Try JetEngine CCT.
        if ($this->hasJetEngine()) {
            $record = $this->getFromCct($transcriptId);
            if ($record) {
                return ['found' => true, 'transcript' => $record];
            }
        }

        return ['found' => false];
    }

    public function list(array $filters = []): array
    {
        $transcripts = $this->allFromOptions();
        $result      = [];

        foreach ($transcripts as $tx) {
            if (!empty($filters['assistant_id']) && ($tx['assistant_id'] ?? '') !== (string) $filters['assistant_id']) {
                continue;
            }
            if (!empty($filters['session_id']) && ($tx['session_id'] ?? '') !== $filters['session_id']) {
                continue;
            }
            $result[] = $tx;
        }

        // Sort by created_at descending.
        \usort($result, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        $limit  = \min(100, \max(1, (int) ($filters['limit'] ?? 50)));
        $offset = \max(0, (int) ($filters['offset'] ?? 0));
        $total  = \count($result);

        return [
            'transcripts' => \array_slice($result, $offset, $limit),
            'total'       => $total,
        ];
    }

    public function delete(string $transcriptId): array
    {
        $transcripts = $this->allFromOptions();
        unset($transcripts[$transcriptId]);
        \update_option(self::OPTION_KEY, $transcripts, false);

        if ($this->hasJetEngine()) {
            $this->deleteFromCct($transcriptId);
        }

        return ['success' => true];
    }

    public function prune(int $olderThanSeconds): array
    {
        $cutoff      = \time() - $olderThanSeconds;
        $transcripts = $this->allFromOptions();
        $deleted     = 0;

        foreach ($transcripts as $id => $tx) {
            if (($tx['created_at'] ?? 0) < $cutoff) {
                unset($transcripts[$id]);
                $deleted++;
            }
        }

        \update_option(self::OPTION_KEY, $transcripts, false);

        return [
            'success'       => true,
            'deleted_count' => $deleted,
        ];
    }

    public function getCounts(array $filters = []): array
    {
        $transcripts = $this->allFromOptions();
        $byAssistant = [];

        foreach ($transcripts as $tx) {
            $aid = $tx['assistant_id'] ?? 'unknown';
            $byAssistant[$aid] = ($byAssistant[$aid] ?? 0) + 1;
        }

        return [
            'total'        => \count($transcripts),
            'by_assistant' => $byAssistant,
        ];
    }

    public function isAvailable(): bool
    {
        return true; // Options are always available.
    }

    // ─── Private helpers ──────────────────────────────────────────────

    /** @return array<string, array> */
    private function allFromOptions(): array
    {
        $data = \get_option(self::OPTION_KEY, []);
        return \is_array($data) ? $data : [];
    }

    private function saveToOptions(string $id, array $transcript): void
    {
        $transcripts = $this->allFromOptions();
        $transcripts[$id] = $transcript;

        // Trim oldest entries if exceeding max.
        if (\count($transcripts) > self::MAX_TRANSCRIPTS) {
            \uasort($transcripts, fn($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
            $transcripts = \array_slice($transcripts, -self::MAX_TRANSCRIPTS, null, true);
        }

        \update_option(self::OPTION_KEY, $transcripts, false);
    }

    private function hasJetEngine(): bool
    {
        return \function_exists('jet_engine') && \function_exists('jet_engine()->cct');
    }

    private function saveToCct(array $transcript): ?int
    {
        if (!\has_action('jet-engine/cct/init')) {
            return null;
        }

        try {
            $cct = \jet_engine()->cct->register_cct('mcp_ai_transcript', [
                'labels' => ['name' => 'Chat Transcripts'],
                'fields' => [
                    ['name' => 'transcript_id', 'type' => 'text'],
                    ['name' => 'assistant_id',  'type' => 'text'],
                    ['name' => 'session_id',    'type' => 'text'],
                    ['name' => 'messages',       'type' => 'textarea'],
                    ['name' => 'metadata_json', 'type' => 'textarea'],
                ],
            ]);

            $itemId = \jet_engine()->cct->data->create_item([
                'transcript_id' => $transcript['id'],
                'assistant_id'  => (string) ($transcript['assistant_id'] ?? ''),
                'session_id'    => $transcript['session_id'] ?? '',
                'messages'      => \wp_json_encode($transcript['messages'] ?? []),
                'metadata_json' => \wp_json_encode($transcript['metadata'] ?? []),
            ]);

            return \is_int($itemId) ? $itemId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getFromCct(string $transcriptId): ?array
    {
        if (!$this->hasJetEngine()) {
            return null;
        }

        try {
            $items = \jet_engine()->cct->data->query([
                'where' => ['transcript_id' => $transcriptId],
                'limit' => 1,
            ]);

            if (empty($items)) {
                return null;
            }

            $item = $items[0];
            return [
                'id'           => $item['transcript_id'] ?? $transcriptId,
                'assistant_id' => $item['assistant_id'] ?? '',
                'session_id'   => $item['session_id'] ?? '',
                'messages'     => \json_decode($item['messages'] ?? '[]', true) ?: [],
                'metadata'     => \json_decode($item['metadata_json'] ?? '{}', true) ?: [],
                'cct_id'       => $item['_ID'] ?? null,
                'created_at'   => $item['created_at'] ?? null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function deleteFromCct(string $transcriptId): void
    {
        if (!$this->hasJetEngine()) {
            return;
        }

        try {
            $items = \jet_engine()->cct->data->query([
                'where' => ['transcript_id' => $transcriptId],
                'limit' => 1,
            ]);

            if (!empty($items)) {
                \jet_engine()->cct->data->delete_item($items[0]['_ID']);
            }
        } catch (\Throwable $e) {
            // Silently fail — options cleanup already succeeded.
        }
    }
}
