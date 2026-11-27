<?php

/**
 * Read an Outlook mail folder.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Outlook_Messages` (Proposal 164 section
 * 164.104). One `GET /me/mailFolders/{folder_id}/messages`.
 *
 * ## Corrections
 *
 * 1. **Rows are projected** to id, subject, sender, received time, preview,
 *    read and attachment flags and web link, where WordPress returned Graph's
 *    messages verbatim.
 * 2. **The next page is reachable** through `next_link`, where WordPress
 *    dropped `@odata.nextLink`.
 * 3. **`top` is read with the numeric clamp**, so a non-numeric value means
 *    "not specified" and the default of 25 applies, instead of `absint()`
 *    turning it into zero and then one.
 *
 * The folder id is percent-encoded as one path segment. A well-known name
 * (`inbox`, `sentitems`, `drafts`) and a folder id both work; a value with a
 * slash cannot change the route because the slash is encoded.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetOutlookMessagesTool extends GraphTool
{
    private const DEFAULT_TOP = 25;
    private const MAX_TOP = 50;

    public function getSlug(): string
    {
        return 'get_outlook_messages';
    }

    public function getName(): string
    {
        return 'Get Outlook Messages';
    }

    public function getDescription(): string
    {
        return 'List messages in an Outlook mail folder: id, subject, sender, received time, preview, read and attachment flags, and web link. Accepts an OData filter and a next_link for paging.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'     => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'folder_id' => array(
                    'type'        => 'string',
                    'description' => 'Mail folder id or well-known name such as inbox, sentitems or drafts. Defaults to inbox.',
                ),
                'top'       => array(
                    'type'        => 'integer',
                    'description' => 'How many messages to return, 1-50. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => 50,
                ),
                'filter'    => array(
                    'type'        => 'string',
                    'description' => 'OData filter expression, for example isRead eq false.',
                ),
                'next_link' => array(
                    'type'        => 'string',
                    'description' => 'next_link from a previous page. Must be an https Graph URL.',
                ),
            ),
            'required'             => array('token'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $folder = $this->textArg($arguments, 'folder_id');

        if ('' === $folder) {
            $folder = 'inbox';
        }

        $top = $this->limit($arguments['top'] ?? null, self::DEFAULT_TOP, self::MAX_TOP);

        $query = array('$top' => $top);

        $filter = $this->textArg($arguments, 'filter');

        if ('' !== $filter) {
            $query['$filter'] = $filter;
        }

        $page = $this->page(
            $token,
            '/me/mailFolders/' . rawurlencode($folder) . '/messages',
            $query,
            $arguments
        );

        if ($this->errors->isError($page)) {
            return $page;
        }

        $rows = array();

        foreach (is_array($page['value'] ?? null) ? $page['value'] : array() as $message) {
            $row = $this->mailRow($message);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d message%s in %s.', count($rows), 1 === count($rows) ? '' : 's', $folder),
            array(
                'messages'  => $rows,
                'count'     => count($rows),
                'folder_id' => $folder,
                'top'       => $top,
                'next_link' => $this->nextLink($page),
            )
        );
    }
}
