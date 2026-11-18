<?php

namespace App\Support\Diagnostics;

use App\Models\Assistant;
use App\Services\AssistantTestPlan;
use App\Support\TenantContext;
use App\Support\ToolCatalogue;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * The probe renderer — Proposal 149 §48 (contract row `platform-operations`).
 *
 * One class runs every probe and returns a {@see ProbeResult}: a status, one
 * actionable sentence and a short list of label/value rows. The page does not
 * know what a probe does, and a probe cannot return a raw payload — that is the
 * row's projection enforced by the return type rather than by review.
 *
 * WHAT IS A PROBE HERE
 *
 * "Send a request, show the envelope" (proposal §7.8). The WordPress originals
 * are four chat UIs plus three diagnostics pages; a chat UI is the console's
 * job, so the Laravel reduction is one request per surface, rendered as a
 * summary. Two of the four probes here are free (no provider call):
 *
 *  - `provider` — an authenticated `GET /models` through the provider client,
 *    which is the only check that proves a key without generating output;
 *  - `assistant` — the dry-run plan `POST /assistants/{id}/test` returns before
 *    a turn, shared through {@see AssistantTestPlan} so the two cannot drift;
 *  - `rest` — route facts, read from the router;
 *  - `mcp` — the MCP transport route and the tool registry it serves.
 *
 * NOT HERE: `test model`, `test profession` and `test team`. Those WordPress
 * pages render the chat UI with a selector; on this runtime they would be a
 * second chat surface whose only difference is which config it hydrates. They
 * stay recorded on the row as the remaining reduction, and the console owns
 * chat. Do not add them by copying a chat panel into this class.
 *
 * STATELESS. Nothing here writes: a probe re-runs on every invocation, so the
 * contract row declares `writer: none` and the page offers no save action.
 */
final class ProbeRunner
{
    /**
     * Probe key => label. The keys are the page's select values and the test
     * names; adding one without a branch here is a failing test, not a silent
     * no-op.
     */
    public const PROBES = [
        'provider' => 'Provider connectivity',
        'rest' => 'REST context',
        'mcp' => 'MCP server',
        'assistant' => 'Test assistant',
    ];

    public function __construct(
        private readonly ProviderRouter $providers,
        private readonly ToolRegistry $tools,
        private readonly ToolReadiness $readiness,
        private readonly Router $router,
        private readonly AssistantTestPlan $plans,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return self::PROBES;
    }

