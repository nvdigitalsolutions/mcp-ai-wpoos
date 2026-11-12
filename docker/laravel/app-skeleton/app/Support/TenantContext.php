<?php

namespace App\Support;

use App\Models\Assistant;

/**
 * The tenancy seam (090-g).
 *
 * Tenant resolution is a runtime concern: a WordPress site is one tenant, a
 * multi-tenant Laravel deployment resolves the tenant from the request, and
 * the console's single-operator mode falls back to the default tenant. Models
 * and services ask this class instead of hard-coding a tenant, so replacing
 * the fallback with real resolution does not touch call sites.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function current(): string
    {
        return self::$tenantId ?? Assistant::DEFAULT_TENANT;
    }

    /**
     * Pin the tenant for the rest of the request (tests, console jobs).
     */
    public static function set(?string $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function forget(): void
    {
        self::$tenantId = null;
    }
}
