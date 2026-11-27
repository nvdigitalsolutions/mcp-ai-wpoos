<?php

/**
 * Upload a file to OneDrive.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Upload_Onedrive_File` (Proposal 164 section
 * 164.104). One `PUT /me/drive/root:/{path}:/content` whose body is the file
 * itself, not a JSON document.
 *
 * The content arrives base64-encoded and is decoded before the request, which
 * is what the WordPress tool did and what the schema says. A malformed payload
 * is refused, not sent as bytes.
 *
 * ## Corrections
 *
 * 1. **The path is validated as well as encoded.** WordPress encoded each
 *    segment but allowed `.` and `..`, which encoding does not change.
 * 2. **Success is any 2xx.** WordPress accepted 200 and 201; the broader check
 *    keeps a conforming proxy answer working.
 * 3. **The reply is projected** to the created item, where WordPress returned
 *    Graph's document.
 *
 * ## Adaptations
 *
 * Graph's simple upload has a size ceiling, above which an upload session is
 * required. No WordPress tool in this family implemented a session, and the
 * ceiling is generous, so a larger file surfaces Graph's own refusal with its
 * message rather than a local guess at the limit.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\ChatChannels;

class UploadOnedriveFileTool extends GraphTool
{
    public function getSlug(): string
    {
        return 'upload_onedrive_file';
    }

    public function getName(): string
    {
        return 'Upload OneDrive File';
    }

    public function getDescription(): string
    {
        return 'Upload a file to OneDrive at a given path, from base64-encoded content, with an optional MIME type. Overwrites an existing file at that path. Returns the created item metadata.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'token'        => array(
                    'type'        => 'string',
                    'description' => 'Microsoft Graph access token.',
                    'writeOnly'   => true,
                    'minLength'   => 1,
                ),
                'file_path'    => array(
                    'type'        => 'string',
                    'description' => 'Destination path including the filename, for example Documents/report.docx.',
                    'minLength'   => 1,
                ),
                'content'      => array(
                    'type'        => 'string',
                    'description' => 'Base64-encoded file content.',
                    'minLength'   => 1,
                ),
                'content_type' => array(
                    'type'        => 'string',
                    'description' => 'MIME type of the file. Defaults to application/octet-stream.',
                ),
            ),
            'required'             => array('token', 'file_path', 'content'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $token = $this->token($arguments);

        if (! is_string($token)) {
            return $token;
        }

        $encoded = $this->drivePath($arguments, 'file_path', 'file path');

        if (! is_string($encoded)) {
            return $encoded;
        }

        $payload = $this->textArg($arguments, 'content');

        if ('' === $payload) {
            return $this->errors->validationFailed(
                'File content is required.',
                array('content' => array('File content must be provided.'))
            );
        }

        $bytes = base64_decode($payload, true);

        if (false === $bytes) {
            return $this->errors->validationFailed(
                'The content is not valid base64 data.',
                array('content' => array('The content is not valid base64.'))
            );
        }

        $contentType = $this->textArg($arguments, 'content_type');

        if ('' === $contentType) {
            $contentType = 'application/octet-stream';
        }

        try {
            $response = $this->apiPut(
                $token,
                '/me/drive/root:/' . $encoded . ':/content',
                $bytes,
                $contentType
            );
        } catch (ChatChannelApiException $error) {
            return $this->failure($error);
        }

        $row = $this->driveItemRow($response);

        if ('' === $row['id']) {
            return $this->errors->create(
                'graph_invalid_response',
                'Microsoft Graph accepted the upload but returned no drive item.'
            );
        }

        return $this->success(
            'Uploaded ' . $row['name'] . ' (' . $row['size'] . ' bytes).',
            $row
        );
    }
}
