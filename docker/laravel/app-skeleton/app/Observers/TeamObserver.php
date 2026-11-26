<?php

namespace App\Observers;

use App\Observers\Concerns\RecordsAdminChanges;

/**
 * Record-level audit for teams (Proposal 149, Wave 2's first `/admin` resource).
 *
 * A team is edited inside an estate panel, so the row carries that estate: the
 * panel's tenant middleware pins `TenantContext` before the write, and
 * `AdminAuditLogger` reads it back.
 *
 * The rules — actor required, timestamps excluded, secrets redacted — live in
 * `RecordsAdminChanges`; two observers with their own copies would drift.
 */
final class TeamObserver
{
    use RecordsAdminChanges;

    protected function auditResource(): string
    {
        return 'team';
    }
}
