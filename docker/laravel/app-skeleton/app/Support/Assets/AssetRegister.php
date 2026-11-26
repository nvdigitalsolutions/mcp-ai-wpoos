<?php

namespace App\Support\Assets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

/**
 * The ISO 27001 information-asset register — Proposal 149 §33.
 *
 * ## The register is DERIVED, not stored
 *
 * The WordPress source (`WP_MCP_AI_Asset_Inventory`) discovers code directories,
 * configuration options, integrations, data stores and documentation, then
 * persists the result in an option and refreshes it weekly on cron. It persists
 * because its discovery walks the plugin tree and that is expensive.
 *
 * This runtime's discovery is a handful of cheap queries, so it is computed per
 * request. A stored snapshot would be stale the moment a migration adds a table,
 * and a stale inventory in a compliance register is worse than none — the whole
 * point is that it reflects what is actually there.
 *
 * ## Vocabulary
 *
 * `CLASSIFICATIONS` and `TYPES` are copied verbatim from the WordPress constants
 * so an estate migrating between runtimes keeps one vocabulary and one set of
 * report labels. `reports` (§32) keys off them.
 *
 * ## Two things this deliberately does not do
 *
 * 1. **No filesystem walk.** Mtimes over `lib/core` would cost more than the page
 *    is worth, and ISO 27001 asks for classification and ownership, not mtimes.
 *    Code assets are recorded by package presence.
 * 2. **No credential values.** An API-key asset records that a key is *set*,
 *    never what it is. `SettingsStoreInterface::getApiKey()` returns the value to
 *    the caller, so it is read transiently to reduce to a boolean and is never
 *    stored, logged or rendered.
 */
final class AssetRegister
{
    /** ISO 27001 classification, lowest to highest. Mirrors the WordPress constant. */
    public const CLASSIFICATIONS = array(
        'public' => 'Public',
        'internal' => 'Internal',
        'confidential' => 'Confidential',
        'restricted' => 'Restricted',
    );

    /** Asset types. Mirrors the WordPress constant. */
    public const TYPES = array(
        'api_key' => 'API Key/Credential',
        'user_data' => 'User Data',
        'chat_transcript' => 'Chat Transcript',
        'code' => 'Source Code',
        'configuration' => 'Configuration',
        'database' => 'Database',
        'third_party' => 'Third-Party Integration',
        'documentation' => 'Documentation',
    );

    /**
     * Runtime tables and how each is classified.
     *
     * Classification follows content, not engine: a table holding assistant
     * configuration or conversation data is confidential, a table holding
     * credential material is restricted, and everything else is internal.
     *
     * @var array<string, array{0: string, 1: string, 2: string}> label, classification, owner
     */
    private const DATA_TABLES = array(
        'users' => array('User accounts', 'confidential', 'Data Management Team'),
        'assistants' => array('Assistants', 'confidential', 'Data Management Team'),
        'teams' => array('Teams', 'internal', 'Data Management Team'),
        'team_members' => array('Team membership', 'internal', 'Data Management Team'),
        'tenants' => array('Estates', 'internal', 'Data Management Team'),
        'tenant_user' => array('Estate membership', 'internal', 'Data Management Team'),
        'nvoos_settings' => array('Operator settings', 'restricted', 'Security Team'),
        'nvoos_tenant_settings' => array('Estate settings', 'confidential', 'Security Team'),
        'nvoos_admin_audit' => array('Admin audit trail', 'confidential', 'Security Team'),
        'nvoos_security_snapshots' => array('Security posture snapshots', 'confidential', 'Security Team'),
        'nvoos_slash_command_audit' => array('Slash-command audit', 'confidential', 'Security Team'),
        'nvoos_remote_sites' => array('Remote site connections', 'restricted', 'Security Team'),
        'nvoos_approvals' => array('Approval requests', 'confidential', 'Security Team'),
        'nvoos_triggers' => array('Workflow triggers', 'internal', 'Development Team'),
        'nvoos_schedules' => array('Managed schedules', 'internal', 'Development Team'),
        'nvoos_schedule_runs' => array('Schedule run history', 'internal', 'Development Team'),
        'nvoos_workflow_runs' => array('Workflow runs', 'internal', 'Development Team'),
        'nvoos_posts' => array('Content records', 'confidential', 'Data Management Team'),
        'nvoos_meta' => array('Content metadata', 'internal', 'Data Management Team'),
        'nvoos_files' => array('Uploaded files', 'confidential', 'Data Management Team'),
        'nvoos_media_derivatives' => array('Media derivatives', 'internal', 'Data Management Team'),
        'nvoos_toolkit_records' => array('Toolkit records', 'confidential', 'Data Management Team'),
        'nvoos_activity_events' => array('Activity events', 'internal', 'Data Management Team'),
        'nvoos_health_samples' => array('Venue health samples', 'internal', 'Data Management Team'),
        'nvoos_admin_views' => array('Saved admin views', 'internal', 'Development Team'),
        'personal_access_tokens' => array('Access tokens (hashed)', 'restricted', 'Security Team'),
        'sessions' => array('Browser sessions', 'restricted', 'Security Team'),
        'password_reset_tokens' => array('Password reset tokens (hashed)', 'restricted', 'Security Team'),
        'cache' => array('Cache entries', 'internal', 'Development Team'),
        'cache_locks' => array('Cache locks', 'internal', 'Development Team'),
        'jobs' => array('Queued jobs', 'internal', 'Development Team'),
        'job_batches' => array('Job batches', 'internal', 'Development Team'),
        'failed_jobs' => array('Failed jobs', 'internal', 'Development Team'),
    );

