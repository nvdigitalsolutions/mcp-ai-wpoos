<?php

/**
 * Tool: duplicate_assistant — copy an assistant into a new draft.
 *
 * The WordPress tool implements duplication as export-then-import, which is the
 * right shape for moving an assistant between sites but heavy for a copy: the
 * bundle exists to cross a boundary this operation does not cross. Here the host
 * reads the source in the caller's estate and writes it back through the same
 * `AssistantWriter` the create tool uses, so a duplicate acquires estate
 * assignment, per-estate slug uniqueness and the draft default by construction.
 *
 * ## What is not copied, and why
 *
 * - **`status`.** WordPress lets a duplicate be published in one call; this tool
 *   does not. A duplicate is a draft, and publishing is the same operator action
 *   it is on `create_assistant`.
 * - **`is_default`.** Copying the flag would either create a second default or
 *   silently un-default the source. Neither is a copy.
 * - **The slug.** Derived from the new title and de-duplicated per estate, so
 *   `support-bot` copies to `support-bot-copy`.
 * - **Credentials.** Nothing to copy by design: this runtime issues assistant
 *   credentials through a separate service, and the writer's `advanced` whitelist
 *   excludes them.
 *
 * ## A source in another estate
 *
 * Reported as **not found**, not forbidden. A `403` would confirm that a record
 * with that id exists somewhere — which is the fact a cross-tenant probe is
 * asking for.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AssistantWriteInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class DuplicateAssistantTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?AssistantWriteInterface $assistants = null,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'duplicate_assistant';
    }

    public function getName(): string
    {
        return 'Duplicate AI Assistant';
    }

    public function getDescription(): string
    {
        return 'Copies an assistant in the current estate into a new draft, keeping its system prompt, provider, model, sampling settings, tool list and metadata. The copy is never published and never becomes the estate default. Returns the new assistant id, slug and status.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'assistant_id' => array(
                    'type'        => 'integer',
                    'description' => 'The assistant to copy.',
                    'minimum'     => 1,
                ),
                'title'        => array(
                    'type'        => 'string',
                    'description' => 'Title for the copy. Defaults to "<original> (Copy)".',
                    'maxLength'   => 255,
                ),
            ),
            'required'             => array( 'assistant_id' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array( 'write', 'state-changing', 'local-only', 'requires-capability' );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $assistantId = $this->intParam($arguments, 'assistant_id', 0);

        if ($assistantId < 1) {
            return $this->errors->validationFailed(
                'assistant_id is required.',
                array( 'assistant_id' => array( 'A positive assistant id is required.' ) )
            );
        }

        if (null === $this->assistants) {
            return $this->success(
                'This host does not accept assistant writes.',
                array( 'assistant' => array() )
            );
        }

        $title  = \mb_substr($this->stringParam($arguments, 'title'), 0, 255);
        $result = $this->assistants->duplicate($assistantId, $title);

        if (empty($result['success'])) {
            $message = (string) ( $result['error'] ?? 'The assistant could not be duplicated.' );

            if ('assistant_not_found' === ( $result['code'] ?? '' )) {
                return $this->errors->notFound(
                    $message,
                    array( 'assistant_id' => $assistantId )
                );
            }

            return $this->errors->create(
                'assistant_duplicate_failed',
                $message,
                array( 'assistant_id' => $assistantId )
            );
        }

        return $this->success(
            \sprintf('Duplicated assistant #%d as a draft.', $assistantId),
            array(
                'assistant' => array(
                    'id'     => (int) ( $result['assistant_id'] ?? 0 ),
                    'slug'   => (string) ( $result['slug'] ?? '' ),
                    'status' => (string) ( $result['status'] ?? 'draft' ),
                ),
                'source_id' => $assistantId,
            )
        );
    }
}
