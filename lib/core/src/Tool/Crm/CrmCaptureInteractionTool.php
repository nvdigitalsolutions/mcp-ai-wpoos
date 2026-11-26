<?php

/**
 * Capture a CRM interaction into the account's memory wing.
 *
 * Ported from `WP_MCP_AI_Tool_CRM_Capture_Interaction`, which is a thin
 * subclass of the Pro capture base (`WP_MCP_AI_Pro_Capture_Tool_Base`) and
 * writes through `WP_MCP_AI_Memory_Capture_Service`. The port targets the
 * core's `MemoryStoreInterface`, the framework-agnostic equivalent of that
 * service.
 *
 * ## What this tool does not do
 *
 * It does **not** write the contact store. A hypothesis during slice 5c was
 * that this tool supplies the correspondence keys (`last_contacted`,
 * `contact_count`); reading it disproves that. It writes a memory record in the
 * wing `account/{account_id}` and nothing else, so §164.69's "no writers"
 * finding stands unqualified.
 *
 * ## The capture policy, and where it lives
 *
 * The Pro base builds an envelope of fourteen fields and hands it to the
 * capture service. `MemoryStoreInterface` accepts nine of them as columns, so
 * the policy travels in `metadata`, which the contract documents as the place
 * for capture policy and provenance:
 *
 * | Pro envelope field                    | core record               |
 * |---------------------------------------|---------------------------|
 * | `agent_id`                            | `agent_id`                |
 * | `wing`                                | `wing` = `account/{id}`   |
 * | `room`                                | `room`                    |
 * | `content`, `title`                    | `content`, `title`        |
 * | `tier`, `importance`, `ttl`           | `tier`, `importance`, `ttl` |
 * | `tags`                                | `tags` (defaults + caller) |
 * | `context_type`, `sensitivity`,        | `metadata`                |
 * | `consent_basis`, `verbatim`, `source`,|                           |
 * | `subject_refs`, `attachments`,        |                           |
 * | `recorded_at`, `valid_from`, `valid_until` |                       |
 *
 * ## The normalisation the service was doing
 *
 * Porting the tool without the service means porting the parts of
 * `normalise_envelope()` a caller can reach, because the tool is now the last
 * line before the store:
 *
 * - **`content` is required.** The schema says so, and the service refused an
 *   empty value with `mempalace_capture_missing_content`; the port answers
 *   `validation_failed` here, since a failure envelope is not a tool result.
 * - **`importance` is clamped to 0–1.** The schema declares the bounds, the
 *   service enforced them, and a direct caller can exceed either.
 * - **`ttl` floors at one day.** The service replaced a non-positive value with
 *   `DAY_IN_SECONDS` rather than storing an already-expired record.
 * - **`tier` falls back to `recall`** when the sanitised value is not one of
 *   the three tiers.
 * - **`account_id` and `room` are keys.** The Pro base applies `sanitize_key()`
 *   before it forms the wing slug, so `Acme Corp` addresses `account/acmecorp`.
 *   That is the record's locating key, not cosmetics.
 *
 * Two things the service did that the port leaves to the store, and says so:
 * `expires_at` is derived by `MemoryStoreInterface` from `ttl` (the port
 * restates it in the payload because the WordPress tool returned it), and
 * `recorded_at` / `valid_from` / `valid_until` default to now/expiry in
 * WordPress but are written here only when the caller supplied them — the same
 * choice `docgen_capture_style_memory` made, so an unsupplied provenance field
 * is absent rather than empty.
 *
 * `verbatim` is always true. The Pro base forces it for every toolkit that does
 * not opt into summarisation, and CRM does not, so a caller cannot turn it off
 * there either.
 *
 * The `wp_mcp_ai_company_researched` action has no core equivalent; the hook
 * that mattered in WordPress (`wp_mcp_ai_memory_stored`) is likewise the host's
 * to emit around the store. A host that needs either wraps the tool.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class CrmCaptureInteractionTool extends AbstractTool
{
    /** The rooms this toolkit may write to. */
    public const ROOMS = array('interactions', 'objections', 'next-actions');

    /** The tiers the memory store understands. */
    public const TIERS = array('core', 'recall', 'archival');

    /** The Pro base's capture defaults for this toolkit. */
    public const DEFAULT_IMPORTANCE = 0.6;

    /** 365 days, in seconds. */
    public const DEFAULT_TTL = 31536000;

    /** The floor the capture service applied to a non-positive `ttl`. */
    public const MIN_TTL = 86400;

    /** Tags every capture carries. */
    public const DEFAULT_TAGS = array('crm', 'capture');

    public const DEFAULT_TIER = 'recall';

    public const DEFAULT_SENSITIVITY = 'pii';

    public const DEFAULT_CONSENT_BASIS = 'legitimate_interest';

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly ?MemoryStoreInterface $memory = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'crm_capture_interaction';
    }

    public function getName(): string
    {
        return 'CRM — Capture Interaction';
    }

    public function getDescription(): string
    {
        return 'Capture a CRM interaction, objection, or next-action into the account memory drawer. The record becomes part of everything remembered about this account and is surfaced by hierarchical recall.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * The Pro base's schema, with one omission kept on purpose: `ttl`,
     * `sensitivity`, `consent_basis` and `agent_id` are read by `execute()` but
     * are not declared properties. That is the Pro base's shape, not an
     * oversight here.
     */
    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'account_id'   => array(
                    'type'        => 'string',
                    'description' => 'CRM account / company identifier (CPT post ID, slug, or external CRM id). Forms the wing slug account/{account_id}.',
                ),
                'room'         => array(
                    'type'        => 'string',
                    'description' => 'Memory room within the account wing.',
                    'enum'        => self::ROOMS,
                ),
                'content'      => array(
                    'type'        => 'string',
                    'description' => 'Verbatim text to capture.',
                ),
                'title'        => array(
                    'type'        => 'string',
                    'description' => 'Optional short label for the captured record.',
                ),
                'importance'   => array(
                    'type'        => 'number',
                    'description' => 'Importance 0.0–1.0; drives tier promotion/demotion. Defaults to ' . self::DEFAULT_IMPORTANCE . '.',
                    'minimum'     => 0,
                    'maximum'     => 1,
                ),
                'tier'         => array(
                    'type'        => 'string',
                    'description' => 'Memory tier override.',
                    'enum'        => self::TIERS,
                ),
                'tags'         => array(
                    'type'        => 'array',
                    'description' => 'Free-form tags for downstream filtering.',
                    'items'       => array('type' => 'string'),
                ),
                'subject_refs' => array(
                    'type'        => 'array',
                    'description' => 'External subject references for provenance and replay.',
                    'items'       => array('type' => 'string'),
                ),
                'attachments'  => array(
                    'type'        => 'array',
                    'description' => 'Optional attachments with sha256 / url / mime / attachment_id keys.',
                    'items'       => array('type' => 'object'),
                ),
                'recorded_at'  => array(
                    'type'        => 'string',
                    'description' => 'When the event actually occurred. Defaults to now.',
                ),
                'valid_from'   => array(
                    'type'        => 'string',
                    'description' => 'Bi-temporal validity start.',
                ),
                'valid_until'  => array(
                    'type'        => 'string',
                    'description' => 'Bi-temporal validity end.',
                ),
            ),
            'required'             => array('account_id', 'room', 'content'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        // The Pro base checks for the capture service before it validates
        // anything, so a host without MemPalace answers "unavailable" even to a
        // malformed call. Pinned rather than tidied.
        if (null === $this->memory) {
            return $this->errors->create(
                'memory_store_unavailable',
                'Memory capture requires a memory store. This runtime has none.'
            );
        }

        $accountId = $this->keyParam($arguments, 'account_id');

        if ('' === $accountId) {
            return $this->errors->validationFailed(
                'account_id is required.',
                array('account_id' => array('Give the account this capture belongs to.')),
            );
        }

        $room = $this->keyParam($arguments, 'room');

        if (! in_array($room, self::ROOMS, true)) {
            return $this->errors->validationFailed(
                sprintf('room "%s" is not allowed for this toolkit. Allowed: %s.', $room, implode(', ', self::ROOMS)),
                array('room' => array('Use one of: ' . implode(', ', self::ROOMS) . '.')),
            );
        }

        $content = $this->content($arguments);

        if ('' === $content) {
            return $this->errors->validationFailed(
                'content is required.',
                array('content' => array('Give the text to capture.')),
            );
        }

        $record = array(
            'agent_id'   => $this->agentId($arguments, $context),
            'content'    => $content,
            'title'      => Text::singleLine($this->scalar($arguments, 'title')),
            'tags'       => $this->mergeTags($arguments['tags'] ?? array()),
            'tier'       => $this->tier($arguments),
            'importance' => $this->importance($arguments),
            'wing'       => 'account/' . $accountId,
            'room'       => $room,
            'ttl'        => $this->ttl($arguments),
            'metadata'   => $this->metadata($arguments),
        );

        try {
            $result = $this->memory->store($record);
        } catch (\Throwable $e) {
            return $this->errors->create('capture_failed', $e->getMessage());
        }

        if (empty($result['success'])) {
            return $this->errors->create(
                'capture_failed',
                (string) ( $result['error'] ?? 'Memory capture failed.' )
            );
        }

        return $this->success(
            'Memory captured.',
            array(
                'wing'       => $record['wing'],
                'room'       => $room,
                'tier'       => $record['tier'],
                // The core store names the handle `memory_id`; WordPress called
                // it `context_id`. One name, documented rather than aliased.
                'memory_id'  => (string) ( $result['memory_id'] ?? '' ),
                // Restated because the WordPress tool returned it. The store
                // derives the same instant from the same `ttl`.
                'expires_at' => gmdate('Y-m-d H:i:s', time() + $record['ttl']),
            )
        );
    }

    /**
     * The agent the record belongs to: assistant, explicit agent, then a stable
     * synthetic id, as the Pro base resolves it.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function agentId(array $arguments, array $context): string
    {
        $candidates = array(
            $context['assistant_id'] ?? null,
            $context['agent_id'] ?? null,
            $arguments['agent_id'] ?? null,
        );

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && '' !== trim((string) $candidate)) {
                return trim((string) $candidate);
            }
        }

        return 'capture:' . $this->getSlug();
    }

    /**
     * The capture text, JSON-encoded when the caller passed a structure.
     *
     * `normalise_envelope()` accepted arrays and objects by encoding them, and
     * the alias list (`content`, `text`, `data`, `body`) is unreachable through
     * this tool because the base only passes `content`. Only the encoding is
     * ported.
     *
     * @param array<string, mixed> $arguments
     */
    private function content(array $arguments): string
    {
        $value = $arguments['content'] ?? null;

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value);

            return false !== $encoded ? $encoded : '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function tier(array $arguments): string
    {
        if (! isset($arguments['tier'])) {
            return self::DEFAULT_TIER;
        }

        $tier = $this->keyParam($arguments, 'tier');

        // The service replaced an unrecognised tier with `recall` rather than
        // refusing; so does the port, because the store is the last line here.
        return in_array($tier, self::TIERS, true) ? $tier : self::DEFAULT_TIER;
    }

    /**
     * Importance, cast and clamped the way the service clamped it.
     *
     * @param array<string, mixed> $arguments
     */
    private function importance(array $arguments): float
    {
        if (! isset($arguments['importance'])) {
            return self::DEFAULT_IMPORTANCE;
        }

        $importance = (float) $arguments['importance'];

        return max(0.0, min(1.0, $importance));
    }

    /**
     * Seconds before expiry, floored at one day.
     *
     * `ttl` is absent from the schema on purpose: the Pro base accepts it in
     * `execute()` without declaring it, and so does the port.
     *
     * @param array<string, mixed> $arguments
     */
    private function ttl(array $arguments): int
    {
        if (! isset($arguments['ttl'])) {
            return self::DEFAULT_TTL;
        }

        $ttl = abs((int) $arguments['ttl']);

        return $ttl > 0 ? $ttl : self::MIN_TTL;
    }

    /**
     * The capture policy and provenance the core record carries in `metadata`.
     *
     * `sensitivity` and `consent_basis` are read from the arguments when
     * supplied, as the Pro base reads them, although neither is a declared
     * schema property.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function metadata(array $arguments): array
    {
        $metadata = array(
            'context_type'  => $this->getSlug(),
            'sensitivity'   => $this->label($arguments, 'sensitivity', self::DEFAULT_SENSITIVITY),
            'consent_basis' => $this->label($arguments, 'consent_basis', self::DEFAULT_CONSENT_BASIS),
            'verbatim'      => true,
            'source'        => $this->getSlug(),
        );

        $refs = $this->refs($arguments['subject_refs'] ?? null);

        if (array() !== $refs) {
            $metadata['subject_refs'] = $refs;
        }

        $attachments = $this->attachments($arguments['attachments'] ?? null);

        if (array() !== $attachments) {
            $metadata['attachments'] = $attachments;
        }

        foreach (array('recorded_at', 'valid_from', 'valid_until') as $field) {
            $value = Text::singleLine($this->scalar($arguments, $field));

            if ('' !== $value) {
                $metadata[ $field ] = $value;
            }
        }

        return $metadata;
    }

    /**
     * A sanitised label from the arguments, or the toolkit default.
     *
     * @param array<string, mixed> $arguments
     */
    private function label(array $arguments, string $key, string $default): string
    {
        if (! isset($arguments[ $key ])) {
            return $default;
        }

        $value = $this->keyParam($arguments, $key);

        return '' !== $value ? $value : $default;
    }

    /**
     * Scalar references as trimmed strings, the way the service filtered them.
     *
     * @return list<string>
     */
    private function refs(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $refs = array();

        foreach ($value as $ref) {
            if (is_scalar($ref) && '' !== trim((string) $ref)) {
                $refs[] = Text::singleLine((string) $ref);
            }
        }

        return $refs;
    }

    /**
     * Attachment maps only.
     *
     * The service also filled `attachment_id`, `sha256`, `mime` and `url` with
     * empty values; the core contract asks for "ids and hashes, not bytes" and
     * an absent key means what an empty key would, so the port carries the map
     * as given rather than padding it.
     *
     * @return list<array<string, mixed>>
     */
    private function attachments(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * Default tags plus the caller's, deduplicated, empties dropped.
     *
     * @return list<string>
     */
    private function mergeTags(mixed $extra): array
    {
        $tags = self::DEFAULT_TAGS;

        if (is_array($extra)) {
            foreach ($extra as $tag) {
                if (is_scalar($tag) && '' !== trim((string) $tag)) {
                    $tags[] = Text::singleLine((string) $tag);
                }
            }
        } elseif (is_scalar($extra) && '' !== trim((string) $extra)) {
            $tags[] = Text::singleLine((string) $extra);
        }

        return array_values(array_unique($tags));
    }

    /**
     * The core equivalent of `sanitize_key()`, as the Pro base applied it.
     *
     * @param array<string, mixed> $arguments
     */
    private function keyParam(array $arguments, string $key): string
    {
        return Text::sanitizeKey($this->scalar($arguments, $key));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function scalar(array $arguments, string $key): string
    {
        $value = $arguments[ $key ] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
