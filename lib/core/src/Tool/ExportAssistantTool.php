<?php

/**
 * Tool: export_assistant — an assistant bundle an agent can carry away.
 *
 * The Laravel runtime could export the estate from the REST controller and from
 * the `/admin` portability page, but an agent asked to "save these assistants"
 * could not: `export_assistant` was named by two presets and had no readiness
 * row, so it was never served.
 *
 * ## Why this delegates instead of building a bundle
 *
 * The `nvoos-assistant` v1 shape and the credential denylist live in
 * `App\Services\AssistantPortability`, which is also what the REST and panel
 * surfaces call. A tool that walked the model itself would be a third
 * implementation of that shape, and the first to drift from the denylist — which
 * is the one part of the bundle that must never be wrong.
 *
 * `AssistantExportInterface` keeps the estate with the host: it is ambient
 * scope, not an argument, because a parameter that named the estate would be a
 * cross-estate read primitive.
 *
 * ## Formats: one, named
 *
 * WordPress offers `json`, `a2a` and `blueprint`. This runtime has no A2A agent
 * card and no Pro blueprint installer, so `json` is the only dialect that can be
 * produced honestly. A caller asking for the others is refused with a sentence
 * that says so — returning a JSON bundle to someone who asked for an A2A card
 * would be a wrong answer delivered confidently.
 *
 * `include_a2a` is deliberately absent rather than accepted-and-ignored: a
 * parameter that cannot change the output is a lying surface.
 *
 * ## The inline ceiling
 *
 * A tool result is not a file download — it becomes part of the conversation and
 * every later turn pays for it. Exporting an estate of large system prompts in
 * one call would exhaust the context window of the model that asked for it, and
 * the export would arrive truncated by the transport rather than refused by us.
 * Above the ceiling the call is refused with the batch size to use instead. The
 * REST route and the panel page stream the same bundle with no such limit; this
 * limit is a property of the chat surface, not of the export.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AssistantExportInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class ExportAssistantTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The largest encoded bundle this tool will return inline.
     *
     * 256 KB is roughly 64k tokens of JSON — already a large fraction of a
     * context window, and a sensible place to stop before the transport does.
     */
    private const MAX_INLINE_BYTES = 262144;

    /**
     * The dialects this runtime can produce.
     */
    private const SUPPORTED_FORMATS = array( 'json' );

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?AssistantExportInterface $exporters = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'export_assistant';
    }

    public function getName(): string
    {
        return 'Export AI Assistants';
    }

    public function getDescription(): string
    {
        return 'Exports one, several, or every assistant in the current estate as a portable nvoos-assistant JSON bundle (format_version 1): titles, system prompts, tool assignments, model settings and plugin meta. Credential keys are never included. Omit assistant_ids to export the whole estate; pass ids to export a batch.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'assistant_ids' => array(
                    'type'        => 'array',
                    'description' => 'Assistant ids to export. Omit (or pass an empty array) to export every assistant in the current estate.',
                    'items'       => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                    'maxItems'    => 100,
                ),
                'format'        => array(
                    'type'        => 'string',
                    'description' => 'Bundle dialect. This runtime produces the canonical nvoos-assistant bundle ("json") only.',
                    'enum'        => self::SUPPORTED_FORMATS,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * `idempotent`, not `cacheable`.
     *
     * Two exports with no intervening write produce the same bundle, so the call
     * is safe to retry. It is not cacheable in spirit: an assistant created a
     * second ago must appear in the next export, and a cached export would hide
     * exactly the change an operator is trying to confirm.
     */
    public function getCapabilityFlags(): array
    {
        return array( 'read-only', 'local-only', 'idempotent', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->exporters) {
            return $this->errors->create(
                'assistant_export_unavailable',
                'This host cannot export assistants.'
            );
        }

        $format = \strtolower($this->stringParam($arguments, 'format'));

        if ('' !== $format && ! \in_array($format, self::SUPPORTED_FORMATS, true)) {
            return $this->errors->validationFailed(
                \sprintf(
                    'Only the "json" bundle can be produced here; "%s" has no surface in this runtime (no A2A agent card, no Pro blueprint installer).',
                    $format
                ),
                array( 'format' => 'Unsupported bundle dialect.' )
            );
        }

        $ids = $this->intListParam($arguments, 'assistant_ids');

        $result = $this->exporters->export(array() === $ids ? null : $ids);

        if (true !== ( $result['success'] ?? false )) {
            $message = (string) ( $result['error'] ?? 'The assistants could not be exported.' );

            if ('assistant_not_found' === ( $result['code'] ?? '' )) {
                // Same status as `duplicate_assistant` uses for a foreign id:
                // two tools disagreeing about whether an id exists elsewhere
                // would be a cross-estate oracle built out of their difference.
                return $this->errors->notFound($message);
            }

            return $this->errors->create((string) ( $result['code'] ?? 'assistant_export_failed' ), $message);
        }

        $bundle = \is_array($result['bundle'] ?? null) ? $result['bundle'] : array();
        $count  = \count(\is_array($bundle['assistants'] ?? null) ? $bundle['assistants'] : array());

        $encoded = \json_encode($bundle);

        if (false === $encoded) {
            return $this->errors->create(
                'assistant_export_failed',
                'The bundle could not be encoded for return.'
            );
        }

        if (\strlen($encoded) > self::MAX_INLINE_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'That bundle is %d bytes, over the %d-byte limit for a tool result. Export fewer assistants per call with assistant_ids, or use the REST export route for the whole estate.',
                    \strlen($encoded),
                    self::MAX_INLINE_BYTES
                ),
                array( 'assistant_ids' => 'Export a smaller batch.' )
            );
        }

        return $this->success(
            \sprintf(
                'Exported %d assistant%s as an nvoos-assistant v%d bundle.',
                $count,
                1 === $count ? '' : 's',
                (int) ( $bundle['format_version'] ?? 1 )
            ),
            array(
                'count'  => $count,
                'bundle' => $bundle,
            )
        );
    }
}
