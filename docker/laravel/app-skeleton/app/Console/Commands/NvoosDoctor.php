<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;

class NvoosDoctor extends Command
{
    protected $signature = 'nvoos:doctor';

    protected $description = 'Print the NV oOS adapter bindings, tools, providers, and runtime state.';

    public function handle(): int
    {
        $contracts = [
            ErrorFactoryInterface::class,
            CacheStoreInterface::class,
            SettingsStoreInterface::class,
            EventDispatcherInterface::class,
            FileStoreInterface::class,
            QueueClientInterface::class,
            AuthProviderInterface::class,
            ContentStoreInterface::class,
            HttpClientInterface::class,
            SseHandler::class,
        ];

        $rows = [];
        foreach ($contracts as $contract) {
            try {
                app($contract);
                $rows[] = [class_basename($contract), 'OK'];
            } catch (\Throwable $e) {
                $rows[] = [class_basename($contract), 'FAIL: '.$e->getMessage()];
            }
        }

        $this->table(['Binding', 'Status'], $rows);
        $this->line('Tools: '.implode(', ', app(ToolRegistry::class)->getSlugs()));
        $this->line('Providers: '.implode(', ', app(ProviderRouter::class)->getRegisteredSlugs()));
        $this->line('Queue connection: '.(string) config('oos.queue_connection'));
        $this->line('Runtime: '.(function_exists('frankenphp_handle_request') ? 'frankenphp-worker' : 'php-cli'));
        $this->line('ABSPATH defined: '.(defined('ABSPATH') ? 'yes' : 'no'));

        $healthy = $this->reportVersionState();

        return $healthy && ! $this->hasFailures($rows) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Report contract and schema versions plus any pending migrations.
     *
     * An upgrade is only complete when all three agree, so the doctor exits
     * non-zero when they do not — that makes it usable as a post-deploy gate
     * rather than something an operator has to read carefully.
     */
    private function reportVersionState(): bool
    {
        $healthy = true;

        $contract = (int) config('oos.contract_version', 1);
        $expected = (int) config('oos.schema_version', 1);

        $this->line('Contract version: '.$contract.' (console expects 1)');

        if (1 !== $contract) {
            $healthy = false;
            $this->error('  FAIL: the console only speaks contract v1.');
        }

        $pending = $this->pendingMigrations();

        if ([] === $pending) {
            $this->line('Pending migrations: none');
        } else {
            $healthy = false;
            $this->error('  FAIL: '.count($pending).' pending migration(s): '.implode(', ', $pending));
            $this->line('  Run: php artisan migrate');
        }

        if (! Schema::hasTable('nvoos_meta')) {
            $healthy = false;
            $this->error('  FAIL: nvoos_meta is missing — run: php artisan migrate');

            return false;
        }

        $actual = (string) DB::table('nvoos_meta')->where('key', 'schema_version')->value('value');
        $this->line('Schema version: '.($actual !== '' ? $actual : 'unset').' (expected '.$expected.')');

        if ((string) $expected !== $actual) {
            $healthy = false;
            $this->error('  FAIL: schema version mismatch — run: php artisan migrate');
        }

        return $healthy;
    }

    /**
     * Migration names that exist on disk but have not run.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return array_keys($migrator->getMigrationFiles(database_path('migrations')));
        }

        $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));

        return array_values(array_diff($files, $migrator->getRepository()->getRan()));
    }

    /**
     * Whether any binding row failed.
     *
     * @param list<array{0:string,1:string}> $rows Binding rows.
     */
    private function hasFailures(array $rows): bool
    {
        foreach ($rows as $row) {
            if (str_starts_with($row[1], 'FAIL')) {
                return true;
            }
        }

        return false;
    }
}
