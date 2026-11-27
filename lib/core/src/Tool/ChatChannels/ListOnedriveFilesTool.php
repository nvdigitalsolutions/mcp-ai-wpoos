<?php

/**
 * List the items in a OneDrive folder.
 *
 * Port of `WP_MCP_AI_Pro_Tool_List_Onedrive_Files` (Proposal 164 section
 * 164.104). One `GET /me/drive/root/children` or
 * `GET /me/drive/root:/{folder_path}:/children`.
 *
 * ## Corrections
 *
 * 1. **The folder path is validated as well as encoded.** WordPress encoded the
 *    segments but allowed `.` and `..`, which encoding does not change.
 * 2. **The next page is reachable** through `next_link`, where WordPress
 *    dropped `@odata.nextLink`.
 * 3. **Rows are projected** to id, name, size, folder flag, child count, MIME
 *    type, web URL and last-modified time.
 * 4. **`top` is read with the numeric clamp**, so a non-numeric value means the
 *    default of 25 rather than `absint()` and then one.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class ListOnedriveFilesTool extends GraphTool
{
    private const DEFAULT_TOP = 25;
    private const MAX_TOP = 100;

    public function getSlug(): string
    {
        return 'list_onedrive_files';
    }

    public function getName(): string
    {
        return 'List OneDrive Files';
    }

    public function getDescription(): string
    {
        return 'List the items in a OneDrive folder: id, name, size, folder flag, child count, MIME type, web URL and last-modified time. Leave folder_path empty for the drive root; pass a returned next_link to read the next page.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'       => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'folder_path' => array(
                    'type'        => 'string',
                    'description' => 'Folder path, for example Documents/Reports. Omit for the drive root.',
                ),
                'top'         => array(
                    'type'        => 'integer',
                    'description' => 'How many items to return, 1-100. Defaults to 25.',
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
                'next_link'   => array(
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

        $folder = $this->textArg($arguments, 'folder_path');

        if ('' === $folder) {
            $endpoint = '/me/drive/root/children';
        } else {
            $encoded = $this->drivePath($arguments, 'folder_path', 'folder path');

            if (! is_string($encoded)) {
                return $encoded;
            }

            $endpoint = '/me/drive/root:/' . $encoded . ':/children';
        }

        $top = $this->limit($arguments['top'] ?? null, self::DEFAULT_TOP, self::MAX_TOP);

        $page = $this->page($token, $endpoint, array('$top' => $top), $arguments);

        if ($this->errors->isError($page)) {
            return $page;
        }

        $rows = array();

        foreach (is_array($page['value'] ?? null) ? $page['value'] : array() as $item) {
            $row = $this->driveItemRow($item);

            if (array() !== $row) {
                $rows[] = $row;
            }
        }

        return $this->success(
            sprintf('Found %d item%s.', count($rows), 1 === count($rows) ? '' : 's'),
            array(
                'files'      => $rows,
                'count'      => count($rows),
                'folder_path' => '' !== $folder ? $folder : null,
                'top'        => $top,
                'next_link'  => $this->nextLink($page),
            )
        );
    }
}
