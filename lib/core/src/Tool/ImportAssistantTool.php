<?php

/**
 * Tool: import_assistant — restore assistants from a bundle an agent can carry.
 *
 * The app could already import from the REST portability controller and the
 * `/admin` portability page; an agent could not. `import_assistant` was named by
 * two presets and had no readiness row.
 *
 * ## Why this is not `create_assistant` in a loop
 *
 * A bundle is a document with a shape, a version and conflict semantics. Creating
 * each entry through `create_assistant` would lose all three: it would ignore
 * `format_version`, create duplicates instead of matching existing assistants,
 * and cannot express `skip`. The engine that owns those rules is
 * `App\Services\AssistantPortability`, and this tool delegates to it through
 * `AssistantImportInterface` — the same arrangement `export_assistant` uses in the
 * other direction.
 *
 * ## `manage_options`, because `overwrite` is the restore path
 *
 * `overwrite` replaces an existing assistant's prompt, provider and tool list.
 * That is what restore is, and it is why WordPress gates this tool on
 * `manage_options` rather than the `edit_posts` that `create_assistant` needs.
 * `edit_posts` may add an assistant; it may not replace one.
 *
 * ## Modes are refused, never coerced
 *
 * The engine falls back to `skip` for a mode it does not know. Left alone that
 * would make an unknown mode silently mean "did nothing", and a caller that
 * asked to overwrite would believe a restore had happened. The tool refuses an
 * unknown mode before the engine sees it.
 *
 * ## No `status_override`, no `confirm_destructive`
 *
 * A bundle records each assistant's status, and a restore that levelled
 * everything to draft would not be a restore — so status flows from the document.
 * A separate override would be a way to *change* status during a restore, which
 * is the operation `create_assistant` withholds, so it is not offered.
 *
 * WordPress's `confirm_destructive` has no consumer in this runtime: confirmation
 * is the approvals queue's job, driven by the `state-changing` flag this tool
 * declares. Accepting a parameter nothing reads would be a lying surface.
 *
 * ## The input ceiling
 *
 * WordPress caps the payload at 2 MB and so does this. It is a guard, not a
 * target: the caller produced this JSON to send it, so the limit protects the
 * host from a malformed size claim rather than protecting a conversation the way
 * `export_assistant`'s output ceiling does. The report that comes back is small
 * regardless of how large the bundle was.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AssistantImportInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class ImportAssistantTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The largest payload accepted, matching the WordPress tool.
     */
    private const MAX_INPUT_BYTES = 2097152;

    /**
     * The conflict modes the engine understands.
     */
    private const MODES = array( 'skip', 'duplicate', 'overwrite' );

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?AssistantImportInterface $importers = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'import_assistant';
    }

    public function getName(): string
    {
        return 'Import AI Assistants';
    }

    public function getDescription(): string
    {
        return 'Imports assistants from a portable nvoos-assistant JSON bundle (format_version 1) into the current estate, restoring titles, system prompts, tool assignments, model settings, statuses and plugin meta. Credential keys are stripped on the way in. mode controls what happens when an assistant already exists: skip (default), duplicate, or overwrite. dry_run reports what would happen without writing.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'json'    => array(
                    'type'        => 'string',
                    'description' => 'The bundle document, as produced by export_assistant or a WordPress export.',
                    'maxLength'   => self::MAX_INPUT_BYTES,
                ),
                'mode'    => array(
                    'type'        => 'string',
                    'description' => 'What to do when an imported assistant already exists, matched by slug and then title. "skip" leaves it alone, "duplicate" creates another copy, "overwrite" replaces it.',
                    'enum'        => self::MODES,
                    'default'     => 'skip',
                ),
                'dry_run' => array(
                    'type'        => 'boolean',
                    'description' => 'Validate the document and report what would happen without writing anything.',
                    'default'     => false,
                ),
            ),
            'required'             => array( 'json' ),
            'additionalProperties' => false,
        );
    }

    /**
     * `manage_options`, not `edit_posts`.
     *
     * `overwrite` replaces an assistant rather than adding one, and WordPress
     * gates its import tool the same way. The approvals queue picks the call up
     * from the `state-changing` flag.
     */
    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    /**
     * No `reversible`: `overwrite` destroys the previous prompt and tool list,
     * and nothing here keeps a copy to put back.
     */
    public function getCapabilityFlags(): array
    {
        return array( 'write', 'state-changing', 'local-only', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        if (null === $this->importers) {
            return $this->errors->create(
                'assistant_import_unavailable',
                'This host cannot import assistants.'
            );
        }

        // Read the document directly rather than through `stringParam()`, which
        // runs `strip_tags()`: a bundle is a transport format, not HTML, and
        // stripping tags from the whole string would quietly rewrite the system
        // prompts inside it. Type-check, trim the edges, and let the engine
        // decide whether the document is a bundle.
        $json = isset($arguments['json']) && \is_string($arguments['json']) ? \trim($arguments['json']) : '';

        if ('' === $json) {
            return $this->errors->validationFailed(
                'An import needs a bundle document in "json".',
                array( 'json' => 'Required.' )
            );
        }

        if (\strlen($json) > self::MAX_INPUT_BYTES) {
            return $this->errors->validationFailed(
                \sprintf(
                    'That bundle is %d bytes, over the %d-byte limit.',
                    \strlen($json),
                    self::MAX_INPUT_BYTES
                ),
                array( 'json' => 'Payload too large.' )
            );
        }

        $mode = \strtolower($this->stringParam($arguments, 'mode'));

        if ('' === $mode) {
            $mode = 'skip';
        }

        // Refused rather than coerced: the engine would fall back to `skip`, and
        // a caller that asked to overwrite must never be told it did.
        if (! \in_array($mode, self::MODES, true)) {
            return $this->errors->validationFailed(
                \sprintf(
                    'Unknown import mode "%s". Use skip, duplicate or overwrite.',
                    $mode
                ),
                array( 'mode' => 'Unsupported mode.' )
            );
        }

        $dryRun = $this->isDryRun($arguments);

        $result = $this->importers->import($json, $mode, $dryRun);

        if (true !== ( $result['success'] ?? false )) {
            $message = (string) ( $result['error'] ?? 'The bundle could not be imported.' );

            if ('bundle_invalid' === ( $result['code'] ?? '' )) {
                return $this->errors->validationFailed($message);
            }

            return $this->errors->create(
                (string) ( $result['code'] ?? 'assistant_import_failed' ),
                $message
            );
        }

        $report = \is_array($result['report'] ?? null) ? $result['report'] : array();

        $imported = (int) ($report['imported'] ?? 0);
        $updated  = (int) ($report['updated'] ?? 0);
        $skipped  = (int) ($report['skipped'] ?? 0);

        $assistants = \is_array($report['assistants'] ?? null) ? $report['assistants'] : array();

        return $this->success(
            \sprintf(
                '%sImported %d, updated %d, skipped %d.',
                $dryRun ? 'Dry run: ' : '',
                $imported,
                $updated,
                $skipped
            ),
            array(
                'dry_run'    => $dryRun,
                'imported'   => $imported,
                'updated'    => $updated,
                'skipped'    => $skipped,
                'mode'       => $mode,
                'assistants' => \array_values($assistants),
            )
        );
    }
}
