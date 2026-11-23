<?php

namespace App\Support\Identity;

use Nvoos\Core\Domain\Contract\RuntimeIdentityInterface;

/**
 * This deployment's public identity — Proposal 090-M §14.
 *
 * Every value already exists somewhere in the app; this class is the one place
 * they are assembled, so the identity tool cannot disagree with the diagnostics
 * payload or with whatever a future status endpoint reports:
 *
 *  - `name` is the product, matching the WordPress tool's `NV oOS`.
 *  - `version` is `app.version`, the key four existing readers already expect
 *    (diagnostics page, diagnostics controller twice, assistant portability).
 *    It is declared in `config/app.php` now, with the same `1.2.0` default those
 *    readers used when it was missing, so nothing changes for a deployment that
 *    does not set `APP_VERSION`.
 *  - `runtime` and `contract` match `DiagnosticsController`'s `rest_context`
 *    (`backend: laravel`, `contract: mcp-ai/v1`). There is no MCP JSON-RPC
 *    mount here, so the honest pair is where the API lives and what it speaks.
 *  - `site_name` / `site_url` are the deployment's, not the product's.
 *
 * No secrets: every field is a public identifier.
 */
final class RuntimeIdentity implements RuntimeIdentityInterface
{
    /**
     * {@inheritDoc}
     */
    public function identity(): array
    {
        return array(
            'name'      => 'NV oOS',
            'version'   => (string) config('app.version', '1.2.0'),
            'runtime'   => 'laravel',
            'contract'  => 'mcp-ai/v1',
            'site_name' => (string) config('app.name', 'Laravel'),
            'site_url'  => (string) config('app.url', ''),
            'api_base'  => url('/api/v1'),
        );
    }
}
