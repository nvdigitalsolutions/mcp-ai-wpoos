<?php

/**
 * Save an assistant output as a draft.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Save_Draft` (Proposal 164 section 164.75). The
 * WordPress tool inherits the base `read` capability and the `read-only` flag,
 * yet it writes a file — a contradiction that would tell this runtime's
 * read-only profile gate that nothing changed when a Paper Store record did.
 * The port therefore asks for `edit_posts` and declares the write, and the
 * deviation is recorded in the readiness row.
 *
 * The draft always carries Draft status: this tool never sends, orders or
 * changes records.
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
use Nvoos\Core\Support\Text;

class FnbSaveDraftTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbDraftWriter $drafts,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_save_draft';
    }

    public function getName(): string
    {
        return 'F&B Save Draft';
    }

    public function getDescription(): string
    {
        return 'Save an assistant output (report, brief, purchase request) as a draft in the Drafts collection. Outputs are always Draft status for approval — this tool never sends messages, places orders or changes records (G-12).';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'title'   => array(
                    'type'        => 'string',
                    'description' => 'Draft title, conventionally "[R-xx] <title> — <period> — Draft — for <approver>".',
                ),
                'content' => array(
                    'type'        => 'string',
                    'description' => 'Markdown content of the draft.',
                ),
            ),
            'required'             => array('title', 'content'),
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
        return array('requires-capability', 'state-changing', 'write', 'idempotent');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $title   = Text::singleLine($this->scalar($arguments, 'title'));
        $content = isset($arguments['content']) && is_string($arguments['content'])
            ? $arguments['content']
            : '';

        $result = $this->drafts->save($title, $content);

        if ($this->errors->isError($result)) {
            return $result;
        }

        return $this->success(
            sprintf('Draft "%s" saved.', (string) $result['title']),
            $result
        );
    }
}
