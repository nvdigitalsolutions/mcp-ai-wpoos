<?php

namespace App\Services;

use App\Models\Assistant;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * What a turn against one assistant would send — Proposal 149 §48.
 *
 * Extracted from `AssistantTestController`, which owned it inline, because two
 * callers now need it:
 *
 *  - `POST /api/v1/assistants/{assistant}/test` returns the plan before a turn
 *    and again beside the reply;
 *  - the `/ops/diagnostics` probe renders it as the "Test Assistant" surface.
 *
 * The plan is free: it resolves the provider, reads the assistant record and
 * compares the assigned tools against the registry. It never calls a provider,
 * so the probe can run on page load without spending tokens.
 *
 * The plan is deliberately descriptive rather than prescriptive — it reports
 * `warnings` as codes (`no_provider`, `tool_not_registered:foo`) instead of
 * prose, because the console and the panel word them differently and the codes
 * are what tests assert.
 */
final class AssistantTestPlan
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Assistant $assistant, ?AbstractProviderClient $client): array
    {
        $config = $assistant->toRecord();

        // What a turn would send. `resolveForChat()` falls back to the site
        // default provider, so the resolved slug is reported separately from
        // the assistant's own setting rather than conflated with it.
        $configuredProvider = (string) ($config['provider'] ?? '');
        $assigned = array_values(array_filter(
            (array) ($config['tools'] ?? []),
            static fn ($slug): bool => is_string($slug) && '' !== $slug,
        ));
        $registered = array_values(array_filter($assigned, fn (string $slug): bool => $this->tools->has($slug)));
        $missing = array_values(array_diff($assigned, $registered));
        $prompt = (string) ($config['system_prompt'] ?? '');

        $warnings = [];
        if ('' === $configuredProvider) {
            $warnings[] = 'no_provider';
        }
        if (null === $client) {
            $warnings[] = 'provider_unavailable';
        }
        if ('' === (string) ($config['model'] ?? '')) {
            $warnings[] = 'no_model';
        }
        if ('' === trim($prompt)) {
            $warnings[] = 'no_system_prompt';
        }
        if ([] === $assigned) {
            $warnings[] = 'no_tools';
        }
        foreach ($missing as $slug) {
            $warnings[] = 'tool_not_registered:' . $slug;
        }

        return [
            'assistant' => [
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'status' => (string) $assistant->status,
            ],
            'provider' => [
                'configured' => $configuredProvider,
                'resolved' => $client?->getProviderSlug() ?? '',
            ],
            'model' => (string) ($config['model'] ?? ''),
            'system_prompt' => [
                'present' => '' !== trim($prompt),
                'characters' => strlen($prompt),
            ],
            'tools' => [
                'assigned' => $assigned,
                'registered' => $registered,
                'missing' => $missing,
            ],
            'warnings' => $warnings,
            'dry_run' => true,
            'reply' => null,
            'tool_calls' => [],
            'iterations' => 0,
            'latency_ms' => null,
            'cost' => null,
        ];
    }

    /**
     * Normalise the two request shapes into one conversation.
     *
     * `messages` wins when both are supplied — it is the explicit form — and a
     * bare `message` becomes a single user turn.
     *
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    public function messages(array $input): array
    {
        if (! empty($input['messages'])) {
            return array_values($input['messages']);
        }

        $message = trim((string) ($input['message'] ?? ''));

        return '' === $message ? [] : [['role' => 'user', 'content' => $message]];
    }
}
