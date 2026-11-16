<?php

namespace App\Observers;

use App\Observers\Concerns\RecordsAdminChanges;

/**
 * Record-level audit for estates (Proposal 149 T-0.9 debt, Wave 2).
 *
 * Attached the moment the first admin resource exists. The rules live in
 * `RecordsAdminChanges` so every administrable model records the same way; this
 * class only names the resource.
 *
 * Estates are audited on the operator panel, which is why the row records no
 * `tenant_id` for the estate being changed: an operator action belongs to no
 * single estate (`AdminAuditLogger` writes null there by design).
 */
final class TenantObserver
{
    use RecordsAdminChanges;

    protected function auditResource(): string
    {
        return 'estate';
    }
}