    /**
     * Contracts shipped with the runtime. They describe the API and the surface
     * matrix, so they are information assets with an owner like any other.
     *
     * @var array<string, array{0: string, 1: string}> path, label
     */
    private const CONTRACTS = array(
        'contracts/surface-ownership.json' => array('Surface ownership contract', 'internal'),
        'contracts/console-endpoints.json' => array('Console endpoint contract', 'internal'),
    );

    /**
     * The whole register, highest classification first, then by name.
     *
     * @return list<array{id: string, name: string, type: string, classification: string, location: string, owner: string, description: string}>
     */
    public static function assets(SettingsStoreInterface $settings): array
    {
        $assets = array_merge(
            self::configurationAssets(),
            self::credentialAssets($settings),
            self::dataAssets(),
            self::integrationAssets(),
            self::codeAssets(),
            self::contractAssets()
        );

        usort($assets, static function (array $left, array $right): int {
            $order = array_flip(array_keys(self::CLASSIFICATIONS));

            $leftRank = $order[$left['classification']] ?? 0;
            $rightRank = $order[$right['classification']] ?? 0;

            if ($leftRank !== $rightRank) {
                // Highest classification first — the register's own argument.
                return $rightRank <=> $leftRank;
            }

            return strcasecmp($left['name'], $right['name']);
        });

        return $assets;
    }

    /**
     * Assets at one classification.
     *
     * @param list<array<string, mixed>> $assets
     * @return list<array<string, mixed>>
     */
    public static function byClassification(array $assets, string $classification): array
    {
        return array_values(array_filter(
            $assets,
            static fn (array $asset): bool => $classification === $asset['classification']
        ));
    }

    /**
     * Assets of one type.
     *
     * @param list<array<string, mixed>> $assets
     * @return list<array<string, mixed>>
     */
    public static function byType(array $assets, string $type): array
    {
        return array_values(array_filter(
            $assets,
            static fn (array $asset): bool => $type === $asset['type']
        ));
    }

    /**
     * Counts by classification and by type, plus the highest classification in
     * use — the one number an auditor asks for first.
     *
     * @param list<array<string, mixed>> $assets
     * @return array{total: int, by_classification: array<string, int>, by_type: array<string, int>, highest: string}
     */
    public static function statistics(array $assets): array
    {
        $byClassification = array_fill_keys(array_keys(self::CLASSIFICATIONS), 0);
        $byType = array_fill_keys(array_keys(self::TYPES), 0);

        foreach ($assets as $asset) {
            $classification = (string) $asset['classification'];
            $type = (string) $asset['type'];

            if (isset($byClassification[$classification])) {
                ++$byClassification[$classification];
            }

            if (isset($byType[$type])) {
                ++$byType[$type];
            }
        }

        $highest = '';

        foreach (array_keys(self::CLASSIFICATIONS) as $level) {
            if ($byClassification[$level] > 0) {
                $highest = $level;
            }
        }

        return array(
            'total' => count($assets),
            'by_classification' => $byClassification,
            'by_type' => $byType,
            'highest' => $highest,
        );
    }

