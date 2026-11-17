<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Import\ConversationImport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

/**
 * Conversation import — Proposal 149 §37, `/ops/conversation-import`.
 *
 * Paste an export body, preview what it will do, then import. The preview is a
 * separate step on purpose: importing is a write into the estate's transcript
 * store, and a 500-conversation body imported blind is not something a user can
 * undo one row at a time.
 *
 * The caps (`ConversationImport::MAX_*`) are enforced on the string before
 * anything touches storage, and the store upserts by session id — so re-pasting
 * the same export corrects rather than duplicates.
 *
 * Why paste rather than upload: an upload path needs a temporary file store, a
 * MIME gate and a size check before the bytes are even readable. A paste is one
 * code path with no filesystem surface, which is the right trade for a
 * deliberately narrow first version.
 */
class ConversationImportPage extends Page
{
    protected static ?string $slug = 'conversation-import';

    protected static ?string $navigationLabel = 'Conversation import';

    protected static ?string $title = 'Conversation import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    // With the data surfaces, after datasets.
    protected static ?int $navigationSort = 16;

    protected string $view = 'filament.operator.conversation-import';

    /** The pasted export body. */
    public string $body = '';

    /** Whether the last action was a preview or an import. */
    public string $mode = '';

    /**
     * The last report.
     *
     * @var array<string, mixed>
     */
    public array $report = array();

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Parse without writing — the preview step.
     */
    public function preview(): void
    {
        $parsed = ConversationImport::parse($this->body);

        $this->mode = 'preview';
        $this->report = array(
            'fatal' => $parsed['fatal'],
            'conversations' => count($parsed['conversations']),
            'messages' => array_sum(array_map(
                static fn (array $conversation): int => count($conversation['messages']),
                $parsed['conversations']
            )),
            'truncated' => array_sum(array_column($parsed['conversations'], 'truncated')),
            'errors' => $parsed['errors'],
        );
    }

    /**
     * Import into the current estate's transcript store.
     */
    public function import(): void
    {
        $report = ConversationImport::import(
            $this->body,
            app(TranscriptStoreInterface::class)
        );

        $this->mode = 'import';
        $this->report = $report;

        if ('' === (string) $report['fatal'] && $report['imported'] > 0) {
            // An import is a bulk write into an estate's transcript store, so it
            // leaves a record row. The row carries counts, never content.
            app(AdminAuditLogger::class)->recordResource(
                'conversation-import',
                gmdate('Ymd-His'),
                'import',
                array(),
                array(
                    'conversations' => (int) $report['imported'],
                    'messages' => (int) $report['messages'],
                    'failed' => (int) $report['failed'],
                    'truncated' => (int) $report['truncated'],
                )
            );

            // The body has been consumed; clearing it stops an accidental
            // double-submit from re-running the same import.
            $this->body = '';
        }
    }

    /**
     * @return array{fatal: string, imported?: int, failed?: int, messages: int, truncated: int, errors: list<string>}
     */
    public function lastReport(): array
    {
        return $this->report;
    }

    public function hasReport(): bool
    {
        return array() !== $this->report;
    }

    /**
     * The caps, so the page states them rather than surprising the user.
     *
     * @return array{bytes: int, conversations: int, messages: int}
     */
    public function limits(): array
    {
        return array(
            'bytes' => ConversationImport::MAX_BYTES,
            'conversations' => ConversationImport::MAX_CONVERSATIONS,
            'messages' => ConversationImport::MAX_MESSAGES,
        );
    }
}
