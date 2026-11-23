<?php

/**
 * Tool: get_system_logs — what happened, from the stores the host keeps.
 *
 * WordPress had this tool for a decade of `debug.log` and activity options; the
 * Laravel runtime had an audit page and a trace page and no way for an agent to
 * read either. An agent debugging "why did that call fail" could not see the two
 * records that answer it.
 *
 * ## What changed from the WordPress tool, and why
 *
 * The WordPress tool's arguments describe a filesystem: `include_debug_log`,
 * `debug_log_bytes`, `plugin_log_directories`, `plugin_log_depth`. This runtime
 * does not scan directories for `.log` files — pasting a log tail into a model's
 * context leaks absolute paths and hostnames, and the structured stores it has
 * are better evidence. So the portable arguments are kept and the file-scanning
 * ones are not offered: accepting `include_debug_log` and ignoring it would be
 * worse than not listing it.
 *
 * `activity_limit` keeps the WordPress name on purpose. It is the cap an agent
 * that has read the WordPress tool's schema will send.
 *
 * ## The filters are applied twice
 *
 * The host applies what its store can express (time, search, row kind) and this
 * tool re-filters the result. A response that claims to obey `levels` must obey
 * it even if a host implementation ignored the argument — the second pass is
 * cheap and keeps the tool's contract true across hosts.
 *
 * ## Capability
 *
 * `manage_options`, not the WordPress tool's declared `edit_posts`. The audit
 * trail is operator-only on this runtime (the page is operator-gated and the
 * trail is cross-estate), and the WordPress tool enforces `manage_options`
 * inside `execute()` anyway — so the effective rule matches; the declaration
 * now tells the truth.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SystemLogInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class GetSystemLogsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 100;

    private const MAX_SEARCH = 200;

    /**
     * An unbound log service means "this host keeps no queryable log", which the
     * registrar's optional-collaborator rule expresses as a null default.
     */
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?SystemLogInterface $log = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'get_system_logs';
    }

    public function getName(): string
    {
        return 'Get System Logs';
    }

    public function getDescription(): string
    {
        return 'Returns recent entries from the host\'s system log stores — the operator audit trail and failed or aborted harness runs — newest first, with severity levels (critical, error, warning, notice, deprecated). Filter by time window, entry type, level or text.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'activity_limit'  => array(
                    'type'        => 'integer',
                    'description' => 'Maximum number of entries to return. Default 20, maximum 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
                'activity_types'  => array(
                    'type'        => 'array',
                    'description' => 'Optional entry types to include: audit.record (a change), audit.request (a request, including refusals), run.incomplete (a turn that ended without a summary), run.aborted (a cancelled turn). Empty means every type.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array( 'audit.record', 'audit.request', 'run.incomplete', 'run.aborted' ),
                    ),
                    'default'     => array(),
                ),
                'levels'          => array(
                    'type'        => 'array',
                    'description' => 'Optional severity levels to include. Empty means every level.',
                    'items'       => array(
                        'type' => 'string',
                        'enum' => array(
                            SystemLogInterface::CRITICAL,
                            SystemLogInterface::ERROR,
                            SystemLogInterface::WARNING,
                            SystemLogInterface::NOTICE,
                            SystemLogInterface::DEPRECATED,
                        ),
                    ),
                    'default'     => array(),
                ),
                'since'           => array(
                    'type'        => 'string',
                    'description' => 'Only return entries at or after this time. Accepts relative windows like "2h", "30m", "3d", "45 minutes" or "2 hours ago" (a bare number is minutes), or an absolute ISO 8601 timestamp. Empty means no time filter.',
                    'default'     => '',
                ),
                'search'          => array(
                    'type'        => 'string',
                    'description' => 'Optional case-insensitive substring filter over an entry\'s summary, actor and detail.',
                    'maxLength'   => self::MAX_SEARCH,
                    'default'     => '',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'local-only', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $limit    = \min(\max(1, $this->intParam($arguments, 'activity_limit', self::DEFAULT_LIMIT)), self::MAX_LIMIT);
        $search   = \mb_substr($this->stringParam($arguments, 'search'), 0, self::MAX_SEARCH);
        $types    = $this->stringListParam($arguments, 'activity_types');
        $levels   = $this->stringListParam($arguments, 'levels');
        $sinceRaw = $this->stringParam($arguments, 'since');
        $since    = '' === $sinceRaw ? '' : $this->parseSince($sinceRaw);

        if ('' !== $sinceRaw && '' === $since) {
            return $this->errors->validationFailed(
                'Unrecognised "since" value.',
                array( 'since' => array( 'Use a relative window (2h, 30m, 3d) or an ISO 8601 timestamp.' ) )
            );
        }

        if (null === $this->log) {
            return $this->emptyHost($limit, $since);
        }

        $sources = $this->log->sources();

        if (array() === $sources) {
            return $this->emptyHost($limit, $since);
        }

        $entries = $this->log->entries(
            array(
                'limit'  => $limit,
                'since'  => $since,
                'search' => $search,
                'types'  => $types,
                'levels' => $levels,
            )
        );

        $entries = $this->filter($entries, $types, $levels, $search, $since);

        \usort(
            $entries,
            static function (array $a, array $b): int {
                $left  = \strtotime((string) ( $a['at'] ?? '' ));
                $right = \strtotime((string) ( $b['at'] ?? '' ));

                if (false === $left || false === $right) {
                    return \strcmp((string) ( $b['at'] ?? '' ), (string) ( $a['at'] ?? '' ));
                }

                return $right <=> $left;
            }
        );

        $entries = \array_slice($entries, 0, $limit);

        return $this->success(
            \sprintf(
                'Found %d system log entries from %s.',
                \count($entries),
                \implode(', ', $sources)
            ),
            array(
                'count'   => \count($entries),
                'limit'   => $limit,
                'since'   => '' === $since ? null : $since,
                'sources' => $sources,
                'entries' => \array_values($entries),
            )
        );
    }

    /**
     * The honest answer when no store is bound or the host declares none.
     *
     * Silently returning an empty list would read as "nothing happened" — the
     * opposite of what an operator needs to know.
     */
    private function emptyHost(int $limit, string $since): array
    {
        return $this->success(
            'This host keeps no queryable system log.',
            array(
                'count'   => 0,
                'limit'   => $limit,
                'since'   => '' === $since ? null : $since,
                'sources' => array(),
                'entries' => array(),
            )
        );
    }

    /**
     * Re-apply every filter the request asked for.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string>               $types
     * @param list<string>               $levels
     *
     * @return list<array<string, mixed>>
     */
    private function filter(array $entries, array $types, array $levels, string $search, string $since): array
    {
        $sinceAt = '' === $since ? false : \strtotime($since);

        $kept = array();

        foreach ($entries as $entry) {
            if (! $this->matchesType($entry, $types) || ! $this->matchesLevel($entry, $levels)) {
                continue;
            }

            if (false !== $sinceAt && \strtotime((string) ( $entry['at'] ?? '' )) < $sinceAt) {
                continue;
            }

            if ('' !== $search && ! $this->matchesSearch($entry, $search)) {
                continue;
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $types
     */
    private function matchesType(array $entry, array $types): bool
    {
        return array() === $types || \in_array((string) ( $entry['type'] ?? '' ), $types, true);
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string>         $levels
     */
    private function matchesLevel(array $entry, array $levels): bool
    {
        return array() === $levels || \in_array((string) ( $entry['level'] ?? '' ), $levels, true);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function matchesSearch(array $entry, string $search): bool
    {
        $haystack = \implode(
            ' ',
            array(
                (string) ( $entry['summary'] ?? '' ),
                (string) ( $entry['actor'] ?? '' ),
                (string) \json_encode($entry['detail'] ?? array()),
            )
        );

        return false !== \stripos($haystack, $search);
    }

    /**
     * Parse the `since` window into an ISO 8601 UTC timestamp.
     *
     * Relative forms mirror the WordPress tool: `2h`, `30m`, `3d`, `45 minutes`,
     * `2 hours ago`; a bare number is minutes. Absolute values go through
     * `strtotime()`, which accepts ISO 8601 and `Y-m-d H:i:s`.
     *
     * @return string ISO 8601 UTC, or empty when the value is not a time.
     */
    private function parseSince(string $value): string
    {
        $value = \trim($value);

        if ('' === $value) {
            return '';
        }

        $relative = \preg_match(
            '/^(\d+)\s*(m|min|mins|minute|minutes|h|hr|hrs|hour|hours|d|day|days)?(?:\s+ago)?$/i',
            $value,
            $matches
        );

        if (1 === $relative) {
            $amount = (int) $matches[1];
            $unit   = \strtolower($matches[2] ?? '');

            if (\str_starts_with($unit, 'h')) {
                $seconds = $amount * 3600;
            } elseif (\str_starts_with($unit, 'd')) {
                $seconds = $amount * 86400;
            } else {
                $seconds = $amount * 60;
            }

            return \gmdate('Y-m-d\TH:i:s\Z', \time() - $seconds);
        }

        $timestamp = \strtotime($value);

        return false === $timestamp ? '' : \gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