    /**
     * Filter by name, type label, owner, location or classification label.
     *
     * @param list<array<string, mixed>> $assets
     * @return list<array<string, mixed>>
     */
    public static function filter(array $assets, string $search = '', string $classification = ''): array
    {
        $search = strtolower(trim($search));
        $classification = trim($classification);

        return array_values(array_filter(
            $assets,
            static function (array $asset) use ($search, $classification): bool {
                if ('' !== $classification && $classification !== (string) $asset['classification']) {
                    return false;
                }

                if ('' === $search) {
                    return true;
                }

                $haystack = strtolower(implode(' ', array(
                    (string) $asset['name'],
                    (string) $asset['type'],
                    (string) (self::TYPES[(string) $asset['type']] ?? ''),
                    (string) $asset['owner'],
                    (string) $asset['location'],
                    (string) (self::CLASSIFICATIONS[(string) $asset['classification']] ?? ''),
                )));

                return str_contains($haystack, $search);
            }
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function configurationAssets(): array
    {
        $assets = array();

        if (Schema::hasTable('nvoos_settings')) {
            $assets[] = self::asset(
                'config_operator_settings',
                'Operator settings',
                'configuration',
                'restricted',
                'nvoos_settings table',
                'Security Team',
                'Platform-wide settings, including provider credentials. Writable only from /ops.'
            );
        }

        if (Schema::hasTable('nvoos_tenant_settings')) {
            $assets[] = self::asset(
                'config_tenant_settings',
                'Estate settings',
                'configuration',
                'confidential',
                'nvoos_tenant_settings table',
                'Security Team',
                'Per-estate settings: provider choice, model, temperature and the Ollama endpoint.'
            );
        }

        return $assets;
    }

    /**
     * One asset per provider that actually holds a credential.
     *
     * The value is read to reduce it to a boolean and discarded; nothing here
     * keeps or returns it.
     *
     * @return list<array<string, mixed>>
     */
    private static function credentialAssets(SettingsStoreInterface $settings): array
    {
        try {
            $slugs = array_values(array_map('strval', app(ProviderRouter::class)->getRegisteredSlugs()));
        } catch (\Throwable $exception) {
            return array();
        }

        $assets = array();

        foreach ($slugs as $slug) {
            try {
                $key = $settings->getApiKey($slug);
            } catch (\Throwable $exception) {
                continue;
            }

            if (null === $key || '' === trim($key)) {
                continue;
            }

            $assets[] = self::asset(
                'api_key_'.$slug,
                ucfirst($slug).' credential',
                'api_key',
                'restricted',
                'Settings store (value never displayed)',
                'Security Team',
                'An API key is configured for this provider. Its value is not recorded, displayed or audited.'
            );
        }

        return $assets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function dataAssets(): array
    {
        $assets = array();

        foreach (self::DATA_TABLES as $table => $definition) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            list($label, $classification, $owner) = $definition;

            try {
                $rows = DB::table($table)->count();
            } catch (\Throwable $exception) {
                $rows = -1;
            }

            $assets[] = self::asset(
                'data_table_'.$table,
                $label,
                'database',
                $classification,
                $table.' table',
                $owner,
                $rows < 0
                    ? 'Row count unavailable.'
                    : sprintf('%s row(s).', number_format($rows))
            );
        }

        if (Schema::hasTable('nvoos_workflow_runs')) {
            $assets[] = self::asset(
                'data_chat_transcripts',
                'Chat transcripts',
                'chat_transcript',
                'confidential',
                'Transcript store',
                'Data Management Team',
                'User conversation data. Served by /api/v1/chat-transcripts and scoped per estate.'
            );
        }

        return $assets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function integrationAssets(): array
    {
        try {
            $slugs = array_values(array_map('strval', app(ProviderRouter::class)->getRegisteredSlugs()));
        } catch (\Throwable $exception) {
            return array();
        }

        $assets = array();

        foreach ($slugs as $slug) {
            $assets[] = self::asset(
                'integration_'.$slug,
                ucfirst($slug).' API',
                'third_party',
                'confidential',
                'External API/service',
                'Development Team',
                'Third-party AI provider client registered by the provider router.'
            );
        }

        return $assets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function codeAssets(): array
    {
        $assets = array();

        $packages = array(
            'app' => array('Application code', 'Confidential', 'Development Team'),
            'vendor/nvoos' => array('NV oOS runtime packages', 'Confidential', 'Development Team'),
        );

        foreach ($packages as $path => $definition) {
            if (! is_dir(base_path($path))) {
                continue;
            }

            list($label, $classification, $owner) = $definition;

            $assets[] = self::asset(
                'code_'.str_replace('/', '_', $path),
                $label,
                'code',
                strtolower($classification),
                $path,
                $owner,
                'Source code shipped with this deployment. Presence only — the register does not walk it.'
            );
        }

        return $assets;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contractAssets(): array
    {
        $assets = array();

        foreach (self::CONTRACTS as $path => $definition) {
            if (! is_file(base_path($path))) {
                continue;
            }

            list($label, $classification) = $definition;

            $assets[] = self::asset(
                'doc_'.str_replace(array('/', '.'), '_', $path),
                $label,
                'documentation',
                $classification,
                $path,
                'Architecture Team',
                'A machine-read contract: it defines the boundary it documents.'
            );
        }

        return $assets;
    }

    /**
     * @return array{id: string, name: string, type: string, classification: string, location: string, owner: string, description: string}
     */
    private static function asset(
        string $id,
        string $name,
        string $type,
        string $classification,
        string $location,
        string $owner,
        string $description
    ): array {
        return array(
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'classification' => $classification,
            'location' => $location,
            'owner' => $owner,
            'description' => $description,
        );
    }
}
