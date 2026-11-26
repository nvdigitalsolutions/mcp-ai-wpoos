<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\CapabilityMap;
use App\Support\CredentialStore;
use App\Support\RemoteSites\RemoteSiteStore;
use App\Support\RemoteSites\RemoteSiteTypes;
use App\Support\RemoteSites\RemoteSiteVerifier;
use BackedEnum;
use UnitEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Remote sites — Proposal 149 §42, `/admin/{estate}/remote-sites`.
 *
 * The console has owned this surface since 090-i (`remoteSites`). §27 made the
 * store estate-scoped after the table shipped without a tenant column, and
 * recorded "the `/admin` page follows in a later slice". This is that slice.
 *
 * SUBSET, not full, and the contract row says so. List, test, enable/disable and
 * delete are here; **create and edit are not**. The console's wizard renders a
 * per-type field schema from {@see RemoteSiteTypes} and posts it flat, and the
 * controller splits it back into `config` + `credentials`. Rebuilding that in
 * Livewire would be the same wizard twice, with a second place to keep the
 * secret/non-secret split correct — so the panel inspects and operates, the
 * console authors.
 *
 * Two rules this page inherits rather than reimplements:
 *
 *  - **The store is the boundary.** Every read and write goes through
 *    {@see RemoteSiteStore}, whose `query()` helper filters on `tenant_id`. A
 *    connection id from another estate answers `null` here exactly as it answers
 *    404 on the API, so a tampered Livewire payload cannot test, disable or
 *    delete across estates.
 *  - **No credential is ever rendered.** The store's row carries
 *    `has_credentials` and a uniform mask; this page shows presence only, and
 *    the Test action is the only place `credentialsFor()` is opened — runtime
 *    only, handed to the verifier, never to the view.
 *
 * The Test action is audited because it is an *effect*: it sends the row's
 * sealed credentials to the URL the row names. Panel actions travel over
 * `/livewire/update`, which `AuditAdminRequests` deliberately skips as UI
 * mechanics, so without `recordResource()` a connection could be exercised
 * against a foreign endpoint with no trace.
 *
 * Access is stricter than the panel's default on purpose. `/admin` admits any
 * non-viewer member; this page additionally requires `manage_options`, because
 * `RemoteSitesApiTest` pins that on the REST surface and the two presentations
 * must not disagree about who may touch a credential-bearing record.
 */
class RemoteSitesPage extends Page
{
    protected static ?string $slug = 'remote-sites';

    protected static ?string $navigationLabel = 'Remote sites';

    protected static ?string $title = 'Remote sites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Connections;

    // After MCP apps: an app connects one assistant, a site connects the estate.
    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.remote-sites';

    /** Post-action status line; empty when nothing has happened this request. */
    public string $status = '';

    /** Tone for the status line: `info`, `success` or `danger`. */
    public string $statusTone = 'info';

    /**
     * Connection id armed for deletion; empty when nothing is armed.
     *
     * Two-step on purpose: the row's button becomes "Confirm delete" and only
     * the second click removes it. A connection is a small record, but it is the
     * only record of an endpoint other surfaces depend on, and a single misclick
     * in a list of look-alike rows is how the wrong one goes.
     */
    public string $pendingDelete = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    /**
     * The estate's connections, redacted and alphabetically sorted.
     *
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        return $this->store()->all();
    }

    /**
     * Type id => human label, from the same catalogue the console renders.
     *
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        $labels = array();

        foreach (RemoteSiteTypes::all() as $type) {
            $labels[(string) $type['id']] = (string) $type['label'];
        }

        return $labels;
    }

    /**
     * Run the same verification the console's Test button runs.
     *
     * The verifier is the shared one, so the panel and the console cannot report
     * different things about the same endpoint. A missing row is reported, not
     * thrown: a stale page after another session deleted the connection is a
     * normal race, not an error.
     */
    public function testConnection(string $id): void
    {
        $site = $this->store()->find($id);

        if (null === $site) {
            $this->report('danger', 'That connection is not in this estate; nothing was tested.');

            return;
        }

        $result = app(RemoteSiteVerifier::class)->verify(
            (string) $site['connection_type'],
            (string) $site['url'],
            array_merge((array) $site['config'], $this->store()->credentialsFor($id)),
        );

        $this->store()->recordTest($id, (bool) $result['success']);

        $this->report(
            $result['success'] ? 'success' : 'danger',
            $result['success'] ? 'Test passed: '.$result['message'] : 'Test failed: '.$result['message'],
        );

        // Host and outcome only. The URL's path can carry a secret (the §30
        // webhook rule), and the credentials are never in scope here anyway.
        app(AdminAuditLogger::class)->recordResource(
            'remote-sites',
            $id,
            'tested',
            array(),
            array(
                'host' => $this->host((string) $site['url']),
                'success' => (bool) $result['success'],
            )
        );
    }

