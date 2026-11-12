<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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

        return self::SUCCESS;
    }
}
