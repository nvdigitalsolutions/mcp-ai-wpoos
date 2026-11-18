<?php

namespace App\Services;

use App\Models\Assistant;
use App\Support\AssistantToolShortcuts;
use App\Support\TenantContext;
use Nvoos\Core\Domain\Tool\ToolShortcutNormaliser;

/**
 * The shortcut catalogue for one estate — Proposal 149 §49.
 *
 * Extracted from `ToolShortcutsController`, which owned the resolution inline
 * and got it wrong in two ways that a panel would have made visible:
 *
 *  1. `Assistant::find($assistantId)` was **unscoped**, so an estate could read
 *     another estate's shortcut configuration by passing its id. That is the
 *     §27/§39/§40/§47 class again, and a panel row would have turned it into a
 *     leak an operator could click.
 *  2. the default assistant was resolved with a bare
 *     `where('is_default', true)->value('id')`, which can pick a **foreign**
 *     default when several estates each mark one. `value()` does not care whose
 *     row it returns.
 *
 * Both are answered the same way as the other estate stores: one resolver, one
 * scoped query, and every caller goes through it.
 *
 * The shaping itself is not here — `ToolShortcutNormaliser` in `lib/core` is
 * shared with the WordPress route, so the console cannot tell which runtime
 * answered. This class only decides *whose* shortcuts are being shaped.
 */
final class AssistantShortcutCatalogue
{
    /**
     * Assistants in the current estate, as `id => title`.
     *
     * @return array<int, string>
     */
    public function assistants(): array
    {
        return Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title')
            ->limit(200)
            ->pluck('title', 'id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
    }

    /**
     * The console body: `{shortcuts, categories, assistant_id}`.
     *
     * `$assistantId` of 0 means "the estate's default". An id that belongs to
     * another estate is treated as absent — the caller gets the empty body, not
     * a 404, which is exactly what the console does when it has no assistant
     * selected.
     *
     * @return array{shortcuts: array<int, array<string, string>>, categories: array<int, string>, assistant_id: int}
     */
    public function forAssistant(?int $assistantId = null, string $search = ''): array
    {
        $assistant = $this->resolve($assistantId);

        if (null === $assistant) {
            return array(
                'shortcuts' => array(),
                'categories' => array(),
                'assistant_id' => 0,
            );
        }

        $normalised = ToolShortcutNormaliser::normalise(
            AssistantToolShortcuts::forAssistant($assistant),
            $search,
            (string) __('General'),
            "\u{26A1}"
        );

        return array(
            'shortcuts' => $normalised['shortcuts'],
            'categories' => $normalised['categories'],
            'assistant_id' => (int) $assistant->id,
        );
    }

    /**
     * Resolve an assistant inside the current estate.
     */
    private function resolve(?int $assistantId): ?Assistant
    {
        if (null === $assistantId || $assistantId <= 0) {
            $assistantId = (int) Assistant::query()
                ->where('tenant_id', TenantContext::current())
                ->where('is_default', true)
                ->value('id');
        }

        if ($assistantId <= 0) {
            return null;
        }

        return Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->whereKey($assistantId)
            ->first();
    }
}
