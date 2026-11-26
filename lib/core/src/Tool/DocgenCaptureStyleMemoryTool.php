<?php

/**
 * Capture a writing-style sample or a draft into agent memory.
 *
 * Port of `WP_MCP_AI_Tool_DocGen_Capture_Style_Memory` under Proposal 164
 * §164.41.
 *
 * ## The verbatim discipline, expressed as two records
 *
 * The WordPress tool writes the original at `tier=archival` and a summary at
 * `tier=recall`, so a later search finds the short representative while the
 * exact words are still there to go back to. This runtime's
 * `MemoryStoreInterface` has both tiers, and the same shape is reproduced here
 * with two `store()` calls.
 *
 * The WordPress service summaries on its own; this tool cannot, because it holds
 * no model. So a caller who wants the recall representative short supplies
 * `summary`, and a caller who does not gets **one** record holding the verbatim
 * text at `tier=recall`. Writing a second copy at `archival` with identical
 * bytes would double the storage to reproduce a distinction that does not exist
 * in that case.
 *
 * ## Provenance travels in the metadata map
 *
 * `subject_refs`, `attachments`, `recorded_at`, `valid_from`, `valid_until`,
 * `sensitivity` and `consent_basis` are carried in the record's `metadata` map,
 * which `MemoryStoreInterface` has held since §164.42. Only what the caller
 * supplies is written, so a record without provenance has an empty map rather
 * than a map full of empty values.
 *
 * `verbatim` is **derived, not asserted**. The archival record holds the original
 * so it says `true`; a recall record holding a summary says `false`. A caller-
 * supplied flag would let the record claim something the tool knows better than
 * the caller does.
 *
 * `recorded_at` is written only when supplied, because the row's own `created_at`
 * already records when the capture happened — the field earns its place when the
 * event occurred earlier than that. `allow_summarisation` is absent entirely:
 * there is no model here to summarise with, so the flag would describe a
 * behaviour this tool does not have.
 *
 * `tier` is not a parameter either: the verbatim record is `archival` and the
 * representative is `recall` by construction. A knob that let a caller move the
 * original out of archival would defeat the discipline the tool exists for.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class DocgenCaptureStyleMemoryTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Two years, matching the WordPress tool's default.
     */
    public const DEFAULT_TTL = 63072000;

    public const DEFAULT_IMPORTANCE = 0.55;

    /** The two rooms the WordPress tool recognises, and what they mean.
     *
     * @var list<string>
     */
    public const ROOMS = array('style', 'drafts');

    /** References one record may cite. */
    public const MAX_REFS = 50;

    /** Attachments one record may cite. */
    public const MAX_ATTACHMENTS = 25;

    /** Characters in a classification label such as `sensitivity`. */
    public const MAX_LABEL = 64;

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly MemoryStoreInterface $memory,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'docgen_capture_style_memory';
    }

    public function getName(): string
    {
        return 'Document Generation — Capture Style Memory';
    }

    public function getDescription(): string
    {
        return 'Remember a writing-style sample or a draft so later documents can match the author\'s voice. The exact text is kept at tier=archival and an optional summary becomes the tier=recall representative, under wing user/{agent_id} and room style or drafts.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'agent_id' => array(
                    'type' => array('string', 'integer'),
                    'description' => 'Whose voice this is — the user or agent the memory belongs to. Forms the wing slug user/{agent_id}, mirroring the WordPress tool\'s user/{user_id}.',
                ),
                'kind' => array(
                    'type' => 'string',
                    'description' => '"style" for a sample of the author\'s voice, "drafts" for a document they wrote.',
                    'enum' => self::ROOMS,
                    'default' => 'style',
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The text to remember, kept exactly as given at tier=archival.',
                ),
                'summary' => array(
                    'type' => 'string',
                    'description' => 'Optional short description of the same text. When given it becomes the tier=recall representative and the content stays archival.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Short label for the memory. Default "Style sample" or "Draft".',
                ),
                'importance' => array(
                    'type' => 'number',
                    'description' => 'Importance 0–1, which drives retrieval ranking. Default ' . self::DEFAULT_IMPORTANCE . '.',
                    'minimum' => 0,
                    'maximum' => 1,
                ),
                'tags' => array(
                    'type' => 'array',
                    'description' => 'Extra tags for filtering. "docgen" and the kind are always added.',
                    'items' => array('type' => 'string'),
                ),
                'subject_refs' => array(
                    'type' => 'array',
                    'description' => 'Where the sample came from — URLs, document ids, message ids. Recorded as provenance. At most ' . self::MAX_REFS . '.',
                    'items' => array('type' => 'string'),
                    'maxItems' => self::MAX_REFS,
                ),
                'attachments' => array(
                    'type' => 'array',
                    'description' => 'Supporting attachments as objects, e.g. {"sha256", "url", "mime", "file_id"}. Ids and hashes, not bytes. At most ' . self::MAX_ATTACHMENTS . '.',
                    'items' => array('type' => 'object'),
                    'maxItems' => self::MAX_ATTACHMENTS,
                ),
                'recorded_at' => array(
                    'type' => 'string',
                    'description' => 'When the event actually happened, if earlier than the capture. ISO 8601 date or date-time. Omitted means now, which the record already timestamps.',
                ),
                'valid_from' => array(
                    'type' => 'string',
                    'description' => 'Start of the window in which this was true, YYYY-MM-DD.',
                ),
                'valid_until' => array(
                    'type' => 'string',
                    'description' => 'End of that window, YYYY-MM-DD. Set it on the record being superseded rather than overwriting the text.',
                ),
                'sensitivity' => array(
                    'type' => 'string',
                    'description' => 'Classification label, e.g. "pii" or "internal". Recorded as given.',
                ),
                'consent_basis' => array(
                    'type' => 'string',
                    'description' => 'Why this may be stored, e.g. "consent" or "contract". Recorded as given.',
                ),
                'ttl' => array(
                    'type' => 'integer',
                    'description' => 'Seconds before the memory expires. Default ' . self::DEFAULT_TTL . ' (two years).',
                    'minimum' => 1,
                ),
            ),
            'required' => array('agent_id', 'content'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $agentId = trim((string) $this->agentId($arguments['agent_id'] ?? null));
        $content = $this->bodyParam($arguments, 'content');
        $summary = $this->bodyParam($arguments, 'summary');
        $kind = strtolower($this->stringParam($arguments, 'kind', 'style'));

        if ('' === $agentId) {
            return $this->errors->validationFailed(
                'An agent_id is required.',
                array('agent_id' => array('Whose voice is this?')),
            );
        }

        if ('' === trim($content)) {
            return $this->errors->validationFailed(
                'Nothing to remember: content is empty.',
                array('content' => array('Give the text to remember.')),
            );
        }

        if (!in_array($kind, self::ROOMS, true)) {
            return $this->errors->validationFailed(
                'kind must be one of: ' . implode(', ', self::ROOMS) . '.',
                array('kind' => array('Use "style" or "drafts".')),
            );
        }

        $wing = 'user/' . $agentId;
        $tags = $this->tags($arguments, $kind);
        $importance = $this->importance($arguments);
        $ttl = max(1, $this->intParam($arguments, 'ttl', self::DEFAULT_TTL));
        $title = $this->stringParam($arguments, 'title', 'drafts' === $kind ? 'Draft' : 'Style sample');

        $wantsRepresentative = '' !== trim($summary) && trim($summary) !== trim($content);

        try {
            $provenance = $this->provenance($arguments);
        } catch (\InvalidArgumentException $e) {
            return $this->errors->validationFailed($e->getMessage());
        }

        // `verbatim` describes what the record holds, which the tool knows and
        // the caller does not: the archival record is the original, and a recall
        // record holding a summary is not.
        $archivalMetadata = array(
            'source' => 'docgen_capture_style_memory',
            'verbatim' => true,
        ) + $provenance;

        $recallMetadata = array(
            'source' => 'docgen_capture_style_memory',
            'verbatim' => !$wantsRepresentative,
        ) + $provenance;

        $archival = array('id' => null, 'error' => null);

        // The verbatim record goes first: a summary without its original breaks
        // the discipline this tool exists for, so a failure here stops before
        // anything shorter is written.
        if ($wantsRepresentative) {
            $archival = $this->store(
                $agentId,
                $content,
                $title,
                $tags,
                MemoryStoreInterface::TIER_ARCHIVAL,
                $importance,
                $wing,
                $kind,
                $ttl,
                $archivalMetadata,
            );

            if (null !== $archival['error']) {
                return $archival['error'];
            }
        }

        $representative = $this->store(
            $agentId,
            $wantsRepresentative ? $summary : $content,
            $title,
            $tags,
            MemoryStoreInterface::TIER_RECALL,
            $importance,
            $wing,
            $kind,
            $ttl,
            $recallMetadata,
        );

        if (null !== $representative['error']) {
            return $representative['error'];
        }

        return $this->success(
            $wantsRepresentative
                ? 'Remembered the verbatim text at archival tier with a recall summary.'
                : 'Remembered the text at recall tier.',
            array(
                'wing' => $wing,
                'room' => $kind,
                'archival_id' => $wantsRepresentative ? $archival['id'] : null,
                'recall_id' => $representative['id'],
                'records' => $wantsRepresentative ? 2 : 1,
            ),
        );
    }

    /**
     * Write one record, or the error to return.
     *
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     *
     * @return array{id: string|null, error: mixed}
     */
    private function store(
        string $agentId,
        string $content,
        string $title,
        array $tags,
        string $tier,
        float $importance,
        string $wing,
        string $room,
        int $ttl,
        array $metadata,
    ): array {
        try {
            $result = $this->memory->store(array(
                'agent_id' => $agentId,
                'content' => $content,
                'title' => $title,
                'tags' => $tags,
                'tier' => $tier,
                'importance' => $importance,
                'wing' => $wing,
                'room' => $room,
                'ttl' => $ttl,
                'metadata' => $metadata,
            ));
        } catch (\Throwable $e) {
            return array(
                'id' => null,
                'error' => $this->errors->create('store_failed', $e->getMessage()),
            );
        }

        if (!($result['success'] ?? false)) {
            return array(
                'id' => null,
                'error' => $this->errors->create(
                    'store_failed',
                    (string) ($result['error'] ?? 'The memory store refused the record.'),
                    array('tier' => $tier),
                ),
            );
        }

        return array(
            'id' => isset($result['memory_id']) ? (string) $result['memory_id'] : null,
            'error' => null,
        );
    }

    /**
     * The memory owner's id, from a string or an integer.
     */
    private function agentId(mixed $value): string
    {
        if (is_int($value) || is_string($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * `docgen` and the kind are always present, so a later search can find every
     * style memory without knowing which caller wrote it.
     *
     * @return list<string>
     */
    private function tags(array $arguments, string $kind): array
    {
        $tags = array('docgen', $kind);

        foreach ($this->stringListParam($arguments, 'tags') as $tag) {
            $tag = trim($tag);

            if ('' !== $tag && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function importance(array $arguments): float
    {
        $value = $this->floatParam($arguments, 'importance', self::DEFAULT_IMPORTANCE);

        return min(1.0, max(0.0, $value));
    }

    /**
     * Read a free-text field without stripping markup characters.
     *
     * `stringParam()` applies `strip_tags()`, which is wrong for the thing this
     * tool stores: a style sample may legitimately contain "a < b", and a draft
     * may contain markup the author typed. The text is written to memory as
     * given, not rendered.
     */
    private function bodyParam(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The caller's provenance, validated, for the metadata map.
     *
     * Only what was supplied is included. A record with no provenance gets an
     * empty map rather than a map of empty values, so a reader can tell "nothing
     * recorded" from "recorded as empty".
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When a value cannot be recorded as stated.
     */
    private function provenance(array $arguments): array
    {
        $meta = array();

        $refs = $this->refs($arguments);

        if (array() !== $refs) {
            $meta['subject_refs'] = $refs;
        }

        $attachments = $this->attachmentList($arguments);

        if (array() !== $attachments) {
            $meta['attachments'] = $attachments;
        }

        foreach (array('recorded_at', 'valid_from', 'valid_until') as $key) {
            $instant = $this->instant($arguments[$key] ?? '', $key);

            if ('' !== $instant) {
                $meta[$key] = $instant;
            }
        }

        foreach (array('sensitivity', 'consent_basis') as $key) {
            $label = $this->label($arguments[$key] ?? '', $key);

            if ('' !== $label) {
                $meta[$key] = $label;
            }
        }

        return $meta;
    }

    /**
     * The references, trimmed, de-duplicated and capped.
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException When there are too many.
     */
    private function refs(array $arguments): array
    {
        $refs = array();

        foreach ($this->stringListParam($arguments, 'subject_refs') as $ref) {
            $ref = trim($ref);

            if ('' !== $ref && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        if (count($refs) > self::MAX_REFS) {
            throw new \InvalidArgumentException('subject_refs carries at most ' . self::MAX_REFS . ' references.');
        }

        return $refs;
    }

    /**
     * The attachments, each of which must be an object rather than a blob.
     *
     * @return list<array<string, mixed>>
     *
     * @throws \InvalidArgumentException When an entry is not an object or there are too many.
     */
    private function attachmentList(array $arguments): array
    {
        $attachments = array();

        foreach (array_values($this->arrayParam($arguments, 'attachments')) as $index => $attachment) {
            if (!is_array($attachment)) {
                throw new \InvalidArgumentException('Attachment ' . ($index + 1) . ' is not an object.');
            }

            $attachments[] = $attachment;
        }

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new \InvalidArgumentException('attachments carries at most ' . self::MAX_ATTACHMENTS . ' entries.');
        }

        return $attachments;
    }

    /**
     * A date, or a date and time with an optional zone.
     *
     * Deliberately not a full RFC 3339 parser. The point is to stop a value that
     * is obviously not a date from being stored as permanent provenance, not to
     * normalise every spelling a caller might use.
     *
     * @throws \InvalidArgumentException When the value is not a date.
     */
    private function instant(mixed $value, string $label): string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';

        if ('' === $raw) {
            return '';
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $raw)) {
            throw new \InvalidArgumentException(
                $label . ' must be a date or date-time, e.g. 2026-11-25 or 2026-11-25T10:00:00Z.',
            );
        }

        return $raw;
    }

    /**
     * A short classification label.
     *
     * @throws \InvalidArgumentException When it is too long.
     */
    private function label(mixed $value, string $name): string
    {
        $raw = is_scalar($value) ? trim((string) $value) : '';

        if (mb_strlen($raw) > self::MAX_LABEL) {
            throw new \InvalidArgumentException($name . ' is at most ' . self::MAX_LABEL . ' characters.');
        }

        return $raw;
    }
}
