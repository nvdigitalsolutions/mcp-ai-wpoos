<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nvoos\Core\Application\Tool\ToolRegistry;

class NvoosTool extends Command
{
    protected $signature = 'nvoos:tool {slug} {--json=}';

    protected $description = 'Execute an NV oOS tool through the framework-agnostic registry.';

    public function handle(): int
    {
        $arguments = json_decode((string) $this->option('json'), true);

        if (! is_array($arguments)) {
            $arguments = [];
        }

        $result = app(ToolRegistry::class)->execute(
            (string) $this->argument('slug'),
            $arguments,
            ['user_id' => 0],
        );

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