    /**
     * Registered provider slugs, as `slug => label`.
     *
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        $options = [];

        foreach ($this->providers->getRegisteredSlugs() as $slug) {
            $options[(string) $slug] = ucfirst(str_replace('_', ' ', (string) $slug));
        }

        return $options;
    }

    /**
     * Assistants in the current estate, as `id => title`.
     *
     * @return array<int, string>
     */
    public function assistantOptions(): array
    {
        return Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title')
            ->limit(200)
            ->pluck('title', 'id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
    }

    /**
     * Run one probe. Unknown keys are a programming error, not user input: the
     * page only submits values from {@see self::PROBES}.
     *
     * @param array<string, mixed> $input
     */
    public function run(string $probe, array $input = []): ProbeResult
    {
        $started = microtime(true);

        $result = match ($probe) {
            'provider' => $this->provider($input),
            'rest' => $this->rest(),
            'mcp' => $this->mcp(),
            'assistant' => $this->assistant($input),
            default => throw new \InvalidArgumentException('Unknown probe: '.$probe),
        };

        // Measured here rather than inside each branch, so the number always
        // means the same thing: how long the operator waited.
        return new ProbeResult(
            $result->probe,
            $result->label,
            $result->status,
            $result->summary,
            $result->rows,
            (int) round((microtime(true) - $started) * 1000),
        );
    }

    /**
     * Authenticated liveness through the provider client.
     *
     * @param array<string, mixed> $input
     */
    private function provider(array $input): ProbeResult
    {
        $slug = (string) ($input['provider'] ?? '');

        if ('' === $slug) {
            return $this->result('provider', ProbeResult::CRITICAL, 'Choose a provider to probe.', array());
        }

        $client = $this->providers->get($slug);

        if (null === $client) {
            return $this->result(
                'provider',
                ProbeResult::CRITICAL,
                sprintf('No client is registered for "%s".', $slug),
                array($this->row('Provider', $slug))
            );
        }

        $connectivity = $client->connectivity();

        if (! $connectivity->attempted) {
            return $this->result(
                'provider',
                ProbeResult::RECOMMENDED,
                $connectivity->detail,
                array(
                    $this->row('Provider', $slug),
                    $this->row('HTTP status', '—'),
                    $this->row('Models', '—'),
                )
            );
        }

        $status = $connectivity->ok ? ProbeResult::GOOD : ProbeResult::CRITICAL;
        $summary = $connectivity->ok
            ? sprintf('%s accepted the configured key.', $slug)
            : sprintf('%s did not accept the request.', $slug);

        return $this->result(
            'provider',
            $status,
            $summary,
            array(
                $this->row('Provider', $slug),
                $this->row('HTTP status', $connectivity->status === null ? 'no response' : (string) $connectivity->status),
                $this->row('Models', $connectivity->ok ? (string) $connectivity->modelCount : '—'),
                $this->row('Detail', $connectivity->detail),
            )
        );
    }

    /**
     * Route facts for the console contract.
     */
    private function rest(): ProbeResult
    {
        $routes = 0;
        $mcp = false;

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();

            if (str_starts_with($uri, 'api/v1')) {
                ++$routes;
            }

            if ('mcp' === $uri) {
                $mcp = true;
            }
        }

        return $this->result(
            'rest',
            $routes > 0 ? ProbeResult::GOOD : ProbeResult::CRITICAL,
            $routes > 0
                ? sprintf('%d routes are mounted under /api/v1.', $routes)
                : 'No route is mounted under /api/v1, so the console has nothing to call.',
            array(
                $this->row('Backend', 'laravel'),
                $this->row('Contract', 'mcp-ai/v1'),
                $this->row('API prefix', '/api/v1'),
                $this->row('Routes', (string) $routes),
                $this->row('MCP route', $mcp ? 'mounted' : 'absent'),
            )
        );
    }

    /**
     * The MCP transport route and the catalogue it serves.
     */
    private function mcp(): ProbeResult
    {
        $post = null;

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            if ('mcp' === $route->uri() && in_array('POST', $route->methods(), true)) {
                $post = $route;
                break;
            }
        }

        $rows = ToolCatalogue::rows($this->tools, $this->readiness);
        $summary = ToolCatalogue::summary($rows);

        if (null === $post) {
            return $this->result(
                'mcp',
                ProbeResult::CRITICAL,
                'No POST /mcp route is mounted, so no MCP client can reach this runtime.',
                array(
                    $this->row('Endpoint', 'absent'),
                    $this->row('Tools registered', (string) $summary['total']),
                )
            );
        }

        $middleware = $post->gatherMiddleware();

        // gatherMiddleware() returns the route's declarations as written —
        // `auth:sanctum`, not the resolved class name. Matching only the FQCN
        // (or only the alias) reports a false "missing", which is a critical
        // result on a healthy runtime. Both forms are accepted here because
        // `routes/ai.php` and a future refactor may spell it either way.
        $authenticated = $this->carries($middleware, array(
            'auth:sanctum',
            'Illuminate\\Auth\\Middleware\\Authenticate:sanctum',
        ));

        $throttled = $this->carries($middleware, array(
            'throttle:mcp',
            'Illuminate\\Routing\\Middleware\\ThrottleRequests:mcp',
        ));

        $status = ProbeResult::GOOD;

        if (! $authenticated || $summary['total'] === 0) {
            $status = ProbeResult::CRITICAL;
        } elseif ($summary['servable'] === 0) {
            $status = ProbeResult::RECOMMENDED;
        }

