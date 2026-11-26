<?php

/**
 * Read-only audit view of what the F&B assistants did.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Audit_Log` (Proposal 164 section 164.76). The
 * WordPress tool reads `WP_MCP_AI_Audit_Logger::get_recent_entries()`; this
 * runtime reads the host's queryable log through {@see SystemLogInterface},
 * which is the same seam `get_system_logs` uses.
 *
 * ## Four decisions, each named
 *
 * - **`manage_options`, not the WordPress `read`.** The F&B base declares `read`,
 *   so any subscriber could read the audit trail. Here the trail is the
 *   operator's, cross-estate and already gated on the host — handing it to a
 *   `read` tool would widen access relative to the page that owns the data.
 *   `get_system_logs` made the same correction; the tool follows it.
 * - **The tool filter keeps entries that do not name a tool.** The WordPress
 *   condition only *rejects* an entry when the field is present and does not
 *   match; an entry without `tool` passes. The port keeps that rule, and also
 *   looks in `detail.tool`, because this runtime's entries carry structured
 *   detail rather than a top-level field. It never invents a match.
 * - **`act_tier_seen` is computed, not hardcoded.** The WordPress tool returns
 *   `false` without reading the entries. Here it is true when a returned entry
 *   names `fnb_import_table`, the toolkit's only ACT-tier slug, and the `note`
 *   says what the flag means. A `false` that cannot be wrong is not a finding.
 * - **No `cacheable` flag.** The base declares it because tables are cached for
 *   five minutes; a log that changes on every call must not claim that.
 *
 * ## When the host keeps no log
 *
 * An unbound service or an empty `sources()` is reported as its own answer —
 * "this host keeps no queryable audit log" — rather than an empty list, which
 * would read as "nothing happened". That is the `SystemLogInterface` rule and
 * the reason `get_system_logs` does the same.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SystemLogInterface;
use Nvoos\Core\Support\Text;

class FnbAuditLogTool extends FnbTool
{
    /** Maximum entries the WordPress tool returns. */
    private const MAX_LIMIT = 500;

    /** The WordPress default. */
    private const DEFAULT_LIMIT = 100;

    /** The toolkit's only ACT-tier slug ("Change records" in the spec). */
    private const ACT_TIER_SLUGS = array('fnb_import_table');

    /**
     * The note the WordPress tool returns, plus what the flag now means.
     */
    private const NOTE = 'ACT-tier tools (send, order, change, image generation, posting) are off by default for the demo. act_tier_seen is computed from the returned entries: it is true only when one names fnb_import_table, the toolkit\'s ACT-tier slug.';

    /**
     * An unbound log means "this host keeps no queryable log", which the
     * registrar's optional-collaborator rule expresses as a null default.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly ?SystemLogInterface $log = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_audit_log';
    }

    public function getName(): string
    {
        return 'F&B Audit Log';
    }

    public function getDescription(): string
    {
        return 'Read-only view of the tool-call audit log (tool, limit). Shows what the assistants did — and whether the F&B ACT-tier import ran — from the host\'s queryable log stores.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'tool'  => array(
                    'type'        => 'string',
                    'description' => 'Filter by tool slug (e.g. fnb_save_draft). Entries that do not record a tool are kept, as in WordPress.',
                ),
                'limit' => array(
                    'type'        => 'integer',
                    'description' => 'Maximum entries (default 100, maximum 500; anything outside 1–500 falls back to 100).',
                    'default'     => self::DEFAULT_LIMIT,
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'manage_options');

        $tool  = Text::singleLine($this->scalar($arguments, 'tool'));
        $limit = abs($this->intParam($arguments, 'limit', self::DEFAULT_LIMIT));

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $limit = self::DEFAULT_LIMIT;
        }

        $sources = null !== $this->log ? $this->log->sources() : array();

        if (array() === $sources) {
            return $this->success(
                'This host keeps no queryable audit log.',
                array(
                    'entries'       => array(),
                    'count'         => 0,
                    'act_tier_seen' => false,
                    'note'          => self::NOTE,
                    'source'        => 'fnb_audit_log',
                    'sources'       => array(),
                )
            );
        }

        $filtered = array();

        foreach ($this->log->entries(array('limit' => $limit)) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = $this->toolName($entry);

            if ('' !== $tool && '' !== $name && false === stripos($name, $tool)) {
                continue;
            }

            $filtered[] = $entry;
        }

        $filtered = array_slice($filtered, 0, $limit);
        $seenAct  = false;

        foreach ($filtered as $entry) {
            if (in_array($this->toolName((array) $entry), self::ACT_TIER_SLUGS, true)) {
                $seenAct = true;
            }
        }

        return $this->success(
            sprintf('Found %d audit entries.', count($filtered)),
            array(
                'entries'       => array_values($filtered),
                'count'         => count($filtered),
                'act_tier_seen' => $seenAct,
                'note'          => self::NOTE,
                'source'        => 'fnb_audit_log',
                'sources'       => $sources,
            )
        );
    }

    /**
     * The tool slug an entry names, from the top level or its detail.
     *
     * @param array<string, mixed> $entry Log entry.
     */
    private function toolName(array $entry): string
    {
        if (isset($entry['tool']) && is_string($entry['tool']) && '' !== $entry['tool']) {
            return $entry['tool'];
        }

        $detail = $entry['detail'] ?? null;

        if (is_array($detail) && isset($detail['tool']) && is_string($detail['tool']) && '' !== $detail['tool']) {
            return $detail['tool'];
        }

        return '';
    }
}
