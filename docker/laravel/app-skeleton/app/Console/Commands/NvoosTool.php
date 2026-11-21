<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;

class NvoosTool extends Command
{
    protected $signature = 'nvoos:tool {slug} {--json=} {--as= : Run as this user id}';

    protected $description = 'Execute an NV oOS tool through the framework-agnostic registry.';

    public function handle(): int
    {
        $arguments = json_decode((string) $this->option('json'), true);

        if (! is_array($arguments)) {
            $arguments = [];
        }

        // Commands run unauthenticated unless told otherwise, which is the
        // WP-CLI convention: `wp` has no user by default and `--user` opts in.
        // The id is validated here so a typo fails as a usage error rather than
        // as a permission error from whichever tool was called.
        $as = $this->option('as');

        if (null !== $as && '' !== $as && (int) $as <= 0) {
            $this->error('--as must be a positive user id.');

            return self::FAILURE;
        }

        $userId = (int) ($as ?? 0);

        $result = app(ToolRegistry::class)->execute(
            (string) $this->argument('slug'),
            $arguments,
            [
                'user_id' => $userId,
                // The registry only enforces a tool's required capability when
                // the caller supplies a checker, and checks it as *this* user.
                // Without it, `--as` would pass the store's gate and still be
                // refused by the registry.
                'auth_provider' => app(AuthProviderInterface::class),
            ],
        );

        // A tool may return an error envelope or an error object; both are
        // failures, and printing `{}` for the second told an operator nothing.
        $failed = $result instanceof \Throwable
            || (is_array($result) && false === ($result['success'] ?? true));

        if ($result instanceof \Throwable) {
            $this->error($result->getMessage());
        } else {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ($failed && 0 === $userId) {
            $this->getOutput()->writeln(
                '<comment>hint: this command runs unauthenticated; pass --as=USER_ID to act as a user.</comment>'
            );
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
