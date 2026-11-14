<?php

namespace App\Support\SlashCommands;

use App\Models\Assistant;
use App\Models\User;
use App\Support\HealthSampler;
use App\Support\TenantContext;
use App\Support\WorkflowRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\TokenUsage;

/**
 * Runs the native slash-command subset (090-i).
 *
 * Each handler formats plain text, which is what the console appends to the
 * chat transcript (`ChatPage.normalizeSlashResult` accepts a string as-is). A
 * command therefore succeeds by returning text and fails by throwing
 * {@see SlashCommandFailed}; there is no third state.
 *
 * Numbers are read at call time, never cached: a `/status` answered from a
 * stale snapshot is worse than a slow one.
 */
final class SlashCommandExecutor
{
    /** Upper bound on tool rows; the catalogue has ~100 entries. */
    private const TOOL_LIMIT = 25;

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ContentStoreInterface $content,
        private readonly TokenUsage $usage,
        private readonly JobStatusStore $jobs,
        private readonly SettingsStoreInterface $settings,
        private readonly HealthSampler $health,
    ) {
    }

    /**
     * Execute a parsed command.
     *
     * @param array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string|null} $parsed
     *
     * @throws SlashCommandFailed
     */
    public function run(array $parsed, ?User $user): string
    {
        $definition = SlashCommandCatalogue::find($parsed['command']);

        if (null === $definition) {
            throw new SlashCommandFailed(
                'command_not_found',
                sprintf('Command "/%s" not found. Use /help to see available commands.', $parsed['command']),
            );
        }

        if (! $this->allows($user, $definition['capability'])) {
            throw new SlashCommandFailed(
                'insufficient_capability',
                sprintf('You do not have permission to run /%s (requires the %s capability).', $definition['name'], $definition['capability']),
            );
        }

        return match ($definition['name']) {
            'help' => $this->help($parsed, $user),
            'status' => $this->status(),
            'model' => $this->model(),
            'jobs' => $this->jobs(),
            'tools' => $this->tools($parsed, $user),
            'cost' => $this->cost($parsed),
            'workflow' => $this->workflow($parsed),
        };
    }

    /**
     * The commands this account may run, keyed by canonical name.
     *
     * @return array<string, array{name: string, description: string, usage: string, parameters: list<string>, category: string, capability: string}>
     */
    public function available(?User $user): array
    {
        $available = [];

        foreach (SlashCommandCatalogue::all() as $name => $definition) {
            if (! $this->allows($user, $definition['capability'])) {
                continue;
            }

            $available[$name] = ['name' => $name] + $definition;
        }

        return $available;
    }

    /**
     * @param array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string|null} $parsed
     */
    private function help(array $parsed, ?User $user): string
    {
        $available = $this->available($user);
        $wanted = $parsed['args'][0] ?? '';

        if ('' !== $wanted) {
            $command = SlashCommandCatalogue::find($wanted);

            if (null === $command || ! isset($available[$command['name']])) {
                throw new SlashCommandFailed(
                    'command_not_found',
                    sprintf('Command "/%s" not found. Use /help to see available commands.', $wanted),
                );
            }

            return $this->describe($command);
        }

        if ([] === $available) {
            return 'No slash commands are available to this account.';
        }

        $lines = [sprintf('Slash commands (%d):', count($available))];

        foreach ($this->grouped($available) as $category => $commands) {
            $lines[] = '';
            $lines[] = $category;

            foreach ($commands as $command) {
                $lines[] = sprintf('  %-24s %s', $command['usage'], $command['description']);
            }
        }

        $lines[] = '';
        $lines[] = "Use /help <command> for one command's usage.";

        return implode("\n", $lines);
    }

    /**
     * @param array{name: string, description: string, usage: string, parameters: list<string>, category: string, capability: string} $command
     */
    private function describe(array $command): string
    {
        $lines = [
            sprintf('/%s — %s', $command['name'], $command['description']),
            sprintf('Usage: %s', $command['usage']),
            sprintf('Category: %s', $command['category']),
            sprintf('Requires: %s', $command['capability']),
        ];

        if ([] !== $command['parameters']) {
            $lines[] = 'Parameters: '.implode(', ', $command['parameters']);
        }

        return implode("\n", $lines);
    }

    /**
     * Group commands by category, categories and commands both alphabetical.
     *
     * @param array<string, array{name: string, description: string, usage: string, parameters: list<string>, category: string, capability: string}> $commands
     *
     * @return array<string, list<array{name: string, description: string, usage: string, parameters: list<string>, category: string, capability: string}>>
     */
    private function grouped(array $commands): array
    {
        $grouped = [];

        foreach ($commands as $command) {
            $grouped[$command['category']][] = $command;
        }

        ksort($grouped);

        return $grouped;
    }

    private function status(): string
    {
        $assistants = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->get(['id', 'status']);

        $online = 0;

        foreach ($assistants as $assistant) {
            if ('publish' === (string) $assistant->status) {
                ++$online;
            }
        }

        $uptime = $this->health->uptimePercent(24);

        return implode("\n", [
            'Backend: laravel',
            sprintf('Agents: %d (%d enabled)', $assistants->count(), $online),
            sprintf('Pending approvals: %d', $this->pendingApprovals()),
            sprintf('Queue depth: %d', $this->queueDepth()),
            sprintf('Uptime (24h): %s', null === $uptime ? 'unknown — no health samples yet' : $uptime.'%'),
            sprintf('Tokens today: %s', number_format($this->tokensToday())),
        ]);
    }

    private function model(): string
    {
        return implode("\n", [
            sprintf('Provider: %s', $this->settings->getDefaultProvider()),
            sprintf('Model: %s', $this->settings->getDefaultModel()),
        ]);
    }

    private function jobs(): string
    {
        if (! $this->jobs->isAvailable()) {
            return 'Job storage is not available on this runtime.';
        }

        $counts = $this->jobs->counts();

        // An absent status is a true zero, not "unknown": the store groups
        // real rows, so a missing key means no job ever held that status.
        foreach (['queued', 'running', 'completed', 'failed', 'cancelled'] as $status) {
            $counts[$status] ??= 0;
        }

        ksort($counts);
        $lines = ['Jobs by status:'];

        foreach ($counts as $status => $total) {
            $lines[] = sprintf('  %-10s %d', $status, $total);
        }

        $lines[] = sprintf('Queue depth: %d', $this->queueDepth());

        $failed = $this->jobs->rows(['status' => 'failed'], 5);
        $failures = array_filter(
            array_map(
                static function (object $row): string {
                    $error = trim((string) ($row->error ?? ''));
                    $handler = (string) ($row->handler ?? 'job');

                    return sprintf('  #%s %s%s', (string) ($row->id ?? '?'), $handler, '' === $error ? '' : ' — '.$error);
                },
                $failed,
            ),
        );

        if ([] !== $failures) {
            $lines[] = '';
            $lines[] = 'Recent failures:';
            $lines = array_merge($lines, array_values($failures));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string|null} $parsed
     */
    private function tools(array $parsed, ?User $user): string
    {
        $search = mb_strtolower(implode(' ', $parsed['args']));
        $visible = [];

        /** @var ToolInterface $tool */
        foreach ($this->tools->all() as $tool) {
            if (! $this->allows($user, (string) $tool->getRequiredCapability())) {
                continue;
            }

            $haystack = mb_strtolower($tool->getSlug().' '.$tool->getName().' '.$tool->getDescription());

            if ('' !== $search && ! str_contains($haystack, $search)) {
                continue;
            }

            $visible[] = $tool;
        }

        usort(
            $visible,
            static fn (ToolInterface $a, ToolInterface $b): int => strcmp($a->getSlug(), $b->getSlug()),
        );

        if ([] === $visible) {
            return '' === $search
                ? 'No tools are available to this account.'
                : sprintf('No tools match "%s".', $search);
        }

        $shown = array_slice($visible, 0, self::TOOL_LIMIT);
        $lines = [sprintf(
            'Tools (%d%s):',
            count($visible),
            count($visible) > self::TOOL_LIMIT ? sprintf(', showing %d', self::TOOL_LIMIT) : '',
        )];

        foreach ($shown as $tool) {
            $lines[] = sprintf('  %-34s %s', $tool->getSlug(), $tool->getName());
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string|null} $parsed
     */
    private function cost(array $parsed): string
    {
        $raw = $parsed['flags']['days'] ?? 30;
        $days = is_numeric($raw) ? (int) $raw : 30;
        $days = max(1, min(365, $days));

        $summary = $this->usage->summary($days);

        $lines = [
            sprintf('Cost summary (last %d days):', $days),
            sprintf('  Cost:   %s', number_format((float) ($summary['total_cost'] ?? 0), 2, '.', '')),
            sprintf('  Tokens: %s', number_format((int) ($summary['total_tokens'] ?? 0))),
        ];

        $providers = (array) ($summary['by_provider'] ?? []);
        arsort($providers);

        if ([] !== $providers) {
            $lines[] = '';
            $lines[] = 'By provider:';

            foreach (array_slice($providers, 0, 5, true) as $provider => $cost) {
                $lines[] = sprintf('  %-14s %s', (string) $provider, number_format((float) $cost, 2, '.', ''));
            }
        }

        $models = (array) ($summary['by_model'] ?? []);
        uasort(
            $models,
            static fn (array $a, array $b): int => ((float) ($b['total_cost'] ?? 0)) <=> ((float) ($a['total_cost'] ?? 0)),
        );

        if ([] !== $models) {
            $lines[] = '';
            $lines[] = 'By model:';

            foreach (array_slice($models, 0, 5, true) as $model => $row) {
                $lines[] = sprintf(
                    '  %-24s %s tokens',
                    (string) $model,
                    number_format((int) ($row['total_tokens'] ?? 0)),
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{command: string, raw_input: string, args: list<string>, flags: array<string, string|true>, raw_args: string, error: string|null} $parsed
     */
    private function workflow(array $parsed): string
    {
        $show = $parsed['flags']['show'] ?? null;

        if (true === $show) {
            throw new SlashCommandFailed('invalid_argument', 'Usage: /workflow [--list | --show=ID]');
        }

        if (is_string($show) && '' !== $show) {
            $item = WorkflowRecords::find($this->content, (int) $show);

            if (null === $item) {
                throw new SlashCommandFailed('not_found', sprintf('Workflow %s not found.', $show));
            }

            $summary = WorkflowRecords::summarise($item);
            $graph = WorkflowRecords::readGraph($item);

            return implode("\n", [
                sprintf('#%d %s', (int) $summary['id'], (string) $summary['title']),
                sprintf('Version: %s', (string) $summary['version']),
                sprintf('Nodes: %d', count((array) ($graph['nodes'] ?? []))),
                sprintf('Edges: %d', count((array) ($graph['edges'] ?? []))),
                sprintf('Modified: %s', (string) $summary['modified']),
            ]);
        }

        $collection = $this->content->query(new ContentQuery(
            types: [WorkflowRecords::TYPE],
            statuses: ['publish'],
            orderBy: 'modified',
            order: 'DESC',
            perPage: 100,
        ));

        if ([] === $collection->items) {
            return 'No workflows yet.';
        }

        $lines = [sprintf('Workflows (%d):', count($collection->items))];

        foreach ($collection->items as $item) {
            $summary = WorkflowRecords::summarise($item);
            $lines[] = sprintf('  #%d %s (%s)', (int) $summary['id'], (string) $summary['title'], (string) $summary['version']);
        }

        return implode("\n", $lines);
    }

    private function allows(?User $user, string $capability): bool
    {
        if (null === $user) {
            return false;
        }

        return '' === $capability || $user->can($capability);
    }

    private function pendingApprovals(): int
    {
        if (! Schema::hasTable('nvoos_approvals')) {
            return 0;
        }

        return (int) DB::table('nvoos_approvals')->where('status', 'pending')->count();
    }

    private function queueDepth(): int
    {
        return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
    }

    private function tokensToday(): int
    {
        if (! Schema::hasTable('nvoos_token_usage')) {
            return 0;
        }

        return (int) DB::table('nvoos_token_usage')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('total_tokens');
    }
}
