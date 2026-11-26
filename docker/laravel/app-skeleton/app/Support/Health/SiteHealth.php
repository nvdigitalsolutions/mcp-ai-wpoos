<?php

namespace App\Support\Health;

use App\Models\FailedJob;
use App\Support\ToolCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * Runtime health probes for `/ops/site-health` — Proposal 149 §31.
 *
 * The WordPress plugin registers eleven `site_status_tests` probes with core
 * Site Health. This runtime has no Site Health to hook into, so the same
 * questions are asked here and rendered on an operator page instead.
 *
 * ## What is ported, and what is not
 *
 * | WordPress probe | Here | Why |
 * |---|---|---|
 * | API connectivity | `providers` | registered provider slugs, from the router |
 * | Model availability | — | model choice is per-assistant data, not a probe |
 * | Credentials validation | — | presence only, never a value; see `providers` |
 * | Tool functionality | `tools` | registry size + servable count |
 * | MCP schema compliance | — | needs the WordPress auditor; no equivalent |
 * | Database schema | `database` | connection + the runtime's own tables |
 * | Cache backend | `cache` | a real round-trip |
 * | Queue storage | `queue` | failed-job depth, the observable that matters |
 * | Cron configuration | `scheduler` | overdue schedules — the observable that matters |
 * | Security posture | `security` | debug mode, app key, secure cookie |
 * | File integrity | — | hashes the WordPress plugin tree; not applicable |
 *
 * The vocabulary is WordPress's on purpose: `good`, `recommended`, `critical`.
 * An operator who has seen Site Health should not have to learn a second one.
 *
 * ## Rules this class follows
 *
 * - **No secrets.** Probes read configuration *flags* (is debug on, is a key
 *   set) and never a credential value.
 * - **A probe never throws.** Health checks that crash the page are worse than
 *   the problem they report, so every probe catches and downgrades.
 * - **An absent table is not a failure.** The runtime survives a missing
 *   optional table (the store guards do), so probes report what is missing
 *   rather than treating it as critical.
 */
final class SiteHealth
{
    public const GOOD = 'good';

    public const RECOMMENDED = 'recommended';

    public const CRITICAL = 'critical';

    /** Runtime tables whose absence would mean the install never migrated. */
    private const RUNTIME_TABLES = array(
        'users',
        'assistants',
        'tenants',
        'tenant_user',
        'teams',
        'nvoos_settings',
        'nvoos_tenant_settings',
        'nvoos_admin_audit',
        'nvoos_workflow_runs',
        'nvoos_triggers',
        'nvoos_schedules',
        'personal_access_tokens',
    );

    /** Failed jobs at or above this count are critical rather than a warning. */
    private const FAILED_JOB_CEILING = 10;

    /**
     * Run every probe.
     *
     * Each entry: `id`, `label`, `status`, `summary`, `detail`.
     *
     * @return list<array{id: string, label: string, status: string, summary: string, detail: string}>
     */
    public static function probes(): array
    {
        return array(
            self::runtime(),
            self::database(),
            self::cache(),
            self::queue(),
            self::scheduler(),
            self::providers(),
            self::tools(),
            self::security(),
        );
    }

    /**
     * @param list<array<string, mixed>> $probes
     * @return array{total: int, good: int, recommended: int, critical: int}
     */
    public static function totals(array $probes): array
    {
        $counts = array(
            'total' => count($probes),
            'good' => 0,
            'recommended' => 0,
            'critical' => 0,
        );

        foreach ($probes as $probe) {
            $status = (string) $probe['status'];

            if (isset($counts[$status])) {
                ++$counts[$status];
            }
        }

        return $counts;
    }

