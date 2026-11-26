<?php

namespace App\Observers;

use App\Observers\Concerns\RecordsAdminChanges;

/**
 * Record-level audit for assistants (Proposal 149, Wave 2's fifth slice).
 *
 * An assistant is edited inside an estate panel, so the row carries that
 * estate: the panel's tenant middleware pins `TenantContext` before the write,
 * and `AdminAuditLogger` reads it back. The REST surface writes too, with no
 * panel resolved — the pinned context is the fallback there, and a request made
 * outside any estate legitimately records a null tenant.
 *
 * The rules — actor required, timestamps excluded, secrets redacted — live in
 * `RecordsAdminChanges`; a second copy here would drift from the team and
 * estate observers.
 *
 * Redaction matters on this model more than on most: `meta` is free-form, so a
 * bundle import can put a key named `api_key` in it. The logger's structural
 * rule catches that without this observer knowing it exists.
 */
final class AssistantObserver
{
    use RecordsAdminChanges;

    protected function auditResource(): string
    {
        return 'assistant';
    }
}
