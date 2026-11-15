<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Capture the Pro toolkit contract fixtures (090-b Phase 4.7).
 *
 * Each fixture is `{ request, status, headers, body }` for one read-only route,
 * written as pretty JSON so a contract change shows up as a readable diff. The
 * replay test compares the live response's **shape** (keys and types), not its
 * values, so captured rows and totals do not need to match the CI database.
 *
 * Runs in-process through the HTTP kernel with a short-lived Sanctum token —
 * the same bearer path an operator token uses, and no running web server.
 */
class CaptureFixtures extends Command
{
    protected $signature = 'fixtures:capture
        {--output= : Directory to write into (defaults to base_path/fixtures)}
        {--user= : Email of the admin to act as (defaults to the first administrator)}';

    protected $description = 'Capture the Pro toolkit contract fixtures.';

    /**
     * Read-only routes, keyed by fixture path (without .json).
     *
     * @return array<string, string>
     */
    private function routes(): array
    {
        return [
            'mcp-ai-pro-v1/toolkits/index' => '/api/v1/pro/toolkits',
            'mcp-ai-pro-v1/toolkits/commerce' => '/api/v1/pro/toolkits/commerce',
            'mcp-ai-pro-v1/toolkits/commerce.settings' => '/api/v1/pro/toolkits/commerce/settings',
            'mcp-ai-pro-v1/toolkits/commerce.dashboard' => '/api/v1/pro/toolkits/commerce/dashboard',
            'mcp-ai-pro-v1/toolkits/commerce.entities.product' => '/api/v1/pro/toolkits/commerce/entities/product',
            'mcp-ai-pro-v1/toolkits/documents' => '/api/v1/pro/toolkits/documents',
        ];
    }

    public function handle(): int
    {
        $output = (string) ($this->option('output') ?: base_path('fixtures'));

        if (! is_dir($output) && ! mkdir($output, 0755, true) && ! is_dir($output)) {
            $this->error('Cannot create '.$output);

            return self::FAILURE;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $createdToken = $user->createToken('fixtures');
        $temporaryUser = $user->wasRecentlyCreated ? $user : null;
        $kernel = $this->laravel->make(Kernel::class);

        try {
            foreach ($this->routes() as $name => $uri) {
                $request = Request::create($uri, 'GET', [], [], [], [
                    'HTTP_ACCEPT' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.$createdToken->plainTextToken,
                ]);

                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);

                $body = json_decode((string) $response->getContent(), true);

                $fixture = [
                    'request' => ['method' => 'GET', 'uri' => $uri],
                    'status' => $response->getStatusCode(),
                    'headers' => ['content-type' => $response->headers->get('content-type')],
                    'body' => $body,
                ];

                $file = $output.'/'.$name.'.json';
                $directory = dirname($file);

                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                file_put_contents(
                    $file,
                    json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
                );

                $this->line(sprintf('%d  %s', $response->getStatusCode(), $name));
            }
        } finally {
            $createdToken->accessToken->delete();

            if ($temporaryUser !== null) {
                $temporaryUser->delete();
            }
        }

        return self::SUCCESS;
    }

    /**
     * The operator to act as. A missing administrator gets a temporary one so
     * the command works on a fresh database; it is deleted afterwards.
     */
    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        if (is_string($email) && $email !== '') {
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error('No user with email '.$email.'.');

                return null;
            }

            return $user;
        }

        $admin = User::whereIn('role', ['administrator', 'admin'])->orderBy('id')->first();

        return $admin ?? User::factory()->admin()->create(['email' => 'fixtures@nvoos.test']);
    }
}
