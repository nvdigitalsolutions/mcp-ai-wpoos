<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
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

class HealthController
{
    public function __invoke(): JsonResponse
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
        ];

        $resolved = [];
        $failures = [];

        foreach ($contracts as $contract) {
            try {
                app($contract);
                $resolved[] = class_basename($contract);
            } catch (\Throwable $e) {
                $failures[$contract] = $e->getMessage();
            }
        }

        try {
            app(SseHandler::class);
            $resolved[] = 'SseHandler';
        } catch (\Throwable $e) {
            $failures[SseHandler::class] = $e->getMessage();
        }

        $ok = [] === $failures && ! defined('ABSPATH');

        return response()->json(
            [
                'ok' => $ok,
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'runtime' => function_exists('frankenphp_handle_request') ? 'frankenphp-worker' : 'php-cli',
                'abspath' => defined('ABSPATH'),
                'queue' => config('oos.queue_connection'),
                'bindings' => $resolved,
                'tools' => app(ToolRegistry::class)->getSlugs(),
                'providers' => app(ProviderRouter::class)->getRegisteredSlugs(),
                'failures' => $failures,
            ],
            $ok ? 200 : 500,
        );
    }
}
