<?php

/**
 * Read one OneDrive item's metadata.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Get_Onedrive_File` (Proposal 164 section
 * 164.104). One `GET /me/drive/items/{id}` or `GET /me/drive/root:/{path}`.
 *
 * The tool returns **metadata**, not bytes: name, size, folder flag, MIME type,
 * web URL and last-modified time. Downloading content would be a second
 * endpoint whose body is the file itself, and no WordPress tool in this family
 * did that.
 *
 * ## Corrections
 *
 * 1. **Exactly one of `item_id` and `file_path` is accepted.** WordPress
 *    preferred the id when both were given and silently ignored the path.
 * 2. **The item id and the path are validated.** WordPress encoded the path
 *    segments but allowed `.` and `..`; the id went in through `rawurlencode`,
 *    which leaves `..` intact. Both are refused now.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class GetOnedriveFileTool extends GraphTool
{
    public function getSlug(): string
    {
        return 'get_onedrive_file';
    }

    public function getName(): string
    {
        return 'Get OneDrive File';
    }

    public function getDescription(): string
    {
        return 'Read one OneDrive item by id or by path: name, size, folder flag, child count, MIME type, web URL and last-modified time. Returns metadata, not the file content.';
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
                'item_id'   => array(
                    'type'        => 'string',
                    'description' => 'Drive item id. Give this or file_path, not both.',
                    'minLength'   => 1,
                ),
                'file_path' => array(
                    'type'        => 'string',
                    'description' => 'Path in the drive, for example Documents/report.docx. Give this or item_id, not both.',
                    'minLength'   => 1,
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

        $id   = $this->textArg($arguments, 'item_id');
        $path = $this->textArg($arguments, 'file_path');

        if ('' !== $id && '' !== $path) {
            return $this->errors->validationFailed(
                'Give either item_id or file_path, not both.',
                array('item_id' => array('Both item_id and file_path were given.'))
            );
        }

        if ('' === $id && '' === $path) {
            return $this->errors->validationFailed(
                'Either item_id or file_path is required.',
                array('item_id' => array('Neither item_id nor file_path was given.'))
            );
        }

        if ('' !== $id) {
            $encoded = $this->itemId($arguments);

            if (! is_string($encoded)) {
                return $encoded;
            }

            $endpoint = '/me/drive/items/' . $encoded;
        } else {
            $encoded = $this->drivePath($arguments, 'file_path', 'file path');

            if (! is_string($encoded)) {
                return $encoded;
            }

            $endpoint = '/me/drive/root:/' . $encoded;
        }

        try {
            $response = $this->apiGet($token, $endpoint);
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $row = $this->driveItemRow($response);

        if ('' === $row['id']) {
            return $this->errors->create(
                'graph_invalid_response',
                'Microsoft Graph returned no drive item.'
            );
        }

        return $this->success('OneDrive item ' . $row['name'] . '.', $row);
    }
}