        return $this->result(
            'mcp',
            $status,
            sprintf('%d tool(s) registered · %d servable.', $summary['total'], $summary['servable']),
            array(
                $this->row('Endpoint', 'POST /mcp'),
                $this->row('Server', 'App\\Mcp\\Servers\\NvoosServer'),
                $this->row('Authentication', $authenticated ? 'sanctum' : 'missing'),
                $this->row('Rate limit', $throttled ? 'throttle:mcp' : 'missing'),
                $this->row('Tools', sprintf('%d registered · %d servable', $summary['total'], $summary['servable'])),
            )
        );
    }

    /**
     * The free half of the Test Assistant page: what a turn would send.
     *
     * @param array<string, mixed> $input
     */
    private function assistant(array $input): ProbeResult
    {
        $id = (int) ($input['assistant'] ?? 0);

        if ($id <= 0) {
            return $this->result('assistant', ProbeResult::CRITICAL, 'Choose an assistant to inspect.', array());
        }

        // Explicit estate scope: the probe runs on `/ops`, which has no tenant
        // middleware, so a bare find() would read across estates (§42's rule).
        $assistant = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->whereKey($id)
            ->first();

        if (null === $assistant) {
            return $this->result(
                'assistant',
                ProbeResult::CRITICAL,
                'No assistant with that id exists in this estate.',
                array($this->row('Assistant', '#'.$id))
            );
        }

        $client = $this->providers->resolveForChat(array(), $assistant->toRecord());
        $plan = $this->plans->build($assistant, $client);
        $warnings = (array) ($plan['warnings'] ?? []);

        if (in_array('provider_unavailable', $warnings, true)) {
            $status = ProbeResult::CRITICAL;
        } elseif ([] !== $warnings) {
            $status = ProbeResult::RECOMMENDED;
        } else {
            $status = ProbeResult::GOOD;
        }

        $tools = (array) ($plan['tools'] ?? []);

        return $this->result(
            'assistant',
            $status,
            [] === $warnings
                ? 'A turn would resolve a provider, a model and every assigned tool.'
                : 'A turn would run, but the plan reports warnings.',
            array(
                $this->row('Assistant', sprintf('#%d %s', $plan['assistant']['id'], $plan['assistant']['title'])),
                $this->row('Provider', $this->providerLabel((array) ($plan['provider'] ?? array()))),
                $this->row('Model', '' === (string) $plan['model'] ? '—' : (string) $plan['model']),
                $this->row('System prompt', $plan['system_prompt']['present'] ? $plan['system_prompt']['characters'].' characters' : 'absent'),
                $this->row(
                    'Tools',
                    sprintf('%d registered of %d assigned', count((array) ($tools['registered'] ?? array())), count((array) ($tools['assigned'] ?? array())))
                        .([] === (array) ($tools['missing'] ?? array()) ? '' : ' · missing: '.implode(', ', (array) $tools['missing']))
                ),
                $this->row('Warnings', [] === $warnings ? 'none' : implode(', ', $warnings)),
            )
        );
    }

    /**
     * Whether any of the given middleware declarations is present.
     *
     * @param array<int, string> $middleware
     * @param array<int, string> $candidates
     */
    private function carries(array $middleware, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $middleware, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function providerLabel(array $provider): string
    {
        $configured = (string) ($provider['configured'] ?? '');
        $resolved = (string) ($provider['resolved'] ?? '');

        if ('' === $resolved) {
            return '' === $configured ? 'none' : $configured.' (unresolved)';
        }

        return $configured === $resolved
            ? $resolved
            : sprintf('%s (configured) → %s (resolved)', '' === $configured ? '—' : $configured, $resolved);
    }

    /**
     * @param array<int, array{label: string, value: string}> $rows
     */
    private function result(string $probe, string $status, string $summary, array $rows): ProbeResult
    {
        return new ProbeResult($probe, self::PROBES[$probe], $status, $summary, $rows, 0);
    }

    /**
     * @return array{label: string, value: string}
     */
    private function row(string $label, string $value): array
    {
        return array('label' => $label, 'value' => $value);
    }
}
