<?php

/**
 * List the drafts saved so far.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_List_Drafts` (Proposal 164 section 164.75). Reads
 * the same Paper Store collection {@see FnbSaveDraftTool} writes, newest first.
 * `name` is `<id>.md` and `path` is the record locator, so a consumer that read
 * the WordPress payload can still tell one draft from another.
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

class FnbListDraftsTool extends FnbTool
{
    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbDraftWriter $drafts,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_list_drafts';
    }

    public function getName(): string
    {
        return 'F&B List Drafts';
    }

    public function getDescription(): string
    {
        return 'List the drafts saved so far, with name and size.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            // Empty stdClass encodes as `{}`; an empty PHP array would encode as
            // `[]`, which strict providers reject.
            'properties'           => (object) array(),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $result = $this->drafts->list();

        return $this->success(
            sprintf('%d draft(s).', (int) $result['count']),
            $result
        );
    }
}