    /**
     * Flip the connection's enabled flag.
     *
     * A disabled connection is skipped by whatever consumes it; the flag is data,
     * not a pause switch with side effects, so this is a single click rather than
     * an armed action.
     */
    public function toggleEnabled(string $id): void
    {
        $site = $this->store()->find($id);

        if (null === $site) {
            $this->report('danger', 'That connection is not in this estate; nothing was changed.');

            return;
        }

        $enabled = ! (bool) $site['enabled'];

        $this->store()->update($id, array('enabled' => $enabled));

        $this->report(
            'info',
            sprintf('"%s" is now %s.', (string) $site['name'], $enabled ? 'enabled' : 'disabled'),
        );

        app(AdminAuditLogger::class)->recordResource(
            'remote-sites',
            $id,
            $enabled ? 'enabled' : 'disabled',
            array('enabled' => ! $enabled),
            array('enabled' => $enabled)
        );
    }

    /**
     * Arm a connection for deletion. A second call with the same id is a no-op;
     * only the confirm button deletes.
     */
    public function confirmDelete(string $id): void
    {
        $this->pendingDelete = $id;
        $this->status = '';
        $this->statusTone = 'info';
    }

    public function cancelDelete(): void
    {
        $this->pendingDelete = '';
    }

    /**
     * Delete the armed connection.
     *
     * The estate is re-resolved from the panel tenant at action time, and the
     * store's `delete()` is itself scoped — so armed Livewire state from a
     * foreign row deletes nothing. That refusal is reported as "not found",
     * identical to a connection that never existed.
     */
    public function deleteConnection(): void
    {
        if ('' === $this->pendingDelete) {
            return;
        }

        $id = $this->pendingDelete;
        $site = $this->store()->find($id);

        if (null === $site) {
            $this->report('danger', 'That connection is not in this estate; nothing was deleted.');
            $this->cancelDelete();

            return;
        }

        $this->store()->delete($id);

        $this->report('success', sprintf('"%s" was deleted.', (string) $site['name']));

        app(AdminAuditLogger::class)->recordResource(
            'remote-sites',
            $id,
            'deleted',
            array(
                'name' => (string) $site['name'],
                'connection_type' => (string) $site['connection_type'],
                'enabled' => (bool) $site['enabled'],
            ),
            array()
        );

        $this->cancelDelete();
    }

    /**
     * The estate this page is scoped to.
     *
     * Empty when the panel has no tenant. Every route into this page is
     * tenant-scoped, so that is a misconfiguration rather than a normal state —
     * and an empty estate id matches no row, which is the fail-closed answer.
     * Falling back to `TenantContext` would silently read the default estate
     * instead.
     */
    private function estateId(): string
    {
        $tenant = Filament::getTenant();

        return null === $tenant ? '' : (string) $tenant->getKey();
    }

    /**
     * A store bound to this page's estate, read at query time.
     *
     * Constructed rather than resolved from the container: the container binding
     * reads `TenantContext`, and a panel page must not depend on ambient request
     * state to know which estate it is showing. The closure is per query so a
     * long-lived Octane worker cannot pin a previous estate.
     */
    private function store(): RemoteSiteStore
    {
        return new RemoteSiteStore(
            app(CredentialStore::class),
            fn (): string => $this->estateId()
        );
    }

    private function report(string $tone, string $message): void
    {
        $this->statusTone = $tone;
        $this->status = $message;
    }

    /**
     * The URL's host, for audit rows. Empty when the stored URL has none.
     */
    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }
}
