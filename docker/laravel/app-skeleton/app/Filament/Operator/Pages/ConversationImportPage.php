<?php

namespace App\Filament\Operator\Pages;

use App\Models\Tenant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Import\ConversationImport;
use App\Support\TenantContext;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

/**
 * Conversation import — Proposal 149 §37, `/ops/conversation-import`.
 *
 * Paste an export body, preview what it will do, then import. The preview is a
 * separate step on purpose: importing is a write into the estate's transcript
 * store, and a 500-conversation body imported blind is not something a user can
 * undo one row at a time.
 *
 * **The target estate is chosen on the page.** `/ops` has no selected estate
 * (149 §21), so before §37.5 every import landed in `default` by fallback. The
 * selector makes the write scope explicit, pins `TenantContext` for the import
 * action only, and records the estate on the audit row — the operator panel's
 * own tenant is null, so the row could not otherwise say where the write went.
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
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Platform;
    protected static ?int $navigationSort = 27;

    protected string $view = 'filament.operator.conversation-import';

    /** The pasted export body. */
    public string $body = '';

    /**
     * Target estate id for the import.
     *
     * The operator panel deliberately has **no** selected estate (149 §21), so
     * this page names its own target. Before this field existed every import
     * landed in `default` — the same implicit single-estate fallback the
     * console API had before §21. `default` stays the initial value so an
     * operator who ignores the selector gets today's behaviour, but it is now
     * a visible choice rather than an accident.
     */
    public string $estate = Tenant::DEFAULT_ID;

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
     * Estates this operator may import into, alphabetical.
     *
     * Every estate is a valid target: the whole premise of `/ops` is that an
     * operator sees across estates. Which estate is being written to is the
     * question this list answers.
     *
     * @return list<array{id: string, name: string}>
     */
    public function estates(): array
    {
        return Tenant::query()
            ->orderBy('name')
            ->get(array('id', 'name'))
            ->map(static fn (Tenant $tenant): array => array(
                'id' => (string) $tenant->getKey(),
                'name' => (string) $tenant->name,
            ))
            ->all();
    }

    /**
     * Import into the selected estate's transcript store.
     */
    public function import(): void
    {
        $target = trim($this->estate);

        // A tampered Livewire payload must not create a transcript under an
        // estate that does not exist, and an empty selector must not fall back
        // to `default` silently — the whole point of the field is that the
        // target is explicit.
        if ('' === $target || null === Tenant::query()->find($target)) {
            $this->mode = 'import';
            $this->report = array(
                'fatal' => 'Choose an estate before importing — nothing was written.',
                'imported' => 0,
                'failed' => 0,
                'messages' => 0,
                'truncated' => 0,
                'errors' => array(),
            );

            return;
        }

        // Pin the target for this action only, then restore. `/ops` has no
        // ambient estate, and a write path must never leave one pinned for the
        // next Livewire request in the same worker (the `finally` is the rule
        // §29 records for `nvoos:fire-triggers`).
        $previous = TenantContext::isPinned() ? TenantContext::current() : null;

        TenantContext::set($target);

        try {
            $report = ConversationImport::import(
                $this->body,
                app(TranscriptStoreInterface::class)
            );
        } finally {
            TenantContext::set($previous);
        }

        $this->mode = 'import';
        $this->report = $report;

        if ('' === (string) $report['fatal'] && $report['imported'] > 0) {
            // An import is a bulk write into an estate's transcript store, so it
            // leaves a record row. The row carries the target estate and counts,
            // never content. The estate id is here because the operator panel's
            // own tenant is null — without it the row cannot say where the
            // write went.
            app(AdminAuditLogger::class)->recordResource(
                'conversation-import',
                gmdate('Ymd-His'),
                'import',
                array(),
                array(
                    'estate' => $target,
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