    /**
     * The worst status across all probes — what the header should show.
     *
     * @param list<array<string, mixed>> $probes
     */
    public static function overall(array $probes): string
    {
        $overall = self::GOOD;

        foreach ($probes as $probe) {
            if (self::CRITICAL === $probe['status']) {
                return self::CRITICAL;
            }

            if (self::RECOMMENDED === $probe['status']) {
                $overall = self::RECOMMENDED;
            }
        }

        return $overall;
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function runtime(): array
    {
        $runtime = function_exists('frankenphp_handle_request') ? 'frankenphp-worker' : 'php-cli';

        // `ABSPATH` defined inside the Laravel runtime means a WordPress class
        // body was loaded — the one condition that makes several adapter
        // guards behave as though it were WordPress.
        $contaminated = defined('ABSPATH');

        return self::probe(
            'runtime',
            'Runtime',
            $contaminated ? self::CRITICAL : self::GOOD,
            sprintf('PHP %s · Laravel %s · %s', PHP_VERSION, app()->version(), $runtime),
            $contaminated
                ? 'ABSPATH is defined. A WordPress class body has been loaded into this runtime, which makes the adapter guards behave as if this were WordPress.'
                : 'No WordPress symbols are loaded.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function database(): array
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $exception) {
            return self::probe(
                'database',
                'Database',
                self::CRITICAL,
                'Cannot connect',
                $exception->getMessage()
            );
        }

        $missing = array();

        foreach (self::RUNTIME_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        $detail = array() === $missing
            ? 'Every runtime table is present.'
            : 'Missing: '.implode(', ', $missing).'. Run `php artisan migrate`.';

        return self::probe(
            'database',
            'Database',
            array() === $missing ? self::GOOD : self::RECOMMENDED,
            (string) config('database.default').' · '.count(self::RUNTIME_TABLES).' runtime table(s) checked',
            $detail
        );
    }

    /**
     * A real round-trip, because "the cache is configured" is not the question.
     *
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function cache(): array
    {
        $key = 'nvoos-health-'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(8));

        try {
            Cache::put($key, $value, 60);
            $read = Cache::get($key);
            Cache::forget($key);
        } catch (\Throwable $exception) {
            return self::probe(
                'cache',
                'Cache',
                self::CRITICAL,
                'Store unreachable',
                $exception->getMessage()
            );
        }

        $ok = $read === $value;

        return self::probe(
            'cache',
            'Cache',
            $ok ? self::GOOD : self::CRITICAL,
            (string) config('cache.default').($ok ? ' · round-trip ok' : ' · round-trip MISMATCH'),
            $ok
                ? 'A value written to the store read back unchanged.'
                : 'A value written to the store did not read back. A cache that silently drops writes is worse than none.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function queue(): array
    {
        try {
            $failed = Schema::hasTable('failed_jobs') ? FailedJob::query()->count() : 0;
            $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        } catch (\Throwable $exception) {
            return self::probe(
                'queue',
                'Queue',
                self::CRITICAL,
                'Unreadable',
                $exception->getMessage()
            );
        }

        $status = self::GOOD;

        if ($failed >= self::FAILED_JOB_CEILING) {
            $status = self::CRITICAL;
        } elseif ($failed > 0) {
            $status = self::RECOMMENDED;
        }

        return self::probe(
            'queue',
            'Queue',
            $status,
            sprintf('%s · %d pending · %d failed', (string) config('queue.default'), $pending, $failed),
            $failed > 0
                ? 'Failed jobs are visible on /ops/queue. A growing count usually means a job is retrying a permanent error.'
                : 'No failed jobs.'
        );
    }

    /**
     * Overdue schedules are the observable that the runner is alive — the
     * configuration itself proves nothing.
     *
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function scheduler(): array
    {
        if (! Schema::hasTable('nvoos_schedules')) {
            return self::probe(
                'scheduler',
                'Scheduler',
                self::RECOMMENDED,
                'No schedule table',
                'The runtime has no managed schedules. `nvoos:fire-triggers` still needs a cron or `schedule:work` runner.'
            );
        }

        try {
            $enabled = DB::table('nvoos_schedules')->where('enabled', true);
            $count = (clone $enabled)->count();
            $overdue = (clone $enabled)->whereNotNull('next_run_at')->where('next_run_at', '<', now())->count();
            $lastRun = (clone $enabled)->max('last_run_at');
        } catch (\Throwable $exception) {
            return self::probe(
                'scheduler',
                'Scheduler',
                self::CRITICAL,
                'Unreadable',
                $exception->getMessage()
            );
        }

        $status = self::GOOD;

        if ($overdue > 0) {
            $status = self::CRITICAL;
        } elseif (0 === $count) {
            $status = self::RECOMMENDED;
        }

        return self::probe(
            'scheduler',
            'Scheduler',
            $status,
            sprintf('%d enabled · %d overdue · last run %s', $count, $overdue, '' === (string) $lastRun ? 'never' : (string) $lastRun),
            $overdue > 0
                ? 'Enabled schedules are past their next run. The cron or `schedule:work` runner is probably not running.'
                : 'Schedules are configured. This probe cannot see a runner that has never started, only one that stopped.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function providers(): array
    {
        try {
            $slugs = array_values(array_map('strval', app(ProviderRouter::class)->getRegisteredSlugs()));
        } catch (\Throwable $exception) {
            return self::probe(
                'providers',
                'AI providers',
                self::CRITICAL,
                'Router unresolvable',
                $exception->getMessage()
            );
        }

        return self::probe(
            'providers',
            'AI providers',
            array() === $slugs ? self::CRITICAL : self::GOOD,
            sprintf('%d provider(s) registered', count($slugs)),
            array() === $slugs
                ? 'No provider client registered, so no chat request can be served.'
                : 'Registered: '.implode(', ', $slugs).'. Presence of a client is not proof of a valid key — chat returns the provider’s own error.'
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function tools(): array
    {
        try {
            $rows = ToolCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class));
            $summary = ToolCatalogue::summary($rows);
        } catch (\Throwable $exception) {
            return self::probe(
                'tools',
                'Tool registry',
                self::CRITICAL,
                'Unreadable',
                $exception->getMessage()
            );
        }

        return self::probe(
            'tools',
            'Tool registry',
            $summary['servable'] > 0 ? self::GOOD : self::RECOMMENDED,
            sprintf('%d registered · %d servable', $summary['total'], $summary['servable']),
            $summary['servable'] > 0
                ? 'Servable tools are those this runtime can actually call. The full catalogue is on /admin/tools.'
                : 'No tool is servable, so every assistant would answer without tools.'
        );
    }

    /**
     * Configuration flags only — never a credential value.
     *
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function security(): array
    {
        $debug = (bool) config('app.debug');
        $key = (string) config('app.key');
        $secureCookie = (bool) config('session.secure');
        $production = 'production' === (string) config('app.env');

        $problems = array();

        if ($debug) {
            $problems[] = 'APP_DEBUG is on: stack traces and environment values reach clients.';
        }

        if ('' === $key) {
            $problems[] = 'APP_KEY is empty: encrypted columns cannot be opened.';
        }

        if ($production && ! $secureCookie) {
            $problems[] = 'SESSION_SECURE_COOKIE is off in production.';
        }

        // Worst-wins, not last-wins: a loop that reassigns $status would let a
        // later warning downgrade an earlier critical.
        $status = self::GOOD;

        if (array() !== $problems) {
            $status = self::RECOMMENDED;
        }

        if ('' === $key) {
            $status = self::CRITICAL;
        }

        return self::probe(
            'security',
            'Security posture',
            $status,
            sprintf('debug %s · key %s · secure cookie %s', $debug ? 'on' : 'off', '' === $key ? 'missing' : 'set', $secureCookie ? 'on' : 'off'),
            array() === $problems ? 'No configuration problem found.' : implode(' ', $problems)
        );
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private static function probe(string $id, string $label, string $status, string $summary, string $detail): array
    {
        return array(
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'summary' => $summary,
            'detail' => $detail,
        );
    }
}
